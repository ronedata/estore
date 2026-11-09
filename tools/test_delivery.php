<?php
// tools/test_delivery.php — জেলা-ভিত্তিক ডেলিভারি চার্জ লজিক টেস্ট
// php tools/test_delivery.php   (শেষে জেলার চার্জ ও গ্লোবাল আগের অবস্থায় ফিরিয়ে দেয়)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/delivery.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}

$snapFees   = $pdo->query("SELECT id, fee FROM delivery_districts")->fetchAll(PDO::FETCH_KEY_PAIR);
$snapGlobal = $pdo->query("SELECT value FROM settings WHERE `key`='delivery_global'")->fetchColumn();
$byEn = function (string $en) use ($pdo) { $s = $pdo->prepare("SELECT * FROM delivery_districts WHERE name_en=?"); $s->execute([$en]); return $s->fetch(); };

echo "\n=== মাইগ্রেশন ও ডেটা ===\n";
t('delivery_districts টেবিল আছে', (bool)$pdo->query("SHOW TABLES LIKE 'delivery_districts'")->fetchColumn());
t('৬৪টি জেলা', (int)$pdo->query("SELECT COUNT(*) FROM delivery_districts")->fetchColumn() === 64);
t('৮টি বিভাগ', (int)$pdo->query("SELECT COUNT(DISTINCT division) FROM delivery_districts")->fetchColumn() === 8);
$expect = ['ঢাকা'=>13,'চট্টগ্রাম'=>11,'রাজশাহী'=>8,'খুলনা'=>10,'বরিশাল'=>6,'সিলেট'=>4,'রংপুর'=>8,'ময়মনসিংহ'=>4];
$got = $pdo->query("SELECT division, COUNT(*) FROM delivery_districts GROUP BY division")->fetchAll(PDO::FETCH_KEY_PAIR);
$okDiv = true; foreach ($expect as $d => $n) if ((int)($got[$d] ?? 0) !== $n) $okDiv = false;
t('প্রতি বিভাগের জেলা সংখ্যা ঠিক', $okDiv);
t('English নাম অনন্য', (int)$pdo->query("SELECT COUNT(DISTINCT name_en) FROM delivery_districts")->fetchColumn() === 64);
t('বাংলা নাম অনন্য', (int)$pdo->query("SELECT COUNT(DISTINCT name_bn) FROM delivery_districts")->fetchColumn() === 64);
t('কোনো বাংলা নাম খালি/?-ভাঙা নয়', (int)$pdo->query("SELECT COUNT(*) FROM delivery_districts WHERE name_bn='' OR name_bn LIKE '%?%'")->fetchColumn() === 0);
t('orders.shipping_district কলাম আছে', (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'shipping_district'")->fetch());
t('orders.shipping_area এখনও ENUM', stripos((string)$pdo->query("SHOW COLUMNS FROM orders LIKE 'shipping_area'")->fetch()['Type'], 'enum') === 0);
t('schema_v9_at চিহ্ন আছে', (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v9_at'")->fetchColumn());
t('manage_delivery পারমিশন আছে', (bool)$pdo->query("SELECT 1 FROM permissions WHERE name='manage_delivery'")->fetchColumn());
t('Super Admin রোলে যুক্ত', (bool)$pdo->query("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=1 AND p.name='manage_delivery'")->fetchColumn());
ensure_estore_schema_v9($pdo, true);
t('আবার চালালে জেলা ডুপ্লিকেট হয় না', (int)$pdo->query("SELECT COUNT(*) FROM delivery_districts")->fetchColumn() === 64);
t('আবার চালালে চার্জ বদলায় না', $pdo->query("SELECT id, fee FROM delivery_districts")->fetchAll(PDO::FETCH_KEY_PAIR) == $snapFees);

echo "\n=== চার্জ নিয়ম (ফাঁকা = গ্লোবাল, 0 = ফ্রি) ===\n";
$dhaka = $byEn('Dhaka'); $gaz = $byEn('Gazipur'); $ctg = $byEn('Chattogram'); $syl = $byEn('Sylhet');
[$ok, ] = dl_save($pdo, '120', [$dhaka['id'] => '60', $gaz['id'] => '', $ctg['id'] => '100', $syl['id'] => '0']);
t('সেভ সফল', $ok);
t('গ্লোবাল ১২০', dl_global($pdo) === 120.0);
$q = dl_quote($pdo, (int)$dhaka['id'], false);
t('ঢাকা → ৬০ (জেলার নিজস্ব)', $q['fee'] === 60.0 && $q['source'] === 'district');
$q = dl_quote($pdo, (int)$gaz['id'], false);
t('গাজীপুর (ফাঁকা) → গ্লোবাল ১২০', $q['fee'] === 120.0 && $q['source'] === 'global');
$q = dl_quote($pdo, (int)$ctg['id'], false);
t('চট্টগ্রাম → ১০০', $q['fee'] === 100.0);
$q = dl_quote($pdo, (int)$syl['id'], false);
t('সিলেট (0) → ফ্রি, গ্লোবাল নয়', $q['fee'] === 0.0 && $q['source'] === 'district');
$q = dl_quote($pdo, (int)$ctg['id'], true);
t('সব পণ্যে Free ট্যাগ → জেলা যাই হোক ০', $q['fee'] === 0.0 && $q['source'] === 'free');
$q = dl_quote($pdo, null, false);
t('জেলা ছাড়া → গ্লোবাল', $q['fee'] === 120.0 && $q['district'] === null);
$q = dl_quote($pdo, 99999, false);
t('অবৈধ জেলা id → গ্লোবাল (ক্র্যাশ নয়)', $q['fee'] === 120.0 && $q['district'] === null);
t('ঢাকা জেলা → area=dhaka', dl_quote($pdo, (int)$dhaka['id'], false)['area'] === 'dhaka');
t('অন্য জেলা → area=nationwide', dl_quote($pdo, (int)$ctg['id'], false)['area'] === 'nationwide');
t('জেলা ছাড়া → area=nationwide', dl_quote($pdo, null, false)['area'] === 'nationwide');
dl_save($pdo, '150', []);
t('গ্লোবাল বদলালে ফাঁকা জেলা অনুসরণ করে (১৫০)', dl_quote($pdo, (int)$gaz['id'], false)['fee'] === 150.0);
t('গ্লোবাল বদলালে নিজস্ব চার্জের জেলা অপরিবর্তিত', dl_quote($pdo, (int)$ctg['id'], false)['fee'] === 100.0);
t('গ্লোবাল বদলালে ফ্রি জেলা ফ্রিই থাকে', dl_quote($pdo, (int)$syl['id'], false)['fee'] === 0.0);

echo "\n=== ইনপুট যাচাই ===\n";
t("parse '' → null", dl_parse_fee('') === null);
t("parse '  ' → null", dl_parse_fee('  ') === null);
t('parse null → null', dl_parse_fee(null) === null);
t("parse '0' → 0.0 (ফ্রি, null নয়)", dl_parse_fee('0') === 0.0);
t("parse '75' → 75.0", dl_parse_fee('75') === 75.0);
t("parse '12.345' → 12.35", dl_parse_fee('12.345') === 12.35);
t("parse 'abc' → false", dl_parse_fee('abc') === false);
t("parse '-1' → false", dl_parse_fee('-1') === false);
t("parse '100001' → false", dl_parse_fee('100001') === false);
t("parse '100000' → ঠিক", dl_parse_fee('100000') === 100000.0);
t('parse অ্যারে → false', dl_parse_fee(['1']) === false);
t("parse '1e3' সংখ্যা হিসেবে গ্রহণ (১০০০)", dl_parse_fee('1e3') === 1000.0);

echo "\n=== সেভ — সব-অথবা-কিছুই-না ===\n";
dl_save($pdo, '120', [$gaz['id'] => '', $ctg['id'] => '100']);
[$ok, $msg] = dl_save($pdo, '200', [$gaz['id'] => '55', $ctg['id'] => 'bad']);
t('একটি অবৈধ হলে পুরো সেভ বাতিল', $ok === false);
t('বাতিলে গ্লোবাল বদলায়নি', dl_global($pdo) === 120.0);
t('বাতিলে আগের জেলার চার্জও বদলায়নি (আংশিক সেভ নেই)', $byEn('Gazipur')['fee'] === null);
t('ত্রুটির বার্তায় জেলার নাম আছে', mb_strpos($msg, 'চট্টগ্রাম') !== false);
[$ok, ] = dl_save($pdo, '', []);
t('গ্লোবাল খালি → বাতিল', $ok === false);
[$ok, ] = dl_save($pdo, '-5', []);
t('গ্লোবাল ঋণাত্মক → বাতিল', $ok === false);
[$ok, ] = dl_save($pdo, 'abc', []);
t('গ্লোবাল অক্ষর → বাতিল', $ok === false);
[$ok, ] = dl_save($pdo, '120', [99999 => '10', 'x' => '5']);
t('অজানা জেলা id উপেক্ষা (ক্র্যাশ নয়)', $ok === true && (int)$pdo->query("SELECT COUNT(*) FROM delivery_districts")->fetchColumn() === 64);
[$ok, ] = dl_save($pdo, '120', [$gaz['id'] => ['1']]);
t('অ্যারে মান → বাতিল', $ok === false);
dl_save($pdo, '120', [$ctg['id'] => '']);
t('ফাঁকা পাঠালে জেলা আবার গ্লোবালে ফেরে (NULL)', $byEn('Chattogram')['fee'] === null && dl_quote($pdo, (int)$ctg['id'], false)['source'] === 'global');

echo "\n=== আলাদা সেভ (গ্লোবাল / জেলা) ===\n";
dl_save($pdo, '120', [$ctg['id'] => '100', $syl['id'] => '0', $gaz['id'] => '']);
[$ok, ] = dl_save_global($pdo, '175');
t('শুধু গ্লোবাল সেভ সফল', $ok && dl_global($pdo) === 175.0);
t('গ্লোবাল সেভে জেলার চার্জ অপরিবর্তিত (চট্টগ্রাম ১০০)', $byEn('Chattogram')['fee'] === '100.00');
t('গ্লোবাল সেভে ফ্রি জেলা (সিলেট ০) অপরিবর্তিত', $byEn('Sylhet')['fee'] === '0.00');
t('গ্লোবাল সেভে ফাঁকা জেলা ফাঁকাই (NULL)', $byEn('Gazipur')['fee'] === null);
[$ok, ] = dl_save_global($pdo, 'abc');
t('গ্লোবাল অবৈধ → বাতিল, মান অপরিবর্তিত', $ok === false && dl_global($pdo) === 175.0);
[$ok, ] = dl_save_global($pdo, '');
t('গ্লোবাল খালি → বাতিল', $ok === false && dl_global($pdo) === 175.0);
[$ok, ] = dl_save_fees($pdo, [$ctg['id'] => '88']);
t('শুধু জেলার চার্জ সেভ সফল', $ok && $byEn('Chattogram')['fee'] === '88.00');
t('জেলার সেভে গ্লোবাল অপরিবর্তিত (১৭৫)', dl_global($pdo) === 175.0);
[$ok, ] = dl_save_fees($pdo, [$ctg['id'] => '10', $gaz['id'] => 'bad']);
t('জেলার সেভে একটি অবৈধ হলে সব বাতিল (চট্টগ্রাম ৮৮ই থাকে)', $ok === false && $byEn('Chattogram')['fee'] === '88.00');
[$ok, ] = dl_save_fees($pdo, []);
t('জেলার সেভ — ফাঁকা অ্যারে ক্ষতিকর নয়', $ok === true && dl_global($pdo) === 175.0);

echo "\n=== অর্ডারে এলাকার নাম ===\n";
t('জেলা থাকলে জেলা', dl_order_area_label(['shipping_district' => 'চট্টগ্রাম', 'shipping_area' => 'nationwide']) === 'চট্টগ্রাম');
t('পুরোনো অর্ডার dhaka → ঢাকা', dl_order_area_label(['shipping_district' => null, 'shipping_area' => 'dhaka']) === 'ঢাকা');
t('পুরোনো অর্ডার nationwide → সারা দেশ', dl_order_area_label(['shipping_area' => 'nationwide']) === 'সারা দেশ');

/* ---- আগের অবস্থা ফিরিয়ে দিই ---- */
$up = $pdo->prepare("UPDATE delivery_districts SET fee = ? WHERE id = ?");
foreach ($snapFees as $id => $fee) $up->execute([$fee, $id]);
$pdo->prepare("UPDATE settings SET value=? WHERE `key`='delivery_global'")->execute([$snapGlobal]);
t('টেস্ট শেষে চার্জ আগের অবস্থায় ফেরানো', $pdo->query("SELECT id, fee FROM delivery_districts")->fetchAll(PDO::FETCH_KEY_PAIR) == $snapFees
  && $pdo->query("SELECT value FROM settings WHERE `key`='delivery_global'")->fetchColumn() === $snapGlobal);

echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
