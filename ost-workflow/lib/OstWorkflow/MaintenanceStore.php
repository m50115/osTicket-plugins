<?php
namespace OstWorkflow;

/**
 * Storage primitives of the ScheduledMaintenance store (OW-REQ-70, module 8). The production implementation is
 * MaintenanceListStore (one dedicated native Custom List; no plugin table); prod-sandbox/maintenance-unit.php drives the
 * same engine with an in-memory implementation to prove the compare-and-swap protocol without a server.
 *
 * Every write the engine relies on is ONE of these:
 *   casRegistry / cas  single-row conditional UPDATE (the row still holds exactly what was read) -> the atomic commit point
 *   insert             a new, not yet referenced item (a journal or an empty plan): invisible until a swap references it
 *   orphan             only on items that no committed state references (an attempt's own journal)
 */
interface MaintenanceStore {
    /** Raw registry text (the dedicated list's own `configuration`); throws not_configured when the list is unusable. */
    function registry();

    /** @return bool true when the registry still held $old and now holds $new */
    function casRegistry($old, $new);

    /** @return array [item id => raw properties] for the ids that exist (any list association; never filtered by status) */
    function items(array $ids);

    /** Like items(), restricted to items whose properties contain $needle (the engine re-checks the decoded content). */
    function itemsContaining(array $ids, $needle);

    /** Creates an internal, not enabled item of the store list. @return int the new item id */
    function insert($value, $raw);

    /** @return bool true when the item still held $old and now holds $new */
    function cas($id, $old, $new);

    /** Best-effort mark of a journal that ended unreferenced (forensics only: readers never follow it). */
    function orphan($id);

    /** May an EMPTY registry be adopted? (the list holds no store item and feeds no form field) */
    function adoptable();

    /** Current UTC time 'Y-m-d H:i:s.uuuuuu' from the single database clock. */
    function now();
}
