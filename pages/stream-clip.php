<?php
declare(strict_types=1);

/**
 * ClipIA - Transmissão e Download Seguro de Clipes Cortados (`pages/stream-clip.php`)
 *
 * Requisitos de segurança aplicados:
 * - Exige usuário autenticado (`require_auth()`).
 * - Valida estritamente se o candidato de corte (`clip_candidates.id`) pertence ao `user_id` da sessão.
 * - Resolve o caminho do arquivo somente dentro de `storage/clips` sem permitir path traversal.
 * - Suporta HTTP Range (`206 Partial Content`) para reprodução fluida no player HTML5 (`<video controls>`) e modo download (`?download=1`).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/repositories.php';
require_once __DIR__ . '/../includes/video_upload.php';
require_once __DIR__ . '/../includes/subtitles_metadata.php';

require_auth();

$user        = current_user();
$userId      = (int)($user['id'] ?? 0);
$candidateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isDownload  = !empty($_GET['download']);
$format      = strtolower(trim((string)($_GET['format'] ?? 'mp4')));

if ($candidateId <= 0 || $userId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Identificador de clipe inválido.';
    exit;
}

$candidate = ProjectRepository::findCandidateByIdForUser($candidateId, $userId);
if ($candidate === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Clipe não encontrado ou acesso não autorizado.';
    exit;
}

if ($format === 'srt') {
    $srtText = '';
    if (!empty($candidate['srt_storage_url'])) {
        $srtPath = VideoUploadManager::resolveSafeMediaPath((string)$candidate['srt_storage_url']);
        if ($srtPath !== null && is_file($srtPath) && is_readable($srtPath)) {
            $srtText = (string)file_get_contents($srtPath);
        }
    }
    if (trim($srtText) === '' && !empty($candidate['srt_content'])) {
        $srtText = (string)$candidate['srt_content'];
    }
    if (trim($srtText) === '') {
        $srtText = SubtitleMetadataHelper::generateSrtLocally(
            (string)($candidate['transcript_excerpt'] ?? ''),
            (float)($candidate['duration_seconds'] ?? 30.0),
            (string)($candidate['aspect_ratio'] ?? '9:16')
        );
    }

    $safeSrtFilename = sprintf(
        'clipia_proj%d_corte%d.srt',
        (int)$candidate['project_id'],
        (int)$candidate['id']
    );

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/x-subrip; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safeSrtFilename . '"');
    header('Content-Length: ' . (string)strlen($srtText));
    header('Cache-Control: no-cache, must-revalidate');
    echo $srtText;
    exit;
}

if (empty($candidate['storage_url'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Clipe não encontrado ou acesso não autorizado.';
    exit;
}

$fullPath = VideoUploadManager::resolveSafeMediaPath((string)$candidate['storage_url']);
if ($fullPath === null || !is_file($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Arquivo de vídeo do clipe não encontrado no armazenamento seguro.';
    exit;
}

$fileSize = (int)filesize($fullPath);
$safeFilename = sprintf(
    'clipia_proj%d_corte%d.mp4',
    (int)$candidate['project_id'],
    (int)$candidate['id']
);

header('X-Content-Type-Options: nosniff');
header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');

if ($isDownload) {
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('Content-Length: ' . (string)$fileSize);
    readfile($fullPath);
    exit;
}

header('Content-Disposition: inline; filename="' . $safeFilename . '"');

$start = 0;
$end   = $fileSize > 0 ? $fileSize - 1 : 0;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', (string)$_SERVER['HTTP_RANGE'], $matches)) {
    $rangeStart = $matches[1] !== '' ? (int)$matches[1] : 0;
    $rangeEnd   = $matches[2] !== '' ? (int)$matches[2] : $end;

    if ($rangeStart > $rangeEnd || $rangeStart >= $fileSize || $rangeEnd >= $fileSize) {
        http_response_code(416);
        header("Content-Range: bytes */{$fileSize}");
        exit;
    }

    $start  = $rangeStart;
    $end    = $rangeEnd;
    $length = $end - $start + 1;

    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
    header('Content-Length: ' . (string)$length);

    $fp = fopen($fullPath, 'rb');
    if ($fp !== false) {
        fseek($fp, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fp);
    }
    exit;
}

header('Content-Length: ' . (string)$fileSize);
readfile($fullPath);
exit;
