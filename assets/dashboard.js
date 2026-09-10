'use strict';
const $ = id => document.getElementById(id);
const settings = JSON.parse($('dashboard-config').textContent);
const configuredNodes = settings.nodes;
const directNodes = configuredNodes.filter(node => node.direct);
const directData = new Map();
const number = new Intl.NumberFormat('es-MX');
const numeric = value => typeof value === 'number' && Number.isFinite(value) && value >= 0;
const count = value => numeric(value) ? number.format(value) : '—';
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function bytes(value) {
    if (!numeric(value)) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const power = value > 0 ? Math.min(4, Math.floor(Math.log(value) / Math.log(1000))) : 0;
    return `${new Intl.NumberFormat('es-MX', {maximumFractionDigits: 1}).format(value / 1000 ** power)} ${units[power]}`;
}
function uptime(seconds) {
    if (!numeric(seconds)) return '—';
    const days = Math.floor(seconds / 86400), hours = Math.floor(seconds % 86400 / 3600), mins = Math.floor(seconds % 3600 / 60);
    return days ? `${days} d ${hours} h` : hours ? `${hours} h ${mins} min` : `${mins} min`;
}
function drops(data) {
    const values = [data?.ingress_mixing?.forward_hop_packets_dropped, data?.ingress_mixing?.final_hop_packets_dropped, data?.egress_mixing?.forward_hop_packets_dropped];
    return values.length > 0 && values.every(numeric) ? values.reduce((a, b) => a + b, 0) : null;
}
const loads = {negligible:'Mínima', low:'Baja', medium:'Media', high:'Alta', very_high:'Muy alta', at_capacity:'Al límite'};
function nymBalance(amount) {
    if (typeof amount !== 'string' || !/^\d+$/.test(amount)) return '—';
    const cents = (BigInt(amount) + 5000n) / 10000n;
    return `${number.format(cents / 100n)}.${(cents % 100n).toString().padStart(2, '0')} NYM`;
}
function operatorTerms(value) {
    if (value === true) return '<span class="badge">Aceptados</span>';
    if (value === false) return '<span class="badge down">No aceptados</span>';
    return '<span class="badge warning">Sin verificar</span>';
}
const explorerData = new Map();
let explorerBusy = false;
const percent = value => numeric(value) ? new Intl.NumberFormat('es-MX', {maximumFractionDigits: 2}).format(value * 100) + ' %' : '—';
function stake(value) {
    if (typeof value === 'number' && !Number.isSafeInteger(value)) return '—';
    return nymBalance(typeof value === 'number' ? String(value) : value);
}
const metric = (label, value) => `<div class="metric"><span>${label}</span><strong>${value}</strong></div>`;
const yesNo = value => value === true ? 'Sí' : value === false ? 'No' : '—';
function shortDate(value) {
    const date = new Date(value);
    return value && Number.isFinite(date.getTime()) ? escapeHtml(date.toLocaleString('es-MX', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'})) : '—';
}
function officialProbe(gateway) {
    if (!gateway) return '<div class="notice">Pruebas no disponibles</div>';
    const o = gateway.last_probe_result?.outcome, wg = o?.wg;
    const row = (label,value) => `<div class="check"><span>${label}</span><strong class="${value === false ? 'stale' : ''}">${value === true ? 'OK' : value === false ? 'Falló' : '—'}</strong></div>`;
    const speed = version => {
        const size=wg?.['downloaded_file_size_bytes_'+version], duration=wg?.['download_duration_milliseconds_'+version];
        return numeric(size) && numeric(duration) && duration > 0 && !wg?.['download_error_'+version] ? number.format(Math.round(size*8/duration/10)/100)+' Mbps' : '—';
    };
    return `<details open class="fold"><summary>Conectividad <span>${shortDate(gateway.last_testrun_utc)}</span></summary><div class="checks">
    ${row('Entrada',o?.as_entry?.can_connect)}${row('Ruta de entrada',o?.as_entry?.can_route)}${row('Salida IPv4',o?.as_exit?.can_route_ip_v4)}${row('Salida IPv6',o?.as_exit?.can_route_ip_v6)}${row('Registro WG',wg?.can_register)}${row('Handshake IPv4',wg?.can_handshake_v4)}${row('Handshake IPv6',wg?.can_handshake_v6)}${row('DNS IPv4',wg?.can_resolve_dns_v4)}${row('DNS IPv6',wg?.can_resolve_dns_v6)}${row('SOCKS5 HTTPS',o?.socks5?.https_connectivity?.https_success)}${row('Puertos',gateway.ports_check?.all_pass)}</div><div class="metric-grid">${metric('Descarga IPv4',speed('v4'))}${metric('Descarga IPv6',speed('v6'))}</div><div class="stamp">Puertos · ${shortDate(gateway.last_ports_check_utc)}</div></details>`;
}
function pendingRewards(rewards) {
    return `<div class="reward-line"><span>Rewards pendientes</span><strong>${nymBalance(rewards?.unym)}</strong></div>`;
}
function explorerCard(entry) {
    if (!entry) return '<div class="notice">Consultando red…</div>';
    const n=entry.data?.node, rewards=pendingRewards(entry.data?.rewards);
    const probe=entry.kind === 'mixnode' ? '' : officialProbe(entry.data?.gateway);
    if (!n) return rewards+'<div class="notice stale">Datos de red no disponibles</div>'+probe;
    const costs=n.rewarding_details?.cost_params;
    const margin=typeof costs?.profit_margin_percent === 'string' && /^\d+(\.\d+)?$/.test(costs.profit_margin_percent) ? percent(Number(costs.profit_margin_percent)) : '—';
    const date=entry.data.meta?.last_updated;
    return `${rewards}<div class="metric-grid network-metrics">${metric('Rendimiento',percent(n.performance_score))}${metric('Saturación',percent(n.uncapped_saturation ?? n.stake_saturation))}${metric('Activo · 24 h',entry.data.activity?.period_hours === 24 && numeric(entry.data.activity?.frequency_percentage) ? percent(entry.data.activity.frequency_percentage/100) : '—')}${metric('Stake',stake(n.total_stake))}${metric('Delegaciones',count(n.rewarding_details?.unique_delegations))}${metric('Configuración',percent(n.config_score))}</div>
    <details open class="fold"><summary>Detalle de staking <span>#${escapeHtml(n.node_id)}</span></summary><div class="metric-grid">${metric('Aporte',stake(n.original_pledge))}${metric('Margen',margin)}${metric('Costo operativo / intervalo',costs?.interval_operating_cost?.denom === 'unym' ? stake(costs.interval_operating_cost.amount) : '—')}${metric('Bonding',yesNo(n.bonded))}${metric('Versiones atrasadas',count(n.versions_behind))}${metric('Estrés: alcanzable',yesNo(n.stress_was_reachable))}</div><div class="address"><span>Wallet de bonding</span><code>${escapeHtml(n.bonding_address || '—')}</code></div></details>
    ${probe}<div class="stamp ${numeric(date) && Date.now()-date*1000>900000 ? 'stale' : ''}">Red · ${numeric(date) ? shortDate(date*1000) : '—'}${entry.errors?.length ? ' · datos parciales' : ''}</div>`;
}
function updateTotalRewards() {
    const ids = configuredNodes.map(node => node.key);
    const amounts = ids.map(id => explorerData.get(id)?.data?.rewards?.unym);
    const available = amounts.filter(value => typeof value === 'string' && /^\d+$/.test(value));
    const complete = ids.length > 0 && available.length === ids.length;
    $('total-rewards').textContent = complete
        ? nymBalance(available.reduce((sum, value) => sum + BigInt(value), 0n).toString()) : '—';
    $('total-rewards-status').textContent = complete
        ? `${ids.length} nodos`
        : `Incompleto · ${available.length}/${ids.length} nodos`;
}
function replaceContent(target, html) {
    const open = Array.from(target.querySelectorAll('details')).map(detail => detail.open);
    target.innerHTML = html;
    target.querySelectorAll('details').forEach((detail, index) => {detail.open = open[index] ?? true;});
}
async function refreshExplorer() {
    if (explorerBusy) return;
    explorerBusy = true;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 14000);
    try {
        const response = await fetch('explorer.php', {cache:'no-store', signal:controller.signal});
        const result = await response.json();
        if (!response.ok || !result.nodes) throw new Error('SpectreDAO no disponible');
        for (const node of configuredNodes) explorerData.set(node.key, result.nodes[node.key] || {kind:node.kind,data:{}});
    } catch {
        for (const node of configuredNodes) explorerData.set(node.key, {kind:node.kind,data:{}});
    } finally {
        clearTimeout(timer);
        explorerBusy = false;
        updateTotalRewards();
        renderConfiguredNodes();
    }
}
function networkCard(config, entry) {
    const n=entry?.data?.node;
    return `<div class="node-top"><h3>${escapeHtml(config.name)}</h3><span class="badge">${config.kind === 'mixnode' ? 'Mixnode' : 'Gateway'} #${config.node_id}</span></div><div class="node-meta"><span>v${escapeHtml(n?.description?.build_information?.build_version || '—')}</span><span>Términos ${yesNo(n?.accepted_tnc)}</span></div><section class="explorer">${explorerCard(entry)}</section>`;
}
function renderConfiguredNodes() {
    for (const config of configuredNodes) {
        const target = $('card-' + config.key);
        const entry = explorerData.get(config.key);
        const data = directData.get(config.key);
        replaceContent(target, config.direct
            ? card(data || {id:config.key,name:config.name,host:'—',data:{},errors:[]})
            : networkCard(config, entry));
    }
}
function walletAmount(wallet) {
    const amount = nymBalance(wallet?.unym);
    if (amount === '—' || typeof wallet?.address !== 'string' || !/^n1[023456789acdefghjklmnpqrstuvwxyz]{38}$/.test(wallet.address)) return amount;
    return `<a class="balance-link" href="https://api.nymtech.net/cosmos/bank/v1beta1/balances/${encodeURIComponent(wallet.address)}" target="_blank" rel="noopener noreferrer" aria-label="Consultar saldo de wallet">${amount}</a>`;
}
function card(node) {
    const d=node.data, health=d.health, active=health?.status === 'up';
    return `<div class="node-top"><h3>${escapeHtml(node.name || node.id)}</h3><span class="badge ${active ? '' : 'warning'}">${!health ? 'Sin respuesta' : active ? 'Activo' : 'No saludable'}</span></div>
    <div class="node-meta"><span>Gateway · v${escapeHtml(d.build?.build_version || '—')}</span><span>Términos ${yesNo(d.auxiliary?.accepted_operator_terms_and_conditions)}</span></div>
    <div class="metric-grid direct-metrics">${metric('Uptime',uptime(health?.uptime))}${metric('Carga',escapeHtml(loads[d.load?.total] || d.load?.total || '—'))}${metric('API',numeric(node.latency_ms) ? count(node.latency_ms)+' ms' : '—')}${metric('TX',bytes(d.wireguard?.bytes_tx))}${metric('RX',bytes(d.wireguard?.bytes_rx))}${metric('Saldo Wallet Servicio',walletAmount(d.wallet))}</div>
    <section class="explorer" id="explorer-${escapeHtml(node.id)}">${explorerCard(explorerData.get(node.id))}</section>
    <details open class="fold direct-detail"><summary>Detalle del gateway${node.errors.length ? '<span class="stale">Datos parciales</span>' : ''}</summary><div class="metric-grid">${metric('Paquetes enviados',count(d.packets?.egress_mixing?.forward_hop_packets_sent))}${metric('Descartados',count(drops(d.packets)))}</div><div class="address"><span>Wallet del servicio</span><code>${escapeHtml(d.wallet?.address || '—')}</code></div><div class="address"><span>Host</span><code>${escapeHtml(node.host)}</code></div></details>`;
}
let busy = false;
async function refresh() {
    if (busy) return;
    busy = true;
    refreshExplorer();
    $('refresh').disabled = true;
    $('nodes').setAttribute('aria-busy', 'true');
    $('update').textContent = 'Consultando nodos…';
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 23000);
    try {
        const response = await fetch('nodes.php', {cache:'no-store', signal:controller.signal});
        const result = await response.json();
        if (!response.ok || !Array.isArray(result.nodes) || result.nodes.length !== directNodes.length) throw new Error(result.error || 'Respuesta no válida.');
        const nodes = result.nodes;
        for (const node of nodes) directData.set(node.id, node);
        renderConfiguredNodes();
        const known = nodes.every(n => n.data.health);
        $('online').innerHTML = `${nodes.filter(n => n.data.health?.status === 'up').length}<small> / ${nodes.length}${known ? '' : ' · sin verificar'}</small>`;
        const sum = getter => {const values = nodes.map(getter); return values.length > 0 && values.every(numeric) ? values.reduce((a,b) => a+b,0) : null;};
        $('sent').textContent = bytes(sum(n => n.data.wireguard?.bytes_tx));
        $('received').textContent = bytes(sum(n => n.data.wireguard?.bytes_rx));
        $('dropped').textContent = count(sum(n => drops(n.data.packets)));
        $('update').textContent = `Actualizado · ${new Date(result.checked_at).toLocaleTimeString('es-MX')}`;
        $('error').hidden = true;
    } catch (error) {
        $('error').textContent = 'Actualización fallida · datos anteriores.';
        $('error').hidden = false;
        $('update').textContent = 'Actualización fallida';
        renderConfiguredNodes();
    } finally {
        clearTimeout(timer);
        busy = false;
        $('refresh').disabled = false;
        $('nodes').setAttribute('aria-busy','false');
    }
}
$('refresh').addEventListener('click', refresh);
$('auto').addEventListener('change', () => {if ($('auto').checked) refresh();});
setInterval(() => {if ($('auto').checked && !document.hidden) refresh();}, settings.refresh_seconds * 1000);
document.addEventListener('visibilitychange', () => {if (!document.hidden && $('auto').checked) refresh();});
refresh();
