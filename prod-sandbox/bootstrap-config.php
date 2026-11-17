<?php
/**
 * Reference-sandbox bootstrap: the lists, help topics and plugin settings the BestCare modules need, created through
 * osTicket's own model classes (the same code paths the SCP forms call). No SQL writes, no core changes, idempotent
 * (everything is looked up by exact name first). Sandbox only.
 *
 *   php bootstrap-config.php [--app=<osticket dir>]
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "cli only\n"); exit(1); }
$app = rtrim(getenv('HOME') . '/development/ost-sandbox/app', '/');
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--app=(.*)$/', $a, $m)) $app = rtrim($m[1], '/');
if (strpos(realpath($app) ?: '', '/ost-sandbox/') === false) { fwrite(STDERR, "refusing: not an ost-sandbox dir\n"); exit(1); }
chdir($app . '/api');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/api/http.php'; $_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
require_once $app . '/api/api.inc.php';
require_once INCLUDE_DIR . 'class.list.php';
require_once INCLUDE_DIR . 'class.topic.php';
require_once INCLUDE_DIR . 'class.dynamic_forms.php';

function say($m) { echo $m . "\n"; }

/** Custom list by exact name (created empty when missing); optional typed property fields (name => label). */
function ensure_list($name, array $props = []) {
    $l = DynamicList::objects()->filter(['name' => $name])->first();
    if ($l) { say("list '$name' exists (id {$l->getId()})"); return $l; }
    $l = DynamicList::create(['name' => $name, 'name_plural' => $name, 'sort_mode' => 'Alpha', 'masks' => 13,
        'notes' => 'BCW reference sandbox']);
    $l->save(true);
    $form = $l->getForm(true);
    $sort = 1;
    foreach ($props as $pname => $label) {
        $f = DynamicFormField::create(['form_id' => $form->getId(), 'type' => 'text', 'label' => $label, 'name' => $pname,
            'sort' => $sort++, 'flags' => 0x1 | 0x2 | 0x4 | 0x8 | 0x10 | 0x20 | 0x40 | 0x80, 'configuration' => '{"size":40,"length":0}']);
        $f->save();
    }
    say("list '$name' created (id {$l->getId()})");
    return $l;
}

/** Help topic by exact name. */
function ensure_topic($name, $deptId, $slaId = 0) {
    $t = Topic::objects()->filter(['topic' => $name])->first();
    if ($t) { say("topic '$name' exists (id {$t->getId()})"); return $t; }
    $errors = [];
    $t = Topic::create();
    $ok = $t->update(['id' => $t->getId(), 'status' => 'active', 'forms' => [], 'topic' => $name, 'ispublic' => 0, 'dept_id' => $deptId, 'sla_id' => $slaId,
        'priority_id' => 2, 'status_id' => 1, 'noautoresp' => 1, 'notes' => 'BCW reference sandbox', 'form_ids' => [], 'sequence_id' => 1], $errors);
    if (!$ok) { fwrite(STDERR, "topic '$name' FAILED " . json_encode($errors) . "\n"); return null; }
    say("topic '$name' created (id {$t->getId()})");
    return $t;
}

$equip  = ensure_list('Installed Equipment', ['location' => 'Location', 'ae_title' => 'AE Title', 'ip_address' => 'IP address',
    'dicom_port' => 'DICOM port', 'software_version' => 'Software version']);
$models = ensure_list('Equipment Models', ['manufacturer' => 'Manufacturer', 'modality' => 'Modality', 'model' => 'Model']);
$supp   = ensure_list('Suppliers', ['contact' => 'Contact', 'phone' => 'Phone', 'email' => 'Email', 'payment' => 'Payment', 'address' => 'Address']);
$store  = ensure_list('BCW Scheduled Maintenance (ost-workflow)');   // dedicated, empty: the M8 store

ensure_topic('BestCare Contracts', 1);
ensure_topic('BestCare Projects', 1);
ensure_topic('Preventive Maintenance', 3);
ensure_topic('Sales Quotes', 2);

// plugin instance settings (same namespace the plugin form writes)
$plugin = Plugin::objects()->filter(['name' => 'Workflow API'])->first();
$inst = $plugin ? $plugin->getInstances()->first() : null;
if (!$inst) { fwrite(STDERR, "plugin instance not found\n"); exit(1); }
$c = $inst->getConfig(); $ns = (object) ['namespace' => $c->getNamespace()];
$c->set('default_dept_id', '1'); $c->set('default_topic_id', '1');
$c->set('installed_equipment_list_id', (string) $equip->getId());
$c->set('maintenance_list_id', (string) $store->getId());
say("plugin settings set in {$ns->namespace}: equipment list {$equip->getId()}, maintenance store list {$store->getId()}");
