<?php
declare(strict_types=1);

/**
 * Wording of the cooperative's printed documents: the standard text, versions the cooperative writes on
 * Cooperative > Document Templates (each with the date it takes effect), and the filling in of the
 * {{placeholders}}. Values are escaped; the template text keeps only a short list of formatting tags.
 */
final class CoopDocs
{
    public const KEYS = [
        'promissory_note'   => 'Promissory note',
        'disclosure'        => 'Disclosure statement notes',
        'comaker'           => 'Co-maker statement',
        'td_certificate'    => 'Time deposit certificate',
        'share_certificate' => 'Share capital certificate',
        'balance_certificate' => 'Certificate of balances',
        'member_notice'     => 'Loan payment reminder',
        'demand_letter'     => 'Demand letter',
    ];

    public const PLACEHOLDERS = [
        'promissory_note' => ['coop', 'coop_address', 'borrower', 'borrower_address', 'member_no', 'loan_no', 'pn_no', 'amount', 'amount_words', 'rate', 'term', 'mode', 'amortization', 'installments', 'first_due', 'maturity', 'penalty_rate', 'grace_days', 'release_date', 'comakers'],
        'disclosure'      => ['coop', 'borrower', 'loan_no', 'amount', 'total_charges', 'net_proceeds', 'total_interest', 'eir'],
        'comaker'         => ['coop', 'comaker', 'borrower', 'loan_no', 'amount', 'amount_words', 'release_date', 'maturity'],
        'td_certificate'  => ['coop', 'member', 'member_no', 'certificate_no', 'principal', 'principal_words', 'rate', 'term', 'placement_date', 'maturity_date', 'maturity_value', 'maturity_action'],
        'share_certificate' => ['coop', 'member', 'member_no', 'certificate_no', 'shares', 'par_value', 'amount', 'amount_words', 'as_of'],
        'balance_certificate' => ['coop', 'member', 'member_no', 'member_type', 'membership_date', 'status', 'as_of', 'purpose', 'today'],
        'member_notice'   => ['coop', 'member', 'member_address', 'loan_no', 'product', 'amount_due', 'due_date', 'penalty', 'balance', 'today'],
        'demand_letter'   => ['coop', 'member', 'member_address', 'loan_no', 'product', 'amount_due', 'days_past_due', 'balance', 'deadline', 'today', 'comakers'],
    ];

