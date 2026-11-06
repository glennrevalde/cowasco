<?php
/**
 * common/dataImportMappingHelpers.php
 *
 * Confirmed-rule registry, column catalogues, saved mapping profiles and
 * reconciliation totals for the Account Master / Billing Master importer.
 *
 * Rules live in data_import_mapping_rules (additive migration
 * database/migrations/20260924_audit_fixes_mysql55.sql). Every rule carries a
 * status:
 *   confirmed  - decided by the owner (24 Sep 2026 clarification answers)
 *   assumed    - the importer's current behaviour, shown as pending confirmation
 *   unresolved - rows that depend on it stay in Review; nothing financial is
 *                written for them
 * A rule that is missing from the table is treated as UNRESOLVED, never as a
 * silent default. Batches keep the rule snapshot they were validated with
 * (mapping_json / mapping_version) so a later change is visible and requires
 * revalidation before the affected rows can move.
 */

if (!function_exists('di_rule_catalog')) {
    function di_rule_catalog(): array
    {
        $catalog = [
            'paid_rule' => [
                'label' => 'What proves a Billing Master row was settled',
                'options' => [
                    'flag_receipt_date' => 'Payment Flag TRUE + Receipt Number + Date Paid (all three)',
                    'flag_only' => 'Payment Flag TRUE alone (missing receipt/date is a warning)',
                    'amount_only' => 'Any positive Amt Paid marks the bill paid',
                    'unresolved' => 'Undecided: keep every payment row in Review',
                ],
                'default' => 'flag_receipt_date',
                'default_status' => 'confirmed',
                'evidence' => '62,282 rows carry flag+receipt+date+amount; 3,229 rows have a positive Amt Paid with no flag, receipt or Date Paid; Cash Rcvd is 0 in all 69,878 rows.',
            ],
            'amt_paid_meaning' => [
                'label' => 'Meaning of Amt Paid',
                'options' => [
                    'tendered' => 'Cash tendered; the amount applied is min(Amt Paid, Total), the rest is change',
                    'applied' => 'Amount applied to the bill (an Amt Paid above Total is an excess to review)',
                    'unresolved' => 'Undecided: keep every payment row in Review',
                ],
                'default' => 'tendered',
                'default_status' => 'assumed',
                'evidence' => 'In 62,081 of the 62,300 flagged receipts Cash Change equals Amt Paid minus Total, and Amt Paid exceeds Total in 49,341 of them; this matches tendered cash, not an applied amount. Confirm before relying on it.',
            ],
            'penalty_compromise' => [
                'label' => 'Rows with both Penalty and Compromise Amt',
                'options' => [
                    'both' => 'Both are valid historical charges; import both on the same receipt (dedicated historical path)',
                    'compromise_replaces' => 'Compromise replaces Penalty (Penalty kept as source metadata)',
                    'penalty_replaces' => 'Penalty replaces Compromise (Compromise kept as source metadata)',
                    'unresolved' => 'Undecided: rows with both amounts stay in Review',
                ],
                'default' => 'both',
                'default_status' => 'confirmed',
                'evidence' => '19,241 rows carry both a positive Penalty and a positive Compromise Amt. The live cashier rule (one or the other) is unchanged.',
            ],
            'blank_optional_fee' => [
                'label' => 'Blank optional fee cells',
                'options' => [
                    'zero' => 'Blank means zero / not applicable for optional charge columns only',
                    'unknown' => 'Blank means unknown: rows with a blank optional fee stay in Review',
                ],
                'default' => 'zero',
                'default_status' => 'confirmed',
                'evidence' => '2,042 blank MonthlyAmortization cells (250 with payment evidence) and 164 blank DamayanContribution cells. Bill Amount and Total are never coerced.',
            ],
            'gl_policy' => [
                'label' => 'General ledger policy for imported history',
                'options' => [
                    'opening_balance' => 'Opening balances at the cut-over: the history goes to the water records; the bills and charges still open are posted to the ledger as opening invoices on the cut-over date (offset: Legacy Water Balances Clearing)',
                    'full_posting' => 'Full historical posting: every imported bill, recurring charge and receipt is posted to the ledger on its own date',
                    'unresolved' => 'Undecided: bill, charge and payment imports stay blocked',
                ],
                'default' => 'opening_balance',
                'default_status' => 'assumed',
                'evidence' => 'The Billing Master holds history from 2012: posting all of it would put twelve years of the former program\'s revenue and collections into the cooperative\'s books (and dates before its closing date are refused). Opening balances bring in only what is still owed, so the water receivables equal the ledger and the open bills are collected at the cashier. Duplicate posting is prevented by the WBILL-/RBILL-/COL- event keys and by the receipt uniqueness index.',
            ],
            'date_order' => [
                'label' => 'Source date convention',
                'options' => [
                    'mdy' => 'month/day/year (MM/DD/YYYY)',
                    'dmy' => 'day/month/year (DD/MM/YYYY)',
                    'unresolved' => 'Undecided: every dated row stays in Review',
                ],
                'default' => 'mdy',
                'default_status' => 'assumed',
                'evidence' => 'All 69,878 Pres Rdg Date values parse as MM/DD/YYYY (for example 10/02/2017); XLSX serial dates are converted explicitly. Malformed strings are rejected, never normalized.',
            ],
            'receipt_scope' => [
                'label' => 'Receipt number uniqueness',
                'options' => [
                    'global' => 'Receipt numbers are unique across all years/books; a reused number is a conflict for review',
                    'per_year' => 'Receipt numbers restart each year (identity = number + year of Date Paid)',
                    'unresolved' => 'Undecided: rows whose receipt already exists stay in Review',
                ],
                'default' => 'global',
                'default_status' => 'assumed',
                'evidence' => '79 receipt numbers repeat across 158 rows in the file. Existing receipts are matched by number and then compared on account, total and date before being treated as already imported.',
            ],
            'partial_payment' => [
                'label' => 'Amount applied below the bill total',
                'options' => [
                    'allocate_partial' => 'Record a partial payment (water principal, then recurring charges, then penalty, then compromise); the bill stays open',
                    'review' => 'Keep partial payments in Review',
                ],
                'default' => 'allocate_partial',
                'default_status' => 'assumed',
                'evidence' => '101 flagged receipts have Amt Paid below Total.',
            ],
            'negative_usage' => [
                'label' => 'Negative usage / present below previous',
                'options' => [
                    'review' => 'Keep in Review (meter change, rollover, reversal or bad data must be confirmed per row)',
                    'accept' => 'Accept the source readings and usage as recorded',
                ],
                'default' => 'review',
                'default_status' => 'assumed',
                'evidence' => '100 rows have negative Usage; 378 rows have Usage different from Present minus Previous.',
            ],
            'disconnection_meaning' => [
                'label' => 'Disconnection column',
                'options' => [
                    'scheduled' => 'Scheduled disconnection date (stored as the bill disconnection date)',
                    'actual' => 'Actual disconnection action (stored as the bill disconnection date; member status untouched)',
                ],
                'default' => 'scheduled',
                'default_status' => 'assumed',
                'evidence' => 'Retained on the bill as disconnection_date either way; member connection status is never changed by the importer.',
            ],
        ];
        $chargeMeta = [
            'Installation Fee' => 'one-time installation charge or a recurring fee',
            'Meter Instal Amt' => 'meter installment amount versus an installation charge/balance',
            'Recon Fee' => 'reconnection charge',
            'Fittings Fee' => 'fittings charge versus installment; inventory treatment',
            'Share Capital' => 'equity contribution (never ordinary fee income) — confirm before mapping',
            'Membership Fee' => 'membership charge timing and GL mapping',
            'Other Fees' => 'generic amount; obtain a breakdown/definition',
            'Water Meter' => 'meter sale, deposit, installment or other charge',
            'Funos' => 'organization-specific definition and accounting treatment',
            'MonthlyAmortization' => 'loan/amortization repayment versus a charge',
            'DamayanContribution' => 'contribution type, exemptions and GL mapping',
        ];
        foreach ($chargeMeta as $column => $question) {
            $catalog['charge:' . $column] = [
                'label' => 'Treatment of "' . $column . '"',
                'options' => [
                    'recurring_plan' => 'Historical recurring charge posted to the mapped recurring plan/bill code',
                    'unresolved' => 'Undecided: rows with a positive value stay in Review',
                ],
                'default' => 'recurring_plan',
                'default_status' => 'assumed',
                'evidence' => 'Open question: ' . $question . '. The plan mapping chosen at upload/batch level supplies the bill code and accounts.',
            ];
        }
        return $catalog;
    }
}

