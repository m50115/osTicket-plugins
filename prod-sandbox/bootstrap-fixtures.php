<?php
/**
 * Reference-sandbox fixtures that only the SCP/model layer can create: items of the Installed Equipment list
 * (the API reads lists, it does not write them) and the plugin setting pointing at that list. Idempotent, sandbox only.
 *   php bootstrap-fixtures.php
 */
if (PHP_SAPI !== 'cli') exit(1);
$app = getenv('HOME') . '/development/ost-sandbox/app';
chdir($app . '/api');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/api/http.php'; $_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
require_once $app . '/api/api.inc.php';
require_once INCLUDE_DIR . 'class.list.php';
$list = DynamicList::objects()->filter(['name' => 'Installed Equipment'])->first();
if (!$list) { fwrite(STDERR, "run bootstrap-config.php first\n"); exit(1); }
$rows = [['Equipo A · SN-A1', 1], ['Equipo B · SN-B2', 1], ['Equipo C · SN-C3', 1], ['Equipo D · SN-D4', 1], ['Equipo R (retirado) · SN-R5', 0],
    ['Equipo E · SN-E6', 1], ['Equipo F · SN-F7', 1], ['Equipo G · SN-G8', 1], ['Equipo H · SN-H9', 1], ['Equipo R2 (retirado) · SN-R10', 0]];
foreach ($rows as $i => [$value, $status]) {
    if ($ex = DynamicListItem::objects()->filter(['list_id' => $list->getId(), 'value' => $value])->first()) {
        if ($ex->status != $status) { $ex->status = $status; $ex->save(true); }
        echo "exists: $value\n"; continue; }
    $item = DynamicListItem::create(['list_id' => $list->getId(), 'value' => $value, 'status' => $status, 'sort' => $i + 1,
        'extra' => 'BCW|1|S=SN-' . ($i + 1) . '|E=1']);
    $item->save(true);
    if ($item->status != $status) { $item->status = $status; $item->save(true); }
    echo "created #{$item->getId()}: $value (status $status)\n";
}
$plugin = Plugin::objects()->filter(['name' => 'Workflow API'])->first();
$c = $plugin->getInstances()->first()->getConfig();
$c->set('installed_equipment_list_id', (string) $list->getId());
echo "installed_equipment_list_id = {$list->getId()}\n";
