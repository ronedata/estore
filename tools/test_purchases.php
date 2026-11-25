<?php
// tools/test_purchases.php — সরবরাহকারী, ক্রয়, গড় ক্রয়মূল্য, স্টক, পরিশোধ (কিস্তি/একসাথে), বাতিল — লজিক টেস্ট
// php tools/test_purchases.php   ("[T]" ডেটা ২০১৯ সালের তারিখে বানায়, শেষে মুছে ফেলে; আসল ডেটা ছোঁয় না)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/purchases.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}
function near($a, $b): bool { return abs((float)$a - (float)$b) < 0.005; }
$clean = function () use ($pdo) {
  $pdo->exec("DELETE FROM purchase_payments WHERE purchase_id IN (SELECT id FROM (SELECT p.id FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE s.name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM purchase_items WHERE purchase_id IN (SELECT id FROM (SELECT p.id FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE s.name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM purchases WHERE supplier_id IN (SELECT id FROM (SELECT id FROM suppliers WHERE name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM suppliers WHERE name LIKE '[T]%'");
  $pdo->exec("DELETE FROM products WHERE name LIKE '[T]%'");
};
$clean();
$NOW = strtotime('2026-11-25 12:00:00');
$cat = (int)$pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
$mkP = function (string $n, int $stock, ?float $cost) use ($pdo, $cat): int {
  $pdo->prepare("INSERT INTO products (category_id, name, price, stock, active, cost_price) VALUES (?,?,500,?,1,?)")->execute([$cat, $n, $stock, $cost]);
  return (int)$pdo->lastInsertId();
};
$stock = fn($id) => (int)$pdo->query("SELECT stock FROM products WHERE id=$id")->fetchColumn();
$costOf = function ($id) use ($pdo) { $v = $pdo->query("SELECT cost_price FROM products WHERE id=$id")->fetchColumn(); return $v === null ? null : (float)$v; };

