<?php
declare(strict_types=1);

/**
 * FB POS (v35): a food and beverage counter. It sells exactly as the POS Register does (the same terminals, shifts,
 * payment methods, receipts, posting and reports); only the screen is different: the items are pictures with their
 * price, under menu groups the user names and orders (Point of Sale > FB POS Menu & Groups).
 *
 * An item is on the FB POS when it is ticked "Show on the FB POS" (Item List, or the Menu & Groups page): inventory
 * parts, assemblies, non-inventory parts, services, other charges and item groups. Its picture is kept in
 * storage/items (storage/companies/cNNN/items for the other companies), not reachable from the web, and is sent
 * through index.php?p=file.item_image&id=N to logged-in users.
 */
final class FbPos
{
    public const TYPES = ['inventory', 'assembly', 'non_inventory', 'service', 'other_charge', 'group'];
    public const IMG_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    public const IMG_MAX = 3145728;   // 3 MB

    /** Whether the FB POS columns and the groups table are there (database/migrations/2027_05_09_accounting_update_v40.sql) */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(fb_pos) + COUNT(fb_group_id) + COUNT(image_file) FROM items WHERE 1 = 0');
                DB::value('SELECT COUNT(*) FROM fb_pos_groups WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** The menu groups: id => [id, name, sort_order, is_active], in their order */
    public static function groups(bool $activeOnly = false): array
    {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT * FROM fb_pos_groups' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name') as $g) {
            $out[(int)$g['id']] = $g;
        }
        return $out;
    }

    /** id => name of the groups, for drop-downs */
    public static function groupOptions(?int $keep = null): array
    {
        $out = [];
        foreach (self::groups() as $id => $g) {
            if ((int)$g['is_active'] === 1 || $id === $keep) {
                $out[$id] = $g['name'];
            }
        }
        return $out;
    }

    public static function dir(): string
    {
        return Tenant::storage('items');
    }

    /** The picture of an item on disk, or null */
    public static function imagePath(?string $file): ?string
    {
        $name = basename((string)$file);
        if ($name === '') {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** The address of an item's picture ('' when it has none) */
    public static function imageUrl(array $item): string
    {
        $path = self::imagePath($item['image_file'] ?? null);
        return $path ? url('file.item_image', ['id' => (int)$item['id'], 'v' => (string)filemtime($path)]) : '';
    }

    /** Stores an uploaded picture for an item ($_FILES entry); returns the file name saved */
    public static function storeImage(int $itemId, array $upload): string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail(in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The picture is too large.' : 'The picture could not be uploaded. Try again.');
        }
        if ((int)$upload['size'] > self::IMG_MAX) {
            fail('The picture is larger than 3 MB. Save a smaller copy and upload that.');
        }
        $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
        if (!isset(self::IMG_TYPES[$ext])) {
            fail('Upload a picture file: .jpg, .png, .gif or .webp.');
        }
        $info = @getimagesize($upload['tmp_name']);
        if (!$info || !in_array($info['mime'], self::IMG_TYPES, true)) {
            fail('That file is not a picture the system can show. Use a JPG or PNG file.');
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            fail('The folder for item pictures could not be created. Make the storage folder writable by the web server.');
        }
        $name = 'item_' . $itemId . '_' . date('YmdHis') . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $name)) {
            fail('The picture could not be saved. Make the storage folder writable by the web server.');
        }
        self::removeImage($itemId, $name);
        return $name;
    }

    /** Deletes the pictures of an item (all but $keep) */
    public static function removeImage(int $itemId, ?string $keep = null): void
    {
        foreach (glob(self::dir() . '/item_' . $itemId . '_*') ?: [] as $old) {
            if (basename($old) !== $keep) {
                @unlink($old);
            }
        }
    }

    /** Sends an item's picture (index.php?p=file.item_image&id=N) */
    public static function send(int $itemId): never
    {
        $file = self::ready() ? DB::value('SELECT image_file FROM items WHERE id = :id', ['id' => $itemId]) : null;
        $path = self::imagePath($file ? (string)$file : null);
        if (!$path) {
            http_response_code(404);
            exit;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        header('Content-Type: ' . (self::IMG_TYPES[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=604800');
        readfile($path);
        exit;
    }

    /** Two letters for an item without a picture (PB for Pork Burger) */
    public static function initials(string $name): string
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $name) ?: []));
        $s = mb_substr($words[0] ?? '?', 0, 1) . mb_substr($words[1] ?? '', 0, 1);
        return mb_strtoupper($s ?: '?');
    }
}
