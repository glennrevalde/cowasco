<?php
/**
 * Shared read-only system data helpers used by Search, Notifications, and
 * operational status pages. Every dynamic identifier is selected from a fixed
 * application whitelist; user values are always passed as prepared parameters.
 */

if (!function_exists('sys_table_exists')) {
    function sys_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('sys_column_exists')) {
    function sys_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}


if (!function_exists('sys_text_length')) {
    function sys_text_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

if (!function_exists('sys_text_initial')) {
    function sys_text_initial(string $value): string
    {
        if ($value === '') return '';
        return function_exists('mb_substr') ? mb_substr($value, 0, 1, 'UTF-8') : substr($value, 0, 1);
    }
}

if (!function_exists('sys_operator_id')) {
    function sys_operator_id(): int
    {
        return (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('sys_audit')) {
    function sys_audit(PDO $pdo, string $action, string $tableName, $recordId = null, array $details = []): void
    {
        if (!sys_table_exists($pdo, 'water_audit_logs')) return;
        try {
            $stmt = $pdo->prepare('INSERT INTO water_audit_logs (user_id, action, table_name, record_id, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->execute([
                sys_operator_id() ?: null,
                substr($action, 0, 100),
                substr($tableName, 0, 100),
                $recordId === null ? null : substr((string)$recordId, 0, 50),
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            error_log('[water audit] ' . $e->getMessage());
        }
    }
}

if (!function_exists('sys_fetch_scalar')) {
    function sys_fetch_scalar(PDO $pdo, string $sql, array $params = [], int $fallback = 0): int
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return $fallback;
        }
    }
}

if (!function_exists('sys_notification_items')) {
    function sys_notification_items(PDO $pdo, ?int $operatorId = null): array
    {
        $operatorId = $operatorId ?: sys_operator_id();
        $items = [];
        $add = static function (string $key, string $title, string $message, string $severity, string $href, int $count = 0) use (&$items, $pdo): void {
            if ($count <= 0) return;
            $path = (string)(parse_url($href, PHP_URL_PATH) ?? '');
            $pageKey = basename($path, '.php');
            // a cooperative page (index.php?p=rpt.x) is checked by its registry key
            parse_str((string)(parse_url($href, PHP_URL_QUERY) ?? ''), $query);
            if ($pageKey === 'index' && !empty($query['p'])) $pageKey = (string)$query['p'];
            if ($pageKey !== '' && function_exists('userCanAccessPage') && !userCanAccessPage($pdo, $pageKey)) return;
            $items[] = [
                'notification_key' => $key,
                'title' => $title,
                'message' => $message,
                'severity' => $severity,
                'href' => $href,
                'count' => $count,
                'is_read' => false,
            ];
        };

        if (sys_table_exists($pdo, 'bill_readings')) {
            $count = sys_fetch_scalar($pdo, "SELECT COUNT(*) FROM bill_readings br INNER JOIN bill_periods bp ON bp.period_id=br.period_id WHERE br.is_billed=0 AND br.is_paid=0 AND COALESCE(br.bill_lifecycle_status,'active')='active' AND LOWER(COALESCE(bp.status,'open'))='open'");
            $add('unposted_water_readings', 'Unposted water readings', "$count reading(s) are saved but have not been posted.", 'warning', 'generate_soa.php', $count);

            $count = sys_fetch_scalar($pdo, "SELECT COUNT(*) FROM bill_readings WHERE is_billed=1 AND is_paid=0 AND due_date < CURDATE() AND COALESCE(bill_lifecycle_status,'active')='active'");
            $add('overdue_water_bills', 'Overdue water bills', "$count posted water bill(s) are past due.", 'danger', 'billing_list.php?type=water&status=overdue', $count);
        }

        if (sys_table_exists($pdo, 'one_time_bills')) {
            $count = sys_fetch_scalar($pdo, "SELECT COUNT(*) FROM one_time_bills WHERE status='unpaid' AND due_date < CURDATE() AND COALESCE(bill_lifecycle_status,'active')='active'");
            $add('overdue_one_time_bills', 'Overdue one-time bills', "$count one-time bill(s) are past due.", 'danger', 'billing_list.php?type=onetime&status=overdue', $count);
        }

        if (sys_table_exists($pdo, 'recurring_bills')) {
            $count = sys_fetch_scalar($pdo, "SELECT COUNT(*) FROM recurring_bills WHERE status IN ('unpaid','overdue') AND due_date < CURDATE() AND COALESCE(bill_lifecycle_status,'active')='active'");
            $add('overdue_recurring_bills', 'Overdue recurring bills', "$count recurring bill(s) are past due.", 'danger', 'billing_list.php?type=recurring&status=overdue', $count);
        }

        if (sys_table_exists($pdo, 'recurring_subscriptions')) {
            $count = sys_fetch_scalar($pdo, "SELECT COUNT(*) FROM recurring_subscriptions s INNER JOIN recurring_plans p ON p.plan_id=s.plan_id WHERE s.status='active' AND p.status='active' AND s.next_billing_date<=CURDATE()");
            $add('recurring_cycles_due', 'Recurring cycles ready', "$count active subscription(s) are due for explicit generation.", 'info', 'recurring_bill.php', $count);
        }

        // low stock: the cooperative inventory (items with a reorder point, stock on hand across sites)
        if (sys_table_exists($pdo, 'items') && sys_table_exists($pdo, 'item_site_stock')) {
            $count = sys_fetch_scalar($pdo, "SELECT COUNT(*) FROM items i WHERE i.is_active = 1 AND i.item_type IN ('inventory','assembly') AND i.reorder_point > 0 AND COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = i.id), 0) <= i.reorder_point");
            $add('low_inventory', 'Low inventory', "$count item(s) are at or below their reorder point.", 'warning', function_exists('url') ? url('rpt.stock_status_item') : '../../index.php?p=rpt.stock_status_item', $count);
        }

        // overdue supplier payables: open vendor bills of the ledger past their due date
        if (sys_table_exists($pdo, 'transactions')) {
            $count = sys_fetch_scalar($pdo, "SELECT COUNT(*) FROM transactions WHERE txn_type = 'bill' AND is_voided = 0 AND status <> 'paid' AND due_date IS NOT NULL AND due_date < CURDATE()");
            $add('overdue_accounts_payable', 'Overdue supplier payables', "$count vendor bill(s) are past their due date.", 'warning', function_exists('url') ? url('rpt.ap_aging_summary') : '../../index.php?p=rpt.ap_aging_summary', $count);
        }

        if (sys_table_exists($pdo, 'operator_password_reset_requests')) {
            $count = sys_fetch_scalar($pdo, "SELECT COUNT(*) FROM operator_password_reset_requests WHERE status='pending'");
            $add('pending_password_resets', 'Pending password-reset requests', "$count request(s) need administrator review.", 'info', 'user_configuration.php', $count);
        }

        if (sys_table_exists($pdo, 'bill_codes')) {
            // an inventory bill code needs its item of the Item List (its COGS / asset accounts are informational)
            $count = sys_fetch_scalar($pdo, "SELECT COUNT(*) FROM bill_codes WHERE ar_account IS NULL OR income_account IS NULL OR (type='Inventory' AND (item_id IS NULL OR item_id = 0))");
            $add('incomplete_bill_code_accounts', 'Incomplete bill-code accounting', "$count bill code(s) are missing an account mapping or the link to an item of the Item List.", 'danger', 'bill_codes.php', $count);
        }

        if ($operatorId > 0 && sys_table_exists($pdo, 'operator_notification_reads')) {
            try {
                $stmt = $pdo->prepare('SELECT notification_key FROM operator_notification_reads WHERE operator_id=?');
                $stmt->execute([$operatorId]);
                $read = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
                foreach ($items as &$item) {
                    $item['is_read'] = isset($read[$item['notification_key']]);
                }
                unset($item);
            } catch (Throwable $e) {}
        }
        return $items;
    }
}

if (!function_exists('sys_unread_notification_count')) {
    function sys_unread_notification_count(PDO $pdo, ?int $operatorId = null): int
    {
        $count = 0;
        foreach (sys_notification_items($pdo, $operatorId) as $item) {
            if (empty($item['is_read'])) $count++;
        }
        return $count;
    }
}
