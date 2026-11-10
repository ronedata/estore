<?php
// tools/test_order_access.php — অর্ডারের গোপন লিংক-কোড, মোবাইল+অর্ডার কোড ট্র্যাকিং, চেষ্টার সীমা — লজিক টেস্ট
// php tools/test_order_access.php   (নিজের "[T]" অর্ডার ও টেস্ট-আইপি 203.0.113.x ছোঁয়, শেষে মুছে ফেলে)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/order_access.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}
$clean = function () use ($pdo) {
  $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM (SELECT id FROM orders WHERE customer_name LIKE '[T]%') x)");
  $pdo->exec("DELETE FROM orders WHERE customer_name LIKE '[T]%'");
  $pdo->exec("DELETE FROM tracking_attempts WHERE ip LIKE '203.0.113.%'");
};
$clean();
$mk = function (string $code, string $mobile, string $token, ?int $cid = null) use ($pdo): int {
  $pdo->prepare("INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_fee, subtotal, total, payment_method, status, customer_id, public_token)
                 VALUES (?, '[T] Test', ?, '[T] addr', 'dhaka', 0, 100, 100, 'COD', 'pending', ?, ?)")->execute([$code, $mobile, $cid, $token]);
  return (int)$pdo->lastInsertId();
};

echo "\n=== মাইগ্রেশন ===\n";
t('orders.public_token কলাম আছে', (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'public_token'")->fetch());
t('অনন্য ইনডেক্স (uq_public_token) আছে', (bool)$pdo->query("SHOW INDEX FROM orders WHERE Key_name='uq_public_token'")->fetch());
t('কোনো অর্ডারে কোড ফাঁকা নয় (পুরোনোগুলোতেও ভরা)', (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE public_token IS NULL OR public_token=''")->fetchColumn() === 0 || (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE customer_name LIKE '[T]%'")->fetchColumn() > 0);
t('সব কোড ৩২ হেক্স', (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE public_token NOT REGEXP '^[a-f0-9]{32}$'")->fetchColumn() === 0);
t('সব কোড অনন্য', (int)$pdo->query("SELECT COUNT(*) - COUNT(DISTINCT public_token) FROM orders WHERE public_token IS NOT NULL")->fetchColumn() === 0);
t('tracking_attempts টেবিল আছে', (bool)$pdo->query("SHOW TABLES LIKE 'tracking_attempts'")->fetchColumn());
t('schema_v12_at চিহ্ন আছে', (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v12_at'")->fetchColumn());
$before = $pdo->query("SELECT id, public_token FROM orders ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
t('আবার চালালে ত্রুটি নেই', ensure_estore_schema_v12($pdo, true) === true);
t('আবার চালালে বিদ্যমান কোড বদলায় না (লিংক ভাঙে না)', $pdo->query("SELECT id, public_token FROM orders ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR) == $before);

echo "\n=== কোড তৈরি ও যাচাই ===\n";
$seen = []; for ($i = 0; $i < 2000; $i++) $seen[oa_new_token()] = 1;
t('২০০০টি কোড সব ভিন্ন', count($seen) === 2000);
t('কোড ঠিক ৩২ ছোট-হাতের হেক্স', (bool)preg_match('/^[a-f0-9]{32}$/D', oa_new_token()));
$g = bin2hex(random_bytes(16));
t('বৈধ কোড গ্রহণ', oa_valid_token($g));
t('বড়-হাতের হেক্স বাতিল', !oa_valid_token(strtoupper($g)));
t('৩১/৩৩ অক্ষর বাতিল', !oa_valid_token(substr($g, 1)) && !oa_valid_token($g . 'a'));
t('শেষে নতুন-লাইন থাকলে বাতিল ($-এর ফাঁদ)', !oa_valid_token($g . "\n"));
t('SQL ইনজেকশন/অ্যারে/null বাতিল', !oa_valid_token("' OR 1=1 --") && !oa_valid_token(['a']) && !oa_valid_token(null) && !oa_valid_token(''));

echo "\n=== মোবাইল ও অর্ডার কোড নরমালাইজ ===\n";
foreach (['01711111111', '+8801711111111', '8801711111111', '1711111111', '017-1111-1111', ' 017 11111111 ', '+880 1711-111111'] as $m) t("মোবাইল '$m' → 01711111111", oa_norm_mobile($m) === '01711111111');
foreach (['12345', '', 'abc', '02711111111', '01211111111', '017111111111', '880171111111', ['01711111111'], null, str_repeat('1', 30)] as $m) t('অবৈধ মোবাইল ' . json_encode($m, JSON_UNESCAPED_UNICODE) . ' → null', oa_norm_mobile($m) === null);
foreach (['ORD0000034', 'ord0000034', '#ORD0000034', 'ORD-0000034', ' ord 0000034 ', '34', '0034', 'ORD34', "ORD0000034\n", '#34'] as $c) t("কোড '" . trim($c) . "' → ORD0000034", oa_norm_code($c) === 'ORD0000034');
foreach (['', 'abc', 'ORD', 'ORD00000034', '12345678', 'XYZ0000034', "34'; DROP TABLE orders;--", ['34'], null, 'ORD-'] as $c) t('অবৈধ কোড ' . json_encode($c, JSON_UNESCAPED_UNICODE) . ' → null', oa_norm_code($c) === null);

echo "\n=== মোবাইল + অর্ডার কোড দিয়ে খোঁজা ===\n";
$tokA = oa_new_token(); $tokB = oa_new_token(); $tokC = oa_new_token();
$idA = $mk('ORD9990001', '01711111111', $tokA);
$idB = $mk('ORD9990002', '+8801722222222', $tokB);
$idC = $mk('ORD9990003', '8801733333333', $tokC);
$f = oa_find($pdo, '01711111111', 'ORD9990001');
t('ঠিক জোড়ায় অর্ডার পায়', $f && (int)$f['id'] === $idA);
t('মোবাইল ভিন্ন ফরম্যাটে (+880…) লিখলেও পায়', ($f = oa_find($pdo, '+8801711111111', 'ORD9990001')) && (int)$f['id'] === $idA);
t('কোড ছোট হাতে/# সহ লিখলেও পায়', ($f = oa_find($pdo, '01711111111', '#ord9990001')) && (int)$f['id'] === $idA);
t('শুধু সংখ্যা দিলেও পায় (9990001)', ($f = oa_find($pdo, '01711111111', '9990001')) && (int)$f['id'] === $idA);
t('ডাটাবেসে "+88…" ফরম্যাটে থাকা মোবাইলও মেলে', ($f = oa_find($pdo, '01722222222', 'ORD9990002')) && (int)$f['id'] === $idB);
t('ডাটাবেসে "88…" ফরম্যাটে থাকা মোবাইলও মেলে', ($f = oa_find($pdo, '01733333333', 'ORD9990003')) && (int)$f['id'] === $idC);
t('সঠিক কোড কিন্তু ভুল মোবাইল → পায় না (অন্যের অর্ডার দেখা যায় না)', oa_find($pdo, '01799999999', 'ORD9990001') === null);
t('সঠিক মোবাইল কিন্তু অন্য অর্ডারের কোড → পায় না', oa_find($pdo, '01711111111', 'ORD9990002') === null);
t('সঠিক মোবাইল, কোড ভুল → পায় না', oa_find($pdo, '01711111111', 'ORD9999999') === null);
t('শুধু মোবাইল (কোড খালি) → পায় না', oa_find($pdo, '01711111111', '') === null);
t('শুধু কোড (মোবাইল খালি) → পায় না', oa_find($pdo, '', 'ORD9990001') === null);
t('SQL ইনজেকশন মোবাইলে → পায় না, ক্র্যাশ নয়', oa_find($pdo, "' OR '1'='1", 'ORD9990001') === null && oa_find($pdo, '01711111111', "' OR '1'='1") === null);
t('অ্যারে ইনপুটে ক্র্যাশ নয়', oa_find($pdo, ['01711111111'], ['ORD9990001']) === null);

echo "\n=== গোপন কোড দিয়ে অর্ডার পেজ ===\n";
t('oa_by_token: ঠিক কোডে অর্ডার', ($o = oa_by_token($pdo, $tokA)) && (int)$o['id'] === $idA);
t('ভুল/অজানা কোডে null', oa_by_token($pdo, bin2hex(random_bytes(16))) === null);
t('অবৈধ কোডে null (ডাটাবেস কুয়েরিই হয় না)', oa_by_token($pdo, "' OR 1=1") === null && oa_by_token($pdo, ['x']) === null && oa_by_token($pdo, '') === null);
t('oa_details_url: কোডসহ URL, আইডি নেই', (function () use ($tokA, $idA) { $u = oa_details_url($tokA); return strpos($u, 'single_order_details.php?t=' . $tokA) !== false && strpos($u, 'id=') === false; })());
t('কোড না থাকা অর্ডারে oa_ensure_token কোড বসায়', (function () use ($pdo, $mk) {
  $id = $mk('ORD9990004', '01744444444', bin2hex(random_bytes(16)));
  $pdo->exec("UPDATE orders SET public_token=NULL WHERE id=$id");
  $o = $pdo->query("SELECT * FROM orders WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
  $tk = oa_ensure_token($pdo, $o);
  return $tk !== null && oa_valid_token($tk) && $pdo->query("SELECT public_token FROM orders WHERE id=$id")->fetchColumn() === $tk;
})());

echo "\n=== আইডি দিয়ে খোলা: শুধু লগইন করা মালিক ===\n";
$cidOwner = 880001; $cidOther = 880002;
$idOwn = $mk('ORD9990005', '01755555555', oa_new_token(), $cidOwner);
t('আইডি দিয়ে, লগইন ছাড়া → null (আইডি আন্দাজে তথ্য ফাঁস বন্ধ)', oa_load_for_view($pdo, ['id' => (string)$idOwn], []) === null);
t('আইডি দিয়ে, মালিক লগইন → অর্ডার', ($o = oa_load_for_view($pdo, ['id' => (string)$idOwn], ['customer_id' => $cidOwner])) && (int)$o['id'] === $idOwn);
t('আইডি দিয়ে, অন্য ক্রেতা লগইন → null', oa_load_for_view($pdo, ['id' => (string)$idOwn], ['customer_id' => $cidOther]) === null);
t('গেস্ট অর্ডার (customer_id null) আইডি দিয়ে কোনো লগইনেই খোলে না', oa_load_for_view($pdo, ['id' => (string)$idA], ['customer_id' => $cidOwner]) === null);
t('গোপন কোড দিলে লগইন ছাড়াই খোলে', ($o = oa_load_for_view($pdo, ['t' => $tokA], [])) && (int)$o['id'] === $idA);
t('ভুল কোড দিলে আইডি ফলব্যাক হয় না (কোড অগ্রাধিকার)', oa_load_for_view($pdo, ['t' => 'bad', 'id' => (string)$idOwn], ['customer_id' => $cidOwner]) === null);
t('আইডি অসংখ্যা ("1 OR 1=1", "-1", "1.5", অ্যারে) → null', oa_load_for_view($pdo, ['id' => '1 OR 1=1'], ['customer_id' => $cidOwner]) === null && oa_load_for_view($pdo, ['id' => '-1'], ['customer_id' => $cidOwner]) === null && oa_load_for_view($pdo, ['id' => '1.5'], ['customer_id' => $cidOwner]) === null && oa_load_for_view($pdo, ['id' => ['1']], ['customer_id' => $cidOwner]) === null);
t('কিছুই না দিলে null', oa_load_for_view($pdo, [], ['customer_id' => $cidOwner]) === null);
t('oa_owner_can_view: customer_id 0/অনুপস্থিত → false', !oa_owner_can_view(['customer_id' => 0], ['customer_id' => 0]) && !oa_owner_can_view(['customer_id' => 5], []));

echo "\n=== ট্র্যাকিংয়ে ভুল চেষ্টার সীমা (১০ বার / ১০ মিনিট) ===\n";
$ip = '203.0.113.10'; $T0 = 1900000000;
t('শুরুতে ব্লক নয়', oa_limit_check($pdo, $ip, $T0)['blocked'] === false);
for ($i = 0; $i < 9; $i++) oa_limit_fail($pdo, $ip, $T0 + $i);
$r = oa_limit_check($pdo, $ip, $T0 + 20);
t('৯টি ভুলে ব্লক নয় (গণনা ৯)', $r['blocked'] === false && $r['fails'] === 9);
oa_limit_fail($pdo, $ip, $T0 + 9);
$r = oa_limit_check($pdo, $ip, $T0 + 30);
t('১০ম ভুলে ব্লক; অপেক্ষা ≈ ১০ মিনিট − ৩০ সেকেন্ড', $r['blocked'] === true && $r['wait'] === 600 - 30);
t('অন্য আইপি ব্লক নয়', oa_limit_check($pdo, '203.0.113.11', $T0 + 30)['blocked'] === false);
t('১০ মিনিট পরে (প্রথম ভুলের ১০ মিনিট পার) ব্লক খোলে', oa_limit_check($pdo, $ip, $T0 + 601)['blocked'] === false);
t('সফল খোঁজা ভুল-গণনায় যোগ হয় না (oa_find নিজে কিছু লেখে না)', (function () use ($pdo) { $b = (int)$pdo->query("SELECT COUNT(*) FROM tracking_attempts")->fetchColumn(); oa_find($pdo, '01711111111', 'ORD9990001'); return (int)$pdo->query("SELECT COUNT(*) FROM tracking_attempts")->fetchColumn() === $b; })());
t('কাস্টম সীমা (৩ বার / ১ মিনিট) মানে', (function () use ($pdo) { $ip = '203.0.113.12'; for ($i = 0; $i < 3; $i++) oa_limit_fail($pdo, $ip, 1900100000 + $i); return oa_limit_check($pdo, $ip, 1900100010, 3, 1)['blocked'] === true && oa_limit_check($pdo, $ip, 1900100070, 3, 1)['blocked'] === false; })());
$bad = new PDO('mysql:host=127.0.0.1;dbname=information_schema;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
t('টেবিল না থাকলে সীমা-যাচাই ব্লক করে না (fail-open)', oa_limit_check($bad, $ip, $T0)['blocked'] === false);
t('টেবিল না থাকলে oa_find/oa_by_token ক্র্যাশ নয়, null', oa_find($bad, '01711111111', 'ORD9990001') === null && oa_by_token($bad, $tokA) === null);

$clean();
t('টেস্ট শেষে টেস্ট-অর্ডার ও চেষ্টা মুছেছে', (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE customer_name LIKE '[T]%'")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM tracking_attempts WHERE ip LIKE '203.0.113.%'")->fetchColumn() === 0);

echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
