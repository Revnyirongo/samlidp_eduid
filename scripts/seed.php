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

$username = getenv('SEED_USER_USERNAME') ?: 'demo';
$email = getenv('SEED_USER_EMAIL') ?: 'demo@example.org';
$displayName = getenv('SEED_USER_DISPLAY_NAME') ?: 'Demo User';

$sql = 'INSERT INTO users (username, email, display_name) VALUES (:username, :email, :display_name)
        ON CONFLICT (username) DO UPDATE SET email = EXCLUDED.email, display_name = EXCLUDED.display_name';

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'username' => $username,
    'email' => $email,
    'display_name' => $displayName,
]);

echo "Seeded user '{$username}'.\n";
