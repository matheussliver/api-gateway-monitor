<?php

declare(strict_types=1);

$testingDatabase = getenv('DB_TEST_DATABASE') ?: 'gateway_logs_test';

$testingDatabaseEnvironment = [
    'DB_TEST_DATABASE' => $testingDatabase,
    'DB_CONNECTION' => getenv('DB_CONNECTION') ?: 'mysql',
    'DB_HOST' => getenv('DB_HOST') ?: 'mysql',
    'DB_PORT' => getenv('DB_PORT') ?: '3306',
    'DB_DATABASE' => $testingDatabase,
    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'gateway_user',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'gateway_password',
    'DB_URL' => '',
];

foreach ($testingDatabaseEnvironment as $name => $value) {
    putenv("$name=$value");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
