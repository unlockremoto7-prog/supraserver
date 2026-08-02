<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

global $cotacao_dolar, $margem_lucro;
$cotacao_dolar = 5.50;
$margem_lucro = 1.30;

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/get_account_info.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT usuario, saldo_cliente FROM usuarios WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$lastLoginTime = $_SESSION['last_login_time'] ?? date('d/m/Y H:i:s');
$lastLoginIp = $_SESSION['last_login_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'IP desconhecido');
$userEmail = $_SESSION['user_email'] ?? 'não definido';

$currentTab = $_GET['tab'] ?? 'imei';

$cotacao_dolar = 5.50;
$margem_lucro = 1.30;

$api = dhruFusionClient();
$imei_raw   = $api->action('imeiservicelist');
$server_raw = $api->action('serverloginservicelist');
$remote_raw = $api->action('remoteservicelist');

$imei_groups   = normalize_dhru_response($imei_raw);
$server_groups = normalize_dhru_response($server_raw);
$remote_groups = normalize_dhru_response($remote_raw);

$imei_groups   = is_array($imei_groups) ? $imei_groups : [];
$server_groups = is_array($server_groups) ? $server_groups : [];
$remote_groups = is_array($remote_groups) ? $remote_groups : [];

mark_groups_origin($imei_groups, 'imei');
mark_groups_origin($server_groups, 'server');
mark_groups_origin($remote_groups, 'remote');

$allGroups = array_values(array_merge($imei_groups, $server_groups, $remote_groups));

if (isset($_GET['debug_api']) && $_GET['debug_api'] === '1') {
    echo '<h2 style="color:#fff;">DEBUG API RAW RESPONSES</h2>';
    echo '<pre style="color:#ddd; background:#071018; padding:1rem; border-radius:6px;">';
    echo "-- IMEISERVICELIST --\n" . htmlspecialchars(var_export($imei_raw, true), ENT_QUOTES, 'UTF-8') . "\n\n";
    echo "-- SERVERLOGINSERVICELIST --\n" . htmlspecialchars(var_export($server_raw, true), ENT_QUOTES, 'UTF-8') . "\n\n";
    echo "-- REMOTESERVICELIST --\n" . htmlspecialchars(var_export($remote_raw, true), ENT_QUOTES, 'UTF-8') . "\n";
    echo '</pre>';

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logDir . '/api_debug.log', date('c') . "\n-- IMEI --\n" . var_export($imei_raw, true) . "\n-- SERVER --\n" . var_export($server_raw, true) . "\n-- REMOTE --\n" . var_export($remote_raw, true) . "\n\n", FILE_APPEND);
}

function mark_groups_origin(array &$groups, string $origin): void
{
    foreach ($groups as &$group) {
        if (!is_array($group)) {
            continue;
        }

        $group['ORIGEM_ABA'] = $origin;

        foreach ($group as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (
                isset($value['SERVICEID']) || isset($value['serviceid']) || isset($value['ID']) || isset($value['id'])
                || isset($value['SERVICENAME']) || isset($value['servicename']) || isset($value['SERVICE_NAME']) || isset($value['name'])
            ) {
                $group[$key]['ORIGEM_ABA'] = $origin;
                continue;
            }

            if (array_is_list($value)) {
                mark_groups_origin($value, $origin);
            }
        }
    }
    unset($group);
}

function normalize_dhru_response($response)
{
    if ($response === false || !is_array($response)) {
        return [];
    }

    $looksLikeService = static function ($item): bool {
        if (!is_array($item)) {
            return false;
        }

        return isset($item['SERVICEID']) || isset($item['serviceid']) || isset($item['ID']) || isset($item['id'])
            || isset($item['SERVICENAME']) || isset($item['servicename']) || isset($item['SERVICE_NAME']) || isset($item['name']);
    };

    $extractList = function ($node) use (&$extractList, $looksLikeService) {
        if (!is_array($node)) {
            return [];
        }

        if (isset($node['LIST']) && is_array($node['LIST'])) {
            return $node['LIST'];
        }
        if (isset($node['SERVICES']) && is_array($node['SERVICES'])) {
            return $node['SERVICES'];
        }
        if (isset($node['SERVICE']) && is_array($node['SERVICE'])) {
            return $node['SERVICE'];
        }
        if (isset($node['SUCCESS']) && is_array($node['SUCCESS'])) {
            $nested = $extractList($node['SUCCESS']);
            if (!empty($nested)) {
                return $nested;
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                if ($looksLikeService($value)) {
                    return [$value];
                }

                $nested = $extractList($value);
                if (!empty($nested)) {
                    return $nested;
                }
            }
        }

        return [];
    };

    $serviceGroups = $extractList($response);
    if (empty($serviceGroups)) {
        $serviceGroups = $response;
    }

    if (!empty($serviceGroups) && is_array($serviceGroups)) {
        $firstItem = reset($serviceGroups);
        if (is_array($firstItem) && $looksLikeService($firstItem)) {
            $serviceGroups = [['SERVICES' => $serviceGroups]];
        }
    }

    return $serviceGroups;
}

$imeiServices = $imei_groups;
$serverServices = $server_groups;
$remoteServices = $remote_groups;

function build_service_map(array $groups, string $type)
{
    $map = [];
    foreach ($groups as $groupData) {
        if (!is_array($groupData)) {
            continue;
        }

        $services = $groupData['SERVICES'] ?? $groupData['SERVICE'] ?? [];
        if (!is_array($services)) {
            continue;
        }

        foreach ($services as $serviceItem) {
            if (!is_array($serviceItem)) {
                continue;
            }

            $serviceId = $serviceItem['SERVICEID'] ?? $serviceItem['serviceid'] ?? $serviceItem['ID'] ?? $serviceItem['id'] ?? '';
            if ($serviceId !== '') {
                $map[$serviceId] = $type;
            }
        }
    }
    return $map;
}

function filter_groups_by_origin(array $groups, string $targetTab): array
{
    return array_values(array_filter($groups, function ($groupData) use ($targetTab) {
        return is_array($groupData) && identify_service_target_tab($groupData) === $targetTab;
    }));
}

