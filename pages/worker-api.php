<?php
declare(strict_types=1);

/**
 * ClipIA - API Protegida de Integração entre o Backend PHP e o Serviço Externo Python (`pages/worker-api.php`)
 *
 * Requisitos de segurança aplicados:
 * - Autenticação obrigatória entre serviços via HMAC-SHA256 (`X-ClipIA-Timestamp` e `X-ClipIA-Signature`).
 * - Exige HTTPS em produção (`WORKER_REQUIRE_HTTPS`), permitindo loopback (`127.0.0.1` / `localhost`) no ambiente de dev.
 * - Nunca expõe chaves de API no navegador.
 * - Entrega tarefas com arquivo enviado ou URL HTTPS de mídia direta validada no painel (`direct_media_url`).
 * - Valida estritamente o JSON de candidatos de corte recebido do modelo/worker antes de persistir no banco.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/repositories.php';
require_once __DIR__ . '/../includes/worker_client.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'      => false,
        'message' => 'Método não permitido. Utilize POST autenticado com HMAC-SHA256.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = (string)file_get_contents('php://input');

$authCheck = ExternalWorkerClient::verifyIncomingRequest($_SERVER, $rawBody);
if (!$authCheck['ok']) {
    http_response_code((int)($authCheck['code'] ?? 401));
    echo json_encode([
        'ok'      => false,
        'message' => $authCheck['error'] ?? 'Falha na autenticação entre serviços.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'message' => 'Corpo JSON inválido.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($payload['action'] ?? ''));

try {
    if ($action === 'claim_next_job') {
        $specificProjectId = isset($payload['project_id']) ? (int)$payload['project_id'] : null;
        $job = WorkerJobRepository::claimNextAuthorizedJob($specificProjectId);

        if ($job === null) {
            echo json_encode([
                'ok'      => true,
                'has_job' => false,
                'job'     => null,
                'message' => 'Nenhuma tarefa autorizada aguardando processamento na fila.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Resolve o caminho absoluto local no ambiente de desenvolvimento para que o worker Python local possa ler o arquivo autorizado
        $relPath = (string)($job['authorized_source']['uploaded_file_path'] ?? '');
        $absPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPath));
        $job['authorized_source']['local_absolute_path'] = $absPath !== false ? $absPath : '';

        echo json_encode([
            'ok'      => true,
            'has_job' => true,
            'job'     => $job,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'download_authorized_source') {
        $jobId     = (int)($payload['job_id'] ?? 0);
        $projectId = (int)($payload['project_id'] ?? 0);

        $pdo = Database::getConnection();
        if ($pdo === null) {
            throw new RuntimeException('Banco de dados indisponível.');
        }

        $stmt = $pdo->prepare(
            "SELECT sv.uploaded_file_path, sv.uploaded_file_name, sv.uploaded_file_mime, sv.source_mode, p.authorized_source_confirmed
             FROM processing_jobs pj
             INNER JOIN projects p ON p.id = pj.project_id
             INNER JOIN source_videos sv ON sv.project_id = p.id
             WHERE pj.id = :job_id AND pj.project_id = :project_id
             LIMIT 1"
        );
        $stmt->execute([
            ':job_id'     => $jobId,
            ':project_id' => $projectId,
        ]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['authorized_source_confirmed'] !== 1 || $row['source_mode'] !== 'uploaded_file') {
            http_response_code(403);
            echo json_encode([
                'ok'      => false,
                'message' => 'Acesso negado: o projeto não possui fonte de vídeo autorizada.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $relPath = (string)$row['uploaded_file_path'];
        $fullPath = VideoUploadManager::resolveSafeMediaPath($relPath);

        if ($fullPath === null || !is_file($fullPath)) {
            http_response_code(404);
            echo json_encode([
                'ok'      => false,
                'message' => 'Arquivo de vídeo autorizado não encontrado no armazenamento seguro.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: ' . ((string)$row['uploaded_file_mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . (string)filesize($fullPath));
        header('Content-Disposition: attachment; filename="' . basename((string)$row['uploaded_file_name']) . '"');
        readfile($fullPath);
        exit;
    }

    if ($action === 'upload_rendered_clip') {
        $jobId          = (int)($payload['job_id'] ?? 0);
        $projectId      = (int)($payload['project_id'] ?? 0);
        $candidateIndex = isset($payload['clip_index'])
            ? (int)$payload['clip_index']
            : (isset($payload['candidate_index']) ? (int)$payload['candidate_index'] : 1);
        $clipBase64     = trim((string)($payload['clip_base64'] ?? ''));

        if ($jobId <= 0 || $projectId <= 0 || $clipBase64 === '') {
            throw new InvalidArgumentException('Identificadores job_id, project_id e o conteúdo clip_base64 são obrigatórios.');
        }

        $pdo = Database::getConnection();
        if ($pdo === null) {
            throw new RuntimeException('Banco de dados indisponível.');
        }

        $stmt = $pdo->prepare(
            "SELECT pj.id, pj.user_id, p.authorized_source_confirmed, sv.source_mode
             FROM processing_jobs pj
             INNER JOIN projects p ON p.id = pj.project_id
             INNER JOIN source_videos sv ON sv.project_id = p.id
             WHERE pj.id = :job_id AND pj.project_id = :project_id
             LIMIT 1"
        );
        $stmt->execute([
            ':job_id'     => $jobId,
            ':project_id' => $projectId,
        ]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['authorized_source_confirmed'] !== 1 || $row['source_mode'] !== 'uploaded_file') {
            http_response_code(403);
            echo json_encode([
                'ok'      => false,
                'message' => 'Acesso negado: o projeto não possui fonte de vídeo autorizada para armazenar clipes.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $binaryData = base64_decode($clipBase64, true);
        if ($binaryData === false || $binaryData === '') {
            throw new InvalidArgumentException('Conteúdo binário do clipe em base64 é inválido.');
        }

        $relativePath = VideoUploadManager::storeRenderedClip(
            $projectId,
            $jobId,
            $candidateIndex,
            $binaryData
        );

        $srtRelativePath = null;
        $srtContent = trim((string)($payload['srt_content'] ?? ''));
        if ($srtContent !== '') {
            $srtRelativePath = VideoUploadManager::storeRenderedSrt(
                $projectId,
                $jobId,
                $candidateIndex,
                $srtContent
            );
        }

        echo json_encode([
            'ok'              => true,
            'storage_url'     => $relativePath,
            'srt_storage_url' => $srtRelativePath,
            'size_bytes'      => strlen($binaryData),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'upload_ingested_source') {
        $jobId        = (int)($payload['job_id'] ?? 0);
        $projectId    = (int)($payload['project_id'] ?? 0);
        $sourceBase64 = trim((string)($payload['source_base64'] ?? ''));
        $origName     = basename(trim((string)($payload['original_name'] ?? 'video.mp4')));
        $mimeType     = trim((string)($payload['mime_type'] ?? ''));

        if ($jobId <= 0 || $projectId <= 0 || $sourceBase64 === '') {
            throw new InvalidArgumentException('job_id, project_id e source_base64 são obrigatórios.');
        }

        $binaryData = base64_decode($sourceBase64, true);
        if ($binaryData === false || $binaryData === '') {
            throw new InvalidArgumentException('Conteúdo binário do vídeo em base64 é inválido.');
        }

        $pdo = Database::getConnection();
        if ($pdo === null) {
            throw new RuntimeException('Banco de dados indisponível.');
        }

        $stmt = $pdo->prepare(
            "SELECT pj.user_id, pj.status, sv.source_mode
             FROM processing_jobs pj
             INNER JOIN source_videos sv ON sv.project_id = pj.project_id AND sv.user_id = pj.user_id
             WHERE pj.id = :job_id AND pj.project_id = :project_id
             LIMIT 1"
        );
        $stmt->execute([':job_id' => $jobId, ':project_id' => $projectId]);
        $row = $stmt->fetch();
        if (!$row || (string)$row['source_mode'] !== 'direct_media_url' || (string)$row['status'] !== 'running') {
            http_response_code(403);
            echo json_encode([
                'ok'      => false,
                'message' => 'Ingestão negada: tarefa inválida ou fonte não é URL de mídia direta.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $userId = (int)$row['user_id'];
        $tmpPath = tempnam(sys_get_temp_dir(), 'clipia_ingest_');
        if ($tmpPath === false) {
            throw new RuntimeException('Falha ao criar arquivo temporário de ingestão.');
        }
        try {
            if (@file_put_contents($tmpPath, $binaryData) === false) {
                throw new RuntimeException('Falha ao gravar vídeo temporário.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = (string)$finfo->file($tmpPath);
            if ($mimeType !== '' && str_starts_with($mimeType, 'video/')) {
                $detectedMime = $mimeType;
            }

            $uploadMeta = VideoUploadManager::storeIngestedTempFile($userId, [
                'tmp_path'      => $tmpPath,
                'original_name' => $origName,
                'size_bytes'    => strlen($binaryData),
                'mime_type'     => $detectedMime,
            ]);

            WorkerJobRepository::finalizeIngestedAuthorizedSource($jobId, $projectId, $uploadMeta);

            echo json_encode([
                'ok'            => true,
                'relative_path' => $uploadMeta['relative_path'],
                'message'       => 'Vídeo ingerido e vinculado ao projeto.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } finally {
            @unlink($tmpPath);
        }
    }

    if ($action === 'reserve_budget') {
        $jobId         = (int)($payload['job_id'] ?? 0);
        $projectId     = (int)($payload['project_id'] ?? 0);
        $reserveBrl    = isset($payload['reserve_brl']) ? (float)$payload['reserve_brl'] : 0.02;
        $maxMonthlyBrl = isset($payload['max_monthly_brl']) ? (float)$payload['max_monthly_brl'] : null;
        $maxJobBrl     = isset($payload['max_job_brl']) ? (float)$payload['max_job_brl'] : null;

        if ($jobId <= 0 || $projectId <= 0) {
            throw new InvalidArgumentException('Identificadores de job_id e project_id são obrigatórios para reserva de orçamento.');
        }

        $res = WorkerJobRepository::reserveBudgetForCall(
            $jobId,
            $projectId,
            $reserveBrl,
            $maxMonthlyBrl,
            $maxJobBrl
        );

        echo json_encode([
            'ok'                   => true,
            'allowed'              => (bool)$res['allowed'],
            'reason'               => $res['reason'],
            'reservation_token'    => $res['reservation_token'],
            'reserved_brl'         => $res['reserved_brl'] ?? 0.0,
            'monthly_spent_brl'    => $res['monthly_spent_brl'],
            'monthly_reserved_brl' => $res['monthly_reserved_brl'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'settle_budget') {
        $token            = trim((string)($payload['reservation_token'] ?? ''));
        $actualCostBrl    = isset($payload['actual_cost_brl']) ? (float)$payload['actual_cost_brl'] : 0.0;
        $promptTokens     = isset($payload['prompt_tokens']) ? (int)$payload['prompt_tokens'] : 0;
        $completionTokens = isset($payload['completion_tokens']) ? (int)$payload['completion_tokens'] : 0;
        $releaseOnly      = !empty($payload['release_only']);

        if ($token === '') {
            throw new InvalidArgumentException('O reservation_token é obrigatório para liquidar ou liberar reserva de orçamento.');
        }

        $settled = WorkerJobRepository::settleBudgetReservation(
            $token,
            $actualCostBrl,
            $promptTokens,
            $completionTokens,
            $releaseOnly
        );

        echo json_encode($settled, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'complete_job') {
        $jobId        = (int)($payload['job_id'] ?? 0);
        $projectId    = (int)($payload['project_id'] ?? 0);
        $candidates   = isset($payload['candidates']) && is_array($payload['candidates']) ? $payload['candidates'] : [];
        $usageMetrics = isset($payload['usage_metrics']) && is_array($payload['usage_metrics']) ? $payload['usage_metrics'] : [];

        if ($jobId <= 0 || $projectId <= 0) {
            throw new InvalidArgumentException('Identificadores de job_id e project_id são obrigatórios.');
        }

        $savedCount = WorkerJobRepository::completeJobWithCandidates(
            $jobId,
            $projectId,
            $candidates,
            $usageMetrics
        );

        echo json_encode([
            'ok'               => true,
            'saved_candidates' => $savedCount,
            'message'          => sprintf('%d candidatos de corte validados e salvos com sucesso.', $savedCount),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fail_job') {
        $jobId        = (int)($payload['job_id'] ?? 0);
        $projectId    = (int)($payload['project_id'] ?? 0);
        $errorMessage = trim((string)($payload['error_message'] ?? 'Erro não especificado durante o processamento externo.'));

        if ($jobId <= 0 || $projectId <= 0) {
            throw new InvalidArgumentException('Identificadores de job_id e project_id são obrigatórios.');
        }

        $updated = WorkerJobRepository::failJob($jobId, $projectId, $errorMessage);

        echo json_encode([
            'ok'      => $updated,
            'message' => $updated
                ? 'Falha registrada no projeto. O usuário pode repetir a tarefa pelo painel.'
                : 'Não foi possível localizar a tarefa informada.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'message' => 'Ação desconhecida na API do worker.',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException | DomainException $e) {
    http_response_code(422);
    echo json_encode([
        'ok'      => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[ClipIA Worker API Error] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'message' => 'Erro interno ao processar requisição do serviço Python.',
    ], JSON_UNESCAPED_UNICODE);
}
