<?php
declare(strict_types=1);

// Fixed destinations: this endpoint cannot be used as an arbitrary HTTP proxy.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}
if (!extension_loaded('curl')) {
    http_response_code(503);
    echo json_encode(['error' => 'El servidor necesita la extensión PHP cURL.']);
    exit;
}
require_once __DIR__ . '/configuration.php';
$config = dashboardConfig();
$paths = ['health' => 'health', 'load' => 'load', 'roles' => 'roles',
    'packets' => 'metrics/packets-stats', 'wireguard' => 'metrics/wireguard-stats',
    'build' => 'build-information', 'description' => 'description', 'auxiliary' => 'auxiliary-details'];
$multi = curl_multi_init();
$requests = [];
$nodes = [];
foreach ($config['nodes'] as $name => $nodeConfig) {
    if ($nodeConfig['kind'] !== 'gateway' || !$nodeConfig['api_url']) continue;
    $host = parse_url($nodeConfig['api_url'], PHP_URL_HOST);
    $nodes[$name] = ['id' => $name, 'name' => $nodeConfig['name'], 'host' => $host, 'data' => [], 'errors' => []];
    foreach ($paths as $key => $path) {
        $ch = curl_init($nodeConfig['api_url'] . ($key === 'auxiliary' ? '/api/v2/' : '/api/v1/') . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 9, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        curl_multi_add_handle($multi, $ch);
        $requests[] = [$ch, $name, $key];
    }
}
do {
    $status = curl_multi_exec($multi, $running);
    if ($running && $status === CURLM_OK) {
        if (curl_multi_select($multi, 0.5) === -1) usleep(10000);
    }
} while ($running && $status === CURLM_OK);
foreach ($requests as [$ch, $name, $key]) {
    $body = curl_multi_getcontent($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $value = is_string($body) ? json_decode($body, true) : null;
    if (curl_errno($ch) === 0 && $code === 200 && is_array($value)) {
        $nodes[$name]['data'][$key] = $value;
    } else {
        $nodes[$name]['errors'][] = $key;
    }
    if ($key === 'health' && isset($nodes[$name]['data']['health'])) {
        $nodes[$name]['latency_ms'] = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    }
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

// Wallets come from each node; only the fixed bank host can be queried.
$multi = curl_multi_init();
$requests = [];
foreach ($nodes as $name => $node) {
    $address = $node['data']['auxiliary']['address'] ?? null;
    if (!is_string($address) || !preg_match('/^n1[023456789acdefghjklmnpqrstuvwxyz]{38}$/D', $address)) {
        $nodes[$name]['errors'][] = 'wallet';
        continue;
    }
    $nodes[$name]['data']['wallet'] = ['address' => $address, 'unym' => null];
    $ch = curl_init('https://api.nymtech.net/cosmos/bank/v1beta1/balances/' . rawurlencode($address));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 9, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    curl_multi_add_handle($multi, $ch);
    $requests[] = [$ch, $name];
}
do {
    $status = curl_multi_exec($multi, $running);
    if ($running && $status === CURLM_OK && curl_multi_select($multi, 0.5) === -1) usleep(10000);
} while ($running && $status === CURLM_OK);
foreach ($requests as [$ch, $name]) {
    $body = curl_multi_getcontent($ch);
    $value = is_string($body) ? json_decode($body, true) : null;
    $amount = null;
    if (curl_errno($ch) === 0 && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200
        && is_array($value['balances'] ?? null)) {
        // Never interpret an incomplete or failed response as a zero balance.
        if (isset($value['pagination']) && empty($value['pagination']['next_key'])) $amount = '0';
        foreach ($value['balances'] as $coin) {
            if (($coin['denom'] ?? '') === 'unym') {
                $amount = is_string($coin['amount'] ?? null) && preg_match('/^[0-9]+$/D', $coin['amount']) ? $coin['amount'] : null;
                break;
            }
        }
    }
    $nodes[$name]['data']['wallet']['unym'] = $amount;
    if ($amount === null) $nodes[$name]['errors'][] = 'saldo';
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

echo json_encode(['checked_at' => gmdate('c'), 'nodes' => array_values($nodes)], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
