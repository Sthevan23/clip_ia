<?php
declare(strict_types=1);

/**
 * ClipIA — Política de Privacidade (/pages/privacy.php)
 */

require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Política de Privacidade';
$layoutType = 'auth';

require_once __DIR__ . '/../includes/header.php';
?>

<main id="main-content" class="container" style="padding: 48px 24px; max-width: 820px;">
    <div style="margin-bottom: 28px; display: flex; justify-content: space-between; align-items: center;">
        <a href="../index.php" class="brand">
            <span class="brand-mark" aria-hidden="true"><?= clipia_icon('scissors', 18) ?></span>
            <span class="brand-text">Clip<span class="brand-accent">IA</span></span>
        </a>
        <a href="../index.php" class="btn btn-secondary btn-sm">&larr; Voltar</a>
    </div>

    <article class="panel-card">
        <div class="panel-header">
            <h1 class="panel-title" style="font-size: 1.35rem;">Política de Privacidade</h1>
            <span class="badge badge-neutral">LGPD & Segurança</span>
        </div>
        <div class="panel-body" style="display: flex; flex-direction: column; gap: 18px; color: var(--text-secondary);">
            <section>
                <h2 style="color: var(--text-primary); font-size: 1.05rem; margin-bottom: 6px;">1. Dados Coletados</h2>
                <p>
                    Coletamos apenas as informações essenciais para criação de conta (nome e e-mail) e os parâmetros técnicos dos projetos de corte configurados pelo usuário.
                </p>
            </section>

            <section>
                <h2 style="color: var(--text-primary); font-size: 1.05rem; margin-bottom: 6px;">2. Segurança e Credenciais</h2>
                <p>
                    Nenhuma chave de API ou credencial de banco de dados é exposta no frontend. As senhas serão armazenadas utilizando hash criptográfico nativo do PHP (`password_hash`) no banco de dados MySQL.
                </p>
            </section>
        </div>
    </article>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
