<?php
namespace OstWorkflow\Handlers;

use OstWorkflow\ApiError;
use OstWorkflow\Contacts;
use OstWorkflow\Request;
use OstWorkflow\Res;

/**
 * Custom Lists of osTicket, read-only (OW-REQ-63). osTicket stays the authority: no table, no index, no write.
 * The plugin reads the core's own `DynamicList` / `DynamicListItem` models the way the SCP does (scp/lists.php).
 *
 * Only CUSTOM lists are exposed (`type` IS NULL): the core's typed lists (e.g. `ticket-status`) have their own
 * semantics and endpoints (`/statuses`) and are not a catalog. Policy `auth`: the SCP shows every list to any agent
 * with access to the admin panel; here every logged-in agent reads the catalogs the app needs (EQ-D3, accepted).
 * Never exposed: `notes`, translations, `masks`, configuration forms, and any write or list administration.
 *
 * Reads never create anything: the list's property form is looked up with `getConfigurationForm(false)`
 * (`getForm()` would auto-create it, a write).
 */
final class Lists {
    const LIMIT_DEFAULT = 100;
    const LIMIT_MAX = 500;
    const Q_MIN = 2;
    const Q_MAX = 100;

    static function routes() {
        return [
            ['GET', '/lists',                              'lists', ['policy' => 'auth']],
            ['GET', '/lists/(?P<id>\d+)/items',            'items', ['policy' => 'auth']],
        ];
    }

    private static function load() {
        require_once(INCLUDE_DIR . 'class.list.php');
        require_once(INCLUDE_DIR . 'class.dynamic_forms.php');
    }

    /** A custom list by id, or 404 (typed lists such as ticket-status are not custom lists). */
    private static function custom($id) {
        self::load();
        $l = \DynamicList::lookup((int) $id);
        if (!$l || $l->type)
            throw ApiError::notFound('list');
        return $l;
    }

    /** The list's item properties as a schema: [{name, label, type}] in form order. No write: no auto-created form. */
    private static function schema(\DynamicList $l) {
        $form = $l->getConfigurationForm(false);
        $out = [];
        if ($form)
            foreach ($form->getFields() as $f)
                $out[] = ['name' => (string) $f->get('name'), 'label' => (string) $f->get('label'), 'type' => (string) $f->get('type')];
        return $out;
    }

    /**
     * GET /lists?limit=&cursor=
     * Custom lists by id: `id, name, name_plural, sort_mode, item_count, properties[]` (the property schema).
     */
    static function lists(Request $req) {
        self::load();
        $limit = $req->intQuery('limit', self::LIMIT_DEFAULT, 1, self::LIMIT_MAX);
        $after = Contacts::cursorId($req);
        $qs = \DynamicList::objects()->filter(['type__isnull' => true]);
        if ($after) $qs = $qs->filter(['id__gt' => $after]);
        $items = []; $more = false; $last = 0;
        foreach ($qs->order_by('id')->limit($limit + 1) as $l) {
            if (count($items) >= $limit) { $more = true; break; }
            $items[] = [
                'id'          => (int) $l->getId(),
                'name'        => (string) $l->name,
                'name_plural' => (string) ($l->name_plural ?: $l->name),
                'sort_mode'   => (string) $l->getSortMode(),
                'item_count'  => (int) $l->getItemCount(),
                'properties'  => self::schema($l),
            ];
            $last = (int) $l->getId();
        }
        return Res::page($items, $more ? Contacts::nextCursor($last) : null);
    }

    /**
     * GET /lists/{id}/items?q=&include_disabled=&limit=&cursor=
     * Items by id. `q` is the core's own search (`DynamicList::search`: value starts with, abbreviation contains, or a
     * property contains it). `include_disabled=1` also returns retired items (needed to resolve historical references);
     * the default is enabled items only. `properties` = {logical field name: {label, value}} (the form field's `name`,
     * not only its label). `meta.version` = SHA-1 of the whole list (every item, every property, enabled or not), so a
     * cache can be validated without paging; the response is also ETag-revalidated like the catalogs.
     */
    static function items(Request $req) {
        $l = self::custom($req->intParam('id'));
        $limit = $req->intQuery('limit', self::LIMIT_DEFAULT, 1, self::LIMIT_MAX);
        $after = Contacts::cursorId($req);
        $inc = $req->q('include_disabled');
        if ($inc !== null && !in_array($inc, ['0', '1'], true))
            throw ApiError::validation("'include_disabled' must be 0 or 1", 'include_disabled');
        $form = $l->getConfigurationForm(false);
        $fields = [];
        if ($form)
            foreach ($form->getForm()->getFields() as $f)
                $fields[] = $f;

        // The core's getAllItems()/search() carry the list's own ordering and order_by() appends: keep id order for cursors.
        $qs = \DynamicListItem::objects()->filter(['list_id' => $l->getId()]);
        if (($q = $req->q('q')) !== null) {
            if (!is_string($q)) throw ApiError::validation("'q' must be a string", 'q');
            $q = trim($q);
            if (strlen($q) < self::Q_MIN)
                throw ApiError::validation("'q' must have at least " . self::Q_MIN . ' characters', 'q');
            if (strlen($q) > self::Q_MAX)
                throw ApiError::validation("'q' is too long (max " . self::Q_MAX . ')', 'q');
            $qs = $qs->filter(\Q::any([
                'value__startswith'    => $q,
                'extra__contains'      => $q,
                'properties__contains' => '"' . $q,
            ]));   // the same three predicates as DynamicList::search()
        }
        if ($inc !== '1')
            $qs = $qs->filter(['status__hasbit' => \DynamicListItem::ENABLED]);
        if ($after) $qs = $qs->filter(['id__gt' => $after]);

        $items = []; $more = false; $last = 0;
        foreach ($qs->order_by('id')->limit($limit + 1) as $it) {
            if (count($items) >= $limit) { $more = true; break; }
            $items[] = self::item($it, $fields);
            $last = (int) $it->getId();
        }
        return Res::page($items, $more ? Contacts::nextCursor($last) : null, ['version' => self::version($l, $fields)]);
    }

    private static function item(\DynamicListItem $it, array $fields) {
        $cfg = $it->getConfiguration();
        $props = [];
        foreach ($fields as $f) {
            $name = (string) $f->get('name');
            if ($name === '' || !array_key_exists($f->get('id'), $cfg)) continue;
            $v = $f->to_php($cfg[$f->get('id')]);
            $props[$name] = ['label' => (string) $f->get('label'), 'value' => (string) $f->toString($v)];
        }
        $abbr = trim((string) $it->get('extra'));
        return [
            'id'         => (int) $it->getId(),
            'value'      => (string) $it->get('value'),
            'extra'      => $abbr !== '' ? $abbr : null,
            'enabled'    => (bool) $it->isEnabled(),
            'internal'   => (bool) $it->isInternal(),
            'sort'       => (int) $it->get('sort'),
            'properties' => (object) $props,
        ];
    }

    /** SHA-1 over the whole list (enabled or not), ordered by id: validates a full cache with one request. */
    private static function version(\DynamicList $l, array $fields) {
        $h = hash_init('sha1');
        hash_update($h, (string) $l->getId() . '|' . (string) $l->name . "\n");
        foreach (\DynamicListItem::objects()->filter(['list_id' => $l->getId()])->order_by('id') as $it)
            hash_update($h, json_encode(self::item($it, $fields), JSON_UNESCAPED_UNICODE) . "\n");
        return hash_final($h);
    }
}
