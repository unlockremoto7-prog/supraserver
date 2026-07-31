<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

$host = getenv('DB_HOST');
$port = getenv('DB_PORT') ?: '5432';
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');

if (!$host || !$db || !$user || !$pass) {
    http_response_code(500);
    echo 'Erro de configuração do banco: as variáveis DB_HOST, DB_NAME, DB_USER e DB_PASSWORD são obrigatórias.';
    exit;
}

if (!extension_loaded('pdo_pgsql')) {
    http_response_code(500);
    echo 'Erro de ambiente: a extensão PDO PostgreSQL não está disponível.';
    exit;
}

$dsn = "pgsql:host=$host;port=$port;dbname=$db";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log('Falha na conexao com o banco de dados: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro ao conectar ao banco de dados. Verifique as variáveis DB_* no painel da Render e o acesso do Supabase.';
    exit;
}
