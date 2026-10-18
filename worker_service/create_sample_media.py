"""
Utilitário de Preparação e Teste de Fonte Local Autorizada (`worker_service/create_sample_media.py`).

Cria:
1. Um arquivo de mídia local autorizado (`storage/samples/authorized_demo_podcast.wav` e `.mp4` sintético)
   utilizando síntese de voz nativa do Windows (`System.Speech.Synthesis.SpeechSynthesizer`) quando
   disponível ou áudio PCM estruturado.
2. Um arquivo complementar de transcrição com timestamps reais (`authorized_demo_podcast.transcript.json`)
   contendo ~210 segundos divididos em múltiplos blocos com:
   - História completa (0s–44s)
   - Dica prática (45s–88s)
   - Curiosidade (89s–131s)
   - Humor e observação (132s–169s)
   - Opinião e explicação didática (170s–212s)
   Além de frases curtas de transição ("E aí como eu disse antes...") para validar que o analisador
   rejeita trechos fora de contexto ou repetidos.
"""

from __future__ import annotations

import json
import math
import shutil
import struct
import subprocess
import sys
import wave
from pathlib import Path


def _resolve_ffmpeg_for_sample() -> str | None:
    which_ff = shutil.which("ffmpeg")
    if which_ff:
        return which_ff
    try:
        import imageio_ffmpeg

        exe = imageio_ffmpeg.get_ffmpeg_exe()
        if exe and Path(exe).is_file():
            return str(Path(exe).resolve())
    except Exception:
        pass
    return None


def _try_generate_windows_speech_wav(wav_path: Path) -> bool:
    if sys.platform != "win32":
        return False
    speech_text = (
        "Quando eu comecei a gravar vídeos educativos em casa, aconteceu uma história curiosa no primeiro mês de projeto. "
        "No começo achei que tinha perdido tudo na demonstração, mas aprendi a fazer um checklist simples antes de cada gravação "
        "e o resultado foi nunca mais perder uma sessão importante de trabalho. "
        "A melhor dica prática para organizar gravações longas é aplicar uma regra simples de verificação em duas etapas. "
        "O primeiro passo é testar o microfone com fone fechado e recomendo monitorar o espaço livre em disco do início ao fim."
    )
    ps_cmd = (
        "Add-Type -AssemblyName System.Speech; "
        "$s = New-Object System.Speech.Synthesis.SpeechSynthesizer; "
        "$s.Rate = 0; "
        f"$s.SetOutputToWaveFile('{str(wav_path.resolve())}'); "
        f"$s.Speak('{speech_text}'); "
        "$s.Dispose();"
    )
    try:
        proc = subprocess.run(
            ["powershell", "-NoProfile", "-Command", ps_cmd],
            capture_output=True,
            text=True,
            timeout=30,
            check=False,
        )
        return proc.returncode == 0 and wav_path.is_file() and wav_path.stat().st_size > 1024
    except Exception:
        return False


