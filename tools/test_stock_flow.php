<?php
/**
 * tools/test_stock_flow.php — অর্ডার স্ট্যাটাস ↔ স্টক ট্রানজিশন টেস্ট
 * সব পরিবর্তন একটাই ট্রানজ্যাকশনে হয় এবং শেষে rollback — DB অপরিবর্তিত থাকে।
 *
 *   php tools/test_stock_flow.php
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/stock_helpers.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;

function ok(string $m): void { global $pass; $pass++; echo "  PASS  $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  FAIL  $m\n"; }
function check(bool $cond, string $m): void { $cond ? ok($m) : bad($m); }

function stock_of(PDO $pdo, int $pid): int {
  $s = $pdo->prepare("SELECT stock FROM products WHERE id=?");
  $s->execute([$pid]);
  return (int)$s->fetchColumn();
}

echo "\n=== Stock Flow Test ===\n\n";

$pdo->beginTransaction();
try {
  ensure_stock_tracking($pdo);

  // টেস্ট প্রোডাক্ট: যেটাতে স্টক আছে
  $p = $pdo->query("SELECT id, name, stock FROM products WHERE active=1 AND stock >= 5 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  if (!$p) { throw new RuntimeException('স্টক>=৫ এমন প্রোডাক্ট নেই'); }

  $pid = (int)$p['id'];
  $startStock = stock_of($pdo, $pid);
  echo "  product #{$pid} ({$p['name']}) শুরুতে stock={$startStock}\n\n";

  // ---- অর্ডার তৈরি (place_order: স্টক কাটা + flag) ----
  $pdo->prepare("INSERT INTO orders (customer_name, mobile, address, shipping_area, shipping_fee, subtotal, total, payment_method, note, status)
                 VALUES ('__TEST__','01700000000','X','dhaka',0,100,100,'COD','','pending')")
      ->execute();
  $oid = (int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?,?,2,50)")
      ->execute([$oid, $pid]);
  $pdo->prepare("UPDATE products SET stock = stock - 2 WHERE id=?")->execute([$pid]);
  set_order_stock_flags($pdo, $oid, 1, 0);

  $s = stock_of($pdo, $pid);
  check($s === $startStock - 2, "অর্ডার তৈরি → স্টক ২ কমেছে ($startStock → $s)");

  $getOrder = function () use ($pdo, $oid): array {
    $st = $pdo->prepare("SELECT * FROM orders WHERE id=?");
    $st->execute([$oid]);
    return $st->fetch(PDO::FETCH_ASSOC);
  };

  // ---- pending → processing (স্টক অপরিবর্তিত) ----
  $o = $getOrder();
  $o = sync_order_stock_status($pdo, $o, 'processing');
  $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute(['processing', $oid]);
  check(stock_of($pdo, $pid) === $startStock - 2, 'processing → স্টক অপরিবর্তিত');

  // ---- processing → cancelled (স্টক ফেরত) ----
  $o = $getOrder();
  $o = sync_order_stock_status($pdo, $o, 'cancelled');
  $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute(['cancelled', $oid]);
  check(stock_of($pdo, $pid) === $startStock, "cancelled → স্টক ফেরত ($startStock)");

  // ---- আবার cancelled (double cancel → দ্বিতীয়বার ফেরত হবে না) ----
  $o = $getOrder();
  $o = sync_order_stock_status($pdo, $o, 'cancelled');
  $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute(['cancelled', $oid]);
  check(stock_of($pdo, $pid) === $startStock, 'double cancel → স্টক দ্বিগুণ বাড়েনি (idempotent)');

  // ---- cancelled → processing (আবার স্টক কাটা) ----
  $o = $getOrder();
  $o = sync_order_stock_status($pdo, $o, 'processing');
  $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute(['processing', $oid]);
  check(stock_of($pdo, $pid) === $startStock - 2, 'cancelled → processing → আবার স্টক কাটা');

  // ---- processing → delivered (স্টক অপরিবর্তিত, sold হিসাবে গণ্য) ----
  $o = $getOrder();
  $o = sync_order_stock_status($pdo, $o, 'delivered');
  $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute(['delivered', $oid]);
  check(stock_of($pdo, $pid) === $startStock - 2, 'delivered → স্টক অপরিবর্তিত');

  $sold = $pdo->prepare("SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id
                         WHERE oi.product_id=? AND o.status='delivered' AND o.id=?");
  $sold->execute([$pid, $oid]);
  check((int)$sold->fetchColumn() === 2, 'delivered অর্ডার sold হিসাবে গণনা হয় (2)');

  // ---- delivered → cancelled (স্টক ফেরত) ----
  $o = $getOrder();
  $o = sync_order_stock_status($pdo, $o, 'cancelled');
  $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute(['cancelled', $oid]);
  check(stock_of($pdo, $pid) === $startStock, 'delivered → cancelled → স্টক ফেরত');

  // ---- স্টক ০ হলে deduction ব্লক ----
  $z = $pdo->query("SELECT id FROM products WHERE active=1 AND stock=0 ORDER BY id LIMIT 1")->fetchColumn();
  if ($z) {
    $blocked = false;
    try { stock_deduct_item($pdo, (int)$z, 1); } catch (RuntimeException $e) { $blocked = true; }
    check($blocked, 'স্টক ০ প্রোডাক্টে deduction → RuntimeException (over-sell বন্ধ)');
  } else {
    echo "  SKIP  স্টক ০ প্রোডাক্ট নেই — over-sell টেস্ট বাদ\n";
  }

} catch (Throwable $e) {
  bad('EXCEPTION: ' . $e->getMessage());
} finally {
  if ($pdo->inTransaction()) $pdo->rollBack();
}

// rollback যাচাই
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE customer_name='__TEST__'")->fetchColumn();
check($leftover === 0, 'rollback → টেস্ট অর্ডার DB-তে নেই');

echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n\n";
exit($fail === 0 ? 0 : 1);
