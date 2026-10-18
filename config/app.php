<?php
declare(strict_types=1);

/**
 * ClipIA - Configurações Gerais da Aplicação, Produção e Sessão Segura
 * Compatível com PHP 8.2+ em hospedagem compartilhada (Hostinger Business)
 */

// 1. Busca arquivo de credenciais prioritariamente FORA da pasta pública (`public_html`)
//    Ex.: /home/u123456789/clipia_secrets/env.production.php ou ../clipia_env.php
$candidateEnvFiles = [];
$customEnvPath = getenv('CLIPIA_ENV_FILE') ?: ($_ENV['CLIPIA_ENV_FILE'] ?? '');
if (is_string($customEnvPath) && $customEnvPath !== '') {
    $candidateEnvFiles[] = $customEnvPath;
}
$projectRootDir = dirname(__DIR__);
$parentOfPublicRoot = dirname($projectRootDir);
$domainRootDir = dirname($parentOfPublicRoot);
$candidateEnvFiles[] = $parentOfPublicRoot . '/clipia_secrets/env.production.php';
$candidateEnvFiles[] = $parentOfPublicRoot . '/clipia_env.php';
$candidateEnvFiles[] = $domainRootDir . '/private/clipia.env.php';
$candidateEnvFiles[] = __DIR__ . '/env.local.php';

$localEnv = [];
foreach ($candidateEnvFiles as $envFileCandidate) {
    if (is_string($envFileCandidate) && $envFileCandidate !== '' && is_file($envFileCandidate) && is_readable($envFileCandidate)) {
        $loaded = include $envFileCandidate;
        if (is_array($loaded)) {
            $localEnv = array_merge($localEnv, $loaded);
        }
    }
}

if (!function_exists('clipia_env')) {
    function clipia_env(string $key, string $default = ''): string
    {
        global $localEnv;
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return (string)$val;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        if (isset($localEnv[$key]) && $localEnv[$key] !== '') {
            return (string)$localEnv[$key];
        }
        return $default;
    }
}

