<?php
declare(strict_types=1);

/**
 * ClipIA — 4. Dashboard Principal (Protegido por Autenticação Real + Dados Reais do MySQL)
 */

require_once __DIR__ . '/includes/auth.php';

$currentUser = require_auth();
$userId = (int)$currentUser['id'];

$stats = ProjectRepository::getStatsForUser($userId);
$projects = ProjectRepository::listByUser($userId, 5);
$rawLogs = UsageLogRepository::listRecentByUser($userId, 8);
$activities = array_map('format_usage_log_entry', $rawLogs);

$totalProjects = (int)$stats['total_projects'];
$totalVideosProcessed = (int)$stats['total_videos_processed'];
$totalClipsGenerated = (int)$stats['total_clips_generated'];

$pageTitle = 'Dashboard';
$layoutType = 'app';
$activeNav = 'dashboard';
$topbarTitle = 'Dashboard';
$topbarSubtitle = 'Visão geral dos seus projetos, vídeos e cortes configurados.';

require_once __DIR__ . '/includes/header.php';
?>

<div class="app-shell">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="app-main">
        <?php require_once __DIR__ . '/includes/topbar.php'; ?>

        <main id="main-content" class="app-content">
            <?php render_flash_container(); ?>

            <!-- Cards de Resumo (Projetos, Vídeos Processados, Cortes Gerados) -->
            <section class="stats-grid" aria-label="Métricas principais">
                <article class="stat-card">
                    <div>
                        <p class="stat-label">Resumo de Projetos</p>
                        <p class="stat-value"><?= $totalProjects ?></p>
                        <p class="stat-footnote">Projetos vinculados à sua conta</p>
                    </div>
                    <div class="stat-icon">
                        <?= clipia_icon('folder', 22) ?>
                    </div>
                </article>

                <article class="stat-card">
                    <div>
                        <p class="stat-label">Total de Vídeos Processados</p>
                        <p class="stat-value"><?= $totalVideosProcessed ?></p>
                        <p class="stat-footnote">Vídeos com processamento concluído</p>
                    </div>
                    <div class="stat-icon">
                        <?= clipia_icon('video', 22) ?>
                    </div>
                </article>

                <article class="stat-card">
                    <div>
                        <p class="stat-label">Total de Cortes Gerados</p>
                        <p class="stat-value"><?= $totalClipsGenerated ?></p>
                        <p class="stat-footnote">Cortes registrados nos seus projetos</p>
                    </div>
                    <div class="stat-icon">
                        <?= clipia_icon('scissors', 22) ?>
                    </div>
                </article>
            </section>

            <!-- Colunas: Projetos Recentes & Atividades Recentes -->
            <div class="dashboard-columns">
                <!-- Área de Projetos Recentes -->
                <section class="panel-card" aria-labelledby="recent-projects-heading">
                    <div class="panel-header">
                        <h2 id="recent-projects-heading" class="panel-title">Projetos Recentes</h2>
                        <div style="display:flex; gap:8px;">
                            <a href="projects.php" class="btn btn-xs btn-outline">Ver todos</a>
                            <a href="create-clips.php" class="btn btn-xs btn-primary">
                                <?= clipia_icon('plus', 14) ?>
                                <span>Criar clipes</span>
                            </a>
                        </div>
                    </div>

                    <div class="panel-body">
                        <?php if (empty($projects)): ?>
                            <!-- Estado Vazio Bem Desenhado para Contas Novas -->
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <?= clipia_icon('film', 24) ?>
                                </div>
                                <h3 class="empty-state-title">Nenhum projeto criado até o momento</h3>
                                <p class="empty-state-text">
                                    Envie um vídeo ou cole uma URL HTTPS direta para gerar clipes verticais automaticamente.
                                </p>
                                <div class="empty-state-actions">
                                    <a href="create-clips.php" class="btn btn-primary btn-sm">
                                        <?= clipia_icon('plus-circle', 16) ?>
                                        <span>Criar clipes</span>
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Projeto</th>
                                            <th>Formato</th>
                                            <th>Cortes</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($projects as $proj): ?>
                                            <?php
                                            $statusClass = (string)($proj['status_badge_class'] ?? 'badge-warning');
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="project-cell-title">
                                                        <span><?= e($proj['title']) ?></span>
                                                    </div>
                                                    <span class="project-cell-url"><?= e($proj['created_at']) ?></span>
                                                </td>
                                                <td><span class="badge badge-neutral"><?= e($proj['aspect_ratio']) ?></span></td>
                                                <td><?= (int)($proj['target_clips'] ?? 0) ?> cortes</td>
                                                <td><span class="badge <?= e($statusClass) ?>"><?= e($proj['status_label'] ?? 'Aguardando fonte') ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Área de Atividades Recentes -->
                <section class="panel-card" aria-labelledby="recent-activities-heading">
                    <div class="panel-header">
                        <h2 id="recent-activities-heading" class="panel-title">Atividades Recentes</h2>
                        <span class="badge badge-neutral">Histórico Real</span>
                    </div>

                    <div class="panel-body">
                        <?php if (empty($activities)): ?>
                            <!-- Estado Vazio Bem Desenhado para Atividades -->
                            <div class="empty-state" style="padding: 32px 18px;">
                                <div class="empty-state-icon">
                                    <?= clipia_icon('activity', 22) ?>
                                </div>
                                <h3 class="empty-state-title">Sem atividades registradas</h3>
                                <p class="empty-state-text" style="margin-bottom: 0;">
                                    As criações de projetos, alterações de configurações e eventos do processamento aparecerão aqui em ordem cronológica.
                                </p>
                            </div>
                        <?php else: ?>
                            <ul class="activity-list">
                                <?php foreach ($activities as $act): ?>
                                    <li class="activity-item">
                                        <div class="activity-dot">
                                            <?= clipia_icon(($act['type'] ?? '') === 'success' ? 'check-circle' : 'sparkles', 16) ?>
                                        </div>
                                        <div>
                                            <p class="activity-title"><?= e($act['title']) ?></p>
                                            <p class="activity-subtitle"><?= e($act['subtitle']) ?></p>
                                            <p class="activity-time"><?= e($act['time']) ?></p>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
