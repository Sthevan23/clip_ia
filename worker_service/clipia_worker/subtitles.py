"""
Sistema de Legendas Sincronizadas (.SRT / .ASS) e Geração Gratuita de Metadados Locais (`subtitles.py`).

Funcionalidades:
1. Geração de legendas sincronizadas a partir dos segmentos e timestamps de palavras do `faster-whisper`,
   ajustadas para o tempo relativo de cada clipe (`0.0s` até `duration_seconds`).
2. Destaque estático de palavras importantes quando houver timestamps confiáveis (usando contraste de cor
   em ASS sem animações bruscas que prejudiquem a leitura).
3. Personalização completa de fonte, tamanho, cores (principal, destaque e contorno), posição
   (`bottom`, `center`, `top` com margens seguras para vídeos verticais 9:16) e estilo (`clean_outline`,
   `boxed_contrast`, `minimal_shadow`).
4. Geração 100% local e gratuita (sem chamadas externas) de título otimizado, descrição curta e
   hashtags relevantes com base na transcrição do trecho.
"""

from __future__ import annotations

from dataclasses import dataclass, field
import re
import unicodedata
from typing import Sequence

from .models import (
    AllowedCategory,
    ClipCandidate,
    SubtitleStyleConfig,
    TranscriptDocument,
    TranscriptSegment,
    TranscriptWord,
)

# Stopwords do português para filtragem semântica local de palavras importantes e hashtags
_PT_STOPWORDS: set[str] = {
    "a", "ao", "aos", "aquela", "aquelas", "aquele", "aqueles", "aquilo", "as", "até",
    "com", "como", "da", "das", "de", "dela", "delas", "dele", "deles", "depois",
    "do", "dos", "e", "ela", "elas", "ele", "eles", "em", "entre", "era", "eram",
    "essa", "essas", "esse", "esses", "esta", "estas", "este", "estes", "eu", "foi",
    "for", "foram", "fosse", "fossem", "há", "isso", "isto", "já", "la", "lá", "lhe",
    "lhes", "lo", "mas", "me", "mesmo", "meu", "meus", "minha", "minhas", "muito",
    "muitos", "na", "nas", "nem", "no", "nos", "nós", "nossa", "nossas", "nosso",
    "nossos", "num", "numa", "não", "o", "os", "ou", "para", "pela", "pelas", "pelo",
    "pelos", "por", "porque", "pôde", "quando", "que", "quem", "se", "seja", "sem",
    "ser", "seu", "seus", "achou", "achei", "só", "sua", "suas", "são", "também",
    "te", "tem", "tendo", "tenho", "ter", "teu", "teus", "tinha", "tinham", "toda",
    "todas", "todo", "todos", "tu", "tua", "tuas", "tudo", "um", "uma", "umas", "uns",
    "vai", "vão", "você", "vocês", "à", "às", "então", "aí", "ali", "aqui", "assim",
    "bem", "cada", "coisa", "coisas", "dar", "deu", "dia", "disse", "dizer", "falar",
    "faz", "fazer", "fez", "fica", "ficar", "ficou", "forma", "gente", "hoje", "hora",
    "isso", "lado", "mais", "menos", "modo", "mundo", "nada", "onde", "outro", "outra",
    "outros", "outras", "parte", "pode", "podem", "poder", "pois", "ponto", "pouco",
    "primeiro", "primeira", "qual", "qualquer", "quanto", "quase", "quer", "sobre",
    "tal", "vez", "vezes", "ver", "vindo", "vir",
}

# Palavras de impacto cognitivo/prático em português que merecem destaque visual na legenda
_IMPACT_WORDS: set[str] = {
    "dica", "prática", "segredo", "passo", "regra", "erro", "nunca", "sempre",
    "importante", "essencial", "simples", "rápido", "resultado", "solução",
    "problema", "história", "aprendi", "checklist", "cérebro", "atenção",
    "retenção", "qualidade", "áudio", "microfone", "câmera", "vídeo",
    "gravação", "edição", "estratégia", "método", "verdade", "curiosidade",
    "explicar", "conceito", "conclusão", "resumo", "teste", "segurança",
}

