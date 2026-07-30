<?php

/**
 * Script de consulta de conta Dhru Fusion.
 * Pode ser incluído por outras páginas ou acessado diretamente.
 */

require_once __DIR__ . '/config.php';

function get_account_info()
{
    $api = dhruFusionClient();
    return $api->action('accountinfo');
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json; charset=utf-8');
    $response = get_account_info();
    echo json_encode($response);
}
