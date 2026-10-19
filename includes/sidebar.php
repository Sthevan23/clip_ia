<?php
declare(strict_types=1);

/**
 * ClipIA - Menu Lateral Reutilizável (Painel SaaS Autenticado)
 */

$activeNav = $activeNav ?? 'dashboard';
$userProfile = get_current_user_profile();
$userId = (int)($userProfile['id'] ?? 0);
$stats = $userId > 0 ? ProjectRepository::getStatsForUser($userId) : ['total_projects' => 0];
$projectsCount = (int)($stats['total_projects'] ?? 0);
?>
<aside class="app-sidebar" id="appSidebar" aria-label="Menu Lateral da Plataforma">
    <div class="sidebar-header">
        <a href="dashboard.php" class="brand" aria-label="ClipIA - Ir para o Dashboard">
            <span class="brand-mark" aria-hidden="true">
                <?= clipia_icon('scissors', 18) ?>
            </span>
            <span class="brand-text">Clip<span class="brand-accent">IA</span></span>
        </a>
        <button type="button" class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="Fechar menu lateral">
            <?= clipia_icon('close', 20) ?>
        </button>
    </div>

    <div class="sidebar-cta">
        <a href="create-clips.php" class="btn btn-primary btn-block">
            <?= clipia_icon('plus-circle', 18) ?>
            <span>Criar clipes</span>
        </a>
    </div>

    <nav class="sidebar-nav" aria-label="Menu do Painel">
        <div class="sidebar-group-label">Plataforma</div>
        <ul class="sidebar-menu">
            <li>
                <a href="dashboard.php" class="sidebar-link <?= $activeNav === 'dashboard' ? 'is-active' : '' ?>" <?= $activeNav === 'dashboard' ? 'aria-current="page"' : '' ?>>
                    <?= clipia_icon('dashboard', 19) ?>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="create-clips.php" class="sidebar-link <?= in_array($activeNav, ['create-clips', 'new-project'], true) ? 'is-active' : '' ?>" <?= $activeNav === 'create-clips' ? 'aria-current="page"' : '' ?>>
                    <?= clipia_icon('sparkles', 19) ?>
                    <span>Criar clipes</span>
                </a>
            </li>
            <li>
                <a href="projects.php" class="sidebar-link <?= $activeNav === 'projects' ? 'is-active' : '' ?>" <?= $activeNav === 'projects' ? 'aria-current="page"' : '' ?>>
                    <?= clipia_icon('folder', 19) ?>
                    <span>Meus projetos</span>
                    <?php if ($projectsCount > 0): ?>
                        <span class="sidebar-counter"><?= $projectsCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <div class="sidebar-group-label">Conta & Sistema</div>
        <ul class="sidebar-menu">
            <li>
                <a href="settings.php" class="sidebar-link <?= $activeNav === 'settings' ? 'is-active' : '' ?>" <?= $activeNav === 'settings' ? 'aria-current="page"' : '' ?>>
                    <?= clipia_icon('settings', 19) ?>
                    <span>Configurações</span>
                </a>
            </li>
            <li>
                <a href="index.php" class="sidebar-link">
                    <?= clipia_icon('external-link', 19) ?>
                    <span>Landing Page</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <div class="worker-status-box" title="Arquitetura preparada para conexão futura com serviço Python externo">
            <div class="worker-status-header">
                <span class="status-dot status-dot-standby" aria-hidden="true"></span>
                <span class="worker-status-title">Motor IA Externo</span>
            </div>
            <p class="worker-status-desc">Banco MySQL ativo. Worker Python desacoplado aguardando integração.</p>
        </div>

        <div class="sidebar-user">
            <div class="user-avatar" aria-hidden="true">
                <?= e(mb_strtoupper(mb_substr($userProfile['name'] ?: 'U', 0, 1))) ?>
            </div>
            <div class="user-info">
                <strong class="user-name"><?= e($userProfile['name']) ?></strong>
                <span class="user-email"><?= e($userProfile['email']) ?></span>
            </div>
            <form action="logout.php" method="POST" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="user-logout" style="background:transparent; border:none; cursor:pointer;" title="Sair da conta com segurança" aria-label="Sair da conta">
                    <?= clipia_icon('log-out', 18) ?>
                </button>
            </form>
        </div>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
