<?php
declare(strict_types=1);

/**
 * ClipIA — Endpoint Interno de Validação de Links do YouTube
 *
 * Protegido por autenticação e CSRF.
 * Valida o domínio e o identificador de 11 caracteres no servidor (rejeitando domínios arbitrários,
 * IPs privados, localhost ou redirecionamentos) e consulta o endpoint oficial YouTube oEmbed.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/youtube.php';

header('Content-Type: application/json; charset=UTF-8');

if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode([
        'ok'    => false,
        'error' => 'Sessão não autenticada. Faça login para validar links.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'    => false,
        'error' => 'Método HTTP não permitido. Utilize POST.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$jsonBody = is_string($rawInput) && $rawInput !== '' ? json_decode($rawInput, true) : null;

$csrfToken = $_POST['csrf_token'] ?? ($jsonBody['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
$rawUrl    = (string)($_POST['youtube_url'] ?? ($jsonBody['youtube_url'] ?? ''));

if (!verify_csrf((string)$csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'ok'    => false,
        'error' => 'Token de segurança (CSRF) inválido ou expirado. Recarregue a página.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$validation = YouTubeUrlValidator::validate($rawUrl);

if (!$validation['valid'] || $validation['video_id'] === null) {
    http_response_code(422);
    echo json_encode([
        'ok'    => false,
        'error' => $validation['error'] ?? 'URL do YouTube inválida.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$metadata = YouTubeUrlValidator::fetchPublicMetadata($validation['video_id']);

echo json_encode([
    'ok'             => true,
    'video_id'       => $validation['video_id'],
    'original_url'   => $validation['original_url'],
    'normalized_url' => $validation['normalized_url'],
    'title'          => $metadata['title'],
    'author_name'    => $metadata['author_name'],
    'thumbnail_url'  => $metadata['thumbnail_url'],
    'embed_url'      => $metadata['embed_url'],
    'embed_allowed'  => $metadata['embed_allowed'],
    'oembed_status'  => $metadata['oembed_status'],
    'legal_notice'   => 'A validação da URL identifica o vídeo e permite pré-visualização incorporada quando autorizada pelo criador, mas não concede permissão nem realiza download do vídeo do YouTube.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