_CATEGORY_HASHTAGS: dict[AllowedCategory, list[str]] = {
    "historia_completa": ["#historiareal", "#bastidores", "#aprendizado"],
    "dica_pratica": ["#dicapratica", "#passoapasso", "#produtividade"],
    "curiosidade": ["#curiosidades", "#vocesabia", "#fatosinteressantes"],
    "humor": ["#humor", "#bastidores", "#momentos"],
    "opiniao": ["#opiniao", "#reflexao", "#pontodevista"],
    "explicacao": ["#explicacao", "#didatica", "#conhecimento"],
}

_CATEGORY_DESCRIPTION_OPENERS: dict[AllowedCategory, str] = {
    "historia_completa": "Relato completo sobre uma experiência real e a lição prática extraída.",
    "dica_pratica": "Dica prática e direta para aplicar imediatamente na sua rotina.",
    "curiosidade": "Fato curioso explicado de forma simples e contextualizada.",
    "humor": "Observação descontraída sobre situações reais do dia a dia.",
    "opiniao": "Ponto de vista fundamentado sobre um erro comum e como evitá-lo.",
    "explicacao": "Explicação didática com início, desenvolvimento e conclusão clara.",
}

_FILLER_PREFIX_RE = re.compile(
    r"^(então|olha só|veja bem|bom|bem|cara|gente|pessoal|sabe)\s*[,:-]?\s*",
    re.IGNORECASE,
)


@dataclass
class SubtitleCue:
    """Representa um bloco individual de legenda sincronizada em tempo relativo ao clipe."""

    index: int
    start_seconds: float
    end_seconds: float
    lines: list[str]
    highlighted_words: list[str] = field(default_factory=list)
    reliable_timing: bool = True

    @property
    def plain_text(self) -> str:
        return "\n".join(self.lines)


def hex_to_ass_color(hex_color: str, alpha_hex: str = "00") -> str:
    """
    Converte uma cor hexadecimal `#RRGGBB` para o padrão Advanced SubStation Alpha `&HAABBGGRR`.
    No formato ASS, `alpha_hex="00"` significa totalmente opaco e `"80"` significa 50% transparente.
    """
    clean = hex_color.strip().lstrip("#").upper()
    if len(clean) != 6 or not re.match(r"^[0-9A-F]{6}$", clean):
        clean = "FFFFFF"
    rr = clean[0:2]
    gg = clean[2:4]
    bb = clean[4:6]
    aa = alpha_hex.strip().upper()[:2].zfill(2)
    return f"&H{aa}{bb}{gg}{rr}"


def format_srt_timestamp(seconds: float) -> str:
    """Formata segundos para o padrão SubRip `HH:MM:SS,mmm`."""
    clamped = max(0.0, float(seconds))
    total_ms = int(round(clamped * 1000))
    hours = total_ms // 3_600_000
    rem = total_ms % 3_600_000
    minutes = rem // 60_000
    rem %= 60_000
    secs = rem // 1_000
    millis = rem % 1_000
    return f"{hours:02d}:{minutes:02d}:{secs:02d},{millis:03d}"


def format_ass_timestamp(seconds: float) -> str:
    """Formata segundos para o padrão ASS `H:MM:SS.cc` (centésimos de segundo)."""
    clamped = max(0.0, float(seconds))
    total_cs = int(round(clamped * 100))
    hours = total_cs // 360_000
    rem = total_cs % 360_000
    minutes = rem // 6_000
    rem %= 6_000
    secs = rem // 100
    centis = rem % 100
    return f"{hours:d}:{minutes:02d}:{secs:02d}.{centis:02d}"


def _strip_accents(text: str) -> str:
    norm = unicodedata.normalize("NFD", text)
    return "".join(ch for ch in norm if unicodedata.category(ch) != "Mn")


def _clean_word_token(raw_word: str) -> str:
    return re.sub(r"^[^\w]+|[^\w]+$", "", raw_word.strip(), flags=re.UNICODE)


