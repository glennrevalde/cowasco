<?php
/**
 * organizationUnitHelpers.php
 *
 * The water forms ask for one Class (Chart of Accounts > Class List, the same list the accounting books use) where
 * the former program asked for a Branch, a Segment and a Division. The class is required on every water transaction
 * that posts to the ledger and goes on its journal entries (WaterPosting::withClass()).
 *
 * The former organization-unit tables stay (branches, ic_divisions, ic_segments) for the records made before; their
 * lookups below are kept for those pages and reports.
 */

if (!function_exists('get_active_branches')) {
    function get_active_branches(PDO $pdo): array
    {
        return $pdo->query("
            SELECT pkey, name, description
            FROM branches
            WHERE Deleted = 0
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_active_divisions')) {
    function get_active_divisions(PDO $pdo): array
    {
        return $pdo->query("
            SELECT pkey, name
            FROM ic_divisions
            WHERE status = 'A'
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_active_segments')) {
    function get_active_segments(PDO $pdo): array
    {
        return $pdo->query("
            SELECT pkey, name
            FROM ic_segments
            WHERE status = 'A'
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('org_int')) {
    function org_int($value): int
    {
        return (int)($value ?? 0);
    }
}

if (!function_exists('render_org_unit_select')) {
    function render_org_unit_select(string $name, array $rows, $selected = null, string $placeholder = 'Default / None', bool $required = false, string $id = ''): string
    {
        $idAttr = $id !== '' ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '';
        $validRows = array_values(array_filter($rows, function ($row) {
            return isset($row['pkey']) && (string)$row['pkey'] !== '';
        }));

        // If Branch / Division / Segment has exactly one active value, select it automatically.
        // This prevents operators from having to click a dropdown when there is only one possible accounting dimension.
        if (($selected === null || $selected === '' || (int)$selected === 0) && count($validRows) === 1) {
            $selected = (string)$validRows[0]['pkey'];
        }

        $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' . $idAttr . ' ' . ($required ? 'required' : '') . ' data-searchable="true" class="form-control org-unit-select">';
        $html .= '<option value="">' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '</option>';

        foreach ($rows as $row) {
            $value = (string)($row['pkey'] ?? '');
            $label = (string)($row['name'] ?? '');
            $isSelected = ((string)$selected === $value) ? ' selected' : '';

            $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>';
            $html .= htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $html .= '</option>';
        }

        $html .= '</select>';
        return $html;
    }
}

if (!function_exists('water_class_ready')) {
    /** Class tracking (Chart of Accounts > Class List) is installed */
    function water_class_ready(): bool
    {
        return class_exists('Classes') && Classes::ready();
    }
}

if (!function_exists('render_water_class_field')) {
    /**
     * The Class box of a water form. Branch, Segment and Division are no longer asked on the water forms: the class
     * (the same Class List the accounting books use) says where the transaction belongs, and every journal entry the
     * transaction posts carries it. Required unless $required is false; it starts on the class this user chose last.
     */
    function render_water_class_field($selected = null, bool $required = true, string $id = 'classId', string $name = 'class_id', string $inputClass = 'form-control', string $label = 'Class'): string
    {
        if (!water_class_ready()) {
            return '';
        }
        $selected = (int)($selected ?? 0) ?: (Classes::lastUsed() ?? 0);
        $options = Classes::options($selected ?: null);
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $html = '<div class="water-class-field">'
            . '<label for="' . $e($id) . '" class="water-class-label">' . $e($label) . ($required ? ' <span class="water-class-req">(required)</span>' : '') . '</label>';
        if (!$options) {
            return $html . '<select id="' . $e($id) . '" name="' . $e($name) . '" class="' . $e($inputClass) . '" ' . ($required ? 'required' : '') . ' disabled><option value="">No class set up</option></select>'
                . '<div class="water-class-hint is-bad">Add the classes on Financial &gt; Class List first: this transaction is not saved without a class.</div></div>';
        }
        $html .= '<select id="' . $e($id) . '" name="' . $e($name) . '" class="' . $e($inputClass) . ' water-class-select" data-searchable="true"' . ($required ? ' required' : '') . '>'
            . '<option value="">Choose a class</option>';
        foreach ($options as $cid => $cname) {
            $html .= '<option value="' . (int)$cid . '"' . ((int)$cid === (int)$selected ? ' selected' : '') . '>' . $e($cname) . '</option>';
        }
        return $html . '</select><div class="water-class-hint">The same Class List as the accounting books; the journal entry carries it.</div></div>';
    }
}

if (!function_exists('water_required_class')) {
    /** The class chosen on a water form: refused when missing or inactive; remembered as this user's last class */
    function water_required_class($value, string $what = 'this transaction'): int
    {
        if (!water_class_ready()) {
            throw new RuntimeException('Class tracking is not installed: run the upgrade scripts first.');
        }
        $id = (int)(is_scalar($value) ? $value : 0);
        if ($id <= 0) {
            throw new RuntimeException(Classes::inUse()
                ? 'Choose the class of ' . $what . ': it is required, and its journal entry carries it.'
                : 'Set up the classes first (Financial > Class List): ' . $what . ' is not saved without a class.');
        }
        $row = Classes::all()[$id] ?? null;
        if (!$row) {
            throw new RuntimeException('The class chosen no longer exists. Choose another class.');
        }
        if ((int)$row['is_active'] !== 1) {
            throw new RuntimeException('The class ' . $row['name'] . ' is no longer active. Choose another class.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['last_class_id'] = $id;
        }
        return $id;
    }
}

if (!function_exists('water_class_columns')) {
    /** The water records keep their class (upgrade 2026_12_20) */
    function water_class_columns(): bool
    {
        return class_exists('WaterPosting') && WaterPosting::classColumns();
    }
}

if (!function_exists('water_class_name')) {
    function water_class_name($id): string
    {
        return water_class_ready() && (int)$id > 0 ? Classes::name((int)$id) : '';
    }
}

if (!function_exists('render_org_unit_fields')) {
    /**
     * Formerly the Branch / Segment / Division boxes of a water form; now the one Class box (the forms that still
     * call this get the class field). $prefix names a second set on the same page; $selected may carry class_id.
     */
    function render_org_unit_fields(PDO $pdo, string $prefix = '', array $selected = []): string
    {
        $name = $prefix !== '' ? $prefix . '_class_id' : 'class_id';
        $id = $prefix !== '' ? $prefix . 'ClassId' : 'classId';
        return render_water_class_field($selected['class_id'] ?? null, true, $id, $name);
    }
}
