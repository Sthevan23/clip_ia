"""
Modelos Pydantic e validação rigorosa de JSON para transcrições, blocos, legendas, metadados e candidatos de corte.
"""

from __future__ import annotations

import json
import re
from typing import Any, Literal

from pydantic import BaseModel, Field, field_validator, model_validator

AllowedCategory = Literal[
    "historia_completa",
    "dica_pratica",
    "curiosidade",
    "humor",
    "opiniao",
    "explicacao",
]

AllowedFontFamily = Literal[
    "Arial",
    "Segoe UI",
    "Verdana",
    "Tahoma",
    "Impact",
    "Trebuchet MS",
]

AllowedFontSize = Literal["small", "medium", "large", "xlarge"]
AllowedSubtitlePosition = Literal["bottom", "center", "top"]
AllowedSubtitlePreset = Literal["clean_outline", "boxed_contrast", "minimal_shadow"]

# Termos sensacionalistas ou métricas inventadas que são removidos/rejeitados
_FORBIDDEN_PROMISES_RE = re.compile(
    r"\b(viraliza[rçã]+o\s+garantida|100%\s+viral|milh[õo]es\s+de\s+views|vai\s+bombar\s+com\s+certeza)\b",
    re.IGNORECASE,
)

_HEX_COLOR_RE = re.compile(r"^#[0-9A-Fa-f]{6}$")


class SubtitleStyleConfig(BaseModel):
    """
    Configuração personalizável de legendas para vídeos curtos (9:16, 1:1 e 16:9).
    Permite escolher fonte, tamanho, cores (texto, destaque e contorno), posição,
    estilo de contorno/caixa e se a legenda deve ser incorporada ao vídeo (burn-in)
    ou apenas exportada em arquivo SRT separado.
    """

    burn_subtitles: bool = Field(
        default=True,
        description="Se True, incorpora (queima) as legendas no vídeo MP4 usando FFmpeg.",
    )
    export_srt: bool = Field(
        default=True,
        description="Se True, gera e disponibiliza o arquivo .srt sincronizado separado.",
    )
    font_family: AllowedFontFamily = Field(default="Arial")
    font_size: AllowedFontSize = Field(default="large")
    primary_color: str = Field(default="#FFFFFF")
    highlight_color: str = Field(default="#FACC15")
    outline_color: str = Field(default="#000000")
    position: AllowedSubtitlePosition = Field(default="bottom")
    style_preset: AllowedSubtitlePreset = Field(default="clean_outline")
    highlight_keywords: bool = Field(
        default=True,
        description="Destaca palavras importantes com cor de contraste estática quando houver timestamps confiáveis.",
    )

    @field_validator("primary_color", "highlight_color", "outline_color")
    @classmethod
    def validate_hex_color(cls, value: str) -> str:
        cleaned = value.strip().upper()
        if not _HEX_COLOR_RE.match(cleaned):
            raise ValueError(f"Cor hexadecimal inválida: '{value}'. Use o formato #RRGGBB.")
        return cleaned


class TranscriptWord(BaseModel):
    """Palavra individual com timestamps e probabilidade retornados pelo faster-whisper."""

    word: str = Field(..., min_length=1)
    start: float = Field(..., ge=0.0)
    end: float = Field(..., gt=0.0)
    probability: float = Field(default=0.9, ge=0.0, le=1.0)

    @model_validator(mode="after")
    def validate_word_interval(self) -> "TranscriptWord":
        if self.end <= self.start:
            object.__setattr__(self, "end", round(self.start + 0.05, 3))
        return self


class TranscriptSegment(BaseModel):
    """Segmento individual de transcrição com timestamps precisos em segundos e palavras opcionais."""

    start: float = Field(..., ge=0.0, description="Início do segmento em segundos")
    end: float = Field(..., gt=0.0, description="Fim do segmento em segundos")
    text: str = Field(..., min_length=1, description="Texto falado no intervalo")
    words: list[TranscriptWord] = Field(
        default_factory=list,
        description="Timestamps por palavra quando extraídos com confiança pelo faster-whisper",
    )

    @model_validator(mode="after")
    def validate_interval(self) -> "TranscriptSegment":
        if self.end <= self.start:
            raise ValueError("O timestamp final (end) deve ser maior que o inicial (start).")
        return self


class TranscriptDocument(BaseModel):
    """Documento completo de transcrição de uma fonte autorizada."""

    language: str = Field(default="pt")
    duration_seconds: float = Field(..., gt=0.0)
    source_label: str = Field(default="authorized_local_file")
    segments: list[TranscriptSegment] = Field(default_factory=list)

    @model_validator(mode="after")
    def validate_segments_within_duration(self) -> "TranscriptDocument":
        if not self.segments:
            return self
        sorted_segs = sorted(self.segments, key=lambda s: (s.start, s.end))
        for seg in sorted_segs:
            if seg.end > self.duration_seconds:
                raise ValueError(
                    f"Segmento ({seg.start:.2f}s–{seg.end:.2f}s) ultrapassa a duração total do vídeo ({self.duration_seconds:.2f}s)."
                )
        object.__setattr__(self, "segments", sorted_segs)
        return self


class TranscriptChunk(BaseModel):
    """Bloco de transcrição com contexto do bloco anterior para análise contínua."""

    chunk_index: int
    total_chunks: int
    window_start: float
    window_end: float
    previous_context_summary: str
    segments: list[TranscriptSegment]

    def formatted_lines_with_timestamps(self) -> str:
        lines: list[str] = []
        for seg in self.segments:
            lines.append(f"[{seg.start:.2f}s - {seg.end:.2f}s] {seg.text.strip()}")
        return "\n".join(lines)


