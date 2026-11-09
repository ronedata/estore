<?php
// tools/test_order_discount.php — অর্ডার ডিসকাউন্টের লজিক ও মাইগ্রেশন টেস্ট (ডাটাবেস বদলায় না)
// php tools/test_order_discount.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/order_discount.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}
function same($a, $b): bool { return is_float($a) && is_float($b) ? abs($a - $b) < 0.0001 : $a === $b; }

echo "\n=== মাইগ্রেশন ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
t('orders.discount কলাম আছে (DECIMAL, ডিফল্ট ০)', isset($cols['discount']) && stripos($cols['discount']['Type'], 'decimal') === 0 && (float)$cols['discount']['Default'] === 0.0);
t('orders.discount_note, discount_by আছে', isset($cols['discount_note'], $cols['discount_by']));
t('schema_v10_at চিহ্ন আছে', (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v10_at'")->fetchColumn());
$before = $pdo->query("SELECT COUNT(*), IFNULL(SUM(total),0) FROM orders")->fetch(PDO::FETCH_NUM);
ensure_estore_schema_v10($pdo, true);
$after = $pdo->query("SELECT COUNT(*), IFNULL(SUM(total),0) FROM orders")->fetch(PDO::FETCH_NUM);
t('আবার চালালে অর্ডার সংখ্যা ও মোট টাকা অপরিবর্তিত', $before == $after);
t('পুরোনো অর্ডারে ডিসকাউন্ট ০ (আগের মোট অক্ষত)', (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE discount<>0 AND discount_by IS NULL AND discount_note IS NULL AND id<=33")->fetchColumn() === 0);

echo "\n=== সংখ্যা যাচাই ===\n";
t("parse '' → 0.0", od_parse_number('') === 0.0);
t('parse null → 0.0', od_parse_number(null) === 0.0);
t("parse '  ' → 0.0", od_parse_number('  ') === 0.0);
t("parse '100' → 100.0", od_parse_number('100') === 100.0);
t("parse '12.345' → 12.35", od_parse_number('12.345') === 12.35);
t("parse '-1' → false", od_parse_number('-1') === false);
t("parse 'abc' → false", od_parse_number('abc') === false);
t("parse '1e3' → 1000.0", od_parse_number('1e3') === 1000.0);
t("parse 'INF' → false", od_parse_number('INF') === false);
t("parse 'NAN' → false", od_parse_number('NAN') === false);
t('parse অ্যারে → false', od_parse_number(['1']) === false);
t("parse '10000001' (সীমার বেশি) → false", od_parse_number('10000001') === false);
t("parse '10000000' (সীমায়) → ঠিক", od_parse_number('10000000') === 10000000.0);
t('validate: percent 100 ঠিক', od_validate_input('percent', '100') === null);
t('validate: percent 100.01 ত্রুটি', od_validate_input('percent', '100.01') !== null);
t('validate: percent 150 ত্রুটি', od_validate_input('percent', '150') !== null);
t('validate: amount 150 (শতাংশ-সীমা নেই) ঠিক', od_validate_input('amount', '150') === null);
t('validate: ঋণাত্মক ত্রুটি', od_validate_input('amount', '-5') !== null);
t('validate: অক্ষর ত্রুটি', od_validate_input('amount', 'ten') !== null);
t("validate: খালি ঠিক (০)", od_validate_input('amount', '') === null);

echo "\n=== চূড়ান্ত হিসাব ===\n";
[$a, $c, $e] = od_resolve('amount', '200', 2940.0);
t('৳200 / সাবটোটাল ২৯৪০ → ২০০, ছাঁটা নয়', same($a, 200.0) && $c === false && $e === null);
[$a, $c, $e] = od_resolve('percent', '10', 2940.0);
t('১০% / ২৯৪০ → ২৯৪', same($a, 294.0) && $c === false);
[$a, $c, $e] = od_resolve('percent', '12.5', 1490.0);
t('১২.৫% / ১৪৯০ → ১৮৬.২৫ (পয়সা পর্যন্ত)', same($a, 186.25));
[$a, $c, $e] = od_resolve('percent', '33.333', 100.0);
t('৩৩.৩৩৩% / ১০০ → ৩৩.৩৩ (২ দশমিকে গোল)', same($a, 33.33));
[$a, $c, $e] = od_resolve('percent', '100', 2940.0);
t('১০০% → সাবটোটালের সমান (ঠিক, ছাঁটা নয়)', same($a, 2940.0) && $c === false);
[$a, $c, $e] = od_resolve('amount', '99999', 2940.0);
t('সাবটোটালের বেশি টাকা → সাবটোটালে ছাঁটা (clamped)', same($a, 2940.0) && $c === true);
[$a, $c, $e] = od_resolve('amount', '2940', 2940.0);
t('ঠিক সাবটোটালের সমান → ছাঁটা নয়', same($a, 2940.0) && $c === false);
[$a, $c, $e] = od_resolve('amount', '', 2940.0);
t('খালি → ০', same($a, 0.0) && $e === null);
[$a, $c, $e] = od_resolve('percent', '150', 2940.0);
t('১৫০% → ত্রুটি, টাকা null', $a === null && $e !== null);
[$a, $c, $e] = od_resolve('amount', 'x', 2940.0);
t('অক্ষর → ত্রুটি', $a === null && $e !== null);
[$a, $c, $e] = od_resolve('amount', '50', 0.0);
t('সাবটোটাল ০ হলে যেকোনো ডিসকাউন্ট → ০ (ছাঁটা)', same($a, 0.0) && $c === true);
[$a, $c, $e] = od_resolve('bogus', '10', 1000.0);
t('অজানা ধরন → টাকা (amount) হিসেবে ধরা', same($a, 10.0));

echo "\n=== মোট ===\n";
t('২৯৪০ − ২৯৪ + ১২০ = ২৭৬৬', same(od_net_total(2940.0, 294.0, 120.0), 2766.0));
t('ডিসকাউন্ট ০ → আগের মতো সাবটোটাল + ডেলিভারি', same(od_net_total(2940.0, 0.0, 120.0), 3060.0));
t('পুরো ডিসকাউন্ট → শুধু ডেলিভারি', same(od_net_total(2940.0, 2940.0, 120.0), 120.0));
t('মোট কখনো ঋণাত্মক নয়', od_net_total(100.0, 500.0, 0.0) === 0.0);
t('পয়সার হিসাব ঠিক (০.১ + ০.২ সমস্যা নেই)', same(od_net_total(0.3, 0.1, 0.2), 0.4));

echo "\n=== কারণ-লেখা ===\n";
t('আগে-পরে ও ভেতরের বাড়তি ফাঁকা কমে', od_note_clean("  নিয়মিত   ক্রেতা \n ") === 'নিয়মিত ক্রেতা');
t('১৬০ অক্ষরে কাটে (বাংলা অক্ষর ঠিক গোনে)', mb_strlen(od_note_clean(str_repeat('অ', 400)), 'UTF-8') === 160);
t('অ্যারে ইনপুটে ক্র্যাশ নয়, খালি', od_note_clean(['x']) === '');
t('খালি → খালি', od_note_clean('') === '');

echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
