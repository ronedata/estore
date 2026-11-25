<?php
// tools/test_reports.php — রিপোর্টের হিসাব (includes/reports.php) ও মাইগ্রেশন v13-এর লজিক টেস্ট
// php tools/test_reports.php   ("[T]" অর্ডার ২০১৯ সালে বানায়, শেষে মুছে ফেলে; আসল ডেটা ছোঁয় না)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/reports.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }
$clean = function () use ($pdo) {
  $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM (SELECT id FROM orders WHERE customer_name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM orders WHERE customer_name LIKE '[T]%'");
};
$clean();

echo "\n=== মাইগ্রেশন v13 ===\n";
t('view_reports পারমিশন আছে', (bool)$pdo->query("SELECT 1 FROM permissions WHERE name='view_reports'")->fetchColumn());
t('Super Admin রোলের সাথে যুক্ত', (bool)$pdo->query("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name='view_reports' AND rp.role_id=1")->fetchColumn());
t('schema_v13_at চিহ্ন আছে', (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v13_at'")->fetchColumn());
$n0 = (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE name='view_reports'")->fetchColumn();
t('আবার চালালে ত্রুটি নেই', ensure_estore_schema_v13($pdo, true) === true);
t('আবার চালালে ডুপ্লিকেট পারমিশন/সংযোগ হয় না', (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE name='view_reports'")->fetchColumn() === $n0 && (int)$pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name='view_reports' AND rp.role_id=1")->fetchColumn() === 1);

echo "\n=== তারিখ-সীমা পার্স ===\n";
$NOW = strtotime('2026-11-25 12:00:00');
$r = rp_parse_range([], $NOW);
t('ডিফল্ট = চালু মাস (2026-11-01 – 2026-11-30)', $r['type'] === 'monthly' && $r['from'] === '2026-11-01' && $r['to'] === '2026-11-30' && $r['error'] === null);
$r = rp_parse_range(['type' => 'today'], $NOW);
t('আজ', $r['from'] === '2026-11-25' && $r['to'] === '2026-11-25');
$r = rp_parse_range(['type' => 'yearly', 'year' => '2024'], $NOW);
t('বার্ষিক 2024', $r['from'] === '2024-01-01' && $r['to'] === '2024-12-31');
$r = rp_parse_range(['type' => 'monthly', 'year' => '2024', 'month' => '2'], $NOW);
t('ফেব্রুয়ারি লিপ ইয়ার 2024 → 29 তারিখ', $r['from'] === '2024-02-01' && $r['to'] === '2024-02-29');
$r = rp_parse_range(['type' => 'monthly', 'year' => '2023', 'month' => '2'], $NOW);
t('ফেব্রুয়ারি 2023 → 28 তারিখ', $r['to'] === '2023-02-28');
$r = rp_parse_range(['type' => 'monthly', 'year' => '2025', 'month' => '12'], $NOW);
t('ডিসেম্বর → 31 তারিখ', $r['to'] === '2025-12-31');
$r = rp_parse_range(['type' => 'custom', 'from' => '2026-03-10', 'to' => '2026-03-01'], $NOW);
t('কাস্টম: from > to হলে অদলবদল', $r['from'] === '2026-03-01' && $r['to'] === '2026-03-10' && $r['error'] === null);
foreach ([['2026-02-30', '2026-03-01'], ['abc', '2026-03-01'], ['', ''], ['2026-3-1', '2026-03-05'], ['1999-01-01', '2026-01-01'], ['2026-01-01', '2101-01-01']] as [$f, $to]) {
  $r = rp_parse_range(['type' => 'custom', 'from' => $f, 'to' => $to], $NOW);
  t("অবৈধ কাস্টম ('$f','$to') → ত্রুটি বার্তা + চালু মাসে ফেরে", $r['error'] !== null && $r['type'] === 'monthly' && $r['from'] === '2026-11-01');
}
$r = rp_parse_range(['type' => 'custom', 'from' => ['2026-01-01'], 'to' => ['x']], $NOW);
t('কাস্টমে অ্যারে ইনপুট → ক্র্যাশ নয়, ত্রুটি বার্তা', $r['error'] !== null);
$r = rp_parse_range(['type' => ['x'], 'year' => ['2020'], 'month' => ['3']], $NOW);
t('অ্যারে type/year/month → ডিফল্ট চালু মাস', $r['type'] === 'monthly' && $r['from'] === '2026-11-01');
$r = rp_parse_range(['type' => 'hack', 'year' => '99999', 'month' => '13'], $NOW);
t('অবৈধ type/year/month → ডিফল্ট', $r['type'] === 'monthly' && $r['year'] === 2026 && $r['month'] === 11);
$r = rp_parse_range(['type' => 'monthly', 'year' => "2020\n", 'month' => '3 '], $NOW);
t('year/month-এ অতিরিক্ত অক্ষর → ডিফল্ট', $r['year'] === 2026 && $r['month'] === 11);

echo "\n=== ফিক্সচার অর্ডার (২০১৯) ===\n";
$prods = $pdo->query("SELECT MIN(id) id, category_id FROM products WHERE category_id IS NOT NULL GROUP BY category_id ORDER BY category_id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($prods) < 2) { echo "  SKIP  কমপক্ষে দুই ক্যাটেগরির পণ্য লাগে\n"; exit(1); }
[$P1, $P2] = [(int)$prods[0]['id'], (int)$prods[1]['id']];
$cat1 = $pdo->query("SELECT name FROM categories WHERE id=" . (int)$prods[0]['category_id'])->fetchColumn();
$cat2 = $pdo->query("SELECT name FROM categories WHERE id=" . (int)$prods[1]['category_id'])->fetchColumn();
$mk = function (string $code, string $when, string $status, float $subtotal, float $disc, float $ship, array $items, string $name = '[T] R') use ($pdo): int {
  $total = round($subtotal - $disc + $ship, 2);
  $pdo->prepare("INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_fee, subtotal, discount, total, payment_method, status, created_at)
                 VALUES (?, ?, '01700000000', '[T]', 'dhaka', ?, ?, ?, ?, 'COD', ?, ?)")->execute([$code, $name, $ship, $subtotal, $disc, $total, $status, $when]);
  $id = (int)$pdo->lastInsertId();
  foreach ($items as [$pid, $q, $pr]) $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?,?,?,?)")->execute([$id, $pid, $q, $pr]);
  return $id;
};
$mk('ORD9970001', '2019-03-10 10:00:00', 'pending',   1040, 100, 60,  [[$P1, 2, 400], [$P2, 1, 240]]);
$mk('ORD9970002', '2019-03-31 23:59:59', 'delivered', 440,  0,   60,  [[$P1, 1, 440]]);
$mk('ORD9970003', '2019-04-01 00:00:00', 'delivered', 240,  0,   60,  [[$P2, 1, 240]]);
$mk('ORD9970004', '2019-03-15 09:00:00', 'shipped',   600,  0,   100, [[$P1, 1, 600]]);
$mk('ORD9970005', '2019-03-20 09:00:00', 'cancelled', 5000, 5,   70,  [[$P1, 5, 1000]]);

$MAR = rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '3'], $NOW);
$s = rp_summary($pdo, $MAR);
t('মার্চ: মোট অর্ডার ৪ (বাতিলসহ)', $s['orders'] === 4);
t('মার্চ: বৈধ ৩, বাতিল ১', $s['valid'] === 3 && $s['cancelled'] === 1);
t('মার্চ: রাজস্ব = 1000+500+700 = 2200 (বাতিলের 9999 বাদ)', near($s['revenue'], 2200));
t('মার্চ: ডিসকাউন্ট = 100 (বাতিলের 5 বাদ)', near($s['discount'], 100));
t('মার্চ: ডেলিভারি = 60+60+100 = 220 (বাতিলের 70 বাদ)', near($s['shipping'], 220));
t('মার্চ: সাবটোটাল = 2080', near($s['subtotal'], 2080));
t('মার্চ: গড় অর্ডার = 2200/3', near($s['avg'], 2200 / 3));
t('৩১ মার্চ 23:59:59 মার্চে ধরা (শেষ দিনের শেষ সেকেন্ড)', rp_orders_count($pdo, $MAR, 'all') === 4 && in_array('ORD9970002', array_column(rp_orders_page($pdo, $MAR, 'all', 1, 50), 'order_code'), true));
$APR = rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '4'], $NOW);
$sa = rp_summary($pdo, $APR);
t('১ এপ্রিল 00:00:00 এপ্রিলে (মার্চে নয়): অর্ডার ১, রাজস্ব 300', $sa['orders'] === 1 && near($sa['revenue'], 300) && !in_array('ORD9970003', array_column(rp_orders_page($pdo, $MAR, 'all', 1, 50), 'order_code'), true));
$YR = rp_parse_range(['type' => 'yearly', 'year' => '2019'], $NOW);
$sy = rp_summary($pdo, $YR);
t('২০১৯ সাল: অর্ডার ৫, বৈধ ৪, রাজস্ব 2500', $sy['orders'] === 5 && $sy['valid'] === 4 && near($sy['revenue'], 2500));
$CU = rp_parse_range(['type' => 'custom', 'from' => '2019-03-15', 'to' => '2019-03-31'], $NOW);
t('কাস্টম 15–31 মার্চ: ORD…04 (15 মার্চ) ও …02 ধরা, …01 (10 মার্চ) নয়', rp_orders_count($pdo, $CU, 'all') === 3 && !in_array('ORD9970001', array_column(rp_orders_page($pdo, $CU, 'all', 1, 50), 'order_code'), true));
$SD = rp_parse_range(['type' => 'custom', 'from' => '2019-03-10', 'to' => '2019-03-10'], $NOW);
t('একদিনের কাস্টম (10 মার্চ) → ১টি', rp_orders_count($pdo, $SD, 'all') === 1);
$sp = rp_summary($pdo, $MAR, 'pending');
t('স্ট্যাটাস ফিল্টার pending → ১টি অর্ডার, রাজস্ব 1000', $sp['orders'] === 1 && near($sp['revenue'], 1000));
$sc = rp_summary($pdo, $MAR, 'cancelled');
t('স্ট্যাটাস ফিল্টার cancelled → রাজস্ব 0 (বাতিল রাজস্বে ধরা হয় না)', $sc['orders'] === 1 && near($sc['revenue'], 0) && $sc['cancelled'] === 1);
t('অবৈধ স্ট্যাটাস → সব (ফিল্টার হয় না)', rp_summary($pdo, $MAR, "pending' OR 1=1 --")['orders'] === 4);
$bs = rp_by_status($pdo, $MAR);
t('স্ট্যাটাস অনুযায়ী ভাগ: pending 1, delivered 1, shipped 1, cancelled 1, processing 0', $bs['pending']['count'] === 1 && $bs['delivered']['count'] === 1 && $bs['shipped']['count'] === 1 && $bs['cancelled']['count'] === 1 && $bs['processing']['count'] === 0);
t('cancelled-এর যোগফল 5000-5+70 = 5065', near($bs['cancelled']['sum'], 5065));
$none = rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '8'], $NOW);
$sn = rp_summary($pdo, $none);
t('খালি মাসে সব ০, ভাগফলে শূন্য-ভাগ নেই', $sn['orders'] === 0 && near($sn['revenue'], 0) && near($sn['avg'], 0));