def _is_important_word(word_token: str) -> bool:
    clean = _clean_word_token(word_token).lower()
    if not clean or clean in _PT_STOPWORDS:
        return False
    if any(ch.isdigit() for ch in clean):
        return True
    if clean in _IMPACT_WORDS or _strip_accents(clean) in {_strip_accents(w) for w in _IMPACT_WORDS}:
        return True
    return len(clean) >= 7


def select_important_words_for_cue(words: Sequence[str], max_highlights: int = 2) -> list[str]:
    """
    Seleciona até `max_highlights` palavras-chave relevantes de uma legenda curta
    para receber destaque visual estático (sem animações que prejudiquem a leitura).
    """
    scored: list[tuple[int, str]] = []
    seen_lower: set[str] = set()

    for w in words:
        clean = _clean_word_token(w)
        lower = clean.lower()
        if not clean or lower in _PT_STOPWORDS or lower in seen_lower or len(clean) < 4:
            continue

        score = 0
        if lower in _IMPACT_WORDS:
            score += 30
        if any(ch.isdigit() for ch in clean):
            score += 25
        if len(clean) >= 7:
            score += 12
        elif len(clean) >= 5:
            score += 6

        if score > 0:
            seen_lower.add(lower)
            scored.append((score, clean))

    scored.sort(key=lambda item: item[0], reverse=True)
    return [w for _, w in scored[:max_highlights]]


def wrap_cue_lines(text: str, max_chars_per_line: int = 30, max_lines: int = 2) -> list[str]:
    """
    Quebra um trecho curto de legenda em no máximo `max_lines` linhas equilibradas
    para excelente legibilidade em telas verticais 9:16.
    """
    words = [w for w in text.strip().split() if w]
    if not words:
        return []

    joined = " ".join(words)
    if len(joined) <= max_chars_per_line or max_lines <= 1:
        return [joined]

    # Divide em 2 linhas equilibradas próximo ao meio do texto
    target_half = len(joined) / 2.0
    best_split = 1
    best_diff = float("inf")
    running_len = 0

    for idx in range(1, len(words)):
        running_len += len(words[idx - 1]) + (1 if idx > 1 else 0)
        diff = abs(running_len - target_half)
        if diff < best_diff:
            best_diff = diff
            best_split = idx

    line1 = " ".join(words[:best_split]).strip()
    line2 = " ".join(words[best_split:]).strip()
    return [line for line in (line1, line2) if line]


