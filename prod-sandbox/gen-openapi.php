<?php
/**
 * Generates ost-workflow/docs/openapi.json from the REAL route table (Router::table()).
 *   php gen-openapi.php <osticket app dir> > ../ost-workflow/docs/openapi.json
 * Paths, methods, path parameters, security, the Idempotency-Key requirement on writes, the standard error
 * responses and `x-policy` (the permission the plugin enforces). Request/response body schemas are described in
 * docs/endpoints/*.md; the generated document is the machine-readable route contract for client generators.
 */
$app = $argv[1] ?? (getenv('HOME') . '/development/ost-sandbox/app');
chdir($app . '/api');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_HOST'] = '127.0.0.1'; $_SERVER['SCRIPT_NAME'] = '/api/http.php';
ob_start();
require $app . '/api/api.inc.php';
ob_end_clean();

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
        'parameters' => array_map(function ($n) { return ['name' => $n, 'in' => 'path', 'required' => true, 'schema' => ['type' => in_array($n, ['uuid', 'hash', 'name'], true) ? 'string' : 'integer']]; }, $m[1]),
        'responses' => ['200' => ['description' => 'Success: {"data": …, "meta": …}'], 'default' => $err],
    ];
    if (!$r['auth']) $op['security'] = [];
    $write = $r['method'] !== 'GET' && $r['auth'] && $r['idem'] !== false;
    if ($write) {
        $op['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9-]{8,64}$'],
                               'description' => 'One per operation; a retry with the same key never repeats the effect.'];
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
