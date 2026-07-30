<?php
$host = getenv('SUPABASE_DB_HOST') ?: '127.0.0.1';
$port = getenv('SUPABASE_DB_PORT') ?: '5432';
$db   = getenv('SUPABASE_DB_NAME') ?: 'postgres';
$user = getenv('SUPABASE_DB_USER') ?: '';
$pass = getenv('SUPABASE_DB_PASSWORD') ?: '';
$sslmode = getenv('SUPABASE_DB_SSLMODE') ?: 'require';

$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=$sslmode";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log('[DB] ' . $e->getMessage());
    http_response_code(500);
    exit;
}
