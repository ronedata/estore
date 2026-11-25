<?php
// tools/test_finance.php — লাভ-ক্ষতি (P&L), অর্ডার-লাভ, ক্রয় ও খরচ রিপোর্টের হিসাব (includes/finance.php) — লজিক টেস্ট
// php tools/test_finance.php   ("[T]" ডেটা ২০১৯ সালে বানায়, শেষে মুছে ফেলে; আসল ডেটা ছোঁয় না)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/finance.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}
function near($a, $b): bool { return abs((float)$a - (float)$b) < 0.005; }
$clean = function () use ($pdo) {
  $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM (SELECT id FROM orders WHERE customer_name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM orders WHERE customer_name LIKE '[T]%'");
  $pdo->exec("DELETE FROM expenses WHERE title LIKE '[T]%'");
  $pdo->exec("DELETE FROM purchase_payments WHERE purchase_id IN (SELECT id FROM (SELECT p.id FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE s.name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM purchase_items WHERE purchase_id IN (SELECT id FROM (SELECT p.id FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE s.name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM purchases WHERE supplier_id IN (SELECT id FROM (SELECT id FROM suppliers WHERE name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM suppliers WHERE name LIKE '[T]%'");
  $pdo->exec("DELETE FROM products WHERE name LIKE '[T]%'");
};
$clean();
$NOW = strtotime('2026-11-25 12:00:00');

$cats = $pdo->query("SELECT id, name FROM categories ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($cats) < 2) { echo "  SKIP  কমপক্ষে দুটি ক্যাটেগরি লাগে\n"; exit(1); }
$mkP = function (string $n, int $cat) use ($pdo): int { $pdo->prepare("INSERT INTO products (category_id, name, price, stock, active) VALUES (?,?,500,10,1)")->execute([$cat, $n]); return (int)$pdo->lastInsertId(); };
$P1 = $mkP('[T] P1', (int)$cats[0]['id']); $P2 = $mkP('[T] P2', (int)$cats[1]['id']);
$mkO = function (string $code, string $when, string $status, float $sub, float $disc, float $ship, array $items) use ($pdo): int {
  $pdo->prepare("INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_fee, subtotal, discount, total, payment_method, status, created_at) VALUES (?, '[T] F', '01700000000', '[T]', 'dhaka', ?, ?, ?, ?, 'COD', ?, ?)")
      ->execute([$code, $ship, $sub, $disc, round($sub - $disc + $ship, 2), $status, $when]);
  $id = (int)$pdo->lastInsertId();
  foreach ($items as [$pid, $q, $pr, $cost]) $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, price, cost) VALUES (?,?,?,?,?)")->execute([$id, $pid, $q, $pr, $cost]);
  return $id;
};
$O1 = $mkO('ORD9950001', '2019-03-05 10:00:00', 'delivered', 1040, 40, 60, [[$P1, 2, 400, 250], [$P2, 1, 240, 100]]);
$O2 = $mkO('ORD9950002', '2019-03-20 10:00:00', 'pending',   450,  0,  50, [[$P1, 1, 450, 260]]);
$O3 = $mkO('ORD9950003', '2019-03-25 10:00:00', 'shipped',   900,  0,  60, [[$P2, 3, 300, null]]);
$O4 = $mkO('ORD9950004', '2019-03-28 10:00:00', 'cancelled', 10000, 0, 70, [[$P1, 10, 1000, 500]]);
$O5 = $mkO('ORD9950005', '2019-04-02 10:00:00', 'delivered', 500,  0,   0, [[$P1, 1, 500, 300]]);
$mkE = function (string $t, string $a, string $d) use ($pdo) { $pdo->prepare("INSERT INTO expenses (title, amount, expense_date, category) VALUES (?,?,?, 'টেস্ট')")->execute([$t, $a, $d]); };
$mkE('[T] E1', '200', '2019-03-10'); $mkE('[T] E2', '300', '2019-03-31'); $mkE('[T] E3', '100', '2019-04-01');
$MAR = rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '3'], $NOW);
$APR = rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '4'], $NOW);
$YR = rp_parse_range(['type' => 'yearly', 'year' => '2019'], $NOW);

