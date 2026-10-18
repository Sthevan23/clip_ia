"""
Módulo de Análise Inteligente de Transcrições (`analyzer.py`).

Implementa:
1. Análise da transcrição por blocos mantendo o contexto entre eles.
2. Provedor de IA configurável:
   - `local_heuristic`: analisador semântico estruturado local (padrão, custo zero, não inicia serviços pagos).
   - `openai_compatible`: integra com a API oficial da OpenAI (ou endpoint compatível configurado)
     apenas quando explicitamente autorizado (`CLIPIA_ALLOW_PAID_API=true`), enviando somente os trechos
     de transcrição necessários de cada bloco (nunca o vídeo inteiro).
3. Controle financeiro persistente (`PersistentBudgetGuard`):
   - Respeita `max_cost_per_job_brl` e o teto mensal `max_monthly_cost_brl` (padrão R$ 100,00)
     mesmo após reiniciar o worker, sem depender exclusivamente de estimativas de tokens.
   - Preserva os limites de chamadas (`max_llm_calls_per_job`), tokens (`max_llm_tokens_per_job`)
     e duração (`max_audio_duration_seconds`).
4. Seleção de histórias completas, dicas práticas, curiosidades, humor, opiniões e explicações relevantes.
5. Prevenção de frases fora de contexto ou incompletas e deduplicação de trechos repetidos ou sobrepostos.
6. Validação estrita do JSON recebido do modelo antes de produzir `ClipCandidate`.
7. Sem promessa de viralização, sem métricas inventadas e sem expor chaves ou dados sensíveis nos logs.
"""

from __future__ import annotations

import json
import logging
import re
from difflib import SequenceMatcher
from typing import Any

import httpx

from .budget import BudgetExceededError, PersistentBudgetGuard
from .chunker import build_contextual_chunks
from .config import WorkerConfig
from .models import (
    AllowedCategory,
    ClipCandidate,
    ClipSelectionParams,
    TranscriptChunk,
    TranscriptDocument,
    TranscriptSegment,
    UsageRecord,
    parse_and_validate_llm_candidates_json,
)
from .security import redact_sensitive_text
from .subtitles import SubtitleGenerator, generate_clip_metadata_locally

logger = logging.getLogger("clipia.analyzer")

# Padrões linguísticos para classificação temática e avaliação de coesão narrativa
_CATEGORY_PATTERNS: dict[AllowedCategory, tuple[str, str, list[str]]] = {
    "historia_completa": (
        "História e relato com início, desenvolvimento e conclusão",
        "Relato contextualizado com abertura clara, desenvolvimento do episódio e lição final.",
        [
            "aconteceu",
            "lembro",
            "uma vez",
            "história",
            "quando eu",
            "no começo",
            "aprendi",
            "experiência",
            "caso real",
            "resultado foi",
        ],
    ),
    "dica_pratica": (
        "Dica prática e recomendação acionável",
        "Orientação prática direta que o espectador pode aplicar imediatamente sem depender de contexto externo.",
        [
            "dica",
            "passo",
            "como fazer",
            "segredo",
            "estratégia",
            "evite",
            "recomendo",
            "prática",
            "aplique",
            "regra",
            "método",
        ],
    ),
    "curiosidade": (
        "Curiosidade e fato pouco conhecido",
        "Apresenta um dado curioso ou contraste inesperado com explicação completa no próprio trecho.",
        [
            "curioso",
            "pouca gente sabe",
            "interessante",
            "sabia que",
            "fato",
            "descoberta",
            "estatística",
            "impressionante",
            "por trás",
        ],
    ),
    "humor": (
        "Momento de humor e observação descontraída",
        "Trecho com observação bem-humorada ou ironia leve que possui setup e conclusão compreensíveis.",
        [
            "engraçado",
            "piada",
            "rir",
            "irônico",
            "brincadeira",
            "cômico",
            "absurdo",
            "divertido",
        ],
    ),
    "opiniao": (
        "Opinião fundamentada e ponto de vista",
        "Posicionamento claro acompanhado dos argumentos que justificam a visão do autor.",
        [
            "na minha opinião",
            "acredito que",
            "ponto de vista",
            "discordo",
            "verdade é que",
            "visão",
            "defendo",
            "erro comum",
        ],
    ),
    "explicacao": (
        "Explicação didática de conceito",
        "Explicação estruturada com definição do problema, causa e conclusão clara.",
        [
            "explicar",
            "significa",
            "funciona assim",
            "por que",
            "conceito",
            "diferença entre",
            "entender",
            "na prática",
            "resumo",
            "conclusão",
        ],
    ),
}

