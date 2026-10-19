<?php
declare(strict_types=1);

/**
 * Estúdio — acompanhamento de progresso e galeria de clipes gerados.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/subtitles_metadata.php';

$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($projectId <= 0) {
    set_flash('error', 'Projeto inválido', 'Informe um projeto válido.');
    header('Location: projects.php');
    exit;
}

$project = ProjectRepository::findByIdForUser($projectId, $userId);
if ($project === null) {
    set_flash('error', 'Acesso negado', 'Projeto não encontrado.');
    header('Location: projects.php');
    exit;
}

$snapshot = ProjectRepository::getStudioSnapshotForUser($projectId, $userId);
$candidates = ProjectRepository::listCandidatesByProjectForUser($projectId, $userId);

function studio_format_mmss(float $sec): string
{
    $total = max(0, (int)round($sec));
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}

$pageTitle = 'Estúdio — ' . $project['title'];
$layoutType = 'app';
$activeNav = 'projects';
$topbarTitle = 'Estúdio de clipes';
$topbarSubtitle = e($project['title']);

require_once __DIR__ . '/includes/header.php';

$statusKey = (string)($snapshot['status'] ?? 'awaiting_source');
$progressPercent = (int)($snapshot['progress_percent'] ?? 10);
?>

<div class="app-shell">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php require_once __DIR__ . '/includes/topbar.php'; ?>

        <main id="main-content" class="app-content">
            <?php render_flash_container(); ?>

            <section
                class="panel-card studio-progress-card"
                id="projectStudioRoot"
                data-project-id="<?= (int)$projectId ?>"
                data-poll-active="<?= in_array($statusKey, ['awaiting_processing', 'processing'], true) ? '1' : '0' ?>"
            >
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title">Progresso</h2>
                        <p class="form-hint" style="margin-top: 4px;" id="studioStatusLabel">
                            <?= e((string)($snapshot['status_label'] ?? '')) ?>
                        </p>
                    </div>
                    <a href="projects.php" class="btn btn-xs btn-secondary">Meus projetos</a>
                </div>
                <div class="panel-body">
                    <div class="studio-progress-bar" role="progressbar" aria-valuenow="<?= $progressPercent ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="studio-progress-fill" id="studioProgressFill" style="width: <?= $progressPercent ?>%;"></div>
                    </div>
                    <ol class="studio-steps" id="studioSteps">
                        <li class="<?= $progressPercent >= 15 ? 'is-done' : '' ?>">Fonte</li>
                        <li class="<?= $progressPercent >= 40 ? 'is-done' : '' ?>">Na fila</li>
                        <li class="<?= $progressPercent >= 70 ? 'is-active' : '' ?>">Processando</li>
                        <li class="<?= $progressPercent >= 100 ? 'is-done' : '' ?>">Clipes prontos</li>
                    </ol>
                    <div id="studioErrorBox" class="studio-error-box" style="<?= ($statusKey === 'failed' && !empty($snapshot['last_error'])) ? '' : 'display:none;' ?>">
                        <?= e((string)($snapshot['last_error'] ?? '')) ?>
                    </div>
                    <?php if ($statusKey === 'awaiting_processing'): ?>
                        <p class="form-hint" id="studioWorkerHint">
                            Projeto na fila. Inicie o worker: <code>python main.py --process-next</code> em <code>worker_service/</code>.
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel-card" style="margin-top: 20px;" aria-labelledby="studio-clips-heading">
                <div class="panel-header">
                    <h2 id="studio-clips-heading" class="panel-title">Clipes gerados</h2>
                    <span class="badge badge-violet" id="studioClipsCountBadge">
                        <?= (int)($snapshot['clips_with_mp4'] ?? 0) ?> / <?= (int)($project['target_clips'] ?? 0) ?> com vídeo
                    </span>
                </div>
                <div class="panel-body">
                    <?php if (empty($candidates)): ?>
                        <p id="studioEmptyClips" class="empty-state-text" style="margin: 0;">
                            Nenhum clipe ainda. Quando o worker concluir, os cortes aparecerão aqui com prévia e download.
                        </p>
                    <?php else: ?>
                        <div class="studio-clips-grid" id="studioClipsGrid">
                            <?php foreach ($candidates as $idx => $cand): ?>
                                <?php
                                $candId = (int)$cand['id'];
                                $dur = round((float)($cand['duration_seconds'] ?? 0), 1);
                                $hasMp4 = !empty($cand['storage_url']);
                                ?>
                                <article class="studio-clip-card">
                                    <?php if ($hasMp4): ?>
                                        <video
                                            controls
                                            preload="metadata"
                                            class="studio-clip-video"
                                            src="pages/stream-clip.php?id=<?= $candId ?>"
                                        ></video>
                                    <?php else: ?>
                                        <div class="studio-clip-placeholder">
                                            <?= clipia_icon('film', 28) ?>
                                            <span>Renderizando…</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="studio-clip-meta">
                                        <h3><?= e((string)$cand['title']) ?></h3>
                                        <span class="badge badge-neutral"><?= studio_format_mmss((float)$cand['start_time_seconds']) ?> · <?= e((string)$dur) ?>s</span>
                                    </div>
                                    <div class="studio-clip-actions">
                                        <?php if ($hasMp4): ?>
                                            <a href="pages/stream-clip.php?id=<?= $candId ?>&amp;download=1" class="btn btn-xs btn-primary">
                                                <?= clipia_icon('download', 14) ?>
                                                <span>Baixar</span>
                                            </a>
                                        <?php endif; ?>
                                        <a href="pages/stream-clip.php?id=<?= $candId ?>&amp;format=srt" class="btn btn-xs btn-secondary">SRT</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($statusKey === 'awaiting_source'): ?>
                <section class="panel-card" style="margin-top: 20px;">
                    <div class="panel-body" style="display: grid; gap: 16px; max-width: 560px;">
                        <p style="margin: 0;">Adicione uma fonte autorizada para enfileirar os clipes:</p>
                        <form action="projects.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="generate_clips">
                            <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                            <label class="form-label" for="studioDirectUrl">URL HTTPS do arquivo</label>
                            <div class="url-input-row">
                                <input type="url" id="studioDirectUrl" name="direct_media_url" class="form-control" placeholder="https://…/video.mp4" required>
                                <button type="submit" class="btn btn-primary btn-sm">Gerar clipes</button>
                            </div>
                        </form>
                        <form action="projects.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="upload_authorized_source">
                            <input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
                            <label class="form-label" for="studioUploadFile">Ou envie o vídeo</label>
                            <div class="url-input-row">
                                <input type="file" id="studioUploadFile" name="authorized_video_file" class="form-control" accept="video/mp4,video/quicktime,video/webm" required>
                                <button type="submit" class="btn btn-secondary btn-sm">Enviar arquivo</button>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
