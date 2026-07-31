<?php

/**
 * Configurações centrais para o painel Dhru Fusion
 */

define('REQUESTFORMAT', 'JSON');
define('DHRUFUSION_URL', 'https://gsm-imei.com');
define('USERNAME', 'samuel.fiel2012');
define('API_ACCESS_KEY', '53J-WJQ-UFK-MUL-XA1-K5M-8AF-B4W');

require_once __DIR__ . '/dhrufusionapi.class.php';

function dhruFusionClient()
{
    $api = new DhruFusion();
    $api->debug = false;
    return $api;
}
