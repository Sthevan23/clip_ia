<?php
declare(strict_types=1);

/**
 * ClipIA — Orientação de Recuperação de Senha Administrativa (/pages/forgot-password.php)
 * Sistema de uso individual exclusivo do proprietário (sem envio público de redefinição para terceiros).
 */

require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Acesso Administrativo';
$layoutType = 'auth';

require_once __DIR__ . '/../includes/header.php';
?>

<main id="main-content" class="auth-wrapper">
    <div class="auth-top-brand">
        <a href="../index.php" class="brand">
            <span class="brand-mark" aria-hidden="true">
                <?= clipia_icon('scissors', 18) ?>
            </span>
            <span class="brand-text">Clip<span class="brand-accent">IA</span></span>
        </a>
    </div>

    <section class="auth-card" aria-labelledby="recover-title">
        <div class="auth-header">
            <h1 id="recover-title" class="auth-title">Redefinição Administrativa</h1>
            <p class="auth-subtitle">
                O ClipIA opera em modo exclusivo do proprietário. Para redefinir a senha administrativa, atualize a tabela <code>users</code> via phpMyAdmin no hPanel da Hostinger com um novo hash gerado por <code>password_hash()</code>.
            </p>
        </div>

        <div class="auth-footer-text">
            <a href="../login.php" class="auth-link">&larr; Voltar para o login administrativo</a>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
