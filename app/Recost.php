<?php
declare(strict_types=1);

/**
 * v35: costs the later transactions of a back-dated, edited, voided or deleted inventory transaction again, in date order.
 *
 * The weighted average is worked out as each transaction is posted. A processing order, bill or sale entered with an
 * earlier date than transactions already posted, or edited, voided or deleted after later ones were posted, used to
 * leave those later ones at the costs of the moment they were entered: the averages after it jumped (a receipt costed
 * at ten times its value, then the stock sold at that inflated average, then the receipt corrected, left the average at
 * a fraction of the right one). Now every transaction with inventory movements on or after the date concerned is posted
 * again, oldest first (on one date, the stock received before the stock used or sold, Stock::dayRanks), exactly as
 * Rebuild Data does for the whole history, so the averages and the cost of sales follow the dates.
 *
 * It runs once the saving transaction is committed, in a transaction of its own: if it cannot finish, nothing of it is
 * kept and a message asks for Rebuild Data. It is skipped, with a message, when more than "auto_recost_limit"
 * transactions would be posted again (System Administration > Company, Security & Closing Date > Inventory and POS).
 *
 * v36: which transactions start it is a setting ("auto_recost_scope"): Disassemble / Process Items only (the
 * standard), every inventory transaction, or never. With the standard, bills, item receipts, checks, sales,
 * adjustments and transfers save as before and start nothing; only a back-dated, edited, voided or deleted
 * processing order costs the later transactions again. Rebuild Data still works out everything in date order.
 *
 * A new transaction dated today (a POS sale, a bill) has nothing after it and starts nothing. On one date, a receipt
 * entered after the day's sales is settled by Stock::move (the shortfall at the cost received); an edit, void or delete
 * of a transaction with later entries on its own date does start a re-costing of that date.
 */
final class Recost
{
    /** The earliest date waiting to be costed again once the current transaction is committed */
    private static ?string $from = null;

    /** What starts a re-costing, label for the settings page */
    public const SCOPES = [
        'process' => 'Disassemble / Process Items only',
        'all'     => 'Every inventory transaction (bills, item receipts, checks, sales, adjustments, transfers, processing)',
        'off'     => 'Never (run Rebuild Data instead)',
    ];

    /** The setting: process (the standard), all or off */
    public static function scope(): string
    {
        $s = Settings::get('auto_recost_scope', 'process');
        return isset(self::SCOPES[$s]) ? $s : 'process';
    }

    /** The items of a transaction's movements, and the date of them: [date or null, item ids] */
    public static function footprint(int $txnId): array
    {
        $r = DB::row('SELECT MIN(txn_date) AS d FROM inventory_movements WHERE txn_id = :t', ['t' => $txnId]);
        $items = array_map('intval', DB::column('SELECT DISTINCT item_id FROM inventory_movements WHERE txn_id = :t', ['t' => $txnId]));
        return [$r && $r['d'] ? (string)$r['d'] : null, $items];
    }

    /**
     * Called by the posting engine after a transaction is posted again or removed.
     * $before / $after: footprint() of the transaction before and after; $edited: it had been posted before (an edit,
     * a void or a delete, not a new transaction).
     */
    public static function note(int $txnId, array $before, array $after): void
    {
        if (Posting::$replaying) {
            return;
        }
        $scope = self::scope();
        if ($scope === 'off') {
            return;
        }
        // the standard: only a processing order (Disassemble / Process Items) starts it
        if ($scope === 'process' && (string)DB::value('SELECT txn_type FROM transactions WHERE id = :id', ['id' => $txnId]) !== Processing::TXN_TYPE) {
            return;
        }
        $dates = array_filter([$before[0], $after[0]]);
        $items = array_values(array_unique(array_merge($before[1], $after[1])));
        if (!$dates || !$items) {
            return;
        }
        $date = min($dates);
        $edited = $before[0] !== null;
        [$in, $p] = DB::in('rci', $items);
        // later movements of the same items by other transactions: on a later date, or (an edit) entered later that day
        $later = DB::value(
            "SELECT 1 FROM inventory_movements WHERE item_id IN $in AND txn_id <> :t AND (txn_date > :d"
            . ($edited ? ' OR (txn_date = :d2 AND txn_id > :t2)' : '') . ') LIMIT 1',
            $p + ['t' => $txnId, 'd' => $date] + ($edited ? ['d2' => $date, 't2' => $txnId] : [])
        );
        if (!$later) {
            return;
        }
        self::$from = self::$from === null ? $date : min(self::$from, $date);
        DB::afterCommit(fn() => self::runPending());
    }