echo "\n=== মাইগ্রেশন v15 ===\n";
foreach (['suppliers', 'purchases', 'purchase_items', 'purchase_payments'] as $tb) t("টেবিল $tb আছে", (bool)$pdo->query("SHOW TABLES LIKE '$tb'")->fetchColumn());
t('manage_purchases পারমিশন ও Super Admin-এর সাথে যুক্ত', (bool)$pdo->query("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name='manage_purchases' AND rp.role_id=1")->fetchColumn());
t('schema_v15_at চিহ্ন আছে', (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v15_at'")->fetchColumn());
$nPerm = (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE name='manage_purchases'")->fetchColumn();
t('আবার চালালে ত্রুটি নেই ও পারমিশন ডুপ্লিকেট হয় না', ensure_estore_schema_v15($pdo, true) === true && (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE name='manage_purchases'")->fetchColumn() === $nPerm);

echo "\n=== সরবরাহকারী ===\n";
[$c, $e] = pu_supplier_clean(['name' => '  [T]   রহিম   ট্রেডার্স ', 'phone' => '+880 1711-111111', 'address' => 'ঢাকা', 'note' => '']);
t('ঠিক ইনপুট গ্রহণ, ফাঁকা ছাঁটা, ফাঁকা নোট → NULL', $c !== null && $c['name'] === '[T] রহিম ট্রেডার্স' && $c['note'] === null && $c['phone'] === '+880 1711-111111');
foreach ([['নাম ফাঁকা', ['name' => ' ']], ['নাম ১২১ অক্ষর', ['name' => str_repeat('ক', 121)]], ['নাম অ্যারে', ['name' => ['x']]], ['ফোনে অক্ষর', ['name' => '[T]x', 'phone' => '017abc']], ['ফোন ৩১ অক্ষর', ['name' => '[T]x', 'phone' => str_repeat('1', 31)]], ['ঠিকানা ২৫৬', ['name' => '[T]x', 'address' => str_repeat('a', 256)]], ['নোট ২৫৬', ['name' => '[T]x', 'note' => str_repeat('a', 256)]]] as [$l, $in]) {
  [$cc, $ee] = pu_supplier_clean($in);
  t("অবৈধ সরবরাহকারী: $l → বাতিল", $cc === null && count($ee) >= 1);
}
t('সব ইনপুট অনুপস্থিত → ক্র্যাশ নয়', pu_supplier_clean([])[0] === null);
$s1 = pu_supplier_add($pdo, $c);
$s2 = pu_supplier_add($pdo, pu_supplier_clean(['name' => '[T] করিম এন্টারপ্রাইজ'])[0]);
t('সরবরাহকারী যোগ ও পড়া', $s1 > 0 && pu_supplier_get($pdo, $s1)['name'] === '[T] রহিম ট্রেডার্স' && (int)pu_supplier_get($pdo, $s1)['active'] === 1);
t('আপডেট সফল, অজানা আইডি → false', pu_supplier_update($pdo, $s1, array_merge($c, ['phone' => '01800000000'])) && pu_supplier_get($pdo, $s1)['phone'] === '01800000000' && pu_supplier_update($pdo, 999999999, $c) === false);
t('নিষ্ক্রিয়/সক্রিয়', pu_supplier_set_active($pdo, $s2, false) && (int)pu_supplier_get($pdo, $s2)['active'] === 0 && pu_supplier_set_active($pdo, $s2, true) && (int)pu_supplier_get($pdo, $s2)['active'] === 1);
$sTmp = pu_supplier_add($pdo, pu_supplier_clean(['name' => '[T] মোছার জন্য'])[0]);
t('ক্রয়-ইতিহাস ছাড়া সরবরাহকারী মোছা যায়; আবার মুছলে ত্রুটি', pu_supplier_delete($pdo, $sTmp)[0] === true && pu_supplier_delete($pdo, $sTmp)[0] === false);

echo "\n=== ক্রয়: যাচাই ===\n";
$pA = $mkP('[T] PA', 10, 100.00);
$pB = $mkP('[T] PB', 0, null);
$pC = $mkP('[T] PC', 5, null);
$base = ['supplier_id' => (string)$s1, 'invoice_no' => 'INV-1', 'purchase_date' => '2019-01-10', 'product_id' => [(string)$pA], 'qty' => ['10'], 'unit_cost' => ['200'], 'paid' => '', 'method' => '', 'note' => ''];
[$ok, $er] = pu_clean_purchase($pdo, $base, $NOW);
t('ঠিক ক্রয় গ্রহণ, মোট ২০০০', $ok !== null && near($ok['total'], 2000) && $ok['paid'] === 0.0 && $ok['invoice_no'] === 'INV-1');
$bad = function (string $label, array $patch) use ($pdo, $base, $NOW) {
  [$c2, $e2] = pu_clean_purchase($pdo, array_merge($base, $patch), $NOW);
  t("অবৈধ: $label → বাতিল + বার্তা, কিছু লেখা হয়নি", $c2 === null && count($e2) >= 1);
};
$bad('সরবরাহকারী নেই', ['supplier_id' => '']); $bad('সরবরাহকারী অজানা', ['supplier_id' => '999999999']); $bad('সরবরাহকারী অক্ষর', ['supplier_id' => 'abc']);
$bad('তারিখ ভুল', ['purchase_date' => '2019-02-30']); $bad('তারিখ ফাঁকা', ['purchase_date' => '']); $bad('তারিখ ভবিষ্যৎ', ['purchase_date' => '2026-11-26']);
$bad('ইনভয়েস ৬১ অক্ষর', ['invoice_no' => str_repeat('a', 61)]); $bad('নোট ২৫৬', ['note' => str_repeat('a', 256)]);
$bad('পণ্য বাছা হয়নি', ['product_id' => [''], 'qty' => ['5'], 'unit_cost' => ['10']]); $bad('পণ্য অজানা', ['product_id' => ['999999999']]);
foreach (['0', '-1', 'abc', '1.5', '100001', ' ', '1e3'] as $q) $bad("পরিমাণ '$q'", ['qty' => [$q]]);
foreach (['abc', '-1', '1e9', '10000001', ''] as $q) $bad("ক্রয়-দাম '$q'", ['unit_cost' => [$q]]);
$bad('আইটেম নেই (সব ফাঁকা সারি)', ['product_id' => [''], 'qty' => [''], 'unit_cost' => ['']]);
$bad('আইটেম অ্যারে-অমিল', ['qty' => ['1', '2']]);
$bad('আইটেম অ্যারে নয়', ['product_id' => 'x', 'qty' => 'y', 'unit_cost' => 'z']);
$bad('আইটেমে অ্যারের ভেতর অ্যারে', ['product_id' => [[1]], 'qty' => [['1']], 'unit_cost' => [['5']]]);
$bad('১০১টি আইটেম', ['product_id' => array_fill(0, 101, (string)$pA), 'qty' => array_fill(0, 101, '1'), 'unit_cost' => array_fill(0, 101, '1')]);
$bad('পরিশোধ > মোট', ['paid' => '2000.01']); $bad('পরিশোধ ঋণাত্মক', ['paid' => '-5']); $bad('পরিশোধ অক্ষর', ['paid' => 'abc']); $bad('মাধ্যম ৩১ অক্ষর', ['method' => str_repeat('a', 31)]);
$bad('লাইনের মোট অনেক বড় (১ লাখ × ১ কোটি)', ['qty' => ['100000'], 'unit_cost' => ['10000000']]);
[$ok2] = pu_clean_purchase($pdo, array_merge($base, ['paid' => '2000', 'method' => 'নগদ']), $NOW);
t('পরিশোধ = মোট (সীমায়) ঠিক', $ok2 !== null && near($ok2['paid'], 2000) && $ok2['method'] === 'নগদ');
[$ok3] = pu_clean_purchase($pdo, array_merge($base, ['product_id' => ['', (string)$pA], 'qty' => ['', '2'], 'unit_cost' => ['', '33.33']]), $NOW);
t('ফাঁকা সারি বাদ; 2 × 33.33 = 66.66', $ok3 !== null && count($ok3['items']) === 1 && near($ok3['total'], 66.66));
$stBefore = $stock($pA);
t('শুধু যাচাই (pu_clean_purchase) ডাটাবেসে কিছু লেখে না', $stock($pA) === $stBefore && (int)$pdo->query("SELECT COUNT(*) FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE s.name LIKE '[T]%'")->fetchColumn() === 0);
t('সরবরাহকারী নিষ্ক্রিয় → ক্রয় বাতিল', (function () use ($pdo, $base, $NOW, $s1) { pu_supplier_set_active($pdo, $s1, false); [$c3] = pu_clean_purchase($pdo, $base, $NOW); pu_supplier_set_active($pdo, $s1, true); return $c3 === null; })());

echo "\n=== ক্রয় সংরক্ষণ: স্টক ও গড় ক্রয়মূল্য ===\n";
$in1 = array_merge($base, ['product_id' => [(string)$pA, (string)$pB, (string)$pC], 'qty' => ['10', '4', '5'], 'unit_cost' => ['200', '50', '60'], 'paid' => '1000', 'method' => 'নগদ', 'invoice_no' => 'INV-A']);
[$cl1, $e1] = pu_clean_purchase($pdo, $in1, $NOW);
$id1 = pu_create($pdo, $cl1, 'tester');
t('ক্রয় তৈরি (আইডি > 0)', $id1 > 0);
t('PA: স্টক 10→20; গড় (10×100 + 10×200)/20 = 150', $stock($pA) === 20 && near($costOf($pA), 150));
t('PB: স্টক 0→4; আগে স্টক ০ তাই ক্রয়মূল্য = 50', $stock($pB) === 4 && near($costOf($pB), 50));
t('PC: স্টক 5→10; আগের ক্রয়মূল্য অজানা তাই = নতুন দাম 60', $stock($pC) === 10 && near($costOf($pC), 60));
$g = pu_get($pdo, $id1);
t('মোট = 2000+200+300 = 2500; ৩টি আইটেম; পণ্যের নাম snapshot', near($g['purchase']['total'], 2500) && count($g['items']) === 3 && $g['items'][0]['product_name'] === '[T] PA' && (int)$g['items'][1]['qty'] === 4 && near($g['items'][2]['line_total'], 300));
t('শুরুর পরিশোধ ১০০০ → পরিশোধ-সারি ১টি; বাকি 1500', count($g['payments']) === 1 && near($g['purchase']['paid'], 1000) && near($g['purchase']['due'], 1500) && $g['payments'][0]['paid_date'] === '2019-01-10' && $g['payments'][0]['method'] === 'নগদ');
t('created_by সংরক্ষিত', $g['purchase']['created_by'] === 'tester');
$in2 = array_merge($base, ['invoice_no' => 'INV-B', 'purchase_date' => '2019-02-05', 'product_id' => [(string)$pA, (string)$pA], 'qty' => ['20', '20'], 'unit_cost' => ['300', '300']]);
$id2 = pu_create($pdo, pu_clean_purchase($pdo, $in2, $NOW)[0], 'tester');
t('একই পণ্য দুই লাইনে: ক্রমান্বয়ে গড় — স্টক 20→60', $stock($pA) === 60);
t('গড় ক্রমান্বয়ে: লাইন১ (20×150+20×300)/40 = 225, লাইন২ (40×225+20×300)/60 = 250', near($costOf($pA), 250));
t('ফাঁকা পরিশোধ → পরিশোধ-সারি নেই, বাকি = মোট', count(pu_get($pdo, $id2)['payments']) === 0 && near(pu_get($pdo, $id2)['purchase']['due'], 12000));
t('একই সরবরাহকারীর একই ইনভয়েস আবার → যাচাইয়ে বাতিল', pu_clean_purchase($pdo, array_merge($in1), $NOW)[0] === null);
t('অন্য সরবরাহকারীর জন্য একই ইনভয়েস নম্বর চলে', pu_clean_purchase($pdo, array_merge($in1, ['supplier_id' => (string)$s2]), $NOW)[0] !== null);

echo "\n=== ট্রানজাকশন (ব্যর্থ হলে কিছুই লেখা হয় না) ===\n";
$stA = $stock($pA); $cA = $costOf($pA); $nPur = (int)$pdo->query("SELECT COUNT(*) FROM purchases")->fetchColumn();
$crafted = ['supplier_id' => $s1, 'invoice_no' => str_repeat('x', 200), 'purchase_date' => '2019-03-01', 'total' => 100.0, 'note' => null, 'paid' => 0.0, 'method' => null,
            'items' => [['product_id' => $pA, 'name' => '[T] PA', 'qty' => 5, 'unit_cost' => 20.0, 'line_total' => 100.0]]];
$prevLog = ini_set('error_log', sys_get_temp_dir() . '/pu_test.log');
t('ডাটাবেস ত্রুটিতে pu_create → 0', pu_create($pdo, $crafted, 't') === 0);
ini_set('error_log', $prevLog ?: '');
t('ব্যর্থ ক্রয়ে স্টক ও ক্রয়মূল্য অপরিবর্তিত, ক্রয়-সারি নেই (rollback)', $stock($pA) === $stA && near($costOf($pA), $cA) && (int)$pdo->query("SELECT COUNT(*) FROM purchases")->fetchColumn() === $nPur && !$pdo->inTransaction());
$pGhost = $mkP('[T] PGhost', 3, 10.0);
$idG = pu_create($pdo, pu_clean_purchase($pdo, array_merge($base, ['invoice_no' => 'INV-G', 'product_id' => [(string)$pGhost], 'qty' => ['2'], 'unit_cost' => ['10']]), $NOW)[0], 't');
$pdo->exec("DELETE FROM products WHERE id=$pGhost");
t('পণ্য পরে মুছে গেলে ক্রয়ের ইতিহাস থাকে (নাম snapshot)', pu_get($pdo, $idG)['items'][0]['product_name'] === '[T] PGhost');
t('মুছে-যাওয়া পণ্যওয়ালা ক্রয় বাতিলে ক্র্যাশ নয়', pu_void($pdo, $idG)[0] === true);

echo "\n=== পরিশোধ (কিস্তি) ===\n";
$bad2 = fn(string $label, $amt, $date = '2019-02-01') => t("অবৈধ পরিশোধ ($label) → ব্যর্থ", pu_add_payment($pdo, $id1, $amt, $date, '', '', 't', $NOW)[0] === false);
foreach ([['০', '0'], ['ঋণাত্মক', '-5'], ['অক্ষর', 'abc'], ['ফাঁকা', ''], ['অ্যারে', ['5']], ['বাকির বেশি', '1500.01']] as [$l, $a]) $bad2($l, $a);
$bad2('ভুল তারিখ', '10', '2019-13-01'); $bad2('ভবিষ্যৎ তারিখ', '10', '2026-11-26');
t('অজানা ক্রয়ে পরিশোধ → ব্যর্থ', pu_add_payment($pdo, 999999999, '10', '2019-02-01', '', '', 't', $NOW)[0] === false);
t('কিস্তি ৪০০ সফল → বাকি 1100', pu_add_payment($pdo, $id1, '400', '2019-02-01', 'বিকাশ', 'প্রথম কিস্তি', 'tester', $NOW)[0] === true && near(pu_get($pdo, $id1)['purchase']['due'], 1100));
t('দ্বিতীয় কিস্তি ৬০০ → বাকি 500, পরিশোধ-সারি ৩টি', pu_add_payment($pdo, $id1, '600', '2019-02-15', '', '', 't', $NOW)[0] === true && near(pu_get($pdo, $id1)['purchase']['due'], 500) && count(pu_get($pdo, $id1)['payments']) === 3);
t('বাকির বেশি (৫০০.০১) → ব্যর্থ, বাকি অপরিবর্তিত', pu_add_payment($pdo, $id1, '500.01', '2019-03-01', '', '', 't', $NOW)[0] === false && near(pu_get($pdo, $id1)['purchase']['due'], 500));
t('পুরো বাকি ৫০০ একসাথে → বাকি 0', pu_add_payment($pdo, $id1, '500', '2019-03-01', '', '', 't', $NOW)[0] === true && near(pu_get($pdo, $id1)['purchase']['due'], 0));
t('পুরো-শোধ ক্রয়ে আরও পরিশোধ → ব্যর্থ', pu_add_payment($pdo, $id1, '1', '2019-03-02', '', '', 't', $NOW)[0] === false);
$pay = pu_get($pdo, $id1)['payments'];
t('ভুল পরিশোধ মুছলে বাকি আবার বাড়ে (৬০০ মুছে → বাকি 600)', pu_delete_payment($pdo, (int)$pay[2]['id'])[0] === true && near(pu_get($pdo, $id1)['purchase']['due'], 600) && pu_delete_payment($pdo, (int)$pay[2]['id'])[0] === false);
$idR = pu_create($pdo, pu_clean_purchase($pdo, array_merge($base, ['invoice_no' => 'INV-R', 'unit_cost' => ['100'], 'qty' => ['1']]), $NOW)[0], 't');
t('সেন্ট-হিসাব: ১০০ বাকিতে ৩৩.৩৩ × ৩ = 99.99, বাকি 0.01, তারপর 0.01 → 0', pu_add_payment($pdo, $idR, '33.33', '2019-02-01', '', '', 't', $NOW)[0] && pu_add_payment($pdo, $idR, '33.33', '2019-02-01', '', '', 't', $NOW)[0] && pu_add_payment($pdo, $idR, '33.33', '2019-02-01', '', '', 't', $NOW)[0] && near(pu_get($pdo, $idR)['purchase']['due'], 0.01) && pu_add_payment($pdo, $idR, '0.01', '2019-02-01', '', '', 't', $NOW)[0] && near(pu_get($pdo, $idR)['purchase']['due'], 0));

echo "\n=== সরবরাহকারীকে একসাথে পরিশোধ (একাধিক ক্রয় মিলিয়ে) ===\n";
$sL = pu_supplier_add($pdo, pu_supplier_clean(['name' => '[T] লাম্প সাপ্লায়ার'])[0]);
$mkBuy = function (int $sid, string $inv, string $date, string $amt, string $paid = '') use ($pdo, $pA, $NOW, $base) {
  return pu_create($pdo, pu_clean_purchase($pdo, array_merge($base, ['supplier_id' => (string)$sid, 'invoice_no' => $inv, 'purchase_date' => $date, 'qty' => ['1'], 'unit_cost' => [$amt], 'paid' => $paid]), $NOW)[0], 't');
};
$L1 = $mkBuy($sL, 'L1', '2019-01-01', '100', '40'); $L2 = $mkBuy($sL, 'L2', '2019-02-01', '200'); $L3 = $mkBuy($sL, 'L3', '2019-03-01', '300');
$other = $mkBuy($s2, 'O1', '2019-01-01', '500');
$dueOf = fn($id) => pu_get($pdo, $id)['purchase']['due'];
t('শুরু: বাকি L1=60, L2=200, L3=300', near($dueOf($L1), 60) && near($dueOf($L2), 200) && near($dueOf($L3), 300));
$sd = array_values(array_filter(pu_suppliers_with_dues($pdo), fn($r) => (int)$r['id'] === $sL))[0];
t('সরবরাহকারীর বাকি 560 (মোট 600, পরিশোধ 40), ক্রয় ৩টি', near($sd['due'], 560) && near($sd['total'], 600) && near($sd['paid'], 40) && $sd['cnt'] === 3);
[$okL, $mL, $alloc] = pu_pay_supplier($pdo, $sL, '250', '2019-04-01', 'ব্যাংক', 'চেক ১২৩', 'tester', $NOW);
t('একসাথে ২৫০: সফল; বণ্টন পুরোনো থেকে — L1 60, L2 190', $okL && count($alloc) === 2 && $alloc[0][0] === $L1 && near($alloc[0][1], 60) && $alloc[1][0] === $L2 && near($alloc[1][1], 190));
t('ফল: L1=0, L2=10, L3=300; অন্য সরবরাহকারী অপ্রভাবিত', near($dueOf($L1), 0) && near($dueOf($L2), 10) && near($dueOf($L3), 300) && near($dueOf($other), 500));
$ls = pu_get($pdo, $L2)['payments'];
t('বণ্টনের পরিশোধ-সারিতে তারিখ/মাধ্যম/নোট ("একসাথে পরিশোধ")', end($ls)['paid_date'] === '2019-04-01' && end($ls)['method'] === 'ব্যাংক' && strpos(end($ls)['note'], 'একসাথে পরিশোধ') !== false && strpos(end($ls)['note'], 'চেক ১২৩') !== false);
t('মোট বাকির (310) বেশি → ব্যর্থ, কিছুই লেখা হয় না', pu_pay_supplier($pdo, $sL, '310.01', '2019-04-02', '', '', 't', $NOW)[0] === false && near($dueOf($L2), 10) && near($dueOf($L3), 300));
foreach ([['০', '0'], ['ঋণাত্মক', '-1'], ['অক্ষর', 'x'], ['ফাঁকা', '']] as [$l, $a]) t("একসাথে পরিশোধ অবৈধ ($l) → ব্যর্থ", pu_pay_supplier($pdo, $sL, $a, '2019-04-02', '', '', 't', $NOW)[0] === false);
t('একসাথে পরিশোধ: ভবিষ্যৎ তারিখ/অজানা সরবরাহকারী → ব্যর্থ', pu_pay_supplier($pdo, $sL, '10', '2026-11-26', '', '', 't', $NOW)[0] === false && pu_pay_supplier($pdo, 999999999, '10', '2019-04-02', '', '', 't', $NOW)[0] === false);
[$okL2, , $alloc2] = pu_pay_supplier($pdo, $sL, '310', '2019-04-05', '', '', 'tester', $NOW);
t('বাকি পুরোটা (310) একসাথে: L2 10 + L3 300 → সব বাকি 0', $okL2 && count($alloc2) === 2 && near($dueOf($L2), 0) && near($dueOf($L3), 0));
t('সব শোধ হলে আরও একসাথে পরিশোধ → ব্যর্থ (বাকি ০)', pu_pay_supplier($pdo, $sL, '1', '2019-04-06', '', '', 't', $NOW)[0] === false);
$cancelBuy = $mkBuy($sL, 'L4-cancel', '2019-05-01', '1000');
pu_void($pdo, $cancelBuy);
t('বাতিল ক্রয় একসাথে-পরিশোধে ধরা হয় না (বাকি ০ থাকে)', pu_pay_supplier($pdo, $sL, '1', '2019-05-02', '', '', 't', $NOW)[0] === false);

echo "\n=== বাতিল ===\n";
$stBeforeVoid = $stock($pA); $cBeforeVoid = $costOf($pA);
$V = $mkBuy($s1, 'V1', '2019-06-01', '100', '');
$stAfterBuy = $stock($pA); $cAfterBuy = $costOf($pA);
t('ক্রয়ে স্টক +1', $stAfterBuy === $stBeforeVoid + 1);
t('পরিশোধ থাকলে বাতিল হয় না (বার্তা)', (function () use ($pdo, $V, $NOW) { pu_add_payment($pdo, $V, '10', '2019-06-02', '', '', 't', $NOW); [$ok, $msg] = pu_void($pdo, $V); return $ok === false && strpos($msg, 'পরিশোধ') !== false && pu_get($pdo, $V)['purchase']['status'] === 'active'; })());
$vp = pu_get($pdo, $V)['payments'];
pu_delete_payment($pdo, (int)$vp[0]['id']);
[$okV, , $shortV] = pu_void($pdo, $V);
t('পরিশোধ মুছে বাতিল সফল; স্টক ফেরে', $okV === true && $stock($pA) === $stBeforeVoid && $shortV === 0);
t('গড় ক্রয়মূল্য ফেরানো হয় না (নথিভুক্ত আচরণ)', near($costOf($pA), $cAfterBuy));
t('স্ট্যাটাস cancelled, cancelled_at বসেছে', pu_get($pdo, $V)['purchase']['status'] === 'cancelled' && pu_get($pdo, $V)['purchase']['cancelled_at'] !== null);
t('আবার বাতিল → ব্যর্থ, স্টক দ্বিতীয়বার কমে না', pu_void($pdo, $V)[0] === false && $stock($pA) === $stBeforeVoid);
t('বাতিল ক্রয়ে পরিশোধ → ব্যর্থ', pu_add_payment($pdo, $V, '5', '2019-06-03', '', '', 't', $NOW)[0] === false);
t('বাতিলের পর একই ইনভয়েস নম্বর আবার ব্যবহার করা যায়', pu_clean_purchase($pdo, array_merge($base, ['invoice_no' => 'V1', 'purchase_date' => '2019-06-05']), $NOW)[0] !== null);
$W = $mkBuy($s1, 'W1', '2019-06-10', '100');
$pdo->exec("UPDATE products SET stock = 0 WHERE id = $pA");
[$okW, , $shortW] = pu_void($pdo, $W);
t('বিক্রি হয়ে স্টক কম থাকলে বাতিল সফল, স্টক ০-এর নিচে নামে না, "স্টক-কম" সংখ্যা ১', $okW && $stock($pA) === 0 && $shortW === 1);
t('অজানা ক্রয় বাতিল → ব্যর্থ', pu_void($pdo, 999999999)[0] === false);

echo "\n=== তালিকা, ফিল্টার, মোট ===\n";
$A = pu_totals($pdo, null, $s1);
t('সরবরাহকারী s1: বাতিল বাদে মোট = 2500 + 12000 + 100(INV-R) = 14600', near($A['total'], 14600) && $A['count'] === 3);
t('s1 পরিশোধ = 1000+400+0... (id1: ১০০০+৪০০+৫০০ বাদে ৬০০ মোছা) → মোট বাকি = মোট − পরিশোধ', near($A['due'], $A['total'] - $A['paid']));
$jan = rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '1'], $NOW);
$tj = pu_totals($pdo, $jan);
t('জানুয়ারি ২০১৯ মোট: id1 (2500) + INV-R (100) + L1 (100) + O1 (500) = 3200 (বাতিল ঘোস্ট বাদ)', near($tj['total'], 3200) && $tj['count'] === 4);
$none = rp_parse_range(['type' => 'monthly', 'year' => '2019', 'month' => '8'], $NOW);
t('ফাঁকা মাসে ০', pu_totals($pdo, $none)['count'] === 0 && near(pu_totals($pdo, $none)['due'], 0));
t('তালিকা: s1-এর সব ক্রয় (বাতিলসহ) নতুন আগে', (function () use ($pdo, $s1) { $l = pu_list($pdo, null, $s1, 'all', null, 1, 50); return count($l) >= 4 && $l[0]['purchase_date'] >= end($l)['purchase_date']; })());
t('status=cancelled → শুধু বাতিলগুলো', (function () use ($pdo, $s1) { $l = pu_list($pdo, null, $s1, 'cancelled', null, 1, 50); return count($l) >= 2 && !array_filter($l, fn($r) => $r['status'] !== 'cancelled'); })());
t('status=due → বাকি আছে এমন সক্রিয় ক্রয় মাত্র', (function () use ($pdo, $s1) { $l = pu_list($pdo, null, $s1, 'due', null, 1, 50); return count($l) >= 1 && !array_filter($l, fn($r) => $r['status'] !== 'active' || $r['due'] <= 0); })());
t('status=paid → পুরো-শোধ সক্রিয় ক্রয়', (function () use ($pdo, $sL) { $l = pu_list($pdo, null, $sL, 'paid', null, 1, 50); return count($l) === 3 && !array_filter($l, fn($r) => $r['due'] > 0); })());
t('খোঁজা (ইনভয়েস নম্বর)', count(pu_list($pdo, null, null, 'all', 'INV-A', 1, 50)) === 1);
t('খোঁজা (সরবরাহকারীর নাম)', count(pu_list($pdo, null, null, 'all', 'লাম্প', 1, 50)) >= 4);
t('খোঁজায় SQL ইনজেকশন নিরাপদ', count(pu_list($pdo, null, null, 'all', "x' OR '1'='1", 1, 50)) === 0);
t('গণনা = তালিকার সংখ্যা', pu_count($pdo, null, $sL, 'all', null) === count(pu_list($pdo, null, $sL, 'all', null, 1, 50)));
$pgA = pu_list($pdo, null, $sL, 'all', null, 1, 2); $pgB = pu_list($pdo, null, $sL, 'all', null, 2, 2);
t('পেজিনেশন: ডুপ্লিকেট নেই, পৃষ্ঠা ০ → ১', count($pgA) === 2 && count($pgB) >= 1 && !array_intersect(array_column($pgA, 'id'), array_column($pgB, 'id')) && count(pu_list($pdo, null, $sL, 'all', null, 0, 2)) === 2);
t('বাকি-সহ সরবরাহকারী তালিকা: খোঁজা ও active ফিল্টার', count(pu_suppliers_with_dues($pdo, 'লাম্প')) === 1 && count(array_filter(pu_suppliers_with_dues($pdo, '[T]', true), fn($r) => (int)$r['active'] === 0)) === 0);
$gl = array_values(array_filter(pu_suppliers_with_dues($pdo), fn($r) => (int)$r['id'] === $sL))[0];
t('লাম্প সাপ্লায়ার: বাতিল বাদে মোট 600, বাকি 0', near($gl['total'], 600) && near($gl['due'], 0));
t('ক্রয়-ইতিহাসওয়ালা সরবরাহকারী মোছা যায় না', pu_supplier_delete($pdo, $sL)[0] === false && pu_supplier_get($pdo, $sL) !== null);

$clean();
t('শেষে টেস্ট ডেটা মুছেছে', (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE name LIKE '[T]%'")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM products WHERE name LIKE '[T]%'")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM purchases pu LEFT JOIN suppliers s ON s.id=pu.supplier_id WHERE s.id IS NULL")->fetchColumn() === 0);
echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
