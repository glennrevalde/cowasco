<?php
/**
 * Compact display helpers for long system-generated identifiers.
 *
 * The stored value is never changed. Pages should keep the full value in links,
 * searches, form fields, data attributes, and title attributes, then use the
 * compact value only for the visible table text.
 */

if (!function_exists('ui_text_length')) {
    function ui_text_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

if (!function_exists('ui_text_slice')) {
    function ui_text_slice(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($value, $start, null, 'UTF-8')
                : mb_substr($value, $start, $length, 'UTF-8');
        }

        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

if (!function_exists('ui_compact_reference')) {
    function ui_compact_reference($value, int $maxLength = 20): string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return '—';
        }

        $maxLength = max(12, $maxLength);
        if (ui_text_length($text) <= $maxLength) {
            return $text;
        }

        $tailLength = min(5, max(3, intdiv($maxLength, 4)));
        $headLength = $maxLength - $tailLength - 1;

        return ui_text_slice($text, 0, $headLength)
            . '…'
            . ui_text_slice($text, -$tailLength);
    }
}

if (!function_exists('ui_compact_text')) {
    function ui_compact_text($value, int $maxLength = 72): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)($value ?? '')) ?? '');
        if ($text === '') {
            return '—';
        }
        if (ui_text_length($text) <= $maxLength) {
            return $text;
        }
        return rtrim(ui_text_slice($text, 0, max(1, $maxLength - 1))) . '…';
    }
}

if (!function_exists('ui_clean_reference')) {
    /**
     * Normalizes a transaction reference for display without changing the stored value.
     */
    function ui_clean_reference($value): string
    {
        $text = trim(strip_tags((string)($value ?? '')));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = preg_replace('/^(?:transaction\s+)?ref(?:erence)?\s*(?:no\.?|#|:|-)?\s*/i', '', $text) ?? $text;
        return trim($text) !== '' ? trim($text) : '—';
    }
}

if (!function_exists('ui_clean_transaction_description')) {
    /**
     * Removes repeated internal IDs and reference text from descriptions shown in
     * accounting tables. The original database description remains untouched.
     */
    function ui_clean_transaction_description($value, $reference = '', string $fallback = 'Transaction'): string
    {
        $text = trim(strip_tags((string)($value ?? '')));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        $referenceText = ui_clean_reference($reference);
        if ($referenceText !== '—') {
            $text = str_ireplace($referenceText, '', $text);
        }

        $text = preg_replace('/\b(?:transaction\s+)?ref(?:erence)?\s*(?:no\.?|#|:|-)?\s*[A-Z0-9][A-Z0-9._\/-]*/i', '', $text) ?? $text;
        $text = preg_replace('/\bfor\s+member\s*#?\s*\d+\b/i', '', $text) ?? $text;
        $text = preg_replace('/\bmember\s*#?\s*\d+\b/i', 'member', $text) ?? $text;
        $text = preg_replace('/\b(bill|contract)\s*#\s*\d+\b/i', '$1', $text) ?? $text;
        $text = preg_replace('/\bcollection\s+receipt\s*(?:no\.?|#|:|-)?\s*/i', 'Collection ', $text) ?? $text;
        $text = preg_replace('/\s+(?:[-–—|])\s+/u', ' - ', $text) ?? $text;
        $text = preg_replace('/(?:\s+-\s+){2,}/u', ' - ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B·-–—|:;,");

        // Prefer the meaningful collection action over a repeated generic prefix.
        if (preg_match('/\b(Cash collection|Receivable collection|Collection of .+)$/i', $text, $match)) {
            $text = trim($match[1]);
        }

        return $text !== '' ? $text : $fallback;
    }
}
