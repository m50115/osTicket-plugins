<?php
namespace OstWorkflow;

/**
 * Pure rules of the Contracts store (OW-REQ-71, module 10): normalization of a version body, the canonical `terms_hash`,
 * the applicable period of a version (CT-D2), the overlap rule (CT-D8) and the entitlement resolution algorithm
 * (CT-E2 RESOLUTION_ALGORITHM, nine outcomes). No database, no request: Contracts and the handler feed it arrays, so every
 * rule here is deterministic and testable on its own.
 *
 * A version body (the immutable "terms") is stored normalized as
 *   {coverage, entitlements[5], response_policy, catalog_version_ref}
 * and its dates/kind/supersedes live in columns. `native_sla_ref` is part of response_policy but NOT of the hash (CT-D6).
 */
final class ContractTerms {
    const HASH_SCHEMA = 1;
    const LINES = ['REMOTE_SUPPORT', 'PREVENTIVE_MAINTENANCE', 'ONSITE_LABOR', 'GENERAL_PARTS', 'SPECIALTY_PARTS'];
    const TREATMENTS = ['INCLUDED_UNLIMITED', 'INCLUDED_QUANTITY', 'BILLABLE', 'EXCLUDED'];
    const PERIODS = ['CALENDAR_YEAR', 'CONTRACT_YEAR'];
    const KINDS = ['INITIAL', 'RENEWAL', 'AMENDMENT', 'CORRECTION'];
    const SCHEDULES = ['24X7', 'LIMITED'];
    const RESULTS = ['COVERED', 'NO_CONTRACT', 'NOT_IN_SCOPE', 'NOT_INCLUDED', 'EXPIRED', 'TERMINATED', 'ORG_MISMATCH', 'AMBIGUOUS', 'EQUIPMENT_REQUIRED'];
    const MAX_IDS = 5000;

    // ------------------------------------------------------------------ dates

