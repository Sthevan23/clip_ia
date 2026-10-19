<?php
declare(strict_types=1);

/**
 * Carregamento de variáveis de ambiente ClipIA (arquivos PHP return array).
 * Precedência por chave em clipia_env(): getenv → $_ENV → $localEnv → default (em app.php).
 *
 * Arquivos fora de public_html usam $domainRootDir (pai de public_html).
 * config/env.local.php só é mesclado fora de APP_ENV=production (evita sobrescrever produção).
 */

/**
 * @return array{
 *   localEnv: array<string, string|int|float|bool>,
 *   loadedBasenames: list<string>,
 *   privateEnvLoaded: bool,
 *   projectRootDir: string,
 *   domainRootDir: string
 * }
 */
function clipia_bootstrap_environment(): array
{
    $projectRootDir = dirname(__DIR__);
    $parentOfPublicRoot = dirname($projectRootDir);
    $domainRootDir = dirname($parentOfPublicRoot);

    $candidateEnvFiles = [];
    $customEnvPath = getenv('CLIPIA_ENV_FILE') ?: ($_ENV['CLIPIA_ENV_FILE'] ?? '');
    if (is_string($customEnvPath) && $customEnvPath !== '') {
        $candidateEnvFiles[] = $customEnvPath;
    }

    $candidateEnvFiles[] = $domainRootDir . '/clipia_secrets/env.production.php';
    $candidateEnvFiles[] = $domainRootDir . '/clipia_env.php';
    $candidateEnvFiles[] = $domainRootDir . '/private/clipia.env.php';

    $localEnv = [];
    $loadedBasenames = [];
    $privateEnvLoaded = false;
    $privatePath = $domainRootDir . '/private/clipia.env.php';

    foreach ($candidateEnvFiles as $envFileCandidate) {
        if (!is_string($envFileCandidate) || $envFileCandidate === '') {
            continue;
        }
        if (!is_file($envFileCandidate) || !is_readable($envFileCandidate)) {
            continue;
        }
        $loaded = include $envFileCandidate;
        if (!is_array($loaded)) {
            continue;
        }
        foreach ($loaded as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $localEnv[$k] = $v === null ? '' : (string)$v;
            }
        }
        $loadedBasenames[] = basename($envFileCandidate);
        if (realpath($envFileCandidate) === realpath($privatePath)) {
            $privateEnvLoaded = true;
        }
    }

    $prelimAppEnv = getenv('APP_ENV');
    if ($prelimAppEnv === false || $prelimAppEnv === '') {
        $prelimAppEnv = (string)($_ENV['APP_ENV'] ?? ($localEnv['APP_ENV'] ?? ''));
    }
    $hostingLayout = str_contains(str_replace('\\', '/', $projectRootDir), '/public_html/');

    if ($prelimAppEnv !== 'production') {
        $envLocalPath = __DIR__ . '/env.local.php';
        if (is_file($envLocalPath) && is_readable($envLocalPath)) {
            $loadedLocal = include $envLocalPath;
            if (is_array($loadedLocal)) {
                foreach ($loadedLocal as $k => $v) {
                    if (!is_string($k) || $k === '') {
                        continue;
                    }
                    if (is_scalar($v) || $v === null) {
                        $localEnv[$k] = $v === null ? '' : (string)$v;
                    }
                }
                $loadedBasenames[] = 'env.local.php';
            }
        }
    }

    return [
        'localEnv'          => $localEnv,
        'loadedBasenames'   => $loadedBasenames,
        'privateEnvLoaded'  => $privateEnvLoaded,
        'projectRootDir'    => $projectRootDir,
        'domainRootDir'     => $domainRootDir,
        'hostingLayout'     => $hostingLayout,
    ];
}

function clipia_hosting_layout_detected(): bool
{
    global $clipiaEnvBootstrap;
    return !empty($clipiaEnvBootstrap['hostingLayout']);
}

function clipia_private_env_loaded(): bool
{
    global $clipiaEnvBootstrap;
    return !empty($clipiaEnvBootstrap['privateEnvLoaded']);
}

/**
 * Segredos de exemplo/desenvolvimento que não podem ser usados em produção (fail-closed).
 * Não registra nem expõe o valor comparado.
 */
function clipia_is_disallowed_worker_secret_for_production(string $secret): bool
{
    $normalized = trim($secret);
    if ($normalized === '') {
        return false;
    }

    static $exactBlocklist = null;
    if ($exactBlocklist === null) {
        $exactBlocklist = [
            'clipia-dev-hmac-secret-key-change-in-production-2026',
            'SUBSTITUA_POR_SEGREDO_HMAC_GERADO_LOCALMENTE',
            'SEGREDO_HMAC_64_CARACTERES_ALEATORIOS',
            'ALTERE_PARA_UM_SEGREDO_HMAC_DE_PELO_MENOS_32_BYTES',
        ];
    }

    if (in_array($normalized, $exactBlocklist, true)) {
        return true;
    }

    if (preg_match('/^(SUBSTITUA|ALTERE)_/i', $normalized) === 1) {
        return true;
    }

    return false;
}

/**
 * Problemas de configuração (mensagens genéricas, sem segredos).
 *
 * @return list<string>
 */
function clipia_collect_production_config_issues(): array
{
    if (!defined('APP_ENV') || APP_ENV !== 'production') {
        return [];
    }

    $issues = [];

    if (clipia_hosting_layout_detected() && !clipia_private_env_loaded() && clipia_env('CLIPIA_ENV_FILE', '') === '') {
        $issues[] = 'Arquivo privado domains/.../private/clipia.env.php não foi carregado (ou use variáveis no hPanel / CLIPIA_ENV_FILE).';
    }

    if (clipia_env('DB_NAME', '') === '' || clipia_env('DB_USER', '') === '') {
        $issues[] = 'MySQL: DB_NAME e DB_USER são obrigatórios em produção.';
    }

    if (strlen((string)WORKER_SHARED_SECRET) < 16) {
        $issues[] = 'WORKER_SHARED_SECRET ausente ou curto (mínimo 16 caracteres).';
    } elseif (clipia_is_disallowed_worker_secret_for_production((string)WORKER_SHARED_SECRET)) {
        $issues[] = 'WORKER_SHARED_SECRET usa valor de exemplo ou de desenvolvimento; gere um segredo exclusivo para produção.';
    }

    if (clipia_env('APP_URL', '') === '') {
        $issues[] = 'APP_URL HTTPS recomendado para links e cookies consistentes.';
    }

    return $issues;
}

function clipia_abort_if_production_misconfigured(): void
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'worker-api.php') {
        return;
    }

    $issues = clipia_collect_production_config_issues();
    if ($issues === []) {
        return;
    }

    error_log('[ClipIA Config] Produção incompleta: ' . implode(' | ', $issues));

    if (!clipia_hosting_layout_detected()) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>ClipIA — Configuração</title></head>';
    echo '<body style="font-family:system-ui,sans-serif;padding:2rem;max-width:640px;margin:auto;">';
    echo '<h1>Configuração do servidor incompleta</h1>';
    echo '<p>O ClipIA detectou hospedagem em <code>public_html</code>, mas faltam credenciais de produção.</p>';
    echo '<p>Crie <code>private/clipia.env.php</code> fora de <code>public_html</code> (veja <code>deploy/HOSTINGER.md</code>) ';
    echo 'ou defina variáveis no hPanel. Detalhes foram registrados em <code>storage/logs/php_errors.log</code>.</p>';
    echo '</body></html>';
    exit;
}