echo "\n=== বেসিস ও ট্যাব ===\n";
t('fi_basis: delivered → delivered; বাকি সব/অ্যারে/অজানা → all', fi_basis('delivered') === 'delivered' && fi_basis('all') === 'all' && fi_basis('hack') === 'all' && fi_basis(['delivered']) === 'all' && fi_basis(null) === 'all');
$tabs = fi_tabs('profit');
t('ট্যাব: ৫টি লিংক, সক্রিয়টি "active", সঠিক ফাইল', substr_count($tabs, '<a ') === 5 && preg_match('/class="nav-link py-1 px-3 active" href="report_profit\.php"/', $tabs) === 1 && strpos($tabs, 'report_purchases.php') !== false && strpos($tabs, 'stock_report.php') !== false);

echo "\n=== P&L: মার্চ ২০১৯ (বাতিল বাদে সব) ===\n";
$p = fi_pnl($pdo, $MAR, 'all');
t('অর্ডার ৩টি (বাতিলটি বাদ)', $p['orders'] === 3);
t('বিক্রয় (ডিসকাউন্টের পর) = 1000+450+900 = 2350', near($p['net_sales'], 2350));
t('ডেলিভারি চার্জ = 60+50+60 = 170; ডিসকাউন্ট 40', near($p['delivery'], 170) && near($p['discount'], 40));
t('রাজস্ব = 1060+500+960 = 2520 (= বিক্রয় + ডেলিভারি)', near($p['revenue'], 2520) && near($p['revenue'], $p['net_sales'] + $p['delivery']));
t('COGS (ক্রয়মূল্য-জানা) = 2×250+100 + 260 = 860; অজানা আইটেমের খরচ ০ ধরা হয়নি', near($p['cogs'], 860));
t('অজানা: ১টি আইটেম, বিক্রি 900', $p['unknown_lines'] === 1 && near($p['unknown_sales'], 900));
t('ক্রয়মূল্য-জানা আইটেমের মার্জিন = (800+240+450) − 860 = 630', near($p['known_sales'], 1490) && near($p['known_margin'], 630));
t('মোট লাভ = 2520 − 860 = 1660', near($p['gross'], 1660));
t('খরচ = 200+300 = 500 (এন্ট্রি ২); নিট = 1660 − 500 = 1160', near($p['expenses'], 500) && $p['expense_count'] === 2 && near($p['net'], 1160));
t('বাতিল অর্ডারের (৳10,000 পণ্য, ক্রয়মূল্য ৫০০×১০) কিছুই ধরা হয়নি', $p['revenue'] < 5000 && $p['cogs'] < 5000);

echo "\n=== P&L: শুধু Delivered, অন্য সময় ===\n";
$pd = fi_pnl($pdo, $MAR, 'delivered');
t('Delivered (মার্চ): শুধু O1 — রাজস্ব 1060, COGS 600, মোট লাভ 460, অজানা ০', $pd['orders'] === 1 && near($pd['revenue'], 1060) && near($pd['cogs'], 600) && near($pd['gross'], 460) && $pd['unknown_lines'] === 0);
t('Delivered: খরচ বেসিসে বদলায় না (500); নিট = 460 − 500 = −40 (ক্ষতি ঋণাত্মক)', near($pd['expenses'], 500) && near($pd['net'], -40));
$pa = fi_pnl($pdo, $APR, 'all');
t('এপ্রিল: রাজস্ব 500, COGS 300, খরচ 100, নিট 100 (মার্চের কিছু ঢোকে না)', $pa['orders'] === 1 && near($pa['revenue'], 500) && near($pa['cogs'], 300) && near($pa['expenses'], 100) && near($pa['net'], 100));
t('১ এপ্রিল ২০১৯-এর খরচ এপ্রিলে (মার্চে নয়): মার্চ খরচ ৩১ মার্চ পর্যন্তই', near($p['expenses'], 500) && near($pa['expenses'], 100));
$py = fi_pnl($pdo, $YR, 'all');
t('২০১৯ সাল: অর্ডার ৪, রাজস্ব 3020, COGS 1160, খরচ 600, নিট 1260', $py['orders'] === 4 && near($py['revenue'], 3020) && near($py['cogs'], 1160) && near($py['expenses'], 600) && near($py['net'], 1260));
$none = fi_pnl($pdo, rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '8'], $NOW), 'all');
t('ফাঁকা মাসে সব ০ (ক্র্যাশ/শূন্য-ভাগ নেই)', $none['orders'] === 0 && near($none['revenue'], 0) && near($none['gross'], 0) && near($none['net'], 0) && near($none['known_margin'], 0) && $none['unknown_lines'] === 0);

