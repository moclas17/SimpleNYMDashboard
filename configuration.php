<?php
// Shared validation. Only server-side configuration can set destinations.
function dashboardConfig(): array
{
    try {
        $config = require __DIR__ . '/config.php';
        if (!is_array($config) || !is_array($config['nodes'] ?? null)
            || !is_int($config['refresh_seconds'] ?? null) || $config['refresh_seconds'] < 30) {
            throw new RuntimeException('Invalid configuration structure or refresh interval.');
        }
        $nodes = [];
        $identities = [];
        foreach ($config['nodes'] as $node) {
            if (!is_array($node) || !is_int($node['node_id'] ?? null) || $node['node_id'] < 1
                || !is_string($node['name'] ?? null) || trim($node['name']) === ''
                || !in_array($node['kind'] ?? null, ['gateway', 'mixnode'], true)
                || !is_string($node['identity'] ?? null) || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D', $node['identity'])) {
                throw new RuntimeException('Invalid node fields.');
            }
            $key = 'node' . $node['node_id'];
            if (isset($nodes[$key]) || isset($identities[$node['identity']])) throw new RuntimeException('Duplicate node.');
            $url = $node['api_url'] ?? null;
            if ($url !== null) {
                if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('Invalid API URL.');
                $parts = parse_url($url);
                if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])
                    || isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException('Invalid API URL components.');
                $url = rtrim($url, '/');
            }
            $nodes[$key] = $node + ['api_url' => null];
            $nodes[$key]['api_url'] = $url;
            $nodes[$key]['key'] = $key;
            $identities[$node['identity']] = true;
        }
        $config['nodes'] = $nodes;
        return $config;
    } catch (Throwable $e) {
        error_log('Nym configuration: ' . $e->getMessage());
        http_response_code(500);
        exit('Configuración inválida. Revisa config.php.');
    }
}
