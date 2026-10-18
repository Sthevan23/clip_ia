<?php
declare(strict_types=1);

/**
 * ClipIA — Gerenciador de Upload Seguro de Arquivo de Vídeo Autorizado
 *
 * Utilizado quando o usuário envia um arquivo de vídeo próprio/autorizado
 * (pois a simples URL do YouTube não permite download/contorno de restrições).
 *
 * Unifica o armazenamento em `storage/uploads` (protegido por `.htaccess`),
 * aplica cotas de espaço (por arquivo, por usuário e total do servidor) e
 * executa limpeza automática de arquivos expirados.
 */

require_once __DIR__ . '/../config/database.php';

class VideoUploadManager
{
    public const MAX_FILE_SIZE_BYTES = 104857600; // 100 MB padrão

    public const ALLOWED_MIMES = [
        'video/mp4'        => 'mp4',
        'video/webm'       => 'webm',
        'video/quicktime'  => 'mov',
    ];

    /**
     * Retorna o diretório absoluto canônico onde os vídeos autorizados são armazenados (`storage/uploads`).
     */
    public static function getUploadDirectory(): string
    {
        return defined('STORAGE_UPLOADS_ABS_DIR')
            ? (string)STORAGE_UPLOADS_ABS_DIR
            : (dirname(__DIR__) . '/storage/uploads');
    }

    /**
     * Retorna o diretório absoluto canônico onde os clipes cortados com FFmpeg são armazenados (`storage/clips`).
     */
    public static function getClipsDirectory(): string
    {
        return defined('STORAGE_CLIPS_ABS_DIR')
            ? (string)STORAGE_CLIPS_ABS_DIR
            : (dirname(__DIR__) . '/storage/clips');
    }

    /**
     * Verifica se a partição real do sistema operacional possui espaço livre suficiente
     * antes de gravar novos uploads ou clipes renderizados.
     */
    public static function checkFreeDiskSpace(string $directory, int $incomingBytes = 0): void
    {
        self::ensureProtectedDirectory($directory);
        $minFreeBytes = defined('STORAGE_MIN_FREE_DISK_BYTES')
            ? (int)STORAGE_MIN_FREE_DISK_BYTES
            : 104857600; // 100 MB

        $freeDisk = @disk_free_space($directory);
        if ($freeDisk !== false && ($freeDisk - $incomingBytes) < $minFreeBytes) {
            throw new DomainException(
                'Espaço livre em disco insuficiente no servidor para armazenar o vídeo com segurança.'
            );
        }
    }

