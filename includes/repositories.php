<?php
declare(strict_types=1);

/**
 * ClipIA - Repositórios de Acesso a Dados (PDO + Prepared Statements)
 * Garante isolamento estrito por `user_id` e comunicação segura com o Worker Python.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/worker_client.php';
require_once __DIR__ . '/video_upload.php';
require_once __DIR__ . '/subtitles_metadata.php';

/**
 * Repositório de Logs de Uso e Atividades (`usage_logs`)
 */
class UsageLogRepository
{
    public static function log(
        int $userId,
        string $actionType,
        string $description,
        ?int $projectId = null,
        int $unitsConsumed = 0
    ): void {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO usage_logs (user_id, project_id, action_type, description, units_consumed, created_at)
                 VALUES (:user_id, :project_id, :action_type, :description, :units_consumed, :created_at)'
            );
            $stmt->execute([
                ':user_id'        => $userId,
                ':project_id'     => $projectId,
                ':action_type'    => $actionType,
                ':description'    => mb_substr($description, 0, 255),
                ':units_consumed' => max(0, $unitsConsumed),
                ':created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            error_log('[ClipIA UsageLogRepository Error] Falha ao registrar log de uso.');
        }
    }

    public static function listRecentByUser(int $userId, int $limit = 8): array
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return [];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT id, project_id, action_type, description, units_consumed, created_at
                 FROM usage_logs
                 WHERE user_id = :user_id
                 ORDER BY id DESC
                 LIMIT :lim'
            );
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[ClipIA UsageLogRepository Error] Falha ao listar logs.');
            return [];
        }
    }
}

/**
 * Repositório de Usuários (`users`)
 */
class UserRepository
{
    public static function findByEmail(string $email): ?array
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return null;
        }

        $normalizedEmail = mb_strtolower(trim($email));
        $stmt = $pdo->prepare(
            'SELECT id, name, email, password_hash, plan_tier,
                    default_aspect_ratio, default_clips_count,
                    default_min_duration, default_max_duration,
                    email_notifications, accepted_terms_at, last_login_at, created_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute([':email' => $normalizedEmail]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findById(int $userId): ?array
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT id, name, email, password_hash, plan_tier,
                    default_aspect_ratio, default_clips_count,
                    default_min_duration, default_max_duration,
                    email_notifications, accepted_terms_at, last_login_at, created_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function create(string $name, string $email, string $plainPassword): int
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            throw new RuntimeException(Database::getPublicErrorMessage());
        }

        $normalizedEmail = mb_strtolower(trim($email));
        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO users (
                name, email, password_hash, plan_tier,
                default_aspect_ratio, default_clips_count,
                default_min_duration, default_max_duration,
                email_notifications, accepted_terms_at, created_at, updated_at
            ) VALUES (
                :name, :email, :password_hash, :plan_tier,
                :default_aspect_ratio, :default_clips_count,
                :default_min_duration, :default_max_duration,
                :email_notifications, :accepted_terms_at, :created_at, :updated_at
            )'
        );

        $stmt->execute([
            ':name'                 => trim($name),
            ':email'                => $normalizedEmail,
            ':password_hash'        => $passwordHash,
            ':plan_tier'            => 'starter',
            ':default_aspect_ratio' => CLIP_DEFAULT_FORMAT,
            ':default_clips_count'  => CLIP_DEFAULT_COUNT,
            ':default_min_duration' => CLIP_DEFAULT_MIN_DURATION,
            ':default_max_duration' => CLIP_DEFAULT_MAX_DURATION,
            ':email_notifications'  => 1,
            ':accepted_terms_at'    => $now,
            ':created_at'           => $now,
            ':updated_at'           => $now,
        ]);

        $newUserId = (int)$pdo->lastInsertId();

        UsageLogRepository::log(
            $newUserId,
            'account_created',
            'Conta criada e termos de uso aceitos.'
        );

        return $newUserId;
    }

    /**
     * Garante a existência da conta administrativa do proprietário quando nenhuma conta existe no banco
     * e as variáveis `ADMIN_OWNER_EMAIL` + (`ADMIN_OWNER_PASSWORD_HASH` ou `ADMIN_OWNER_PASSWORD`) estão definidas.
     * Nunca sobrescreve usuários, projetos ou senhas existentes.
     */
    public static function ensureOwnerAccountIfEmpty(): void
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return;
        }

        try {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count > 0) {
                return;
            }

            $ownerEmail = mb_strtolower(trim((string)ADMIN_OWNER_EMAIL));
            $ownerName  = trim((string)ADMIN_OWNER_NAME) ?: 'Administrador ClipIA';
            $ownerHash  = trim((string)ADMIN_OWNER_PASSWORD_HASH);
            $ownerPlain = (string)ADMIN_OWNER_PASSWORD;

            if ($ownerEmail === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            if ($ownerHash === '' && $ownerPlain !== '') {
                $ownerHash = password_hash($ownerPlain, PASSWORD_DEFAULT);
            }

            if ($ownerHash === '') {
                return;
            }

            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                'INSERT INTO users (
                    name, email, password_hash, plan_tier,
                    default_aspect_ratio, default_clips_count,
                    default_min_duration, default_max_duration,
                    email_notifications, accepted_terms_at, created_at, updated_at
                ) VALUES (
                    :name, :email, :password_hash, :plan_tier,
                    :default_aspect_ratio, :default_clips_count,
                    :default_min_duration, :default_max_duration,
                    :email_notifications, :accepted_terms_at, :created_at, :updated_at
                )'
            );
            $stmt->execute([
                ':name'                 => $ownerName,
                ':email'                => $ownerEmail,
                ':password_hash'        => $ownerHash,
                ':plan_tier'            => 'owner',
                ':default_aspect_ratio' => CLIP_DEFAULT_FORMAT,
                ':default_clips_count'  => CLIP_DEFAULT_COUNT,
                ':default_min_duration' => CLIP_DEFAULT_MIN_DURATION,
                ':default_max_duration' => CLIP_DEFAULT_MAX_DURATION,
                ':email_notifications'  => 1,
                ':accepted_terms_at'    => $now,
                ':created_at'           => $now,
                ':updated_at'           => $now,
            ]);
        } catch (Throwable $e) {
            error_log('[ClipIA ensureOwnerAccountIfEmpty] ' . $e->getMessage());
        }
    }

    public static function touchLastLogin(int $userId): void
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return;
        }

        $stmt = $pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id');
        $stmt->execute([
            ':now' => date('Y-m-d H:i:s'),
            ':id'  => $userId,
        ]);
    }

    public static function updateProfile(int $userId, string $name, string $email): bool
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return false;
        }

        $normalizedEmail = mb_strtolower(trim($email));

        $check = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $check->execute([
            ':email' => $normalizedEmail,
            ':id'    => $userId,
        ]);
        if ($check->fetch()) {
            throw new DomainException('Este endereço de e-mail já está em uso por outra conta.');
        }

        $stmt = $pdo->prepare(
            'UPDATE users
             SET name = :name, email = :email, updated_at = :now
             WHERE id = :id'
        );
        $result = $stmt->execute([
            ':name'  => trim($name),
            ':email' => $normalizedEmail,
            ':now'   => date('Y-m-d H:i:s'),
            ':id'    => $userId,
        ]);

        if ($result) {
            UsageLogRepository::log($userId, 'profile_updated', 'Dados de perfil atualizados.');
        }
        return $result;
    }

    public static function updatePreferences(
        int $userId,
        string $aspectRatio,
        int $clipsCount,
        int $minDuration,
        int $maxDuration,
        bool $emailNotifications
    ): bool {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return false;
        }

        $stmt = $pdo->prepare(
            'UPDATE users
             SET default_aspect_ratio = :aspect_ratio,
                 default_clips_count = :clips_count,
                 default_min_duration = :min_duration,
                 default_max_duration = :max_duration,
                 email_notifications = :email_notifications,
                 updated_at = :now
             WHERE id = :id'
        );

        $result = $stmt->execute([
            ':aspect_ratio'        => $aspectRatio,
            ':clips_count'         => $clipsCount,
            ':min_duration'        => $minDuration,
            ':max_duration'        => $maxDuration,
            ':email_notifications' => $emailNotifications ? 1 : 0,
            ':now'                 => date('Y-m-d H:i:s'),
            ':id'                  => $userId,
        ]);

        if ($result) {
            UsageLogRepository::log($userId, 'preferences_updated', 'Preferências padrão de corte atualizadas.');
        }
        return $result;
    }

    public static function updatePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return false;
        }

        $user = self::findById($userId);
        if (!$user || !password_verify($currentPassword, (string)$user['password_hash'])) {
            throw new DomainException('A senha atual informada está incorreta.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'UPDATE users
             SET password_hash = :password_hash, updated_at = :now
             WHERE id = :id'
        );
        $result = $stmt->execute([
            ':password_hash' => $newHash,
            ':now'           => date('Y-m-d H:i:s'),
            ':id'            => $userId,
        ]);

        if ($result) {
            UsageLogRepository::log($userId, 'password_changed', 'Senha de acesso alterada com segurança.');
        }
        return $result;
    }
}