if (!function_exists('di_rules_table_ready')) {
    function di_rules_table_ready(PDO $pdo): bool
    {
        return function_exists('di_table_exists') && di_table_exists($pdo, 'data_import_mapping_rules');
    }
}

if (!function_exists('di_mapping_rules')) {
    /** All rules with their current value/status (request-cached). */
    function di_mapping_rules(PDO $pdo, bool $refresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$refresh) return $cache;
        $catalog = di_rule_catalog();
        $rows = [];
        if (di_rules_table_ready($pdo)) {
            try {
                foreach ($pdo->query('SELECT * FROM data_import_mapping_rules') as $row) $rows[(string)$row['rule_key']] = $row;
            } catch (Throwable $e) { $rows = []; }
        }
        $out = [];
        foreach ($catalog as $key => $definition) {
            $row = $rows[$key] ?? null;
            $value = $row ? (string)$row['rule_value'] : 'unresolved';
            $status = $row ? (string)$row['status'] : 'unresolved';
            if (!isset($definition['options'][$value])) { $value = 'unresolved'; $status = 'unresolved'; }
            if ($value === 'unresolved') $status = 'unresolved';
            $out[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'value' => $value,
                'value_label' => $definition['options'][$value] ?? $value,
                'status' => $status,
                'options' => $definition['options'],
                'evidence' => $definition['evidence'],
                'notes' => $row ? (string)($row['notes'] ?? '') : '',
                'confirmed_by' => $row ? (int)($row['confirmed_by'] ?? 0) : 0,
                'confirmed_at' => $row ? ($row['confirmed_at'] ?? null) : null,
                'version' => $row ? (int)($row['version'] ?? 1) : 0,
                'stored' => $row !== null,
            ];
        }
        return $cache = $out;
    }
}

