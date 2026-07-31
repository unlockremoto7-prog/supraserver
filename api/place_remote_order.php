<?php

/**
 * Script de envio de pedido de Remote Service Dhru Fusion em modo de produção.
 * Recebe POST via AJAX, valida saldo, envia a ordem para a API e persiste o pedido.
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Usuário não autenticado. Faça login novamente.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$remoteInfo = trim((string) filter_input(INPUT_POST, 'remoteInfo', FILTER_SANITIZE_STRING));
$serviceId = trim((string) filter_input(INPUT_POST, 'serviceId', FILTER_SANITIZE_STRING));
$reference = trim((string) filter_input(INPUT_POST, 'reference', FILTER_SANITIZE_STRING));

function parse_decimal_value($value)
{
    if ($value === null || $value === false || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (float) $value;
    }

    $value = trim((string) $value);
    $value = str_replace(['$', 'R$', '€', ' '], '', $value);
    $value = str_replace(',', '.', $value);

    return is_numeric($value) ? (float) $value : null;
}

function safe_array_get(array $array, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (isset($array[$key])) {
            return $array[$key];
        }
    }
    return $default;
}

function flatten_service_items(array $item)
{
    $services = [];

    if (isset($item['SERVICEID']) || isset($item['ID']) || isset($item['serviceid']) || isset($item['id'])) {
        return [$item];
    }

    foreach ($item as $value) {
        if (is_array($value)) {
            $services = array_merge($services, flatten_service_items($value));
        }
    }

    return $services;
}

function find_remote_service_price(DhruFusion $api, string $serviceId): ?float
{
    $response = $api->action('remoteservicelist');
    if (!is_array($response) || !isset($response['SUCCESS'])) {
        return null;
    }

    $services = flatten_service_items($response['SUCCESS']);
    foreach ($services as $service) {
        $currentId = (string) safe_array_get($service, ['SERVICEID', 'serviceid', 'ID', 'id'], '');
        if ($currentId === $serviceId) {
            $priceValue = safe_array_get($service, ['CREDIT', 'credit', 'PRICE', 'price'], null);
            $price = parse_decimal_value($priceValue);
            if ($price !== null && $price > 0) {
                return $price;
            }
        }
    }

    return null;
}

function build_order_status(array $successData): string
{
    $statusRaw = safe_array_get($successData, ['STATUS', 'status', 'State', 'state'], 'Sucesso');
    $statusNormalized = strtolower((string) $statusRaw);
    if (strpos($statusNormalized, 'pend') !== false || strpos($statusNormalized, 'aguard') !== false) {
        return 'Pendente';
    }
    return 'Sucesso';
}

function get_api_order_identifier(array $successData): string
{
    $possibleKeys = ['ORDERID', 'orderid', 'ID', 'id', 'REFERENCEID', 'referenceid', 'TXNID', 'txn_id', 'transactionid'];
    foreach ($possibleKeys as $key) {
        if (!empty($successData[$key])) {
            return (string) $successData[$key];
        }
    }
    return '';
}

if ($serviceId === '') {
    echo json_encode(['error' => 'ID do serviço é obrigatório.']);
    exit;
}

if ($remoteInfo === '') {
    echo json_encode(['error' => 'O campo de informações remotas é obrigatório para Remote Service.']);
    exit;
}

$stmt = $pdo->prepare('SELECT saldo_cliente FROM usuarios WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    echo json_encode(['error' => 'Usuário inválido.']);
    exit;
}

$saldoAtual = (float) $user['saldo_cliente'];
$api = dhruFusionClient();
$servicePrice = find_remote_service_price($api, $serviceId);

if ($servicePrice === null) {
    echo json_encode(['error' => 'Não foi possível obter o preço do serviço Remote Service.']);
    exit;
}

if ($saldoAtual < $servicePrice) {
    echo json_encode(['error' => 'Saldo insuficiente para este serviço.']);
    exit;
}

$params = [
    'ID' => $serviceId,
    'ACCOUNT' => $remoteInfo,
    'USERNAME' => $remoteInfo,
    'REMOTEINFO' => $remoteInfo,
];
if ($reference !== '') {
    $params['REFERENCE'] = $reference;
}

$response = $api->action('placeremoteorder', $params);
if ($response === false) {
    echo json_encode(['error' => 'Falha de comunicação com a API Dhru Fusion.']);
    exit;
}

if (!is_array($response)) {
    echo json_encode(['error' => 'Resposta inválida da API Dhru Fusion.']);
    exit;
}

$apiError = null;
if (isset($response['error'])) {
    $apiError = $response['error'];
} elseif (isset($response['ERROR'])) {
    $apiError = $response['ERROR'];
}

if ($apiError !== null) {
    echo json_encode(['error' => $apiError, 'response' => $response]);
    exit;
}

if (!isset($response['SUCCESS'])) {
    echo json_encode(['error' => 'Resposta inesperada da API Dhru Fusion.', 'response' => $response]);
    exit;
}

$successData = current($response['SUCCESS']);
if (!is_array($successData)) {
    $successData = ['result' => $successData];
}

$orderStatus = build_order_status($successData);
$orderIdentifier = get_api_order_identifier($successData);

try {
    $pdo->beginTransaction();

    $novoSaldo = $saldoAtual - $servicePrice;
    $update = $pdo->prepare('UPDATE usuarios SET saldo_cliente = ? WHERE id = ?');
    $update->execute([$novoSaldo, $userId]);

    $insert = $pdo->prepare('INSERT INTO pedidos (usuario_id, imei, servico_id, referencia, status, resposta_api, data_pedido) VALUES (?, ?, ?, ?, ?, ?, NOW()) RETURNING id');
    $insert->execute([
        $userId,
        $remoteInfo,
        $serviceId,
        $reference,
        $orderStatus,
        json_encode($response, JSON_UNESCAPED_UNICODE),
    ]);

    $pedidoId = $insert->fetchColumn();
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'Erro ao salvar o pedido no sistema: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'order_id' => $pedidoId,
    'api_order_id' => $orderIdentifier,
    'status' => $orderStatus,
    'saldo_cliente' => number_format($novoSaldo, 2, '.', ''),
    'price' => number_format($servicePrice, 2, '.', ''),
    'response' => $response,
]);
