<?php
declare(strict_types=1);

/**
 * ClipIA — Interface, Autenticação HMAC e Validação de Contrato do Serviço Externo Python
 *
 * IMPORTANTE:
 * 1. A hospedagem compartilhada Hostinger executa apenas PHP + MySQL.
 * 2. O serviço Python roda em ambiente separado e comunica-se via HTTPS usando assinatura HMAC-SHA256
 *    (`X-ClipIA-Timestamp` + `X-ClipIA-Signature`) sobre o corpo bruto da requisição.
 * 3. Aceita apenas tarefas cuja fonte de vídeo seja autorizada (`source_mode = 'uploaded_file'`)
 *    e nunca processa URLs arbitrárias enviadas por usuários.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/subtitles_metadata.php';

interface ProcessingServiceInterface
{
    public function isConfigured(): bool;

    public function buildJobPayload(array $projectData): array;

    public function dispatchJob(int $projectId, int $userId, array $payload): array;
}

class ExternalWorkerClient implements ProcessingServiceInterface
{
    private bool $enabled;
    private string $secret;

    public function __construct()
    {
        $this->secret  = (string)WORKER_SHARED_SECRET;
        $this->enabled = strlen($this->secret) >= 16;
    }

    public function isConfigured(): bool
    {
        return $this->enabled;
    }

    public function buildJobPayload(array $projectData): array
    {
        return [
            'schema_version' => '1.1',
            'project_id'     => (int)($projectData['project_id'] ?? 0),
            'user_id'        => (int)($projectData['user_id'] ?? 0),
            'source'         => [
                'provider'           => 'youtube',
                'source_mode'        => (string)($projectData['source_mode'] ?? 'url_only'),
                'original_url'       => (string)($projectData['source_url'] ?? ''),
                'external_video_id'  => (string)($projectData['external_video_id'] ?? ''),
                'uploaded_file_path' => $projectData['uploaded_file_path'] ?? null,
                'uploaded_file_name' => $projectData['uploaded_file_name'] ?? null,
                'uploaded_file_mime' => $projectData['uploaded_file_mime'] ?? null,
            ],
            'clip_parameters' => [
                'target_clips_count'   => (int)($projectData['target_clips_count'] ?? CLIP_DEFAULT_COUNT),
                'min_duration_seconds' => (int)($projectData['min_duration_seconds'] ?? CLIP_DEFAULT_MIN_DURATION),
                'max_duration_seconds' => (int)($projectData['max_duration_seconds'] ?? CLIP_DEFAULT_MAX_DURATION),
                'aspect_ratio'         => (string)($projectData['aspect_ratio'] ?? CLIP_DEFAULT_FORMAT),
                'subtitle_style'       => SubtitleMetadataHelper::normalizeSubtitleStyle(
                    is_array($projectData['subtitle_style'] ?? null) ? $projectData['subtitle_style'] : []
                ),
            ],
            'created_at' => date('c'),
        ];
    }

    public function dispatchJob(int $projectId, int $userId, array $payload): array
    {
        if (!$this->isConfigured()) {
            return [
                'dispatched'      => false,
                'worker_state'    => 'standby_not_configured',
                'external_job_id' => null,
                'message'         => 'Serviço externo de processamento (Python) aguardando configuração de WORKER_SHARED_SECRET. O job permanece salvo na fila do MySQL.',
            ];
        }

        return [
            'dispatched'      => true,
            'worker_state'    => 'queued_for_polling',
            'external_job_id' => null,
            'message'         => 'Tarefa autenticada disponível na fila para o Worker Python.',
        ];
    }

    /**
     * Calcula a assinatura HMAC-SHA256 padronizada entre PHP e Python:
     * `HMAC_SHA256(secret, "{timestamp}.{rawBody}")`
     */
    public static function computeSignature(string $secret, int $timestamp, string $rawBody): string
    {
        $signingString = $timestamp . '.' . $rawBody;
        return hash_hmac('sha256', $signingString, $secret);
    }

    /**
     * Verifica se a requisição recebida do Worker Python é autêntica, recente (janela anti-replay de 300s)
     * e utiliza HTTPS quando exigido em produção.
     */
    public static function verifyIncomingRequest(
        array $serverVars,
        string $rawBody,
        ?string $overrideSecret = null
    ): array {
        $secret = $overrideSecret !== null ? $overrideSecret : (string)WORKER_SHARED_SECRET;

        if (strlen($secret) < 16) {
            return [
                'ok'    => false,
                'code'  => 503,
                'error' => 'A chave compartilhada do Worker (WORKER_SHARED_SECRET) não está configurada no servidor (mínimo de 16 caracteres).',
            ];
        }

        if (defined('APP_ENV') && APP_ENV === 'production' && clipia_is_disallowed_worker_secret_for_production($secret)) {
            return [
                'ok'    => false,
                'code'  => 503,
                'error' => 'WORKER_SHARED_SECRET inválido para produção (valor de exemplo ou de desenvolvimento).',
            ];
        }

        // Em produção (Hostinger), exige tráfego HTTPS
        $isHttps = (!empty($serverVars['HTTPS']) && $serverVars['HTTPS'] !== 'off')
            || (($serverVars['SERVER_PORT'] ?? '') === '443')
            || (strtolower((string)($serverVars['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

        if (WORKER_REQUIRE_HTTPS && !$isHttps) {
            return [
                'ok'    => false,
                'code'  => 403,
                'error' => 'Conexão insegura rejeitada. A API de integração entre serviços exige HTTPS.',
            ];
        }

        $timestampHeader = (string)($serverVars['HTTP_X_CLIPIA_TIMESTAMP'] ?? '');
        $signatureHeader = strtolower(trim((string)($serverVars['HTTP_X_CLIPIA_SIGNATURE'] ?? '')));

        if ($timestampHeader === '' || !ctype_digit($timestampHeader) || $signatureHeader === '') {
            return [
                'ok'    => false,
                'code'  => 401,
                'error' => 'Cabeçalhos de autenticação entre serviços ausentes (X-ClipIA-Timestamp / X-ClipIA-Signature).',
            ];
        }

        $timestamp = (int)$timestampHeader;
        if (abs(time() - $timestamp) > 300) {
            return [
                'ok'    => false,
                'code'  => 401,
                'error' => 'Timestamp da requisição expirado (janela máxima de 300 segundos).',
            ];
        }

        $expectedSignature = self::computeSignature($secret, $timestamp, $rawBody);
        if (!hash_equals($expectedSignature, $signatureHeader)) {
            return [
                'ok'    => false,
                'code'  => 401,
                'error' => 'Assinatura HMAC-SHA256 inválida.',
            ];
        }

        return [
            'ok'    => true,
            'code'  => 200,
            'error' => null,
        ];
    }

    /**
     * Valida o JSON estruturado de candidatos enviado pelo Worker Python antes de gravar no MySQL.
     */
    public static function validateCandidatesPayload(
        array $candidates,
        int $minDuration,
        int $maxDuration,
        int $maxCount,
        float $maxVideoDuration = 0.0
    ): array {
        if (empty($candidates)) {
            throw new DomainException('A lista de candidatos enviada pelo serviço de análise está vazia.');
        }

        if (count($candidates) > $maxCount) {
            throw new DomainException(sprintf(
                'Quantidade de candidatos (%d) excede o máximo configurado para o projeto (%d).',
                count($candidates),
                $maxCount
            ));
        }

        $allowedCategories = [
            'historia_completa',
            'historia',
            'dica_pratica',
            'dica',
            'curiosidade',
            'humor',
            'opiniao',
            'explicacao',
        ];
        $sanitized = [];
        $seenExcerpts = [];

        foreach ($candidates as $idx => $item) {
            if (!is_array($item)) {
                throw new DomainException("Candidato na posição #{$idx} não é um objeto JSON válido.");
            }

            $start = isset($item['start_time_seconds']) ? (float)$item['start_time_seconds'] : -1.0;
            $end   = isset($item['end_time_seconds']) ? (float)$item['end_time_seconds'] : -1.0;

            if ($start < 0 || $end <= $start) {
                throw new DomainException("Timestamps inválidos no candidato #{$idx} (início: {$start}, fim: {$end}).");
            }

            if ($maxVideoDuration > 0.0 && $end > ($maxVideoDuration + 0.01)) {
                throw new DomainException(sprintf(
                    'Candidato #%d ultrapassa a duração total do vídeo (fim: %.2fs > duração: %.2fs).',
                    $idx,
                    $end,
                    $maxVideoDuration
                ));
            }

            $duration = round($end - $start, 2);
            if ($duration < $minDuration || $duration > $maxDuration) {
                throw new DomainException(sprintf(
                    'Duração do candidato #%d (%.1fs) fora dos limites configurados (%ds a %ds).',
                    $idx,
                    $duration,
                    $minDuration,
                    $maxDuration
                ));
            }

            $title      = trim((string)($item['title'] ?? ''));
            $summary    = trim((string)($item['summary'] ?? ''));
            $rationale  = trim((string)($item['rationale'] ?? ''));
            $transcript = trim((string)($item['transcript_excerpt'] ?? ''));
            $category   = strtolower(trim((string)($item['category'] ?? 'explicacao')));
            $rawScore   = $item['relevance_score'] ?? ($item['estimated_interest_score'] ?? -1.0);
            $score      = is_numeric($rawScore) ? (float)$rawScore : -1.0;

            if ($title === '' || $summary === '' || $rationale === '' || $transcript === '') {
                throw new DomainException("Candidato #{$idx} não possui todos os campos obrigatórios (title, summary, rationale, transcript_excerpt).");
            }

            if ($score < 0.0 || $score > 100.0) {
                throw new DomainException("Pontuação estimada de interesse inválida no candidato #{$idx} ({$score}). Deve estar entre 0 e 100.");
            }

            $normalizedExcerpt = mb_strtolower(preg_replace('/\s+/u', ' ', $transcript) ?? $transcript);
            if (in_array($normalizedExcerpt, $seenExcerpts, true)) {
                throw new DomainException("Candidato #{$idx} contém trecho de transcrição duplicado.");
            }
            $seenExcerpts[] = $normalizedExcerpt;

            foreach ($sanitized as $prevIdx => $prev) {
                $overlapStart = max($start, (float)$prev['start_time_seconds']);
                $overlapEnd   = min($end, (float)$prev['end_time_seconds']);
                if ($overlapEnd > $overlapStart) {
                    throw new DomainException(sprintf(
                        'Sobreposição temporal detectada entre os candidatos #%d e #%d (%.2fs a %.2fs).',
                        $prevIdx,
                        $idx,
                        $overlapStart,
                        $overlapEnd
                    ));
                }
            }

            if (!in_array($category, $allowedCategories, true)) {
                $category = 'explicacao';
            }

            $storageUrl = isset($item['storage_url']) ? trim((string)$item['storage_url']) : null;
            if ($storageUrl !== null && $storageUrl !== '') {
                $cleanStorageUrl = ltrim(str_replace('\\', '/', $storageUrl), '/');
                if (
                    str_contains($cleanStorageUrl, '..')
                    || str_contains($cleanStorageUrl, '://')
                    || !str_starts_with($cleanStorageUrl, 'storage/clips/')
                ) {
                    throw new DomainException("Caminho storage_url inválido no candidato #{$idx}.");
                }
                $storageUrl = mb_substr($cleanStorageUrl, 0, 500);
            } else {
                $storageUrl = null;
            }

            $rawStatus = strtolower(trim((string)($item['status'] ?? '')));
            $clipStatus = ($storageUrl !== null && in_array($rawStatus, ['ready', 'approved', 'candidate'], true))
                ? 'ready'
                : 'candidate';

            $srtStorageUrl = isset($item['srt_storage_url']) ? trim((string)$item['srt_storage_url']) : null;
            if ($srtStorageUrl !== null && $srtStorageUrl !== '') {
                $cleanSrtUrl = ltrim(str_replace('\\', '/', $srtStorageUrl), '/');
                if (
                    str_contains($cleanSrtUrl, '..')
                    || str_contains($cleanSrtUrl, '://')
                    || !str_starts_with($cleanSrtUrl, 'storage/clips/')
                ) {
                    throw new DomainException("Caminho srt_storage_url inválido no candidato #{$idx}.");
                }
                $srtStorageUrl = mb_substr($cleanSrtUrl, 0, 500);
            } else {
                $srtStorageUrl = null;
            }

            $localMeta = SubtitleMetadataHelper::generateMetadataLocally($transcript, $category, $title);
            $shortDesc = trim((string)($item['short_description'] ?? ''));
            if ($shortDesc === '') {
                $shortDesc = (string)$localMeta['short_description'];
            }

            $rawHashtags = $item['hashtags'] ?? [];
            $hashtagsArr = SubtitleMetadataHelper::normalizeHashtags(
                (is_array($rawHashtags) || is_string($rawHashtags)) ? $rawHashtags : []
            );
            if (empty($hashtagsArr)) {
                $hashtagsArr = $localMeta['hashtags'];
            }
            $hashtagsStr = implode(' ', $hashtagsArr);

            $srtContent = isset($item['srt_content']) ? trim((string)$item['srt_content']) : '';
            if ($srtContent === '') {
                $srtContent = trim(SubtitleMetadataHelper::generateSrtLocally($transcript, $duration));
            }

            $subtitleStyle = SubtitleMetadataHelper::normalizeSubtitleStyle(
                is_array($item['subtitle_style'] ?? null) ? $item['subtitle_style'] : []
            );
            $subtitlesBurned = isset($item['subtitles_burned'])
                ? (bool)$item['subtitles_burned']
                : (bool)$subtitleStyle['burn_subtitles'];

            $sanitized[] = [
                'title'              => mb_substr($title, 0, 180),
                'short_description'  => mb_substr($shortDesc, 0, 500),
                'hashtags'           => mb_substr($hashtagsStr, 0, 500),
                'category'           => $category,
                'start_time_seconds' => round($start, 2),
                'end_time_seconds'   => round($end, 2),
                'duration_seconds'   => $duration,
                'summary'            => mb_substr($summary, 0, 1000),
                'rationale'          => mb_substr($rationale, 0, 1000),
                'transcript_excerpt' => mb_substr($transcript, 0, 4000),
                'relevance_score'    => (int)round($score),
                'storage_url'        => $storageUrl,
                'srt_storage_url'    => $srtStorageUrl,
                'srt_content'        => mb_substr($srtContent, 0, 32000),
                'subtitles_burned'   => $subtitlesBurned ? 1 : 0,
                'subtitle_style'     => $subtitleStyle,
                'status'             => $clipStatus,
            ];
        }

        return $sanitized;
    }
}