if (!function_exists('di_rule')) {
    function di_rule(PDO $pdo, string $key): string
    {
        $rules = di_mapping_rules($pdo);
        return (string)($rules[$key]['value'] ?? 'unresolved');
    }
}

if (!function_exists('di_rules_snapshot')) {
    /** Compact key => value map plus a version hash for batch snapshots. */
    function di_rules_snapshot(PDO $pdo): array
    {
        $map = [];
        $version = 0;
        foreach (di_mapping_rules($pdo) as $key => $rule) {
            $map[$key] = ['value' => $rule['value'], 'status' => $rule['status']];
            $version += (int)$rule['version'];
        }
        ksort($map);
        return ['rules' => $map, 'version' => $version, 'hash' => substr(hash('sha256', json_encode($map)), 0, 16), 'captured_at' => date('c')];
    }
}

if (!function_exists('di_save_mapping_rule')) {
    function di_save_mapping_rule(PDO $pdo, string $key, string $value, string $notes, int $userId): void
    {
        $catalog = di_rule_catalog();
        if (!isset($catalog[$key])) throw new InvalidArgumentException('Unknown mapping rule.');
        if (!isset($catalog[$key]['options'][$value])) throw new InvalidArgumentException('Unsupported value for ' . $catalog[$key]['label'] . '.');
        if (!di_rules_table_ready($pdo)) throw new RuntimeException('The mapping rule table is not installed. Apply database/migrations/20260924_audit_fixes_mysql55.sql first.');
        $status = $value === 'unresolved' ? 'unresolved' : 'confirmed';
        $stmt = $pdo->prepare(
            'INSERT INTO data_import_mapping_rules (rule_key, rule_value, status, notes, confirmed_by, confirmed_at, updated_at, version) '
            . 'VALUES (?,?,?,?,?,NOW(),NOW(),1) '
            . 'ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value), status = VALUES(status), notes = VALUES(notes), '
            . 'confirmed_by = VALUES(confirmed_by), confirmed_at = NOW(), updated_at = NOW(), version = version + 1'
        );
        $stmt->execute([$key, $value, $status, substr($notes, 0, 500), $userId ?: null]);
        if (function_exists('di_audit')) di_audit($pdo, $userId, 'IMPORT_RULE_CHANGE', 'data_import_mapping_rules', $key, ['value' => $value, 'status' => $status]);
        di_mapping_rules($pdo, true);
    }
}

/* -------------------------------------------------------------------------
 * Column catalogues (every source column, none silently discarded)
 * ---------------------------------------------------------------------- */