/**
 * Repositório de Projetos, Vídeos de Origem, Candidatos de Corte e Jobs
 */
class ProjectRepository
{
    public const VALID_STATUSES = [
        'awaiting_source',
        'awaiting_processing',
        'processing',
        'completed',
        'failed',
    ];

    public static function createForUser(int $userId, array $data): int
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            throw new RuntimeException(Database::getPublicErrorMessage());
        }

        $now              = date('Y-m-d H:i:s');
        $title            = trim((string)$data['title']);
        $sourceUrl        = trim((string)$data['source_url']);
        $externalVideoId  = trim((string)$data['external_video_id']);
        $videoTitle       = trim((string)($data['video_title'] ?? ''));
        $authorName       = trim((string)($data['author_name'] ?? ''));
        $thumbnailUrl     = trim((string)($data['thumbnail_url'] ?? ''));
        $embedUrl         = trim((string)($data['embed_url'] ?? ''));
        $embedAllowed     = isset($data['embed_allowed']) ? (bool)$data['embed_allowed'] : true;
        $sourceMode       = trim((string)($data['source_mode'] ?? 'url_only'));
        $uploadedFilePath = isset($data['uploaded_file_path']) ? (string)$data['uploaded_file_path'] : null;
        $uploadedFileName = isset($data['uploaded_file_name']) ? (string)$data['uploaded_file_name'] : null;
        $uploadedFileMime = isset($data['uploaded_file_mime']) ? (string)$data['uploaded_file_mime'] : null;
        $uploadedFileSize = isset($data['uploaded_file_size']) ? (int)$data['uploaded_file_size'] : null;

        $targetClipsCount = (int)$data['target_clips_count'];
        $minDuration      = (int)$data['min_duration_seconds'];
        $maxDuration      = (int)$data['max_duration_seconds'];
        $aspectRatio      = trim((string)$data['aspect_ratio']);
        $subtitleStyle    = SubtitleMetadataHelper::normalizeSubtitleStyle(
            is_array($data['subtitle_style'] ?? null) ? $data['subtitle_style'] : []
        );
        $subtitleConfigJson = json_encode($subtitleStyle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($thumbnailUrl === '' && $externalVideoId !== '') {
            $thumbnailUrl = 'https://i.ytimg.com/vi/' . $externalVideoId . '/hqdefault.jpg';
        }
        if ($embedUrl === '' && $externalVideoId !== '') {
            $embedUrl = 'https://www.youtube-nocookie.com/embed/' . $externalVideoId . '?rel=0';
        }
        if ($videoTitle === '') {
            $videoTitle = $title;
        }

        $initialProjectStatus = ($sourceMode === 'uploaded_file' && !empty($uploadedFilePath))
            ? 'awaiting_processing'
            : 'awaiting_source';

        $workerClient = new ExternalWorkerClient();

        $pdo->beginTransaction();

        try {
            $stmtProj = $pdo->prepare(
                'INSERT INTO projects (
                    user_id, title, target_clips_count,
                    min_duration_seconds, max_duration_seconds,
                    aspect_ratio, subtitle_config_json, authorized_source_confirmed,
                    status, created_at, updated_at
                ) VALUES (
                    :user_id, :title, :target_clips_count,
                    :min_duration_seconds, :max_duration_seconds,
                    :aspect_ratio, :subtitle_config_json, :authorized_source_confirmed,
                    :status, :created_at, :updated_at
                )'
            );
            $stmtProj->execute([
                ':user_id'                     => $userId,
                ':title'                       => $title,
                ':target_clips_count'          => $targetClipsCount,
                ':min_duration_seconds'        => $minDuration,
                ':max_duration_seconds'        => $maxDuration,
                ':aspect_ratio'                => $aspectRatio,
                ':subtitle_config_json'        => $subtitleConfigJson,
                ':authorized_source_confirmed' => 1,
                ':status'                      => $initialProjectStatus,
                ':created_at'                  => $now,
                ':updated_at'                  => $now,
            ]);

            $projectId = (int)$pdo->lastInsertId();

            $stmtVideo = $pdo->prepare(
                'INSERT INTO source_videos (
                    project_id, user_id, provider, source_mode, source_url,
                    external_video_id, video_title, author_name, thumbnail_url,
                    embed_url, embed_allowed,
                    uploaded_file_path, uploaded_file_name, uploaded_file_mime, uploaded_file_size,
                    created_at
                ) VALUES (
                    :project_id, :user_id, :provider, :source_mode, :source_url,
                    :external_video_id, :video_title, :author_name, :thumbnail_url,
                    :embed_url, :embed_allowed,
                    :uploaded_file_path, :uploaded_file_name, :uploaded_file_mime, :uploaded_file_size,
                    :created_at
                )'
            );
            $stmtVideo->execute([
                ':project_id'         => $projectId,
                ':user_id'            => $userId,
                ':provider'           => 'youtube',
                ':source_mode'        => $sourceMode,
                ':source_url'         => $sourceUrl,
                ':external_video_id'  => $externalVideoId,
                ':video_title'        => mb_substr($videoTitle, 0, 255),
                ':author_name'        => $authorName !== '' ? mb_substr($authorName, 0, 180) : null,
                ':thumbnail_url'      => mb_substr($thumbnailUrl, 0, 500),
                ':embed_url'          => mb_substr($embedUrl, 0, 500),
                ':embed_allowed'      => $embedAllowed ? 1 : 0,
                ':uploaded_file_path' => $uploadedFilePath,
                ':uploaded_file_name' => $uploadedFileName,
                ':uploaded_file_mime' => $uploadedFileMime,
                ':uploaded_file_size' => $uploadedFileSize,
                ':created_at'         => $now,
            ]);

            $payloadArray = $workerClient->buildJobPayload([
                'project_id'           => $projectId,
                'user_id'              => $userId,
                'source_mode'          => $sourceMode,
                'source_url'           => $sourceUrl,
                'external_video_id'    => $externalVideoId,
                'uploaded_file_path'   => $uploadedFilePath,
                'uploaded_file_name'   => $uploadedFileName,
                'uploaded_file_mime'   => $uploadedFileMime,
                'target_clips_count'   => $targetClipsCount,
                'min_duration_seconds' => $minDuration,
                'max_duration_seconds' => $maxDuration,
                'aspect_ratio'         => $aspectRatio,
                'subtitle_style'       => $subtitleStyle,
            ]);

            $jobStatus = ($initialProjectStatus === 'awaiting_processing') ? 'queued' : 'pending';
            $payloadJson = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $stmtJob = $pdo->prepare(
                'INSERT INTO processing_jobs (
                    project_id, user_id, job_type, status, attempts, payload_json, created_at, updated_at
                ) VALUES (
                    :project_id, :user_id, :job_type, :status, 0, :payload_json, :created_at, :updated_at
                )'
            );
            $stmtJob->execute([
                ':project_id'   => $projectId,
                ':user_id'      => $userId,
                ':job_type'     => 'find_highlights',
                ':status'       => $jobStatus,
                ':payload_json' => $payloadJson,
                ':created_at'   => $now,
                ':updated_at'   => $now,
            ]);

            $pdo->commit();

            $statusDesc = ($initialProjectStatus === 'awaiting_processing')
                ? 'aguardando processamento (arquivo autorizado enviado)'
                : 'aguardando fonte autorizada';

            UsageLogRepository::log(
                $userId,
                'project_created',
                sprintf('Projeto "%s" criado [ID YouTube: %s] — %s.', $title, $externalVideoId, $statusDesc),
                $projectId,
                0
            );

            return $projectId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ClipIA ProjectRepository Error] Falha ao criar projeto: ' . $e->getMessage());
            throw new RuntimeException('Não foi possível salvar o projeto no banco de dados.');
        }
    }

    public static function attachAuthorizedFileForUser(int $projectId, int $userId, array $uploadData): bool
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return false;
        }

        $existing = self::findByIdForUser($projectId, $userId);
        if ($existing === null) {
            return false;
        }

        $workerClient = new ExternalWorkerClient();
        $updatedPayloadArray = $workerClient->buildJobPayload([
            'project_id'           => $projectId,
            'user_id'              => $userId,
            'source_mode'          => 'uploaded_file',
            'source_url'           => $existing['source_url'] ?? '',
            'external_video_id'    => $existing['video_external_id'] ?? '',
            'uploaded_file_path'   => $uploadData['relative_path'],
            'uploaded_file_name'   => $uploadData['original_name'],
            'uploaded_file_mime'   => $uploadData['mime_type'],
            'target_clips_count'   => $existing['target_clips'] ?? CLIP_DEFAULT_COUNT,
            'min_duration_seconds' => $existing['min_duration'] ?? CLIP_DEFAULT_MIN_DURATION,
            'max_duration_seconds' => $existing['max_duration'] ?? CLIP_DEFAULT_MAX_DURATION,
            'aspect_ratio'         => $existing['aspect_ratio'] ?? CLIP_DEFAULT_FORMAT,
            'subtitle_style'       => $existing['subtitle_style'] ?? SubtitleMetadataHelper::getDefaultSubtitleStyle(),
        ]);
        $updatedPayloadJson = json_encode($updatedPayloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();

        try {
            $stmtVideo = $pdo->prepare(
                'UPDATE source_videos
                 SET source_mode = :source_mode,
                     uploaded_file_path = :file_path,
                     uploaded_file_name = :file_name,
                     uploaded_file_mime = :file_mime,
                     uploaded_file_size = :file_size
                 WHERE project_id = :project_id AND user_id = :user_id'
            );
            $stmtVideo->execute([
                ':source_mode' => 'uploaded_file',
                ':file_path'   => $uploadData['relative_path'],
                ':file_name'   => $uploadData['original_name'],
                ':file_mime'   => $uploadData['mime_type'],
                ':file_size'   => $uploadData['size_bytes'],
                ':project_id'  => $projectId,
                ':user_id'     => $userId,
            ]);

            $stmtProj = $pdo->prepare(
                "UPDATE projects
                 SET status = 'awaiting_processing', updated_at = :now
                 WHERE id = :id AND user_id = :user_id"
            );
            $stmtProj->execute([
                ':now'     => $now,
                ':id'      => $projectId,
                ':user_id' => $userId,
            ]);

            $stmtJob = $pdo->prepare(
                "UPDATE processing_jobs
                 SET status = 'queued', payload_json = :payload_json, error_message = NULL, updated_at = :now
                 WHERE project_id = :project_id AND user_id = :user_id"
            );
            $stmtJob->execute([
                ':payload_json' => $updatedPayloadJson,
                ':now'          => $now,
                ':project_id'   => $projectId,
                ':user_id'      => $userId,
            ]);

            $pdo->commit();

            UsageLogRepository::log(
                $userId,
                'source_uploaded',
                sprintf('Fonte de vídeo autorizada (%s) enviada para o projeto "%s".', $uploadData['original_name'], $existing['title']),
                $projectId
            );

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ClipIA attachAuthorizedFileForUser Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Permite ao usuário proprietário repetir (retry) uma tarefa que tenha falhado (`failed`).
     */
    public static function retryFailedProjectForUser(int $projectId, int $userId): bool
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return false;
        }

        $proj = self::findByIdForUser($projectId, $userId);
        if ($proj === null || $proj['status'] !== 'failed') {
            return false;
        }

        // Só pode ir para a fila de processamento se houver fonte autorizada anexada
        $nextProjStatus = ($proj['source_mode'] === 'uploaded_file' && $proj['uploaded_file_name'] !== '')
            ? 'awaiting_processing'
            : 'awaiting_source';
        $nextJobStatus = ($nextProjStatus === 'awaiting_processing') ? 'queued' : 'pending';

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();

        try {
            $stmtProj = $pdo->prepare(
                'UPDATE projects SET status = :status, updated_at = :now WHERE id = :id AND user_id = :user_id'
            );
            $stmtProj->execute([
                ':status'  => $nextProjStatus,
                ':now'     => $now,
                ':id'      => $projectId,
                ':user_id' => $userId,
            ]);

            $stmtJob = $pdo->prepare(
                'UPDATE processing_jobs
                 SET status = :status, attempts = 0, error_message = NULL, finished_at = NULL, updated_at = :now
                 WHERE project_id = :project_id AND user_id = :user_id'
            );
            $stmtJob->execute([
                ':status'     => $nextJobStatus,
                ':now'        => $now,
                ':project_id' => $projectId,
                ':user_id'    => $userId,
            ]);

            $pdo->commit();

            UsageLogRepository::log(
                $userId,
                'job_retried',
                sprintf('Nova tentativa de análise solicitada para o projeto "%s".', $proj['title']),
                $projectId
            );

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    /**
     * Lista os candidatos de corte (`clip_candidates`) de um projeto específico pertencente ao usuário.
     */
    public static function listCandidatesByProjectForUser(int $projectId, int $userId): array
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT id, project_id, title, short_description, hashtags, category,
                    start_time_seconds, end_time_seconds, duration_seconds,
                    aspect_ratio, summary, rationale, transcript_excerpt,
                    relevance_score, storage_url, srt_storage_url, srt_content,
                    subtitles_burned, subtitle_style_json, status, created_at
             FROM clip_candidates
             WHERE project_id = :project_id AND user_id = :user_id
             ORDER BY relevance_score DESC, start_time_seconds ASC'
        );
        $stmt->execute([
            ':project_id' => $projectId,
            ':user_id'    => $userId,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Busca um candidato de corte específico garantindo isolamento estrito por `user_id`.
     */
    public static function findCandidateByIdForUser(int $candidateId, int $userId): ?array
    {
        $pdo = Database::getConnection();
        if ($pdo === null || $candidateId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT id, project_id, user_id, title, short_description, hashtags, category,
                    start_time_seconds, end_time_seconds, duration_seconds,
                    aspect_ratio, summary, rationale, transcript_excerpt,
                    relevance_score, storage_url, srt_storage_url, srt_content,
                    subtitles_burned, subtitle_style_json, status, created_at
             FROM clip_candidates
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id'      => $candidateId,
            ':user_id' => $userId,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Atualiza o título, a descrição curta e as hashtags de um candidato de corte pertencente ao usuário.
     */
    public static function updateCandidateMetadataForUser(
        int $candidateId,
        int $userId,
        string $title,
        string $shortDescription,
        string $hashtags
    ): bool {
        $pdo = Database::getConnection();
        if ($pdo === null || $candidateId <= 0) {
            return false;
        }

        $cand = self::findCandidateByIdForUser($candidateId, $userId);
        if ($cand === null) {
            return false;
        }

        $cleanTitle = mb_substr(trim($title), 0, 180);
        if ($cleanTitle === '') {
            throw new DomainException('O título do corte não pode ficar em branco.');
        }

        $cleanDesc = mb_substr(trim($shortDescription), 0, 500);
        $cleanTags = mb_substr(SubtitleMetadataHelper::formatHashtagsString($hashtags), 0, 500);

        $stmt = $pdo->prepare(
            'UPDATE clip_candidates
             SET title = :title,
                 short_description = :short_description,
                 hashtags = :hashtags
             WHERE id = :id AND user_id = :user_id'
        );
        $ok = $stmt->execute([
            ':title'             => $cleanTitle,
            ':short_description' => $cleanDesc,
            ':hashtags'          => $cleanTags,
            ':id'                => $candidateId,
            ':user_id'           => $userId,
        ]);

        if ($ok) {
            UsageLogRepository::log(
                $userId,
                'clip_metadata_updated',
                sprintf('Metadados do corte "%s" atualizados.', mb_substr($cleanTitle, 0, 60)),
                (int)$cand['project_id']
            );
        }

        return $ok;
    }

    /**
     * Atualiza o estado de legenda e caminhos renderizados (`storage_url`, `srt_storage_url`, `srt_content`,
     * `subtitles_burned`, `subtitle_style_json`) de um candidato pertencente ao usuário.
     */
    public static function updateCandidateSubtitlesForUser(
        int $candidateId,
        int $userId,
        array $renderResult
    ): bool {
        $pdo = Database::getConnection();
        if ($pdo === null || $candidateId <= 0) {
            return false;
        }

        $cand = self::findCandidateByIdForUser($candidateId, $userId);
        if ($cand === null) {
            return false;
        }

        $style = SubtitleMetadataHelper::normalizeSubtitleStyle(
            is_array($renderResult['subtitle_style'] ?? null) ? $renderResult['subtitle_style'] : []
        );
        $styleJson = json_encode($style, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $storageUrl = !empty($renderResult['storage_url'])
            ? (string)$renderResult['storage_url']
            : (string)($cand['storage_url'] ?? '');
        $srtStorageUrl = !empty($renderResult['srt_storage_url'])
            ? (string)$renderResult['srt_storage_url']
            : (string)($cand['srt_storage_url'] ?? '');
        $srtContent = !empty($renderResult['srt_content'])
            ? (string)$renderResult['srt_content']
            : (string)($cand['srt_content'] ?? '');
        $burned = !empty($renderResult['subtitles_burned']) ? 1 : 0;

        $stmt = $pdo->prepare(
            'UPDATE clip_candidates
             SET storage_url = :storage_url,
                 srt_storage_url = :srt_storage_url,
                 srt_content = :srt_content,
                 subtitles_burned = :subtitles_burned,
                 subtitle_style_json = :subtitle_style_json,
                 status = :status
             WHERE id = :id AND user_id = :user_id'
        );
        $ok = $stmt->execute([
            ':storage_url'         => $storageUrl !== '' ? $storageUrl : null,
            ':srt_storage_url'     => $srtStorageUrl !== '' ? $srtStorageUrl : null,
            ':srt_content'         => $srtContent !== '' ? $srtContent : null,
            ':subtitles_burned'    => $burned,
            ':subtitle_style_json' => $styleJson,
            ':status'              => $storageUrl !== '' ? 'ready' : (string)$cand['status'],
            ':id'                  => $candidateId,
            ':user_id'             => $userId,
        ]);

        if ($ok) {
            UsageLogRepository::log(
                $userId,
                'clip_subtitles_rendered',
                sprintf(
                    'Legenda reprocessada para o corte "%s" (%s).',
                    mb_substr((string)$cand['title'], 0, 50),
                    $burned ? 'incorporada ao MP4' : 'vídeo limpo + SRT separado'
                ),
                (int)$cand['project_id']
            );
        }

        return $ok;
    }

    public static function listByUser(int $userId, int $limit = 100): array
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT
                p.id,
                p.user_id,
                p.title,
                p.target_clips_count,
                p.min_duration_seconds,
                p.max_duration_seconds,
                p.aspect_ratio,
                p.subtitle_config_json,
                p.status,
                p.created_at,
                sv.source_mode,
                sv.source_url,
                sv.external_video_id,
                sv.video_title,
                sv.author_name,
                sv.thumbnail_url,
                sv.embed_url,
                sv.embed_allowed,
                sv.uploaded_file_path,
                sv.uploaded_file_name,
                sv.uploaded_file_size,
                (SELECT COUNT(*) FROM clip_candidates cc WHERE cc.project_id = p.id AND cc.user_id = p.user_id) AS generated_clips_count,
                (SELECT pj.error_message FROM processing_jobs pj WHERE pj.project_id = p.id AND pj.user_id = p.user_id ORDER BY pj.id DESC LIMIT 1) AS last_error_message,
                (SELECT pj.attempts FROM processing_jobs pj WHERE pj.project_id = p.id AND pj.user_id = p.user_id ORDER BY pj.id DESC LIMIT 1) AS job_attempts
             FROM projects p
             LEFT JOIN source_videos sv ON sv.project_id = p.id AND sv.user_id = p.user_id
             WHERE p.user_id = :user_id
             ORDER BY p.id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return array_map([self::class, 'formatProjectRow'], $rows);
    }

    public static function findByIdForUser(int $projectId, int $userId): ?array
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT
                p.id,
                p.user_id,
                p.title,
                p.target_clips_count,
                p.min_duration_seconds,
                p.max_duration_seconds,
                p.aspect_ratio,
                p.subtitle_config_json,
                p.status,
                p.created_at,
                sv.source_mode,
                sv.source_url,
                sv.external_video_id,
                sv.video_title,
                sv.author_name,
                sv.thumbnail_url,
                sv.embed_url,
                sv.embed_allowed,
                sv.uploaded_file_path,
                sv.uploaded_file_name,
                sv.uploaded_file_size,
                (SELECT COUNT(*) FROM clip_candidates cc WHERE cc.project_id = p.id AND cc.user_id = p.user_id) AS generated_clips_count,
                (SELECT pj.error_message FROM processing_jobs pj WHERE pj.project_id = p.id AND pj.user_id = p.user_id ORDER BY pj.id DESC LIMIT 1) AS last_error_message,
                (SELECT pj.attempts FROM processing_jobs pj WHERE pj.project_id = p.id AND pj.user_id = p.user_id ORDER BY pj.id DESC LIMIT 1) AS job_attempts
             FROM projects p
             LEFT JOIN source_videos sv ON sv.project_id = p.id AND sv.user_id = p.user_id
             WHERE p.id = :id AND p.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id'      => $projectId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        return $row ? self::formatProjectRow($row) : null;
    }

    public static function updateForUser(
        int $projectId,
        int $userId,
        string $title,
        int $targetClipsCount,
        string $aspectRatio
    ): bool {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return false;
        }

        $existing = self::findByIdForUser($projectId, $userId);
        if ($existing === null) {
            return false;
        }

        $stmt = $pdo->prepare(
            'UPDATE projects
             SET title = :title,
                 target_clips_count = :target_clips_count,
                 aspect_ratio = :aspect_ratio,
                 updated_at = :now
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':title'              => trim($title),
            ':target_clips_count' => $targetClipsCount,
            ':aspect_ratio'       => $aspectRatio,
            ':now'                => date('Y-m-d H:i:s'),
            ':id'                 => $projectId,
            ':user_id'            => $userId,
        ]);

        if ($stmt->rowCount() > 0) {
            UsageLogRepository::log(
                $userId,
                'project_updated',
                sprintf('Projeto "%s" atualizado.', trim($title)),
                $projectId
            );
            return true;
        }
        return false;
    }

    public static function deleteForUser(int $projectId, int $userId): bool
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return false;
        }

        $existing = self::findByIdForUser($projectId, $userId);
        if ($existing === null) {
            return false;
        }

        $candidatesToClean = self::listCandidatesByProjectForUser($projectId, $userId);

        $stmt = $pdo->prepare('DELETE FROM projects WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            ':id'      => $projectId,
            ':user_id' => $userId,
        ]);

        if ($stmt->rowCount() > 0) {
            if (!empty($existing['uploaded_file_path'])) {
                VideoUploadManager::deleteStoredFile((string)$existing['uploaded_file_path']);
            }
            foreach ($candidatesToClean as $candRow) {
                if (!empty($candRow['storage_url'])) {
                    VideoUploadManager::deleteStoredFile((string)$candRow['storage_url']);
                }
                if (!empty($candRow['srt_storage_url'])) {
                    VideoUploadManager::deleteStoredFile((string)$candRow['srt_storage_url']);
                }
            }
            UsageLogRepository::log(
                $userId,
                'project_deleted',
                sprintf('Projeto "%s" excluído.', $existing['title']),
                null
            );
            return true;
        }

        return false;
    }

    public static function getStatsForUser(int $userId): array
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return [
                'total_projects'         => 0,
                'total_videos_processed' => 0,
                'total_clips_generated'  => 0,
                'pending_jobs'           => 0,
            ];
        }

        $stmtProjects = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE user_id = :user_id');
        $stmtProjects->execute([':user_id' => $userId]);
        $totalProjects = (int)$stmtProjects->fetchColumn();

        $stmtProcessed = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE user_id = :user_id AND status = 'completed'");
        $stmtProcessed->execute([':user_id' => $userId]);
        $totalVideosProcessed = (int)$stmtProcessed->fetchColumn();

        $stmtClips = $pdo->prepare('SELECT COUNT(*) FROM clip_candidates WHERE user_id = :user_id');
        $stmtClips->execute([':user_id' => $userId]);
        $totalClipsGenerated = (int)$stmtClips->fetchColumn();

        $stmtJobs = $pdo->prepare("SELECT COUNT(*) FROM processing_jobs WHERE user_id = :user_id AND status IN ('pending', 'queued', 'running')");
        $stmtJobs->execute([':user_id' => $userId]);
        $pendingJobs = (int)$stmtJobs->fetchColumn();

        return [
            'total_projects'         => $totalProjects,
            'total_videos_processed' => $totalVideosProcessed,
            'total_clips_generated'  => $totalClipsGenerated,
            'pending_jobs'           => $pendingJobs,
        ];
    }

    public static function getStatusMetadata(string $status): array
    {
        return match ($status) {
            'awaiting_source', 'draft' => [
                'key'         => 'awaiting_source',
                'label'       => 'Aguardando fonte',
                'badge_class' => 'badge-warning',
            ],
            'awaiting_processing', 'queued' => [
                'key'         => 'awaiting_processing',
                'label'       => 'Aguardando processamento',
                'badge_class' => 'badge-violet',
            ],
            'processing' => [
                'key'         => 'processing',
                'label'       => 'Processando',
                'badge_class' => 'badge-violet',
            ],
            'completed' => [
                'key'         => 'completed',
                'label'       => 'Concluído',
                'badge_class' => 'badge-success',
            ],
            'failed' => [
                'key'         => 'failed',
                'label'       => 'Falhou',
                'badge_class' => 'badge-danger',
            ],
            default => [
                'key'         => 'awaiting_source',
                'label'       => 'Aguardando fonte',
                'badge_class' => 'badge-warning',
            ],
        };
    }

    private static function formatProjectRow(array $row): array
    {
        $rawStatus = (string)($row['status'] ?? 'awaiting_source');
        $statusMeta = self::getStatusMetadata($rawStatus);

        $createdAtRaw = (string)($row['created_at'] ?? '');
        $createdAtFormatted = $createdAtRaw !== ''
            ? date('d/m/Y H:i', strtotime($createdAtRaw))
            : date('d/m/Y H:i');

        $videoId = (string)($row['external_video_id'] ?? '');
        $embedUrl = (string)($row['embed_url'] ?? '');
        if ($embedUrl === '' && $videoId !== '') {
            $embedUrl = 'https://www.youtube-nocookie.com/embed/' . $videoId . '?rel=0';
        }

        $rawSubCfg = isset($row['subtitle_config_json']) && is_string($row['subtitle_config_json'])
            ? json_decode($row['subtitle_config_json'], true)
            : [];
        $subtitleStyle = SubtitleMetadataHelper::normalizeSubtitleStyle(is_array($rawSubCfg) ? $rawSubCfg : []);

        return [
            'id'                 => (int)$row['id'],
            'user_id'            => (int)$row['user_id'],
            'title'              => (string)$row['title'],
            'source_mode'        => (string)($row['source_mode'] ?? 'url_only'),
            'source_url'         => (string)($row['source_url'] ?? ''),
            'video_external_id'  => $videoId,
            'video_title'        => (string)($row['video_title'] ?? $row['title']),
            'author_name'        => (string)($row['author_name'] ?? ''),
            'thumbnail_url'      => (string)($row['thumbnail_url'] ?? ''),
            'embed_url'          => $embedUrl,
            'embed_allowed'      => !isset($row['embed_allowed']) || (bool)$row['embed_allowed'],
            'uploaded_file_path' => (string)($row['uploaded_file_path'] ?? ''),
            'uploaded_file_name' => (string)($row['uploaded_file_name'] ?? ''),
            'uploaded_file_size' => isset($row['uploaded_file_size']) ? (int)$row['uploaded_file_size'] : 0,
            'aspect_ratio'       => (string)($row['aspect_ratio'] ?? '9:16'),
            'subtitle_style'     => $subtitleStyle,
            'target_clips'       => (int)($row['target_clips_count'] ?? 5),
            'GeneratedClips'     => (int)($row['generated_clips_count'] ?? 0),
            'min_duration'       => (int)($row['min_duration_seconds'] ?? 30),
            'max_duration'       => (int)($row['max_duration_seconds'] ?? 60),
            'status'             => $statusMeta['key'],
            'status_label'       => $statusMeta['label'],
            'status_badge_class' => $statusMeta['badge_class'],
            'last_error_message' => (string)($row['last_error_message'] ?? ''),
            'job_attempts'       => (int)($row['job_attempts'] ?? 0),
            'created_at'         => $createdAtFormatted,
        ];
    }
}

