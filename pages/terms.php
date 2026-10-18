<?php
declare(strict_types=1);

/**
 * ClipIA — Termos de Uso e Diretrizes de Direitos Autorais (/pages/terms.php)
 */

require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Termos de Uso';
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
            <h1 class="panel-title" style="font-size: 1.35rem;">Termos de Uso e Diretrizes de Conteúdo</h1>
            <span class="badge badge-violet">Institucional</span>
        </div>
        <div class="panel-body" style="display: flex; flex-direction: column; gap: 18px; color: var(--text-secondary);">
            <section>
                <h2 style="color: var(--text-primary); font-size: 1.05rem; margin-bottom: 6px;">1. Objeto da Plataforma</h2>
                <p>
                    O ClipIA é uma aplicação web voltada à organização de projetos de vídeo e configuração de parâmetros para geração de cortes verticais destinados a redes sociais.
                </p>
            </section>

            <section id="direitos-autorais">
                <h2 style="color: var(--text-primary); font-size: 1.05rem; margin-bottom: 6px;">2. Identificação via URL e Fontes Autorizadas</h2>
                <p>
                    A inserção de um link do YouTube na tela de Novo Projeto tem finalidade exclusiva de identificação de metadados públicos de referência (título e miniatura). A URL informada não concede nem garante autorização automática para download ou redistribuição de obras de terceiros. Toda importação de vídeo em etapas futuras deverá utilizar uma fonte autorizada ou de titularidade do usuário.
                </p>
            </section>

            <section>
                <h2 style="color: var(--text-primary); font-size: 1.05rem; margin-bottom: 6px;">3. Responsabilidade do Usuário</h2>
                <p>
                    O usuário declara ser titular dos direitos ou possuir permissão expressa sobre qualquer material audiovisual submetido para processamento na plataforma.
                </p>
            </section>
        </div>
    </article>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