if (!function_exists('di_billing_column_catalog')) {
    function di_billing_column_catalog(): array
    {
        $rule = static fn(string $key): string => 'rule:' . $key;
        return [
            'Account No' => ['type' => 'identifier text (leading zeros kept)', 'destination' => 'water_accounts.account_number match', 'transform' => 'trim, strip apostrophe, ".0" suffix', 'blank' => 'error', 'validation' => '1-50 digits; must exist among the water-service accounts', 'rule' => ''],
            'Receipt Number' => ['type' => 'text ≤ 50', 'destination' => 'payment_transactions.receipt_no', 'transform' => 'trim, uppercase identity', 'blank' => 'no payment', 'validation' => 'duplicate in file → review; existing receipt compared on account/total/date', 'rule' => $rule('receipt_scope')],
            'Pres Rdg Date' => ['type' => 'date', 'destination' => 'bill_readings.billing_date + import period', 'transform' => 'strict parse', 'blank' => 'error', 'validation' => '2000–2100; duplicate Account+date → review', 'rule' => $rule('date_order')],
            'Prev Rdg Date' => ['type' => 'date', 'destination' => 'import period start', 'transform' => 'strict parse', 'blank' => 'previous month assumed for period only', 'validation' => 'must precede Pres Rdg Date', 'rule' => $rule('date_order')],
            'Due Date' => ['type' => 'date', 'destination' => 'bill_readings.due_date / term_days', 'transform' => 'strict parse', 'blank' => 'allowed (term 0)', 'validation' => 'valid date', 'rule' => $rule('date_order')],
            'Penalty Date' => ['type' => 'date', 'destination' => 'preserved metadata (normalized_json.source.penalty_date)', 'transform' => 'strict parse', 'blank' => 'allowed', 'validation' => 'valid date', 'rule' => $rule('date_order')],
            'Disconnection' => ['type' => 'date', 'destination' => 'bill_readings.disconnection_date / disconnection_days', 'transform' => 'strict parse', 'blank' => 'allowed', 'validation' => 'valid date', 'rule' => $rule('disconnection_meaning')],
            'Present' => ['type' => 'whole number', 'destination' => 'bill_readings.pres_reading', 'transform' => 'none', 'blank' => 'error', 'validation' => 'integer ≥ 0', 'rule' => ''],
            'Previous' => ['type' => 'whole number', 'destination' => 'bill_readings.prev_reading', 'transform' => 'none', 'blank' => 'error', 'validation' => 'integer ≥ 0', 'rule' => ''],
            'Usage' => ['type' => 'whole number', 'destination' => 'bill_readings.consumption (source value kept)', 'transform' => 'none', 'blank' => 'error', 'validation' => 'compared with Present−Previous → review on mismatch', 'rule' => $rule('negative_usage')],
            'Rate Code' => ['type' => 'code A–D', 'destination' => 'rate snapshot via batch tariff mapping', 'transform' => 'uppercase (original kept)', 'blank' => 'review', 'validation' => 'mapped rate + pipe group must exist', 'rule' => ''],
            'Bill Amount' => ['type' => 'money', 'destination' => 'bill_readings.amount_due (never recalculated)', 'transform' => '2-dp rounding only', 'blank' => 'error', 'validation' => '≥ 0', 'rule' => ''],
            'Penalty' => ['type' => 'money', 'destination' => 'receipt penalty_amount / journal penalty line', 'transform' => '2-dp', 'blank' => 'zero', 'validation' => '≥ 0; both with Compromise → rule', 'rule' => $rule('penalty_compromise')],
            'Installation Fee' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:Installation Fee')],
            'Meter Instal Amt' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:Meter Instal Amt')],
            'Compromise Amt' => ['type' => 'money', 'destination' => 'receipt compromise_amount / journal compromise line', 'transform' => '2-dp', 'blank' => 'zero', 'validation' => '≥ 0; both with Penalty → rule', 'rule' => $rule('penalty_compromise')],
            'Recon Fee' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:Recon Fee')],
            'Date Paid' => ['type' => 'date', 'destination' => 'payment_transactions.payment_date/business_date', 'transform' => 'strict parse', 'blank' => 'no settlement', 'validation' => 'required by paid rule', 'rule' => $rule('paid_rule')],
            'Collector' => ['type' => 'text', 'destination' => 'preserved metadata (source_collector); cashier = importing operator', 'transform' => 'none', 'blank' => 'unknown (all rows blank)', 'validation' => 'none', 'rule' => ''],
            'Fittings Fee' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:Fittings Fee')],
            'Total' => ['type' => 'money', 'destination' => 'validation target (bill + charges + penalty + compromise)', 'transform' => '2-dp', 'blank' => 'review when payment present', 'validation' => 'must reconcile within 0.02 → else review', 'rule' => ''],
            'Cash Rcvd' => ['type' => 'money', 'destination' => 'preserved metadata only (0 in every row)', 'transform' => 'none', 'blank' => 'allowed', 'validation' => 'never authoritative', 'rule' => ''],
            'Amt Paid' => ['type' => 'money', 'destination' => 'tendered_amount; applied = min(Amt Paid, Total)', 'transform' => '2-dp', 'blank' => 'no settlement', 'validation' => 'per rule; partial → partial_payment rule', 'rule' => $rule('amt_paid_meaning')],
            'Cash Change' => ['type' => 'money', 'destination' => 'change_amount (checked against Amt Paid − applied)', 'transform' => '2-dp', 'blank' => 'zero', 'validation' => 'mismatch > 0.02 → review', 'rule' => $rule('amt_paid_meaning')],
            'Payment Flag' => ['type' => 'boolean', 'destination' => 'settlement evidence', 'transform' => 'TRUE/FALSE', 'blank' => 'FALSE', 'validation' => 'per paid rule', 'rule' => $rule('paid_rule')],
            'Receipt Flag' => ['type' => 'boolean', 'destination' => 'receipt_print_count (1 when TRUE)', 'transform' => 'TRUE/FALSE', 'blank' => 'FALSE', 'validation' => 'warning only', 'rule' => ''],
            'Share Capital' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan (equity treatment unconfirmed)', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:Share Capital')],
            'Membership Fee' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:Membership Fee')],
            'Other Fees' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:Other Fees')],
            'Water Meter' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:Water Meter')],
            'Senior' => ['type' => 'boolean/flag', 'destination' => 'preserved metadata (historical snapshot; member eligibility untouched)', 'transform' => 'none', 'blank' => 'allowed', 'validation' => 'none', 'rule' => ''],
            'SeniorDisNo' => ['type' => 'text', 'destination' => 'preserved metadata (exact value)', 'transform' => 'none', 'blank' => 'allowed', 'validation' => 'none', 'rule' => ''],
            'Funos' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:Funos')],
            'FunosBal' => ['type' => 'money balance', 'destination' => 'preserved metadata only (never revenue)', 'transform' => 'none', 'blank' => 'allowed', 'validation' => 'none', 'rule' => ''],
            'FunosPrev' => ['type' => 'money balance', 'destination' => 'preserved metadata only', 'transform' => 'none', 'blank' => 'allowed', 'validation' => 'none', 'rule' => ''],
            'SpecialAccountFlag' => ['type' => 'flag', 'destination' => 'preserved metadata only', 'transform' => 'none', 'blank' => 'allowed', 'validation' => 'none', 'rule' => ''],
            'MonthlyAmortization' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:MonthlyAmortization')],
            'DamayanContribution' => ['type' => 'money', 'destination' => 'recurring_bills via mapped plan', 'transform' => '2-dp', 'blank' => 'optional fee rule', 'validation' => '≥ 0', 'rule' => $rule('charge:DamayanContribution')],
        ];
    }
}