    /** 'YYYY-MM-DD' (a real calendar date, year >= 1000) or null. */
    static function date($v) {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1000)
            return null;
        return $v;
    }

    static function addDays($date, $n) {
        return gmdate('Y-m-d', strtotime($date . ' 00:00:00 UTC') + $n * 86400);
    }

    // ------------------------------------------------------------------ normalization (shape only; completeness is checked at activation)

    private static function bad($msg, $field) { return ApiError::validation($msg, $field); }

    private static function idList($v, $field) {
        if (!is_array($v) || ($v && array_keys($v) !== range(0, count($v) - 1)))
            throw self::bad("'$field' must be a list of equipment ids", $field);
        if (count($v) > self::MAX_IDS)
            throw self::bad("'$field' has more than " . self::MAX_IDS . ' ids', $field);
        $out = [];
        foreach ($v as $id) {
            if (!is_int($id) || $id < 1)
                throw self::bad("'$field' must contain positive integer equipment ids", $field);
            $out[$id] = true;
        }
        $ids = array_keys($out);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private static function only(array $in, array $allowed, $field) {
        foreach ($in as $k => $_)
            if (!in_array($k, $allowed, true))
                throw self::bad("Unknown key '$k' in '$field'", $field);
    }

    static function normalizeCoverage($c) {
        if ($c === null) return null;
        if (!is_array($c) || !isset($c['mode']))
            throw self::bad("'coverage' must be {mode: LISTED|ORG_ALL, ...}", 'coverage');
        if ($c['mode'] === 'LISTED') {
            self::only($c, ['mode', 'equipment_ids'], 'coverage');
            return ['mode' => 'LISTED', 'equipment_ids' => self::idList($c['equipment_ids'] ?? [], 'coverage.equipment_ids')];
        }
        if ($c['mode'] === 'ORG_ALL') {
            self::only($c, ['mode', 'excluded_ids'], 'coverage');
            return ['mode' => 'ORG_ALL', 'excluded_ids' => self::idList($c['excluded_ids'] ?? [], 'coverage.excluded_ids')];
        }
        throw self::bad("'coverage.mode' must be LISTED or ORG_ALL", 'coverage.mode');
    }

    /** Always the five lines in the fixed order; a missing line is EXCLUDED ("No incluido"). */
    static function normalizeEntitlements($e) {
        if ($e === null) $e = [];
        if (!is_array($e) || ($e && array_keys($e) !== range(0, count($e) - 1)))
            throw self::bad("'entitlements' must be a list of {service_line, treatment, ...}", 'entitlements');
        $by = [];
        foreach ($e as $row) {
            if (!is_array($row) || !isset($row['service_line']) || !in_array($row['service_line'], self::LINES, true))
                throw self::bad("each entitlement needs a 'service_line' of " . implode('|', self::LINES), 'entitlements');
            $line = $row['service_line'];
            if (isset($by[$line]))
                throw self::bad("service line $line appears twice", 'entitlements');
            self::only($row, ['service_line', 'treatment', 'quantity', 'period_basis', 'exclusions_text'], 'entitlements');
            $t = $row['treatment'] ?? null;
            if (!in_array($t, self::TREATMENTS, true))
                throw self::bad("'treatment' of $line must be one of " . implode('|', self::TREATMENTS), 'entitlements');
            $q = $row['quantity'] ?? null; $p = $row['period_basis'] ?? null;
            if ($t === 'INCLUDED_QUANTITY' && $line !== 'PREVENTIVE_MAINTENANCE')
                throw self::bad('INCLUDED_QUANTITY applies only to PREVENTIVE_MAINTENANCE', 'entitlements');
            if ($t !== 'INCLUDED_QUANTITY' && ($q !== null || $p !== null))
                throw self::bad("'quantity' and 'period_basis' apply only to INCLUDED_QUANTITY", 'entitlements');
            if ($q !== null && (!is_int($q) || $q < 1 || $q > 1000))
                throw self::bad("'quantity' must be an integer between 1 and 1000", 'entitlements');
            if ($p !== null && !in_array($p, self::PERIODS, true))
                throw self::bad("'period_basis' must be CALENDAR_YEAR or CONTRACT_YEAR", 'entitlements');
            $x = $row['exclusions_text'] ?? null;
            if ($x !== null && (!is_string($x) || mb_strlen($x) > 200))
                throw self::bad("'exclusions_text' must be text of at most 200 characters", 'entitlements');
            $by[$line] = ['service_line' => $line, 'treatment' => $t, 'quantity' => $q, 'period_basis' => $p,
                          'exclusions_text' => ($x === null || trim($x) === '') ? null : trim($x)];
        }
        $out = [];
        foreach (self::LINES as $line)
            $out[] = $by[$line] ?? ['service_line' => $line, 'treatment' => 'EXCLUDED', 'quantity' => null, 'period_basis' => null, 'exclusions_text' => null];
        return $out;
    }

    static function normalizePolicy($p) {
        if ($p === null) return null;
        if (!is_array($p))
            throw self::bad("'response_policy' must be {tier_code, schedule, response_objective_hours, native_sla_ref?} or null", 'response_policy');
        self::only($p, ['tier_code', 'schedule', 'response_objective_hours', 'native_sla_ref'], 'response_policy');
        $tier = $p['tier_code'] ?? null;
        if (!is_string($tier) || trim($tier) === '' || mb_strlen(trim($tier)) > 40)
            throw self::bad("'response_policy.tier_code' must be short text (1-40)", 'response_policy.tier_code');
        $s = $p['schedule'] ?? null;
        if (!is_array($s) || !in_array($s['type'] ?? null, self::SCHEDULES, true))
            throw self::bad("'response_policy.schedule' must be {type: 24X7|LIMITED, description?}", 'response_policy.schedule');
        self::only($s, ['type', 'description'], 'response_policy.schedule');
        $d = $s['description'] ?? null;
        if ($d !== null && (!is_string($d) || mb_strlen($d) > 120))
            throw self::bad("'response_policy.schedule.description' must be text of at most 120 characters", 'response_policy.schedule');
        $h = $p['response_objective_hours'] ?? null;
        if (!is_int($h) || $h < 1 || $h > 8760)
            throw self::bad("'response_policy.response_objective_hours' must be an integer between 1 and 8760", 'response_policy.response_objective_hours');
        $n = $p['native_sla_ref'] ?? null;
        if ($n !== null && (!is_int($n) || $n < 1))
            throw self::bad("'response_policy.native_sla_ref' must be an osTicket SLA id or null", 'response_policy.native_sla_ref');
        return ['tier_code' => trim($tier), 'schedule' => ['type' => $s['type'], 'description' => ($d === null || trim($d) === '') ? null : trim($d)],
                'response_objective_hours' => $h, 'native_sla_ref' => $n];
    }

    /**
     * Shape of a version as sent by the client (PUT draft / POST contract).
     * @return array {kind, supersedes, effective_from, effective_to, body}
     */
    static function normalizeVersion(array $in) {
        self::only($in, ['kind', 'supersedes', 'effective_from', 'effective_to', 'coverage', 'entitlements', 'response_policy', 'catalog_version_ref'], 'version');
        $from = self::date($in['effective_from'] ?? null);
        $to = self::date($in['effective_to'] ?? null);
        if ($from === null) throw self::bad("'effective_from' must be a valid date YYYY-MM-DD", 'effective_from');
        if ($to === null) throw self::bad("'effective_to' must be a valid date YYYY-MM-DD", 'effective_to');
        if ($from > $to) throw self::bad("'effective_from' cannot be after 'effective_to'", 'effective_from');
        $kind = $in['kind'] ?? null;
        if ($kind !== null && !in_array($kind, self::KINDS, true))
            throw self::bad("'kind' must be one of " . implode('|', self::KINDS), 'kind');
        $sup = $in['supersedes'] ?? null;
        if ($sup !== null && (!is_string($sup) || !preg_match(Documents::UUID, $sup)))
            throw self::bad("'supersedes' must be a contract_version_ref", 'supersedes');
        $cat = $in['catalog_version_ref'] ?? null;
        if ($cat !== null && (!is_string($cat) || !preg_match(Documents::UUID, $cat)))
            throw self::bad("'catalog_version_ref' must be a catalog_version_ref", 'catalog_version_ref');
        return [
            'kind' => $kind, 'supersedes' => $sup === null ? null : strtolower($sup),
            'effective_from' => $from, 'effective_to' => $to,
            'body' => [
                'coverage' => self::normalizeCoverage($in['coverage'] ?? null),
                'entitlements' => self::normalizeEntitlements($in['entitlements'] ?? null),
                'response_policy' => self::normalizePolicy($in['response_policy'] ?? null),
                'catalog_version_ref' => $cat === null ? null : strtolower($cat),
            ],
        ];
    }

    /** Rules a version must satisfy to be activated (V7, V9, V10). @return string[] problems (empty = complete) */
    static function incomplete(array $body) {
        $p = [];
        $c = $body['coverage'];
        if ($c === null) $p[] = 'coverage is required';
        elseif ($c['mode'] === 'LISTED' && !$c['equipment_ids']) $p[] = 'LISTED coverage needs at least one equipment (V7)';
        $any = false;
        foreach ($body['entitlements'] as $e) {
            if ($e['treatment'] !== 'EXCLUDED') $any = true;
            if ($e['treatment'] === 'INCLUDED_QUANTITY' && ($e['quantity'] === null || $e['period_basis'] === null))
                $p[] = 'preventive maintenance by quantity needs quantity and period_basis (V10)';
        }
        if (!$any) $p[] = 'at least one service line must be other than EXCLUDED (V9)';
        return $p;
    }

    // ------------------------------------------------------------------ terms_hash

    private static function ksortDeep($v) {
        if (!is_array($v)) return $v;
        $isList = $v === [] || array_keys($v) === range(0, count($v) - 1);
        if (!$isList) ksort($v, SORT_STRING);
        foreach ($v as $k => $x) $v[$k] = self::ksortDeep($x);
        return $v;
    }

    /** The exact document hashed (CT-D1, F-E4-03): documents, native_sla_ref, kind, supersedes, authorship and state are NOT in it. */
    static function hashDocument($from, $to, array $body) {
        $c = $body['coverage'];
        $cov = $c['mode'] === 'LISTED' ? ['mode' => 'LISTED', 'equipment_ids' => $c['equipment_ids']] : ['mode' => 'ORG_ALL', 'excluded_ids' => $c['excluded_ids']];
        $ent = [];
        foreach ($body['entitlements'] as $e)
            $ent[] = ['service_line' => $e['service_line'], 'treatment' => $e['treatment'], 'quantity' => $e['quantity'],
                      'period_basis' => $e['period_basis'], 'exclusions_text' => $e['exclusions_text']];
        $rp = null;
        if ($body['response_policy']) {
            $r = $body['response_policy'];
            $rp = ['tier_code' => $r['tier_code'], 'schedule' => $r['schedule'], 'response_objective_hours' => $r['response_objective_hours']];
        }
        return ['hash_schema' => self::HASH_SCHEMA, 'effective_from' => $from, 'effective_to' => $to, 'coverage' => $cov,
                'entitlements' => $ent, 'response_policy' => $rp, 'catalog_version_ref' => $body['catalog_version_ref']];
    }

    /** sha256 of the canonical serialization (sorted keys, no spaces, UTF-8, ISO dates, ascending id lists). */
    static function hash($from, $to, array $body) {
        return hash('sha256', json_encode(self::ksortDeep(self::hashDocument($from, $to, $body)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ------------------------------------------------------------------ applicable period (CT-D2) and overlap (CT-D8)

    /**
     * applicable_to = min(effective_to, next.effective_from - 1 day, terminated_on - 1 day) for every EFFECTIVE version of ONE
     * contract. @param array[] $versions {id, effective_from, effective_to} @return array id => [from, to] | null (empty period)
     */
    static function ranges(array $versions, $terminatedOn) {
        usort($versions, function ($a, $b) { return strcmp($a['effective_from'], $b['effective_from']); });
        $out = [];
        foreach ($versions as $i => $v) {
            $end = $v['effective_to'];
            if (isset($versions[$i + 1])) $end = min($end, self::addDays($versions[$i + 1]['effective_from'], -1));
            if ($terminatedOn !== null) $end = min($end, self::addDays($terminatedOn, -1));
            $out[$v['id']] = $end >= $v['effective_from'] ? [$v['effective_from'], $end] : null;
        }
        return $out;
    }

    static function claims(array $body, $line) {
        foreach ($body['entitlements'] as $e)
            if ($e['service_line'] === $line) return $e['treatment'] !== 'EXCLUDED';
        return false;
    }

    static function treatment(array $body, $line) {
        foreach ($body['entitlements'] as $e)
            if ($e['service_line'] === $line) return $e['treatment'];
        return 'EXCLUDED';
    }

    static function covers(array $cov, $equipmentId) {
        return $cov['mode'] === 'LISTED' ? in_array($equipmentId, $cov['equipment_ids'], true) : !in_array($equipmentId, $cov['excluded_ids'], true);
    }

    static function scopesIntersect(array $a, array $b) {
        if ($a['mode'] === 'ORG_ALL' && $b['mode'] === 'ORG_ALL') return true;
        if ($a['mode'] === 'LISTED' && $b['mode'] === 'LISTED') return (bool) array_intersect($a['equipment_ids'], $b['equipment_ids']);
        $l = $a['mode'] === 'LISTED' ? $a : $b;
        $o = $a['mode'] === 'LISTED' ? $b : $a;
        return (bool) array_diff($l['equipment_ids'], $o['excluded_ids']);
    }

    /**
     * Overlaps between EFFECTIVE versions of DIFFERENT contracts of one organization: same claimed line, intersecting scope,
     * intersecting applicable days. @param array[] $items {contract_ref, contract_version_ref, from, to, body} (period already derived)
     * @return array[] conflicts {service_line, from, to, a:{...}, b:{...}}
     */
    static function overlaps(array $items) {
        $out = [];
        $n = count($items);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $items[$i]; $b = $items[$j];
                if ($a['contract_ref'] === $b['contract_ref']) continue;
                $from = max($a['from'], $b['from']); $to = min($a['to'], $b['to']);
                if ($from > $to) continue;
                if (!$a['body']['coverage'] || !$b['body']['coverage'] || !self::scopesIntersect($a['body']['coverage'], $b['body']['coverage'])) continue;
                foreach (self::LINES as $line) {
                    if (self::claims($a['body'], $line) && self::claims($b['body'], $line))
                        $out[] = ['service_line' => $line, 'from' => $from, 'to' => $to,
                                  'a' => ['contract_ref' => $a['contract_ref'], 'contract_version_ref' => $a['contract_version_ref']],
                                  'b' => ['contract_ref' => $b['contract_ref'], 'contract_version_ref' => $b['contract_version_ref']]];
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ resolution (RESOLUTION_ALGORITHM)

    /**
     * Entitlement of (organization O, optional equipment E, line L) on local day $d, from the versions of O's contracts.
     * @param array[] $versions EFFECTIVE versions of the ACTIVE/CANCELLED contracts of O:
     *        {contract_ref, contract_version_ref, effective_from, effective_to, terminated_on|null, terms_hash, body}
     * @param callable $orgMismatch fn(): bool - is E named by an applicable LISTED version of a contract of ANOTHER organization?
     */
    static function resolve($d, $line, $equipmentId, array $versions, $orgMismatch) {
        // Applicable version of each contract on $d (CT-D2): greatest effective_from <= d, and d <= its effective_to.
        $byContract = [];
        foreach ($versions as $v) $byContract[$v['contract_ref']][] = $v;
        $applicable = []; $terminated = null; $endedOn = null; $any = false;
        foreach ($byContract as $vs) {
            usort($vs, function ($a, $b) { return strcmp($a['effective_from'], $b['effective_from']); });
            $ranges = self::ranges(array_map(function ($v) { return ['id' => $v['contract_version_ref'], 'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to']]; }, $vs), $vs[0]['terminated_on']);
            $pick = null;
            foreach ($vs as $v) if ($v['effective_from'] <= $d) $pick = $v;
            foreach ($vs as $v) {
                if ($v['effective_from'] <= $d) $any = true;
                $r = $ranges[$v['contract_version_ref']];
                if ($r && $r[1] < $d && ($endedOn === null || $r[1] > $endedOn)) $endedOn = $r[1];
            }
            if (!$pick || $d > $pick['effective_to']) continue;
            if ($pick['terminated_on'] !== null && $d >= $pick['terminated_on'])
                $terminated = $terminated === null || $pick['terminated_on'] > $terminated['terminated_on'] ? $pick : $terminated;
            else
                $applicable[] = $pick;
        }
        $base = ['result' => null, 'contract_ref' => null, 'contract_version_ref' => null, 'terms_hash' => null, 'treatment' => null,
                 'effective_from' => null, 'effective_to' => null, 'tier_code' => null, 'response_objective_hours' => null, 'schedule' => null,
                 'catalog_version_ref' => null, 'native_sla_ref' => null, 'terminated_on' => null, 'ended_on' => null, 'candidates' => []];
        $with = function ($result, array $v = null, $extra = []) use ($base, $line) {
            $r = array_merge($base, ['result' => $result], $extra);
            if ($v) {
                $rp = $v['body']['response_policy'];
                $r = array_merge($r, ['contract_ref' => $v['contract_ref'], 'contract_version_ref' => $v['contract_version_ref'], 'terms_hash' => $v['terms_hash'],
                    'treatment' => self::treatment($v['body'], $line), 'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'],
                    'tier_code' => $rp ? $rp['tier_code'] : null, 'response_objective_hours' => $rp ? $rp['response_objective_hours'] : null,
                    'schedule' => $rp ? $rp['schedule'] : null, 'native_sla_ref' => $rp ? $rp['native_sla_ref'] : null,
                    'catalog_version_ref' => $v['body']['catalog_version_ref']]);
            }
            return $r;
        };

        if (!$applicable) {
            if ($terminated) return $with('TERMINATED', null, ['contract_ref' => $terminated['contract_ref'], 'terminated_on' => $terminated['terminated_on']]);
            if ($any) return $with('EXPIRED', null, ['ended_on' => $endedOn]);
            return $with('NO_CONTRACT');
        }
        if ($equipmentId !== null) {
            $scope = array_values(array_filter($applicable, function ($v) use ($equipmentId) { return $v['body']['coverage'] && self::covers($v['body']['coverage'], $equipmentId); }));
            if (!$scope)
                return $with($orgMismatch() ? 'ORG_MISMATCH' : 'NOT_IN_SCOPE');
            $claim = array_values(array_filter($scope, function ($v) use ($line) { return self::claims($v['body'], $line); }));
            if (!$claim)
                return $with('NOT_INCLUDED', count($scope) === 1 ? $scope[0] : null);
            if (count($claim) === 1)
                return $with('COVERED', $claim[0]);
            return $with('AMBIGUOUS', null, ['candidates' => array_map(function ($v) { return ['contract_ref' => $v['contract_ref'], 'contract_version_ref' => $v['contract_version_ref']]; }, $claim)]);
        }
        $claim = array_values(array_filter($applicable, function ($v) use ($line) { return self::claims($v['body'], $line); }));
        if (!$claim)
            return $with('NOT_INCLUDED', count($applicable) === 1 ? $applicable[0] : null);
        $c = $claim[0]['body']['coverage'];
        if (count($claim) === 1 && $c && $c['mode'] === 'ORG_ALL' && !$c['excluded_ids'])
            return $with('COVERED', $claim[0]);
        return $with('EQUIPMENT_REQUIRED');
    }
}
