<?php
declare(strict_types=1);

/**
 * ClipIA — Modelo para private/clipia.env.php (Hostinger, SEM credenciais reais)
 *
 * Copie para (criação MANUAL no servidor, nunca no Git):
 *   /home/u586160337/domains/sthevandev.com.br/private/clipia.env.php
 *
 * Com Auto Deploy em public_html/clipia/, config/app.php resolve:
 *   $domainRootDir/private/clipia.env.php
 *
 * Precedência por chave: variáveis do hPanel (getenv) → arquivos mesclados → defaults.
 * config/env.local.php NÃO é carregado quando APP_ENV=production.
 *
 * Obrigatório em produção (validação em config/clipia_env_loader.php):
 *   APP_ENV, DB_NAME, DB_USER, DB_PASS, WORKER_SHARED_SECRET (≥16 chars)
 *
 * Recomendado: APP_URL, WORKER_REQUIRE_HTTPS=1, ADMIN_OWNER_* (se users vazio)
 *
 * Opcional: STORAGE_*, CLIPIA_MAX_*_BRL, WORKER_JOB_STALE_TIMEOUT_SECONDS
 *
 * Gerar hash admin (no PC, não commitar a senha):
 *   php -r "echo password_hash('SuaSenhaForteAqui', PASSWORD_DEFAULT);"
 *
 * Gerar segredo HMAC (use o MESMO valor no worker_service/.env local):
 *   php -r "echo bin2hex(random_bytes(32));"
 */

return [
    // --- Obrigatório ---
    'APP_ENV'      => 'production',
    'APP_URL'      => 'https://clipia.sthevandev.com.br',
    'DB_DRIVER'    => 'mysql',
    'DB_HOST'      => '127.0.0.1',
    'DB_PORT'      => '3306',
    'DB_NAME'      => 'u123456789_nome_do_banco',
    'DB_USER'      => 'u123456789_usuario_mysql',
    'DB_PASS'      => 'SUBSTITUA_PELA_SENHA_MYSQL_DO_HPANEL',
    'WORKER_SHARED_SECRET' => 'SUBSTITUA_POR_SEGREDO_HMAC_GERADO_LOCALMENTE',

    // --- Recomendado ---
    'APP_TIMEZONE'                     => 'America/Sao_Paulo',
    'WORKER_REQUIRE_HTTPS'             => '1',
    'ADMIN_OWNER_NAME'                 => 'Administrador ClipIA',
    'ADMIN_OWNER_EMAIL'                => 'admin@exemplo.seudominio.com.br',
    'ADMIN_OWNER_PASSWORD_HASH'        => '',

    // --- Opcional (worker / orçamento futuro) ---
    'WORKER_JOB_STALE_TIMEOUT_SECONDS' => '1800',
    'CLIPIA_MAX_MONTHLY_COST_BRL'      => '100.00',
    'CLIPIA_MAX_COST_PER_JOB_BRL'      => '2.50',

    // --- Opcional (armazenamento) ---
    'STORAGE_MAX_FILE_SIZE_BYTES'      => '104857600',
    'STORAGE_MAX_USER_QUOTA_BYTES'     => '524288000',
    'STORAGE_MAX_TOTAL_QUOTA_BYTES'    => '5368709120',
    'STORAGE_MEDIA_RETENTION_HOURS'    => '72',
];