class ClipCandidate(BaseModel):
    """
    Candidato de corte validado.
    Registra obrigatoriamente: início, fim, título, descrição curta, hashtags,
    categoria, resumo, justificativa, transcrição, legendas SRT sincronizadas e
    pontuação estimada de interesse (0 a 100), sem prometer viralização.
    """

    start_time_seconds: float = Field(..., ge=0.0)
    end_time_seconds: float = Field(..., gt=0.0)
    duration_seconds: float = Field(default=0.0, ge=0.0)
    title: str = Field(..., min_length=5, max_length=180)
    short_description: str = Field(default="", max_length=600)
    hashtags: list[str] = Field(default_factory=list)
    category: AllowedCategory = Field(default="explicacao")
    summary: str = Field(..., min_length=15, max_length=600)
    rationale: str = Field(..., min_length=15, max_length=600)
    transcript_excerpt: str = Field(..., min_length=15, max_length=3000)
    relevance_score: int = Field(..., ge=0, le=100)
    storage_url: str | None = Field(default=None, max_length=500)
    srt_storage_url: str | None = Field(default=None, max_length=500)
    srt_content: str = Field(default="", max_length=25000)
    subtitles_burned: bool = Field(default=True)
    subtitle_style: SubtitleStyleConfig = Field(default_factory=SubtitleStyleConfig)
    status: Literal["candidate", "approved", "rendering", "ready", "failed"] = Field(default="candidate")

    @field_validator("title", "summary", "rationale", "short_description")
    @classmethod
    def sanitize_no_viral_promises(cls, value: str) -> str:
        cleaned = _FORBIDDEN_PROMISES_RE.sub("relevância editorial", value.strip())
        return cleaned

    @field_validator("hashtags")
    @classmethod
    def normalize_hashtags(cls, value: list[str]) -> list[str]:
        normalized: list[str] = []
        seen: set[str] = set()
        for raw_tag in value:
            clean = re.sub(r"[^\w#]", "", str(raw_tag).strip(), flags=re.UNICODE)
            if not clean:
                continue
            if not clean.startswith("#"):
                clean = f"#{clean}"
            if len(clean) < 3:
                continue
            lower_key = clean.lower()
            if lower_key not in seen:
                seen.add(lower_key)
                normalized.append(clean[:40])
        return normalized[:12]

    @model_validator(mode="after")
    def compute_and_check_duration(self) -> "ClipCandidate":
        if self.end_time_seconds <= self.start_time_seconds:
            raise ValueError("end_time_seconds deve ser maior que start_time_seconds.")
        computed = round(self.end_time_seconds - self.start_time_seconds, 2)
        object.__setattr__(self, "duration_seconds", computed)
        return self


class ClipSelectionParams(BaseModel):
    """Parâmetros configuráveis de seleção de candidatos e estilo de legendas."""

    min_duration_seconds: float = Field(default=30.0, ge=10.0, le=300.0)
    max_duration_seconds: float = Field(default=60.0, ge=15.0, le=300.0)
    target_clips_count: int = Field(default=5, ge=1, le=20)
    aspect_ratio: str = Field(default="9:16")
    subtitle_style: SubtitleStyleConfig = Field(default_factory=SubtitleStyleConfig)

    @model_validator(mode="after")
    def check_bounds(self) -> "ClipSelectionParams":
        if self.min_duration_seconds > self.max_duration_seconds:
            raise ValueError("A duração mínima não pode ser maior que a duração máxima.")
        return self


class UsageRecord(BaseModel):
    """Registro auditável de consumo e custos de cada execução (sem armazenar chaves ou dados sensíveis)."""

    transcription_mode: str = "faster_whisper_local"
    whisper_model: str = "base"
    whisper_device: str = "cpu"
    audio_duration_seconds: float = 0.0
    chunks_analyzed: int = 0
    analysis_provider: str = "local_heuristic"
    llm_model_used: str = "none"
    paid_api_used: bool = False
    llm_calls_count: int = 0
    prompt_tokens: int = 0
    completion_tokens: int = 0
    llm_tokens_used: int = 0
    provider_reported_cost_usd: float | None = None
    estimated_cost_brl: float = 0.0
    monthly_spent_brl_after: float = 0.0
    budget_blocked: bool = False
    retries_count: int = 0
    ffmpeg_used: bool = False
    clips_rendered_count: int = 0
    subtitles_generated_count: int = 0
    subtitles_burned: bool = True
    errors_logged: list[str] = Field(default_factory=list)


def parse_and_validate_llm_candidates_json(raw_text: str) -> list[dict[str, Any]]:
    """
    Extrai e valida de forma segura o JSON retornado por um modelo de IA.
    Rejeita respostas malformadas e garante que a estrutura contenha uma lista de objetos.
    """
    cleaned = raw_text.strip()
    if cleaned.startswith("```"):
        cleaned = re.sub(r"^```(?:json)?\s*", "", cleaned, flags=re.IGNORECASE)
        cleaned = re.sub(r"\s*```$", "", cleaned)

    try:
        parsed = json.loads(cleaned)
    except json.JSONDecodeError as exc:
        # Tenta localizar o primeiro objeto ou array JSON válido no texto
        match = re.search(r"(\{[\s\S]*\}|\[[\s\S]*\])", cleaned)
        if not match:
            raise ValueError(f"O modelo não retornou um JSON válido: {exc}") from exc
        parsed = json.loads(match.group(1))

    if isinstance(parsed, dict):
        candidates_list = parsed.get("candidates")
        if not isinstance(candidates_list, list):
            raise ValueError("O JSON retornado pelo modelo não contém a chave 'candidates' como lista.")
        return [item for item in candidates_list if isinstance(item, dict)]

    if isinstance(parsed, list):
        return [item for item in parsed if isinstance(item, dict)]

    raise ValueError("Estrutura JSON inesperada recebida do provedor de IA.")