def generate_clip_metadata_locally(
    transcript_excerpt: str,
    category: AllowedCategory = "explicacao",
    existing_title: str = "",
) -> tuple[str, str, list[str]]:
    """
    Gera gratuitamente (100% local, sem chamadas externas) um título atraente e claro,
    uma descrição curta pronta para redes sociais e hashtags relevantes baseadas na transcrição.
    """
    excerpt_clean = re.sub(r"\s+", " ", transcript_excerpt.strip())
    sentences = [
        s.strip()
        for s in re.split(r"(?<=[.!?])\s+", excerpt_clean)
        if len(s.strip()) >= 10
    ]
    first_sentence = sentences[0] if sentences else excerpt_clean
    last_sentence = sentences[-1] if len(sentences) >= 2 else ""

    # 1. Título otimizado para vídeos curtos (sem promessas falsas de viralização)
    headline = _FILLER_PREFIX_RE.sub("", first_sentence).strip()
    headline = re.sub(r"[.!?]+$", "", headline).strip()
    if headline:
        headline = headline[0].upper() + headline[1:]

    if len(headline) > 82:
        # Corta na primeira vírgula ou limite de palavra
        comma_parts = [p.strip() for p in headline.split(",") if len(p.strip()) >= 18]
        if comma_parts and len(comma_parts[0]) <= 82:
            headline = comma_parts[0]
        else:
            headline = headline[:80].rsplit(" ", 1)[0].rstrip(" ,;:-") + "..."

    if not headline and existing_title:
        headline = existing_title.strip()

    title_final = headline[:140] if headline else "Destaque do episódio"

    # 2. Descrição curta baseada na transcrição
    opener = _CATEGORY_DESCRIPTION_OPENERS.get(
        category,
        _CATEGORY_DESCRIPTION_OPENERS["explicacao"],
    )
    if last_sentence and last_sentence != first_sentence:
        takeaway = re.sub(r"\s+", " ", last_sentence).strip()
        if len(takeaway) > 150:
            takeaway = takeaway[:147].rsplit(" ", 1)[0].rstrip(" ,;:-") + "..."
        short_description = f"{opener} {takeaway}"
    else:
        core = re.sub(r"\s+", " ", first_sentence).strip()
        if len(core) > 160:
            core = core[:157].rsplit(" ", 1)[0].rstrip(" ,;:-") + "..."
        short_description = f"{opener} {core}"

    short_description = short_description[:480].strip()

    # 3. Hashtags relevantes extraídas por frequência e especificidade de termos da transcrição
    raw_tokens = re.findall(r"[\wÀ-ÿ]{4,}", excerpt_clean.lower())
    token_scores: dict[str, int] = {}
    for tok in raw_tokens:
        if tok in _PT_STOPWORDS or tok.isdigit():
            continue
        ascii_tok = re.sub(r"[^a-z0-9]", "", _strip_accents(tok))
        if len(ascii_tok) < 4 or ascii_tok in _PT_STOPWORDS:
            continue
        bonus = 3 if tok in _IMPACT_WORDS else 1
        if len(ascii_tok) >= 7:
            bonus += 1
        token_scores[ascii_tok] = token_scores.get(ascii_tok, 0) + bonus

    ranked_keywords = sorted(
        token_scores.items(),
        key=lambda kv: (kv[1], len(kv[0])),
        reverse=True,
    )

    hashtags: list[str] = []
    seen_tags: set[str] = set()

    for kw, _ in ranked_keywords:
        tag = f"#{kw}"
        if tag not in seen_tags:
            seen_tags.add(tag)
            hashtags.append(tag)
        if len(hashtags) >= 4:
            break

    for cat_tag in _CATEGORY_HASHTAGS.get(category, ["#conteudodigital", "#cortes"]):
        lower_ct = cat_tag.lower()
        if lower_ct not in seen_tags:
            seen_tags.add(lower_ct)
            hashtags.append(cat_tag)

    for fallback_tag in ("#cortes", "#shortsbrasil"):
        if len(hashtags) < 6 and fallback_tag not in seen_tags:
            seen_tags.add(fallback_tag)
            hashtags.append(fallback_tag)

    return title_final, short_description, hashtags[:8]


