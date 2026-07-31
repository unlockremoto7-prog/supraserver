<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

$host = getenv('DB_HOST');
$port = getenv('DB_PORT') ?: '5432';
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');
$sslmode = getenv('DB_SSLMODE') ?: 'require';
$databaseUrl = getenv('DATABASE_URL') ?: getenv('DB_URL');

if (!$host || !$db || !$user || !$pass) {
    if ($databaseUrl) {
        $parsed = parse_url($databaseUrl);
        $host = $parsed['host'] ?? null;
        $port = $parsed['port'] ?? '5432';
        $db = ltrim($parsed['path'] ?? '', '/');
        $user = urldecode($parsed['user'] ?? '');
        $pass = urldecode($parsed['pass'] ?? '');
    }
}

if (!$host || !$db || !$user || !$pass) {
    http_response_code(500);
    echo 'Erro de configuração do banco: as variáveis DB_HOST, DB_NAME, DB_USER e DB_PASSWORD são obrigatórias, ou então a DATABASE_URL/DB_URL do Supabase.';
    exit;
}

if (!extension_loaded('pdo_pgsql')) {
    http_response_code(500);
    echo 'Erro de ambiente: a extensão PDO PostgreSQL não está disponível.';
    exit;
}

$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=$sslmode";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log('Falha na conexao com o banco de dados: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro ao conectar ao banco de dados. Verifique as variáveis DB_* no painel da Render ou a DATABASE_URL/DB_URL do Supabase.<br>';
    echo 'Detalhes: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}