echo "\n=== মাস অনুযায়ী ===\n";
$ms = fi_pnl_months($pdo, $YR, 'all');
t('২০১৯: ১২টি মাস (ফাঁকা মাসসহ), জানুয়ারি থেকে ডিসেম্বর', count($ms) === 12 && $ms[0]['key'] === '2019-01' && $ms[11]['key'] === '2019-12');
t('মার্চ: অর্ডার ৩, রাজস্ব 2520, COGS 860, খরচ 500, লাভ 1160', $ms[2]['orders'] === 3 && near($ms[2]['revenue'], 2520) && near($ms[2]['cogs'], 860) && near($ms[2]['expenses'], 500) && near($ms[2]['profit'], 1160));
t('এপ্রিল: রাজস্ব 500, COGS 300, খরচ 100, লাভ 100', near($ms[3]['revenue'], 500) && near($ms[3]['cogs'], 300) && near($ms[3]['expenses'], 100) && near($ms[3]['profit'], 100));
t('ফাঁকা মাস (জানুয়ারি) সব ০', $ms[0]['orders'] === 0 && near($ms[0]['profit'], 0));
t('মাসগুলোর লাভ-যোগফল = বার্ষিক নিট (1260)', near(array_sum(array_column($ms, 'profit')), $py['net']));
$msd = fi_pnl_months($pdo, $MAR, 'delivered');
t('একমাসের সময়ে ১টি সারি; Delivered বেসিসে মার্চ লাভ −40', count($msd) === 1 && near($msd[0]['profit'], -40));
$ms3 = fi_pnl_months($pdo, rp_parse_range(['type' => 'custom', 'from' => '2000-01-01', 'to' => '2100-12-31'], $NOW), 'all');
t('অতি-লম্বা সময়ে মাস-সংখ্যা সর্বোচ্চ ৩৬ (মেমোরি/টাইম নিরাপদ)', count($ms3) === 36);

echo "\n=== পণ্য/ক্যাটেগরি অনুযায়ী মার্জিন ===\n";
$bp = fi_profit_by_product($pdo, $MAR, 'all', 10);
$bpm = []; foreach ($bp as $r) $bpm[$r['name']] = $r;
t('P1: পরিমাণ 3, বিক্রি 1250, ক্রয়মূল্য 760, মার্জিন 490', isset($bpm['[T] P1']) && $bpm['[T] P1']['qty'] === 3 && near($bpm['[T] P1']['sales'], 1250) && near($bpm['[T] P1']['cost'], 760) && near($bpm['[T] P1']['profit'], 490));
t('P2: শুধু ক্রয়মূল্য-জানা ১টি (O1), অজানা (O3) বাদ: পরিমাণ 1, মার্জিন 140', isset($bpm['[T] P2']) && $bpm['[T] P2']['qty'] === 1 && near($bpm['[T] P2']['profit'], 140));
t('বেশি মার্জিন আগে (P1 তারপর P2)', array_search('[T] P1', array_column($bp, 'name'), true) < array_search('[T] P2', array_column($bp, 'name'), true));
t('limit মানা হয় (১)', count(fi_profit_by_product($pdo, $MAR, 'all', 1)) === 1);
$bc = fi_profit_by_category($pdo, $MAR, 'all');
$bcm = []; foreach ($bc as $r) $bcm[$r['name']] = $r;
t("ক্যাটেগরি '{$cats[0]['name']}': মার্জিন 490; '{$cats[1]['name']}': 140", near($bcm[$cats[0]['name']]['profit'] ?? -1, 490) && near($bcm[$cats[1]['name']]['profit'] ?? -1, 140));
t('খালি সময়ে খালি তালিকা', fi_profit_by_product($pdo, rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '8'], $NOW), 'all') === [] && fi_profit_by_category($pdo, rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '8'], $NOW), 'all') === []);