function identify_service_target_tab($payload): string
{
    if (is_array($payload)) {
        if (!empty($payload['ORIGEM_ABA']) && in_array($payload['ORIGEM_ABA'], ['imei', 'server', 'remote'], true)) {
            return $payload['ORIGEM_ABA'];
        }

        if (!empty($payload['TYPE']) && in_array($payload['TYPE'], ['imei', 'server', 'remote'], true)) {
            return $payload['TYPE'];
        }

        if (!empty($payload['TAB']) && in_array($payload['TAB'], ['imei', 'server', 'remote'], true)) {
            return $payload['TAB'];
        }

        foreach ($payload as $item) {
            if (is_array($item) && !empty($item['ORIGEM_ABA']) && in_array($item['ORIGEM_ABA'], ['imei', 'server', 'remote'], true)) {
                return $item['ORIGEM_ABA'];
            }
        }

        $segments = [];
        foreach (['GROUP', 'NAME', 'SERVICENAME', 'SERVICE_NAME', 'CATEGORY', 'TITLE'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                $segments[] = $payload[$key];
            }
        }

        if (!empty($payload['SERVICES']) && is_array($payload['SERVICES'])) {
            foreach ($payload['SERVICES'] as $serviceItem) {
                if (is_array($serviceItem)) {
                    $segments[] = $serviceItem['SERVICENAME'] ?? $serviceItem['servicename'] ?? $serviceItem['SERVICE_NAME'] ?? $serviceItem['name'] ?? '';
                }
            }
        }

        $text = strtolower(implode(' ', array_filter($segments, fn($value) => is_string($value) && trim($value) !== '')));

        if (preg_match('/\b(rent|rental|alug|aluguel|remote|remoto|teamviewer|anydesk|hours|minutes|hora|minuto|via usb)\b/', $text)) {
            return 'remote';
        }

        if (preg_match('/\b(license|licenca|credit|credits|credito|creditos|activation|ativacao|tool|dongle|box|global|repair|renew|renovacao|account|usuario|user|login|year|month|days|server)\b/', $text)) {
            return 'server';
        }
    }

    return 'imei';
}

function format_brl($amount)
{
    return 'R$ ' . number_format((float) $amount, 2, ',', '.');
}

