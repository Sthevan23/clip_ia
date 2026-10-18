<?php
declare(strict_types=1);

/**
 * ClipIA — Cadastro Público Desativado (Uso Exclusivo do Proprietário)
 *
 * O sistema opera exclusivamente para o proprietário administrativo, sem registro aberto a terceiros.
 */

require_once __DIR__ . '/includes/auth.php';

redirect_if_authenticated();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    http_response_code(403);
}

set_flash(
    'warning',
    'Cadastro público desativado',
    'Este sistema é de uso exclusivo do administrador proprietário. Utilize suas credenciais administrativas para entrar.'
);

if (!headers_sent()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