    /** Runs the re-costing noted during the transaction just committed */
    public static function runPending(): void
    {
        $date = self::$from;
        self::$from = null;
        if ($date === null) {
            return;
        }
        $web = PHP_SAPI !== 'cli';
        try {
            $r = self::from($date);
            if ($r['skipped']) {
                if ($web) {
                    flash('warning', 'The costs of the ' . number_format($r['count']) . ' inventory transactions dated from ' . fdate($date)
                        . ' were not worked out again: more than ' . number_format($r['limit']) . ' at once. Run System Administration > Utilities: Verify & Rebuild Data > Rebuild data so their costs follow the dates.');
                }
            } elseif ($r['count'] > 0 && $web) {
                flash('info', 'The costs of ' . number_format($r['count']) . ' inventory transaction' . ($r['count'] === 1 ? '' : 's') . ' dated from ' . fdate($date)
                    . ' were worked out again in date order, so the average costs and the cost of sales follow the dates.');
            }
        } catch (Throwable $e) {
            error_log('Recost from ' . $date . ': ' . $e->getMessage());
            if ($web) {
                flash('warning', 'The transaction was saved, but the costs of the later transactions could not be worked out again ('
                    . $e->getMessage() . '). Run System Administration > Utilities: Verify & Rebuild Data > Rebuild data.');
            }
        }
    }

    /**
     * Posts again, in date order, every transaction with inventory movements on or after $date.
     * Returns ['count' => transactions, 'skipped' => more than the limit, 'limit' => the limit].
     */
    public static function from(string $date, ?int $limit = null): array
    {
        $limit = $limit ?? max(1, (int)Settings::get('auto_recost_limit', '2000'));
        $txns = DB::all(
            'SELECT DISTINCT m.txn_id AS id, t.txn_date FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id WHERE m.txn_date >= :d',
            ['d' => $date]
        );
        $out = ['count' => count($txns), 'skipped' => false, 'limit' => $limit];
        if (!$txns) {
            return $out;
        }
        if (count($txns) > $limit) {
            $out['skipped'] = true;
            return $out;
        }
        @set_time_limit(600);
        $ranks = Stock::dayRanks(array_column($txns, 'id'));
        usort($txns, fn($a, $b) => [$a['txn_date'], $ranks[(int)$a['id']] ?? 1, (int)$a['id']] <=> [$b['txn_date'], $ranks[(int)$b['id']] ?? 1, (int)$b['id']]);
        $items = array_map('intval', DB::column('SELECT DISTINCT item_id FROM inventory_movements WHERE txn_date >= :d', ['d' => $date]));

        DB::tx(function () use ($txns, $items, $date) {
            // back to where the items stood before the date
            DB::run('DELETE FROM inventory_movements WHERE txn_date >= :d', ['d' => $date]);
            Stock::resync($items);
            foreach ($items as $itemId) {
                $b = DB::row('SELECT COALESCE(SUM(qty),0) AS q, COALESCE(SUM(total_cost),0) AS v FROM inventory_movements WHERE item_id = :i', ['i' => $itemId]);
                $q = (float)$b['q'];
                $v = (float)$b['v'];
                if ($q > 0.000001 && $v > 0.004) {
                    $avg = $v / $q;
                } else {
                    // nothing to divide: the last average the item had before the date, else what it has now
                    $last = DB::value('SELECT avg_after FROM inventory_movements WHERE item_id = :i ORDER BY txn_date DESC, id DESC LIMIT 1', ['i' => $itemId]);
                    $avg = $last !== null && $last !== false ? (float)$last : null;
                }
                if ($avg !== null) {
                    DB::run('UPDATE items SET avg_cost = :a WHERE id = :i', ['a' => round($avg, 4), 'i' => $itemId]);
                }
            }
            // and every transaction from the date posted again, oldest first
            $wasReplaying = Posting::$replaying;
            $wasCheck = Posting::$stockCheck;
            Posting::$replaying = true;
            Posting::$stockCheck = false;
            try {
                foreach ($txns as $t) {
                    Posting::post((int)$t['id']);
                }
            } finally {
                Posting::$replaying = $wasReplaying;
                Posting::$stockCheck = $wasCheck;
            }
            Stock::resync($items);
            Audit::log('recost', 'inv', 'transaction', null, 'Costs from ' . fdate($date), [
                'reason' => count($txns) . ' inventory transaction(s) dated from ' . fdate($date) . ' costed again in date order after a back-dated, edited, voided or deleted transaction',
            ]);
        });
        return $out;
    }
}
