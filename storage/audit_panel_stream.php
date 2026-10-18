<?php
declare(strict_types=1);
/**
 * Auditoria rápida: simula usuário autenticado e valida download MP4/SRT do clipe gerado localmente.
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/repositories.php';
require_once dirname(__DIR__) . '/includes/video_upload.php';
require_once dirname(__DIR__) . '/includes/subtitles_metadata.php';

$auditJson = dirname(__DIR__) . '/storage/audit_real_free_run.json';
if (!is_file($auditJson)) {
    echo "PANEL_SKIP no_audit_json\n";
    exit(1);
}
$data = json_decode((string)file_get_contents($auditJson), true);
if (!is_array($data) || empty($data['candidates'][0])) {
    echo "PANEL_FAIL invalid_audit_json\n";
    exit(1);
}
$c0 = $data['candidates'][0];
$relMp4 = (string)($c0['storage_url'] ?? '');
$relSrt = (string)($c0['srt_storage_url'] ?? '');
$mp4Full = VideoUploadManager::resolveSafeMediaPath($relMp4);
$srtFull = VideoUploadManager::resolveSafeMediaPath($relSrt);
$mp4Ok = ($mp4Full !== null && is_file($mp4Full) && filesize($mp4Full) > 1024);
$srtOk = ($srtFull !== null && is_file($srtFull) && filesize($srtFull) > 20);
$tagsRaw = $c0['hashtags'] ?? '';
$tagsStr = is_array($tagsRaw) ? implode(' ', $tagsRaw) : (string)$tagsRaw;
$paidUsed = !empty($data['usage_metrics']['paid_api_used']);
$metaOk = !empty($c0['title']) && !empty($c0['short_description'])
    && str_contains($tagsStr, '#')
    && !$paidUsed;
echo ($mp4Ok && $srtOk && $metaOk ? 'PANEL_FILES_OK' : 'PANEL_FILES_FAIL')
    . " mp4=" . ($mp4Ok ? '1' : '0')
    . " srt=" . ($srtOk ? '1' : '0')
    . " meta=" . ($metaOk ? '1' : '0') . "\n";
