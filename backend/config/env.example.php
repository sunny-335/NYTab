<?php
declare(strict_types=1);

return [
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '5432',
    'DB_NAME' => 'nytab',
    'DB_USER' => 'nytab',
    'DB_PASSWORD' => 'change_me',
    'JWT_SECRET' => 'please_generate_a_long_random_string',
    'JWT_ACCESS_TTL' => 3600,
    'JWT_REFRESH_TTL' => 604800,
    'APP_ENV' => 'development',
    'APP_URL' => 'http://localhost:5173',
    'CORS_ORIGINS' => 'http://localhost:5173',
    'UPLOAD_MAX_SIZE' => 5242880,
];