if (!function_exists('di_account_column_catalog')) {
    function di_account_column_catalog(): array
    {
        $meta = static fn(string $what): array => ['type' => 'source value', 'destination' => 'preserved metadata (raw_json / normalized_json.source)', 'transform' => 'none', 'blank' => 'allowed', 'validation' => $what, 'rule' => ''];
        return [
            'Account No' => ['type' => 'identifier text', 'destination' => 'water_accounts.account_number', 'transform' => 'trim, strip apostrophe/".0"', 'blank' => 'error', 'validation' => '1–50 digits; existing account preserved', 'rule' => ''],
            'Zone' => ['type' => 'integer', 'destination' => 'water_accounts.zone', 'transform' => 'none', 'blank' => 'unassigned', 'validation' => 'integer', 'rule' => ''],
            'Reading Day' => $meta('kept as source metadata'),
            'Sequence No' => ['type' => 'text ≤ 50', 'destination' => 'water_accounts.meter_sequence', 'transform' => 'length-limited (full value kept)', 'blank' => 'unassigned', 'validation' => 'length', 'rule' => ''],
            'Account Name' => ['type' => 'text (Windows-1252/UTF-8)', 'destination' => 'water_accounts.lastname / firstname ("LAST, FIRST")', 'transform' => 'split at first comma; institutions kept whole', 'blank' => 'allowed', 'validation' => 'truncation surfaced, never silent', 'rule' => ''],
            'Discount Volume' => ['type' => 'integer', 'destination' => 'normalized discount_volume (metadata)', 'transform' => 'none', 'blank' => 'allowed', 'validation' => 'integer', 'rule' => ''],
            'Discount Flag' => ['type' => 'boolean', 'destination' => 'discounted_members via selected discount', 'transform' => 'TRUE/FALSE', 'blank' => 'FALSE', 'validation' => 'requires mapping', 'rule' => ''],
            'Reading Flag' => $meta('kept as source metadata'),
            'Account Address' => ['type' => 'text', 'destination' => 'addresses.street (source-only address)', 'transform' => 'length-limited to 100 (full value kept)', 'blank' => 'no address', 'validation' => 'truncation surfaced', 'rule' => ''],
            'Rate Code' => ['type' => 'code', 'destination' => 'water_accounts.rc_id via tariff mapping', 'transform' => 'uppercase (original kept)', 'blank' => 'rc_id NULL + warning', 'validation' => 'mapped rate must exist', 'rule' => ''],
            'Meter Number' => ['type' => 'text', 'destination' => 'preserved metadata (blank in 6,788 rows; scientific notation kept as text)', 'transform' => 'none', 'blank' => 'allowed', 'validation' => 'scientific notation flagged', 'rule' => ''],
            'Present Reading' => $meta('kept as source metadata until reading migration is approved'),
            'Previous Reading' => $meta('kept as source metadata until reading migration is approved'),
            'Previous Rdg Date' => $meta('date kept as source metadata'),
            'Meter Reading Date' => $meta('date kept as source metadata'),
            'Installment Start Date' => $meta('installment fields kept as metadata until migration is approved'),
            'Inst Total Amt' => $meta('installment fields kept as metadata until migration is approved'),
            'Mo Inst Amt' => $meta('installment fields kept as metadata until migration is approved'),
            'Inst Amt Balance' => $meta('installment fields kept as metadata until migration is approved'),
            'Active Status Flag' => ['type' => 'boolean', 'destination' => 'water_accounts.status / connection_status', 'transform' => 'TRUE→A, FALSE→I', 'blank' => 'active + warning', 'validation' => 'boolean', 'rule' => ''],
            'OR Flag' => $meta('kept as source metadata'),
            'Usage' => $meta('kept as source metadata'),
            'Bill Amount' => $meta('kept as source metadata (latest bill)'),
            'Share Capital Bal' => $meta('balance kept as metadata; never revenue'),
            'Membership Fee' => $meta('kept as metadata'),
            'Other Fees' => $meta('kept as metadata'),
            'Installation Fee' => $meta('kept as metadata'),
            'Water Meter' => $meta('kept as metadata'),
            'Fittings Fee' => $meta('kept as metadata'),
            'Fittings Fee Balance' => $meta('balance kept as metadata'),
            'Recon Fee' => $meta('kept as metadata'),
            'Billing Flag' => $meta('kept as metadata'),
            'Total Credit' => $meta('credit kept as metadata (meaning unconfirmed)'),
            'Credit Balance' => $meta('balance kept as metadata (meaning unconfirmed)'),
            'Monthly Amortization' => $meta('kept as metadata (meaning unconfirmed)'),
            'Pay Amortization' => $meta('kept as metadata'),
            'RateKWHR' => $meta('kept as metadata'),
            'AmountBM' => $meta('kept as metadata'),
            'Credit Balance1' => $meta('balance kept as metadata (meaning unconfirmed)'),
            'Monthly Amortization1' => $meta('kept as metadata (meaning unconfirmed)'),
            'SeniorMaster' => ['type' => 'boolean', 'destination' => 'discounted_members via Senior discount mapping', 'transform' => 'TRUE/FALSE', 'blank' => 'FALSE', 'validation' => 'requires mapping', 'rule' => ''],
            'SeniorDisNo' => $meta('kept exactly as metadata'),
            'Funos' => $meta('kept as metadata (definition unconfirmed)'),
            'FunosBal' => $meta('balance kept as metadata'),
            'FunosPrev' => $meta('kept as metadata'),
            'cellno' => ['type' => 'phone text (leading zeros kept)', 'destination' => 'membercontactchannels (Mobile channel)', 'transform' => 'whitespace removed', 'blank' => 'no contact', 'validation' => 'format', 'rule' => ''],
            'SMSDate' => $meta('date kept as metadata'),
            'sc50Flag' => $meta('kept as metadata'),
            'TermLoan' => $meta('loan value kept as metadata (meaning unconfirmed)'),
            'DateLoan' => $meta('date kept as metadata'),
            'DamayanExemptFlag' => $meta('kept as metadata'),
        ];
    }
}

