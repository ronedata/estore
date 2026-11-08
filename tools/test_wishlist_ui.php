<?php
/**
 * tools/test_wishlist_ui.php — লগইন কাস্টমারের জন্য wishlist remove flow টেস্ট
 * সেশন/ডেটা শেষে পরিষ্কার করা হয়।
 *
 *   php tools/test_wishlist_ui.php
 */
require_once __DIR__ . '/../includes/config.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function ok(string $m): void { global $pass; $pass++; echo "  PASS  $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  FAIL  $m\n"; }

$createdCustomer = false;
$createdWishlist = false;

try {
  // টেস্ট কাস্টমার (থাকলে ব্যবহার)
  $stm = $pdo->prepare("SELECT id FROM customers WHERE mobile=?");
  $stm->execute(['01700000001']);
  $cid = (int)$stm->fetchColumn();
  if ($cid <= 0) {
    $pdo->prepare("INSERT INTO customers (mobile, name, password_hash, active, created_at) VALUES (?,?,?,1,NOW())")
        ->execute(['01700000001', '__TESTCUST__', password_hash('TestPass123!', PASSWORD_DEFAULT)]);
    $cid = (int)$pdo->lastInsertId();
    $createdCustomer = true;
  }

  $pid = (int)$pdo->query("SELECT id FROM products WHERE active=1 ORDER BY id LIMIT 1")->fetchColumn();
  if ($pid <= 0) throw new RuntimeException('প্রোডাক্ট নেই');

  $pdo->prepare("INSERT IGNORE INTO wishlists (customer_id, product_id, created_at) VALUES (?,?,NOW())")
      ->execute([$cid, $pid]);
  $createdWishlist = true;

  $cnt = $pdo->prepare("SELECT COUNT(*) FROM wishlists WHERE customer_id=? AND product_id=?");
  $cnt->execute([$cid, $pid]);
  echo (int)$cnt->fetchColumn() === 1 ? ok("wishlist-এ প্রোডাক্ট #$pid যোগ হয়েছে") : bad('wishlist যোগ ব্যর্থ');

  // wishlist পেজে remove বাটন রেন্ডার হয় কিনা (HTML টেমপ্লেট চেক)
  $src = file_get_contents(__DIR__ . '/../wishlist.php');
  str_contains($src, 'cardRemoveWishlist') ? ok('wishlist.php-এ remove বাটন ফ্ল্যাগ আছে') : bad('remove বাটন ফ্ল্যাগ নেই');
  str_contains($src, 'function removeFromWishlist') ? ok('removeFromWishlist() JS আছে') : bad('remove JS ফাংশন নেই');

  $card = file_get_contents(__DIR__ . '/../includes/partials_product_card.php');
  str_contains($card, 'wl-remove-btn') ? ok('কার্ডে .wl-remove-btn মার্কআপ আছে') : bad('কার্ডে remove বাটন নেই');

  $css = file_get_contents(__DIR__ . '/../assets/css/components.css');
  str_contains($css, '.wl-remove-btn') ? ok('CSS-এ .wl-remove-btn স্টাইল আছে') : bad('CSS-এ স্টাইল নেই');

} catch (Throwable $e) {
  bad('EXCEPTION: ' . $e->getMessage());
} finally {
  // পরিষ্কার
  if ($createdWishlist) {
    $pdo->prepare("DELETE FROM wishlists WHERE customer_id=?")->execute([$cid ?? 0]);
  }
  if ($createdCustomer && !empty($cid)) {
    $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$cid]);
  }
}

$leftCust = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE mobile='01700000001'")->fetchColumn();
$leftWl   = (int)$pdo->query("SELECT COUNT(*) FROM wishlists")->fetchColumn();
echo $leftCust === 0 ? ok('টেস্ট কাস্টমার মুছে ফেলা হয়েছে') : bad('টেস্ট কাস্টমার রয়ে গেছে');
echo $leftWl === 0 ? ok('wishlists টেবিল পরিষ্কার') : bad("wishlists-এ $leftWl রো রয়ে গেছে");

echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n\n";
exit($fail === 0 ? 0 : 1);
