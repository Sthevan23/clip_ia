<?php
declare(strict_types=1);

/**
 * ClipIA - Cabeçalho Reutilizável
 *
 * Variáveis esperadas da página:
 * - $pageTitle (string)
 * - $pageDescription (string, opcional)
 * - $layoutType ('public' | 'auth' | 'app')
 * - $activeNav (string, opcional)
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/alerts.php';

$pageTitle = isset($pageTitle) ? $pageTitle . ' — ' . APP_NAME : APP_NAME . ' — Cortes Inteligentes para Redes Sociais';
$pageDescription = $pageDescription ?? 'Plataforma web de inteligência artificial para identificar momentos relevantes de vídeos e estruturar cortes verticais 9:16 para redes sociais.';
$layoutType = $layoutType ?? 'public';
$activeNav = $activeNav ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($pageDescription) ?>">
    <meta name="theme-color" content="#0A0B10">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(ASSETS_URL) ?>/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(ASSETS_URL) ?>/css/style.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="layout-<?= e($layoutType) ?>">
    <a href="#main-content" class="skip-link">Pular para o conteúdo principal</a>

    <?php if ($layoutType === 'public'): ?>
    <header class="site-header" role="banner">
        <div class="container header-inner">
            <a href="index.php" class="brand" aria-label="ClipIA - Página Inicial">
                <span class="brand-mark" aria-hidden="true">
                    <?= clipia_icon('scissors', 18) ?>
                </span>
                <span class="brand-text">Clip<span class="brand-accent">IA</span></span>
                <span class="brand-badge">Beta UI</span>
            </a>

            <button type="button" class="mobile-nav-toggle" id="publicMenuToggle" aria-expanded="false" aria-controls="publicNav" aria-label="Abrir menu de navegação">
                <?= clipia_icon('menu', 22) ?>
            </button>

            <nav class="site-nav" id="publicNav" aria-label="Navegação Principal">
                <ul class="nav-list">
                    <li><a href="index.php#como-funciona" class="nav-link">Como funciona</a></li>
                    <li><a href="index.php#demonstracao" class="nav-link">Demonstração</a></li>
                    <li><a href="index.php#beneficios" class="nav-link">Benefícios</a></li>
                    <li><a href="index.php#recursos" class="nav-link">Recursos</a></li>
                    <li><a href="dashboard.php" class="nav-link nav-link-subtle">Ver Painel</a></li>
                </ul>
                <div class="header-actions">
                    <a href="login.php" class="btn btn-primary btn-sm">Acesso Administrativo</a>
                </div>
            </nav>
        </div>
    </header>
    <?php endif; ?>