    private const TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'h3', 'h4', 'h5', 'div', 'span', 'hr', 'small', 'blockquote'];

    /** The version in effect on $date (latest effective date on or before it), or null for the standard wording */
    public static function template(string $key, string $date): ?array
    {
        return DB::row('SELECT * FROM document_templates WHERE doc_key = :k AND is_active = 1 AND effective_date <= :d ORDER BY effective_date DESC, version_no DESC LIMIT 1', ['k' => $key, 'd' => $date]);
    }

    /** The document text for a date with the placeholders filled in (HTML) */
    public static function render(string $key, string $date, array $vars): string
    {
        $t = self::template($key, $date);
        $body = $t ? self::cleanHtml($t['body']) : self::standard($key);
        // the paper of a saved version is the page size the browser prints on
        $paper = $t && in_array($t['paper'], ['A4', 'Letter', 'Legal'], true) ? '<style>@page { size: ' . strtolower($t['paper']) . '; }</style>' : '';
        return $paper . preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn($m) => array_key_exists($m[1], $vars) ? h((string)$vars[$m[1]]) : $m[0], $body);
    }

    /** Keeps formatting tags only, and no attribute except colspan / rowspan */
    public static function cleanHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root') ?: $doc->documentElement;
        self::cleanNode($root);
        $out = '';
        foreach ($root->childNodes as $c) {
            $out .= $doc->saveHTML($c);
        }
        return $out;
    }

    private static function cleanNode(DOMNode $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $c = $node->childNodes->item($i);
            if ($c instanceof DOMElement) {
                $tag = strtolower($c->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'svg', 'math'], true)) {
                    $node->removeChild($c);
                    continue;
                }
                self::cleanNode($c);
                if (!in_array($tag, self::TAGS, true)) {
                    while ($c->firstChild) {
                        $node->insertBefore($c->firstChild, $c);
                    }
                    $node->removeChild($c);
                    continue;
                }
                for ($a = $c->attributes->length - 1; $a >= 0; $a--) {
                    $attr = $c->attributes->item($a);
                    if (!in_array(strtolower($attr->name), ['colspan', 'rowspan'], true)) {
                        $c->removeAttribute($attr->name);
                    }
                }
            } elseif ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction) {
                $node->removeChild($c);
            }
        }
    }

    /** The standard wording (used until the cooperative saves its own version) */
    public static function standard(string $key): string
    {
        return match ($key) {
            'promissory_note' => '<p>FOR VALUE RECEIVED, I/We, <b>{{borrower}}</b>, of {{borrower_address}}, member no. {{member_no}}, promise to pay jointly and severally to the order of <b>{{coop}}</b> at its office at {{coop_address}} the principal sum of <b>{{amount_words}}</b> (PHP {{amount}}), with interest at the rate of {{rate}} per cent ({{rate}}%) per annum, payable in {{installments}} {{mode}} amortizations of PHP {{amortization}} each, the first falling due on {{first_due}} and the last on {{maturity}}.</p>'
                . '<p>Any amortization not paid on its due date shall bear a penalty of {{penalty_rate}}% per annum on the amount in arrears after a grace period of {{grace_days}} day(s). Upon failure to pay any amortization when due, the whole unpaid balance shall, at the option of the holder, become immediately due and payable without need of demand.</p>'
                . '<p>I/We authorize the Cooperative to apply my/our deposits, share capital, interest on share capital and patronage refund to any amount due on this note, and to deduct the amortizations from my/our salary where a payroll deduction arrangement exists.</p>'
                . '<p>In case of judicial action, I/We agree to pay attorney\'s fees and costs of collection. Venue of any action shall be the courts of the place where the Cooperative holds office.</p>',
            'disclosure' => '<p>This disclosure statement is given before the loan is consummated, as required by the Truth in Lending Act (R.A. 3765). The finance charges and the effective interest rate shown above are computed on the net proceeds actually received by the borrower.</p>'
                . '<p>The borrower acknowledges having read and understood the terms above.</p>',
            'comaker' => '<p>I, <b>{{comaker}}</b>, bind myself jointly and severally with <b>{{borrower}}</b> for the payment of loan no. {{loan_no}} granted by {{coop}} on {{release_date}} in the amount of <b>{{amount_words}}</b> (PHP {{amount}}), maturing on {{maturity}}, including interest, penalties and other charges.</p>'
                . '<p>I authorize the Cooperative to apply my deposits, share capital, interest on share capital and patronage refund to the obligation in case the principal borrower defaults, and I waive notice of default and demand.</p>',
            'td_certificate' => '<p>This is to certify that <b>{{member}}</b> (member no. {{member_no}}) has placed with <b>{{coop}}</b> a time deposit of <b>{{principal_words}}</b> (PHP {{principal}}) on {{placement_date}} for {{term}}, at {{rate}}% per annum, maturing on <b>{{maturity_date}}</b> with a maturity value of PHP {{maturity_value}}.</p>'
                . '<p>At maturity: {{maturity_action}}. Withdrawal before maturity is subject to the pre-termination rate and fee of the product. This certificate is not negotiable.</p>',
            'share_certificate' => '<p>This certifies that <b>{{member}}</b>, member no. {{member_no}}, is the registered owner of <b>{{shares}}</b> fully paid-up share(s) of the capital stock of <b>{{coop}}</b>, with a par value of PHP {{par_value}} per share, or a total of <b>{{amount_words}}</b> (PHP {{amount}}), as of {{as_of}}.</p>'
                . '<p>The shares are transferable only on the books of the Cooperative, subject to its articles and by-laws.</p>',
            'balance_certificate' => '<p>This is to certify that <b>{{member}}</b>, member no. {{member_no}}, has been a {{member_type}} member of <b>{{coop}}</b> since {{membership_date}}, with membership status <b>{{status}}</b>, and has the following balances in the books of the Cooperative as of <b>{{as_of}}</b>:</p>',
            'member_notice' => '<p>{{today}}</p><p>Dear <b>{{member}}</b>,</p>'
                . '<p>This is a friendly reminder that your amortization on loan no. {{loan_no}} ({{product}}) of <b>PHP {{amount_due}}</b> is due on <b>{{due_date}}</b>. Penalties to date: PHP {{penalty}}. Outstanding principal: PHP {{balance}}.</p>'
                . '<p>Please disregard this notice if payment has been made. Thank you for keeping your account current.</p>',
            'demand_letter' => '<p>{{today}}</p><p><b>{{member}}</b><br>{{member_address}}</p><p>Dear Member,</p>'
                . '<p>Our records show that loan no. {{loan_no}} ({{product}}) is <b>{{days_past_due}} days past due</b>, with an amount due of <b>PHP {{amount_due}}</b> and an outstanding balance of PHP {{balance}}.</p>'
                . '<p>We request you to settle the amount due on or before <b>{{deadline}}</b>. Failing that, the Cooperative will be constrained to apply your deposits and share capital to the obligation and to take the appropriate action, including against your co-maker(s): {{comakers}}.</p>'
                . '<p>Please visit our office to discuss a payment arrangement if needed.</p>',
            default => '',
        };
    }
}
