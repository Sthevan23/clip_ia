<?php
/**
 * ClipIA - Arquivo de Exemplo de Credenciais (NÃO VERSIONAR CREDENCIAIS REAIS)
 *
 * Para usar em produção (Hostinger Business):
 * 1. Recomendado (fora da pasta pública): crie `/home/u123456789/clipia_secrets/env.production.php`
 *    (um nível acima de `public_html`) com permissão `0600` e cole este conteúdo.
 * 2. Alternativa local: copie para `config/env.local.php` (protegido por `.htaccess`).
 * 3. Preencha os dados reais do banco MySQL criado no hPanel da Hostinger.
 * 4. Defina um segredo forte em `WORKER_SHARED_SECRET` (mínimo 32 caracteres aleatórios)
 *    que também será usado em `CLIPIA_WORKER_SHARED_SECRET` no `.env` do serviço Python externo.
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

