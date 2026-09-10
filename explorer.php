<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}
if (!extension_loaded('curl')) {
    http_response_code(503);
    echo json_encode(['error' => 'cURL no disponible.']);
    exit;
}
require_once __DIR__ . '/configuration.php';
$config = dashboardConfig();
$identities = [];
foreach ($config['nodes'] as $key => $node) $identities[$key] = [$node['node_id'], $node['identity']];
$multi = curl_multi_init();
$requests = [];
$nodes = [];
foreach ($identities as $name => [$id, $identity]) {
    $nodes[$name] = ['kind' => $config['nodes'][$name]['kind'], 'data' => [], 'errors' => []];
    foreach (['node' => '', 'meta' => '/meta', 'activity' => '/active-set-frequency', 'gateway' => '', 'rewards' => '', 'claim' => ''] as $key => $suffix) {
        if ($key === 'gateway' && $config['nodes'][$name]['kind'] === 'mixnode') continue;
        $url = $key === 'gateway' ? 'https://mainnet-node-status-api.nymtech.cc/v2/gateways/' . $identity
            : 'https://api.nym.spectredao.net/api/v1/nodes/' . $id . $suffix;
        if ($key === 'rewards') {
            $query = base64_encode(json_encode(['get_pending_node_operator_reward' => ['node_id' => $id]]));
            $url = 'https://api.nymtech.net/cosmwasm/wasm/v1/contract/'
                . 'n17srjznxl9dvzdkpwpw24gg668wc73val88a6m5ajg6ankwvz9wtst0cznr/smart/' . rawurlencode($query);
        }
        if ($key === 'claim') {
            $url = 'https://api.nymtech.net/cosmos/tx/v1beta1/txs?query='
                . rawurlencode("wasm-v2_withdraw_operator_reward.mix_id='$id'")
                . '&order_by=ORDER_BY_DESC&limit=1';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        curl_multi_add_handle($multi, $ch);
        $requests[] = [$ch, $name, $key];
    }
}
do {
    $status = curl_multi_exec($multi, $running);
    if ($running && $status === CURLM_OK && curl_multi_select($multi, 0.5) === -1) usleep(10000);
} while ($running && $status === CURLM_OK);
foreach ($requests as [$ch, $name, $key]) {
    $body = curl_multi_getcontent($ch);
    $data = is_string($body) ? json_decode($body, true, 512, JSON_BIGINT_AS_STRING) : null;
    $valid = curl_errno($ch) === 0 && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && is_array($data);
    if ($valid && $key === 'node') {
        $valid = ($data['identity_key'] ?? null) === $identities[$name][1]
            && ($data['node_id'] ?? null) === $identities[$name][0];
    }
    if ($valid && $key === 'gateway') {
        $valid = ($data['gateway_identity_key'] ?? null) === $identities[$name][1];
        // Keep only the operational metrics; omit verbose remote logs.
        $data = array_intersect_key($data, array_flip(['gateway_identity_key', 'last_probe_result',
            'ports_check', 'last_ports_check_utc', 'last_testrun_utc', 'last_updated_utc']));
    }
    if ($valid && $key === 'rewards') {
        $coin = $data['data']['amount_earned'] ?? null;
        $valid = is_array($coin) && ($coin['denom'] ?? null) === 'unym'
            && is_string($coin['amount'] ?? null) && preg_match('/^[0-9]+$/D', $coin['amount']);
        if ($valid) $data = ['unym' => $coin['amount'], 'checked_at' => gmdate('c')];
    }
    if ($valid && $key === 'claim') {
        $tx = $data['tx_responses'][0] ?? null;
        $claim = ['timestamp' => null, 'unym' => null];
        if (is_array($tx) && ($tx['code'] ?? 1) === 0) {
            foreach (($tx['events'] ?? []) as $event) {
                if (($event['type'] ?? '') !== 'wasm-v2_withdraw_operator_reward') continue;
                foreach (($event['attributes'] ?? []) as $attribute) {
                    if (($attribute['key'] ?? '') === 'amount' && is_string($attribute['value'] ?? null)) {
                        $claim['unym'] = preg_replace('/[^0-9]/', '', $attribute['value']);
                    }
                }
                $claim['timestamp'] = is_string($tx['timestamp'] ?? null) ? $tx['timestamp'] : null;
                break;
            }
        }
        $data = $claim;
    }
    if ($valid) $nodes[$name]['data'][$key] = $data;
    else $nodes[$name]['errors'][] = $key;
    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);
echo json_encode(['checked_at' => gmdate('c'), 'nodes' => $nodes], JSON_INVALID_UTF8_SUBSTITUTE);
