<?php
declare(strict_types=1);

/**
 * Exact decimal arithmetic for the cooperative module (balances, interest, amortization, allocations).
 *
 * Values are decimal strings ("1234.56"). Nothing here goes through a PHP float, so a balance that was
 * built from ten thousand deposits is exactly the sum of them. When the bcmath extension is loaded it does
 * the work; otherwise a pure-PHP implementation on integer strings gives the same results.
 *
 *   Dec::add('10.10', '0.20')          => '10.30'
 *   Dec::mul('20000', '0.015', 2)      => '300.00'
 *   Dec::div('300', '0.16361', 2)      => '1833.51'   (truncated at the scale; use round() for half-up)
 *   Dec::round('1833.5999', 2)         => '1833.60'
 *   Dec::cmp('1.00', '1')              => 0
 */
final class Dec
{
    /** @var bool|null null = not checked yet */
    private static ?bool $bc = null;
    /** set to true to force the pure-PHP path (tests) */
    public static bool $forcePhp = false;

    private static function bc(): bool
    {
        if (self::$forcePhp) {
            return false;
        }
        return self::$bc ??= function_exists('bcadd');
    }

    /** Normalises any number-like input to a plain decimal string ("-12.50" -> "-12.50", "1,250" -> "1250", "" -> "0") */
    public static function of($v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_float($v)) {
            // a float is only ever an input from a form or an old column; it is fixed at 6 decimals here, once
            $v = number_format($v, 6, '.', '');
        }
        $s = str_replace([',', ' ', "\u{20B1}", '₱'], '', trim((string)$v));
        if ($s === '' || $s === '-' || $s === '.') {
            return '0';
        }
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
            $s = '-' . substr($s, 1, -1);
        }
        if (!preg_match('/^[+-]?\d*(\.\d*)?$/', $s)) {
            throw new UserError('Not a number: ' . mb_substr((string)$v, 0, 40));
        }
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '+-');
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $int = ltrim($int, '0');
        $frac = rtrim($frac, '0');
        if ($int === '') {
            $int = '0';
        }
        $out = $int . ($frac !== '' ? '.' . $frac : '');
        return ($neg && $out !== '0') ? '-' . $out : $out;
    }

    public static function add($a, $b, int $scale = 6): string
    {
        $a = self::of($a);
        $b = self::of($b);
        if (self::bc()) {
            return self::trim(bcadd($a, $b, $scale));
        }
        return self::fromInt(self::toInt($a, $scale) + self::toInt($b, $scale), $scale);
    }

    public static function sub($a, $b, int $scale = 6): string
    {
        $a = self::of($a);
        $b = self::of($b);
        if (self::bc()) {
            return self::trim(bcsub($a, $b, $scale));
        }
        return self::fromInt(self::toInt($a, $scale) - self::toInt($b, $scale), $scale);
    }

    public static function mul($a, $b, int $scale = 6): string
    {
        $a = self::of($a);
        $b = self::of($b);
        if (self::bc()) {
            return self::round(bcmul($a, $b, 12), $scale);
        }
        // multiply as integers scaled at 6 each (12 decimals), then round to $scale
        [$ia, $sa] = self::parts($a);
        [$ib, $sb] = self::parts($b);
        $prod = self::bigMul($ia, $ib);                           // digit string, scale $sa + $sb
        $prod = self::placeDot($prod, $sa + $sb);
        return self::round($prod, $scale);
    }

    /** $a / $b rounded half-up at $scale; a division by zero throws */
    public static function div($a, $b, int $scale = 6): string
    {
        $a = self::of($a);
        $b = self::of($b);
        if (self::cmp($b, '0') === 0) {
            throw new UserError('Division by zero in a financial computation.');
        }
        if (self::bc()) {
            return self::round(bcdiv($a, $b, $scale + 4), $scale);
        }
        // scale both to integers, then long-divide with $scale + 4 extra digits
        $negA = str_starts_with($a, '-');
        $negB = str_starts_with($b, '-');
        [$ia, $sa] = self::parts(ltrim($a, '-'));
        [$ib, $sb] = self::parts(ltrim($b, '-'));
        // a/b = (ia / 10^sa) / (ib / 10^sb) = ia * 10^(sb) / (ib * 10^(sa))
        $num = $ia . str_repeat('0', $sb + $scale + 4);
        $den = $ib . str_repeat('0', $sa);
        $q = self::bigDivInt($num, $den);
        $q = self::placeDot($q, $scale + 4);
        $q = self::round($q, $scale);
        return ($negA xor $negB) && self::cmp($q, '0') !== 0 ? '-' . $q : $q;
    }

    /** -1, 0 or 1 */
    public static function cmp($a, $b): int
    {
        $a = self::of($a);
        $b = self::of($b);
        if (self::bc()) {
            return bccomp($a, $b, 12);
        }
        $d = self::sub($a, $b, 12);
        if (str_starts_with($d, '-')) {
            return -1;
        }
        return $d === '0' ? 0 : 1;
    }

    public static function isZero($a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function isNeg($a): bool
    {
        return self::cmp($a, '0') < 0;
    }

    public static function isPos($a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function neg($a): string
    {
        $a = self::of($a);
        if ($a === '0') {
            return '0';
        }
        return str_starts_with($a, '-') ? substr($a, 1) : '-' . $a;
    }

    public static function abs($a): string
    {
        return ltrim(self::of($a), '-');
    }

    public static function min($a, $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    public static function max($a, $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    /** Rounds half away from zero to $scale decimals (the way amounts on receipts are rounded) */
    public static function round($a, int $scale = 2): string
    {
        $a = self::of($a);
        $neg = str_starts_with($a, '-');
        $a = ltrim($a, '-');
        [$int, $frac] = array_pad(explode('.', $a, 2), 2, '');
        if (strlen($frac) <= $scale) {
            $out = $int . ($scale > 0 ? '.' . str_pad($frac, $scale, '0') : '');
            return self::trim(($neg ? '-' : '') . $out);
        }
        $keep = substr($frac, 0, $scale);
        $next = (int)$frac[$scale];
        $digits = $int . $keep;                                   // integer with $scale implied decimals
        if ($next >= 5) {
            $digits = self::bigAddInt($digits, '1');
        }
        $out = self::placeDot($digits, $scale);
        return self::trim(($neg ? '-' : '') . $out);
    }

    /** Fixed-point text with exactly $scale decimals ("1250.5" -> "1250.50"), for storing and printing */
    public static function fix($a, int $scale = 2): string
    {
        $a = self::round($a, $scale);
        $neg = str_starts_with($a, '-');
        $a = ltrim($a, '-');
        [$int, $frac] = array_pad(explode('.', $a, 2), 2, '');
        $out = $int . ($scale > 0 ? '.' . str_pad($frac, $scale, '0') : '');
        return ($neg && $out !== '0.00' ? '-' : '') . $out;
    }

    /** Sum of a list */
    public static function sum(array $values, int $scale = 2): string
    {
        $t = '0';
        foreach ($values as $v) {
            $t = self::add($t, $v, 6);
        }
        return self::round($t, $scale);
    }

    /** "1,250.50" / "(1,250.50)" for the screen */
    public static function money($a, bool $blankZero = false): string
    {
        $a = self::fix($a, 2);
        if ($blankZero && $a === '0.00') {
            return '';
        }
        $neg = str_starts_with($a, '-');
        $a = ltrim($a, '-');
        [$int, $frac] = explode('.', $a);
        $int = strrev(implode(',', str_split(strrev($int), 3)));
        return $neg ? '(' . $int . '.' . $frac . ')' : $int . '.' . $frac;
    }

    /** Percent of an amount: pct is "3" for 3 % */
    public static function pct($amount, $pct, int $scale = 2): string
    {
        return self::round(self::div(self::mul($amount, $pct, 10), '100', 10), $scale);
    }

    // ------------------------------------------------------------------ internals (pure PHP path)

    /** removes a trailing fractional zero run and a dangling dot; "-0" becomes "0" */
    private static function trim(string $s): string
    {
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        if ($s === '-0' || $s === '' || $s === '-') {
            $s = '0';
        }
        return $s;
    }

    /** ["123456", 2] for "1234.56": the digits and the number of decimals; sign kept on the digits */
    private static function parts(string $a): array
    {
        $neg = str_starts_with($a, '-');
        $a = ltrim($a, '-');
        [$int, $frac] = array_pad(explode('.', $a, 2), 2, '');
        $digits = ltrim($int . $frac, '0');
        if ($digits === '') {
            $digits = '0';
        }
        return [($neg && $digits !== '0' ? '-' : '') . $digits, strlen($frac)];
    }

    private static function toInt(string $a, int $scale): string
    {
        [$d, $s] = self::parts($a);
        $neg = str_starts_with($d, '-');
        $d = ltrim($d, '-');
        if ($s < $scale) {
            $d .= str_repeat('0', $scale - $s);
        } elseif ($s > $scale) {
            $d = substr($d, 0, strlen($d) - ($s - $scale)) ?: '0';   // truncate beyond the scale (inputs are already exact)
        }
        $d = ltrim($d, '0') ?: '0';
        return ($neg && $d !== '0' ? '-' : '') . $d;
    }

    private static function fromInt($i, int $scale): string
    {
        $s = is_string($i) ? $i : (string)$i;
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-');
        return self::trim(($neg ? '-' : '') . self::placeDot($s, $scale));
    }

    /** puts the decimal point $scale digits from the right of a digit string */
    private static function placeDot(string $digits, int $scale): string
    {
        $neg = str_starts_with($digits, '-');
        $digits = ltrim($digits, '-');
        if ($scale <= 0) {
            return ($neg ? '-' : '') . (ltrim($digits, '0') ?: '0');
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $int = substr($digits, 0, -$scale);
        $frac = substr($digits, -$scale);
        $int = ltrim($int, '0') ?: '0';
        return self::trim(($neg ? '-' : '') . $int . '.' . $frac);
    }

    // signed-integer string arithmetic (sign handled here, magnitudes by the *Mag helpers)

    private static function bigAddInt(string $a, string $b): string
    {
        $na = str_starts_with($a, '-');
        $nb = str_starts_with($b, '-');
        $a = ltrim($a, '-');
        $b = ltrim($b, '-');
        if ($na === $nb) {
            return ($na ? '-' : '') . self::addMag($a, $b);
        }
        $c = self::cmpMag($a, $b);
        if ($c === 0) {
            return '0';
        }
        return $c > 0 ? ($na ? '-' : '') . self::subMag($a, $b) : ($nb ? '-' : '') . self::subMag($b, $a);
    }

    private static function bigMul(string $a, string $b): string
    {
        $neg = (str_starts_with($a, '-') xor str_starts_with($b, '-'));
        $a = ltrim($a, '-');
        $b = ltrim($b, '-');
        $r = self::mulMag($a, $b);
        return ($neg && $r !== '0' ? '-' : '') . $r;
    }

    private static function bigDivInt(string $a, string $b): string
    {
        // magnitudes only (callers pass positive values)
        return self::divMag($a, $b);
    }

    private static function cmpMag(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }
        return strcmp($a, $b) <=> 0;
    }

    private static function addMag(string $a, string $b): string
    {
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $carry = 0;
        $out = '';
        while ($i >= 0 || $j >= 0 || $carry) {
            $s = $carry + ($i >= 0 ? (int)$a[$i--] : 0) + ($j >= 0 ? (int)$b[$j--] : 0);
            $out .= (string)($s % 10);
            $carry = intdiv($s, 10);
        }
        return ltrim(strrev($out), '0') ?: '0';
    }

    /** a - b with a >= b */
    private static function subMag(string $a, string $b): string
    {
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $borrow = 0;
        $out = '';
        while ($i >= 0) {
            $d = (int)$a[$i--] - $borrow - ($j >= 0 ? (int)$b[$j--] : 0);
            if ($d < 0) {
                $d += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out .= (string)$d;
        }
        return ltrim(strrev($out), '0') ?: '0';
    }

    private static function mulMag(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $la = strlen($a);
        $lb = strlen($b);
        $res = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            $da = (int)$a[$i];
            for ($j = $lb - 1; $j >= 0; $j--) {
                $p = $da * (int)$b[$j] + $res[$i + $j + 1];
                $res[$i + $j + 1] = $p % 10;
                $res[$i + $j] += intdiv($p, 10);
            }
        }
        return ltrim(implode('', $res), '0') ?: '0';
    }

    /** integer quotient of two magnitude strings (schoolbook long division) */
    private static function divMag(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if ($b === '0') {
            throw new UserError('Division by zero in a financial computation.');
        }
        if (self::cmpMag($a, $b) < 0) {
            return '0';
        }
        $q = '';
        $rem = '';
        $len = strlen($a);
        for ($i = 0; $i < $len; $i++) {
            $rem = ltrim($rem . $a[$i], '0');
            $d = 0;
            while ($rem !== '' && self::cmpMag($rem, $b) >= 0) {
                $rem = self::subMag($rem, $b);
                $d++;
            }
            $q .= (string)$d;
        }
        return ltrim($q, '0') ?: '0';
    }
}
