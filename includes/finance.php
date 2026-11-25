<?php
// includes/finance.php — লাভ-ক্ষতি (Profit & Loss), ক্রয় ও খরচ রিপোর্টের হিসাব (helper)
// সূত্র: রাজস্ব = Σ অর্ডারের মোট (ডেলিভারি চার্জসহ, ডিসকাউন্টের পর); COGS = Σ (অর্ডার-আইটেমের ক্রয়মূল্য snapshot × পরিমাণ);
//        মোট লাভ = রাজস্ব − COGS; নিট লাভ = মোট লাভ − খরচ (expenses)। ক্রয় (ইনভেন্টরি কেনা) নিজে খরচ নয় — বিক্রি হলে COGS হয়ে আসে।
//        যে আইটেমের ক্রয়মূল্য অজানা (NULL) তা COGS-এ ০ ধরা হয় না — আলাদা সংখ্যা ও সতর্কতা দেখায়।
// অর্ডার: basis=all → বাতিল বাদে সব; basis=delivered → শুধু Delivered

require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/costs.php';
require_once __DIR__ . '/expenses.php';
require_once __DIR__ . '/purchases.php';

if (!function_exists('fi_pnl')) {

  function fi_basis($v): string { return $v === 'delivered' ? 'delivered' : 'all'; }

  /** @return array{0:string,1:array} */
  function fi_order_where(array $range, string $basis, string $alias = 'o'): array {
    [$w, $p] = rp_where($range, 'all', $alias);
    $w .= $basis === 'delivered' ? " AND $alias.status = 'delivered'" : " AND $alias.status <> 'cancelled'";
    return [$w, $p];
  }

  function fi_cost_expr(PDO $pdo, string $alias = 'oi'): string {
    return cs_has_column($pdo, 'order_items', 'cost') ? "$alias.cost" : 'NULL';
  }

  function fi_safe_expense_total(PDO $pdo, array $range): array {
    try { return [ex_total($pdo, $range), ex_count($pdo, $range)]; } catch (Throwable $e) { return [0.0, 0]; }
  }

  /**
   * লাভ-ক্ষতির সারাংশ।
   * @return array<string,float|int>
   */
  function fi_pnl(PDO $pdo, array $range, string $basis = 'all'): array {
    $basis = fi_basis($basis);
    [$w, $p] = fi_order_where($range, $basis);
    $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(o.subtotal),0), COALESCE(SUM(o.discount),0), COALESCE(SUM(o.shipping_fee),0), COALESCE(SUM(o.total),0) FROM orders o WHERE $w");
    $st->execute($p);
    [$n, $sub, $disc, $ship, $rev] = $st->fetch(PDO::FETCH_NUM);

    $c = fi_cost_expr($pdo);
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN $c IS NOT NULL THEN $c * oi.qty END),0),
                                COALESCE(SUM(CASE WHEN $c IS NULL THEN oi.price * oi.qty END),0),
                                COALESCE(SUM($c IS NULL),0),
                                COALESCE(SUM(CASE WHEN $c IS NOT NULL THEN oi.price * oi.qty END),0)
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $w");
    $st->execute($p);
    [$cogs, $unkSales, $unkLines, $knownSales] = $st->fetch(PDO::FETCH_NUM);

    [$exp, $expN] = fi_safe_expense_total($pdo, $range);

    $revC = pu_cents($rev); $cogsC = pu_cents($cogs); $expC = pu_cents($exp);
    return [
      'basis' => $basis, 'orders' => (int)$n,
      'net_sales' => pu_money(pu_cents($sub) - pu_cents($disc)), 'discount' => round((float)$disc, 2), 'delivery' => round((float)$ship, 2),
      'revenue' => pu_money($revC),
      'cogs' => pu_money($cogsC),
      'unknown_lines' => (int)$unkLines, 'unknown_sales' => round((float)$unkSales, 2),
      'known_sales' => round((float)$knownSales, 2), 'known_margin' => pu_money(pu_cents($knownSales) - $cogsC),
      'gross' => pu_money($revC - $cogsC),
      'expenses' => pu_money($expC), 'expense_count' => $expN,
      'net' => pu_money($revC - $cogsC - $expC),
    ];
  }

  /** সময়ের প্রতিটি মাসের রাজস্ব, COGS, খরচ, লাভ (সর্বোচ্চ ৩৬ মাস) */
  function fi_pnl_months(PDO $pdo, array $range, string $basis = 'all'): array {
    $basis = fi_basis($basis);
    $months = [];
    $cur = strtotime(date('Y-m-01', strtotime($range['from'])));
    $end = strtotime(date('Y-m-01', strtotime($range['to'])));
    while ($cur <= $end && count($months) < 36) {
      $k = date('Y-m', $cur);
      $months[$k] = ['key' => $k, 'label' => date('M Y', $cur), 'orders' => 0, 'revenue' => 0.0, 'cogs' => 0.0, 'expenses' => 0.0];
      $cur = strtotime('+1 month', $cur);
    }
    [$w, $p] = fi_order_where($range, $basis);
    $st = $pdo->prepare("SELECT DATE_FORMAT(o.created_at,'%Y-%m') k, COUNT(*), COALESCE(SUM(o.total),0) FROM orders o WHERE $w GROUP BY k");
    $st->execute($p);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $n, $rev]) if (isset($months[$k])) { $months[$k]['orders'] = (int)$n; $months[$k]['revenue'] = round((float)$rev, 2); }
    $c = fi_cost_expr($pdo);
    $st = $pdo->prepare("SELECT DATE_FORMAT(o.created_at,'%Y-%m') k, COALESCE(SUM(CASE WHEN $c IS NOT NULL THEN $c * oi.qty END),0)
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $w GROUP BY k");
    $st->execute($p);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $cg]) if (isset($months[$k])) $months[$k]['cogs'] = round((float)$cg, 2);
    try {
      $st = $pdo->prepare("SELECT DATE_FORMAT(expense_date,'%Y-%m') k, COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date <= ? GROUP BY k");
      $st->execute([$range['from'], $range['to']]);
      foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $ex]) if (isset($months[$k])) $months[$k]['expenses'] = round((float)$ex, 2);
    } catch (Throwable $e) {}
    foreach ($months as &$m) $m['profit'] = pu_money(pu_cents($m['revenue']) - pu_cents($m['cogs']) - pu_cents($m['expenses']));
    unset($m);
    return array_values($months);
  }

  /** পণ্য অনুযায়ী লাভ (শুধু ক্রয়মূল্য-জানা আইটেম; ডিসকাউন্ট ও ডেলিভারি আগে) */
  function fi_profit_by_product(PDO $pdo, array $range, string $basis = 'all', int $limit = 10): array {
    [$w, $p] = fi_order_where($range, fi_basis($basis));
    $c = fi_cost_expr($pdo);
    $st = $pdo->prepare("SELECT COALESCE(pr.name, CONCAT('পণ্য #', oi.product_id)) AS name, SUM(oi.qty) AS qty, SUM(oi.price*oi.qty) AS sales, SUM($c*oi.qty) AS cost
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN products pr ON pr.id = oi.product_id
                         WHERE $w AND $c IS NOT NULL GROUP BY oi.product_id, pr.name ORDER BY (SUM(oi.price*oi.qty) - SUM($c*oi.qty)) DESC LIMIT " . max(1, min(100, $limit)));
    $st->execute($p);
    return array_map(fn($r) => ['name' => $r['name'], 'qty' => (int)$r['qty'], 'sales' => round((float)$r['sales'], 2), 'cost' => round((float)$r['cost'], 2),
                                'profit' => pu_money(pu_cents($r['sales']) - pu_cents($r['cost']))], $st->fetchAll(PDO::FETCH_ASSOC));
  }

  function fi_profit_by_category(PDO $pdo, array $range, string $basis = 'all'): array {
    [$w, $p] = fi_order_where($range, fi_basis($basis));
    $c = fi_cost_expr($pdo);
    $st = $pdo->prepare("SELECT COALESCE(ca.name,'ক্যাটেগরি নেই') AS name, SUM(oi.qty) AS qty, SUM(oi.price*oi.qty) AS sales, SUM($c*oi.qty) AS cost
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN products pr ON pr.id = oi.product_id LEFT JOIN categories ca ON ca.id = pr.category_id
                         WHERE $w AND $c IS NOT NULL GROUP BY ca.id, ca.name ORDER BY (SUM(oi.price*oi.qty) - SUM($c*oi.qty)) DESC");
    $st->execute($p);
    return array_map(fn($r) => ['name' => $r['name'], 'qty' => (int)$r['qty'], 'sales' => round((float)$r['sales'], 2), 'cost' => round((float)$r['cost'], 2),
                                'profit' => pu_money(pu_cents($r['sales']) - pu_cents($r['cost']))], $st->fetchAll(PDO::FETCH_ASSOC));
  }

  /**
   * প্রতি অর্ডারের লাভ: মোট − Σ(ক্রয়মূল্য × পরিমাণ); কোনো আইটেমের ক্রয়মূল্য অজানা হলে null (অজানা)।
   * @param array $rows অর্ডার-সারি (কমপক্ষে id, total)
   * @return array<int,array{cost:float,unknown:int,profit:?float}>
   */
  function fi_order_profits(PDO $pdo, array $rows): array {
    $ids = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $rows)));
    if (!$ids) return [];
    $c = fi_cost_expr($pdo);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT oi.order_id, COALESCE(SUM(CASE WHEN $c IS NOT NULL THEN $c * oi.qty END),0), COALESCE(SUM($c IS NULL),0) FROM order_items oi WHERE oi.order_id IN ($ph) GROUP BY oi.order_id");
    $st->execute($ids);
    $agg = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$oid, $cost, $unk]) $agg[(int)$oid] = ['cost' => round((float)$cost, 2), 'unknown' => (int)$unk];
    $out = [];
    foreach ($rows as $r) {
      $a = $agg[(int)$r['id']] ?? ['cost' => 0.0, 'unknown' => 1];
      $out[(int)$r['id']] = ['cost' => $a['cost'], 'unknown' => $a['unknown'], 'profit' => $a['unknown'] > 0 ? null : pu_money(pu_cents($r['total']) - pu_cents($a['cost']))];
    }
    return $out;
  }

  /* ===================== ক্রয় রিপোর্ট ===================== */

  /** সরবরাহকারী অনুযায়ী (ক্রয়ের তারিখের সময়ে, বাতিল বাদে): ক্রয়, মোট, পরিশোধ (এখন পর্যন্ত), বাকি */
  function fi_purchases_by_supplier(PDO $pdo, array $range): array {
    $st = $pdo->prepare("SELECT COALESCE(s.name,'—') AS name, COUNT(*) AS cnt, COALESCE(SUM(pu.total),0) AS total, COALESCE(SUM(py.paid),0) AS paid
      FROM purchases pu LEFT JOIN suppliers s ON s.id = pu.supplier_id
      LEFT JOIN (SELECT purchase_id, SUM(amount) paid FROM purchase_payments GROUP BY purchase_id) py ON py.purchase_id = pu.id
      WHERE pu.status='active' AND pu.purchase_date >= ? AND pu.purchase_date <= ? GROUP BY pu.supplier_id, s.name ORDER BY total DESC");
    $st->execute([$range['from'], $range['to']]);
    return array_map(fn($r) => ['name' => $r['name'], 'count' => (int)$r['cnt'], 'total' => round((float)$r['total'], 2), 'paid' => round((float)$r['paid'], 2),
                                'due' => pu_money(pu_cents($r['total']) - pu_cents($r['paid']))], $st->fetchAll(PDO::FETCH_ASSOC));
  }

  /** সবচেয়ে বেশি কেনা পণ্য (পরিমাণ ও টাকা, গড় ক্রয়-দাম) */
  function fi_purchases_by_product(PDO $pdo, array $range, int $limit = 10): array {
    $st = $pdo->prepare("SELECT i.product_name AS name, SUM(i.qty) AS qty, SUM(i.line_total) AS amount
      FROM purchase_items i JOIN purchases pu ON pu.id = i.purchase_id
      WHERE pu.status='active' AND pu.purchase_date >= ? AND pu.purchase_date <= ? GROUP BY i.product_id, i.product_name ORDER BY amount DESC LIMIT " . max(1, min(100, $limit)));
    $st->execute([$range['from'], $range['to']]);
    return array_map(fn($r) => ['name' => $r['name'], 'qty' => (int)$r['qty'], 'amount' => round((float)$r['amount'], 2), 'avg' => (int)$r['qty'] > 0 ? round((float)$r['amount'] / (int)$r['qty'], 2) : 0.0], $st->fetchAll(PDO::FETCH_ASSOC));
  }

  /** এই সময়ে সরবরাহকারীকে আসলে দেওয়া টাকা (পরিশোধের তারিখ অনুযায়ী; বাতিল ক্রয় বাদে) */
  function fi_purchase_payments_in(PDO $pdo, array $range): array {
    $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(x.amount),0) FROM purchase_payments x JOIN purchases pu ON pu.id = x.purchase_id
                         WHERE pu.status='active' AND x.paid_date >= ? AND x.paid_date <= ?");
    $st->execute([$range['from'], $range['to']]);
    [$n, $sum] = $st->fetch(PDO::FETCH_NUM);
    return ['count' => (int)$n, 'amount' => round((float)$sum, 2)];
  }

  /** খরচের মাসভিত্তিক যোগফল (সর্বোচ্চ ৩৬ মাস) */
  function fi_expense_months(PDO $pdo, array $range): array {
    $months = [];
    $cur = strtotime(date('Y-m-01', strtotime($range['from'])));
    $end = strtotime(date('Y-m-01', strtotime($range['to'])));
    while ($cur <= $end && count($months) < 36) { $k = date('Y-m', $cur); $months[$k] = ['key' => $k, 'label' => date('M Y', $cur), 'amount' => 0.0, 'count' => 0]; $cur = strtotime('+1 month', $cur); }
    $st = $pdo->prepare("SELECT DATE_FORMAT(expense_date,'%Y-%m') k, COALESCE(SUM(amount),0), COUNT(*) FROM expenses WHERE expense_date >= ? AND expense_date <= ? GROUP BY k");
    $st->execute([$range['from'], $range['to']]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $a, $n]) if (isset($months[$k])) { $months[$k]['amount'] = round((float)$a, 2); $months[$k]['count'] = (int)$n; }
    return array_values($months);
  }

  /** সবচেয়ে বড় খরচগুলো */
  function fi_top_expenses(PDO $pdo, array $range, ?string $cat, int $limit = 5): array {
    [$w, $p] = ex_where($range, $cat);
    $st = $pdo->prepare("SELECT e.* FROM expenses e WHERE $w ORDER BY e.amount DESC, e.id DESC LIMIT " . max(1, min(50, $limit)));
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /** রিপোর্ট-পেজের ট্যাব (ডেস্কটপ-মোবাইল দুটোতেই চলে) */
  function fi_tabs(string $active): string {
    $tabs = ['orders' => ['report_orders.php', 'অর্ডার'], 'profit' => ['report_profit.php', 'লাভ-ক্ষতি'], 'purchases' => ['report_purchases.php', 'ক্রয়'], 'expenses' => ['report_expenses.php', 'খরচ'], 'stock' => ['stock_report.php', 'স্টক']];
    $h = '<ul class="nav nav-pills flex-nowrap overflow-auto mb-3" id="rpTabs" style="white-space:nowrap">';
    foreach ($tabs as $k => [$href, $label]) {
      if ($k === 'stock' && function_exists('can') && !can('view_stock_report')) continue;
      $h .= '<li class="nav-item"><a class="nav-link py-1 px-3' . ($k === $active ? ' active' : '') . '" href="' . $href . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $h . '</ul>';
  }
}