if (!function_exists('clipia_is_https_request')) {
    function clipia_is_https_request(?array $server = null): bool
    {
        $srv = $server ?? $_SERVER;
        if (!empty($srv['HTTPS']) && strtolower((string)$srv['HTTPS']) !== 'off') {
            return true;
        }
        if ((string)($srv['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if (strtolower((string)($srv['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        if (strtolower((string)($srv['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
        return false;
    }
}

// Definições básicas de ambiente e blindagem de erros internos antes de iniciar sessão
if (!defined('APP_NAME')) {
    define('APP_NAME', 'ClipIA');
    define('APP_VERSION', '0.5.0-prod-ready');
    define('APP_ENV', clipia_env('APP_ENV', 'development'));
    define('APP_TIMEZONE', clipia_env('APP_TIMEZONE', 'America/Sao_Paulo'));

    date_default_timezone_set(APP_TIMEZONE);

    // Oculta erros internos na tela em produção e grava apenas em arquivo de log protegido
    $logDir = $projectRootDir . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    ini_set('log_errors', '1');
    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', $logDir . '/php_errors.log');
    }

    if (APP_ENV === 'production') {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('expose_php', '0');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    } else {
        ini_set('display_errors', clipia_env('APP_DISPLAY_ERRORS', '0'));
        ini_set('display_startup_errors', '0');
        error_reporting(E_ALL);
    }

    // Detecção automática da URL base (compatível com proxy/SSL da Hostinger)
    $isHttpsReq = clipia_is_https_request();
    $protocol = ($isHttpsReq || APP_ENV === 'production') ? 'https' : 'http';
    $rawHost = (string)($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
    $safeHost = preg_replace('/[^A-Za-z0-9.\-:]/', '', $rawHost) ?: 'localhost:8000';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $baseDir = rtrim(preg_replace('#/pages$#', '', $scriptDir), '/');

    $configuredAppUrl = rtrim(clipia_env('APP_URL', ''), '/');
    define('BASE_URL', $configuredAppUrl !== '' ? $configuredAppUrl : ($protocol . '://' . $safeHost . ($baseDir ? $baseDir : '')));
    define('ASSETS_URL', BASE_URL . '/assets');

    // Configuração de autenticação entre o painel PHP e o Worker Python externo
    $defaultWorkerSecret = (APP_ENV === 'production')
        ? ''
        : 'clipia-dev-hmac-secret-key-change-in-production-2026';
    define('WORKER_SHARED_SECRET', clipia_env('WORKER_SHARED_SECRET', $defaultWorkerSecret));
    define('WORKER_API_ENABLED', WORKER_SHARED_SECRET !== '');
    define('WORKER_API_ENDPOINT', clipia_env('CLIPIA_WORKER_URL', ''));
    define('WORKER_REQUIRE_HTTPS', clipia_env('WORKER_REQUIRE_HTTPS', APP_ENV === 'production' ? '1' : '0') === '1');
    define('WORKER_API_TIMEOUT', 30);
    define('WORKER_JOB_STALE_TIMEOUT_SECONDS', max(60, (int)clipia_env('WORKER_JOB_STALE_TIMEOUT_SECONDS', '1800')));

    // Orçamento e limites de armazenamento
    define('MAX_MONTHLY_COST_BRL', max(1.0, (float)clipia_env('CLIPIA_MAX_MONTHLY_COST_BRL', '100.00')));
    define('MAX_COST_PER_JOB_BRL', max(0.05, (float)clipia_env('CLIPIA_MAX_COST_PER_JOB_BRL', '2.50')));
    define('STORAGE_UPLOADS_REL_DIR', 'storage/uploads');
    define('STORAGE_UPLOADS_ABS_DIR', $projectRootDir . '/storage/uploads');
    define('STORAGE_CLIPS_REL_DIR', 'storage/clips');
    define('STORAGE_CLIPS_ABS_DIR', $projectRootDir . '/storage/clips');
    define('STORAGE_MIN_FREE_DISK_BYTES', max(10485760, (int)clipia_env('STORAGE_MIN_FREE_DISK_BYTES', '104857600'))); // 100 MB livres mínimos
    define('STORAGE_MAX_FILE_SIZE_BYTES', max(1048576, (int)clipia_env('STORAGE_MAX_FILE_SIZE_BYTES', '104857600'))); // 100 MB
    define('STORAGE_MAX_USER_QUOTA_BYTES', max(10485760, (int)clipia_env('STORAGE_MAX_USER_QUOTA_BYTES', '524288000'))); // 500 MB por usuário
    define('STORAGE_MAX_TOTAL_QUOTA_BYTES', max(52428800, (int)clipia_env('STORAGE_MAX_TOTAL_QUOTA_BYTES', '5368709120'))); // 5 GB total
    define('STORAGE_MEDIA_RETENTION_HOURS', max(1, (int)clipia_env('STORAGE_MEDIA_RETENTION_HOURS', '72'))); // Expiração 72h

    // Limites padrão de configuração de cortes
    define('CLIP_DEFAULT_COUNT', 5);
    define('CLIP_MIN_COUNT', 1);
    define('CLIP_MAX_COUNT', 15);
    define('CLIP_DEFAULT_MIN_DURATION', 30);
    define('CLIP_DEFAULT_MAX_DURATION', 60);
    define('CLIP_DEFAULT_FORMAT', '9:16');

    // Modo exclusivo de proprietário único (sem cadastro público aberto)
    define('PUBLIC_REGISTRATION_ENABLED', false);
    define('ADMIN_OWNER_NAME', clipia_env('ADMIN_OWNER_NAME', 'Administrador ClipIA'));
    define('ADMIN_OWNER_EMAIL', clipia_env('ADMIN_OWNER_EMAIL', APP_ENV === 'production' ? '' : 'admin@clipia.local'));
    define('ADMIN_OWNER_PASSWORD_HASH', clipia_env('ADMIN_OWNER_PASSWORD_HASH', ''));
    define('ADMIN_OWNER_PASSWORD', clipia_env('ADMIN_OWNER_PASSWORD', APP_ENV === 'production' ? '' : 'AdminClipIA123!'));
}

// Configuração de Sessão Segura (inicia apenas em rotas de navegador, não na API machine-to-machine do worker)
$currentScriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
if (session_status() === PHP_SESSION_NONE && $currentScriptName !== 'worker-api.php') {
    $isHttps = clipia_is_https_request();
    $cookieSecure = $isHttps || (APP_ENV === 'production');

    if (!headers_sent()) {
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', '7200');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $cookieSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('CLIPIA_SESSID');
    }

    session_start();

    // Expiração de inatividade de sessão (2 horas)
    $sessionMaxIdle = 7200;
    if (!empty($_SESSION['auth_last_activity']) && (time() - (int)$_SESSION['auth_last_activity']) > $sessionMaxIdle) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            session_start();
        }
    }
    $_SESSION['auth_last_activity'] = time();
}