/* -------------------------------------------------------------------------
 * Saved mapping profiles
 * ---------------------------------------------------------------------- */
if (!function_exists('di_profiles_table_ready')) {
    function di_profiles_table_ready(PDO $pdo): bool
    {
        return function_exists('di_table_exists') && di_table_exists($pdo, 'data_import_mapping_profiles');
    }
}

if (!function_exists('di_mapping_profiles')) {
    function di_mapping_profiles(PDO $pdo, string $importType = ''): array
    {
        if (!di_profiles_table_ready($pdo)) return [];
        try {
            if ($importType !== '') {
                $stmt = $pdo->prepare('SELECT * FROM data_import_mapping_profiles WHERE import_type IN (?, "any") ORDER BY profile_name, version DESC');
                $stmt->execute([$importType]);
            } else {
                $stmt = $pdo->query('SELECT * FROM data_import_mapping_profiles ORDER BY profile_name, version DESC');
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) $row['options'] = di_json_decode((string)($row['options_json'] ?? '{}'));
            unset($row);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('di_save_mapping_profile')) {
    function di_save_mapping_profile(PDO $pdo, string $name, string $importType, array $options, int $userId): int
    {
        if (!di_profiles_table_ready($pdo)) throw new RuntimeException('Mapping profiles require database/migrations/20260924_audit_fixes_mysql55.sql.');
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 80) throw new InvalidArgumentException('Give the mapping profile a name of at most 80 characters.');
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version),0) FROM data_import_mapping_profiles WHERE profile_name = ? AND import_type = ?');
        $stmt->execute([$name, $importType]);
        $version = (int)$stmt->fetchColumn() + 1;
        $insert = $pdo->prepare('INSERT INTO data_import_mapping_profiles (profile_name, import_type, version, options_json, created_by, created_at) VALUES (?,?,?,?,?,NOW())');
        $insert->execute([$name, $importType, $version, di_json_encode($options), $userId ?: null]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('di_validate_option_references')) {
    /**
     * Flag destination references in batch options that no longer exist
     * (deleted rate codes, pipe sizes, plans, discounts, cities, org units).
     * Returns a list of human-readable problems; empty when everything resolves.
     */
    function di_validate_option_references(PDO $pdo, array $options, array $context): array
    {
        $problems = [];
        foreach (($options['rate_map'] ?? []) as $code => $rateId) {
            if ((int)$rateId > 0 && empty($context['rate_ids'][(int)$rateId])) $problems[] = 'Rate Code ' . $code . ' maps to rate #' . (int)$rateId . ', which no longer exists.';
        }
        foreach (($options['pipe_map'] ?? []) as $code => $pipeId) {
            if ((int)$pipeId > 0 && empty($context['pipe_ids'][(int)$pipeId])) $problems[] = 'Rate Code ' . $code . ' maps to pipe size #' . (int)$pipeId . ', which is inactive or missing.';
        }
        foreach (($options['recurring_map'] ?? []) as $column => $planId) {
            if ((int)$planId > 0 && empty($context['recurring_plans'][(int)$planId])) $problems[] = $column . ' maps to recurring plan #' . (int)$planId . ', which no longer exists.';
        }
        foreach (['source_discount_id' => 'Discount Flag', 'senior_discount_id' => 'SeniorMaster'] as $key => $label) {
            $id = (int)($options[$key] ?? 0);
            if ($id > 0 && empty($context['discount_ids'][$id])) $problems[] = $label . ' maps to discount #' . $id . ', which is inactive or missing.';
        }
        $city = (int)($options['default_city_id'] ?? 0);
        if ($city > 0 && empty($context['city_ids'][$city])) $problems[] = 'Default city #' . $city . ' is inactive or missing.';
        return $problems;
    }
}

/* -------------------------------------------------------------------------
 * Reconciliation totals
 * ---------------------------------------------------------------------- */
if (!function_exists('di_batch_reconciliation')) {
    /**
     * Counts and monetary totals per status for bills, charges and payments,
     * computed from the staged normalized rows. Stored on the batch when the
     * reconciliation_json column exists.
     */
    function di_batch_reconciliation(PDO $pdo, int $batchId, bool $store = true): array
    {
        $buckets = ['ready', 'review', 'error', 'skipped', 'imported', 'not_applicable', 'conflict'];
        $init = static fn(): array => array_fill_keys($buckets, ['count' => 0, 'amount' => 0.0]);
        $out = ['bills' => $init(), 'charges' => $init(), 'payments' => $init(), 'members' => $init(), 'source' => ['rows' => 0, 'bill_amount' => 0.0, 'total' => 0.0, 'amt_paid' => 0.0], 'computed_at' => date('c')];
        $type = (string)($pdo->query('SELECT import_type FROM data_import_batches WHERE batch_id = ' . (int)$batchId)->fetchColumn() ?: '');
        // read in pages of 1,000 rows: a Billing Master batch holds about 140 MB of staged JSON, which a single
        // (buffered) result would have to hold in PHP's memory at once (128 MB is the usual memory_limit)
        $columns = $type === 'account_master'
            ? '`row_number`, import_status'
            : '`row_number`, import_status, recurring_status, payment_status, normalized_json, issues_json, raw_json';
        $page = $pdo->prepare('SELECT ' . $columns . ' FROM data_import_rows WHERE batch_id = ? AND `row_number` > ? ORDER BY `row_number` LIMIT 1000');
        $after = -1;
        do {
            $page->execute([$batchId, $after]);
            $rows = $page->fetchAll(PDO::FETCH_ASSOC);
            $page->closeCursor();
            foreach ($rows as $row) {
                $after = (int)$row['row_number'];
                $out['source']['rows']++;
                if ($type === 'account_master') {
                    $status = (string)$row['import_status'];
                    if (!isset($out['members'][$status])) $status = 'review';
                    $out['members'][$status]['count']++;
                    continue;
                }
                $n = di_json_decode((string)$row['normalized_json']);
                $raw = di_json_decode((string)$row['raw_json']);
                $out['source']['bill_amount'] += (float)($raw['Bill Amount'] ?? 0);
                $out['source']['total'] += (float)($raw['Total'] ?? 0);
                $out['source']['amt_paid'] += (float)($raw['Amt Paid'] ?? 0);
                $billStatus = (string)$row['import_status'];
                if (!isset($out['bills'][$billStatus])) $billStatus = 'review';
                $out['bills'][$billStatus]['count']++;
                $out['bills'][$billStatus]['amount'] += (float)($n['bill_amount'] ?? 0);
                $chargeStatus = (string)$row['recurring_status'];
                if ($chargeStatus !== 'not_applicable') {
                    if (!isset($out['charges'][$chargeStatus])) $chargeStatus = 'review';
                    $out['charges'][$chargeStatus]['count']++;
                    $chargeTotal = 0.0;
                    foreach (($n['recurring_lines'] ?? []) as $line) $chargeTotal += (float)($line['amount'] ?? 0);
                    if ($chargeTotal <= 0) $chargeTotal = (float)($n['extra_total'] ?? 0);
                    $out['charges'][$chargeStatus]['amount'] += $chargeTotal;
                }
                $payStatus = (string)$row['payment_status'];
                if ($payStatus !== 'not_applicable') {
                    $conflict = strpos((string)$row['issues_json'], '"code":"EXISTING_RECEIPT_CONFLICT"') !== false;
                    if ($conflict) $payStatus = 'conflict';
                    if (!isset($out['payments'][$payStatus])) $payStatus = 'review';
                    $out['payments'][$payStatus]['count']++;
                    $out['payments'][$payStatus]['amount'] += (float)($n['applied_total'] ?? $n['source_total'] ?? 0);
                }
            }
        } while (count($rows) === 1000);
        foreach (['bills', 'charges', 'payments'] as $group) {
            foreach ($out[$group] as $key => $bucket) $out[$group][$key]['amount'] = round($bucket['amount'], 2);
        }
        foreach ($out['source'] as $key => $value) if (is_float($value)) $out['source'][$key] = round($value, 2);
        if ($store && di_column_exists($pdo, 'data_import_batches', 'reconciliation_json')) {
            try {
                $pdo->prepare('UPDATE data_import_batches SET reconciliation_json = ? WHERE batch_id = ?')->execute([di_json_encode($out), $batchId]);
            } catch (Throwable $e) {}
        }
        return $out;
    }
}

if (!function_exists('di_csv_safe_cell')) {
    /** Spreadsheet-formula-safe text: neutralize leading =, +, -, @, tab, CR. */
    function di_csv_safe_cell($value): string
    {
        $value = (string)($value ?? '');
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) return "'" . $value;
        return $value;
    }
}
