<?php
declare(strict_types=1);

/**
 * ClipIA - Autenticação Real, Sessões Seguras e Proteção de Rotas Privadas
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/repositories.php';

/**
 * Verifica se há um usuário autenticado na sessão atual.
 */
function is_authenticated(): bool
{
    return !empty($_SESSION['auth_user_id']) && is_int($_SESSION['auth_user_id']);
}

/**
 * Retorna o ID do usuário autenticado ou null.
 */
function current_user_id(): ?int
{
    return is_authenticated() ? (int)$_SESSION['auth_user_id'] : null;
}

/**
 * Inicia a sessão autenticada do usuário com regeneração de ID de sessão (proteção contra Session Fixation)
 * e renovação do token CSRF.
 */
function login_user_session(array $user): void
{
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['auth_user_id'] = (int)$user['id'];
    $_SESSION['auth_user_email'] = (string)$user['email'];
    $_SESSION['auth_user_name'] = (string)$user['name'];
    $_SESSION['auth_login_time'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    UserRepository::touchLastLogin((int)$user['id']);
}

/**
 * Encerra a sessão do usuário com segurança, limpando variáveis e invalidando o cookie de sessão.
 */
function logout_user_session(): void
{
    $userId = current_user_id();
    if ($userId !== null) {
        UsageLogRepository::log($userId, 'user_logout', 'Sessão encerrada pelo usuário.');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    // Inicia nova sessão limpa apenas para poder armazenar mensagens flash pós-logout
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

/**
 * Protege páginas privadas contra usuários não autenticados.
 * Caso o usuário não esteja logado ou não exista mais no banco, redireciona para login.php.
 */
function require_auth(): array
{
    if (!is_authenticated()) {
        set_flash('warning', 'Acesso restrito', 'Faça login na sua conta para acessar o painel do ClipIA.');
        if (!headers_sent()) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        throw new RuntimeException('Usuário não autenticado.');
    }

    $user = UserRepository::findById((int)$_SESSION['auth_user_id']);
    if ($user === null) {
        logout_user_session();
        set_flash('error', 'Sessão inválida', 'Sua sessão expirou ou a conta não foi encontrada. Faça login novamente.');
        if (!headers_sent()) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        throw new RuntimeException('Conta não encontrada.');
    }

    return $user;
}

/**
 * Redireciona usuários já autenticados para o dashboard ao visitarem login.php ou register.php.
 */
function redirect_if_authenticated(): void
{
    if (is_authenticated() && UserRepository::findById((int)$_SESSION['auth_user_id']) !== null) {
        if (!headers_sent()) {
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
    }
}
