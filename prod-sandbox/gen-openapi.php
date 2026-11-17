<?php
/**
 * Generates ost-workflow/docs/openapi.json from the REAL route table (Router::table()).
 *   php gen-openapi.php <osticket app dir> > ../ost-workflow/docs/openapi.json
 *   OW_SRC=<ost-workflow dir> php gen-openapi.php > ../ost-workflow/docs/openapi.json   (no osTicket: routes are static data)
 * Paths, methods, path parameters, security, the (optional, ignored) Idempotency-Key header on writes, the standard error
 * responses and `x-policy` (the permission the plugin enforces). Request/response body schemas are described in
 * docs/endpoints/*.md; the generated document is the machine-readable route contract for client generators.
 */
if (getenv('OW_SRC')) {
    $src = rtrim(getenv('OW_SRC'), '/') . '/lib/';
    spl_autoload_register(function ($c) use ($src) {
        if (strpos($c, 'OstWorkflow\\') === 0 && is_file($f = $src . str_replace('\\', '/', $c) . '.php')) require $f;
    });
} else {
    $app = $argv[1] ?? (getenv('HOME') . '/development/ost-sandbox/app');
    chdir($app . '/api');
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_HOST'] = '127.0.0.1'; $_SERVER['SCRIPT_NAME'] = '/api/http.php';
    ob_start();
    require $app . '/api/api.inc.php';
    ob_end_clean();
}

$ver = \OstWorkflow\Build::VERSION;
$errors = array_keys(\OstWorkflow\ApiError::CATALOG);
$err = ['description' => 'Typed error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
$paths = [];
foreach (\OstWorkflow\Router::table() as $r) {
    $path = preg_replace('/\(\?P<(\w+)>[^)]*\)/', '{$1}', $r['path']);
    preg_match_all('/\{(\w+)\}/', $path, $m);
    $tag = substr(strrchr($r['class'], '\\'), 1);
    $op = [
        'operationId' => lcfirst($tag) . ucfirst($r['action']) . '_' . strtolower($r['method']) . '_' . preg_replace('/\W+/', '_', trim($path, '/')),
        'tags' => [$tag],
        'x-policy' => $r['policy'] ?: 'auth',
        'parameters' => array_map(function ($n) { return ['name' => $n, 'in' => 'path', 'required' => true, 'schema' => ['type' => in_array($n, ['uuid', 'version_uuid', 'doc_uuid', 'hash', 'name'], true) ? 'string' : 'integer']]; }, $m[1]),
        'responses' => ['200' => ['description' => 'Success: {"data": …, "meta": …}'], 'default' => $err],
    ];
    if (!$r['auth']) $op['security'] = [];
    $write = $r['method'] !== 'GET' && $r['auth'] && $r['action'] !== 'logout';
    if ($write) {
        $op['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'deprecated' => true, 'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9-]{8,64}$'],
                               'description' => 'Accepted for compatibility, never required, never stored: the server keeps no replay ledger. A client that retries reconciles against the official state (GET /tickets, GET /match/ticket, the resource itself).'];
        $op['responses']['201'] = ['description' => 'Created'];
    }
    if (in_array($r['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true))
        $op['requestBody'] = ['required' => false, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];
    if ($r['class'] === 'OstWorkflow\\Handlers\\Catalogs' || $r['class'] === 'OstWorkflow\\Handlers\\Forms')
        $op['responses']['304'] = ['description' => 'Not modified (If-None-Match)'];
    $paths[$path][strtolower($r['method'])] = $op;
}
ksort($paths);
echo json_encode([
    'openapi' => '3.0.3',
    'info' => ['title' => 'ost-workflow', 'version' => $ver, 'description' => 'osTicket 1.17.2 plugin ost:workflow. Conventions: docs/wiki/Convenciones.md. Bodies: docs/endpoints/*.md.'],
    'servers' => [['url' => '/api/workflow/v1']],
    'security' => [['bearer' => []]],
    'components' => [
        'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
        'schemas' => ['Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'required' => ['code', 'message'],
            'properties' => ['code' => ['type' => 'string', 'enum' => $errors], 'message' => ['type' => 'string'], 'field' => ['type' => 'string'], 'details' => ['type' => 'object']]]]]],
    ],
    'paths' => $paths,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