echo "\n=== প্রতি অর্ডারের লাভ ===\n";
$op = fi_order_profits($pdo, [['id' => $O1, 'total' => 1060], ['id' => $O2, 'total' => 500], ['id' => $O3, 'total' => 960], ['id' => 999999999, 'total' => 10]]);
t('O1: লাভ = 1060 − 600 = 460, অজানা ০', near($op[$O1]['profit'], 460) && near($op[$O1]['cost'], 600) && $op[$O1]['unknown'] === 0);
t('O2: লাভ = 500 − 260 = 240', near($op[$O2]['profit'], 240));
t('O3 (ক্রয়মূল্য অজানা): লাভ null (০ নয়), অজানা ১', $op[$O3]['profit'] === null && $op[$O3]['unknown'] === 1);
t('অজানা অর্ডার-আইডি → লাভ null, ক্র্যাশ নয়', $op[999999999]['profit'] === null);
t('খালি তালিকা → খালি', fi_order_profits($pdo, []) === []);
t('অর্ডারের লাভ-যোগফল (O1+O2) = মার্চ মোট লাভ থেকে অজানা O3-এর রাজস্ব বাদ = 1660 − 960 = 700', near($op[$O1]['profit'] + $op[$O2]['profit'], $p['gross'] - 960));

echo "\n=== ক্রয় রিপোর্ট ===\n";
$S1 = (function () use ($pdo) { $pdo->exec("INSERT INTO suppliers (name) VALUES ('[T] S1'),('[T] S2')"); return (int)$pdo->query("SELECT id FROM suppliers WHERE name='[T] S1'")->fetchColumn(); })();
$S2 = (int)$pdo->query("SELECT id FROM suppliers WHERE name='[T] S2'")->fetchColumn();
$mkPu = function (int $sid, string $inv, string $date, float $total, string $status, array $items, array $pays) use ($pdo): int {
  $pdo->prepare("INSERT INTO purchases (supplier_id, invoice_no, purchase_date, total, status) VALUES (?,?,?,?,?)")->execute([$sid, $inv, $date, $total, $status]);
  $id = (int)$pdo->lastInsertId();
  foreach ($items as [$pid, $name, $q, $c]) $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, product_name, qty, unit_cost, line_total) VALUES (?,?,?,?,?,?)")->execute([$id, $pid, $name, $q, $c, $q * $c]);
  foreach ($pays as [$d, $a]) $pdo->prepare("INSERT INTO purchase_payments (purchase_id, amount, paid_date) VALUES (?,?,?)")->execute([$id, $a, $d]);
  return $id;
};
$mkPu($S1, 'A', '2019-03-10', 1000, 'active', [[$P1, '[T] P1', 10, 100]], [['2019-03-12', 400]]);
$mkPu($S1, 'B', '2019-03-20', 500, 'active', [[$P1, '[T] P1', 5, 100]], []);
$mkPu($S2, 'C', '2019-04-02', 300, 'active', [[$P2, '[T] P2', 3, 100]], [['2019-04-02', 300]]);
$mkPu($S1, 'D', '2019-03-15', 999, 'cancelled', [[$P2, '[T] P2', 9, 111]], [['2019-03-16', 50]]);
$bs = fi_purchases_by_supplier($pdo, $MAR);
t('মার্চ: সরবরাহকারী S1 — ক্রয় ২, মোট 1500, পরিশোধ 400, বাকি 1100; বাতিল D বাদ, S2 নেই', count($bs) === 1 && $bs[0]['name'] === '[T] S1' && $bs[0]['count'] === 2 && near($bs[0]['total'], 1500) && near($bs[0]['paid'], 400) && near($bs[0]['due'], 1100));
$bsa = fi_purchases_by_supplier($pdo, $APR);
t('এপ্রিল: S2 — মোট 300, পরিশোধ 300, বাকি 0', count($bsa) === 1 && $bsa[0]['name'] === '[T] S2' && near($bsa[0]['due'], 0));
$bpp = fi_purchases_by_product($pdo, $MAR, 10);
t('মার্চে কেনা পণ্য: P1 পরিমাণ 15, মোট 1500, গড় দাম 100 (বাতিল D-এর P2 বাদ)', count($bpp) === 1 && $bpp[0]['name'] === '[T] P1' && $bpp[0]['qty'] === 15 && near($bpp[0]['amount'], 1500) && near($bpp[0]['avg'], 100));
$pin = fi_purchase_payments_in($pdo, $MAR);
t('মার্চে সরবরাহকারীকে দেওয়া টাকা = 400 (১টি); বাতিল ক্রয়ের ৫০ বাদ; এপ্রিলের ৩০০ বাদ', $pin['count'] === 1 && near($pin['amount'], 400));
t('এপ্রিলে দেওয়া টাকা = 300', near(fi_purchase_payments_in($pdo, $APR)['amount'], 300));
t('মার্চের ক্রয় — pu_totals (রিপোর্টের KPI): মোট 1500, পরিশোধ 400, বাকি 1100', (function () use ($pdo, $MAR) { $t = pu_totals($pdo, $MAR); return near($t['total'], 1500) && near($t['paid'], 400) && near($t['due'], 1100) && $t['count'] === 2; })());

