<?php
declare(strict_types=1);

/**
 * Endpoint JSON autenticado — progresso do projeto no estúdio (sem expor dados de outros usuários).
 */

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Faça login para acompanhar o projeto.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)(get_current_user_profile()['id'] ?? 0);
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

if ($projectId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'project_id inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$snapshot = ProjectRepository::getStudioSnapshotForUser($projectId, $userId);
if ($snapshot === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Projeto não encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'snapshot' => $snapshot], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
