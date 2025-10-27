<?php

require_once __DIR__ . '/db.php';

try {
    $pdo = createPdoFromEnv();
} catch (RuntimeException $runtimeException) {
    fwrite(STDERR, $runtimeException->getMessage() . "\n");
    exit(1);
} catch (PDOException $exception) {
    fwrite(STDERR, 'Database connection failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(255) PRIMARY KEY, executed_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW())');

$migrationsDir = __DIR__ . '/../migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDOUT, "No migrations directory found, skipping.\n");
    exit(0);
}

$files = glob($migrationsDir . '/*.sql');
sort($files);

foreach ($files as $file) {
    $version = basename($file);

    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
    $stmt->execute(['version' => $version]);
    if ($stmt->fetchColumn()) {
        continue;
    }

    $sql = file_get_contents($file);
    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\n|$)/', $sql)));

    $pdo->beginTransaction();
    try {
        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
        $insert = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $insert->execute(['version' => $version]);
        $pdo->commit();
        fwrite(STDOUT, "Applied migration {$version}\n");
    } catch (PDOException $exception) {
        $pdo->rollBack();
        fwrite(STDERR, 'Migration failed for '.$version.': '.$exception->getMessage()."\n");
        exit(1);
    }
}
