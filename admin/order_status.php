<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_orders');

$pdo = get_pdo();

$back = $_POST['return'] ?? '';
if ($back !== '' && preg_match('~^(https?:)?//~i', $back)) $back = '';
$backUrl = 'orders.php' . ($back !== '' ? '?' . $back : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: orders.php'); exit;
}

if (!csrf_verify()) {
  $_SESSION['flash'] = ['ok' => false, 'message' => 'CSRF token mismatch'];
  header('Location: ' . $backUrl); exit;
}

$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? 'pending';
$allowed = ['pending','processing','shipped','delivered','cancelled'];

if ($id > 0 && in_array($status, $allowed, true)) {
  try {
    $pdo->beginTransaction();

    ensure_stock_tracking($pdo);

    $stm = $pdo->prepare("SELECT * FROM orders WHERE id=? FOR UPDATE");
    $stm->execute([$id]);
    $order = $stm->fetch(PDO::FETCH_ASSOC);

    if ($order) {
      // ক্যান্সেল হলে স্টক ফেরত, cancelled থেকে ফিরলে আবার কাটা
      sync_order_stock_status($pdo, $order, $status);
      $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$status, $id]);
      $_SESSION['flash'] = ['ok' => true, 'message' => 'স্ট্যাটাস ও স্টক আপডেট হয়েছে।'];
    } else {
      $_SESSION['flash'] = ['ok' => false, 'message' => 'অর্ডার পাওয়া যায়নি।'];
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['ok' => false, 'message' => 'স্টক সমন্বয় ব্যর্থ: ' . $e->getMessage()];
  }
} else {
  $_SESSION['flash'] = ['ok' => false, 'message' => 'অবৈধ স্ট্যাটাস।'];
}

header('Location: ' . $backUrl);
exit;
