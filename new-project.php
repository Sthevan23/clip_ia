<?php
declare(strict_types=1);

if (!isset($_GET['advanced'])) {
    header('Location: create-clips.php', true, 302);
    exit;
}

/**
 * ClipIA — 5. Página de Novo Projeto (modo avançado)
 * Criação de projetos a partir de links públicos suportados do YouTube:
 * - Validação estrita do domínio e ID no servidor (rejeitando domínios arbitrários, IPs e redirecionamentos)
 * - Integração compatível com políticas via YouTube oEmbed + Player Incorporado oficial
 * - Opção de envio de arquivo de vídeo autorizado (.mp4, .mov, .webm) quando a importação por URL não estiver disponível
 * - Persistência completa no MySQL (projects, source_videos, processing_jobs e usage_logs)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/youtube.php';
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

$previewState = [
    'validated'     => false,
    'video_id'      => '',
    'title'         => 'Nenhum vídeo validado ainda',
    'author_name'   => '',
    'thumbnail_url' => ASSETS_URL . '/img/placeholder-video.svg',
    'embed_url'     => '',
    'embed_allowed' => false,
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf             = $_POST['csrf_token'] ?? '';
    $projectName      = trim((string)($_POST['project_name'] ?? ''));
    $youtubeUrl       = trim((string)($_POST['youtube_url'] ?? ''));
    $sourceMode       = trim((string)($_POST['source_mode'] ?? 'url_only'));
    $clipsCount       = (int)($_POST['clips_count'] ?? $defaultClipsCount);
    $minDuration      = (int)($_POST['min_duration'] ?? $defaultMinDuration);
    $maxDuration      = (int)($_POST['max_duration'] ?? $defaultMaxDuration);
    $aspectRatio      = trim((string)($_POST['aspect_ratio'] ?? '9:16'));
    $authorizedSource = isset($_POST['authorized_source']);

    $postedSubStyle = SubtitleMetadataHelper::normalizeSubtitleStyle([
        'burn_subtitles'     => ($_POST['subtitle_output_mode'] ?? 'burn_and_srt') === 'burn_and_srt',
        'export_srt'         => true,
        'font_family'        => $_POST['subtitle_font_family'] ?? $defaultSubStyle['font_family'],
        'font_size'          => $_POST['subtitle_font_size'] ?? $defaultSubStyle['font_size'],
        'primary_color'      => $_POST['subtitle_primary_color'] ?? $defaultSubStyle['primary_color'],
        'highlight_color'    => $_POST['subtitle_highlight_color'] ?? $defaultSubStyle['highlight_color'],
        'outline_color'      => $_POST['subtitle_outline_color'] ?? $defaultSubStyle['outline_color'],
        'position'           => $_POST['subtitle_position'] ?? $defaultSubStyle['position'],
        'style_preset'       => $_POST['subtitle_style_preset'] ?? $defaultSubStyle['style_preset'],
        'highlight_keywords' => isset($_POST['subtitle_highlight_keywords']),
    ]);

    $allowedRatios    = ['9:16', '1:1', '16:9'];
    $allowedModes     = ['url_only', 'uploaded_file'];

    // 1. Validação estrita da URL do YouTube no servidor
    $ytValidation = YouTubeUrlValidator::validate($youtubeUrl);

    if ($ytValidation['valid'] && $ytValidation['video_id'] !== null) {
        $meta = YouTubeUrlValidator::fetchPublicMetadata($ytValidation['video_id']);
        $previewState = [
            'validated'     => true,
            'video_id'      => $ytValidation['video_id'],
            'title'         => $meta['title'],
            'author_name'   => $meta['author_name'],
            'thumbnail_url' => $meta['thumbnail_url'],
            'embed_url'     => $meta['embed_url'],
            'embed_allowed' => $meta['embed_allowed'],
        ];
        if ($projectName === '') {
            $projectName = mb_substr($meta['title'], 0, 180);
        }
    }

    if (!verify_csrf($csrf)) {
        set_flash('error', 'Sessão expirada', 'Não foi possível validar o token CSRF. Recarregue a página e tente novamente.');
    } elseif (!$ytValidation['valid'] || $ytValidation['video_id'] === null) {
        set_flash('error', 'URL do YouTube rejeitada', (string)$ytValidation['error']);
    } elseif ($projectName === '' || mb_strlen($projectName) < 3 || mb_strlen($projectName) > 180) {
        set_flash('error', 'Nome do projeto inválido', 'Informe um nome descritivo entre 3 e 180 caracteres.');
    } elseif (!in_array($sourceMode, $allowedModes, true)) {
        set_flash('error', 'Modo de fonte inválido', 'Selecione uma opção válida de origem do vídeo.');
    } elseif ($clipsCount < CLIP_MIN_COUNT || $clipsCount > CLIP_MAX_COUNT) {
        set_flash('error', 'Quantidade de cortes inválida', 'Escolha entre ' . CLIP_MIN_COUNT . ' e ' . CLIP_MAX_COUNT . ' cortes por projeto.');
    } elseif ($minDuration < 10 || $maxDuration > 180 || $minDuration >= $maxDuration) {
        set_flash('error', 'Intervalo de duração inválido', 'A duração mínima (mín. 10s) deve ser menor que a duração máxima (máx. 180s).');
    } elseif (!in_array($aspectRatio, $allowedRatios, true)) {
        set_flash('error', 'Formato inválido', 'Selecione um formato de vídeo válido (9:16, 1:1 ou 16:9).');
    } elseif (!$authorizedSource) {
        set_flash('error', 'Confirmação obrigatória', 'Confirme que compreende que o link não realiza download e que possui autorização para processar a fonte de vídeo.');
    } else {
        $uploadMeta = null;
        $canProceed = true;

        // Se o usuário escolheu enviar o arquivo de vídeo autorizado agora
        if ($sourceMode === 'uploaded_file') {
            try {
                $uploadMeta = VideoUploadManager::validateAndStore($_FILES['authorized_video_file'] ?? [], $userId);
            } catch (DomainException $e) {
                $canProceed = false;
                set_flash('error', 'Arquivo de vídeo inválido', $e->getMessage());
            } catch (Throwable $e) {
                $canProceed = false;
                error_log('[ClipIA Video Upload Error] ' . $e->getMessage());
                set_flash('error', 'Erro no envio do arquivo', 'Não foi possível processar o upload do arquivo de vídeo.');
            }
        }

        if ($canProceed) {
            try {
                $videoId = (string)$ytValidation['video_id'];
                ProjectRepository::createForUser($userId, [
                    'title'                => $projectName,
                    'source_mode'          => $sourceMode,
                    'source_url'           => $ytValidation['original_url'],
                    'external_video_id'    => $videoId,
                    'video_title'          => $previewState['title'],
                    'author_name'          => $previewState['author_name'],
                    'thumbnail_url'        => $previewState['thumbnail_url'],
                    'embed_url'            => $previewState['embed_url'],
                    'embed_allowed'        => $previewState['embed_allowed'],
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

                if ($sourceMode === 'uploaded_file') {
                    set_flash(
                        'success',
                        'Projeto criado — Aguardando processamento',
                        'O link do YouTube e o arquivo de vídeo autorizado foram registrados. O projeto está na fila para o serviço externo de processamento.'
                    );
                } else {
                    set_flash(
                        'success',
                        'Projeto criado — Aguardando fonte autorizada',
                        'A referência do YouTube foi salva. Como colar um link não permite baixar vídeos do YouTube, envie o arquivo de vídeo autorizado na tela "Meus projetos" quando desejar liberar o processamento.'
                    );
                }

                header('Location: projects.php');
                exit;
            } catch (Throwable $e) {
                set_flash('error', 'Erro ao salvar projeto', 'Não foi possível registrar o projeto no banco de dados.');
            }
        }
    }
}

$pageTitle = 'Novo Projeto';
$layoutType = 'app';
$activeNav = 'new-project';
$topbarTitle = 'Novo Projeto de Cortes';
$topbarSubtitle = 'Identifique o vídeo do YouTube, pré-visualize no player oficial e defina a fonte autorizada.';

require_once __DIR__ . '/includes/header.php';
?>

<div class="app-shell">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php require_once __DIR__ . '/includes/topbar.php'; ?>

        <main id="main-content" class="app-content">
            <?php render_flash_container(); ?>

            <form action="new-project.php" method="POST" id="newProjectForm" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" id="csrfTokenInput" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="video_external_id" id="videoExternalId" value="<?= e($previewState['video_id']) ?>">
                <input type="hidden" name="video_detected_title" id="videoDetectedTitle" value="<?= e($previewState['title']) ?>">

                <div class="new-project-grid">
                    <!-- Coluna Esquerda: Formulário de Configuração -->
                    <section class="panel-card" aria-labelledby="config-project-heading">
                        <div class="panel-header">
                            <h2 id="config-project-heading" class="panel-title">1. Referência do YouTube & Fonte Autorizada</h2>
                            <span class="badge badge-violet">Validação Server-Side</span>
                        </div>

                        <div class="panel-body">
                            <!-- Campo para nome do projeto -->
                            <div class="form-group">
                                <label for="projectName" class="form-label">Nome do projeto</label>
                                <input
                                    type="text"
                                    id="projectName"
                                    name="project_name"
                                    class="form-control"
                                    placeholder="Ex: Podcast Episódio #18 — Cortes para Reels"
                                    value="<?= e($_POST['project_name'] ?? '') ?>"
                                    required
                                >
                                <span class="form-hint">Se deixado em branco ao validar o link, será preenchido automaticamente com o título oficial do vídeo.</span>
                            </div>

                            <!-- Campo para colar o link do YouTube + Botão para validar o link -->
                            <div class="form-group">
                                <label for="youtubeUrl" class="form-label">Link público do vídeo no YouTube</label>
                                <div class="url-input-row">
                                    <input
                                        type="url"
                                        id="youtubeUrl"
                                        name="youtube_url"
                                        class="form-control"
                                        placeholder="https://www.youtube.com/watch?v=... ou https://youtu.be/..."
                                        value="<?= e($_POST['youtube_url'] ?? '') ?>"
                                        required
                                    >
                                    <button type="button" id="validateYoutubeBtn" class="btn btn-secondary">
                                        <?= clipia_icon('link', 17) ?>
                                        <span>Validar link</span>
                                    </button>
                                </div>
                                <span class="form-hint">Formatos aceitos: <code>youtube.com/watch?v=ID</code>, <code>youtu.be/ID</code>, <code>youtube.com/shorts/ID</code> e <code>youtube.com/live/ID</code>. Domínios externos, IPs e redirecionamentos são bloqueados.</span>
                            </div>

                            <!-- Aviso Obrigatório sobre Direitos e Não-Download do YouTube -->
                            <div class="legal-notice-box" role="note" aria-label="Aviso importante sobre políticas do YouTube e direitos autorais">
                                <?= clipia_icon('alert-triangle', 20) ?>
                                <div class="legal-notice-text">
                                    <strong>Importante — Colar um link não significa que o vídeo pode ser baixado:</strong>
                                    A URL do YouTube serve exclusivamente para identificar os metadados públicos e exibir o player oficial incorporado quando permitido pelo criador. O ClipIA <strong>não baixa vídeos do YouTube nem contorna DRM ou restrições técnicas</strong>. Para o processamento dos cortes, utilize a opção abaixo para enviar um arquivo de vídeo que você tenha autorização para processar.
                                </div>
                            </div>

                            <!-- Seleção da Disponibilidade da Fonte de Vídeo Autorizada -->
                            <div class="form-group">
                                <label class="form-label">Fonte de vídeo para processamento dos cortes</label>
                                <div class="form-row" style="gap: 12px;">
                                    <label class="format-card">
                                        <input
                                            type="radio"
                                            name="source_mode"
                                            value="url_only"
                                            <?= (($_POST['source_mode'] ?? 'url_only') === 'url_only') ? 'checked' : '' ?>
                                        >
                                        <span class="format-card-inner" style="align-items: flex-start; text-align: left; padding: 14px;">
                                            <span class="badge badge-warning">Status: Aguardando fonte</span>
                                            <span class="format-name">Salvar apenas referência por agora</span>
                                            <span class="format-desc">Registra o link do YouTube e permite enviar o arquivo de vídeo autorizado depois.</span>
                                        </span>
                                    </label>

                                    <label class="format-card">
                                        <input
                                            type="radio"
                                            name="source_mode"
                                            value="uploaded_file"
                                            <?= (($_POST['source_mode'] ?? '') === 'uploaded_file') ? 'checked' : '' ?>
                                        >
                                        <span class="format-card-inner" style="align-items: flex-start; text-align: left; padding: 14px;">
                                            <span class="badge badge-violet">Status: Aguardando processamento</span>
                                            <span class="format-name">Enviar arquivo de vídeo autorizado</span>
                                            <span class="format-desc">Envie o arquivo original (.mp4, .mov, .webm) que você tem permissão para processar.</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <!-- Área Condicional de Upload de Arquivo de Vídeo Autorizado -->
                            <div
                                id="authorizedUploadContainer"
                                class="form-group"
                                style="display: <?= (($_POST['source_mode'] ?? 'url_only') === 'uploaded_file') ? 'block' : 'none' ?>; padding: 16px; border-radius: var(--radius-md); background-color: var(--bg-elevated); border: 1px solid var(--accent-soft-border);"
                            >
                                <label for="authorizedVideoFile" class="form-label">Arquivo de vídeo autorizado (.mp4, .mov ou .webm — máx. 100 MB)</label>
                                <input
                                    type="file"
                                    id="authorizedVideoFile"
                                    name="authorized_video_file"
                                    class="form-control"
                                    accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm"
                                >
                                <span class="form-hint">Envie apenas vídeos de sua autoria ou com licença/autorização expressa do detentor dos direitos.</span>
                            </div>

                            <!-- Configuração de Quantidade e Duração Mínima/Máxima -->
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="clipsCount" class="form-label">Quantidade desejada de cortes</label>
                                    <input
                                        type="number"
                                        id="clipsCount"
                                        name="clips_count"
                                        class="form-control"
                                        min="<?= CLIP_MIN_COUNT ?>"
                                        max="<?= CLIP_MAX_COUNT ?>"
                                        value="<?= (int)($_POST['clips_count'] ?? $defaultClipsCount) ?>"
                                        required
                                    >
                                    <span class="form-hint">Entre <?= CLIP_MIN_COUNT ?> e <?= CLIP_MAX_COUNT ?> cortes sugeridos.</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Intervalo de duração (segundos)</label>
                                    <div class="form-row" style="gap: 10px;">
                                        <div>
                                            <input
                                                type="number"
                                                id="minDuration"
                                                name="min_duration"
                                                class="form-control"
                                                min="10"
                                                max="170"
                                                value="<?= (int)($_POST['min_duration'] ?? $defaultMinDuration) ?>"
                                                aria-label="Duração mínima em segundos"
                                                required
                                            >
                                            <span class="form-hint">Mínima (s)</span>
                                        </div>
                                        <div>
                                            <input
                                                type="number"
                                                id="maxDuration"
                                                name="max_duration"
                                                class="form-control"
                                                min="15"
                                                max="180"
                                                value="<?= (int)($_POST['max_duration'] ?? $defaultMaxDuration) ?>"
                                                aria-label="Duração máxima em segundos"
                                                required
                                            >
                                            <span class="form-hint">Máxima (s)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Opção de Formato Vertical 9:16 -->
                            <div class="form-group">
                                <label class="form-label">Formato de saída dos cortes</label>
                                <div class="format-options" role="radiogroup" aria-label="Formato de proporção do vídeo">
                                    <label class="format-card">
                                        <input
                                            type="radio"
                                            name="aspect_ratio"
                                            value="9:16"
                                            <?= ($defaultAspectRatio === '9:16') ? 'checked' : '' ?>
                                        >
                                        <span class="format-card-inner">
                                            <span class="format-ratio-box ratio-9-16" aria-hidden="true"></span>
                                            <span class="format-name">Vertical 9:16</span>
                                            <span class="format-desc">Reels, Shorts e TikTok (Recomendado)</span>
                                        </span>
                                    </label>

                                    <label class="format-card">
                                        <input
                                            type="radio"
                                            name="aspect_ratio"
                                            value="1:1"
                                            <?= ($defaultAspectRatio === '1:1') ? 'checked' : '' ?>
                                        >
                                        <span class="format-card-inner">
                                            <span class="format-ratio-box ratio-1-1" aria-hidden="true"></span>
                                            <span class="format-name">Quadrado 1:1</span>
                                            <span class="format-desc">Feed e Comunidades</span>
                                        </span>
                                    </label>

                                    <label class="format-card">
                                        <input
                                            type="radio"
                                            name="aspect_ratio"
                                            value="16:9"
                                            <?= ($defaultAspectRatio === '16:9') ? 'checked' : '' ?>
                                        >
                                        <span class="format-card-inner">
                                            <span class="format-ratio-box ratio-16-9" aria-hidden="true"></span>
                                            <span class="format-name">Horizontal 16:9</span>
                                            <span class="format-desc">Recorte Widescreen</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <!-- Personalização de Legendas Sincronizadas e Metadados -->
                            <div class="form-group" style="margin-top: 18px; padding: 16px; border-radius: var(--radius-md); background-color: var(--bg-elevated); border: 1px solid var(--border-subtle);">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 12px;">
                                    <label class="form-label" style="margin-bottom: 0;">
                                        <?= clipia_icon('subtitles', 17) ?>
                                        <span>Legendas Sincronizadas (faster-whisper + FFmpeg)</span>
                                    </label>
                                    <span class="badge badge-success">100% Local &amp; Gratuito</span>
                                </div>

                                <div class="form-group">
                                    <label for="subtitleOutputMode" class="form-label">Modo de geração de legendas</label>
                                    <select id="subtitleOutputMode" name="subtitle_output_mode" class="form-control">
                                        <option value="burn_and_srt" <?= (($_POST['subtitle_output_mode'] ?? 'burn_and_srt') === 'burn_and_srt') ? 'selected' : '' ?>>
                                            Incorporar legenda ao vídeo MP4 com FFmpeg + Exportar arquivo .SRT separado
                                        </option>
                                        <option value="srt_only" <?= (($_POST['subtitle_output_mode'] ?? '') === 'srt_only') ? 'selected' : '' ?>>
                                            Gerar vídeo MP4 sem legenda incorporada + Exportar arquivo .SRT separado
                                        </option>
                                    </select>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="subtitleFontFamily" class="form-label">Fonte</label>
                                        <select id="subtitleFontFamily" name="subtitle_font_family" class="form-control">
                                            <?php foreach (SubtitleMetadataHelper::ALLOWED_FONTS as $fKey => $fLabel): ?>
                                                <option value="<?= e($fKey) ?>" <?= (($_POST['subtitle_font_family'] ?? $defaultSubStyle['font_family']) === $fKey) ? 'selected' : '' ?>>
                                                    <?= e($fLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="subtitleFontSize" class="form-label">Tamanho da fonte</label>
                                        <select id="subtitleFontSize" name="subtitle_font_size" class="form-control">
                                            <?php foreach (SubtitleMetadataHelper::ALLOWED_SIZES as $sKey => $sLabel): ?>
                                                <option value="<?= e($sKey) ?>" <?= (($_POST['subtitle_font_size'] ?? $defaultSubStyle['font_size']) === $sKey) ? 'selected' : '' ?>>
                                                    <?= e($sLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="subtitlePosition" class="form-label">Posição vertical (9:16)</label>
                                        <select id="subtitlePosition" name="subtitle_position" class="form-control">
                                            <?php foreach (SubtitleMetadataHelper::ALLOWED_POSITIONS as $pKey => $pLabel): ?>
                                                <option value="<?= e($pKey) ?>" <?= (($_POST['subtitle_position'] ?? $defaultSubStyle['position']) === $pKey) ? 'selected' : '' ?>>
                                                    <?= e($pLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="subtitleStylePreset" class="form-label">Estilo de contorno e sombra</label>
                                        <select id="subtitleStylePreset" name="subtitle_style_preset" class="form-control">
                                            <?php foreach (SubtitleMetadataHelper::ALLOWED_PRESETS as $prKey => $prLabel): ?>
                                                <option value="<?= e($prKey) ?>" <?= (($_POST['subtitle_style_preset'] ?? $defaultSubStyle['style_preset']) === $prKey) ? 'selected' : '' ?>>
                                                    <?= e($prLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row" style="grid-template-columns: repeat(3, 1fr);">
                                    <div class="form-group">
                                        <label for="subtitlePrimaryColor" class="form-label">Cor principal</label>
                                        <input
                                            type="color"
                                            id="subtitlePrimaryColor"
                                            name="subtitle_primary_color"
                                            class="form-control"
                                            style="height: 40px; padding: 4px;"
                                            value="<?= e($_POST['subtitle_primary_color'] ?? $defaultSubStyle['primary_color']) ?>"
                                        >
                                    </div>

                                    <div class="form-group">
                                        <label for="subtitleHighlightColor" class="form-label">Cor de destaque</label>
                                        <input
                                            type="color"
                                            id="subtitleHighlightColor"
                                            name="subtitle_highlight_color"
                                            class="form-control"
                                            style="height: 40px; padding: 4px;"
                                            value="<?= e($_POST['subtitle_highlight_color'] ?? $defaultSubStyle['highlight_color']) ?>"
                                        >
                                    </div>

                                    <div class="form-group">
                                        <label for="subtitleOutlineColor" class="form-label">Cor do contorno</label>
                                        <input
                                            type="color"
                                            id="subtitleOutlineColor"
                                            name="subtitle_outline_color"
                                            class="form-control"
                                            style="height: 40px; padding: 4px;"
                                            value="<?= e($_POST['subtitle_outline_color'] ?? $defaultSubStyle['outline_color']) ?>"
                                        >
                                    </div>
                                </div>

                                <label class="checkbox-label" for="subtitleHighlightKeywords" style="margin-top: 4px;">
                                    <input
                                        type="checkbox"
                                        id="subtitleHighlightKeywords"
                                        name="subtitle_highlight_keywords"
                                        value="1"
                                        <?= (!isset($_POST['csrf_token']) || isset($_POST['subtitle_highlight_keywords'])) ? 'checked' : '' ?>
                                    >
                                    <span>
                                        Destacar palavras importantes com contraste estático de cor quando houver timestamps confiáveis (sem animações que prejudiquem a leitura).
                                    </span>
                                </label>
                            </div>

                            <!-- Confirmação de Fonte Autorizada -->
                            <div class="form-group" style="margin-top: 18px;">
                                <label class="checkbox-label" for="authorizedSourceCheck">
                                    <input
                                        type="checkbox"
                                        id="authorizedSourceCheck"
                                        name="authorized_source"
                                        value="1"
                                        checked
                                        required
                                    >
                                    <span>
                                        Estou ciente de que colar um link do YouTube não permite baixar o vídeo e declaro que utilizarei apenas fontes de vídeo sobre as quais possuo autorização ou titularidade.
                                    </span>
                                </label>
                            </div>

                            <!-- Botão para Continuar -->
                            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px;">
                                <a href="dashboard.php" class="btn btn-ghost">Cancelar</a>
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <span>Continuar e salvar projeto</span>
                                    <?= clipia_icon('arrow-right', 18) ?>
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- Coluna Direita: Prévia Visual (Título, Miniatura e Player Incorporado Oficial) -->
                    <aside class="panel-card" aria-labelledby="preview-heading">
                        <div class="panel-header">
                            <h2 id="preview-heading" class="panel-title">2. Pré-visualização Oficial</h2>
                            <span id="videoPreviewStatusBadge" class="badge <?= $previewState['validated'] ? 'badge-success' : 'badge-neutral' ?>">
                                <?= $previewState['validated'] ? 'URL Validada' : 'Aguardando Link' ?>
                            </span>
                        </div>

                        <div class="panel-body">
                            <div class="video-preview-box">
                                <div class="video-thumbnail-wrapper" id="videoPreviewStage">
                                    <img
                                        id="videoPreviewImg"
                                        src="<?= e($previewState['thumbnail_url']) ?>"
                                        alt="Miniatura de pré-visualização do vídeo"
                                        style="display: <?= ($previewState['validated'] && $previewState['embed_allowed']) ? 'none' : 'block' ?>;"
                                    >
                                    <iframe
                                        id="videoEmbedFrame"
                                        src="<?= ($previewState['validated'] && $previewState['embed_allowed']) ? e($previewState['embed_url']) : 'about:blank' ?>"
                                        title="Player incorporado oficial do YouTube para pré-visualização"
                                        style="width: 100%; height: 100%; border: 0; display: <?= ($previewState['validated'] && $previewState['embed_allowed']) ? 'block' : 'none' ?>;"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                        referrerpolicy="strict-origin-when-cross-origin"
                                        allowfullscreen
                                    ></iframe>
                                </div>
                                <div class="video-preview-meta">
                                    <h3 id="videoPreviewTitle" class="video-preview-title">
                                        <?= e($previewState['title']) ?>
                                    </h3>
                                    <div class="video-preview-sub">
                                        <span id="videoPreviewId">
                                            <?= $previewState['video_id'] !== '' ? 'ID do Vídeo: ' . e($previewState['video_id']) : 'Cole um link do YouTube e clique em "Validar link"' ?>
                                        </span>
                                        <span id="videoPreviewAuthor">
                                            <?= $previewState['author_name'] !== '' ? 'Canal: ' . e($previewState['author_name']) : 'YouTube oEmbed / IFrame' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="summary-spec-list" aria-label="Resumo da configuração atual">
                                <div class="summary-spec-item">
                                    <span>Estado inicial do projeto:</span>
                                    <strong id="summaryInitialState" class="text-accent">Aguardando fonte</strong>
                                </div>
                                <div class="summary-spec-item">
                                    <span>Quantidade solicitada:</span>
                                    <strong id="summaryClipsCount"><?= $defaultClipsCount ?> cortes</strong>
                                </div>
                                <div class="summary-spec-item">
                                    <span>Duração por corte:</span>
                                    <strong id="summaryDurationRange"><?= $defaultMinDuration ?>s até <?= $defaultMaxDuration ?>s</strong>
                                </div>
                                <div class="summary-spec-item">
                                    <span>Proporção de tela:</span>
                                    <strong id="summaryFormat">Vertical 9:16 (Reels/Shorts/TikTok)</strong>
                                </div>
                                <div class="summary-spec-item">
                                    <span>Serviço externo (Python):</span>
                                    <strong>Interface preparada (Não executa na hospedagem PHP)</strong>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </form>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
