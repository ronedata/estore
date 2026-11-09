<?php
/**
 * admin/permission_labels.php — permission identifier → মেনুর বাংলা নাম + গ্রুপ
 *
 * সাইডবারের (admin/header.php) সাথে মিলিয়ে রাখা হয়েছে। নতুন মেনু যোগ করলে
 * এখানেও একটি এন্ট্রি দিন — নাহলে identifier-ই দেখাবে (fallback)।
 */

/* সরাসরি অ্যাক্সেস ব্লক — এটি শুধু include করার helper, স্বতন্ত্র পেজ নয় */
if (!defined('BASE_URL')) { http_response_code(403); exit; }

if (!function_exists('permission_label')) {
  function permission_label_map(): array {
    return [
      'view_dashboard'       => ['label' => 'ড্যাশবোর্ড',            'icon' => 'fa-gauge-high',        'group' => 'সাধারণ'],
      'manage_products'      => ['label' => 'পণ্য ব্যবস্থাপনা',      'icon' => 'fa-box',               'group' => 'ক্যাটালগ'],
      'manage_products_quick'=> ['label' => 'দ্রুত পণ্য যোগ',        'icon' => 'fa-mobile-screen-button','group' => 'ক্যাটালগ'],
      'manage_categories'    => ['label' => 'ক্যাটেগরি',             'icon' => 'fa-tags',              'group' => 'ক্যাটালগ'],
      'manage_sliders'       => ['label' => 'স্লাইডার',              'icon' => 'fa-images',            'group' => 'ক্যাটালগ'],
      'manage_home_sections' => ['label' => 'হোম সেকশন',             'icon' => 'fa-table-cells-large', 'group' => 'ক্যাটালগ'],
      'manage_announcements' => ['label' => 'ঘোষণা',                  'icon' => 'fa-bullhorn',          'group' => 'ক্যাটালগ'],
      'manage_delivery'      => ['label' => 'ডেলিভারি চার্জ',          'icon' => 'fa-truck-fast',        'group' => 'বিক্রয়'],
      'manage_reviews'       => ['label' => 'রিভিউ',                'icon' => 'fa-star-half-stroke',  'group' => 'কনটেন্ট'],
      'manage_customers'     => ['label' => 'কাস্টমার',              'icon' => 'fa-user-group',        'group' => 'কনটেন্ট'],
      'manage_pages'         => ['label' => 'পেজ',                   'icon' => 'fa-file-lines',        'group' => 'কনটেন্ট'],
      'view_page_views'      => ['label' => 'Page Views',            'icon' => 'fa-chart-simple',      'group' => 'রিপোর্ট'],
      'manage_orders'        => ['label' => 'অর্ডার ব্যবস্থাপনা',    'icon' => 'fa-list-check',        'group' => 'বিক্রয়'],
      'manage_admin_users'   => ['label' => 'ইউজার ও রোল',           'icon' => 'fa-users-gear',        'group' => 'সিস্টেম'],
      'view_stock_report'    => ['label' => 'স্টক রিপোর্ট',          'icon' => 'fa-warehouse',         'group' => 'রিপোর্ট'],
      'payments'             => ['label' => 'পেমেন্ট',               'icon' => 'fa-credit-card',       'group' => 'বিক্রয়'],
      'settings'             => ['label' => 'সেটিংস',                'icon' => 'fa-gear',              'group' => 'সিস্টেম'],
    ];
  }

  /** permission identifier থেকে বাংলা মেনু-নাম */
  function permission_label(string $name): string {
    $map = permission_label_map();
    return $map[$name]['label'] ?? $name;
  }

  /** permission identifier থেকে FontAwesome আইকন ক্লাস */
  function permission_icon(string $name): string {
    $map = permission_label_map();
    return $map[$name]['icon'] ?? 'fa-key';
  }

  /** permission identifier থেকে গ্রুপ নাম */
  function permission_group(string $name): string {
    $map = permission_label_map();
    return $map[$name]['group'] ?? 'অন্যান্য';
  }

  /** গ্রুপ অনুযায়ী সাজানো (গ্রুপের ক্রম ম্যাপে যেভাবে এসেছে সেভাবেই) */
  function permission_grouped(array $permissions): array {
    $order = [];
    foreach (permission_label_map() as $v) {
      if (!in_array($v['group'], $order, true)) $order[] = $v['group'];
    }
    $out = [];
    foreach ($order as $g) $out[$g] = [];

    $extra = [];
    foreach ($permissions as $p) {
      $g = permission_group((string)$p['name']);
      if (isset($out[$g])) $out[$g][] = $p;
      else $extra[] = $p;
    }
    if ($extra) $out['অন্যান্য'] = $extra;

    // খালি গ্রুপ বাদ
    return array_filter($out, fn($rows) => !empty($rows));
  }

  /** যে permission গুলো সাইডবারে নেই (নতুন যোগ হয়েছে কিন্তু ম্যাপে নেই) */
  function permission_unmapped(array $permissions): array {
    $map = permission_label_map();
    return array_values(array_filter($permissions, fn($p) => !isset($map[(string)$p['name']])));
  }
}
