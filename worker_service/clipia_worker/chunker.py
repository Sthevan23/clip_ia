"""
Divisão da transcrição em blocos com manutenção de contexto entre eles (`chunker.py`).

Objetivo:
- Analisar transcrições longas por blocos (`window_seconds`), mantendo uma janela de sobreposição
  (`overlap_seconds`) e um resumo textual dos últimos segmentos do bloco anterior para que histórias,
  explicações ou raciocínios na fronteira entre dois blocos nunca percam o contexto.
"""

from __future__ import annotations

from .models import TranscriptChunk, TranscriptDocument, TranscriptSegment


def build_contextual_chunks(
    document: TranscriptDocument,
    window_seconds: float = 120.0,
    overlap_seconds: float = 25.0,
) -> list[TranscriptChunk]:
    """
    Divide os segmentos da transcrição em blocos temporais com sobreposição e
    injeta em cada bloco o resumo de contexto imediatamente anterior.
    """
    segments = document.segments
    if not segments:
        return []

    window_seconds = max(45.0, float(window_seconds))
    overlap_seconds = max(10.0, min(float(overlap_seconds), window_seconds * 0.45))
    step_seconds = window_seconds - overlap_seconds

    total_duration = float(document.duration_seconds)
    raw_windows: list[tuple[float, float, list[TranscriptSegment]]] = []

    cursor = 0.0
    while cursor < total_duration:
        w_start = round(cursor, 2)
        w_end = round(min(total_duration, cursor + window_seconds), 2)

        in_window = [
            seg
            for seg in segments
            if seg.end > w_start and seg.start < w_end
        ]

        if in_window:
            win_tuple = (in_window[0].start, in_window[-1].end, in_window)
            if not raw_windows or (raw_windows[-1][0], raw_windows[-1][1]) != (win_tuple[0], win_tuple[1]):
                raw_windows.append(win_tuple)

        if w_end >= total_duration:
            break
        cursor += step_seconds

    total_chunks = len(raw_windows)
    chunks: list[TranscriptChunk] = []
    previous_summary = "Início do vídeo (nenhum bloco anterior)."

    for idx, (actual_start, actual_end, seg_list) in enumerate(raw_windows):
        chunks.append(
            TranscriptChunk(
                chunk_index=idx + 1,
                total_chunks=total_chunks,
                window_start=round(actual_start, 2),
                window_end=round(actual_end, 2),
                previous_context_summary=previous_summary,
                segments=seg_list,
            )
        )

        # Constrói o contexto para o próximo bloco usando os últimos segmentos deste bloco
        tail_segments = seg_list[-3:] if len(seg_list) >= 3 else seg_list
        tail_text = " ".join(s.text.strip() for s in tail_segments).strip()
        if len(tail_text) > 360:
            tail_text = "..." + tail_text[-357:]
        previous_summary = (
            f"Final do bloco #{idx + 1} ({tail_segments[0].start:.1f}s–{tail_segments[-1].end:.1f}s): "
            f"{tail_text}"
        )

    return chunks
