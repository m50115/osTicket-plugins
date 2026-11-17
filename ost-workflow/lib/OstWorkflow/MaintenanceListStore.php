<?php
namespace OstWorkflow;

/**
 * Native storage of the ScheduledMaintenance store: ONE dedicated osTicket Custom List (plugin setting
 * `maintenance_list_id`, created empty by an administrator in Admin > Manage > Lists). No plugin table, no core schema
 * change, no SQL text on business data: everything goes through osTicket's ORM models DynamicList / DynamicListItem.
 *
 *   the list's `configuration`   registry {plans: equipment -> plan item id}   (custom lists never write this column after
 *                                creation: DynamicList::update() only touches name/name_plural/sort_mode/notes)
 *   one plan item per equipment  the state of every maintenance of that equipment (the atomic unit: uniqueness and CAS)
 *   one journal item per write   the audit entry (and the long texts) of one record; immutable once referenced
 *
 * Compare-and-swap = the ORM bulk update `filter(id, column = what was read)->update(...)` and its affected-row count.
 * Items are read by id whatever their list association or status, so an SCP "delete" (which only clears list_id,
 * class.list.php DynamicListItem::delete) or "disable" of an item never hides a record.
 */
final class MaintenanceListStore implements MaintenanceStore {
    const CHUNK = 500;
    const ST_INTERNAL = 2;          // DynamicListItem::INTERNAL, without ENABLED: never offered by a form dropdown

    private $listId;

    function __construct($listId) {
        $this->listId = (int) $listId;
        require_once(INCLUDE_DIR . 'class.list.php');
    }

    function registry() {
        // A fresh query every time (never DynamicList::lookup, whose identity map would keep serving the value read
        // before a concurrent swap and make the compare-and-swap retry forever).
        foreach (\DynamicList::objects()->filter(['id' => $this->listId])->values_flat('type', 'configuration')->limit(1) as $r) {
            if ($r[0] !== null && $r[0] !== '') break;
            return (string) $r[1];
        }
        throw new ApiError('not_configured', "The plugin setting 'maintenance_list_id' does not name a custom list");
    }

    function casRegistry($old, $new) {
        return (int) \DynamicList::objects()
            ->filter(['id' => $this->listId, 'type__isnull' => true, 'configuration' => (string) $old])
            ->update(['configuration' => (string) $new]) === 1;
    }

    function items(array $ids) {
        return $this->fetch($ids, null);
    }

    function itemsContaining(array $ids, $needle) {
        return $this->fetch($ids, (string) $needle);
    }

    private function fetch(array $ids, $needle) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $qs = \DynamicListItem::objects()->filter(['id__in' => $chunk]);
            if ($needle !== null) $qs = $qs->filter(['properties__contains' => $needle]);
            foreach ($qs->values_flat('id', 'properties') as $r)
                $out[(int) $r[0]] = $r[1] === null ? null : (string) $r[1];
        }
        return $out;
    }

    function insert($value, $raw) {
        $it = \DynamicListItem::create([
            'list_id' => $this->listId, 'status' => self::ST_INTERNAL, 'value' => (string) $value,
            'extra' => null, 'sort' => 1, 'properties' => (string) $raw,
        ]);
        if (!$it->save() || !$it->getId())
            throw new ApiError('internal_error', 'Unable to write the maintenance store');
        return (int) $it->getId();
    }

    function rewrite($id, $raw) {
        \DynamicListItem::objects()->filter(['id' => (int) $id])->update(['properties' => (string) $raw]);
    }

    function cas($id, $old, $new) {
        return (int) \DynamicListItem::objects()
            ->filter(['id' => (int) $id, 'properties' => (string) $old])
            ->update(['properties' => (string) $new]) === 1;
    }

    function orphan($id) {
        try {
            \DynamicListItem::objects()->filter(['id' => (int) $id])->update(['extra' => 'orphan']);
        } catch (\Throwable $t) {
            // forensics only
        }
    }

    function now() {
        $r = db_query('SELECT UTC_TIMESTAMP(6)');
        $v = $r ? db_result($r) : null;
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $v))
            throw new ApiError('internal_error', 'Unable to read the database clock');
        return $v;
    }
}