    /**
     * Armazena um arquivo de clipe MP4 renderizado pelo Worker Python em `storage/clips`.
     */
    public static function storeRenderedClip(
        int $projectId,
        int $jobId,
        int $clipIndex,
        string $rawClipBytes
    ): string {
        $byteLen = strlen($rawClipBytes);
        if ($byteLen <= 0) {
            throw new DomainException('O arquivo de clipe renderizado enviado está vazio.');
        }

        $maxFileSize = defined('STORAGE_MAX_FILE_SIZE_BYTES')
            ? (int)STORAGE_MAX_FILE_SIZE_BYTES
            : self::MAX_FILE_SIZE_BYTES;
        if ($byteLen > $maxFileSize) {
            throw new DomainException('O clipe renderizado excede o tamanho máximo permitido.');
        }

        $clipsDir = self::getClipsDirectory();
        self::ensureProtectedDirectory($clipsDir);
        self::checkFreeDiskSpace($clipsDir, $byteLen);

        $safeClipName = sprintf(
            'clip_p%d_j%d_%02d.mp4',
            max(0, $projectId),
            max(0, $jobId),
            max(1, $clipIndex)
        );
        $destPath = $clipsDir . '/' . $safeClipName;
        $tmpPath = $clipsDir . '/.tmp_' . bin2hex(random_bytes(6)) . '_' . $safeClipName;

        try {
            if (@file_put_contents($tmpPath, $rawClipBytes) === false) {
                throw new RuntimeException('Falha ao gravar clipe renderizado no disco.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = (string)$finfo->file($tmpPath);
            if (!in_array($detectedMime, ['video/mp4', 'application/octet-stream'], true)) {
                throw new DomainException("MIME inválido para clipe MP4 renderizado: {$detectedMime}");
            }

            if (!@rename($tmpPath, $destPath)) {
                if (!@copy($tmpPath, $destPath)) {
                    throw new RuntimeException('Não foi possível mover o clipe renderizado para o destino final.');
                }
                @unlink($tmpPath);
            }
            @chmod($destPath, 0640);
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        $relClipsDir = defined('STORAGE_CLIPS_REL_DIR') ? (string)STORAGE_CLIPS_REL_DIR : 'storage/clips';
        return $relClipsDir . '/' . $safeClipName;
    }

    /**
     * Armazena um arquivo de legenda `.srt` sincronizada gerada pelo Worker Python em `storage/clips`.
     */
    public static function storeRenderedSrt(
        int $projectId,
        int $jobId,
        int $clipIndex,
        string $srtContent
    ): string {
        $cleanContent = trim($srtContent);
        if ($cleanContent === '') {
            throw new DomainException('O conteúdo de legenda SRT enviado está vazio.');
        }

        if (strlen($cleanContent) > 512000) {
            throw new DomainException('O arquivo de legenda SRT excede o limite de tamanho permitido.');
        }

        $clipsDir = self::getClipsDirectory();
        self::ensureProtectedDirectory($clipsDir);
        self::checkFreeDiskSpace($clipsDir, strlen($cleanContent));

        $safeSrtName = sprintf(
            'clip_p%d_j%d_%02d.srt',
            max(0, $projectId),
            max(0, $jobId),
            max(1, $clipIndex)
        );
        $destPath = $clipsDir . '/' . $safeSrtName;
        if (@file_put_contents($destPath, $cleanContent . "\n") === false) {
            throw new RuntimeException('Falha ao gravar arquivo de legenda SRT no disco.');
        }
        @chmod($destPath, 0640);

        $relClipsDir = defined('STORAGE_CLIPS_REL_DIR') ? (string)STORAGE_CLIPS_REL_DIR : 'storage/clips';
        return $relClipsDir . '/' . $safeSrtName;
    }

    /**
     * Valida e armazena um arquivo de vídeo enviado via `$_FILES` em pasta protegida por `.htaccess`.
     * Retorna array com metadados do arquivo salvo ou lança `DomainException` com mensagem clara.
     */
    public static function validateAndStore(array $fileInfo, int $userId): array
    {
        $errorCode = (int)($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode !== UPLOAD_ERR_OK) {
            $msg = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo enviado excede o tamanho máximo permitido pelo servidor.',
                UPLOAD_ERR_PARTIAL => 'O envio do arquivo foi interrompido antes da conclusão. Tente novamente.',
                UPLOAD_ERR_NO_FILE => 'Selecione um arquivo de vídeo válido (.mp4, .mov ou .webm).',
                default => 'Falha ao receber o arquivo de vídeo enviado.',
            };
            throw new DomainException($msg);
        }

        $tmpPath = (string)($fileInfo['tmp_name'] ?? '');
        $origName = basename((string)($fileInfo['name'] ?? 'video.mp4'));
        $fileSize = (int)($fileInfo['size'] ?? 0);

        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new DomainException('Arquivo temporário de upload não localizado.');
        }

        if ($fileSize <= 0) {
            throw new DomainException('O arquivo de vídeo enviado está vazio.');
        }

        $maxFileSize = defined('STORAGE_MAX_FILE_SIZE_BYTES')
            ? (int)STORAGE_MAX_FILE_SIZE_BYTES
            : self::MAX_FILE_SIZE_BYTES;

        if ($fileSize > $maxFileSize) {
            $maxMb = (int)round($maxFileSize / 1048576);
            throw new DomainException("O arquivo excede o limite de {$maxMb} MB para upload direto pelo painel.");
        }

        // Inspeção real do MIME type pelo conteúdo do arquivo (finfo)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)$finfo->file($tmpPath);

        if (!isset(self::ALLOWED_MIMES[$detectedMime])) {
            throw new DomainException('Formato de arquivo não permitido. Envie apenas vídeos MP4, MOV ou WebM.');
        }

        $ext = self::ALLOWED_MIMES[$detectedMime];
        $extFromName = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($extFromName, ['mp4', 'mov', 'webm'], true)) {
            throw new DomainException('A extensão do arquivo deve ser .mp4, .mov ou .webm.');
        }

        $uploadBaseDir = self::getUploadDirectory();
        self::ensureProtectedDirectory($uploadBaseDir);

        // Executa limpeza preventiva de mídias expiradas antes de checar cotas de disco
        self::purgeExpiredMediaFiles();

        // Verifica cotas de armazenamento por usuário e global do servidor
        self::enforceStorageQuotas($userId, $fileSize, $uploadBaseDir);

        $safeFileName = sprintf(
            'u%d_%s_%s.%s',
            $userId,
            date('Ymd_His'),
            bin2hex(random_bytes(8)),
            $ext
        );

        $destinationPath = $uploadBaseDir . '/' . $safeFileName;

        // Suporta move_uploaded_file em requisições HTTP e copy em testes automatizados CLI
        $moved = is_uploaded_file($tmpPath)
            ? move_uploaded_file($tmpPath, $destinationPath)
            : copy($tmpPath, $destinationPath);

        if (!$moved) {
            throw new RuntimeException('Não foi possível armazenar o arquivo de vídeo no servidor.');
        }

        @chmod($destinationPath, 0640);

        $safeOriginalName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $origName) ?: ('video.' . $ext);
        $relDir = defined('STORAGE_UPLOADS_REL_DIR') ? (string)STORAGE_UPLOADS_REL_DIR : 'storage/uploads';