echo "\n=== তালিকা ও পেজিনেশন ===\n";
$all = rp_orders_page($pdo, $MAR, 'all', 1, 50);
t('নতুন আগে সাজানো (তারিখ কমতে)', $all[0]['order_code'] === 'ORD9970002' && end($all)['order_code'] === 'ORD9970001');
t('পৃষ্ঠা ১, প্রতি পৃষ্ঠায় ২ → ২টি', count(rp_orders_page($pdo, $MAR, 'all', 1, 2)) === 2);
t('পৃষ্ঠা ২, প্রতি পৃষ্ঠায় ২ → ২টি (বাকি)', count(rp_orders_page($pdo, $MAR, 'all', 2, 2)) === 2);
t('পৃষ্ঠা ৩ → খালি', count(rp_orders_page($pdo, $MAR, 'all', 3, 2)) === 0);
t('দুই পৃষ্ঠায় কোনো অর্ডার দুইবার নয়', count(array_unique(array_merge(array_column(rp_orders_page($pdo, $MAR, 'all', 1, 2), 'id'), array_column(rp_orders_page($pdo, $MAR, 'all', 2, 2), 'id')))) === 4);
t('পৃষ্ঠা ০/ঋণাত্মক → পৃষ্ঠা ১', count(rp_orders_page($pdo, $MAR, 'all', 0, 2)) === 2 && count(rp_orders_page($pdo, $MAR, 'all', -5, 2)) === 2);