_DANGLING_START_RE = re.compile(
    r"^(\.\.\.|…|e aí|mas então|como eu disse antes|isso que|porque ele|portanto isso|continuando|então como eu falava)\b",
    re.IGNORECASE,
)

_DANGLING_END_WORDS_RE = re.compile(
    r"\b(porque|quando|se|mas|e|ou|para|como|que|sem|embora|enquanto|portanto|onde)\s*[.!?]*$",
    re.IGNORECASE,
)


def _normalize_text_for_comparison(text: str) -> str:
    cleaned = re.sub(r"[^\w\s]", "", text.lower(), flags=re.UNICODE)
    return re.sub(r"\s+", " ", cleaned).strip()


def _jaccard_or_sequence_similarity(a: str, b: str) -> float:
    na = _normalize_text_for_comparison(a)
    nb = _normalize_text_for_comparison(b)
    if not na or not nb:
        return 0.0
    seq_ratio = SequenceMatcher(None, na, nb).ratio()
    words_a = set(na.split())
    words_b = set(nb.split())
    if not words_a or not words_b:
        return seq_ratio
    intersection = len(words_a & words_b)
    min_len = min(len(words_a), len(words_b))
    containment_ratio = intersection / max(1, min_len)
    return max(seq_ratio, containment_ratio * 0.9)


def _temporal_overlap_ratio(c1: ClipCandidate, c2: ClipCandidate) -> float:
    inter_start = max(c1.start_time_seconds, c2.start_time_seconds)
    inter_end = min(c1.end_time_seconds, c2.end_time_seconds)
    if inter_end <= inter_start:
        return 0.0
    inter_len = inter_end - inter_start
    min_dur = max(1.0, min(c1.duration_seconds, c2.duration_seconds))
    return inter_len / min_dur


def _is_clean_context_start(text: str) -> bool:
    stripped = text.strip()
    if not stripped or len(stripped) < 12:
        return False
    if _DANGLING_START_RE.search(stripped):
        return False
    if stripped[0].islower():
        return False
    return True


def _is_complete_sentence_end(text: str) -> bool:
    stripped = text.strip()
    if not stripped:
        return False
    if stripped.endswith(("...", "…", ",", ";", ":", "-")):
        return False
    if not stripped.endswith((".", "!", "?")):
        return False
    if _DANGLING_END_WORDS_RE.search(stripped):
        return False
    return True


def _truncate_at_word_boundary(text: str, max_chars: int, add_ellipsis: bool = False) -> str:
    cleaned = text.strip()
    if len(cleaned) <= max_chars:
        return cleaned
    cut = cleaned[:max_chars].rsplit(" ", 1)[0].rstrip(" ,;:-")
    return f"{cut}..." if add_ellipsis else cut


def _deduplicate_transcript_segments(segments: list[TranscriptSegment]) -> list[TranscriptSegment]:
    unique_segments: list[TranscriptSegment] = []
    seen_normalized: list[str] = []

    for seg in segments:
        norm = _normalize_text_for_comparison(seg.text)
        if not norm:
            continue
        is_dup = any(
            SequenceMatcher(None, norm, prev).ratio() >= 0.82
            for prev in seen_normalized
        )
        if is_dup:
            continue
        seen_normalized.append(norm)
        unique_segments.append(seg)

    return unique_segments


