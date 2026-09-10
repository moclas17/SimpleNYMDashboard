<?php
require_once __DIR__ . '/configuration.php';
$config = dashboardConfig();
$publicNodes = array_values(array_map(static fn($n) => ['key' => $n['key'], 'name' => $n['name'], 'node_id' => $n['node_id'], 'kind' => $n['kind'], 'direct' => $n['kind'] === 'gateway' && $n['api_url'] !== null], $config['nodes']));
$directCount = count(array_filter($publicNodes, static fn($n) => $n['direct']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Nym · Monitor de nodos</title>
    <link rel="stylesheet" href="assets/dashboard.css?v=20260910-5">
    <script id="dashboard-config" type="application/json"><?php echo json_encode(['nodes' => $publicNodes, 'refresh_seconds' => $config['refresh_seconds']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script src="assets/dashboard.js?v=<?php echo filemtime(__DIR__ . '/assets/dashboard.js'); ?>" defer></script>
</head>
<body>
<main>
    <header><div><h1>nym <span>Monitor</span></h1><span class="subtitle"><?php echo count($publicNodes); ?> nodos</span></div><div class="controls"><span id="update" role="status">Consultando…</span><label><input id="auto" type="checkbox" checked> <?php echo $config['refresh_seconds']; ?> s</label><button id="refresh" type="button">Actualizar</button></div></header>
    <p id="error" class="error" role="alert" hidden></p>
    <section class="overview" aria-label="Resumen de los nodos"><div class="rewards-total"><span class="label">Rewards pendientes</span><strong id="total-rewards" aria-live="polite">—</strong><span id="total-rewards-status" class="hint">Consultando…</span></div><div><span class="label">API gateways</span><strong id="online">—<small> / <?php echo $directCount; ?></small></strong><span class="hint"><?php echo $directCount; ?> gateways con API</span></div><div><span class="label">TX gateways</span><strong id="sent">—</strong><span class="hint">WireGuard</span></div><div><span class="label">RX gateways</span><strong id="received">—</strong><span class="hint">WireGuard</span></div><div><span class="label">Descartados</span><strong id="dropped">—</strong><span class="hint">Gateways</span></div></section>
    <section id="nodes" class="nodes" aria-label="Detalle de nodos" aria-busy="true"><?php foreach ($publicNodes as $node): ?><article class="node" id="card-<?php echo $node['key']; ?>"><div class="loading"><?php echo htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8'); ?> · Consultando…</div></article><?php endforeach; ?><?php if (!$publicNodes): ?><p class="notice">Agrega nodos en config.php.</p><?php endif; ?></section>

</main>
</body>
</html>
