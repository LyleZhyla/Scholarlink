<?php
// ✨ SCHOLARSHIP CATEGORIES (Scholarship / Incentive / Allowance)
// Single list used by the scholarship builders (External/Super Admin), the save handler,
// and every scholarship filter. Add or rename categories here only.

if (!defined('SCHOLARSHIP_CATEGORIES')) {
    define('SCHOLARSHIP_CATEGORIES', [
        'Scholarship' => '🎓',
        'Incentive'   => '🏆',
        'Allowance'   => '💵',
    ]);
    define('DEFAULT_SCHOLARSHIP_CATEGORY', 'Scholarship');
}

if (!function_exists('categoryLabel')) {
    // "🎓 Scholarship"
    function categoryLabel(?string $category): string {
        $category = $category ?: DEFAULT_SCHOLARSHIP_CATEGORY;
        return (SCHOLARSHIP_CATEGORIES[$category] ?? '🏷️') . ' ' . $category;
    }
}