/**
 * Repositório Exclusivo de Comunicação Autenticada com o Serviço Externo Python (`WorkerJobRepository`)
 *
 * Garante que:
 * 1. Apenas projetos com fonte autorizada (`source_mode = 'uploaded_file'`) sejam entregues ao worker.
 * 2. Nunca entrega URLs arbitrárias para download.
 * 3. Valida e persiste os candidatos de corte (`clip_candidates`), atualiza `processing_jobs` e `projects`,
 *    e registra consumo/erros em `usage_logs`.
 */
class WorkerJobRepository
{
    /**
     * Garante que as tabelas de controle de orçamento compartilhado (`monthly_budgets` e `budget_reservations`)
     * existam no banco (MySQL ou SQLite de teste).
     * IMPORTANTE: Em MySQL, `CREATE TABLE` causa commit implícito, portanto deve ser chamado ANTES de abrir transações.
     */
    private static bool $budgetTablesEnsured = false;

    public static function ensureBudgetTables(PDO $pdo): void
    {
        if (self::$budgetTablesEnsured) {
            return;
        }
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS monthly_budgets (
                    month_key TEXT PRIMARY KEY,
                    spent_brl REAL NOT NULL DEFAULT 0.0,
                    reserved_brl REAL NOT NULL DEFAULT 0.0,
                    calls_count INTEGER NOT NULL DEFAULT 0,
                    prompt_tokens INTEGER NOT NULL DEFAULT 0,
                    completion_tokens INTEGER NOT NULL DEFAULT 0,
                    updated_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS budget_reservations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    reservation_token TEXT NOT NULL UNIQUE,
                    month_key TEXT NOT NULL,
                    job_id INTEGER NOT NULL,
                    project_id INTEGER NOT NULL,
                    reserved_brl REAL NOT NULL DEFAULT 0.0,
                    settled_brl REAL NULL,
                    status TEXT NOT NULL DEFAULT 'reserved',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `monthly_budgets` (
                    `month_key` CHAR(7) NOT NULL,
                    `spent_brl` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                    `reserved_brl` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                    `calls_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `prompt_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `completion_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`month_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `budget_reservations` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `reservation_token` VARCHAR(64) NOT NULL,
                    `month_key` CHAR(7) NOT NULL,
                    `job_id` BIGINT UNSIGNED NOT NULL,
                    `project_id` BIGINT UNSIGNED NOT NULL,
                    `reserved_brl` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
                    `settled_brl` DECIMAL(12,4) DEFAULT NULL,
                    `status` ENUM('reserved', 'settled', 'released') NOT NULL DEFAULT 'reserved',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_budget_reservation_token` (`reservation_token`),
                    KEY `idx_budget_res_month_status` (`month_key`, `status`),
                    KEY `idx_budget_res_job_status` (`job_id`, `status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
        self::$budgetTablesEnsured = true;
    }

    /**
     * Inicia uma transação rastreada pelo PDO (`beginTransaction()`) aplicando `SET SESSION TRANSACTION ISOLATION LEVEL`
     * quando necessário.
     */
    private static function beginTrackedTransaction(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
    }

    /**
     * Retorna o estado atual do orçamento mensal compartilhado no banco de dados (spent_brl, reserved_brl).
     */
    public static function getMonthlyBudgetSnapshot(?string $monthKey = null): array
    {
        $pdo = Database::getConnection();
        $month = $monthKey ?? gmdate('Y-m');
        if ($pdo === null) {
            return [
                'month_key'    => $month,
                'spent_brl'    => 0.0,
                'reserved_brl' => 0.0,
                'calls_count'  => 0,
            ];
        }

        try {
            self::ensureBudgetTables($pdo);
            $stmt = $pdo->prepare('SELECT spent_brl, reserved_brl, calls_count FROM monthly_budgets WHERE month_key = :m LIMIT 1');
            $stmt->execute([':m' => $month]);
            $row = $stmt->fetch();

            // Calcula também o histórico dos jobs concluídos caso haja registros anteriores à criação de monthly_budgets
            $monthPrefix = $month . '%';
            $stmtJobs = $pdo->prepare(
                "SELECT result_summary_json
                 FROM processing_jobs
                 WHERE status = 'completed'
                   AND finished_at LIKE :month_prefix
                   AND result_summary_json IS NOT NULL"
            );
            $stmtJobs->execute([':month_prefix' => $monthPrefix]);
            $jobSpent = 0.0;
            foreach ($stmtJobs->fetchAll() as $r) {
                $decoded = json_decode((string)($r['result_summary_json'] ?? ''), true);
                if (is_array($decoded) && isset($decoded['estimated_cost_brl'])) {
                    $jobSpent += max(0.0, (float)$decoded['estimated_cost_brl']);
                }
            }

            $tableSpent = $row ? max(0.0, (float)$row['spent_brl']) : 0.0;
            $tableReserved = $row ? max(0.0, (float)$row['reserved_brl']) : 0.0;
            $effectiveSpent = round(max($tableSpent, $jobSpent), 4);

            return [
                'month_key'    => $month,
                'spent_brl'    => $effectiveSpent,
                'reserved_brl' => round($tableReserved, 4),
                'calls_count'  => $row ? (int)$row['calls_count'] : 0,
            ];
        } catch (Throwable $e) {
            return [
                'month_key'    => $month,
                'spent_brl'    => 0.0,
                'reserved_brl' => 0.0,
                'calls_count'  => 0,
            ];
        }
    }

    /**
     * Calcula o gasto acumulado no mês corrente (em R$) + reservas ativas no banco de dados.
     */
    public static function getMonthlySpentBrl(): float
    {
        $snap = self::getMonthlyBudgetSnapshot();
        return round((float)$snap['spent_brl'] + (float)$snap['reserved_brl'], 4);
    }

    /**
     * Reserva atomicamente no banco de dados o custo estimado de uma chamada antes que o worker consulte a API paga.
     * Previne race conditions quando múltiplos workers executam simultaneamente.
     */
    public static function reserveBudgetForCall(
        int $jobId,
        int $projectId,
        float $reserveBrl,
        ?float $maxMonthlyBrl = null,
        ?float $maxJobBrl = null
    ): array {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            throw new RuntimeException('Banco de dados indisponível para reserva de orçamento.');
        }

        self::ensureBudgetTables($pdo);

        $reserveAmount = round(max(0.005, $reserveBrl), 4);
        $monthlyCap = $maxMonthlyBrl !== null && $maxMonthlyBrl > 0
            ? $maxMonthlyBrl
            : (defined('MAX_MONTHLY_COST_BRL') ? (float)MAX_MONTHLY_COST_BRL : 100.00);
        $jobCap = $maxJobBrl !== null && $maxJobBrl > 0
            ? $maxJobBrl
            : (defined('MAX_COST_PER_JOB_BRL') ? (float)MAX_COST_PER_JOB_BRL : 2.50);

        $month = gmdate('Y-m');
        $now = gmdate('Y-m-d H:i:s');
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        self::beginTrackedTransaction($pdo);

        try {
            // Garante a linha do mês atual
            if ($driver === 'sqlite') {
                $insMonth = $pdo->prepare(
                    'INSERT OR IGNORE INTO monthly_budgets (month_key, spent_brl, reserved_brl, calls_count, prompt_tokens, completion_tokens, updated_at)
                     VALUES (:m, 0.0, 0.0, 0, 0, 0, :now)'
                );
            } else {
                $insMonth = $pdo->prepare(
                    'INSERT IGNORE INTO monthly_budgets (month_key, spent_brl, reserved_brl, calls_count, prompt_tokens, completion_tokens, updated_at)
                     VALUES (:m, 0.0, 0.0, 0, 0, 0, :now)'
                );
            }
            $insMonth->execute([':m' => $month, ':now' => $now]);

            // Bloqueia a linha do mês para leitura/atualização atômica
            $lockSql = 'SELECT spent_brl, reserved_brl FROM monthly_budgets WHERE month_key = :m';
            if ($driver === 'mysql') {
                $lockSql .= ' FOR UPDATE';
            }
            $stmtLock = $pdo->prepare($lockSql);
            $stmtLock->execute([':m' => $month]);
            $monthRow = $stmtLock->fetch();

            $spentMonth = $monthRow ? (float)$monthRow['spent_brl'] : 0.0;
            $reservedMonth = $monthRow ? (float)$monthRow['reserved_brl'] : 0.0;

            // Calcula o total já gasto + reservado na tarefa atual (job_id)
            $stmtJobUsage = $pdo->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN status = 'settled' THEN settled_brl ELSE 0 END), 0) AS job_settled,
                    COALESCE(SUM(CASE WHEN status = 'reserved' THEN reserved_brl ELSE 0 END), 0) AS job_reserved
                 FROM budget_reservations
                 WHERE job_id = :job_id"
            );
            $stmtJobUsage->execute([':job_id' => $jobId]);
            $jobUsage = $stmtJobUsage->fetch();
            $jobCommitted = ((float)($jobUsage['job_settled'] ?? 0.0)) + ((float)($jobUsage['job_reserved'] ?? 0.0));

