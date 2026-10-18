<?php
declare(strict_types=1);

/**
 * ClipIA - Endpoint Seguro de Logout
 * Exige método POST + Token CSRF válido para evitar ataques de CSRF de logout forçado.
 */

require_once __DIR__ . '/includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($csrf)) {
        set_flash('error', 'Solicitação inválida', 'Não foi possível validar o token de segurança para encerrar a sessão.');
        header('Location: dashboard.php');
        exit;
    }

    logout_user_session();
    set_flash('success', 'Sessão encerrada', 'Você saiu da sua conta com segurança.');
    header('Location: login.php');
    exit;
}

// Caso alguém acesse via GET diretamente, redireciona com segurança
header('Location: login.php');
exit;
