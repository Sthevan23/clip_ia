<?php
declare(strict_types=1);

/**
 * Recuperação única do login admin (produção).
 * Requer em private/clipia.env.php:
 *   'CLIPIA_ALLOW_OWNER_SETUP_ONCE' => '1',
 *   'CLIPIA_OWNER_SETUP_TOKEN' => 'segredo-longo-aleatorio-min-16-chars',
 * Após usar: apague este arquivo do servidor e volte ALLOW para '0'.
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/repositories.php';

if (!defined('APP_ENV') || APP_ENV !== 'production') {
    http_response_code(404);
    exit;
}

$allow = clipia_env('CLIPIA_ALLOW_OWNER_SETUP_ONCE', '0') === '1';
$setupToken = trim(clipia_env('CLIPIA_OWNER_SETUP_TOKEN', ''));
if (!$allow || strlen($setupToken) < 16) {
    http_response_code(404);
    exit;
}

$message = '';
$messageType = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = (string)($_POST['setup_token'] ?? '');
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if (!hash_equals($setupToken, $token)) {
        $message = 'Token de setup inválido.';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'E-mail inválido.';
        $messageType = 'error';
    } elseif (!UserRepository::isOwnerPasswordAcceptable($password)) {
        $message = 'Senha deve ter entre 8 e 256 caracteres.';
        $messageType = 'error';
    } elseif (!Database::isConnected()) {
        $message = 'Banco indisponível.';
        $messageType = 'error';
    } elseif (UserRepository::applyOwnerRecoveryCredentials($email, $password)) {
        $message = 'Conta atualizada. Remova owner-setup-once.php e CLIPIA_ALLOW_OWNER_SETUP_ONCE do env. Faça login em login.php.';
        $messageType = 'success';
    } else {
        $message = 'Não foi possível criar/atualizar a conta (verifique phpMyAdmin ou logs).';
        $messageType = 'error';
    }
}

$pageTitle = 'Setup admin (uma vez)';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($pageTitle) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 420px; margin: 2rem auto; padding: 0 1rem; }
        .ok { color: #0a0; } .err { color: #c00; }
        label { display: block; margin-top: 1rem; }
        input { width: 100%; padding: 0.5rem; box-sizing: border-box; }
        button { margin-top: 1rem; padding: 0.6rem 1rem; }
    </style>
</head>
<body>
    <h1>Recuperar admin (uma vez)</h1>
    <?php if ($message !== ''): ?>
        <p class="<?= $messageType === 'success' ? 'ok' : 'err' ?>"><?= e($message) ?></p>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <label>Token (do env privado)
            <input type="password" name="setup_token" required>
        </label>
        <label>E-mail admin
            <input type="email" name="email" value="01@clipia.sthevandev.com.br" required>
        </label>
        <label>Senha nova
            <input type="password" name="password" required minlength="8">
        </label>
        <button type="submit">Gravar conta admin</button>
    </form>
</body>
</html>
