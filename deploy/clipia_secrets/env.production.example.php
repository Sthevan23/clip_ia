<?php
/**
 * Copie para FORA do public_html:
 *   /home/u123456789/clipia_secrets/env.production.php
 * Permissão 0600. Nunca envie este arquivo preenchido para o Git ou FTP público.
 */
return [
    'APP_ENV'                          => 'production',
    'APP_URL'                          => 'https://seudominio.com.br',
    'APP_TIMEZONE'                     => 'America/Sao_Paulo',

    'ADMIN_OWNER_NAME'                 => 'Administrador ClipIA',
    'ADMIN_OWNER_EMAIL'                => 'admin@seudominio.com.br',
    'ADMIN_OWNER_PASSWORD_HASH'        => '', // php -r "echo password_hash('SuaSenha', PASSWORD_DEFAULT);"

    'DB_DRIVER'                        => 'mysql',
    'DB_HOST'                          => '127.0.0.1',
    'DB_PORT'                          => '3306',
    'DB_NAME'                          => 'u123456789_clipia',
    'DB_USER'                          => 'u123456789_admin',
    'DB_PASS'                          => 'SENHA_MYSQL_AQUI',

    'WORKER_SHARED_SECRET'             => 'SEGREDO_HMAC_64_CARACTERES_ALEATORIOS',
    'WORKER_REQUIRE_HTTPS'             => '1',
    'WORKER_JOB_STALE_TIMEOUT_SECONDS' => '1800',

    'CLIPIA_MAX_MONTHLY_COST_BRL'      => '100.00',
    'CLIPIA_MAX_COST_PER_JOB_BRL'      => '2.50',

    'STORAGE_MAX_FILE_SIZE_BYTES'      => '104857600',
    'STORAGE_MAX_USER_QUOTA_BYTES'     => '524288000',
    'STORAGE_MAX_TOTAL_QUOTA_BYTES'    => '5368709120',
    'STORAGE_MEDIA_RETENTION_HOURS'    => '72',
];