        return [
            'relative_path' => $relDir . '/' . $safeFileName,
            'original_name' => mb_substr($safeOriginalName, 0, 255),
            'mime_type'     => $detectedMime,
            'size_bytes'    => $fileSize,
        ];
    }

    /**
     * Resolve de forma segura um caminho relativo de mídia autorizada dentro do projeto,
     * prevenindo Path Traversal e suportando tanto `storage/uploads` quanto o legado `uploads/videos`.
     */
    public static function resolveSafeMediaPath(string $relativePath): ?string
    {
        $cleanRel = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($cleanRel === '' || str_contains($cleanRel, '..')) {
            return null;
        }

        $projectRoot = realpath(dirname(__DIR__));
        if ($projectRoot === false) {
            return null;
        }

        $allowedPrefixes = [
            realpath($projectRoot . '/storage/uploads'),
            realpath($projectRoot . '/storage/clips'),
            realpath($projectRoot . '/storage/samples'),
            realpath($projectRoot . '/uploads/videos'),
        ];

        $candidateFull = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanRel));
        if ($candidateFull === false || !is_file($candidateFull)) {
            return null;
        }

        foreach ($allowedPrefixes as $prefix) {
            if ($prefix !== false && str_starts_with($candidateFull, $prefix . DIRECTORY_SEPARATOR)) {
                return $candidateFull;
            }
        }

        return null;
    }

    /**
     * Remove do disco o arquivo de mídia associado a um caminho relativo (e eventual `.transcript.json`).
     */
    public static function deleteStoredFile(?string $relativePath): bool
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return false;
        }

        $safeFull = self::resolveSafeMediaPath($relativePath);
        if ($safeFull === null) {
            return false;
        }

        $companionJson = $safeFull . '.transcript.json';
        if (is_file($companionJson)) {
            @unlink($companionJson);
        }

        return @unlink($safeFull);
    }

    /**
     * Verifica os limites de espaço em disco por usuário (`STORAGE_MAX_USER_QUOTA_BYTES`)
     * e o limite global do diretório de uploads (`STORAGE_MAX_TOTAL_QUOTA_BYTES`).
     */
    public static function enforceStorageQuotas(int $userId, int $incomingBytes, string $uploadBaseDir): void
    {
        self::checkFreeDiskSpace($uploadBaseDir, $incomingBytes);
        $maxUserQuota = defined('STORAGE_MAX_USER_QUOTA_BYTES') ? (int)STORAGE_MAX_USER_QUOTA_BYTES : 524288000;
        $maxTotalQuota = defined('STORAGE_MAX_TOTAL_QUOTA_BYTES') ? (int)STORAGE_MAX_TOTAL_QUOTA_BYTES : 5368709120;

        $totalDiskUsed = 0;
        $userDiskUsed = 0;
        $userPrefix = 'u' . $userId . '_';

        if (is_dir($uploadBaseDir)) {
            $entries = @scandir($uploadBaseDir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === '.gitkeep') {
                    continue;
                }
                $full = $uploadBaseDir . '/' . $entry;
                if (is_file($full)) {
                    $sz = (int)(@filesize($full) ?: 0);
                    $totalDiskUsed += $sz;
                    if (str_starts_with($entry, $userPrefix)) {
                        $userDiskUsed += $sz;
                    }
                }
            }
        }

        if (($userDiskUsed + $incomingBytes) > $maxUserQuota) {
            $quotaMb = (int)round($maxUserQuota / 1048576);
            throw new DomainException(
                "Limite de espaço da sua conta atingido ({$quotaMb} MB). Exclua projetos antigos antes de enviar um novo vídeo."
            );
        }

        if (($totalDiskUsed + $incomingBytes) > $maxTotalQuota) {
            throw new DomainException(
                'O armazenamento temporário de vídeos do servidor atingiu a capacidade máxima. Tente novamente mais tarde.'
            );
        }
    }

    /**
     * Remove arquivos de mídia temporários expirados (mais antigos que `retentionHours`)
     * que não pertençam a tarefas ativas (`queued` ou `running`), preservando os resultados no banco.
     */
    public static function purgeExpiredMediaFiles(?int $retentionHours = null): int
    {
        $hours = $retentionHours ?? (defined('STORAGE_MEDIA_RETENTION_HOURS') ? (int)STORAGE_MEDIA_RETENTION_HOURS : 72);
        $cutoffTimestamp = time() - max(1, $hours) * 3600;
        $uploadDir = self::getUploadDirectory();

        if (!is_dir($uploadDir)) {
            return 0;
        }

        // Identifica arquivos que ainda estão em fila ou em execução para nunca apagá-los antes do processamento
        $activeFiles = [];
        $pdo = Database::getConnection();
        if ($pdo !== null) {
            try {
                $stmt = $pdo->query(
                    "SELECT sv.uploaded_file_path
                     FROM source_videos sv
                     INNER JOIN processing_jobs pj ON pj.project_id = sv.project_id
                     WHERE pj.status IN ('pending', 'queued', 'running')
                       AND sv.uploaded_file_path IS NOT NULL"
                );
                if ($stmt !== false) {
                    foreach ($stmt->fetchAll() as $r) {
                        $bn = basename((string)($r['uploaded_file_path'] ?? ''));
                        if ($bn !== '') {
                            $activeFiles[$bn] = true;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Continua com segurança caso a tabela ainda não exista
            }
        }

        $removedCount = 0;
        $entries = @scandir($uploadDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === '.gitkeep') {
                continue;
            }
            if (isset($activeFiles[$entry])) {
                continue;
            }

            $full = $uploadDir . '/' . $entry;
            if (!is_file($full)) {
                continue;
            }

            $mtime = (int)(@filemtime($full) ?: time());
            if ($mtime < $cutoffTimestamp) {
                if (@unlink($full)) {
                    $removedCount++;
                }
            }
        }

        return $removedCount;
    }

    /**
     * Garante que o diretório de uploads exista com permissões seguras (`0750`)
     * e possua `.htaccess` impedindo listagem e execução de scripts.
     */
    public static function ensureProtectedDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $htaccessPath = $dir . '/.htaccess';
        if (!is_file($htaccessPath)) {
            $rules = "Options -Indexes -ExecCGI\n"
                   . "Require all denied\n"
                   . "<FilesMatch \"\\.(php|php[0-9]|phtml|phar|pl|py|cgi|sh|htaccess)$\">\n"
                   . "    Require all denied\n"
                   . "</FilesMatch>\n";
            @file_put_contents($htaccessPath, $rules);
        }
    }
}

