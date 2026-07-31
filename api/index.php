<?php
$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestedPath = trim($requestedPath, '/');

if ($requestedPath === '' || $requestedPath === 'api') {
    $file = 'dashboard.php';
} else {
    $file = $requestedPath . '.php';
}

$baseDir = __DIR__;
$target = $baseDir . DIRECTORY_SEPARATOR . $file;

if (!is_file($target)) {
    $fallback = $baseDir . DIRECTORY_SEPARATOR . 'dashboard.php';
    if (is_file($fallback)) {
        require $fallback;
    } else {
        http_response_code(404);
        echo 'Página não encontrada.';
    }
    exit;
}

require $target;
