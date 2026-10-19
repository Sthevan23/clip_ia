<?php
declare(strict_types=1);

/**
 * ClipIA - Funções Auxiliares de Segurança, Validação e Formatação
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/youtube.php';

/**
 * Sanitiza strings para exibição segura no HTML (prevenção contra XSS)
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Gera ou retorna o token CSRF da sessão atual
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

/**
 * Verifica se o token CSRF enviado é válido em tempo constante
 */
function verify_csrf(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals((string)$_SESSION['csrf_token'], (string)$token);
}

/**
 * Define uma mensagem flash na sessão (success, error, warning, info)
 */
function set_flash(string $type, string $title, string $message): void
{
    $_SESSION['flash_messages'][] = [
        'type'    => $type,
        'title'   => $title,
        'message' => $message,
    ];
}

/**
 * Retorna e limpa as mensagens flash da sessão
 */
function get_flashes(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Extrai e valida o ID de 11 caracteres de uma URL do YouTube usando o validador estrito no servidor.
 */
function extract_youtube_id(string $url): ?string
{
    $res = YouTubeUrlValidator::validate($url);
    return $res['valid'] ? $res['video_id'] : null;
}

/**
 * Valida regras de senha forte no servidor:
 * Mínimo de 8 caracteres, com pelo menos 1 letra e 1 número.
 */
function validate_password_strength(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'A senha deve conter no mínimo 8 caracteres.';
    }
    if (strlen($password) > 72) {
        return 'A senha não pode ultrapassar 72 caracteres.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'A senha deve conter pelo menos uma letra e um número.';
    }
    return null;
}

/**
 * Retorna os dados reais do perfil do usuário autenticado a partir do banco de dados.
 */
function get_current_user_profile(): array
{
    if (!empty($_SESSION['auth_user_id'])) {
        $dbUser = UserRepository::findById((int)$_SESSION['auth_user_id']);
        if ($dbUser !== null) {
            return [
                'id'                   => (int)$dbUser['id'],
                'name'                 => (string)$dbUser['name'],
                'email'                => (string)$dbUser['email'],
                'plan_tier'            => (string)($dbUser['plan_tier'] ?? 'starter'),
                'default_aspect_ratio' => (string)($dbUser['default_aspect_ratio'] ?? '9:16'),
                'default_clips_count'  => (int)($dbUser['default_clips_count'] ?? 5),
                'default_min_duration' => (int)($dbUser['default_min_duration'] ?? 30),
                'default_max_duration' => (int)($dbUser['default_max_duration'] ?? 60),
                'email_notifications'  => !empty($dbUser['email_notifications']),
            ];
        }
    }

    return [
        'id'                   => 0,
        'name'                 => 'Usuário',
        'email'                => '',
        'plan_tier'            => 'starter',
        'default_aspect_ratio' => '9:16',
        'default_clips_count'  => 5,
        'default_min_duration' => 30,
        'default_max_duration' => 60,
        'email_notifications'  => true,
    ];
}

/**
 * Formata um registro de `usage_logs` para exibição no painel de Atividades Recentes.
 */
function format_usage_log_entry(array $log): array
{
    $action = (string)($log['action_type'] ?? '');
    $title = match ($action) {
        'account_created'     => 'Conta criada no ClipIA',
        'user_login'          => 'Acesso realizado na plataforma',
        'project_created'     => 'Novo projeto configurado',
        'source_uploaded'     => 'Arquivo de vídeo autorizado vinculado',
        'project_updated'     => 'Projeto atualizado',
        'project_deleted'     => 'Projeto excluído',
        'profile_updated'     => 'Dados de perfil atualizados',
        'preferences_updated' => 'Preferências de corte atualizadas',
        'password_changed'    => 'Senha alterada com segurança',
        default               => 'Atividade registrada',
    };

    $type = match ($action) {
        'account_created', 'project_created', 'source_uploaded', 'password_changed' => 'success',
        default => 'info',
    };

    $createdAt = (string)($log['created_at'] ?? '');
    $timeFormatted = $createdAt !== '' ? date('d/m/Y H:i', strtotime($createdAt)) : 'Recente';

    return [
        'title'    => $title,
        'subtitle' => (string)($log['description'] ?? ''),
        'time'     => $timeFormatted,
        'type'     => $type,
    ];
}
