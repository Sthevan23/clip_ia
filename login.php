<?php
declare(strict_types=1);

/**
 * ClipIA — 2. Página de Login (Autenticação Real com password_verify e PDO)
 */

require_once __DIR__ . '/includes/auth.php';

redirect_if_authenticated();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!verify_csrf($csrf)) {
        set_flash('error', 'Sessão expirada', 'Não foi possível validar o token de segurança. Recarregue a página e tente novamente.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', 'E-mail inválido', 'Informe um endereço de e-mail válido para continuar.');
    } elseif ($password === '') {
        set_flash('error', 'Senha obrigatória', 'Digite sua senha para acessar sua conta.');
    } elseif (!Database::isConnected()) {
        set_flash('error', 'Banco de dados indisponível', Database::getPublicErrorMessage());
    } else {
        UserRepository::ensureOwnerAccountIfEmpty();
        $user = UserRepository::findByEmail($email);

        // Mensagem genérica unificada para não expor se o e-mail existe ou não (Prevenção contra enumeração de usuários)
        if ($user === null || !password_verify($password, (string)$user['password_hash'])) {
            set_flash('error', 'Credenciais inválidas', 'E-mail ou senha incorretos. Verifique os dados informados e tente novamente.');
        } else {
            login_user_session($user);
            UsageLogRepository::log((int)$user['id'], 'user_login', 'Login realizado com sucesso.');

            set_flash(
                'success',
                'Bem-vindo de volta, ' . $user['name'] . '!',
                'Seu acesso foi autenticado com segurança.'
            );
            header('Location: dashboard.php');
            exit;
        }
    }
}

$pageTitle = 'Entrar na Conta';
$layoutType = 'auth';

require_once __DIR__ . '/includes/header.php';
?>

<main id="main-content" class="auth-wrapper">
    <div class="auth-top-brand">
        <a href="index.php" class="brand" aria-label="ClipIA - Voltar para a página inicial">
            <span class="brand-mark" aria-hidden="true">
                <?= clipia_icon('scissors', 18) ?>
            </span>
            <span class="brand-text">Clip<span class="brand-accent">IA</span></span>
        </a>
    </div>

    <section class="auth-card" aria-labelledby="login-title">
        <div class="auth-header">
            <h1 id="login-title" class="auth-title">Acesso Administrativo</h1>
            <p class="auth-subtitle">Uso exclusivo do proprietário. Entre com suas credenciais administrativas.</p>
        </div>

        <?php render_flash_container(); ?>

        <form action="login.php" method="POST" id="loginForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="form-group">
                <label for="loginEmail" class="form-label">E-mail</label>
                <input
                    type="email"
                    id="loginEmail"
                    name="email"
                    class="form-control"
                    placeholder="voce@empresa.com.br"
                    value="<?= e($_POST['email'] ?? '') ?>"
                    autocomplete="email"
                    required
                >
                <span class="form-error-msg">Informe um e-mail válido.</span>
            </div>

            <div class="form-group">
                <div class="form-label-row">
                    <label for="loginPassword" class="form-label">Senha</label>
                </div>
                <div class="input-group input-with-action">
                    <input
                        type="password"
                        id="loginPassword"
                        name="password"
                        class="form-control"
                        placeholder="Digite sua senha"
                        autocomplete="current-password"
                        required
                    >
                    <button
                        type="button"
                        class="input-action-btn"
                        data-toggle-password="loginPassword"
                        aria-label="Mostrar senha"
                        aria-pressed="false"
                    >
                        <span class="icon-eye-open"><?= clipia_icon('eye', 18) ?></span>
                        <span class="icon-eye-off" style="display:none;"><?= clipia_icon('eye-off', 18) ?></span>
                    </button>
                </div>
                <span class="form-error-msg">Informe sua senha.</span>
            </div>

            <div class="form-group" style="margin-top: 22px;">
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <span>Entrar na plataforma</span>
                    <?= clipia_icon('arrow-right', 18) ?>
                </button>
            </div>
        </form>

        <div class="auth-footer-text">
            Acesso restrito ao proprietário (cadastro público desativado).
        </div>
    </section>

    <p style="margin-top:20px; font-size:0.8rem; color:var(--text-muted);">
        <a href="index.php" style="color:var(--text-secondary);">&larr; Voltar para a Landing Page</a>
    </p>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
