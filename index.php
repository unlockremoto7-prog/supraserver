<?php
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = trim($uri, '/');

if ($uri === '' || $uri === 'index') {
    require __DIR__ . '/dashboard.php';
    exit;
}

$target = __DIR__ . '/' . $uri . '.php';
if (is_file($target)) {
    require $target;
    exit;
}

if (is_file(__DIR__ . '/dashboard.php')) {
    require __DIR__ . '/dashboard.php';
} else {
    http_response_code(404);
    echo 'Página não encontrada.';
}