            if (($jobCommitted + $reserveAmount) > ($jobCap + 1e-6)) {
                $pdo->rollBack();
                return [
                    'allowed'            => false,
                    'reason'             => sprintf(
                        'Limite de gasto por tarefa atingido no banco compartilhado (comprometido na tarefa: R$ %.4f, reserva solicitada: R$ %.4f, limite: R$ %.2f).',
                        $jobCommitted,
                        $reserveAmount,
                        $jobCap
                    ),
                    'reservation_token'  => null,
                    'monthly_spent_brl'  => round($spentMonth, 4),
                    'monthly_reserved_brl' => round($reservedMonth, 4),
                ];
            }

            if (($spentMonth + $reservedMonth + $reserveAmount) > ($monthlyCap + 1e-6)) {
                $pdo->rollBack();
                return [
                    'allowed'            => false,
                    'reason'             => sprintf(
                        'Teto mensal compartilhado atingido no banco de dados (gasto: R$ %.2f, reservado: R$ %.2f, nova chamada: R$ %.4f, teto: R$ %.2f).',
                        $spentMonth,
                        $reservedMonth,
                        $reserveAmount,
                        $monthlyCap
                    ),
                    'reservation_token'  => null,
                    'monthly_spent_brl'  => round($spentMonth, 4),
                    'monthly_reserved_brl' => round($reservedMonth, 4),
                ];
            }