echo "\n=== খরচ রিপোর্ট ===\n";
$em = fi_expense_months($pdo, $YR);
t('খরচ-মাস: ১২টি; মার্চ 500 (২টি), এপ্রিল 100 (১টি)', count($em) === 12 && near($em[2]['amount'], 500) && $em[2]['count'] === 2 && near($em[3]['amount'], 100) && $em[3]['count'] === 1);
$te = fi_top_expenses($pdo, $MAR, null, 5);
t('সবচেয়ে বড় খরচ আগে (E2 300, তারপর E1 200); ফিল্টারের বাইরের E3 নেই', count($te) === 2 && $te[0]['title'] === '[T] E2' && $te[1]['title'] === '[T] E1');
t('ক্যাটেগরি ফিল্টার (__none) → ফাঁকা (সব "টেস্ট" ক্যাটেগরির)', fi_top_expenses($pdo, $MAR, '__none', 5) === []);
t('limit ১ → ১টি; limit ০/ঋণাত্মক সীমায় ছাঁটা (ক্র্যাশ নয়)', count(fi_top_expenses($pdo, $MAR, null, 1)) === 1 && count(fi_top_expenses($pdo, $MAR, null, 0)) === 1 && count(fi_top_expenses($pdo, $MAR, null, -5)) === 1);

echo "\n=== কাঠামো ===\n";
t('fi_cost_expr: কলাম থাকলে oi.cost', fi_cost_expr($pdo) === 'oi.cost');

$clean();
t('শেষে টেস্ট ডেটা মুছেছে', (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE customer_name LIKE '[T]%'")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE title LIKE '[T]%'")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE name LIKE '[T]%'")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM products WHERE name LIKE '[T]%'")->fetchColumn() === 0);
echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
