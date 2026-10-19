<?php
declare(strict_types=1);

/**
 * Importação idempotente do resultado real do worker (`storage/audit_real_free_run.json`)
 * para exibição no painel web, sem reprocessar vídeo e sem duplicar candidatos.
 *
 * Obrigatório em produção: `projects.php` faz require deste arquivo (fatal se ausente).
 * A ação "import_audit_demo" só aparece/funciona se o JSON existir no servidor; o pacote
 * Hostinger não envia esse JSON nem clipes de audit — uso normal do site não depende dele.
 */

require_once __DIR__ . '/repositories.php';

class AuditDemoImporter
{
    public const IMPORT_KEY = 'audit_real_free_run';

    public const DEFAULT_JSON_REL = 'storage/audit_real_free_run.json';

    public const DEMO_PROJECT_TITLE = 'Demonstração — Teste real gratuito (Whisper + FFmpeg)';

    /**
     * Caminho absoluto padrão do JSON de auditoria dentro do projeto.
     */
    public static function getDefaultJsonPath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_JSON_REL);
    }

    public static function defaultJsonExists(): bool
    {
        $path = self::getDefaultJsonPath();
        return is_file($path) && is_readable($path);
    }

    /**
     * Normaliza e valida caminho relativo de clipe ou legenda (somente `storage/clips/`).
     */
    public static function normalizeClipStorageUrl(string $raw): ?string
    {
        $clean = ltrim(str_replace('\\', '/', trim($raw)), '/');
        if ($clean === '' || str_contains($clean, '..') || str_contains($clean, '://')) {
            return null;
        }
        if (!str_starts_with($clean, 'storage/clips/')) {
            return null;
        }
        $full = VideoUploadManager::resolveSafeMediaPath($clean);
        if ($full === null || !is_file($full)) {
            return null;
        }
        return $clean;
    }

    /**
     * Extrai caminho relativo seguro do vídeo de origem (`storage/samples/` ou `storage/uploads/`).
     */
    public static function normalizeSourceMediaRelPath(string $rawPath): ?string
    {
        $normalized = str_replace('\\', '/', trim($rawPath));
        if ($normalized === '') {
            return null;
        }
        if (preg_match('#(storage/(?:samples|uploads)/[A-Za-z0-9._\-/]+\.(?:mp4|mov|webm|wav))$#i', $normalized, $m)) {
            $rel = $m[1];
            if (str_contains($rel, '..')) {
                return null;
            }
            $full = VideoUploadManager::resolveSafeMediaPath($rel);
            return ($full !== null && is_file($full)) ? $rel : null;
        }
        return self::normalizeClipStorageUrl($normalized);
    }

    public static function findDemoProjectIdForUser(int $userId): ?int
    {
        $pdo = Database::getConnection();
        if ($pdo === null || $userId <= 0) {
            return null;
        }

        $needle = '%"import_key":"' . self::IMPORT_KEY . '"%';
        $stmt = $pdo->prepare(
            'SELECT p.id
             FROM projects p
             INNER JOIN processing_jobs pj ON pj.project_id = p.id AND pj.user_id = p.user_id
             WHERE p.user_id = :user_id
               AND pj.result_summary_json LIKE :needle
             ORDER BY p.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':needle'  => $needle,
        ]);
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['id'];
        }

        $stmtTitle = $pdo->prepare(
            'SELECT id FROM projects WHERE user_id = :user_id AND title = :title ORDER BY id DESC LIMIT 1'
        );
        $stmtTitle->execute([
            ':user_id' => $userId,
            ':title'   => self::DEMO_PROJECT_TITLE,
        ]);
        $rowTitle = $stmtTitle->fetch();

        return $rowTitle ? (int)$rowTitle['id'] : null;
    }

    /**
     * @return array{project_id:int,candidate_ids:array<int>,created:bool,import_key:string,view_path:string}
     */
    public static function importForUser(int $userId, ?string $jsonPath = null): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Usuário inválido para importação da demonstração.');
        }

        $path = $jsonPath ?? self::getDefaultJsonPath();
        $resolvedJson = realpath($path);
        $storageRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage');
        if (
            $resolvedJson === false
            || $storageRoot === false
            || !str_starts_with($resolvedJson, $storageRoot . DIRECTORY_SEPARATOR)
            || !is_file($resolvedJson)
            || !is_readable($resolvedJson)
            || !str_ends_with(strtolower($resolvedJson), '.json')
        ) {
            throw new DomainException(
                'Arquivo de resultado do teste real não encontrado ou fora de storage/. Execute o worker com --output-json storage/audit_real_free_run.json.'
            );
        }
        $path = $resolvedJson;

        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload) || empty($payload['ok'])) {
            throw new DomainException('JSON de auditoria inválido ou incompleto (campo ok ausente).');
        }

        $candidates = $payload['candidates'] ?? [];
        if (!is_array($candidates) || count($candidates) === 0) {
            throw new DomainException('O JSON de auditoria não contém candidatos de corte para importar.');
        }

        if (!empty($payload['usage_metrics']['paid_api_used'])) {
            throw new DomainException('Importação recusada: o resultado indica uso de API paga (paid_api_used=true).');
        }

        $params = is_array($payload['selection_parameters'] ?? null) ? $payload['selection_parameters'] : [];
        $aspectRatio = (string)($params['aspect_ratio'] ?? '9:16');
        $minDur = (int)round((float)($params['min_duration_seconds'] ?? 30));
        $maxDur = (int)round((float)($params['max_duration_seconds'] ?? 60));
        $targetClips = max(1, count($candidates));
        $subtitleStyle = SubtitleMetadataHelper::normalizeSubtitleStyle(
            is_array($params['subtitle_style'] ?? null) ? $params['subtitle_style'] : []
        );
        $subtitleConfigJson = json_encode($subtitleStyle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sourceRel = self::normalizeSourceMediaRelPath((string)($payload['source_file'] ?? ''));
        if ($sourceRel === null) {
            throw new DomainException('Caminho do vídeo de origem no JSON não é seguro ou o arquivo não existe.');
        }

        $sanitizedCandidates = [];
        foreach ($candidates as $idx => $cand) {
            if (!is_array($cand)) {
                throw new DomainException("Candidato #{$idx} inválido no JSON de auditoria.");
            }
            $mp4Rel = self::normalizeClipStorageUrl((string)($cand['storage_url'] ?? ''));
            if ($mp4Rel === null) {
                throw new DomainException("MP4 do candidato #{$idx} ausente ou fora de storage/clips.");
            }
            $srtRel = null;
            if (!empty($cand['srt_storage_url'])) {
                $srtRel = self::normalizeClipStorageUrl((string)$cand['srt_storage_url']);
            }
            $hashtagsRaw = $cand['hashtags'] ?? '';
            $hashtagsStr = is_array($hashtagsRaw)
                ? SubtitleMetadataHelper::formatHashtagsString($hashtagsRaw)
                : SubtitleMetadataHelper::formatHashtagsString((string)$hashtagsRaw);

            $styleJson = json_encode(
                SubtitleMetadataHelper::normalizeSubtitleStyle(
                    is_array($cand['subtitle_style'] ?? null) ? $cand['subtitle_style'] : $subtitleStyle
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $sanitizedCandidates[] = [
                'title'              => mb_substr(trim((string)($cand['title'] ?? 'Corte importado')), 0, 180),
                'short_description'  => mb_substr(trim((string)($cand['short_description'] ?? '')), 0, 500),
                'hashtags'           => mb_substr($hashtagsStr, 0, 500),
                'category'           => mb_substr(trim((string)($cand['category'] ?? 'explicacao')), 0, 64),
                'start_time_seconds' => round((float)($cand['start_time_seconds'] ?? 0), 2),
                'end_time_seconds'   => round((float)($cand['end_time_seconds'] ?? 0), 2),
                'duration_seconds'   => round((float)($cand['duration_seconds'] ?? 0), 2),
                'summary'            => mb_substr(trim((string)($cand['summary'] ?? '')), 0, 1000),
                'rationale'          => mb_substr(trim((string)($cand['rationale'] ?? '')), 0, 1000),
                'transcript_excerpt' => mb_substr(trim((string)($cand['transcript_excerpt'] ?? '')), 0, 4000),
                'relevance_score'    => (int)round((float)($cand['relevance_score'] ?? 80)),
                'storage_url'        => $mp4Rel,
                'srt_storage_url'    => $srtRel,
                'srt_content'        => mb_substr(trim((string)($cand['srt_content'] ?? '')), 0, 32000),
                'subtitles_burned'   => !empty($cand['subtitles_burned']) ? 1 : 0,
                'subtitle_style_json'=> $styleJson,
                'status'             => 'ready',
            ];
        }

        $pdo = Database::getConnection();
        if ($pdo === null) {
            throw new RuntimeException(Database::getPublicErrorMessage());
        }

        $existingProjectId = self::findDemoProjectIdForUser($userId);
        $created = ($existingProjectId === null);
        $now = date('Y-m-d H:i:s');

        $resultSummary = json_encode([
            'import_key'     => self::IMPORT_KEY,
            'source_json'    => self::DEFAULT_JSON_REL,
            'imported_at'    => $now,
            'usage_metrics'  => $payload['usage_metrics'] ?? [],
            'transcription_mode' => $payload['usage_metrics']['transcription_mode'] ?? 'faster_whisper_local',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pdo->beginTransaction();
        try {
            if ($existingProjectId !== null) {
                $projectId = $existingProjectId;
                $pdo->prepare(
                    'UPDATE projects
                     SET status = :status,
                         target_clips_count = :target_clips,
                         min_duration_seconds = :min_dur,
                         max_duration_seconds = :max_dur,
                         aspect_ratio = :aspect,
                         subtitle_config_json = :sub_cfg,
                         updated_at = :now
                     WHERE id = :id AND user_id = :user_id'
                )->execute([
                    ':status'      => 'completed',
                    ':target_clips'=> $targetClips,
                    ':min_dur'     => $minDur,
                    ':max_dur'     => $maxDur,
                    ':aspect'      => $aspectRatio,
                    ':sub_cfg'     => $subtitleConfigJson,
                    ':now'         => $now,
                    ':id'          => $projectId,
                    ':user_id'     => $userId,
                ]);

                $pdo->prepare('DELETE FROM clip_candidates WHERE project_id = :pid AND user_id = :uid')
                    ->execute([':pid' => $projectId, ':uid' => $userId]);

                $jobUpdate = $pdo->prepare(
                    'UPDATE processing_jobs
                     SET status = :status,
                         result_summary_json = :summary,
                         error_message = NULL,
                         finished_at = :now,
                         updated_at = :now
                     WHERE project_id = :pid AND user_id = :uid'
                );
                $jobUpdate->execute([
                    ':status'  => 'completed',
                    ':summary' => $resultSummary,
                    ':now'     => $now,
                    ':pid'     => $projectId,
                    ':uid'     => $userId,
                ]);

                if ($jobUpdate->rowCount() === 0) {
                    $pdo->prepare(
                        'INSERT INTO processing_jobs (
                            project_id, user_id, job_type, status, attempts, max_attempts,
                            result_summary_json, finished_at, payload_json, created_at, updated_at
                        ) VALUES (
                            :project_id, :user_id, :job_type, :status, 1, 3,
                            :summary, :now, :payload, :now, :now
                        )'
                    )->execute([
                        ':project_id' => $projectId,
                        ':user_id'    => $userId,
                        ':job_type'   => 'audit_demo_import',
                        ':status'     => 'completed',
                        ':summary'    => $resultSummary,
                        ':now'        => $now,
                        ':payload'    => json_encode(['import_key' => self::IMPORT_KEY], JSON_UNESCAPED_UNICODE),
                    ]);
                }

                $sourceBase = basename($sourceRel);
                $sourceSize = @filesize(VideoUploadManager::resolveSafeMediaPath($sourceRel) ?? '') ?: null;
                $svUpd = $pdo->prepare(
                    'UPDATE source_videos
                     SET source_mode = :source_mode,
                         uploaded_file_path = :uploaded_file_path,
                         uploaded_file_name = :uploaded_file_name,
                         uploaded_file_mime = :uploaded_file_mime,
                         uploaded_file_size = :uploaded_file_size
                     WHERE project_id = :project_id AND user_id = :user_id'
                );
                $svUpd->execute([
                    ':source_mode'        => 'uploaded_file',
                    ':uploaded_file_path' => $sourceRel,
                    ':uploaded_file_name' => $sourceBase,
                    ':uploaded_file_mime' => 'video/mp4',
                    ':uploaded_file_size' => $sourceSize,
                    ':project_id'         => $projectId,
                    ':user_id'            => $userId,
                ]);
                if ($svUpd->rowCount() === 0) {
                    $pdo->prepare(
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
                    )->execute([
                        ':project_id'         => $projectId,
                        ':user_id'            => $userId,
                        ':provider'           => 'youtube',
                        ':source_mode'        => 'uploaded_file',
                        ':source_url'         => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                        ':external_video_id'  => 'audit-demo-local',
                        ':video_title'        => self::DEMO_PROJECT_TITLE,
                        ':author_name'        => 'ClipIA — Teste local',
                        ':thumbnail_url'      => '',
                        ':embed_url'          => '',
                        ':embed_allowed'      => 0,
                        ':uploaded_file_path' => $sourceRel,
                        ':uploaded_file_name' => $sourceBase,
                        ':uploaded_file_mime' => 'video/mp4',
                        ':uploaded_file_size' => $sourceSize,
                        ':created_at'         => $now,
                    ]);
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO projects (
                        user_id, title, target_clips_count,
                        min_duration_seconds, max_duration_seconds,
                        aspect_ratio, subtitle_config_json, authorized_source_confirmed,
                        status, created_at, updated_at
                    ) VALUES (
                        :user_id, :title, :target_clips,
                        :min_dur, :max_dur, :aspect, :sub_cfg, 1,
                        :status, :now, :now
                    )'
                )->execute([
                    ':user_id'    => $userId,
                    ':title'      => self::DEMO_PROJECT_TITLE,
                    ':target_clips'=> $targetClips,
                    ':min_dur'    => $minDur,
                    ':max_dur'    => $maxDur,
                    ':aspect'     => $aspectRatio,
                    ':sub_cfg'    => $subtitleConfigJson,
                    ':status'     => 'completed',
                    ':now'        => $now,
                ]);
                $projectId = (int)$pdo->lastInsertId();

                $sourceBase = basename($sourceRel);
                $sourceSize = @filesize(VideoUploadManager::resolveSafeMediaPath($sourceRel) ?? '') ?: null;

                $pdo->prepare(
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
                )->execute([
                    ':project_id'         => $projectId,
                    ':user_id'            => $userId,
                    ':provider'           => 'youtube',
                    ':source_mode'        => 'uploaded_file',
                    ':source_url'         => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    ':external_video_id'  => 'audit-demo-local',
                    ':video_title'        => self::DEMO_PROJECT_TITLE,
                    ':author_name'        => 'ClipIA — Teste local',
                    ':thumbnail_url'      => '',
                    ':embed_url'          => '',
                    ':embed_allowed'      => 0,
                    ':uploaded_file_path' => $sourceRel,
                    ':uploaded_file_name' => $sourceBase,
                    ':uploaded_file_mime' => 'video/mp4',
                    ':uploaded_file_size' => $sourceSize,
                    ':created_at'         => $now,
                ]);
                $sourceVideoId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO processing_jobs (
                        project_id, user_id, job_type, status, attempts, max_attempts,
                        result_summary_json, finished_at, payload_json, created_at, updated_at
                    ) VALUES (
                        :project_id, :user_id, :job_type, :status, 1, 3,
                        :summary, :now, :payload, :now, :now
                    )'
                )->execute([
                    ':project_id' => $projectId,
                    ':user_id'    => $userId,
                    ':job_type'   => 'audit_demo_import',
                    ':status'     => 'completed',
                    ':summary'    => $resultSummary,
                    ':now'        => $now,
                    ':payload'    => json_encode(['import_key' => self::IMPORT_KEY], JSON_UNESCAPED_UNICODE),
                ]);
            }

            if (!isset($sourceVideoId)) {
                $stmtSv = $pdo->prepare(
                    'SELECT id FROM source_videos WHERE project_id = :pid AND user_id = :uid LIMIT 1'
                );
                $stmtSv->execute([':pid' => $projectId, ':uid' => $userId]);
                $svRow = $stmtSv->fetch();
                $sourceVideoId = $svRow ? (int)$svRow['id'] : 0;
            }

            $ins = $pdo->prepare(
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
                    :start, :end, :duration,
                    :aspect, :summary, :rationale, :transcript,
                    :score, :storage_url, :srt_storage_url, :srt_content,
                    :subtitles_burned, :subtitle_style_json, :status, :created_at
                )'
            );

            $candidateIds = [];
            foreach ($sanitizedCandidates as $cand) {
                $ins->execute([
                    ':project_id'          => $projectId,
                    ':source_video_id'     => $sourceVideoId,
                    ':user_id'             => $userId,
                    ':title'               => $cand['title'],
                    ':short_description'   => $cand['short_description'],
                    ':hashtags'            => $cand['hashtags'],
                    ':category'            => $cand['category'],
                    ':start'               => $cand['start_time_seconds'],
                    ':end'                 => $cand['end_time_seconds'],
                    ':duration'            => $cand['duration_seconds'],
                    ':aspect'              => $aspectRatio,
                    ':summary'             => $cand['summary'],
                    ':rationale'           => $cand['rationale'],
                    ':transcript'          => $cand['transcript_excerpt'],
                    ':score'               => $cand['relevance_score'],
                    ':storage_url'         => $cand['storage_url'],
                    ':srt_storage_url'     => $cand['srt_storage_url'],
                    ':srt_content'         => $cand['srt_content'] !== '' ? $cand['srt_content'] : null,
                    ':subtitles_burned'    => $cand['subtitles_burned'],
                    ':subtitle_style_json' => $cand['subtitle_style_json'],
                    ':status'              => $cand['status'],
                    ':created_at'          => $now,
                ]);
                $candidateIds[] = (int)$pdo->lastInsertId();
            }

            $pdo->commit();

            UsageLogRepository::log(
                $userId,
                'audit_demo_imported',
                sprintf(
                    'Demonstração importada do teste real (%s) — %d corte(s), projeto #%d.',
                    self::DEFAULT_JSON_REL,
                    count($candidateIds),
                    $projectId
                ),
                $projectId
            );

            return [
                'project_id'     => $projectId,
                'candidate_ids'  => $candidateIds,
                'created'        => $created,
                'import_key'     => self::IMPORT_KEY,
                'view_path'      => 'projects.php?view_candidates=' . $projectId,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