function render_service_cards(array $serviceGroups, string $section, string $serviceType)
{
    global $cotacao_dolar, $margem_lucro;

    if (empty($serviceGroups)) {
        echo '<div class="empty-state">Nenhum serviço disponível em ' . htmlspecialchars($section, ENT_QUOTES, 'UTF-8') . '.</div>';
        return;
    }

    $hasServices = false;

    foreach ($serviceGroups as $groupName => $groupData) {
        if (!is_array($groupData)) {
            continue;
        }

        $services = $groupData['SERVICES'] ?? $groupData['SERVICE'] ?? $groupData['services'] ?? $groupData['service'] ?? [];
        if (empty($services) && is_array($groupData) && (isset($groupData['SERVICEID']) || isset($groupData['serviceid']) || isset($groupData['ID']) || isset($groupData['id']))) {
            $services = [$groupData];
        }

        if (!is_array($services)) {
            continue;
        }

        $groupLabel = is_string($groupName) ? $groupName : 'Categoria';
        $matchedServices = [];
        foreach ($services as $serviceItem) {
            if (!is_array($serviceItem)) {
                continue;
            }

            $matchedServices[] = $serviceItem;
        }

        if (!empty($matchedServices)) {
            $hasServices = true;
            echo '<div class="service-group">';
            echo '<div class="service-group-title">' . htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div class="service-grid">';

            foreach ($matchedServices as $serviceItem) {
                $serviceId = $serviceItem['SERVICEID'] ?? $serviceItem['serviceid'] ?? $serviceItem['ID'] ?? $serviceItem['id'] ?? '';
                $serviceName = $serviceItem['SERVICENAME'] ?? $serviceItem['servicename'] ?? $serviceItem['SERVICE_NAME'] ?? $serviceItem['name'] ?? 'Serviço';
                $credit = $serviceItem['CREDIT'] ?? $serviceItem['credit'] ?? $serviceItem['PRICE'] ?? $serviceItem['price'] ?? '0.00';
                $tat = $serviceItem['TAT'] ?? $serviceItem['tat'] ?? $serviceItem['TIME'] ?? $serviceItem['time'] ?? '1-10 Minutes';
                $short = $serviceItem['SHORTNAME'] ?? $serviceItem['shortname'] ?? $serviceItem['GROUP'] ?? '';
                $logo = $serviceItem['LOGO'] ?? $serviceItem['logo'] ?? '';

                if ($serviceId === '' || $serviceName === '') {
                    continue;
                }

                $numericPrice = is_numeric(str_replace(',', '.', (string)$credit)) ? (float) str_replace(',', '.', (string)$credit) : null;
                $priceInReal = $numericPrice !== null ? (($numericPrice * $cotacao_dolar) * $margem_lucro) : 0.00;
                $priceLabel = $numericPrice !== null ? format_brl($priceInReal) : htmlspecialchars($credit, ENT_QUOTES, 'UTF-8');
                $timeLabel = htmlspecialchars($tat, ENT_QUOTES, 'UTF-8');
                $serviceBadge = $short ? htmlspecialchars($short, ENT_QUOTES, 'UTF-8') : 'IMEI';

                $dataAttrs = ' data-service-id="' . htmlspecialchars($serviceId, ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-service-type="' . htmlspecialchars($serviceType, ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-service-name="' . htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-service-credit="' . htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') . '"';
                if ($numericPrice !== null) {
                    $dataAttrs .= ' data-service-price="' . htmlspecialchars(number_format($priceInReal, 2, '.', ''), ENT_QUOTES, 'UTF-8') . '"';
                }
                $minQty = $serviceItem['MIN'] ?? $serviceItem['MINQ'] ?? $serviceItem['MINIMUM'] ?? $serviceItem['minimum'] ?? $serviceItem['MINQTY'] ?? $serviceItem['minqty'] ?? null;
                if ($minQty !== null && is_numeric($minQty)) {
                    $dataAttrs .= ' data-min-qty="' . htmlspecialchars((int)$minQty, ENT_QUOTES, 'UTF-8') . '"';
                }

                echo '<button type="button" class="service-card"' . $dataAttrs . '>';
                echo '<div class="service-card-top"><span class="service-chip">' . $serviceBadge . '</span>';
                if ($logo) {
                    echo '<span class="service-logo">' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '</span>';
                }
                echo '</div>';
                echo '<div class="service-card-body">';
                echo '<h3>' . htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') . '</h3>';
                echo '<div class="service-meta">';
                echo '<span class="service-price">' . $priceLabel . '</span>';
                echo '<span class="service-time">' . $timeLabel . '</span>';
                echo '</div>';
                echo '</div>';
                echo '</button>';
            }

            echo '</div>';
            echo '</div>';
        }
    }

    if (!$hasServices) {
        echo '<div class="empty-state">Nenhum serviço mapeado para esta aba.</div>';
    }
}

$serviceTypeMap = array_merge(
    build_service_map($imeiServices, 'imei'),
    build_service_map($serverServices, 'server'),
    build_service_map($remoteServices, 'remote')
);

$activeTab = $_GET['tab'] ?? 'imei';
if (!in_array($activeTab, ['imei', 'server', 'remote'], true)) {
    $activeTab = 'imei';
}

$activeHistory = $_GET['history'] ?? 'all';
if (!in_array($activeHistory, ['all', 'imei', 'server', 'remote'], true)) {
    $activeHistory = 'all';
}

$accountInfo = get_account_info();
$balance = '0.00';
$currency = 'USD';

if (is_array($accountInfo)) {
    if (isset($accountInfo['SUCCESS'][0]['AccountInfo']['creditraw'])) {
        $balance = $accountInfo['SUCCESS'][0]['AccountInfo']['creditraw'];
    } elseif (isset($accountInfo['SUCCESS'][0]['AccountInfo']['credit'])) {
        $balance = $accountInfo['SUCCESS'][0]['AccountInfo']['credit'];
    } elseif (isset($accountInfo['BALANCE'])) {
        $balance = $accountInfo['BALANCE'];
    }

    if (isset($accountInfo['SUCCESS'][0]['AccountInfo']['currency'])) {
        $currency = $accountInfo['SUCCESS'][0]['AccountInfo']['currency'];
    }
}

$balanceLabel = '$' . htmlspecialchars(number_format((float) str_replace(',', '.', $balance), 2, '.', ''), ENT_QUOTES, 'UTF-8');

$stmt = $pdo->prepare('SELECT id, imei, servico_id, referencia, status, data_pedido, resposta_api FROM pedidos WHERE usuario_id = ? ORDER BY data_pedido DESC LIMIT 50');
$stmt->execute([$userId]);
$orders = $stmt->fetchAll();

function resolve_order_type($serviceId, array $map)
{
    return $map[$serviceId] ?? 'imei';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Supraserver | Painel Dark Premium</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            color-scheme: dark;
            --bg: #060709;
            --surface: #0f1215;
            --surface-2: #14181d;
            --surface-3: #1b2026;
            --text: #e8edf3;
            --muted: #98a1b2;
            --border: rgba(255,255,255,.08);
            --accent: #22c55e;
            --accent-2: #facc15;
            --danger: #ef4444;
        }
        body.theme-light {
            color-scheme: light;
            --bg: #f5f7fb;
            --surface: #ffffff;
            --surface-2: #f0f3f8;
            --surface-3: #e9edf6;
            --text: #111827;
            --muted: #6b7280;
            --border: rgba(17,24,39,.08);
            --accent: #16a34a;
            --accent-2: #eab308;
            --danger: #dc2626;
        }
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: radial-gradient(circle at top, rgba(34,197,94,.14), transparent 28%), var(--bg);
            color: var(--text);
            min-height: 100vh;
        }
        .top-support {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 38px;
            background: #020405;
            border-bottom: 1px solid rgba(255,255,255,.08);
            z-index: 1050;
        }
        .top-support .bar-inner {
            max-width: 1200px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            font-size: .85rem;
        }
        .top-support a {
            color: var(--muted);
            text-decoration: none;
        }
        .top-support a:hover {
            color: var(--text);
        }
        .main-nav {
            position: sticky;
            top: 38px;
            z-index: 1040;
            background: rgba(7,11,14,.96);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255,255,255,.08);
        }
        .main-nav .navbar-brand {
            display: flex;
            align-items: center;
            gap: .6rem;
            font-weight: 700;
            letter-spacing: .03em;
            color: #fff;
        }
        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #22c55e, #14b8a6);
            display: grid;
            place-items: center;
            color: #fff;
            font-weight: 700;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: .6rem;
            padding: .55rem .85rem;
            border-radius: 999px;
            background: rgba(34,197,94,.14);
            color: #d9f99d;
            border: 1px solid rgba(34,197,94,.18);
            font-size: .92rem;
        }
        .status-pill strong {
            color: #fff;
        }
        .page-container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 132px 1rem 3rem;
        }
        .hero-panel,
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 28px 80px rgba(0,0,0,.18);
        }
        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .section-heading h2 {
            margin: 0;
            font-size: 1.25rem;
        }
        .badge-surface {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .55rem .8rem;
            border-radius: 999px;
            background: var(--surface-2);
            color: var(--muted);
            font-size: .9rem;
        }
        .tabs-row {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            margin-bottom: 1.5rem;
        }
        .tabs-row button,
        .history-tabs button {
            border: none;
            background: var(--surface-2);
            color: var(--text);
            padding: .8rem 1rem;
            border-radius: 14px;
            cursor: pointer;
            font-weight: 600;
            transition: transform .2s ease, background .2s ease;
        }
        .tabs-row button.active,
        .history-tabs button.active {
            background: var(--accent);
            color: #020617;
            transform: translateY(-1px);
        }
        .service-section,
        .history-panel {
            padding: 1.5rem;
        }
        .service-group {
            margin-bottom: 1.5rem;
        }
        .service-group-title {
            margin-bottom: 1rem;
            font-size: 1rem;
            font-weight: 700;
            color: var(--accent);
        }
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
        }
        .service-card {
            display: flex;
            flex-direction: column;
            width: 100%;
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 18px;
            background: var(--surface-3);
            color: var(--text);
            text-align: left;
            padding: 1rem 1rem 1.2rem;
            transition: transform .2s ease, border-color .2s ease, background .2s ease;
            cursor: pointer;
            min-height: 172px;
        }
        .service-card:hover {
            transform: translateY(-3px);
            border-color: rgba(34,197,94,.4);
            background: rgba(255,255,255,.03);
        }
        .service-card-top {
            display: flex;
            justify-content: space-between;
            gap: .75rem;
            margin-bottom: 1rem;
        }
        .service-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 58px;
            height: 28px;
            border-radius: 999px;
            background: rgba(34,197,94,.14);
            color: #d9f99d;
            font-size: .8rem;
            font-weight: 700;
        }
        .service-card h3 {
            margin: 0 0 .7rem;
            font-size: 1rem;
            line-height: 1.35;
        }
        .service-meta {
            display: flex;
            flex-wrap: wrap;
            gap: .65rem;
            align-items: center;
        }
        .service-price {
            color: var(--accent);
            font-weight: 700;
        }
        .service-time {
            color: var(--accent-2);
            font-weight: 600;
        }
        .service-card.selected {
            border-color: rgba(34,197,94,.8);
            background: rgba(34,197,94,.08);
        }
        .order-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .75rem;
            margin-top: 1rem;
        }
        .order-actions .form-control {
            background: var(--surface-2);
            border: 1px solid rgba(255,255,255,.08);
            color: var(--text);
        }
        .order-actions .form-control::placeholder {
            color: var(--muted);
        }
        .history-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            margin-bottom: 1.25rem;
        }
        .table-responsive {
            overflow: hidden;
        }
        .table thead {
            background: rgba(255,255,255,.04);
        }
        .table th,
        .table td {
            border-top: 1px solid rgba(255,255,255,.08);
            vertical-align: middle;
        }
        .table tbody tr:hover {
            background: rgba(255,255,255,.04);
        }
        .user-dropdown,
        .customizer-panel {
            position: fixed;
            top: 88px;
            right: 1rem;
            width: min(360px, calc(100% - 2rem));
            background: var(--surface);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 22px;
            box-shadow: 0 32px 90px rgba(0,0,0,.25);
            padding: 1.25rem;
            backdrop-filter: blur(18px);
            opacity: 0;
            pointer-events: none;
            transform: translateY(-12px);
            transition: opacity .25s ease, transform .25s ease;
            z-index: 2000;
        }
        .user-dropdown.show,
        .customizer-panel.show {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
        }
        .user-dropdown h3,
        .customizer-panel h3 {
            margin: 0 0 1rem;
            font-size: 1rem;
        }
        .user-dropdown a,
        .user-dropdown button,
        .customizer-panel button {
            width: 100%;
            text-align: left;
            border: none;
            background: transparent;
            color: var(--text);
            padding: .85rem 1rem;
            border-radius: 14px;
            margin-bottom: .6rem;
            transition: background .2s ease;
            cursor: pointer;
            font-weight: 600;
        }
        .user-dropdown a:hover,
        .user-dropdown button:hover,
        .customizer-panel button:hover {
            background: rgba(255,255,255,.05);
        }
        .user-dropdown .security-box {
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 18px;
            padding: 1rem;
            background: var(--surface-2);
        }
        .security-box small {
            display: block;
            color: var(--muted);
            margin-bottom: .6rem;
        }
        .gear-button {
            background: rgba(255,255,255,.07);
            border: 1px solid rgba(255,255,255,.08);
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text);
            cursor: pointer;
            transition: transform .2s ease, background .2s ease;
        }
        .gear-button:hover {
            transform: rotate(10deg);
            background: rgba(255,255,255,.12);
        }
        .customizer-panel .block {
            margin-bottom: 1rem;
        }
        .customizer-panel .block p {
            color: var(--muted);
            margin: .35rem 0 1rem;
        }
        .switch-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .8rem;
        }
        .switch-card {
            padding: 1rem;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,.08);
            background: var(--surface-2);
            cursor: pointer;
            transition: border-color .2s ease, background .2s ease;
        }
        .switch-card.active {
            border-color: rgba(34,197,94,.35);
            background: rgba(34,197,94,.08);
        }
        .switch-card strong {
            display: block;
            margin-bottom: .35rem;
            font-size: .95rem;
        }
        .empty-state {
            padding: 2rem;
            border: 1px dashed rgba(255,255,255,.12);
            border-radius: 18px;
            text-align: center;
            color: var(--muted);
        }
        .selected-service-banner {
            background: rgba(34,197,94,.08);
            border: 1px solid rgba(34,197,94,.16);
            border-radius: 18px;
            padding: 1rem 1.2rem;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            color: var(--text);
        }
        .selected-service-banner strong {
            color: var(--accent);
        }
        .content-bottom {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        @media (min-width: 992px) {
            .content-bottom {
                grid-template-columns: 1.5fr 1fr;
            }
        }
        body.style-list .service-grid {
            grid-template-columns: 1fr;
        }
        body.style-list .service-card {
            min-height: auto;
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
        }
        body.style-list .service-card-body {
            flex: 1;
            margin-left: 1rem;
        }
    </style>
</head>
<body class="theme-dark style-cards">
<div class="top-support">
    <div class="bar-inner">
        <div>
            <a href="https://api.whatsapp.com/send?phone=5581999999999" target="_blank">WhatsApp Suporte</a>
            <span class="text-muted mx-2">•</span>
            <a href="mailto:contato@supraserver.com">contato@supraserver.com</a>
        </div>
        <div class="badge-surface">Painel Premium Supraserver</div>
    </div>
</div>
<nav class="main-nav navbar navbar-expand-lg">
    <div class="container-fluid px-4">
        <a class="navbar-brand" href="#">
            <span class="brand-mark">S</span>
            <span>Supraserver</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <button class="gear-button" id="settingsToggle" type="button" aria-label="Abrir customizador">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83l-.7.7a2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0l-.7-.7a2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15 1.65 1.65 0 0 0 3.09 14H3a2 2 0 0 1-2-2v-1a2 2 0 0 1 2-2h.09c.43 0 .84-.17 1.14-.47a1.65 1.65 0 0 0 .33-1.82l-.06-.06a2 2 0 0 1 0-2.83l.7-.7a2 2 0 0 1 2.83 0l.06.06c.42.38 1.01.45 1.5.28H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2h1a2 2 0 0 1 2 2v.09c0 .43.17.84.47 1.14a1.65 1.65 0 0 0 1.82.33l.06-.06a2 2 0 0 1 2.83 0l.7.7a2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.33.48.33 1.09 0 1.57z"></path></svg>
            </button>
            <div class="status-pill">
                <span>Usuário: <strong><?php echo htmlspecialchars($user['usuario'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <span>Saldo Global: <strong><?php echo $balanceLabel; ?></strong></span>
            </div>
            <button id="userMenuToggle" class="btn btn-sm btn-outline-light">Perfil</button>
        </div>
    </div>
</nav>
<ul id="userDropdown" class="dropdown-menu dropdown-menu-end dropdown-menu-dark shadow-lg" aria-labelledby="userMenuToggle">
    <li><a class="dropdown-item" href="perfil.php">Meu perfil</a></li>
    <li><a class="dropdown-item" href="adicionar_fundo.php">+ Adicionar Fundo</a></li>
    <li><a class="dropdown-item" href="api_acesso.php">API Acesso</a></li>
    <li><a class="dropdown-item" href="faturas.php">Minha fatura</a></li>
    <li><a class="dropdown-item" href="email_info.php">Meu e-mail</a></li>
    <li><a class="dropdown-item" href="afirmacao.php">Meu Afirmação</a></li>
    <li><a class="dropdown-item" href="transferir.php">Transferência de Créditos</a></li>
    <li><a class="dropdown-item" href="preferencias.php">Email Preferência</a></li>
    <li><a class="dropdown-item" href="status_servicos.php">Serviço Estado</a></li>
    <li><a class="dropdown-item" href="instalar_app.php">Instalar App De Ligação</a></li>
    <li><hr class="dropdown-divider border-secondary"></li>
    <li class="px-3 small text-muted"><strong>Último Login</strong></li>
    <li class="px-3 small text-muted font-monospace"><?= htmlspecialchars($lastLoginTime) ?></li>
    <li class="px-3 small text-muted font-monospace text-wrap">IP: <?= htmlspecialchars($lastLoginIp) ?></li>
    <li><hr class="dropdown-divider border-secondary"></li>
    <li><a class="dropdown-item text-danger fw-bold" href="logout.php">Sair</a></li>
</ul>
<div class="customizer-panel" id="customizerPanel">
    <h3>Customização</h3>
    <div class="block">
        <strong>Modo de tema</strong>
        <p>Altere o modo de cor do painel de forma instantânea.</p>
        <div class="switch-grid">
            <button type="button" class="switch-card" data-theme="dark" id="themeDark">Escuro</button>
            <button type="button" class="switch-card" data-theme="light" id="themeLight">Claro</button>
        </div>
    </div>
    <div class="block">
        <strong>Estilo da ordem</strong>
        <p>Escolha entre visualização em lista ou bloco de serviços.</p>
        <div class="switch-grid">
            <button type="button" class="switch-card active" data-style="cards" id="styleCards">Blocos</button>
            <button type="button" class="switch-card" data-style="list" id="styleList">Lista</button>
        </div>
    </div>
</div>
<div class="page-container">
    <section class="panel service-section">
        <div class="section-heading">
            <h2>Fazer Pedido</h2>
            <span class="badge-surface">Selecione um serviço e clique para iniciar</span>
        </div>
        <div style="margin: .5rem 0 1rem; display:flex; gap:.5rem; align-items:center;">
            <input id="serviceSearch" type="search" class="form-control" placeholder="Pesquisar serviços (nome, categoria)" style="max-width:420px;" />
            <button id="clearSearch" class="btn btn-outline-light" type="button">Limpar</button>
        </div>
        <div class="tabs-row" role="tablist">
            <button class="<?php echo $activeTab === 'imei' ? 'active' : ''; ?>" data-tab="tab-imei">IMEI Service</button>
            <button class="<?php echo $activeTab === 'server' ? 'active' : ''; ?>" data-tab="tab-server">Server Services</button>
            <button class="<?php echo $activeTab === 'remote' ? 'active' : ''; ?>" data-tab="tab-remote">Remote Service</button>
        </div>
        <div id="tab-imei" class="tab-panel" style="display: <?php echo $activeTab === 'imei' ? 'block' : 'none'; ?>;">
            <?php render_service_cards(filter_groups_by_origin($allGroups, 'imei'), 'IMEI Service', 'imei'); ?>
        </div>
        <div id="tab-server" class="tab-panel" style="display: <?php echo $activeTab === 'server' ? 'block' : 'none'; ?>;">
            <?php render_service_cards(filter_groups_by_origin($allGroups, 'server'), 'Server Services', 'server'); ?>
        </div>
        <div id="tab-remote" class="tab-panel" style="display: <?php echo $activeTab === 'remote' ? 'block' : 'none'; ?>;">
            <?php render_service_cards(filter_groups_by_origin($allGroups, 'remote'), 'Remote Service', 'remote'); ?>
        </div>
        <div class="selected-service-banner" id="selectedServiceBanner" style="display:none;">
            <div>
                <div>Serviço selecionado:</div>
                <strong id="selectedServiceName"></strong>
            </div>
            <div id="selectedServicePrice"></div>
        </div>
        <form id="orderForm" class="order-actions">
            <input type="hidden" name="serviceId" id="serviceId" value="">
            <input type="hidden" name="orderType" id="orderType" value="imei">
            <div id="inputGroup" class="d-flex flex-column gap-2">
                <label id="inputLabel" class="form-label mb-0 text-muted">Digite o IMEI do Aparelho</label>
                <div id="imeiGroup">
                    <input type="text" name="imei" id="imeiInput" class="form-control" placeholder="Digite o IMEI" required>
                </div>
                <div id="accountGroup" style="display:none;">
                    <input type="text" name="account" id="accountInput" class="form-control" placeholder="Nome de Usuário / E-mail da Conta" />
                </div>
                <div id="remoteGroup" style="display:none;">
                    <input type="text" name="remoteInfo" id="remoteInput" class="form-control" placeholder="Informações gerais de contato" />
                </div>
            </div>
            <input type="text" name="reference" id="referenceInput" class="form-control" placeholder="Referência opcional">
            <div id="quantityGroup" style="display:none; min-width:220px;">
                <input type="number" name="quantity" id="quantityInput" class="form-control" min="1" value="1" placeholder="Quantidade / Créditos" />
            </div>
            <div id="depositInfo" style="display:none; align-self:center; color: var(--muted); margin-left:.5rem;"></div>
            <button type="submit" class="btn btn-success">Enviar pedido</button>
        </form>
        <div id="orderFeedback" class="mt-3"></div>
    </section>

    <div class="content-bottom">
        <section class="panel history-panel" id="historySection">
            <div class="section-heading">
                <h2>Histórico de Pedidos</h2>
                <span class="badge-surface">Filtre por tipo de ordem</span>
            </div>
            <div class="history-tabs" role="tablist">
                <button class="<?php echo $activeHistory === 'all' ? 'active' : ''; ?>" data-filter="all">Todos</button>
                <button class="<?php echo $activeHistory === 'imei' ? 'active' : ''; ?>" data-filter="imei">IMEI Orders</button>
                <button class="<?php echo $activeHistory === 'server' ? 'active' : ''; ?>" data-filter="server">Server Orders</button>
                <button class="<?php echo $activeHistory === 'remote' ? 'active' : ''; ?>" data-filter="remote">Remote Orders</button>
            </div>
            <div class="table-responsive">
                <table class="table table-borderless table-hover text-white align-middle">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>IMEI</th>
                            <th>Serviço</th>
                            <th>Referência</th>
                            <th>Status</th>
                            <th>Tipo</th>
                        </tr>
                    </thead>
                    <tbody id="orderTableBody">
                        <?php foreach ($orders as $order):
                            $serviceId = $order['servico_id'] ?? '';
                            $orderType = resolve_order_type($serviceId, $serviceTypeMap);
                        ?>
                            <tr data-order-type="<?php echo htmlspecialchars($orderType, ENT_QUOTES, 'UTF-8'); ?>">
                                <td><?php echo htmlspecialchars($order['data_pedido'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($order['imei'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($serviceId, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($order['referencia'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($order['status'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo strtoupper(htmlspecialchars($orderType, ENT_QUOTES, 'UTF-8')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel" style="padding:1.5rem;">
            <div class="section-heading">
                <h2>Resumo do Usuário</h2>
                <span class="badge-surface">Informações e segurança</span>
            </div>
            <div style="display:grid; gap:1rem;">
                <div class="service-card" style="padding:1.25rem;">
                    <strong>Nome</strong>
                    <p><?php echo htmlspecialchars($user['usuario'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="service-card" style="padding:1.25rem;">
                    <strong>Saldo Cliente</strong>
                    <p>R$ <?php echo number_format($user['saldo_cliente'], 2, ',', '.'); ?></p>
                </div>
                <div class="service-card" style="padding:1.25rem;">
                    <strong>Último Login</strong>
                    <p><?php echo htmlspecialchars($lastLoginTime, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($lastLoginIp, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        </section>
    </div>
</div>

<script>
    const tabs = document.querySelectorAll('.tabs-row button');
    const tabPanels = document.querySelectorAll('.tab-panel');
    const historyFilters = document.querySelectorAll('.history-tabs button');
    const orderRows = document.querySelectorAll('#orderTableBody tr');
    const userMenuToggle = document.getElementById('userMenuToggle');
    const userDropdown = document.getElementById('userDropdown');
    const settingsToggle = document.getElementById('settingsToggle');
    const customizerPanel = document.getElementById('customizerPanel');
    const themeDark = document.getElementById('themeDark');
    const themeLight = document.getElementById('themeLight');
    const styleCards = document.getElementById('styleCards');
    const styleList = document.getElementById('styleList');
    const serviceCards = document.querySelectorAll('.service-card');
    const selectedServiceBanner = document.getElementById('selectedServiceBanner');
    const selectedServiceName = document.getElementById('selectedServiceName');
    const selectedServicePrice = document.getElementById('selectedServicePrice');
    const serviceIdInput = document.getElementById('serviceId');
    const orderTypeInput = document.getElementById('orderType');
    const imeiGroup = document.getElementById('imeiGroup');
    const imeiInput = document.getElementById('imeiInput');
    const accountGroup = document.getElementById('accountGroup');
    const accountInput = document.getElementById('accountInput');
    const remoteGroup = document.getElementById('remoteGroup');
    const remoteInput = document.getElementById('remoteInput');
    const orderForm = document.getElementById('orderForm');
    const orderFeedback = document.getElementById('orderFeedback');
    const orderTableBody = document.getElementById('orderTableBody');
    const inputLabel = document.getElementById('inputLabel');
    const inputGroup = document.getElementById('inputGroup');
    const referenceInput = document.getElementById('referenceInput');
    const quantityGroup = document.getElementById('quantityGroup');
    const quantityInput = document.getElementById('quantityInput');
    const depositInfo = document.getElementById('depositInfo');
    const submitButton = orderForm.querySelector('button[type="submit"]');
    let currentHistoryFilter = '<?php echo $activeHistory; ?>';
    let currentTab = '<?php echo $activeTab; ?>';
    let currentMinQty = 1;

    function formatBrl(value) {
        return 'R$ ' + Number(value || 0).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function buildHistoryQuery() {
        return '?tab=' + currentTab + '&history=' + currentHistoryFilter;
    }

    function setActiveTab(tabId) {
        tabs.forEach(button => button.classList.toggle('active', button.dataset.tab === tabId));
        tabPanels.forEach(panel => panel.style.display = panel.id === tabId ? 'block' : 'none');
        currentTab = tabId.replace('tab-', '');
        orderTypeInput.value = currentTab;
        history.replaceState(null, '', buildHistoryQuery());
    }

    const tabOrderTypeMap = {
        'tab-imei': 'imei',
        'tab-server': 'server',
        'tab-remote': 'remote'
    };

    tabs.forEach(button => {
        button.addEventListener('click', () => {
            setActiveTab(button.dataset.tab);
            const orderType = tabOrderTypeMap[button.dataset.tab] || 'imei';
            setOrderMode(orderType);
            serviceIdInput.value = '';
            selectedServiceBanner.style.display = 'none';
            serviceCards.forEach(item => item.classList.remove('selected'));
        });
    });

    function setDefaultTabFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');
        const historyFilter = params.get('history') || '<?php echo $activeHistory; ?>';

        if (tab && ['imei', 'server', 'remote'].includes(tab)) {
            const button = document.querySelector(`.tabs-row button[data-tab="tab-${tab}"]`);
            if (button) {
                button.click();
            }
        } else {
            const defaultButton = document.querySelector(`.tabs-row button[data-tab="tab-${currentTab}"]`);
            if (defaultButton) {
                defaultButton.click();
            }
        }

        if (historyFilter && ['all', 'imei', 'server', 'remote'].includes(historyFilter)) {
            currentHistoryFilter = historyFilter;
            filterOrders(historyFilter, false);
        } else {
            filterOrders('all', false);
        }
    }

    function filterOrders(type, updateState = true) {
        currentHistoryFilter = type;
        historyFilters.forEach(button => button.classList.toggle('active', button.dataset.filter === type));
        const rows = orderTableBody.querySelectorAll('tr');
        rows.forEach(row => {
            const rowType = row.dataset.orderType;
            row.style.display = type === 'all' || rowType === type ? '' : 'none';
        });
        if (updateState) {
            history.replaceState(null, '', buildHistoryQuery() + '#historySection');
        }
    }

    historyFilters.forEach(button => {
        button.addEventListener('click', () => filterOrders(button.dataset.filter));
    });

    userMenuToggle.addEventListener('click', (event) => {
        event.stopPropagation();
        if (userDropdown) {
            userDropdown.classList.toggle('show');
        }
        customizerPanel.classList.remove('show');
    });

    settingsToggle.addEventListener('click', (event) => {
        event.stopPropagation();
        customizerPanel.classList.toggle('show');
        if (userDropdown) {
            userDropdown.classList.remove('show');
        }
    });

    document.addEventListener('click', (event) => {
        if (userDropdown && !userDropdown.contains(event.target) && event.target !== userMenuToggle) {
            userDropdown.classList.remove('show');
        }
        if (!customizerPanel.contains(event.target) && event.target !== settingsToggle) {
            customizerPanel.classList.remove('show');
        }
    });

    function applyPreferences() {
        const theme = localStorage.getItem('supraserver_theme') || 'dark';
        const style = localStorage.getItem('supraserver_style') || 'cards';
        document.body.classList.toggle('theme-light', theme === 'light');
        document.body.classList.toggle('theme-dark', theme === 'dark');
        document.body.classList.toggle('style-list', style === 'list');
        document.body.classList.toggle('style-cards', style === 'cards');
        themeDark.classList.toggle('active', theme === 'dark');
        themeLight.classList.toggle('active', theme === 'light');
        styleCards.classList.toggle('active', style === 'cards');
        styleList.classList.toggle('active', style === 'list');
    }

    [themeDark, themeLight].forEach(button => {
        button.addEventListener('click', () => {
            localStorage.setItem('supraserver_theme', button.dataset.theme);
            applyPreferences();
        });
    });

    [styleCards, styleList].forEach(button => {
        button.addEventListener('click', () => {
            localStorage.setItem('supraserver_style', button.dataset.style);
            applyPreferences();
        });
    });

    function setOrderMode(type, serviceName = '') {
        orderTypeInput.value = type;
        const serviceText = String(serviceName || '').toLowerCase();
        const isBypassLike = /(bypass|iremoval|removal|hfz|lpro|apple)/i.test(serviceText);
        const isCreditPack = /(credit|credits|pack)/i.test(serviceText);
        const isFixedLicense = /(license|licenca|unlocktool|dft|pro|meses|ano|year)/i.test(serviceText);
        const isRemoteRental = /(alug|aluguel|rental|remote|teamviewer|anydesk|hora|hour)/i.test(serviceText);

        inputLabel.textContent = type === 'imei'
            ? (isBypassLike ? 'Número de Série (Serial Number)' : 'Digite o IMEI do Aparelho')
            : type === 'server'
                ? (isCreditPack ? 'Username (Nome de Usuário da Ferramenta)' : 'E-mail de Registro / Nome de Usuário')
                : 'Pagamento do Aluguel por Hora';

        imeiGroup.style.display = type === 'imei' ? 'block' : 'none';
        imeiInput.required = type === 'imei';
        accountGroup.style.display = type === 'server' ? 'block' : 'none';
        accountInput.required = type === 'server';
        remoteGroup.style.display = type === 'remote' ? 'block' : 'none';
        remoteInput.required = type === 'remote';
        referenceInput.style.display = type === 'remote' ? 'none' : 'block';
        referenceInput.required = type !== 'remote';
        inputGroup.style.display = type === 'remote' ? 'none' : 'block';
        quantityGroup.style.display = (type === 'server' && isCreditPack) ? 'block' : 'none';
        depositInfo.style.display = (type === 'server' && isCreditPack) ? 'block' : 'none';

        if (type === 'imei') {
            imeiInput.placeholder = isBypassLike
                ? 'Número de Série (Serial Number)'
                : 'Digite o IMEI do Aparelho';
            quantityInput.value = 1;
        } else if (type === 'server') {
            accountInput.placeholder = isCreditPack ? 'Username (Nome de Usuário da Ferramenta)' : 'E-mail de Registro / Nome de Usuário';
            if (isCreditPack) {
                quantityInput.min = currentMinQty;
                quantityInput.value = Math.max(currentMinQty, Number(quantityInput.value || currentMinQty));
                depositInfo.textContent = 'Depósito estimado: ' + formatBrl(Number(quantityInput.value || currentMinQty));
            } else {
                quantityInput.value = 1;
                depositInfo.textContent = '';
            }
        } else if (type === 'remote') {
            imeiInput.value = '';
            accountInput.value = '';
            remoteInput.value = '';
            quantityInput.value = 1;
            referenceInput.value = '';
            depositInfo.textContent = '';
            submitButton.disabled = false;
            submitButton.style.opacity = '1';
        }

        if (type === 'server' && isFixedLicense) {
            quantityGroup.style.display = 'none';
            depositInfo.style.display = 'none';
        }

        if (type === 'server' && isRemoteRental) {
            quantityGroup.style.display = 'none';
            depositInfo.style.display = 'none';
        }
    }

    function initDefaultOrderMode() {
        const orderType = currentTab || 'imei';
        setOrderMode(orderType);
        orderTypeInput.value = orderType;
    }

    function selectServiceCard(card) {
        serviceCards.forEach(item => item.classList.remove('selected'));
        card.classList.add('selected');
        const selectedType = card.dataset.serviceType || 'imei';
        const selectedName = card.dataset.serviceName || '';
        serviceIdInput.value = card.dataset.serviceId || '';
        orderTypeInput.value = selectedType;
        setOrderMode(selectedType, selectedName);
        selectedServiceName.textContent = (selectedName || '') + (card.dataset.serviceId ? ' (' + card.dataset.serviceId + ')' : '');
        selectedServicePrice.textContent = card.dataset.serviceCredit || '';
        selectedServiceBanner.style.display = 'flex';

        const price = card.dataset.servicePrice ? parseFloat(card.dataset.servicePrice) : null;
        const minQty = card.dataset.minQty ? parseInt(card.dataset.minQty, 10) : 1;
        currentMinQty = Math.max(1, minQty || 1);
        const qGroup = document.getElementById('quantityGroup');
        const hasServerCredit = selectedType === 'server' && /(credit|credits|pack)/i.test(selectedName);

        if (selectedType === 'remote') {
            qGroup.style.display = 'none';
            depositInfo.style.display = 'none';
            quantityInput.value = 1;
            referenceInput.value = '';
            submitButton.disabled = false;
            submitButton.style.opacity = '1';
            return;
        }

        if (price !== null && !isNaN(price) && hasServerCredit) {
            qGroup.style.display = 'block';
            depositInfo.style.display = 'block';
            const qInput = document.getElementById('quantityInput');
            qInput.min = currentMinQty;
            qInput.value = Math.max(currentMinQty, Number(qInput.value || currentMinQty));
            updateDeposit(price, qInput.value, currentMinQty);
            qInput.oninput = function () {
                const newQty = Math.max(currentMinQty, parseInt(this.value, 10) || currentMinQty);
                this.value = newQty;
                updateDeposit(price, this.value, currentMinQty);
            };
        } else if (price !== null && !isNaN(price)) {
            qGroup.style.display = 'none';
            depositInfo.style.display = 'none';
            quantityInput.value = 1;
        } else {
            qGroup.style.display = 'none';
            depositInfo.style.display = 'none';
        }
    }

    serviceCards.forEach(card => {
        card.addEventListener('click', () => selectServiceCard(card));
    });

    function updateDeposit(unitPrice, quantity, minAllowed = currentMinQty) {
        const qty = Math.max(1, parseInt(quantity, 10) || 1);
        const total = (parseFloat(unitPrice) * qty) || 0;
        if (qty < minAllowed) {
            submitButton.disabled = true;
            submitButton.style.opacity = '.55';
            depositInfo.textContent = 'Quantidade mínima permitida: ' + minAllowed + ' crédito(s).';
            return;
        }
        submitButton.disabled = false;
        submitButton.style.opacity = '1';
        depositInfo.textContent = 'Depósito necessário: ' + formatBrl(total);
    }

    const serviceSearch = document.getElementById('serviceSearch');
    const clearSearch = document.getElementById('clearSearch');
    serviceSearch.addEventListener('input', function () {
        const q = (this.value || '').trim().toLowerCase();
        serviceCards.forEach(card => {
            const name = (card.dataset.serviceName || '').toLowerCase();
            const badge = (card.querySelector('.service-chip') || {}).textContent || '';
            const combined = (name + ' ' + badge).toLowerCase();
            card.style.display = q === '' || combined.includes(q) ? '' : 'none';
        });
    });
    clearSearch.addEventListener('click', function () {
        serviceSearch.value = '';
        serviceSearch.dispatchEvent(new Event('input'));
    });

    function createHistoryRow({ date, imei, serviceId, reference, status, type }) {
        const row = document.createElement('tr');
        row.dataset.orderType = type;
        row.innerHTML = `
            <td>${date}</td>
            <td>${imei}</td>
            <td>${serviceId}</td>
            <td>${reference || '-'}</td>
            <td>${status}</td>
            <td>${type.toUpperCase()}</td>
        `;
        return row;
    }

    function formatCurrentDateTime() {
        const now = new Date();
        return now.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    orderForm.addEventListener('submit', function (event) {
        event.preventDefault();
        orderFeedback.innerHTML = '<div class="alert alert-info" role="status">Processando pedido...</div>';
        const formData = new FormData(this);
        const orderType = orderTypeInput.value || 'imei';
        const endpoint = orderType === 'server' ? 'place_server_order.php' : orderType === 'remote' ? 'place_remote_order.php' : 'place_imei_order.php';

        if (orderType === 'remote') {
            formData.set('orderType', 'remote');
        }

        fetch(endpoint, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(json => {
            const errorMessage = json.error || json.ERROR || (json.response && (json.response.error || json.response.ERROR));
            if (errorMessage) {
                orderFeedback.innerHTML = '<div class="alert alert-danger" role="alert">' + String(errorMessage) + '</div>';
                return;
            }

            const status = json.status || 'Sucesso';
            const newRow = createHistoryRow({
                date: formatCurrentDateTime(),
                imei: orderType === 'server' ? (formData.get('account') || '-') : orderType === 'remote' ? (formData.get('remoteInfo') || '-') : (formData.get('imei') || '-'),
                serviceId: formData.get('serviceId') || '-',
                reference: formData.get('reference') || '-',
                status: status,
                type: orderType
            });

            if (currentHistoryFilter !== 'all' && currentHistoryFilter !== orderType) {
                newRow.style.display = 'none';
            }
            orderTableBody.prepend(newRow);

            orderFeedback.innerHTML = '<div class="alert alert-success" role="status">Pedido enviado com sucesso. Novo pedido adicionado ao histórico.</div>';
            orderForm.reset();
            serviceIdInput.value = '';
            selectedServiceBanner.style.display = 'none';
            setOrderMode(orderType);
        })
        .catch(() => {
            orderFeedback.innerHTML = '<div class="alert alert-danger" role="alert">Erro ao enviar pedido. Tente novamente.</div>';
        });
    });

    applyPreferences();
    initDefaultOrderMode();
    setDefaultTabFromUrl();
    filterOrders('all');
</script>
</body>
</html>
