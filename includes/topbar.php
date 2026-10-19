<?php
declare(strict_types=1);

/**
 * ClipIA - Barra Superior do Painel (Topbar)
 */

$topbarTitle = $topbarTitle ?? 'Painel';
$topbarSubtitle = $topbarSubtitle ?? '';
$userProfile = get_current_user_profile();
?>
<header class="app-topbar">
    <div class="topbar-left">
        <button type="button" class="sidebar-open-btn" id="sidebarOpenBtn" aria-label="Abrir menu lateral" aria-controls="appSidebar" aria-expanded="false">
            <?= clipia_icon('menu', 22) ?>
        </button>
        <div>
            <h1 class="topbar-title"><?= e($topbarTitle) ?></h1>
            <?php if ($topbarSubtitle !== ''): ?>
                <p class="topbar-subtitle"><?= e($topbarSubtitle) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="topbar-right">
        <a href="create-clips.php" class="btn btn-primary btn-sm topbar-cta">
            <?= clipia_icon('plus', 16) ?>
            <span>Criar clipes</span>
        </a>

        <form action="logout.php" method="POST" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="btn btn-outline btn-sm" title="Encerrar sessão atual">
                <?= clipia_icon('log-out', 16) ?>
                <span>Sair</span>
            </button>
        </form>
    </div>
</header>
