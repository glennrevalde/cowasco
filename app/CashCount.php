<?php
declare(strict_types=1);

/**
 * v38: the Cash Count Sheet of a POS shift: the bills and coins counted in the drawer when the register is closed.
 *
 * On Shifts, Cash Drops & Readings, clicking "Cash counted in drawer" opens the sheet: a quantity for each bill
 * (1,000 to 20) and coin (20 to 0.01); the amounts and the total cash are worked out as they are typed, and the total
 * becomes the cash counted. The quantities are kept with the shift (table pos_cash_counts) and are printed on the
 * Cash Count Sheet report (Point of Sale > Reports), one sheet per completed shift, with its date cover and cashier.
 * A correction of the cash counted can be made with the sheet too; the sheet is then replaced.
 */
final class CashCount
{
    /** The bills and coins of the sheet, largest first: kind => denominations */
    public const DENOMS = [
        'bill' => ['1000', '500', '200', '100', '50', '20'],
        'coin' => ['20', '10', '5', '1', '0.25', '0.10', '0.05', '0.01'],
    ];

    public const KINDS = ['bill' => 'Bills', 'coin' => 'Coins'];

    /** Whether the table of the bills and coins counted is there (database/migrations/2027_05_09_accounting_update_v40.sql) */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM pos_cash_counts WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** "1,000.00" / "0.25" as a label */
    public static function label(string $denom): string
    {
        return (float)$denom >= 1 ? number_format((float)$denom, 0) : number_format((float)$denom, 2);
    }

    /**
     * The sheet posted with the form (cc[bill][1000] = quantity ...): ['rows' => [[kind, denomination, qty, amount]],
     * 'total' => cash], or null when no quantity was entered.
     */
    public static function fromPost(): ?array
    {
        $in = $_POST['cc'] ?? null;
        // only a sheet taken with "Use this count": a sheet opened and closed again leaves the cash counted as typed
        if (!is_array($in) || (string)($_POST['cc_used'] ?? '') !== '1') {
            return null;
        }
        $rows = [];
        $total = 0.0;
        $any = false;
        foreach (self::DENOMS as $kind => $denoms) {
            foreach ($denoms as $d) {
                $raw = trim((string)($in[$kind][$d] ?? ''));
                if ($raw === '') {
                    $qty = 0;
                } elseif (!preg_match('/^\d{1,7}$/', str_replace(',', '', $raw))) {
                    fail('Enter whole numbers on the Cash Count Sheet (' . self::KINDS[$kind] . ' ' . self::label($d) . ': "' . $raw . '").');
                } else {
                    $qty = (int)str_replace(',', '', $raw);
                }
                $amount = r2($qty * (float)$d);
                $any = $any || $qty > 0;
                $total += $amount;
                $rows[] = ['kind' => $kind, 'denomination' => $d, 'qty' => $qty, 'amount' => $amount];
            }
        }
        return $any ? ['rows' => $rows, 'total' => r2($total)] : null;
    }

    /** Keeps the sheet of a shift (replacing an earlier one) */
    public static function save(int $shiftId, array $rows): void
    {
        if (!self::ready()) {
            return;
        }
        DB::run('DELETE FROM pos_cash_counts WHERE shift_id = :s', ['s' => $shiftId]);
        foreach ($rows as $r) {
            DB::insert('pos_cash_counts', [
                'shift_id' => $shiftId, 'kind' => $r['kind'], 'denomination' => $r['denomination'],
                'qty' => (int)$r['qty'], 'amount' => r2((float)$r['amount']), 'counted_by' => Auth::id(),
            ]);
        }
    }

    /** The sheet of a shift: kind => [denomination => qty] (empty when none was kept) */
    public static function forShift(int $shiftId): array
    {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT kind, denomination, qty FROM pos_cash_counts WHERE shift_id = :s', ['s' => $shiftId]) as $r) {
            $out[$r['kind']][self::key((string)$r['denomination'])] = (int)$r['qty'];
        }
        return $out;
    }

    /** "1000.00" from the database as the key of DENOMS ("1000", "0.25") */
    public static function key(string $d): string
    {
        foreach (self::DENOMS as $denoms) {
            foreach ($denoms as $k) {
                if (abs((float)$k - (float)$d) < 0.001) {
                    return $k;
                }
            }
        }
        return $d;
    }

    /**
     * The sheet as a window over the page (inside the form, so its quantities are posted with it).
     * $target: the id of the "Cash counted" box it fills; $cover: "11/12/2026 02:01 PM to 11/12/2026 10:00 PM";
     * $saved: forShift() of a closed shift, when it is corrected.
     */
    public static function modal(string $id, string $target, string $cover, string $cashier, array $saved = []): string
    {
        $posted = is_array($_POST['cc'] ?? null) ? $_POST['cc'] : null;
        $h = '<div class="modal fade cc-modal" id="' . h($id) . '" tabindex="-1" aria-labelledby="' . h($id) . 'T" data-cc-target="#' . h($target) . '">'
            . '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">'
            . '<div class="modal-header"><span class="cc-ico"><i class="bi bi-cash-stack"></i></span><div><h5 class="modal-title" id="' . h($id) . 'T">Cash Count Sheet</h5>'
            . '<div class="cc-head"><span>Date cover: <strong>' . h($cover) . '</strong></span><span>Cashier name: <strong>' . h($cashier) . '</strong></span></div></div>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            . '<input type="hidden" name="cc_used" value="' . h((string)($_POST['cc_used'] ?? '')) . '" data-cc-used>'
            . '<div class="modal-body p-0"><table class="table table-sm cc-table mb-0"><thead><tr><th></th><th class="num">Denomination</th><th class="num">Qty.</th><th class="num">Amount</th></tr></thead>';
        foreach (self::DENOMS as $kind => $denoms) {
            $h .= '<tbody><tr class="cc-kind"><th colspan="4">' . h(self::KINDS[$kind]) . '</th></tr>';
            foreach ($denoms as $d) {
                $q = $posted !== null ? (string)($posted[$kind][$d] ?? '') : (isset($saved[$kind][$d]) && $saved[$kind][$d] > 0 ? (string)$saved[$kind][$d] : '');
                $h .= '<tr><td></td><td class="num cc-den">' . h(self::label($d)) . '</td>'
                    . '<td class="cc-q"><input class="form-control form-control-sm text-end" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" name="cc[' . $kind . '][' . h($d) . ']" value="' . h($q) . '" data-cc-den="' . h($d) . '" aria-label="' . h(self::KINDS[$kind] . ' ' . self::label($d)) . ', quantity"></td>'
                    . '<td class="num cc-amt" data-cc-amt>0.00</td></tr>';
            }
            $h .= '</tbody>';
        }
        $h .= '</table></div>'
            . '<div class="modal-footer"><div class="cc-total"><span>Total cash</span><strong data-cc-total>0.00</strong></div>'
            . '<div class="d-flex gap-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cc-clear>Clear</button>'
            . '<button type="button" class="btn btn-primary btn-sm" data-cc-use><i class="bi bi-check-lg"></i> Use this count</button></div></div>'
            . '</div></div></div>';
        return $h;
    }
}
