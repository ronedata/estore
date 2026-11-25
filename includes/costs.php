<?php
// includes/costs.php — পণ্যের ক্রয়মূল্য (গড়) ও অর্ডার-আইটেমে ক্রয়মূল্য snapshot (helper)
// নিয়ম: cost_price = NULL মানে "অজানা" (০ নয়); কলাম না থাকলে (মাইগ্রেশন চলেনি) সব ফাংশন চুপচাপ বাদ যায়, অর্ডার/সেভ আটকায় না

if (!defined('CS_MAX')) define('CS_MAX', 10000000);

if (!function_exists('cs_parse_money')) {

  /** '' → null (অজানা/ফাঁকা); সংখ্যা ≥০ → float (২ দশমিক); অবৈধ → false */
  function cs_parse_money($v) {
    if ($v === null) return null;
    if (!is_scalar($v)) return false;
    $v = trim((string)$v);
    if ($v === '') return null;
    if (!is_numeric($v)) return false;
    $f = (float)$v;
    if (!is_finite($f) || $f < 0 || $f > CS_MAX) return false;
    return round($f, 2);
  }

  /** কলাম আছে কি (প্রতি রিকোয়েস্টে একবার করে মেপে রাখে) */
  function cs_has_column(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $k = $table . '.' . $col;
    if (!isset($cache[$k])) {
      try { $cache[$k] = in_array($col, $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN), true); }
      catch (Throwable $e) { $cache[$k] = false; }
    }
    return $cache[$k];
  }

  /** পণ্যের ক্রয়মূল্য সেট (null = অজানা)। কলাম না থাকলে false */
  function cs_set_product_cost(PDO $pdo, int $productId, ?float $cost): bool {
    if ($productId <= 0 || !cs_has_column($pdo, 'products', 'cost_price')) return false;
    try { return $pdo->prepare("UPDATE products SET cost_price=? WHERE id=?")->execute([$cost, $productId]); }
    catch (Throwable $e) { return false; }
  }

  /**
   * অর্ডার-আইটেমে "এখনকার" পণ্যের ক্রয়মূল্য লিখে রাখে (শুধু দেওয়া আইটেম আইডি)। পরে ক্রয়মূল্য বদলালে পুরোনো অর্ডারের লাভ বদলায় না।
   */
  function cs_snapshot_items(PDO $pdo, array $itemIds): int {
    $ids = array_values(array_filter(array_map('intval', $itemIds), fn($i) => $i > 0));
    if (!$ids || !cs_has_column($pdo, 'order_items', 'cost') || !cs_has_column($pdo, 'products', 'cost_price')) return 0;
    try {
      $ph = implode(',', array_fill(0, count($ids), '?'));
      $st = $pdo->prepare("UPDATE order_items oi JOIN products p ON p.id = oi.product_id SET oi.cost = p.cost_price WHERE oi.id IN ($ph)");
      $st->execute($ids);
      return $st->rowCount();
    } catch (Throwable $e) { return 0; }
  }

  /** একটি অর্ডারের সব আইটেমে snapshot (নতুন অর্ডার তৈরির পর) */
  function cs_snapshot_order(PDO $pdo, int $orderId): int {
    try {
      $st = $pdo->prepare("SELECT id FROM order_items WHERE order_id=?");
      $st->execute([$orderId]);
      return cs_snapshot_items($pdo, $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return 0; }
  }

  /**
   * গড় ক্রয়মূল্য (weighted average): নতুন ক্রয়ে আগের স্টক ও নতুন স্টক মিলিয়ে।
   * আগের স্টক ০/ঋণাত্মক বা আগের দাম অজানা হলে নতুন ক্রয়-দামই গড়।
   */
  function cs_weighted_avg(int $oldStock, ?float $oldCost, int $qty, float $unitCost): float {
    if ($qty <= 0) return $oldCost ?? round($unitCost, 2);
    if ($oldCost === null || $oldStock <= 0) return round($unitCost, 2);
    return round(($oldStock * $oldCost + $qty * $unitCost) / ($oldStock + $qty), 2);
  }
}