class SubtitleGenerator:
    """
    Constrói legendas sincronizadas (`.srt` e `.ass`) a partir dos segmentos do `faster-whisper`
    para o intervalo exato de cada `ClipCandidate`.
    """

    @staticmethod
    def _has_reliable_words(seg: TranscriptSegment) -> bool:
        if not seg.words or len(seg.words) < 2:
            return False
        avg_prob = sum(w.probability for w in seg.words) / len(seg.words)
        return avg_prob >= 0.45

    @classmethod
    def build_cues_for_candidate(
        cls,
        candidate: ClipCandidate,
        document: TranscriptDocument | None = None,
        style: SubtitleStyleConfig | None = None,
        aspect_ratio: str = "9:16",
    ) -> list[SubtitleCue]:
        """
        Extrai e sincroniza as legendas do trecho `[candidate.start_time_seconds, candidate.end_time_seconds]`,
        convertendo todos os timestamps para o tempo relativo do clipe (`0.0` até `candidate.duration_seconds`).
        """
        active_style = style or candidate.subtitle_style or SubtitleStyleConfig()
        clip_start = float(candidate.start_time_seconds)
        clip_end = float(candidate.end_time_seconds)
        clip_duration = max(1.0, round(clip_end - clip_start, 2))
        max_chars_line = 28 if aspect_ratio == "9:16" else (32 if aspect_ratio == "1:1" else 42)
        max_words_per_cue = 6 if aspect_ratio == "9:16" else 8

        matching_segments: list[TranscriptSegment] = []
        if document is not None and document.segments:
            for seg in document.segments:
                overlap_start = max(clip_start, seg.start)
                overlap_end = min(clip_end, seg.end)
                if (overlap_end - overlap_start) >= 0.25:
                    matching_segments.append(seg)

        if not matching_segments:
            matching_segments = [
                TranscriptSegment(
                    start=clip_start,
                    end=clip_end,
                    text=candidate.transcript_excerpt,
                    words=[],
                )
            ]

        raw_cues: list[SubtitleCue] = []
        cue_idx = 1

        for seg in matching_segments:
            seg_rel_start = max(0.0, round(seg.start - clip_start, 2))
            seg_rel_end = min(clip_duration, round(seg.end - clip_start, 2))
            if seg_rel_end <= seg_rel_start:
                continue

            if cls._has_reliable_words(seg):
                # Agrupa palavras usando seus timestamps reais confiáveis
                valid_words: list[TranscriptWord] = [
                    w
                    for w in seg.words
                    if w.end > clip_start and w.start < clip_end and w.word.strip()
                ]
                if valid_words:
                    bucket: list[TranscriptWord] = []
                    for w in valid_words:
                        bucket.append(w)
                        bucket_text = " ".join(x.word.strip() for x in bucket)
                        ends_punct = w.word.strip().endswith((".", "!", "?", ",", ";", ":"))
                        if (
                            len(bucket) >= max_words_per_cue
                            or len(bucket_text) >= (max_chars_line * 1.8)
                            or (len(bucket) >= 3 and ends_punct)
                        ):
                            c_start = max(0.0, round(bucket[0].start - clip_start, 2))
                            c_end = min(
                                clip_duration,
                                max(round(c_start + 0.45, 2), round(bucket[-1].end - clip_start, 2)),
                            )
                            words_str = [x.word.strip() for x in bucket]
                            lines = wrap_cue_lines(" ".join(words_str), max_chars_per_line=max_chars_line)
                            highlights = (
                                select_important_words_for_cue(words_str)
                                if active_style.highlight_keywords
                                else []
                            )
                            if lines:
                                raw_cues.append(
                                    SubtitleCue(
                                        index=cue_idx,
                                        start_seconds=c_start,
                                        end_seconds=c_end,
                                        lines=lines,
                                        highlighted_words=highlights,
                                        reliable_timing=True,
                                    )
                                )
                                cue_idx += 1
                            bucket = []

                    if bucket:
                        c_start = max(0.0, round(bucket[0].start - clip_start, 2))
                        c_end = min(
                            clip_duration,
                            max(round(c_start + 0.45, 2), round(bucket[-1].end - clip_start, 2)),
                        )
                        words_str = [x.word.strip() for x in bucket]
                        lines = wrap_cue_lines(" ".join(words_str), max_chars_per_line=max_chars_line)
                        highlights = (
                            select_important_words_for_cue(words_str)
                            if active_style.highlight_keywords
                            else []
                        )
                        if lines:
                            raw_cues.append(
                                SubtitleCue(
                                    index=cue_idx,
                                    start_seconds=c_start,
                                    end_seconds=c_end,
                                    lines=lines,
                                    highlighted_words=highlights,
                                    reliable_timing=True,
                                )
                            )
                            cue_idx += 1
                    continue

            # Divisão proporcional de frases curtas quando só há timestamps de segmento
            seg_words = [w.strip() for w in seg.text.strip().split() if w.strip()]
            if not seg_words:
                continue

            word_chunks: list[list[str]] = []
            current_chunk: list[str] = []
            for w in seg_words:
                current_chunk.append(w)
                chunk_str = " ".join(current_chunk)
                ends_clause = w.endswith((".", "!", "?", ",", ";", ":"))
                if (
                    len(current_chunk) >= max_words_per_cue
                    or len(chunk_str) >= (max_chars_line * 1.8)
                    or (len(current_chunk) >= 4 and ends_clause)
                ):
                    word_chunks.append(current_chunk)
                    current_chunk = []
            if current_chunk:
                word_chunks.append(current_chunk)

            total_chars = max(1, sum(len(" ".join(ch)) for ch in word_chunks))
            seg_span = max(0.5, seg_rel_end - seg_rel_start)
            cursor = seg_rel_start

            for ch_idx, ch_words in enumerate(word_chunks):
                ch_text = " ".join(ch_words)
                weight = len(ch_text) / total_chars
                piece_dur = max(0.6, round(seg_span * weight, 2))
                c_start = round(cursor, 2)
                if ch_idx == len(word_chunks) - 1:
                    c_end = round(seg_rel_end, 2)
                else:
                    c_end = min(round(seg_rel_end, 2), round(c_start + piece_dur, 2))
                if c_end <= c_start:
                    c_end = min(clip_duration, round(c_start + 0.5, 2))
                cursor = c_end

                lines = wrap_cue_lines(ch_text, max_chars_per_line=max_chars_line)
                highlights = (
                    select_important_words_for_cue(ch_words, max_highlights=1)
                    if active_style.highlight_keywords
                    else []
                )
                if lines and c_end > c_start:
                    raw_cues.append(
                        SubtitleCue(
                            index=cue_idx,
                            start_seconds=c_start,
                            end_seconds=c_end,
                            lines=lines,
                            highlighted_words=highlights,
                            reliable_timing=True,
                        )
                    )
                    cue_idx += 1

        # Normaliza ordem monotônica sem sobreposição e dentro de [0.0, clip_duration]
        normalized_cues: list[SubtitleCue] = []
        last_end = 0.0
        for idx, cue in enumerate(raw_cues, start=1):
            s_time = max(last_end, min(clip_duration - 0.2, cue.start_seconds))
            e_time = max(round(s_time + 0.35, 2), min(clip_duration, cue.end_seconds))
            if e_time <= s_time or s_time >= clip_duration:
                continue
            last_end = e_time
            normalized_cues.append(
                SubtitleCue(
                    index=idx,
                    start_seconds=round(s_time, 2),
                    end_seconds=round(e_time, 2),
                    lines=cue.lines,
                    highlighted_words=cue.highlighted_words,
                    reliable_timing=cue.reliable_timing,
                )
            )

        return normalized_cues

    @staticmethod
    def format_srt(cues: Sequence[SubtitleCue]) -> str:
        """Gera o conteúdo completo de um arquivo `.srt` (SubRip) em UTF-8."""
        blocks: list[str] = []
        for idx, cue in enumerate(cues, start=1):
            start_str = format_srt_timestamp(cue.start_seconds)
            end_str = format_srt_timestamp(cue.end_seconds)
            text_block = "\n".join(cue.lines)
            blocks.append(f"{idx}\n{start_str} --> {end_str}\n{text_block}")
        return "\n\n".join(blocks) + ("\n" if blocks else "")

    @staticmethod
    def _escape_ass_text(text: str) -> str:
        return text.replace("\\", r"\\").replace("{", r"\{").replace("}", r"\}")

    @classmethod
    def _apply_ass_keyword_highlights(
        cls,
        lines: Sequence[str],
        highlighted_words: Sequence[str],
        primary_ass_color: str,
        highlight_ass_color: str,
    ) -> str:
        """
        Aplica destaque estático de cor (`{\\c&HBBGGRR&}`) às palavras importantes da legenda,
        evitando animações de escala ou movimento que prejudiquem a leitura.
        """
        highlight_set = {w.lower() for w in highlighted_words if w}
        rendered_lines: list[str] = []

        for line in lines:
            if not highlight_set:
                rendered_lines.append(cls._escape_ass_text(line))
                continue

            tokens = line.split(" ")
            styled_tokens: list[str] = []
            for tok in tokens:
                clean_tok = _clean_word_token(tok).lower()
                escaped_tok = cls._escape_ass_text(tok)
                if clean_tok and clean_tok in highlight_set:
                    styled_tokens.append(
                        f"{{\\c{highlight_ass_color}}}{escaped_tok}{{\\c{primary_ass_color}}}"
                    )
                else:
                    styled_tokens.append(escaped_tok)
            rendered_lines.append(" ".join(styled_tokens))

        return r"\N".join(rendered_lines)

    @classmethod
    def format_ass(
        cls,
        cues: Sequence[SubtitleCue],
        style: SubtitleStyleConfig,
        width: int = 720,
        height: int = 1280,
    ) -> str:
        """
        Gera um script Advanced SubStation Alpha (`.ass`) otimizado para FFmpeg (`libass`),
        configurando fonte grande, contorno nítido, sombra, cores personalizadas e
        posicionamento seguro para vídeos verticais 9:16 (`720x1280`), `1:1` ou `16:9`.
        """
        size_map_vertical = {
            "small": 42,
            "medium": 50,
            "large": 58,
            "xlarge": 66,
        }
        base_font_px = size_map_vertical.get(style.font_size, 58)
        if height < 1000:
            base_font_px = max(32, int(round(base_font_px * 0.78)))

        primary_ass = hex_to_ass_color(style.primary_color, "00")
        highlight_ass = hex_to_ass_color(style.highlight_color, "00")
        outline_ass = hex_to_ass_color(style.outline_color, "00")

        # Configuração do preset de borda, contorno e sombra
        if style.style_preset == "boxed_contrast":
            border_style = 3
            outline_px = 3
            shadow_px = 0
            back_ass = hex_to_ass_color(style.outline_color, "55")
        elif style.style_preset == "minimal_shadow":
            border_style = 1
            outline_px = 2
            shadow_px = 3
            back_ass = "&H88000000"
        else:
            # clean_outline (padrão de máxima legibilidade para vídeos verticais)
            border_style = 1
            outline_px = 4
            shadow_px = 2
            back_ass = "&H80000000"

        # Posicionamento e margens seguras (evita sobrepor botões e descrição do Reels/TikTok/Shorts)
        if style.position == "top":
            alignment = 8
            margin_v = int(round(height * 0.12))
        elif style.position == "center":
            alignment = 5
            margin_v = 40
        else:
            alignment = 2
            margin_v = int(round(height * 0.15)) if height >= 1000 else int(round(height * 0.10))

        margin_h = max(36, int(round(width * 0.065)))
        font_name = style.font_family.replace(",", " ")

        header = (
            "[Script Info]\n"
            "Title: ClipIA Synchronized Subtitles\n"
            "ScriptType: v4.00+\n"
            "WrapStyle: 2\n"
            "ScaledBorderAndShadow: yes\n"
            f"PlayResX: {width}\n"
            f"PlayResY: {height}\n\n"
            "[V4+ Styles]\n"
            "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, "
            "Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, "
            "Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
            f"Style: Default,{font_name},{base_font_px},{primary_ass},{highlight_ass},{outline_ass},{back_ass},"
            f"-1,0,0,0,100,100,0,0,{border_style},{outline_px},{shadow_px},{alignment},"
            f"{margin_h},{margin_h},{margin_v},1\n\n"
            "[Events]\n"
            "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n"
        )

        dialogue_lines: list[str] = []
        for cue in cues:
            start_ass = format_ass_timestamp(cue.start_seconds)
            end_ass = format_ass_timestamp(cue.end_seconds)
            highlights = cue.highlighted_words if (style.highlight_keywords and cue.reliable_timing) else []
            styled_text = cls._apply_ass_keyword_highlights(
                cue.lines,
                highlights,
                primary_ass_color=primary_ass,
                highlight_ass_color=highlight_ass,
            )
            dialogue_lines.append(
                f"Dialogue: 0,{start_ass},{end_ass},Default,,0,0,0,,{styled_text}"
            )

        return header + "\n".join(dialogue_lines) + "\n"