class TranscriptAnalyzer:
    """
    Analisa uma `TranscriptDocument` em blocos contextuais e seleciona os melhores
    candidatos de corte respeitando duração mínima, máxima, duração total do vídeo,
    quantidade de candidatos, limite por tarefa e teto mensal de R$ 100,00.
    """

    def __init__(self, config: WorkerConfig) -> None:
        self.config = config
        self.budget_guard = PersistentBudgetGuard(config)

    def analyze(
        self,
        document: TranscriptDocument,
        params: ClipSelectionParams,
        usage: UsageRecord,
        backend_api: Any | None = None,
        job_id: int | None = None,
        project_id: int | None = None,
    ) -> list[ClipCandidate]:
        dedup_segments = _deduplicate_transcript_segments(document.segments)
        clean_document = TranscriptDocument(
            language=document.language,
            duration_seconds=document.duration_seconds,
            source_label=document.source_label,
            segments=dedup_segments,
        )

        chunks = build_contextual_chunks(
            clean_document,
            window_seconds=self.config.chunk_window_seconds,
            overlap_seconds=self.config.chunk_overlap_seconds,
        )
        usage.chunks_analyzed = len(chunks)
        usage.analysis_provider = self.config.ai_provider
        usage.monthly_spent_brl_after = self.budget_guard.get_monthly_spent_brl()

        if not chunks:
            raise ValueError("A transcrição não possui segmentos de fala suficientes para análise.")

        raw_candidates: list[ClipCandidate] = []

        use_remote_llm = (
            self.config.ai_provider == "openai_compatible"
            and self.config.allow_paid_api is True
            and bool(self.config.llm_api_key)
        )

        if self.config.ai_provider == "openai_compatible" and not self.config.allow_paid_api:
            logger.warning(
                "CLIPIA_AI_PROVIDER='openai_compatible', mas CLIPIA_ALLOW_PAID_API=false. "
                "Por política de controle de custos, nenhum serviço pago foi iniciado automaticamente; "
                "utilizando analisador semântico local."
            )
            usage.analysis_provider = "local_heuristic (paid_api_locked)"

        for chunk in chunks:
            if use_remote_llm and not usage.budget_blocked:
                if usage.llm_calls_count >= self.config.max_llm_calls_per_job:
                    logger.warning(
                        "Limite de chamadas de IA por tarefa atingido (%d). Analisando blocos restantes localmente.",
                        self.config.max_llm_calls_per_job,
                    )
                    chunk_candidates = self._analyze_chunk_locally(chunk, params, clean_document.duration_seconds)
                elif usage.llm_tokens_used >= self.config.max_llm_tokens_per_job:
                    logger.warning(
                        "Limite de tokens por tarefa atingido (%d). Analisando blocos restantes localmente.",
                        self.config.max_llm_tokens_per_job,
                    )
                    chunk_candidates = self._analyze_chunk_locally(chunk, params, clean_document.duration_seconds)
                else:
                    chunk_candidates = self._analyze_chunk_with_llm(
                        chunk,
                        params,
                        usage,
                        clean_document.duration_seconds,
                        backend_api=backend_api,
                        job_id=job_id,
                        project_id=project_id,
                    )
            else:
                chunk_candidates = self._analyze_chunk_locally(chunk, params, clean_document.duration_seconds)

            raw_candidates.extend(chunk_candidates)

        deduplicated = self._deduplicate_and_rank(raw_candidates, params, clean_document.duration_seconds)

        if not deduplicated and clean_document.segments:
            deduplicated = self._build_fallback_candidates(
                clean_document.segments,
                params,
                clean_document.duration_seconds,
            )

        final_candidates = deduplicated[: params.target_clips_count]
        for cand in final_candidates:
            gen_title, gen_desc, gen_tags = generate_clip_metadata_locally(
                transcript_excerpt=cand.transcript_excerpt,
                category=cand.category,
                existing_title=cand.title,
            )
            if not cand.short_description:
                cand.short_description = gen_desc
            if not cand.hashtags:
                cand.hashtags = gen_tags
            cand.subtitle_style = params.subtitle_style
            cand.subtitles_burned = bool(params.subtitle_style.burn_subtitles)
            cues = SubtitleGenerator.build_cues_for_candidate(
                candidate=cand,
                document=clean_document,
                style=params.subtitle_style,
                aspect_ratio=params.aspect_ratio,
            )
            cand.srt_content = SubtitleGenerator.format_srt(cues)

        usage.subtitles_generated_count = len(final_candidates)
        usage.subtitles_burned = bool(params.subtitle_style.burn_subtitles)
        return final_candidates

    def _is_candidate_within_bounds(
        self,
        cand: ClipCandidate,
        params: ClipSelectionParams,
        video_duration: float,
    ) -> bool:
        if cand.start_time_seconds < 0.0:
            return False
        if cand.end_time_seconds > video_duration:
            return False
        if cand.duration_seconds < params.min_duration_seconds:
            return False
        if cand.duration_seconds > params.max_duration_seconds:
            return False
        if not _is_clean_context_start(cand.transcript_excerpt):
            return False
        if not _is_complete_sentence_end(cand.transcript_excerpt):
            return False
        return True

    def _build_openai_messages_for_chunk(
        self,
        chunk: TranscriptChunk,
        params: ClipSelectionParams,
        video_duration: float,
    ) -> list[dict[str, str]]:
        """
        Monta as mensagens para a API de texto da OpenAI enviando SOMENTE os trechos
        de transcrição necessários deste bloco (`chunk.formatted_lines_with_timestamps()`)
        e o resumo curto do bloco anterior, nunca o vídeo inteiro.
        """
        system_prompt = (
            "Você é um analista editorial técnico do ClipIA. Analise apenas os segmentos de transcrição "
            "deste bloco e selecione os melhores trechos autocontidos.\n"
            "REGRAS OBRIGATÓRIAS:\n"
            f"1. Duração estrita de cada trecho: entre {params.min_duration_seconds:.0f}s e "
            f"{params.max_duration_seconds:.0f}s, sem ultrapassar {video_duration:.1f}s.\n"
            "2. Categorias permitidas ('category'): 'historia_completa', 'dica_pratica', 'curiosidade', "
            "'humor', 'opiniao', 'explicacao'.\n"
            "3. Rejeite frases incompletas, trechos fora de contexto ou ideias repetidas.\n"
            "4. NUNCA prometa viralização nem invente métricas de engajamento.\n"
            "5. Retorne EXCLUSIVAMENTE um objeto JSON com a chave 'candidates', onde cada item contém: "
            "'start_time_seconds' (float), 'end_time_seconds' (float), 'category' (string), "
            "'title' (string), 'summary' (string), 'rationale' (string), "
            "'transcript_excerpt' (string) e 'relevance_score' (int de 0 a 100)."
        )

        # Garante que apenas os segmentos estritamente dentro da janela do bloco sejam enviados
        compact_lines = chunk.formatted_lines_with_timestamps()
        user_prompt = (
            f"Resumo do contexto anterior:\n{chunk.previous_context_summary[:380]}\n\n"
            f"Segmentos do bloco {chunk.chunk_index}/{chunk.total_chunks} "
            f"[{chunk.window_start:.1f}s–{chunk.window_end:.1f}s]:\n"
            f"{compact_lines}"
        )

        return [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_prompt},
        ]

    def _analyze_chunk_with_llm(
        self,
        chunk: TranscriptChunk,
        params: ClipSelectionParams,
        usage: UsageRecord,
        video_duration: float,
        backend_api: Any | None = None,
        job_id: int | None = None,
        project_id: int | None = None,
    ) -> list[ClipCandidate]:
        """
        Consulta a API oficial da OpenAI (ou endpoint compatível configurado)
        aplicando reserva atômica pré-chamada de orçamento por tarefa e teto mensal de R$ 100,00
        (compartilhado no banco de dados quando `backend_api` está ativo + ledger local).
        """
        if not self.config.allow_paid_api or self.config.ai_provider != "openai_compatible":
            raise PermissionError(
                "Bloqueio de segurança de custos: chamadas a provedores pagos de IA estão desativadas (allow_paid_api=False)."
            )
        if not self.config.llm_api_key:
            raise PermissionError("Chave de API ausente para o provedor configurado.")

        messages = self._build_openai_messages_for_chunk(chunk, params, video_duration)
        total_prompt_chars = sum(len(m["content"]) for m in messages)

        # 1. Reserva atômica PRÉ-CHAMADA (no banco compartilhado + ledger local)
        reservation: dict[str, Any] | None = None
        try:
            reservation = self.budget_guard.reserve_call_budget(
                current_job_cost_brl=usage.estimated_cost_brl,
                estimated_input_chars=total_prompt_chars,
                backend_api=backend_api,
                job_id=job_id,
                project_id=project_id,
            )
        except BudgetExceededError as budget_exc:
            usage.budget_blocked = True
            safe_b_msg = redact_sensitive_text(str(budget_exc), [self.config.llm_api_key, self.config.worker_shared_secret])
            logger.warning("Orçamento bloqueou nova chamada à API: %s — utilizando análise local.", safe_b_msg)
            usage.errors_logged.append(safe_b_msg)
            return self._analyze_chunk_locally(chunk, params, video_duration)

        url = f"{self.config.llm_base_url}/chat/completions"
        headers = {
            "Authorization": f"Bearer {self.config.llm_api_key}",
            "Content-Type": "application/json",
        }
        body: dict[str, Any] = {
            "model": self.config.llm_model,
            "temperature": 0.2,
            "response_format": {"type": "json_object"},
            "messages": messages,
        }

        try:
            with httpx.Client(timeout=self.config.llm_timeout_seconds, verify=self.config.verify_tls) as client:
                resp = client.post(url, headers=headers, content=json.dumps(body, ensure_ascii=False))

            if resp.status_code in (401, 403):
                # Erro de autenticação: libera a reserva pré-chamada, bloqueia novas chamadas remotas e nunca expõe a chave
                self.budget_guard.release_call_reservation(reservation, backend_api=backend_api)
                reservation = None
                usage.budget_blocked = True
                auth_err = (
                    f"Erro de autenticação no provedor de IA (HTTP {resp.status_code}) no bloco #{chunk.chunk_index}. "
                    "Verifique OPENAI_API_KEY (ou CLIPIA_LLM_API_KEY)."
                )
                logger.warning("%s — aplicando análise local de contingência.", auth_err)
                usage.errors_logged.append(auth_err)
                return self._analyze_chunk_locally(chunk, params, video_duration)

            if resp.status_code >= 400:
                self.budget_guard.release_call_reservation(reservation, backend_api=backend_api)
                reservation = None
                raise RuntimeError(f"Falha HTTP {resp.status_code} retornada pelo provedor de IA.")

            payload = resp.json()

            # Contabiliza chamada realizada e liquida a reserva no banco compartilhado e no ledger mensal em disco
            usage.paid_api_used = True
            usage.llm_calls_count += 1
            usage.llm_model_used = str(payload.get("model") or self.config.llm_model)

            resp_headers_dict = dict(resp.headers.items()) if hasattr(resp, "headers") and resp.headers else {}
            cost_info = self.budget_guard.extract_and_record_call_cost(
                payload,
                resp_headers_dict,
                reservation=reservation,
                backend_api=backend_api,
            )
            reservation = None

            usage.prompt_tokens += int(cost_info["prompt_tokens"])
            usage.completion_tokens += int(cost_info["completion_tokens"])
            usage.llm_tokens_used += int(cost_info["total_tokens"])
            if cost_info["provider_reported_cost_usd"] is not None:
                prev_rep = usage.provider_reported_cost_usd or 0.0
                usage.provider_reported_cost_usd = round(prev_rep + float(cost_info["provider_reported_cost_usd"]), 6)
            usage.estimated_cost_brl = round(usage.estimated_cost_brl + float(cost_info["call_cost_brl"]), 4)
            usage.monthly_spent_brl_after = float(cost_info["monthly_spent_brl"])

            content_text = payload["choices"][0]["message"]["content"]
            parsed_items = parse_and_validate_llm_candidates_json(content_text)

            validated: list[ClipCandidate] = []
            for item in parsed_items:
                cand = ClipCandidate.model_validate(item)
                if self._is_candidate_within_bounds(cand, params, video_duration):
                    validated.append(cand)
            return validated

        except httpx.TimeoutException:
            if reservation is not None:
                self.budget_guard.release_call_reservation(reservation, backend_api=backend_api)
            timeout_msg = (
                f"Timeout ({self.config.llm_timeout_seconds:.0f}s) ao aguardar resposta do provedor de IA "
                f"no bloco #{chunk.chunk_index}."
            )
            logger.warning("%s — aplicando análise local de contingência.", timeout_msg)
            usage.errors_logged.append(timeout_msg)
            return self._analyze_chunk_locally(chunk, params, video_duration)

        except Exception as exc:
            if reservation is not None:
                self.budget_guard.release_call_reservation(reservation, backend_api=backend_api)
            safe_exc = redact_sensitive_text(
                str(exc),
                [self.config.llm_api_key, self.config.worker_shared_secret],
            )
            err_msg = f"Falha no provedor de IA no bloco #{chunk.chunk_index}: {safe_exc}"
            logger.warning("%s — aplicando análise local de contingência.", err_msg)
            usage.errors_logged.append(err_msg)
            return self._analyze_chunk_locally(chunk, params, video_duration)

    def _analyze_chunk_locally(
        self,
        chunk: TranscriptChunk,
        params: ClipSelectionParams,
        video_duration: float,
    ) -> list[ClipCandidate]:
        segs = chunk.segments
        if not segs:
            return []

        raw_dicts: list[dict[str, Any]] = []
        n = len(segs)

        for i in range(n):
            start_seg = segs[i]
            if not _is_clean_context_start(start_seg.text):
                continue
            if start_seg.text.strip().endswith(("...", "…")) or _DANGLING_END_WORDS_RE.search(start_seg.text.strip()):
                continue

            accumulated_texts: list[str] = []
            prev_end_time = start_seg.start

            for j in range(i, n):
                end_seg = segs[j]
                if end_seg.end > video_duration:
                    break

                seg_text_stripped = end_seg.text.strip()
                if not seg_text_stripped:
                    break
                if seg_text_stripped.endswith(("...", "…")) or _DANGLING_END_WORDS_RE.search(seg_text_stripped):
                    break
                if j > i and _DANGLING_START_RE.search(seg_text_stripped):
                    break
                # Se o segmento anterior já fechou uma frase (.!?), o novo segmento deve iniciar frase limpa
                if (
                    j > i
                    and _is_complete_sentence_end(segs[j - 1].text)
                    and not _is_clean_context_start(seg_text_stripped)
                ):
                    break
                if j > i and (end_seg.start - prev_end_time) > 8.0:
                    break

                accumulated_texts.append(seg_text_stripped)
                prev_end_time = end_seg.end
                dur = round(end_seg.end - start_seg.start, 2)

                if dur < params.min_duration_seconds:
                    continue
                if dur > params.max_duration_seconds:
                    break

                if not _is_complete_sentence_end(seg_text_stripped):
                    continue

                full_excerpt = " ".join(accumulated_texts).strip()
                if not _is_complete_sentence_end(full_excerpt):
                    continue

                category, title, summary, rationale, score = self._evaluate_excerpt(
                    full_excerpt,
                    dur,
                    params,
                    chunk.previous_context_summary,
                )

                raw_dicts.append(
                    {
                        "start_time_seconds": round(start_seg.start, 2),
                        "end_time_seconds": round(end_seg.end, 2),
                        "title": title,
                        "category": category,
                        "summary": summary,
                        "rationale": rationale,
                        "transcript_excerpt": full_excerpt,
                        "relevance_score": score,
                    }
                )
                break

        serialized_json = json.dumps({"candidates": raw_dicts}, ensure_ascii=False)
        validated_dicts = parse_and_validate_llm_candidates_json(serialized_json)

        results: list[ClipCandidate] = []
        for item in validated_dicts:
            cand = ClipCandidate.model_validate(item)
            if self._is_candidate_within_bounds(cand, params, video_duration):
                results.append(cand)
        return results

    def _evaluate_excerpt(
        self,
        excerpt: str,
        duration: float,
        params: ClipSelectionParams,
        previous_context_summary: str,
    ) -> tuple[AllowedCategory, str, str, str, int]:
        lower_text = excerpt.lower()
        best_cat: AllowedCategory = "explicacao"
        best_hits = 0

        for cat_key, (_, _, keywords) in _CATEGORY_PATTERNS.items():
            hits = sum(1 for kw in keywords if kw in lower_text)
            if hits > best_hits:
                best_hits = hits
                best_cat = cat_key

        cat_title_prefix, base_rationale, _ = _CATEGORY_PATTERNS[best_cat]

        sentences = [s.strip() for s in re.split(r"(?<=[.!?])\s+", excerpt) if len(s.strip()) >= 12]
        first_sentence = sentences[0] if sentences else excerpt
        clean_headline = re.sub(r"[.!?]+$", "", first_sentence).strip()
        clean_headline = _truncate_at_word_boundary(clean_headline, 78, add_ellipsis=True)

        title = f"{cat_title_prefix.split(' ')[0]}: {clean_headline}"
        title = _truncate_at_word_boundary(title, 140, add_ellipsis=True)

        if len(sentences) >= 2:
            opening_part = _truncate_at_word_boundary(re.sub(r"[.!?]+$", "", sentences[0]), 135, add_ellipsis=False)
            closing_part = _truncate_at_word_boundary(re.sub(r"[.!?]+$", "", sentences[-1]), 135, add_ellipsis=False)
            summary = f"Aborda {opening_part} e conclui mostrando {closing_part}."
        else:
            short_body = _truncate_at_word_boundary(re.sub(r"[.!?]+$", "", excerpt), 185, add_ellipsis=False)
            summary = f"Trecho autocontido ({duration:.0f}s) abordando: {short_body}."

        ideal_mid = (params.min_duration_seconds + params.max_duration_seconds) / 2.0
        half_span = max(5.0, (params.max_duration_seconds - params.min_duration_seconds) / 2.0)
        duration_fit = max(0.0, 1.0 - (abs(duration - ideal_mid) / (half_span * 1.5)))

        sentence_bonus = min(14, len(sentences) * 3)
        keyword_bonus = min(18, best_hits * 5)
        closure_bonus = 6 if _is_complete_sentence_end(excerpt) else 0
        context_continuity_bonus = 4 if previous_context_summary else 0

        raw_score = int(
            round(52 + (duration_fit * 16) + sentence_bonus + keyword_bonus + closure_bonus + context_continuity_bonus)
        )
        score = max(45, min(96, raw_score))

        rationale = (
            f"{base_rationale} Selecionado com {duration:.1f}s dentro da janela configurada "
            f"({params.min_duration_seconds:.0f}s–{params.max_duration_seconds:.0f}s), "
            f"apresentando abertura contextualizada e fechamento completo."
        )

        return best_cat, title, summary, rationale, score

    def _deduplicate_and_rank(
        self,
        candidates: list[ClipCandidate],
        params: ClipSelectionParams,
        video_duration: float,
    ) -> list[ClipCandidate]:
        sorted_cands = sorted(
            candidates,
            key=lambda c: (c.relevance_score, -c.start_time_seconds),
            reverse=True,
        )

        selected: list[ClipCandidate] = []
        for cand in sorted_cands:
            if not self._is_candidate_within_bounds(cand, params, video_duration):
                continue

            is_duplicate = False
            for existing in selected:
                if _temporal_overlap_ratio(cand, existing) > 0.0:
                    is_duplicate = True
                    break
                if _jaccard_or_sequence_similarity(cand.transcript_excerpt, existing.transcript_excerpt) > 0.55:
                    is_duplicate = True
                    break

            if not is_duplicate:
                selected.append(cand)
            if len(selected) >= params.target_clips_count:
                break

        return sorted(selected, key=lambda c: (-c.relevance_score, c.start_time_seconds))

    def _build_fallback_candidates(
        self,
        segments: list[TranscriptSegment],
        params: ClipSelectionParams,
        video_duration: float,
    ) -> list[ClipCandidate]:
        in_bounds = [
            s
            for s in segments
            if s.end <= video_duration
            and not _DANGLING_START_RE.search(s.text.strip())
            and not s.text.strip().endswith(("...", "…"))
            and not _DANGLING_END_WORDS_RE.search(s.text.strip())
        ]
        if not in_bounds:
            return []

        start_idx = next((idx for idx, s in enumerate(in_bounds) if _is_clean_context_start(s.text)), None)
        if start_idx is None:
            return []

        valid_segs = in_bounds[start_idx:]
        while valid_segs and not _is_complete_sentence_end(valid_segs[-1].text):
            valid_segs.pop()

        if not valid_segs:
            return []

        start_s = valid_segs[0].start
        end_s = min(video_duration, valid_segs[-1].end)
        dur = round(end_s - start_s, 2)

        if dur < params.min_duration_seconds or dur > params.max_duration_seconds:
            return []

        full_text = " ".join(s.text.strip() for s in valid_segs).strip()
        if len(full_text) < 20 or not _is_complete_sentence_end(full_text):
            return []

        cat, title, summary, rationale, score = self._evaluate_excerpt(
            full_text,
            dur,
            params,
            "Segmento integral.",
        )

        return [
            ClipCandidate(
                start_time_seconds=round(start_s, 2),
                end_time_seconds=round(end_s, 2),
                title=title,
                category=cat,
                summary=summary,
                rationale=rationale,
                transcript_excerpt=full_text,
                relevance_score=score,
            )
        ]
