<?php
declare(strict_types=1);

/**
 * ClipIA — Sistema de Legendas Sincronizadas e Metadados Locais (`includes/subtitles_metadata.php`)
 *
 * Responsabilidades:
 * - Valida e normaliza a personalização de legendas (fonte, tamanho, cores, posição, estilo e modo burn-in / SRT).
 * - Gera gratuitamente (100% local, sem chamadas externas) títulos, descrições curtas e hashtags relevantes
 *   com base na transcrição do corte.
 * - Gera legendas `.srt` sincronizadas de contingência caso o candidato ainda não possua `.srt`.
 * - Permite re-renderizar um clipe específico com FFmpeg aplicando novas configurações de legenda
 *   (com legenda incorporada ou vídeo limpo + SRT separado).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/video_upload.php';

class SubtitleMetadataHelper
{
    public const ALLOWED_FONTS = [
        'Arial'        => 'Arial (Alta Legibilidade)',
        'Segoe UI'     => 'Segoe UI (Moderna)',
        'Verdana'      => 'Verdana (Larga e Clara)',
        'Tahoma'       => 'Tahoma (Compacta Forte)',
        'Impact'       => 'Impact (Destaque Intenso)',
        'Trebuchet MS' => 'Trebuchet MS (Dinâmica)',
    ];

    public const ALLOWED_SIZES = [
        'small'  => 'Pequena (42pt)',
        'medium' => 'Média (50pt)',
        'large'  => 'Grande — Recomendada 9:16 (58pt)',
        'xlarge' => 'Extra Grande (66pt)',
    ];

    public const ALLOWED_POSITIONS = [
        'bottom' => 'Inferior Segura (Acima da barra do Reels/TikTok)',
        'center' => 'Centro Vertical',
        'top'    => 'Superior Segura',
    ];

    public const ALLOWED_PRESETS = [
        'clean_outline'  => 'Contorno Forte + Sombra (Alta Legibilidade)',
        'boxed_contrast' => 'Caixa de Contraste Semi-Opaca',
        'minimal_shadow' => 'Contorno Fino + Sombra Suave',
    ];

    private const PT_STOPWORDS = [
        'a', 'ao', 'aos', 'aquela', 'aquelas', 'aquele', 'aqueles', 'aquilo', 'as', 'ate', 'até',
        'com', 'como', 'da', 'das', 'de', 'dela', 'delas', 'dele', 'deles', 'depois',
        'do', 'dos', 'e', 'ela', 'elas', 'ele', 'eles', 'em', 'entre', 'era', 'eram',
        'essa', 'essas', 'esse', 'esses', 'esta', 'estas', 'este', 'estes', 'eu', 'foi',
        'for', 'foram', 'fosse', 'fossem', 'ha', 'há', 'isso', 'isto', 'ja', 'já', 'la', 'lá',
        'lhe', 'lhes', 'lo', 'mas', 'me', 'mesmo', 'meu', 'meus', 'minha', 'minhas', 'muito',
        'muitos', 'na', 'nas', 'nem', 'no', 'nos', 'nós', 'nossa', 'nossas', 'nosso',
        'nossos', 'num', 'numa', 'nao', 'não', 'o', 'os', 'ou', 'para', 'pela', 'pelas', 'pelo',
        'pelos', 'por', 'porque', 'quando', 'que', 'quem', 'se', 'seja', 'sem', 'ser', 'seu',
        'seus', 'so', 'só', 'sua', 'suas', 'sao', 'são', 'tambem', 'também', 'te', 'tem',
        'tendo', 'tenho', 'ter', 'teu', 'teus', 'tinha', 'tinham', 'toda', 'todas', 'todo',
        'todos', 'tudo', 'um', 'uma', 'umas', 'uns', 'vai', 'vao', 'vão', 'voce', 'você',
        'entao', 'então', 'ai', 'aí', 'ali', 'aqui', 'assim', 'bem', 'cada', 'coisa', 'coisas',
        'dar', 'deu', 'dia', 'disse', 'dizer', 'falar', 'faz', 'fazer', 'fez', 'fica', 'ficar',
        'forma', 'gente', 'hoje', 'hora', 'lado', 'mais', 'menos', 'modo', 'mundo', 'nada',
        'onde', 'outro', 'outra', 'outros', 'outras', 'parte', 'pode', 'podem', 'poder',
        'ponto', 'pouco', 'primeiro', 'primeira', 'qual', 'qualquer', 'quanto', 'quase', 'sobre',
        'vez', 'vezes', 'ver', 'achei', 'achou',
    ];

    private const IMPACT_WORDS = [
        'dica', 'pratica', 'segredo', 'passo', 'regra', 'erro', 'nunca', 'sempre',
        'importante', 'essencial', 'simples', 'rapido', 'resultado', 'solucao',
        'problema', 'historia', 'aprendi', 'checklist', 'cerebro', 'atencao',
        'retencao', 'qualidade', 'audio', 'microfone', 'camera', 'video',
        'gravacao', 'edicao', 'estrategia', 'metodo', 'verdade', 'curiosidade',
        'explicar', 'conceito', 'conclusao', 'resumo', 'teste', 'seguranca',
    ];

    private const CATEGORY_HASHTAGS = [
        'historia_completa' => ['#historiareal', '#bastidores', '#aprendizado'],
        'dica_pratica'      => ['#dicapratica', '#passoapasso', '#produtividade'],
        'curiosidade'       => ['#curiosidades', '#vocesabia', '#fatosinteressantes'],
        'humor'             => ['#humor', '#bastidores', '#momentos'],
        'opiniao'           => ['#opiniao', '#reflexao', '#pontodevista'],
        'explicacao'        => ['#explicacao', '#didatica', '#conhecimento'],
    ];

    private const CATEGORY_OPENERS = [
        'historia_completa' => 'Relato completo sobre uma experiência real e a lição prática extraída.',
        'dica_pratica'      => 'Dica prática e direta para aplicar imediatamente na sua rotina.',
        'curiosidade'       => 'Fato curioso explicado de forma simples e contextualizada.',
        'humor'             => 'Observação descontraída sobre situações reais do dia a dia.',
        'opiniao'           => 'Ponto de vista fundamentado sobre um erro comum e como evitá-lo.',
        'explicacao'        => 'Explicação didática com início, desenvolvimento e conclusão clara.',
    ];

    public static function getDefaultSubtitleStyle(): array
    {
        return [
            'burn_subtitles'     => true,
            'export_srt'         => true,
            'font_family'        => 'Arial',
            'font_size'          => 'large',
            'primary_color'      => '#FFFFFF',
            'highlight_color'    => '#FACC15',
            'outline_color'      => '#000000',
            'position'           => 'bottom',
            'style_preset'       => 'clean_outline',
            'highlight_keywords' => true,
        ];
    }

    public static function normalizeSubtitleStyle(array $input): array
    {
        $defaults = self::getDefaultSubtitleStyle();

        $fontFamily = trim((string)($input['font_family'] ?? $defaults['font_family']));
        if (!isset(self::ALLOWED_FONTS[$fontFamily])) {
            $fontFamily = $defaults['font_family'];
        }

        $fontSize = strtolower(trim((string)($input['font_size'] ?? $defaults['font_size'])));
        if (!isset(self::ALLOWED_SIZES[$fontSize])) {
            $fontSize = $defaults['font_size'];
        }

        $position = strtolower(trim((string)($input['position'] ?? $defaults['position'])));
        if (!isset(self::ALLOWED_POSITIONS[$position])) {
            $position = $defaults['position'];
        }

        $preset = strtolower(trim((string)($input['style_preset'] ?? $defaults['style_preset'])));
        if (!isset(self::ALLOWED_PRESETS[$preset])) {
            $preset = $defaults['style_preset'];
        }

        $primaryColor = strtoupper(trim((string)($input['primary_color'] ?? $defaults['primary_color'])));
        if (!preg_match('/^#[0-9A-F]{6}$/', $primaryColor)) {
            $primaryColor = $defaults['primary_color'];
        }

        $highlightColor = strtoupper(trim((string)($input['highlight_color'] ?? $defaults['highlight_color'])));
        if (!preg_match('/^#[0-9A-F]{6}$/', $highlightColor)) {
            $highlightColor = $defaults['highlight_color'];
        }

        $outlineColor = strtoupper(trim((string)($input['outline_color'] ?? $defaults['outline_color'])));
        if (!preg_match('/^#[0-9A-F]{6}$/', $outlineColor)) {
            $outlineColor = $defaults['outline_color'];
        }

        $burnSubtitles = isset($input['burn_subtitles'])
            ? filter_var($input['burn_subtitles'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
            : true;

        $exportSrt = isset($input['export_srt'])
            ? filter_var($input['export_srt'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
            : true;

        $highlightKeywords = isset($input['highlight_keywords'])
            ? filter_var($input['highlight_keywords'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
            : true;

        return [
            'burn_subtitles'     => (bool)$burnSubtitles,
            'export_srt'         => (bool)$exportSrt,
            'font_family'        => $fontFamily,
            'font_size'          => $fontSize,
            'primary_color'      => $primaryColor,
            'highlight_color'    => $highlightColor,
            'outline_color'      => $outlineColor,
            'position'           => $position,
            'style_preset'       => $preset,
            'highlight_keywords' => (bool)$highlightKeywords,
        ];
    }

    public static function stripAccents(string $str): string
    {
        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
            'ç'=>'c','Ç'=>'C','ñ'=>'n','Ñ'=>'N',
        ];
        return strtr($str, $map);
    }

    public static function normalizeHashtags(array|string $raw): array
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $raw = $decoded;
                } else {
                    $raw = preg_split('/[\s,;]+/u', $trimmed) ?: [];
                }
            } else {
                $raw = preg_split('/[\s,;]+/u', $trimmed) ?: [];
            }
        }

        $normalized = [];
        $seen = [];
        foreach ($raw as $item) {
            $clean = preg_replace('/[^\p{L}\p{N}#_]/u', '', trim((string)$item)) ?? '';
            $clean = ltrim($clean, '#');
            if (mb_strlen($clean) < 2) {
                continue;
            }
            $tag = '#' . mb_substr($clean, 0, 38);
            $key = mb_strtolower($tag);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $normalized[] = $tag;
            }
            if (count($normalized) >= 12) {
                break;
            }
        }
        return $normalized;
    }

    public static function formatHashtagsString(array|string $raw): string
    {
        return implode(' ', self::normalizeHashtags($raw));
    }

    /**
     * Gera gratuitamente (100% local, sem chamadas externas) título, descrição curta e hashtags
     * relevantes a partir do trecho transcrito.
     */
    public static function generateMetadataLocally(
        string $transcriptExcerpt,
        string $category = 'explicacao',
        string $existingTitle = ''
    ): array {
        $excerptClean = trim(preg_replace('/\s+/u', ' ', $transcriptExcerpt) ?? $transcriptExcerpt);
        $rawSentences = preg_split('/(?<=[.!?])\s+/u', $excerptClean) ?: [];
        $sentences = array_values(array_filter($rawSentences, static fn($s) => mb_strlen(trim($s)) >= 10));

        $firstSentence = $sentences[0] ?? $excerptClean;
        $lastSentence  = count($sentences) >= 2 ? $sentences[count($sentences) - 1] : '';

        $headline = preg_replace('/^(então|olha só|veja bem|bom|bem|cara|gente|pessoal|sabe)\s*[,:-]?\s*/iu', '', $firstSentence) ?? $firstSentence;
        $headline = trim(preg_replace('/[.!?]+$/u', '', $headline) ?? $headline);
        if ($headline !== '') {
            $headline = mb_strtoupper(mb_substr($headline, 0, 1)) . mb_substr($headline, 1);
        }

        if (mb_strlen($headline) > 82) {
            $commaParts = explode(',', $headline);
            $firstClause = trim($commaParts[0] ?? '');
            if (mb_strlen($firstClause) >= 18 && mb_strlen($firstClause) <= 82) {
                $headline = $firstClause;
            } else {
                $cut = mb_substr($headline, 0, 80);
                $lastSpace = mb_strrpos($cut, ' ');
                if ($lastSpace !== false && $lastSpace > 20) {
                    $cut = mb_substr($cut, 0, $lastSpace);
                }
                $headline = rtrim($cut, ' ,;:-') . '...';
            }
        }

        if ($headline === '' && $existingTitle !== '') {
            $headline = trim($existingTitle);
        }
        $finalTitle = $headline !== '' ? mb_substr($headline, 0, 140) : 'Destaque do episódio';

        $opener = self::CATEGORY_OPENERS[$category] ?? self::CATEGORY_OPENERS['explicacao'];
        if ($lastSentence !== '' && $lastSentence !== $firstSentence) {
            $takeaway = mb_substr(trim($lastSentence), 0, 155);
            $shortDesc = $opener . ' ' . $takeaway;
        } else {
            $core = mb_substr(trim($firstSentence), 0, 165);
            $shortDesc = $opener . ' ' . $core;
        }
        $shortDesc = mb_substr(trim($shortDesc), 0, 480);

        // Extração local de palavras-chave para hashtags
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($excerptClean), $matches);
        $tokens = $matches[0] ?? [];
        $scores = [];

        foreach ($tokens as $tok) {
            $ascii = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', self::stripAccents($tok)) ?? '');
            if (strlen($ascii) < 4 || in_array($ascii, self::PT_STOPWORDS, true) || ctype_digit($ascii)) {
                continue;
            }
            $bonus = in_array($ascii, self::IMPACT_WORDS, true) ? 3 : 1;
            if (strlen($ascii) >= 7) {
                $bonus += 1;
            }
            $scores[$ascii] = ($scores[$ascii] ?? 0) + $bonus;
        }

        arsort($scores);
        $hashtags = [];
        foreach (array_keys($scores) as $kw) {
            $hashtags[] = '#' . $kw;
            if (count($hashtags) >= 4) {
                break;
            }
        }

        $catTags = self::CATEGORY_HASHTAGS[$category] ?? ['#conteudodigital', '#cortes'];
        foreach ($catTags as $ct) {
            if (!in_array($ct, $hashtags, true)) {
                $hashtags[] = $ct;
            }
        }
        foreach (['#cortes', '#shortsbrasil'] as $fb) {
            if (count($hashtags) < 6 && !in_array($fb, $hashtags, true)) {
                $hashtags[] = $fb;
            }
        }

        return [
            'title'             => $finalTitle,
            'short_description' => $shortDesc,
            'hashtags'          => array_slice(self::normalizeHashtags($hashtags), 0, 8),
        ];
    }

    /**
     * Formata segundos em timestamp SRT `HH:MM:SS,mmm`.
     */
    public static function formatSrtTime(float $seconds): string
    {
        $totalMs = max(0, (int)round($seconds * 1000));
        $hours   = intdiv($totalMs, 3600000);
        $rem     = $totalMs % 3600000;
        $mins    = intdiv($rem, 60000);
        $rem     %= 60000;
        $secs    = intdiv($rem, 1000);
        $millis  = $rem % 1000;
        return sprintf('%02d:%02d:%02d,%03d', $hours, $mins, $secs, $millis);
    }

    /**
     * Gera conteúdo `.srt` sincronizado proporcionalmente ao trecho quando o worker ainda não enviou `srt_content`.
     */
    public static function generateSrtLocally(
        string $transcriptExcerpt,
        float $durationSeconds,
        string $aspectRatio = '9:16'
    ): string {
        $words = preg_split('/\s+/u', trim($transcriptExcerpt), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($words)) {
            return '';
        }

        $maxWordsPerCue = ($aspectRatio === '9:16') ? 6 : 8;
        $chunks = array_chunk($words, $maxWordsPerCue);
        $totalChars = max(1, array_sum(array_map(static fn($ch) => mb_strlen(implode(' ', $ch)), $chunks)));
        $clipDur = max(1.0, $durationSeconds);

        $cursor = 0.0;
        $blocks = [];
        foreach ($chunks as $idx => $chunkWords) {
            $text = implode(' ', $chunkWords);
            $weight = mb_strlen($text) / $totalChars;
            $pieceDur = max(0.6, round($clipDur * $weight, 2));
            $start = round($cursor, 2);
            $end = ($idx === count($chunks) - 1)
                ? round($clipDur, 2)
                : min(round($clipDur, 2), round($start + $pieceDur, 2));
            if ($end <= $start) {
                $end = min($clipDur, round($start + 0.5, 2));
            }
            $cursor = $end;

            // Quebra em até 2 linhas equilibradas se passar de 28 caracteres
            if (mb_strlen($text) > 28 && count($chunkWords) >= 4) {
                $half = (int)ceil(count($chunkWords) / 2);
                $line1 = implode(' ', array_slice($chunkWords, 0, $half));
                $line2 = implode(' ', array_slice($chunkWords, $half));
                $text = $line1 . "\n" . $line2;
            }

            $cueNum = $idx + 1;
            $blocks[] = sprintf(
                "%d\n%s --> %s\n%s",
                $cueNum,
                self::formatSrtTime($start),
                self::formatSrtTime($end),
                $text
            );
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * Re-renderiza o clipe MP4 e o arquivo SRT de um candidato usando o módulo Python `FFmpegClipCutter`
     * com as novas configurações de estilo de legenda ou modo burn-in (`burn_subtitles`).
     */
    public static function rerenderCandidateWithStyle(
        array $candidateRow,
        array $projectRow,
        array $newStyle
    ): array {
        $normalizedStyle = self::normalizeSubtitleStyle($newStyle);
        $sourceRelPath = (string)($projectRow['uploaded_file_path'] ?? '');
        $sourceFullPath = VideoUploadManager::resolveSafeMediaPath($sourceRelPath);

        if ($sourceFullPath === null || !is_file($sourceFullPath)) {
            throw new DomainException('O arquivo de vídeo original deste projeto não está mais disponível para re-renderização.');
        }

        $projectRoot = dirname(__DIR__);
        $workerDir   = $projectRoot . DIRECTORY_SEPARATOR . 'worker_service';
        $clipsDir    = VideoUploadManager::getClipsDirectory();
        VideoUploadManager::checkFreeDiskSpace($clipsDir, 2 * 1024 * 1024);

        $companionJson = $sourceFullPath . '.transcript.json';
        $payload = [
            'media_path'      => $sourceFullPath,
            'transcript_json' => is_file($companionJson) ? $companionJson : null,
            'output_dir'      => $clipsDir,
            'project_id'      => (int)$candidateRow['project_id'],
            'candidate_id'    => (int)$candidateRow['id'],
            'aspect_ratio'    => (string)($candidateRow['aspect_ratio'] ?? '9:16'),
            'candidate'       => [
                'start_time_seconds' => (float)$candidateRow['start_time_seconds'],
                'end_time_seconds'   => (float)$candidateRow['end_time_seconds'],
                'title'              => (string)$candidateRow['title'],
                'short_description'  => (string)($candidateRow['short_description'] ?? ''),
                'hashtags'           => self::normalizeHashtags($candidateRow['hashtags'] ?? ''),
                'category'           => (string)($candidateRow['category'] ?? 'explicacao'),
                'summary'            => (string)($candidateRow['summary'] ?? 'Resumo técnico do trecho selecionado.'),
                'rationale'          => (string)($candidateRow['rationale'] ?? 'Justificativa técnica do trecho selecionado.'),
                'transcript_excerpt' => (string)$candidateRow['transcript_excerpt'],
                'relevance_score'    => (int)($candidateRow['relevance_score'] ?? 80),
                'subtitle_style'     => $normalizedStyle,
            ],
        ];

        $tmpInputJson = tempnam(sys_get_temp_dir(), 'clipia_sub_');
        if ($tmpInputJson === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário para configuração de legenda.');
        }

        $pyScript = <<<'PY'
import json, sys
from pathlib import Path
sys.path.insert(0, str(Path(sys.argv[1]).resolve()))
from clipia_worker.config import WorkerConfig
from clipia_worker.cutter import FFmpegClipCutter
from clipia_worker.models import ClipCandidate, ClipSelectionParams, SubtitleStyleConfig, TranscriptDocument, UsageRecord

data = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
cfg = WorkerConfig.from_env()
cutter = FFmpegClipCutter(cfg)
style = SubtitleStyleConfig.model_validate(data["candidate"]["subtitle_style"])
cand = ClipCandidate.model_validate(data["candidate"])
params = ClipSelectionParams(
    min_duration_seconds=max(10.0, min(cand.duration_seconds, 30.0)),
    max_duration_seconds=max(cand.duration_seconds + 5.0, 60.0),
    target_clips_count=1,
    aspect_ratio=data["aspect_ratio"],
    subtitle_style=style,
)
doc = None
if data.get("transcript_json") and Path(data["transcript_json"]).is_file():
    doc = TranscriptDocument.model_validate(json.loads(Path(data["transcript_json"]).read_text(encoding="utf-8")))

paths = cutter.cut_candidates(
    media_path=data["media_path"],
    candidates=[cand],
    params=params,
    usage=UsageRecord(),
    document=doc,
    output_dir=data["output_dir"],
    project_id=data["project_id"],
    job_id=data["candidate_id"],
)
Path(sys.argv[3]).write_text(json.dumps({
    "ok": True,
    "storage_url": cand.storage_url,
    "srt_storage_url": cand.srt_storage_url,
    "srt_content": cand.srt_content,
    "subtitles_burned": cand.subtitles_burned,
}, ensure_ascii=False), encoding="utf-8")
PY;

        $tmpPyFile  = $tmpInputJson . '.py';
        $tmpOutJson = $tmpInputJson . '.out.json';
        try {
            file_put_contents(
                $tmpInputJson,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            );
            file_put_contents($tmpPyFile, $pyScript);

            $cmd = sprintf(
                'python %s %s %s %s 2>&1',
                escapeshellarg($tmpPyFile),
                escapeshellarg($workerDir),
                escapeshellarg($tmpInputJson),
                escapeshellarg($tmpOutJson)
            );
            $output = [];
            $retCode = 0;
            exec($cmd, $output, $retCode);
            $fullOut = trim(implode("\n", $output));

            if ($retCode !== 0 || !is_file($tmpOutJson)) {
                throw new RuntimeException('Falha ao re-renderizar legenda com FFmpeg: ' . mb_substr($fullOut, -280));
            }

            $decoded = json_decode((string)file_get_contents($tmpOutJson), true);
            if (!is_array($decoded) || empty($decoded['ok'])) {
                throw new RuntimeException('Resposta inválida do renderizador de legendas.');
            }

            return [
                'storage_url'      => (string)($decoded['storage_url'] ?? ''),
                'srt_storage_url'  => (string)($decoded['srt_storage_url'] ?? ''),
                'srt_content'      => (string)($decoded['srt_content'] ?? ''),
                'subtitles_burned' => !empty($decoded['subtitles_burned']),
                'subtitle_style'   => $normalizedStyle,
            ];
        } finally {
            @unlink($tmpInputJson);
            @unlink($tmpPyFile);
            @unlink($tmpOutJson);
        }
    }
}
