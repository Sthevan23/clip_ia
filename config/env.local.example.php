<?php
declare(strict_types=1);

/**
 * Desenvolvimento local — copie para config/env.local.php (gitignored).
 * Não commite credenciais reais.
 */

return [
    'APP_ENV' => 'development',
    'APP_URL' => 'http://127.0.0.1:8099',

    // Opcional: criar admin a partir do env antes do login
    // 'ADMIN_OWNER_EMAIL' => 'voce@exemplo.local',
    // 'ADMIN_OWNER_PASSWORD' => 'SuaSenhaDeTeste123',

    // Somente com '1': primeiro login cria o admin se users estiver vazia (desligue depois)
    'CLIPIA_BOOTSTRAP_OWNER_ON_FIRST_LOGIN' => '1',

    'DB_DRIVER' => 'sqlite',
    'DB_SQLITE_PATH' => __DIR__ . '/../storage/clipia_local.sqlite',
];
