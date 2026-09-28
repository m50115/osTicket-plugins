<?php
namespace OstWorkflow;

/** DTOs, normalization and small helpers shared by the contact/organization/matching handlers. */
final class Contacts {
    const SUFFIXES = ['s a de c v', 'sa de cv', 's de rl de cv', 'sapi de cv', 's a p i de c v', 'sc', 's c', 'a c', 'ac', 'sas', 's a s',
                      'srl', 's r l', 'sa', 's a', 'inc', 'llc', 'ltd', 'corp', 'co', 'gmbh'];

    // ------------------------------------------------------------------
    // Normalization (Architecture §L: no fuzzy matching, just canonical forms)
    // ------------------------------------------------------------------

    static function norm($s) {
        $s = mb_strtolower(trim((string) $s), 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
                        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
                        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c']);
        $s = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $s)));
        return $s;
    }

    /** Name without trailing corporate suffixes ("Acme, S.A. de C.V." -> "acme"). */
    static function normOrg($s) {
        $n = self::norm($s);
        $suffixes = self::SUFFIXES;
        usort($suffixes, function ($a, $b) { return strlen($b) - strlen($a); });
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($suffixes as $suf) {
                if ($n === $suf) break;
                if (substr($n, -strlen($suf) - 1) === ' ' . $suf) {
                    $n = trim(substr($n, 0, -strlen($suf)));
                    $changed = true;
                    break;
                }
            }
        }
        return $n;
    }

    static function last10($phone) {
        $d = preg_replace('/\D+/', '', (string) $phone);
        return strlen($d) >= 10 ? substr($d, -10) : '';
    }

    // ------------------------------------------------------------------
    // Cursor by id (ascending)
    // ------------------------------------------------------------------

    static function cursorId(Request $req) {
        $c = $req->q('cursor');
        if ($c === null) return 0;
        $d = json_decode(Token::unb64($c), true);
        if (!is_array($d) || !isset($d['i']) || !is_int($d['i']))
            throw ApiError::validation('Invalid cursor', 'cursor');
        return $d['i'];
    }

    static function nextCursor($id) { return Token::b64(json_encode(['i' => (int) $id])); }

    // ------------------------------------------------------------------
    // DTOs
    // ------------------------------------------------------------------

    /**
     * Runs $fn as osTicket's staff forms expect: they read the request values from $_POST when the method is POST.
     * Scoped: both globals are restored afterwards.
     */
    static function asPost(array $vars, callable $fn) {
        $oldPost = $_POST; $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_POST = $vars; $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            return $fn();
        } finally {
            $_POST = $oldPost;
            if ($oldMethod === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $oldMethod;
        }
    }

    static function times($table, $idCol, $id) {
        $r = Store::row('SELECT created, updated FROM ' . $table . ' WHERE ' . $idCol . '=' . (int) $id);
        return ['created' => Time::iso($r['created'] ?? null), 'updated' => Time::iso($r['updated'] ?? null)];
    }

    static function user(\User $u) {
        $fresh = Store::row('SELECT u.name, u.org_id, e.address FROM ' . USER_TABLE . ' u LEFT JOIN ' . USER_EMAIL_TABLE
            . ' e ON e.id=u.default_email_id WHERE u.id=' . (int) $u->getId());
        $org = !empty($fresh['org_id']) ? \Organization::lookup((int) $fresh['org_id']) : null;
        $emails = [];
        $q = Store::q('SELECT address FROM ' . USER_EMAIL_TABLE . ' WHERE user_id=' . (int) $u->getId() . ' ORDER BY id');
        while ($q && ($r = db_fetch_array($q))) $emails[] = (string) $r['address'];
        return array_merge([
            'id'    => (int) $u->getId(),
            'name'  => (string) ($fresh['name'] ?? $u->getName()),
            'email' => (string) ($fresh['address'] ?? $u->getEmail()),
            'emails'=> $emails,
            'phone' => (string) ($u->getPhoneNumber() ?: ''),
            'org'   => $org ? ['id' => (int) $org->getId(), 'name' => (string) $org->getName()] : null,
            'is_primary_contact' => (bool) $u->isPrimaryContact(),
            'has_account' => (bool) $u->hasAccount(),
        ], self::times(USER_TABLE, 'id', $u->getId()));
    }

    static function userBrief(\User $u) {
        return ['id' => (int) $u->getId(), 'name' => (string) $u->getName(), 'email' => (string) $u->getEmail(),
                'phone' => (string) ($u->getPhoneNumber() ?: '')];
    }

    static function org(\Organization $o) {
        $mgr = $o->getAccountManager();
        $id = $o->getAccountManagerId();
        $row = Store::row('SELECT extra FROM ' . ORGANIZATION_TABLE . ' WHERE id=' . (int) $o->getId());
        return array_merge([
            'id'      => (int) $o->getId(),
            'name'    => (string) $o->getName(),
            'domain'  => (string) $o->ht['domain'],
            'manager' => $id ? ['token' => (string) $id, 'name' => $mgr ? (string) ($mgr instanceof \Staff ? $mgr->getName()->getOriginal() : $mgr->getName()) : null] : null,
            'flags'   => [
                'collab_all_members'     => (bool) $o->autoAddMembersAsCollabs(),
                'collab_primary_contact' => (bool) $o->autoAddPrimaryContactsAsCollabs(),
                'assign_account_manager' => (bool) $o->autoAssignAccountManager(),
                'share_primary_contact'  => (bool) $o->shareWithPrimaryContacts(),
                'share_everybody'        => (bool) $o->shareWithEverybody(),
            ],
            'members_count' => (int) $o->getNumUsers(),
            'extra'   => $row['extra'] ?? null,
            'extra_hash' => hash('sha256', (string) ($row['extra'] ?? '')),
        ], self::times(ORGANIZATION_TABLE, 'id', $o->getId()));
    }

    /** Advisory warnings the app should show before touching an organization (Architecture §N). */
    static function orgWarnings(\Organization $o) {
        $w = [];
        if ($o->autoAddCollabs()) $w[] = 'auto_collaborators';
        if ($o->autoAssignAccountManager() && $o->getAccountManagerId()) $w[] = 'account_manager_auto_assign';
        return $w;
    }

    /** name=>value of the scalar answers of a set of DynamicFormEntry (used to rebuild a full POST-like vars array). */
    static function answerVars(array $entries) {
        $vars = [];
        foreach ($entries as $entry) {
            foreach ($entry->getAnswers() as $a) {
                $f = $a->getField();
                if (!$f) continue;
                $v = $a->getValue();
                if (is_object($v) || is_array($v)) $v = (string) $a->toString();
                if ($f->get('name')) $vars[$f->get('name')] = $v;
            }
        }
        return $vars;
    }

    /**
     * name=>raw value of the stored answers, read with SQL: instantiating the form entries first would make
     * osTicket ignore the request values afterwards (it caches loaded values on the field objects).
     * @param string $type 'U' user | 'O' organization
     */
    static function rawAnswers($type, $id) {
        $out = [];
        $q = Store::q('SELECT f.name, v.value FROM ' . FORM_ENTRY_TABLE . ' e JOIN ' . FORM_ANSWER_TABLE . ' v ON v.entry_id=e.id JOIN '
            . FORM_FIELD_TABLE . ' f ON f.id=v.field_id WHERE e.object_type=' . Store::esc($type) . ' AND e.object_id=' . (int) $id
            . ' AND f.name IS NOT NULL AND f.name<>\'\'');
        while ($q && ($r = db_fetch_array($q)))
            $out[$r['name']] = (string) $r['value'];
        return $out;
    }

    /** name=>displayed text of the answers (e.g. formatted phone numbers). */
    static function answerText(array $entries) {
        $out = [];
        foreach ($entries as $entry)
            foreach ($entry->getAnswers() as $a)
                if (($f = $a->getField()) && $f->get('name')) $out[$f->get('name')] = (string) $a->toString();
        return $out;
    }

    /** Form entries as {form,fields:[{id,name,label,type,editable,value}]}. */
    static function entries($entries) {
        $out = [];
        foreach ($entries as $entry) {
            $fields = [];
            foreach ($entry->getFields() as $f) {
                if (!$f->isVisibleToStaff()) continue;
                $a = $f->getAnswer();
                $fields[] = ['id' => (int) $f->get('id'), 'name' => $f->get('name'), 'label' => (string) $f->getLabel(),
                             'type' => $f->get('type'), 'editable' => (bool) $f->isEditableToStaff(),
                             'value' => $a ? (string) $a->toString() : ''];
            }
            $out[] = ['entry_id' => (int) $entry->get('id'), 'form_id' => (int) $entry->get('form_id'),
                      'title' => (string) $entry->getTitle(), 'fields' => $fields];
        }
        return $out;
    }

    static function note(\QuickNote $n) {
        $st = $n->getStaff();
        return ['id' => (int) $n->id, 'body' => (string) $n->body, 'staff' => $st ? ['id' => (int) $st->getId(), 'name' => $st->getName()->getOriginal()] : null,
                'created' => Time::iso($n->created), 'updated' => Time::iso(strpos((string) $n->updated, '0000-') === 0 ? null : $n->updated)];
    }
}
