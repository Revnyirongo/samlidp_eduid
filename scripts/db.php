<?php

function createPdoFromEnv()
{
    $databaseUrl = getenv('DATABASE_URL');
    if (!$databaseUrl) {
        throw new RuntimeException('DATABASE_URL is not set.');
    }

    $components = parse_url($databaseUrl);
    if ($components === false) {
        throw new RuntimeException('Unable to parse DATABASE_URL.');
    }

    $host = $components['host'] ?? '127.0.0.1';
    $port = $components['port'] ?? 5432;
    $dbname = ltrim($components['path'] ?? '', '/');
    $user = $components['user'] ?? getenv('POSTGRES_USER') ?? 'postgres';
    $pass = $components['pass'] ?? getenv('POSTGRES_PASSWORD') ?? '';

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbname);

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}