            $token = 'res_' . bin2hex(random_bytes(16));
            $insRes = $pdo->prepare(
                "INSERT INTO budget_reservations (
                    reservation_token, month_key, job_id, project_id, reserved_brl, status, created_at, updated_at
                ) VALUES (
                    :token, :month_key, :job_id, :project_id, :reserved_brl, 'reserved', :now, :now
                )"
            );
            $insRes->execute([
                ':token'        => $token,
                ':month_key'    => $month,
                ':job_id'       => $jobId,
                ':project_id'   => $projectId,
                ':reserved_brl' => $reserveAmount,
                ':now'          => $now,
            ]);

            $newReservedMonth = round($reservedMonth + $reserveAmount, 4);
            $updMonth = $pdo->prepare(
                'UPDATE monthly_budgets SET reserved_brl = :res, updated_at = :now WHERE month_key = :m'
            );
            $updMonth->execute([
                ':res' => $newReservedMonth,
                ':now' => $now,
                ':m'   => $month,
            ]);

            $pdo->commit();

            return [
                'allowed'              => true,
                'reason'               => null,
                'reservation_token'    => $token,
                'reserved_brl'         => $reserveAmount,
                'monthly_spent_brl'    => round($spentMonth, 4),
                'monthly_reserved_brl' => $newReservedMonth,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ClipIA reserveBudgetForCall Error] ' . $e->getMessage());
            throw new RuntimeException('Falha ao reservar orçamento no banco de dados.');
        }
    }

    /**
     * Liquida (`settled`) ou libera (`released`, quando actualCostBrl = 0) uma reserva de orçamento no banco.
     */
    public static function settleBudgetReservation(
        string $reservationToken,
        float $actualCostBrl,
        int $promptTokens = 0,
        int $completionTokens = 0,
        bool $releaseOnly = false
    ): array {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            throw new RuntimeException('Banco de dados indisponível para liquidação de orçamento.');
        }

        self::ensureBudgetTables($pdo);

        $now = gmdate('Y-m-d H:i:s');
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        self::beginTrackedTransaction($pdo);

        try {
            $sqlRes = 'SELECT id, month_key, reserved_brl, status FROM budget_reservations WHERE reservation_token = :t';
            if ($driver === 'mysql') {
                $sqlRes .= ' FOR UPDATE';
            }
            $stmtRes = $pdo->prepare($sqlRes);
            $stmtRes->execute([':t' => trim($reservationToken)]);
            $resRow = $stmtRes->fetch();

            if (!$resRow) {
                $pdo->rollBack();
                throw new DomainException('Reserva de orçamento não encontrada.');
            }

            $month = (string)$resRow['month_key'];
            $reservedBrl = (float)$resRow['reserved_brl'];
            $currentStatus = (string)$resRow['status'];

            $sqlMonth = 'SELECT spent_brl, reserved_brl, calls_count, prompt_tokens, completion_tokens FROM monthly_budgets WHERE month_key = :m';
            if ($driver === 'mysql') {
                $sqlMonth .= ' FOR UPDATE';
            }
            $stmtM = $pdo->prepare($sqlMonth);
            $stmtM->execute([':m' => $month]);
            $mRow = $stmtM->fetch();

            if ($currentStatus !== 'reserved') {
                $pdo->commit();
                return [
                    'ok'                   => true,
                    'status'               => $currentStatus,
                    'monthly_spent_brl'    => $mRow ? round((float)$mRow['spent_brl'], 4) : 0.0,
                    'monthly_reserved_brl' => $mRow ? round((float)$mRow['reserved_brl'], 4) : 0.0,
                ];
            }

            $settledBrl = $releaseOnly ? 0.0 : round(max(0.0, $actualCostBrl), 4);
            $nextStatus = $releaseOnly ? 'released' : 'settled';

            $updRes = $pdo->prepare(
                'UPDATE budget_reservations
                 SET settled_brl = :settled, status = :status, updated_at = :now
                 WHERE id = :id'
            );
            $updRes->execute([
                ':settled' => $settledBrl,
                ':status'  => $nextStatus,
                ':now'     => $now,
                ':id'      => (int)$resRow['id'],
            ]);

            $prevSpent = $mRow ? (float)$mRow['spent_brl'] : 0.0;
            $prevReserved = $mRow ? (float)$mRow['reserved_brl'] : 0.0;
            $prevCalls = $mRow ? (int)$mRow['calls_count'] : 0;
            $prevPrompt = $mRow ? (int)$mRow['prompt_tokens'] : 0;
            $prevCompl = $mRow ? (int)$mRow['completion_tokens'] : 0;

            $newReserved = round(max(0.0, $prevReserved - $reservedBrl), 4);
            $newSpent = round($prevSpent + $settledBrl, 4);
            $newCalls = $releaseOnly ? $prevCalls : ($prevCalls + 1);
            $newPrompt = $releaseOnly ? $prevPrompt : ($prevPrompt + max(0, $promptTokens));
            $newCompl = $releaseOnly ? $prevCompl : ($prevCompl + max(0, $completionTokens));

            $updMonth = $pdo->prepare(
                'UPDATE monthly_budgets
                 SET spent_brl = :spent,
                     reserved_brl = :reserved,
                     calls_count = :calls,
                     prompt_tokens = :pt,
                     completion_tokens = :ct,
                     updated_at = :now
                 WHERE month_key = :m'
            );
            $updMonth->execute([
                ':spent'    => $newSpent,
                ':reserved' => $newReserved,
                ':calls'    => $newCalls,
                ':pt'       => $newPrompt,
                ':ct'       => $newCompl,
                ':now'      => $now,
                ':m'        => $month,
            ]);

            $pdo->commit();

            return [
                'ok'                   => true,
                'status'               => $nextStatus,
                'settled_brl'          => $settledBrl,
                'monthly_spent_brl'    => $newSpent,
                'monthly_reserved_brl' => $newReserved,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof DomainException) {
                throw $e;
            }
            error_log('[ClipIA settleBudgetReservation Error] ' . $e->getMessage());
            throw new RuntimeException('Falha ao liquidar reserva de orçamento.');
        }
    }

    /**
     * Libera todas as reservas pendentes (`status = 'reserved'`) vinculadas a um job que falhou ou terminou.
     */
    public static function releaseAllOpenReservationsForJob(int $jobId): void
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return;
        }
        try {
            self::ensureBudgetTables($pdo);
            $stmt = $pdo->prepare(
                "SELECT reservation_token FROM budget_reservations WHERE job_id = :job_id AND status = 'reserved'"
            );
            $stmt->execute([':job_id' => $jobId]);
            foreach ($stmt->fetchAll() as $row) {
                self::settleBudgetReservation((string)$row['reservation_token'], 0.0, 0, 0, true);
            }
        } catch (Throwable $e) {
            error_log('[ClipIA releaseAllOpenReservationsForJob] ' . $e->getMessage());
        }
    }

    /**
     * Recupera automaticamente tarefas travadas em `running` há mais de `staleSeconds`
     * (por exemplo, se um worker externo caiu no meio da execução).
     */
    public static function recoverStaleRunningJobs(?int $staleSeconds = null): int
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return 0;
        }

        $timeout = $staleSeconds ?? (defined('WORKER_JOB_STALE_TIMEOUT_SECONDS') ? (int)WORKER_JOB_STALE_TIMEOUT_SECONDS : 1800);
        $cutoff = date('Y-m-d H:i:s', time() - max(60, $timeout));
        $now = date('Y-m-d H:i:s');

        try {
            $stmt = $pdo->prepare(
                "SELECT id, project_id, attempts, max_attempts
                 FROM processing_jobs
                 WHERE status = 'running' AND started_at IS NOT NULL AND started_at < :cutoff"
            );
            $stmt->execute([':cutoff' => $cutoff]);
            $staleRows = $stmt->fetchAll();

            $recovered = 0;
            foreach ($staleRows as $row) {
                $jid = (int)$row['id'];
                $pid = (int)$row['project_id'];
                $att = (int)$row['attempts'];
                $maxAtt = (int)$row['max_attempts'];

                self::releaseAllOpenReservationsForJob($jid);

                if ($att < $maxAtt) {
                    $pdo->prepare("UPDATE processing_jobs SET status = 'queued', updated_at = :now WHERE id = :id AND status = 'running'")
                        ->execute([':now' => $now, ':id' => $jid]);
                    $pdo->prepare("UPDATE projects SET status = 'awaiting_processing', updated_at = :now WHERE id = :id")
                        ->execute([':now' => $now, ':id' => $pid]);
                } else {
                    $pdo->prepare("UPDATE processing_jobs SET status = 'failed', error_message = 'Tempo limite de execução do worker excedido.', finished_at = :now, updated_at = :now WHERE id = :id AND status = 'running'")
                        ->execute([':now' => $now, ':id' => $jid]);
                    $pdo->prepare("UPDATE projects SET status = 'failed', updated_at = :now WHERE id = :id")
                        ->execute([':now' => $now, ':id' => $pid]);
                }
                $recovered++;
            }
            return $recovered;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Reserva atomicamente a próxima tarefa na fila (`status = 'queued'` e `attempts < max_attempts`)
     * que possua fonte autorizada, garantindo que dois workers nunca processem a mesma tarefa simultaneamente.
     */
    public static function claimNextAuthorizedJob(?int $specificProjectId = null): ?array
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return null;
        }

        self::recoverStaleRunningJobs();

        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::beginTrackedTransaction($pdo);

        try {
            $sql = "SELECT
                        pj.id AS job_id,
                        pj.project_id,
                        pj.user_id,
                        pj.job_type,
                        pj.attempts,
                        p.title AS project_title,
                        p.target_clips_count,
                        p.min_duration_seconds,
                        p.max_duration_seconds,
                        p.aspect_ratio,
                        p.subtitle_config_json,
                        p.authorized_source_confirmed,
                        sv.id AS source_video_id,
                        sv.source_mode,
                        sv.external_video_id,
                        sv.uploaded_file_path,
                        sv.uploaded_file_name,
                        sv.uploaded_file_mime
                    FROM processing_jobs pj
                    INNER JOIN projects p ON p.id = pj.project_id AND p.user_id = pj.user_id
                    INNER JOIN source_videos sv ON sv.project_id = p.id AND sv.user_id = p.user_id
                    WHERE pj.status = 'queued'
                      AND pj.attempts < pj.max_attempts
                      AND p.authorized_source_confirmed = 1
                      AND sv.source_mode = 'uploaded_file'
                      AND sv.uploaded_file_path IS NOT NULL";

            $params = [];
            if ($specificProjectId !== null && $specificProjectId > 0) {
                $sql .= ' AND pj.project_id = :project_id';
                $params[':project_id'] = $specificProjectId;
            }

            $sql .= ' ORDER BY pj.id ASC LIMIT 1';
            if ($driver === 'mysql') {
                $sql .= ' FOR UPDATE';
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $job = $stmt->fetch();

            if (!$job) {
                $pdo->commit();
                return null;
            }

            $now = date('Y-m-d H:i:s');
            $jobId = (int)$job['job_id'];
            $projectId = (int)$job['project_id'];
            $userId = (int)$job['user_id'];
            $newAttempts = ((int)$job['attempts']) + 1;

            // Atualização condicional atômica (CAS): garante que apenas 1 worker altere de 'queued' para 'running'
            $updJob = $pdo->prepare(
                "UPDATE processing_jobs
                 SET status = 'running', attempts = :attempts, started_at = :now, updated_at = :now
                 WHERE id = :job_id AND status = 'queued'"
            );
            $updJob->execute([
                ':attempts' => $newAttempts,
                ':now'      => $now,
                ':job_id'   => $jobId,
            ]);

            if ($updJob->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }

            $updProj = $pdo->prepare(
                "UPDATE projects SET status = 'processing', updated_at = :now WHERE id = :project_id"
            );
            $updProj->execute([
                ':now'        => $now,
                ':project_id' => $projectId,
            ]);

            $pdo->commit();

            UsageLogRepository::log(
                $userId,
                'job_started',
                sprintf('Serviço Python iniciou análise da tentativa #%d para o projeto "%s".', $newAttempts, $job['project_title']),
                $projectId
            );

            $rawSubCfg = isset($job['subtitle_config_json']) && is_string($job['subtitle_config_json'])
                ? json_decode($job['subtitle_config_json'], true)
                : [];
            $subtitleStyle = SubtitleMetadataHelper::normalizeSubtitleStyle(is_array($rawSubCfg) ? $rawSubCfg : []);

            return [
                'job_id'               => $jobId,
                'project_id'           => $projectId,
                'user_id'              => $userId,
                'source_video_id'      => (int)$job['source_video_id'],
                'attempt'              => $newAttempts,
                'project_title'        => (string)$job['project_title'],
                'authorized_source'    => [
                    'source_mode'        => (string)$job['source_mode'],
                    'external_video_id'  => (string)$job['external_video_id'],
                    'uploaded_file_path' => (string)$job['uploaded_file_path'],
                    'uploaded_file_name' => (string)$job['uploaded_file_name'],
                    'uploaded_file_mime' => (string)$job['uploaded_file_mime'],
                ],
                'clip_parameters'      => [
                    'target_clips_count'   => (int)$job['target_clips_count'],
                    'min_duration_seconds' => (int)$job['min_duration_seconds'],
                    'max_duration_seconds' => (int)$job['max_duration_seconds'],
                    'aspect_ratio'         => (string)$job['aspect_ratio'],
                    'subtitle_style'       => $subtitleStyle,
                ],
                'monthly_spent_brl'    => self::getMonthlySpentBrl(),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ClipIA claimNextAuthorizedJob Error] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Recebe, valida e salva os candidatos de corte analisados pelo Worker Python.
     */
    public static function completeJobWithCandidates(
        int $jobId,
        int $projectId,
        array $rawCandidates,
        array $usageMetrics = []
    ): int {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            throw new RuntimeException('Banco de dados indisponível.');
        }

        $stmt = $pdo->prepare(
            'SELECT pj.id, pj.user_id, pj.project_id,
                    p.title, p.target_clips_count, p.min_duration_seconds, p.max_duration_seconds, p.aspect_ratio,
                    sv.id AS source_video_id, sv.source_mode
             FROM processing_jobs pj
             INNER JOIN projects p ON p.id = pj.project_id AND p.user_id = pj.user_id
             INNER JOIN source_videos sv ON sv.project_id = p.id AND sv.user_id = p.user_id
             WHERE pj.id = :job_id AND pj.project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute([
            ':job_id'     => $jobId,
            ':project_id' => $projectId,
        ]);
        $jobRow = $stmt->fetch();

        if (!$jobRow) {
            throw new DomainException('Tarefa de processamento não localizada para o projeto informado.');
        }

        if (($jobRow['source_mode'] ?? '') !== 'uploaded_file') {
            throw new DomainException('Operação negada: o projeto não possui fonte de vídeo autorizada vinculada.');
        }

        $userId        = (int)$jobRow['user_id'];
        $sourceVideoId = (int)$jobRow['source_video_id'];
        $minDuration   = (int)$jobRow['min_duration_seconds'];
        $maxDuration   = (int)$jobRow['max_duration_seconds'];
        $maxCount      = (int)$jobRow['target_clips_count'];
        $aspectRatio   = (string)$jobRow['aspect_ratio'];
        $videoDurLimit = isset($usageMetrics['audio_duration_seconds']) ? (float)$usageMetrics['audio_duration_seconds'] : 0.0;

        $validatedCandidates = ExternalWorkerClient::validateCandidatesPayload(
            $rawCandidates,
            $minDuration,
            $maxDuration,
            $maxCount,
            $videoDurLimit
        );

        $now = date('Y-m-d H:i:s');
        $resultSummaryJson = json_encode($usageMetrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $pdo->beginTransaction();

        try {
            // Remove candidatos anteriores caso seja uma reexecução
            $delStmt = $pdo->prepare('DELETE FROM clip_candidates WHERE project_id = :project_id AND user_id = :user_id');
            $delStmt->execute([
                ':project_id' => $projectId,
                ':user_id'    => $userId,
            ]);

            $insCandidate = $pdo->prepare(
                'INSERT INTO clip_candidates (
                    project_id, source_video_id, user_id,
                    title, short_description, hashtags, category,
                    start_time_seconds, end_time_seconds, duration_seconds,
                    aspect_ratio, summary, rationale, transcript_excerpt,
                    relevance_score, storage_url, srt_storage_url, srt_content,
                    subtitles_burned, subtitle_style_json, status, created_at
                ) VALUES (
                    :project_id, :source_video_id, :user_id,
                    :title, :short_description, :hashtags, :category,
                    :start_time_seconds, :end_time_seconds, :duration_seconds,
                    :aspect_ratio, :summary, :rationale, :transcript_excerpt,
                    :relevance_score, :storage_url, :srt_storage_url, :srt_content,
                    :subtitles_burned, :subtitle_style_json, :status, :created_at
                )'
            );

            foreach ($validatedCandidates as $cand) {
                $styleJson = json_encode(
                    $cand['subtitle_style'] ?? SubtitleMetadataHelper::getDefaultSubtitleStyle(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                $insCandidate->execute([
                    ':project_id'          => $projectId,
                    ':source_video_id'     => $sourceVideoId,
                    ':user_id'             => $userId,
                    ':title'               => $cand['title'],
                    ':short_description'   => $cand['short_description'] ?? null,
                    ':hashtags'            => $cand['hashtags'] ?? null,
                    ':category'            => $cand['category'],
                    ':start_time_seconds'  => $cand['start_time_seconds'],
                    ':end_time_seconds'    => $cand['end_time_seconds'],
                    ':duration_seconds'    => $cand['duration_seconds'],
                    ':aspect_ratio'        => $aspectRatio,
                    ':summary'             => $cand['summary'],
                    ':rationale'           => $cand['rationale'],
                    ':transcript_excerpt'  => $cand['transcript_excerpt'],
                    ':relevance_score'     => $cand['relevance_score'],
                    ':storage_url'         => $cand['storage_url'] ?? null,
                    ':srt_storage_url'     => $cand['srt_storage_url'] ?? null,
                    ':srt_content'         => $cand['srt_content'] ?? null,
                    ':subtitles_burned'    => isset($cand['subtitles_burned']) ? (int)$cand['subtitles_burned'] : 1,
                    ':subtitle_style_json' => $styleJson,
                    ':status'              => $cand['status'] ?? 'candidate',
                    ':created_at'          => $now,
                ]);
            }

            $updJob = $pdo->prepare(
                "UPDATE processing_jobs
                 SET status = 'completed', result_summary_json = :summary_json, error_message = NULL, finished_at = :now, updated_at = :now
                 WHERE id = :job_id"
            );
            $updJob->execute([
                ':summary_json' => $resultSummaryJson,
                ':now'          => $now,
                ':job_id'       => $jobId,
            ]);

            $updProj = $pdo->prepare(
                "UPDATE projects SET status = 'completed', updated_at = :now WHERE id = :project_id"
            );
            $updProj->execute([
                ':now'        => $now,
                ':project_id' => $projectId,
            ]);

            $pdo->commit();

            self::releaseAllOpenReservationsForJob($jobId);

            $audioDurationSec = (int)($usageMetrics['audio_duration_seconds'] ?? 0);
            $llmTokensUsed    = (int)($usageMetrics['llm_tokens_used'] ?? 0);
            $providerUsed     = (string)($usageMetrics['analysis_provider'] ?? 'local');
            $costBrl          = isset($usageMetrics['estimated_cost_brl']) ? (float)$usageMetrics['estimated_cost_brl'] : 0.0;

            UsageLogRepository::log(
                $userId,
                'analysis_completed',
                sprintf(
                    'Análise concluída para "%s": %d cortes candidatos gerados (provedor: %s, tokens IA: %d, custo: R$ %.4f).',
                    $jobRow['title'],
                    count($validatedCandidates),
                    $providerUsed,
                    $llmTokensUsed,
                    $costBrl
                ),
                $projectId,
                max(1, (int)ceil($audioDurationSec / 60))
            );

            return count($validatedCandidates);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ClipIA completeJobWithCandidates Error] ' . $e->getMessage());
            throw new RuntimeException('Falha ao persistir os candidatos de corte no banco de dados.');
        }
    }

    /**
     * Registra falha de execução reportada pelo Worker Python e atualiza o status do projeto para `failed`.
     */
    public static function failJob(int $jobId, int $projectId, string $errorMessage): bool
    {
        $pdo = Database::getConnection();
        if ($pdo === null) {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT pj.id, pj.user_id, p.title
             FROM processing_jobs pj
             INNER JOIN projects p ON p.id = pj.project_id
             WHERE pj.id = :job_id AND pj.project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute([
            ':job_id'     => $jobId,
            ':project_id' => $projectId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $cleanError = mb_substr(trim($errorMessage), 0, 480);

        $pdo->beginTransaction();
        try {
            $updJob = $pdo->prepare(
                "UPDATE processing_jobs
                 SET status = 'failed', error_message = :err, finished_at = :now, updated_at = :now
                 WHERE id = :job_id"
            );
            $updJob->execute([
                ':err'    => $cleanError,
                ':now'    => $now,
                ':job_id' => $jobId,
            ]);

            $updProj = $pdo->prepare(
                "UPDATE projects SET status = 'failed', updated_at = :now WHERE id = :project_id"
            );
            $updProj->execute([
                ':now'        => $now,
                ':project_id' => $projectId,
            ]);

            $pdo->commit();

            self::releaseAllOpenReservationsForJob($jobId);

            UsageLogRepository::log(
                (int)$row['user_id'],
                'analysis_failed',
                sprintf('Falha na análise do projeto "%s": %s', $row['title'], mb_substr($cleanError, 0, 140)),
                $projectId
            );

            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }
}
