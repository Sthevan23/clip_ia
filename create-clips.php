<?php
declare(strict_types=1);

/**
 * ClipIA — Criar clipes (fluxo principal)
 * URL HTTPS direta do arquivo, upload MP4/MOV/WebM ou referência YouTube + arquivo.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/youtube.php';
require_once __DIR__ . '/includes/direct_media_url.php';
require_once __DIR__ . '/includes/video_upload.php';
require_once __DIR__ . '/includes/subtitles_metadata.php';

$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$userProfile = get_current_user_profile();

$defaultClipsCount = (int)($userProfile['default_clips_count'] ?? CLIP_DEFAULT_COUNT);
$defaultMinDuration = (int)($userProfile['default_min_duration'] ?? CLIP_DEFAULT_MIN_DURATION);
$defaultMaxDuration = (int)($userProfile['default_max_duration'] ?? CLIP_DEFAULT_MAX_DURATION);
$defaultAspectRatio = (string)($userProfile['default_aspect_ratio'] ?? CLIP_DEFAULT_FORMAT);
$defaultSubStyle = SubtitleMetadataHelper::getDefaultSubtitleStyle();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $projectName = trim((string)($_POST['project_name'] ?? ''));
    $videoUrl = trim((string)($_POST['video_url'] ?? ''));
    $clipsCount = (int)($_POST['clips_count'] ?? $defaultClipsCount);
    $minDuration = (int)($_POST['min_duration'] ?? $defaultMinDuration);
    $maxDuration = (int)($_POST['max_duration'] ?? $defaultMaxDuration);
    $aspectRatio = trim((string)($_POST['aspect_ratio'] ?? '9:16'));
    $authorizedSource = isset($_POST['authorized_source']);

    $postedSubStyle = SubtitleMetadataHelper::normalizeSubtitleStyle([
        'burn_subtitles'     => true,
        'export_srt'         => true,
        'font_family'        => $defaultSubStyle['font_family'],
        'font_size'          => $defaultSubStyle['font_size'],
        'primary_color'      => $defaultSubStyle['primary_color'],
        'highlight_color'    => $defaultSubStyle['highlight_color'],
        'outline_color'      => $defaultSubStyle['outline_color'],
        'position'           => $defaultSubStyle['position'],
        'style_preset'       => $defaultSubStyle['style_preset'],
        'highlight_keywords' => true,
    ]);

    $allowedRatios = ['9:16', '1:1', '16:9'];
    $uploadMeta = null;
    $hasUpload = isset($_FILES['video_file']) && (int)($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!verify_csrf($csrf)) {
        set_flash('error', 'Sessão expirada', 'Recarregue a página e tente novamente.');
    } elseif (!$authorizedSource) {
        set_flash('error', 'Confirmação obrigatória', 'Confirme que possui direito de usar a fonte de vídeo informada.');
    } elseif ($clipsCount < CLIP_MIN_COUNT || $clipsCount > CLIP_MAX_COUNT) {
        set_flash('error', 'Quantidade inválida', 'Escolha entre ' . CLIP_MIN_COUNT . ' e ' . CLIP_MAX_COUNT . ' clipes.');
    } elseif ($minDuration < 10 || $maxDuration > 180 || $minDuration >= $maxDuration) {
        set_flash('error', 'Duração inválida', 'Ajuste o intervalo mínimo e máximo de cada clipe.');
    } elseif (!in_array($aspectRatio, $allowedRatios, true)) {
        set_flash('error', 'Formato inválido', 'Selecione 9:16, 1:1 ou 16:9.');
    } else {
        $sourceMode = 'url_only';
        $sourceUrl = $videoUrl;
        $externalVideoId = '';
        $videoTitle = $projectName;
        $authorName = '';
        $thumbnailUrl = '';
        $embedUrl = '';
        $embedAllowed = false;
        $authorizedMediaUrl = null;

        if ($hasUpload) {
            try {
                $uploadMeta = VideoUploadManager::validateAndStore($_FILES['video_file'], $userId);
                $sourceMode = 'uploaded_file';
            } catch (DomainException $e) {
                set_flash('error', 'Arquivo inválido', $e->getMessage());
                $uploadMeta = null;
            } catch (Throwable $e) {
                error_log('[ClipIA create-clips upload] ' . $e->getMessage());
                set_flash('error', 'Falha no envio', 'Não foi possível receber o arquivo de vídeo.');
                $uploadMeta = null;
            }
        }

        if ($uploadMeta === null && $videoUrl !== '') {
            $directCheck = DirectMediaUrlValidator::validate($videoUrl);
            if ($directCheck['valid'] && $directCheck['normalized_url'] !== null) {
                $sourceMode = 'direct_media_url';
                $authorizedMediaUrl = $directCheck['normalized_url'];
                $sourceUrl = $authorizedMediaUrl;
                $externalVideoId = 'direct';
                $videoTitle = $projectName !== '' ? $projectName : basename(parse_url($authorizedMediaUrl, PHP_URL_PATH) ?: 'Vídeo');
            } else {
                $ytValidation = YouTubeUrlValidator::validate($videoUrl);
                if ($ytValidation['valid'] && $ytValidation['video_id'] !== null) {
                    set_flash(
                        'error',
                        'YouTube não pode ser baixado automaticamente',
                        'Este link serve apenas como referência. Envie o arquivo MP4/MOV/WebM ou cole uma URL HTTPS direta do arquivo (.mp4) no seu armazenamento autorizado.'
                    );
                } else {
                    set_flash(
                        'error',
                        'Link não compatível',
                        $directCheck['error'] ?? 'Informe uma URL HTTPS direta do vídeo ou envie um arquivo.'
                    );
                }
            }
        }

        if ($uploadMeta === null && $sourceMode !== 'direct_media_url' && $videoUrl === '') {
            set_flash('error', 'Fonte ausente', 'Cole uma URL compatível ou envie um arquivo de vídeo.');
        }

        if ($uploadMeta !== null || $sourceMode === 'direct_media_url') {
            if ($projectName === '' || mb_strlen($projectName) < 3) {
                if ($uploadMeta !== null) {
                    $projectName = pathinfo($uploadMeta['original_name'], PATHINFO_FILENAME);
                } elseif ($authorizedMediaUrl !== null) {
                    $projectName = basename(parse_url($authorizedMediaUrl, PHP_URL_PATH) ?: 'Projeto de clipes');
                }
                $projectName = mb_substr(trim($projectName), 0, 180);
                if (mb_strlen($projectName) < 3) {
                    $projectName = 'Projeto de clipes ' . date('d/m H:i');
                }
            }

            if ($uploadMeta !== null && $videoUrl !== '') {
                $ytValidation = YouTubeUrlValidator::validate($videoUrl);
                if ($ytValidation['valid'] && $ytValidation['video_id'] !== null) {
                    $meta = YouTubeUrlValidator::fetchPublicMetadata($ytValidation['video_id']);
                    $sourceUrl = $ytValidation['original_url'];
                    $externalVideoId = $ytValidation['video_id'];
                    $videoTitle = $meta['title'];
                    $authorName = $meta['author_name'];
                    $thumbnailUrl = $meta['thumbnail_url'];
                    $embedUrl = $meta['embed_url'];
                    $embedAllowed = $meta['embed_allowed'];
                } else {
                    $externalVideoId = 'upload';
                    $sourceUrl = $videoUrl !== '' ? $videoUrl : 'local://upload';
                }
            } elseif ($uploadMeta !== null) {
                $externalVideoId = 'upload';
                $sourceUrl = 'local://upload';
                $videoTitle = pathinfo($uploadMeta['original_name'], PATHINFO_FILENAME);
            }

            try {
                $projectId = ProjectRepository::createForUser($userId, [
                    'title'                => $projectName,
                    'source_mode'          => $sourceMode,
                    'source_url'           => $sourceUrl,
                    'external_video_id'    => $externalVideoId !== '' ? $externalVideoId : 'direct',
                    'video_title'          => $videoTitle,
                    'author_name'          => $authorName,
                    'thumbnail_url'        => $thumbnailUrl,
                    'embed_url'            => $embedUrl,
                    'embed_allowed'        => $embedAllowed,
                    'authorized_media_url' => $authorizedMediaUrl,
                    'uploaded_file_path'   => $uploadMeta['relative_path'] ?? null,
                    'uploaded_file_name'   => $uploadMeta['original_name'] ?? null,
                    'uploaded_file_mime'   => $uploadMeta['mime_type'] ?? null,
                    'uploaded_file_size'   => $uploadMeta['size_bytes'] ?? null,
                    'target_clips_count'   => $clipsCount,
                    'min_duration_seconds' => $minDuration,
                    'max_duration_seconds' => $maxDuration,
                    'aspect_ratio'         => $aspectRatio,
                    'subtitle_style'       => $postedSubStyle,
                ]);

                set_flash(
                    'success',
                    'Clipes enfileirados',
                    'O projeto foi criado. Acompanhe o progresso e baixe os cortes quando o worker concluir o processamento.'
                );
                header('Location: project-studio.php?id=' . $projectId);
                exit;
            } catch (Throwable $e) {
                set_flash('error', 'Erro ao criar projeto', 'Não foi possível salvar no banco de dados.');
            }
        }
    }
}

$pageTitle = 'Criar clipes';
$layoutType = 'app';
$activeNav = 'create-clips';
$topbarTitle = 'Criar clipes';
$topbarSubtitle = 'Transforme um vídeo longo em vários cortes verticais automaticamente.';

require_once __DIR__ . '/includes/header.php';
?>

<div class="app-shell">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php require_once __DIR__ . '/includes/topbar.php'; ?>

        <main id="main-content" class="app-content">
            <?php render_flash_container(); ?>

            <section class="create-clips-hero panel-card" aria-labelledby="create-clips-heading">
                <div class="create-clips-hero-inner">
                    <div>
                        <p class="create-clips-kicker">Estúdio ClipIA</p>
                        <h1 id="create-clips-heading" class="create-clips-title">Cole o link ou envie o vídeo</h1>
                        <p class="create-clips-lead">
                            A IA local transcreve, encontra os melhores trechos e renderiza clipes em 9:16 com legendas.
                            Links do YouTube <strong>não</strong> são baixados — use URL HTTPS do arquivo ou upload.
                        </p>
                    </div>
                    <div class="create-clips-hero-badge">
                        <?= clipia_icon('sparkles', 22) ?>
                        <span>Cortes automáticos</span>
                    </div>
                </div>
            </section>

            <form action="create-clips.php" method="POST" enctype="multipart/form-data" class="create-clips-form" id="createClipsForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="create-clips-grid">
                    <section class="panel-card">
                        <div class="panel-header">
                            <h2 class="panel-title">Fonte do vídeo</h2>
                        </div>
                        <div class="panel-body">
                            <div class="form-group">
                                <label for="videoUrl" class="form-label">URL do vídeo</label>
                                <input
                                    type="url"
                                    id="videoUrl"
                                    name="video_url"
                                    class="form-control form-control-lg"
                                    placeholder="https://seu-cdn.com/video.mp4 ou link YouTube (referência + upload)"
                                    value="<?= e($_POST['video_url'] ?? '') ?>"
                                    autocomplete="off"
                                >
                                <span class="form-hint">Compatível: URL HTTPS direta (.mp4, .mov, .webm). YouTube só com arquivo enviado abaixo.</span>
                            </div>

                            <div class="create-clips-divider" aria-hidden="true"><span>ou</span></div>

                            <div class="form-group">
                                <label for="videoFile" class="form-label">Enviar arquivo (MP4, MOV, WebM)</label>
                                <input
                                    type="file"
                                    id="videoFile"
                                    name="video_file"
                                    class="form-control"
                                    accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm"
                                >
                            </div>

                            <div class="form-group">
                                <label for="projectName" class="form-label">Nome do projeto (opcional)</label>
                                <input
                                    type="text"
                                    id="projectName"
                                    name="project_name"
                                    class="form-control"
                                    placeholder="Ex: Podcast #12 — melhores momentos"
                                    value="<?= e($_POST['project_name'] ?? '') ?>"
                                    maxlength="180"
                                >
                            </div>

                            <label class="checkbox-label" for="authorizedSourceCheck">
                                <input type="checkbox" id="authorizedSourceCheck" name="authorized_source" value="1" checked required>
                                <span>Declaro que tenho autorização para processar este vídeo.</span>
                            </label>
                        </div>
                    </section>

                    <section class="panel-card">
                        <div class="panel-header">
                            <h2 class="panel-title">Configuração dos clipes</h2>
                        </div>
                        <div class="panel-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="clipsCount" class="form-label">Quantidade</label>
                                    <input type="number" id="clipsCount" name="clips_count" class="form-control"
                                           min="<?= CLIP_MIN_COUNT ?>" max="<?= CLIP_MAX_COUNT ?>"
                                           value="<?= (int)($_POST['clips_count'] ?? $defaultClipsCount) ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Duração (seg)</label>
                                    <div class="form-row" style="gap: 8px;">
                                        <input type="number" name="min_duration" class="form-control" min="10" max="170"
                                               value="<?= (int)($_POST['min_duration'] ?? $defaultMinDuration) ?>" aria-label="Mínima">
                                        <input type="number" name="max_duration" class="form-control" min="15" max="180"
                                               value="<?= (int)($_POST['max_duration'] ?? $defaultMaxDuration) ?>" aria-label="Máxima">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Formato</label>
                                <div class="format-options">
                                    <label class="format-card">
                                        <input type="radio" name="aspect_ratio" value="9:16" <?= $defaultAspectRatio === '9:16' ? 'checked' : '' ?>>
                                        <span class="format-card-inner">
                                            <span class="format-ratio-box ratio-9-16"></span>
                                            <span class="format-name">Vertical 9:16</span>
                                            <span class="format-desc">Reels, Shorts, TikTok</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg btn-block create-clips-submit">
                                <?= clipia_icon('sparkles', 20) ?>
                                <span>Gerar clipes</span>
                            </button>
                            <p class="form-hint" style="margin-top: 12px; text-align: center;">
                                Após enfileirar, execute o worker Python no Windows para processar a fila.
                            </p>
                        </div>
                    </section>
                </div>
            </form>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