echo "\n=== ক্যাটেগরি অনুযায়ী বিক্রি ===\n";
$cs = rp_sales_by_category($pdo, $MAR);
$map = []; foreach ($cs as $c) $map[$c['name']] = $c;
t("ক্যাটেগরি '$cat1': 2×400 + 440 + 600 = 1840 (বাতিল 5000 বাদ), পরিমাণ 4", isset($map[$cat1]) && near($map[$cat1]['amount'], 1840) && $map[$cat1]['qty'] === 4);
t("ক্যাটেগরি '$cat2': 240, পরিমাণ 1", isset($map[$cat2]) && near($map[$cat2]['amount'], 240) && $map[$cat2]['qty'] === 1);
t('বেশি বিক্রি আগে সাজানো', $cs[0]['amount'] >= $cs[count($cs) - 1]['amount']);
t('খালি মাসে ক্যাটেগরি তালিকা খালি', rp_sales_by_category($pdo, $none) === []);

echo "\n=== মাসিক সিরিজ ও পুরোনো Pending ===\n";
$sr = rp_monthly_series($pdo, 6, strtotime('2019-04-15'));
t('৬টি মাস, ২০১৮-১১ থেকে ২০১৯-০৪, ফাঁকা মাসও আছে', count($sr) === 6 && $sr[0]['key'] === '2018-11' && $sr[5]['key'] === '2019-04');
t('২০১৯-০৩: রাজস্ব 2200, অর্ডার ৩', near($sr[4]['revenue'], 2200) && $sr[4]['orders'] === 3);
t('২০১৯-০৪: রাজস্ব 300, অর্ডার ১', near($sr[5]['revenue'], 300) && $sr[5]['orders'] === 1);
t('ফাঁকা মাসে ০', near($sr[0]['revenue'], 0) && $sr[0]['orders'] === 0);
t('মাস-সংখ্যা সীমা ১–২৪', count(rp_monthly_series($pdo, 0)) === 1 && count(rp_monthly_series($pdo, 99)) === 24);
t('৩ দিনের বেশি পুরোনো Pending: 20 মার্চ ২০১৯ ধরলে ১টি (10 মার্চেরটি)', rp_old_pending($pdo, 3, strtotime('2019-03-20')) === 1);
t('একই, 11 মার্চ ধরলে ০ (৩ দিন পূর্ণ হয়নি)', rp_old_pending($pdo, 3, strtotime('2019-03-11')) === 0);

echo "\n=== CSV ও লিংক-প্যারাম ===\n";
t('= দিয়ে শুরু → আগে \'', rp_csv_safe('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")");
t('+ - @ ট্যাব দিয়ে শুরু → আগে \'', rp_csv_safe('+1') === "'+1" && rp_csv_safe('-1') === "'-1" && rp_csv_safe('@a') === "'@a" && rp_csv_safe("\tx") === "'\tx");
t('সাধারণ লেখা/বাংলা/ফাঁকা অপরিবর্তিত', rp_csv_safe('রাহিম') === 'রাহিম' && rp_csv_safe('') === '' && rp_csv_safe('abc=') === 'abc=');
$kp = rp_keep_params($MAR, 'pending');
t('keep_params: type, year, month, status', $kp === ['type' => 'monthly', 'year' => 2019, 'month' => 3, 'status' => 'pending']);
$kp = rp_keep_params($CU, 'all');
t('keep_params কাস্টম: from/to, status নেই', $kp === ['type' => 'custom', 'from' => '2019-03-15', 'to' => '2019-03-31']);

$clean();
t('শেষে টেস্ট অর্ডার মুছেছে', (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE customer_name LIKE '[T]%'")->fetchColumn() === 0);
echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
