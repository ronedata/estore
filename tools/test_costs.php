<?php
// tools/test_costs.php — ক্রয়মূল্য (গড়), অর্ডার-আইটেমে snapshot, খরচ মডিউল ও মাইগ্রেশন v14 — লজিক টেস্ট
// php tools/test_costs.php   ("[T]" ডেটা ২০১৯ সালে বানায়, শেষে মুছে ফেলে)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/expenses.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}
function near($a, $b): bool { return abs((float)$a - (float)$b) < 0.005; }
$clean = function () use ($pdo) {
  $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM (SELECT id FROM orders WHERE customer_name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM order_items WHERE product_id IN (SELECT id FROM (SELECT id FROM products WHERE name LIKE '[T]%') y)");
  $pdo->exec("DELETE FROM orders WHERE customer_name LIKE '[T]%'");
  $pdo->exec("DELETE FROM products WHERE name LIKE '[T]%'");
  $pdo->exec("DELETE FROM expenses WHERE title LIKE '[T]%' OR title LIKE '=[T]%'");
};
$clean();

echo "\n=== মাইগ্রেশন v14 ===\n";
t('products.cost_price কলাম (DECIMAL NULL)', (bool)$pdo->query("SHOW COLUMNS FROM products WHERE Field='cost_price' AND `Null`='YES'")->fetch());
t('order_items.cost কলাম (DECIMAL NULL)', (bool)$pdo->query("SHOW COLUMNS FROM order_items WHERE Field='cost' AND `Null`='YES'")->fetch());
t('expenses টেবিল আছে', (bool)$pdo->query("SHOW TABLES LIKE 'expenses'")->fetchColumn());
t('manage_expenses পারমিশন ও Super Admin-এর সাথে যুক্ত', (bool)$pdo->query("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name='manage_expenses' AND rp.role_id=1")->fetchColumn());
t('schema_v14_at চিহ্ন আছে', (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v14_at'")->fetchColumn());
$nullCosts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE cost_price IS NULL")->fetchColumn();
t('আবার চালালে ত্রুটি নেই', ensure_estore_schema_v14($pdo, true) === true);
t('আবার চালালে বিদ্যমান ডেটা বদলায় না (cost_price NULL-ই থাকে, ০ হয় না)', (int)$pdo->query("SELECT COUNT(*) FROM products WHERE cost_price IS NULL")->fetchColumn() === $nullCosts);

echo "\n=== cs_parse_money ===\n";
foreach ([['', null], [null, null], ['  ', null], ['0', 0.0], ['120', 120.0], ['120.555', 120.56], ['0.1', 0.1], [' 45.5 ', 45.5], ['10000000', 10000000.0]] as [$in, $want]) {
  $got = cs_parse_money($in);
  t('ঠিক ' . json_encode($in) . ' → ' . json_encode($want), $want === null ? $got === null : (is_float($got) && near($got, $want)));
}
foreach (['abc', '-1', '-0.01', '10000001', '1e9', 'NaN', 'INF', '1,000', '১২৩', ['5'], '0x1A', '12abc'] as $bad) {
  t('অবৈধ ' . json_encode($bad, JSON_UNESCAPED_UNICODE) . ' → false', cs_parse_money($bad) === false);
}

echo "\n=== গড় ক্রয়মূল্য (cs_weighted_avg) ===\n";
t('স্টক ০ → নতুন দামই গড়', near(cs_weighted_avg(0, null, 10, 50), 50) && near(cs_weighted_avg(0, 999, 10, 50), 50));
t('আগের দাম অজানা → নতুন দাম', near(cs_weighted_avg(10, null, 5, 60), 60));
t('10 পিস×100 + 10 পিস×200 → গড় 150', near(cs_weighted_avg(10, 100, 10, 200), 150));
t('3×100 + 4×101 → 100.57 (২ দশমিকে গোল)', near(cs_weighted_avg(3, 100, 4, 101), 100.57));
t('1 পিস×100 + 99 পিস×200 → 199', near(cs_weighted_avg(1, 100, 99, 200), 199));
t('পরিমাণ ০ → আগের গড়ই (বদলায় না)', near(cs_weighted_avg(5, 100, 0, 999), 100));
t('ঋণাত্মক স্টক (oversold) → নতুন দাম', near(cs_weighted_avg(-3, 100, 5, 80), 80));
t('একই দামে কেনা → গড় একই', near(cs_weighted_avg(7, 55.5, 13, 55.5), 55.5));
t('দাম কমলে গড় কমে (10×200, 10×100 → 150)', near(cs_weighted_avg(10, 200, 10, 100), 150));
t('অনেক বড় সংখ্যায় ক্র্যাশ নয়', near(cs_weighted_avg(1000000, 9999999.99, 1000000, 0.01), 5000000.0));

echo "\n=== পণ্যে ক্রয়মূল্য সেট ও snapshot ===\n";
t('অজানা কলাম/টেবিলে cs_has_column false, ক্র্যাশ নয়', cs_has_column($pdo, 'products', 'no_such_col') === false && cs_has_column($pdo, 'no_such_table', 'x') === false);
t('cs_has_column সত্যিকারের কলামে true', cs_has_column($pdo, 'products', 'cost_price') && cs_has_column($pdo, 'order_items', 'cost'));
$cat = (int)$pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
$mkP = function (string $n, int $stock, float $price, ?float $cost) use ($pdo, $cat): int {
  $pdo->prepare("INSERT INTO products (category_id, name, price, stock, active, cost_price) VALUES (?,?,?,?,1,?)")->execute([$cat, $n, $price, $stock, $cost]);
  return (int)$pdo->lastInsertId();
};
$pA = $mkP('[T] CostA', 10, 100, 40.00);
$pB = $mkP('[T] CostB', 5, 200, null);
t('cs_set_product_cost: মান বসে', cs_set_product_cost($pdo, $pA, 45.5) && near($pdo->query("SELECT cost_price FROM products WHERE id=$pA")->fetchColumn(), 45.5));
t('cs_set_product_cost null → অজানা (NULL, ০ নয়)', cs_set_product_cost($pdo, $pA, null) && $pdo->query("SELECT cost_price FROM products WHERE id=$pA")->fetchColumn() === null);
t('cs_set_product_cost ০ → ০ (ফ্রি পণ্য, অজানা নয়)', cs_set_product_cost($pdo, $pA, 0.0) && near($pdo->query("SELECT cost_price FROM products WHERE id=$pA")->fetchColumn(), 0));
cs_set_product_cost($pdo, $pA, 40.00);
t('cs_set_product_cost অবৈধ আইডি (0/-1) → false', cs_set_product_cost($pdo, 0, 5.0) === false && cs_set_product_cost($pdo, -1, 5.0) === false);

$pdo->prepare("INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_fee, subtotal, total, payment_method, status, created_at) VALUES ('ORD9960001','[T] Cost','01700000000','[T]','dhaka',0,300,300,'COD','pending','2019-05-05 10:00:00')")->execute();
$oid = (int)$pdo->lastInsertId();
$ins = $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?,?,?,?)");
$ins->execute([$oid, $pA, 2, 100]); $iA = (int)$pdo->lastInsertId();
$ins->execute([$oid, $pB, 1, 200]); $iB = (int)$pdo->lastInsertId();
t('নতুন আইটেমে cost শুরুতে NULL', $pdo->query("SELECT cost FROM order_items WHERE id=$iA")->fetchColumn() === null);
t('cs_snapshot_order: পণ্যের ক্রয়মূল্য আইটেমে বসে (A=40)', cs_snapshot_order($pdo, $oid) >= 1 && near($pdo->query("SELECT cost FROM order_items WHERE id=$iA")->fetchColumn(), 40));
t('ক্রয়মূল্য অজানা পণ্যের আইটেমে cost NULL থাকে (০ নয়)', $pdo->query("SELECT cost FROM order_items WHERE id=$iB")->fetchColumn() === null);
cs_set_product_cost($pdo, $pA, 99.0);
t('পরে পণ্যের ক্রয়মূল্য বদলালে পুরোনো আইটেমের cost বদলায় না (snapshot)', near($pdo->query("SELECT cost FROM order_items WHERE id=$iA")->fetchColumn(), 40));
$ins->execute([$oid, $pA, 1, 100]); $iA2 = (int)$pdo->lastInsertId();
t('শুধু দেওয়া নতুন আইটেমে snapshot: নতুনে 99', cs_snapshot_items($pdo, [$iA2]) === 1 && near($pdo->query("SELECT cost FROM order_items WHERE id=$iA2")->fetchColumn(), 99));
t('একই সময়ে পুরোনো আইটেম অক্ষত (40)', near($pdo->query("SELECT cost FROM order_items WHERE id=$iA")->fetchColumn(), 40));
t('cs_snapshot_items খালি/অবৈধ তালিকায় ০, ক্র্যাশ নয়', cs_snapshot_items($pdo, []) === 0 && cs_snapshot_items($pdo, [0, -5, 'x']) === 0);
t('অজানা আইটেম আইডিতে কিছু বদলায় না', cs_snapshot_items($pdo, [999999999]) === 0);

echo "\n=== খরচ: যাচাই (ex_clean) ===\n";
$NOW = strtotime('2026-11-25 12:00:00');
$ok = ['title' => '  [T]   দোকান   ভাড়া ', 'amount' => '5000.456', 'expense_date' => '2026-11-25', 'category' => ' ভাড়া ', 'note' => ''];
[$c, $e] = ex_clean($ok, $NOW);
t('ঠিক ইনপুট গ্রহণ', $c !== null && !$e);
t('অতিরিক্ত ফাঁকা ছাঁটা: "[T] দোকান ভাড়া"', $c['title'] === '[T] দোকান ভাড়া' && $c['category'] === 'ভাড়া');
t('পরিমাণ ২ দশমিকে গোল (5000.46)', near($c['amount'], 5000.46));
t('ফাঁকা নোট/ক্যাটেগরি → NULL', $c['note'] === null && ex_clean(array_merge($ok, ['category' => '']), $NOW)[0]['category'] === null);
foreach ([
  ['শিরোনাম ফাঁকা', ['title' => '   ']], ['শিরোনাম ১৬১ অক্ষর', ['title' => str_repeat('ক', 161)]], ['শিরোনাম অ্যারে', ['title' => ['x']]],
  ['পরিমাণ ০', ['amount' => '0']], ['পরিমাণ ঋণাত্মক', ['amount' => '-5']], ['পরিমাণ অক্ষর', ['amount' => 'abc']], ['পরিমাণ ফাঁকা', ['amount' => '']],
  ['পরিমাণ অ্যারে', ['amount' => ['5']]], ['পরিমাণ ১ কোটির বেশি', ['amount' => '10000001']], ['পরিমাণ 0.001 (০-তে গোল)', ['amount' => '0.001']],
  ['তারিখ ভুল', ['expense_date' => '2026-02-30']], ['তারিখ ফাঁকা', ['expense_date' => '']], ['তারিখ ভবিষ্যৎ', ['expense_date' => '2026-11-26']],
  ['তারিখ অ্যারে', ['expense_date' => ['2026-01-01']]], ['তারিখ ১৯৯৯', ['expense_date' => '1999-12-31']],
  ['ক্যাটেগরি ৬১ অক্ষর', ['category' => str_repeat('a', 61)]], ['নোট ২৫৬ অক্ষর', ['note' => str_repeat('a', 256)]], ['নোট অ্যারে', ['note' => ['x']]],
] as [$label, $patch]) {
  [$c2, $e2] = ex_clean(array_merge($ok, $patch), $NOW);
  t("অবৈধ: $label → বাতিল + বার্তা", $c2 === null && count($e2) >= 1);
}
t('আজকের তারিখ ঠিক, গতকালও ঠিক', ex_clean(array_merge($ok, ['expense_date' => '2026-11-25']), $NOW)[0] !== null && ex_clean(array_merge($ok, ['expense_date' => '2026-11-24']), $NOW)[0] !== null);
t('১৬০ অক্ষরের শিরোনাম (সীমায়) ঠিক', ex_clean(array_merge($ok, ['title' => str_repeat('ক', 160)]), $NOW)[0] !== null);
t('সব ইনপুট অনুপস্থিত → ক্র্যাশ নয়, ৩টি ত্রুটি', count(ex_clean([], $NOW)[1]) >= 3);

echo "\n=== খরচ: CRUD ও যোগফল (২০১৯) ===\n";
$mk = function (string $title, string $amt, string $date, ?string $cat, ?string $note = null) use ($pdo, $NOW): int {
  [$cl] = ex_clean(['title' => $title, 'amount' => $amt, 'expense_date' => $date, 'category' => $cat ?? '', 'note' => $note ?? ''], $NOW);
  return ex_add($pdo, $cl, 'tester');
};
$e1 = $mk('[T] ভাড়া মার্চ', '5000', '2019-03-01', 'ভাড়া');
$e2 = $mk('[T] বিদ্যুৎ', '1200.50', '2019-03-31', 'ইউটিলিটি');
$e3 = $mk('[T] বিজ্ঞাপন', '800', '2019-04-01', 'বিজ্ঞাপন');
$e4 = $mk('[T] চা', '300', '2019-03-15', null);
$e5 = $mk('=[T] ফর্মুলা', '10', '2019-03-20', 'ভাড়া');
t('ex_add আইডি দেয়, created_by সংরক্ষণ', $e1 > 0 && $pdo->query("SELECT created_by FROM expenses WHERE id=$e1")->fetchColumn() === 'tester');
$MAR = rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '3'], $NOW);
t('মার্চের যোগফল = 5000+1200.50+300+10 = 6510.50 (৩১ মার্চ ধরা, ১ এপ্রিল নয়)', near(ex_total($pdo, $MAR), 6510.50));
t('মার্চে গণনা ৪', ex_count($pdo, $MAR) === 4);
t('এপ্রিল = 800', near(ex_total($pdo, rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '4'], $NOW)), 800));
t('বছর ২০১৯ = 7310.50', near(ex_total($pdo, rp_parse_range(['type' => 'yearly', 'year' => '2019'], $NOW)), 7310.50));
t('ক্যাটেগরি ফিল্টার "ভাড়া" = 5010', near(ex_total($pdo, $MAR, 'ভাড়া'), 5010));
t('"ক্যাটেগরি নেই" ফিল্টার (__none) = 300', near(ex_total($pdo, $MAR, '__none'), 300));
t('অজানা ক্যাটেগরি = 0', near(ex_total($pdo, $MAR, 'নেই-এমন'), 0));
t('ক্যাটেগরি ফিল্টারে SQL ইনজেকশন নিরাপদ', near(ex_total($pdo, $MAR, "ভাড়া' OR '1'='1"), 0));
$byc = ex_by_category($pdo, $MAR); $m = []; foreach ($byc as $b) $m[$b['name']] = $b;
t('ক্যাটেগরি অনুযায়ী: ভাড়া 5010 (২টি), ইউটিলিটি 1200.50, ক্যাটেগরি নেই 300', near($m['ভাড়া']['sum'], 5010) && $m['ভাড়া']['count'] === 2 && near($m['ইউটিলিটি']['sum'], 1200.5) && near($m['ক্যাটেগরি নেই']['sum'], 300));
t('বেশি খরচ আগে সাজানো', $byc[0]['sum'] >= end($byc)['sum']);
$list = ex_list($pdo, $MAR, null, 1, 2);
t('তালিকা নতুন তারিখ আগে: ৩১ মার্চ, তারপর ২০ মার্চ', $list[0]['title'] === '[T] বিদ্যুৎ' && $list[1]['title'] === '=[T] ফর্মুলা');
t('পৃষ্ঠা ২ (প্রতি ২টি) → বাকি ২টি, ডুপ্লিকেট নেই', count(ex_list($pdo, $MAR, null, 2, 2)) === 2 && count(array_unique(array_merge(array_column($list, 'id'), array_column(ex_list($pdo, $MAR, null, 2, 2), 'id')))) === 4);
t('পৃষ্ঠা ৩ খালি, পৃষ্ঠা ০ → পৃষ্ঠা ১', count(ex_list($pdo, $MAR, null, 3, 2)) === 0 && count(ex_list($pdo, $MAR, null, 0, 2)) === 2);
t('ক্যাটেগরি-তালিকায় "ভাড়া" আছে', in_array('ভাড়া', ex_categories($pdo), true));

