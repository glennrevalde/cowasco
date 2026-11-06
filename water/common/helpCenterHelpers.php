<?php
/**
 * Shared helpers for the water-billing Help Center and in-app user manual.
 */

if (!function_exists('hc_escape')) {
    function hc_escape($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('hc_manual_content_path')) {
    function hc_manual_content_path(): string
    {
        return __DIR__ . '/../resources/manuals/user_manual_content.json';
    }
}

if (!function_exists('hc_load_manual_content')) {
    function hc_load_manual_content(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $path = hc_manual_content_path();
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The user manual content file is missing or unreadable.');
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('The user manual content could not be loaded.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('The user manual content has an invalid structure.');
        }

        foreach (['metadata', 'articles', 'chapters', 'faqs', 'glossary', 'troubleshooting'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $decoded)) {
                throw new RuntimeException('The user manual is missing the required section: ' . $requiredKey);
            }
        }

        $cache = $decoded;
        return $cache;
    }
}

if (!function_exists('hc_article_categories')) {
    function hc_article_categories(array $articles): array
    {
        $categories = [];
        foreach ($articles as $article) {
            $category = trim((string)($article['category'] ?? 'Other'));
            if ($category === '') {
                $category = 'Other';
            }
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }
        ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        return $categories;
    }
}

if (!function_exists('hc_article_search_text')) {
    function hc_article_search_text(array $article): string
    {
        $parts = [
            $article['title'] ?? '',
            $article['summary'] ?? '',
            $article['category'] ?? '',
            implode(' ', (array)($article['keywords'] ?? [])),
            implode(' ', (array)($article['steps'] ?? [])),
            implode(' ', (array)($article['notes'] ?? [])),
        ];
        return strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? ''));
    }
}

if (!function_exists('hc_can_open_article_page')) {
    function hc_can_open_article_page(PDO $pdo, array $article): bool
    {
        $pageKey = trim((string)($article['page'] ?? ''));
        if ($pageKey === '' || $pageKey === 'help_center' || $pageKey === 'user_manual') {
            return true;
        }
        if (str_contains($pageKey, '.') && class_exists('Access')) {
            return Access::page($pageKey);
        }
        return function_exists('userCanAccessPage') && userCanAccessPage($pdo, $pageKey);
    }
}

if (!function_exists('hc_safe_anchor')) {
    function hc_safe_anchor(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
