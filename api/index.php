<?php
// Ponto de entrada (Front Controller) para o Vercel
$request = $_SERVER['REQUEST_URI'];
$path = parse_url($request, PHP_URL_PATH);

// Se a raiz for acessada, busca o index.php da raiz
if ($path === '/' || $path === '/index.php') {
    if (file_exists(__DIR__ . '/../index.php')) {
        include __DIR__ . '/../index.php';
        exit;
    }
}

// Remove a barra inicial para verificar o arquivo
$file = ltrim($path, '/');

// Roteia para o arquivo PHP correspondente na raiz se ele existir
if (file_exists(__DIR__ . '/../' . $file) && substr($file, -4) === '.php') {
    include __DIR__ . '/../' . $file;
    exit;
}

// Se o arquivo não for encontrado, exibe erro 404
http_response_code(404);
echo "Página não encontrada.";