def create_sample_authorized_assets(base_dir: Path) -> tuple[Path, Path, Path, Path]:
    samples_dir = base_dir / "storage" / "samples"
    samples_dir.mkdir(parents=True, exist_ok=True)

    wav_path = samples_dir / "authorized_demo_podcast.wav"
    mp4_path = samples_dir / "authorized_demo_podcast.mp4"
    transcript_path = samples_dir / "authorized_demo_podcast.mp4.transcript.json"

    # Gera arquivo WAV com fala sintetizada real no Windows (ou onda senoidal caso indisponível) para testes com faster-whisper
    if not _try_generate_windows_speech_wav(wav_path):
        sample_rate = 16000
        duration_sec = 6
        num_samples = sample_rate * duration_sec
        with wave.open(str(wav_path), "w") as wf:
            wf.setnchannels(1)
            wf.setsampwidth(2)
            wf.setframerate(sample_rate)
            frames = bytearray()
            for i in range(num_samples):
                t = i / sample_rate
                val = int(4000.0 * math.sin(2.0 * math.pi * 220.0 * t) * math.sin(2.0 * math.pi * 2.0 * t))
                frames.extend(struct.pack("<h", val))
            wf.writeframes(bytes(frames))

    # Gera um arquivo MP4 real codificado com H.264 + AAC via FFmpeg (212s) para suportar corte real por FFmpeg
    ffmpeg_bin = _resolve_ffmpeg_for_sample()
    generated_real_mp4 = False
    if ffmpeg_bin:
        try:
            proc = subprocess.run(
                [
                    ffmpeg_bin,
                    "-y",
                    "-hide_banner",
                    "-loglevel",
                    "error",
                    "-f",
                    "lavfi",
                    "-i",
                    "color=c=0x1e1b4b:s=360x640:r=5:d=212",
                    "-f",
                    "lavfi",
                    "-i",
                    "sine=frequency=220:sample_rate=16000:duration=212",
                    "-c:v",
                    "libx264",
                    "-preset",
                    "ultrafast",
                    "-tune",
                    "stillimage",
                    "-pix_fmt",
                    "yuv420p",
                    "-c:a",
                    "aac",
                    "-b:a",
                    "48k",
                    "-shortest",
                    "-movflags",
                    "+faststart",
                    str(mp4_path),
                ],
                capture_output=True,
                text=True,
                timeout=60,
                check=False,
            )
            generated_real_mp4 = proc.returncode == 0 and mp4_path.is_file() and mp4_path.stat().st_size > 4096
        except Exception:
            generated_real_mp4 = False

    if not generated_real_mp4:
        ftyp_box = (
            b"\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom"
            b"\x00\x00\x00\x08mdat" + b"\x00" * 2048
        )
        mp4_path.write_bytes(ftyp_box)

    # MP4 curto com fala real (TTS/síntese do WAV) para teste gratuito com faster-whisper + FFmpeg.
    # O MP4 longo acima usa tom contínuo (212s) para cortes alinhados ao .transcript.json de integração.
    speech_mp4_path = samples_dir / "authorized_demo_podcast_speech.mp4"
    if ffmpeg_bin and wav_path.is_file() and wav_path.stat().st_size > 1024:
        try:
            with wave.open(str(wav_path), "r") as wf:
                speech_dur = max(5.0, wf.getnframes() / float(wf.getframerate()))
            speech_dur_str = f"{speech_dur:.3f}"
            proc_speech = subprocess.run(
                [
                    ffmpeg_bin,
                    "-y",
                    "-hide_banner",
                    "-loglevel",
                    "error",
                    "-f",
                    "lavfi",
                    "-i",
                    f"color=c=0x1e1b4b:s=720x1280:r=25:d={speech_dur_str}",
                    "-i",
                    str(wav_path),
                    "-c:v",
                    "libx264",
                    "-preset",
                    "ultrafast",
                    "-tune",
                    "stillimage",
                    "-pix_fmt",
                    "yuv420p",
                    "-c:a",
                    "aac",
                    "-b:a",
                    "128k",
                    "-shortest",
                    "-movflags",
                    "+faststart",
                    str(speech_mp4_path),
                ],
                capture_output=True,
                text=True,
                timeout=90,
                check=False,
            )
            if proc_speech.returncode != 0 or not speech_mp4_path.is_file():
                speech_mp4_path = mp4_path  # fallback documentado no README de testes
        except Exception:
            speech_mp4_path = mp4_path
    else:
        speech_mp4_path = mp4_path

    transcript_data = {
        "language": "pt",
        "duration_seconds": 212.0,
        "source_label": "authorized_demo_podcast.mp4",
        "segments": [
            {
                "start": 0.0,
                "end": 14.5,
                "text": "Quando eu comecei a gravar vídeos educativos em casa, aconteceu uma história curiosa no primeiro mês de projeto.",
            },
            {
                "start": 14.5,
                "end": 29.0,
                "text": "Eu passei três dias editando um vídeo inteiro sem perceber que o microfone principal estava desligado e só o áudio da webcam tinha sido gravado.",
            },
            {
                "start": 29.0,
                "end": 43.5,
                "text": "No começo achei que tinha perdido tudo, mas aprendi a fazer um checklist de trinta segundos antes de cada gravação e o resultado foi nunca mais perder uma sessão.",
            },
            {
                "start": 43.5,
                "end": 47.0,
                "text": "E aí como eu disse antes isso muda a rotina.",
            },
            {
                "start": 47.0,
                "end": 62.0,
                "text": "A melhor dica prática para quem quer melhorar a retenção em vídeos curtos é estruturar cada bloco em três passos simples.",
            },
            {
                "start": 62.0,
                "end": 76.5,
                "text": "Primeiro apresente o problema real logo na primeira frase, depois mostre um exemplo prático e finalize com uma recomendação clara que a pessoa possa aplicar hoje.",
            },
            {
                "start": 76.5,
                "end": 88.0,
                "text": "Evite introduções longas dizendo seu nome ou pedindo inscrição antes de entregar o conteúdo prometido no título.",
            },
            {
                "start": 88.0,
                "end": 103.0,
                "text": "Uma curiosidade interessante que pouca gente sabe sobre áudio digital é que o cérebro humano tolera imagem em baixa resolução, mas abandona o vídeo rapidamente se o som tiver eco.",
            },
            {
                "start": 103.0,
                "end": 118.0,
                "text": "Estudos de percepção audiovisual mostram que um áudio limpo faz até uma gravação simples parecer muito mais clara e confiável para quem assiste.",
            },
            {
                "start": 118.0,
                "end": 131.0,
                "text": "Por trás dessa descoberta está o fato de que a fala carrega toda a linha de raciocínio, enquanto o vídeo serve como apoio visual.",
            },
            {
                "start": 131.0,
                "end": 145.0,
                "text": "É até engraçado pensar que muita gente gasta milhares de reais numa lente nova para gravar num quarto vazio que parece uma caverna dando eco.",
            },
            {
                "start": 145.0,
                "end": 158.0,
                "text": "A brincadeira entre os editores é que um cobertor pendurado atrás da câmera resolve mais problemas acústicos do que metade dos filtros caros do mercado.",
            },
            {
                "start": 158.0,
                "end": 169.5,
                "text": "No fim das contas é divertido perceber que a solução mais simples quase sempre vence a complicação técnica.",
            },
            {
                "start": 169.5,
                "end": 184.0,
                "text": "Na minha opinião, o erro comum de muitos criadores é tentar cortar frases soltas fora de contexto apenas para parecer polêmico.",
            },
            {
                "start": 184.0,
                "end": 198.5,
                "text": "Para explicar como funciona um bom recorte vertical, precisamos preservar o início da ideia, a justificativa central e a conclusão completa do raciocínio.",
            },
            {
                "start": 198.5,
                "end": 212.0,
                "text": "Em resumo, quando o espectador entende o conceito do início ao fim sem precisar adivinhar o que veio antes, o corte cumpre seu papel educativo.",
            },
        ],
    }

    for seg in transcript_data["segments"]:
        raw_words = [w for w in str(seg["text"]).split() if w.strip()]
        if not raw_words:
            seg["words"] = []
            continue
        s_time = float(seg["start"])
        e_time = float(seg["end"])
        span = max(0.5, e_time - s_time)
        total_len = max(1, sum(len(w) for w in raw_words))
        cursor = s_time
        seg_words = []
        for idx, w in enumerate(raw_words):
            w_dur = max(0.08, span * (len(w) / total_len))
            w_start = round(cursor, 2)
            w_end = round(e_time if idx == len(raw_words) - 1 else min(e_time, w_start + w_dur), 2)
            if w_end <= w_start:
                w_end = round(w_start + 0.08, 2)
            cursor = w_end
            seg_words.append(
                {
                    "word": w,
                    "start": w_start,
                    "end": w_end,
                    "probability": 0.94,
                }
            )
        seg["words"] = seg_words

    transcript_path.write_text(
        json.dumps(transcript_data, indent=2, ensure_ascii=False),
        encoding="utf-8",
    )

    return wav_path, mp4_path, transcript_path, speech_mp4_path


if __name__ == "__main__":
    project_root = Path(__file__).resolve().parent.parent
    wav_f, mp4_f, json_f, speech_mp4_f = create_sample_authorized_assets(project_root)
    print(f"WAV criado em: {wav_f}")
    print(f"MP4 criado em: {mp4_f}")
    print(f"MP4 com fala (teste Whisper real): {speech_mp4_f}")
    print(f"Transcrição com timestamps criada em: {json_f}")