[$upd] = ex_clean(['title' => '[T] ভাড়া মার্চ (সংশোধিত)', 'amount' => '5500', 'expense_date' => '2019-03-02', 'category' => 'ভাড়া', 'note' => 'বেড়েছে'], $NOW);
t('ex_update সফল, মান বদলায়', ex_update($pdo, $e1, $upd) && near(ex_get($pdo, $e1)['amount'], 5500) && ex_get($pdo, $e1)['note'] === 'বেড়েছে');
t('আপডেটে created_by অক্ষত', ex_get($pdo, $e1)['created_by'] === 'tester');
t('ex_update অজানা আইডি → false', ex_update($pdo, 999999999, $upd) === false);
t('আপডেটের পর যোগফল 7010.50 (6510.50 + 500)', near(ex_total($pdo, $MAR), 7010.50));
t('ex_delete সফল, আবার মুছলে false', ex_delete($pdo, $e4) === true && ex_delete($pdo, $e4) === false && ex_get($pdo, $e4) === null);
t('মোছার পর মার্চ = 6710.50', near(ex_total($pdo, $MAR), 6710.50));
$ov = ex_overview($pdo, strtotime('2019-03-20 12:00:00'));
t('overview (২০ মার্চ ২০১৯): আজ 10, মাস 6710.50, বছর 7510.50', near($ov['today'], 10) && near($ov['month'], 6710.5) && near($ov['year'], 7510.5));

$clean();
t('শেষে টেস্ট ডেটা মুছেছে', (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE title LIKE '[T]%' OR title LIKE '=[T]%'")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM products WHERE name LIKE '[T]%'")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE customer_name LIKE '[T]%'")->fetchColumn() === 0);
echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
