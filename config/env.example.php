<?php
/**
 * ClipIA - Arquivo de Exemplo de Credenciais (NÃO VERSIONAR CREDENCIAIS REAIS)
 *
 * Para produção na Hostinger, prefira o modelo dedicado:
 *   config/env.production.example.php
 * (copie o conteúdo para ~/clipia_secrets/env.production.php fora de public_html).
 *
 * Desenvolvimento local: copie para config/env.local.php (gitignored).
 */

return [
    'APP_ENV'                          => 'production',
    'APP_URL'                          => 'https://seudominio.com.br',
    'APP_TIMEZONE'                     => 'America/Sao_Paulo',

    // Conta Administrativa do Proprietário (Uso Individual Exclusivo — Sem Cadastro Público)
    'ADMIN_OWNER_NAME'                 => 'Administrador ClipIA',
    'ADMIN_OWNER_EMAIL'                => 'admin@seudominio.com.br',
    'ADMIN_OWNER_PASSWORD_HASH'        => '', // Gere com: php -r "echo password_hash('SuaSenhaForte123!', PASSWORD_DEFAULT);"

    // Banco de Dados MySQL (Hostinger Business)
    'DB_DRIVER'                        => 'mysql',
    'DB_HOST'                          => '127.0.0.1',
    'DB_PORT'                          => '3306',
    'DB_NAME'                          => 'u123456789_clipia',
    'DB_USER'                          => 'u123456789_admin',
    'DB_PASS'                          => 'ALTERE_PARA_SUA_SENHA_FORTE_AQUI',

    // Segurança entre Backend PHP e Worker Python Externo (HMAC + HTTPS)
    'WORKER_SHARED_SECRET'             => 'ALTERE_PARA_UM_SEGREDO_HMAC_DE_PELO_MENOS_32_BYTES',
    'WORKER_REQUIRE_HTTPS'             => '1',
    'WORKER_JOB_STALE_TIMEOUT_SECONDS' => '1800',

    // Orçamento Compartilhado no Banco de Dados (Teto Mensal em R$)
    'CLIPIA_MAX_MONTHLY_COST_BRL'      => '100.00',
    'CLIPIA_MAX_COST_PER_JOB_BRL'      => '2.50',

    // Limites de Espaço e Expiração de Mídia (storage/uploads)
    'STORAGE_MAX_FILE_SIZE_BYTES'      => '104857600',  // 100 MB por vídeo
    'STORAGE_MAX_USER_QUOTA_BYTES'     => '524288000',  // 500 MB por usuário
    'STORAGE_MAX_TOTAL_QUOTA_BYTES'    => '5368709120', // 5 GB total
    'STORAGE_MEDIA_RETENTION_HOURS'    => '72',         // Expiração após 72 horas
];

