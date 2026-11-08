<?php
require_once __DIR__.'/includes/config.php';
header('Content-Type: application/json; charset=utf-8');

/* ---------- CSRF ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'নিরাপত্তা টোকেন অবৈধ। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।']);
  exit;
}

try {
  $pid = (int)($_POST['product_id'] ?? 0);
  $qty = max(1, (int)($_POST['qty'] ?? 1));

  if ($pid <= 0) { echo json_encode(['ok'=>false,'message'=>'Invalid product']); exit; }

  $pdo = get_pdo();
  $stm = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE id=? AND active=1");
  $stm->execute([$pid]);
  $p = $stm->fetch();

  if (!$p) { echo json_encode(['ok'=>false,'message'=>'Product unavailable']); exit; }

  $stock = max(0, (int)$p['stock']);
  if ($stock === 0) {
    echo json_encode(['ok'=>false,'message'=>'এই পণ্যটি স্টক আউট (Stock Out)।','stock_out'=>true]);
    exit;
  }

  if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) { $_SESSION['cart'] = []; }

  $current = (int)($_SESSION['cart'][$pid] ?? 0);
  $wanted  = $current + $qty;

  if ($wanted > $stock) {
    $_SESSION['cart'][$pid] = $stock;
    echo json_encode([
      'ok' => false,
      'message' => 'স্টকে মাত্র '.$stock.' টি আছে, তাই সর্বোচ্চ '.$stock.' টি রাখা হয়েছে।',
      'stock' => $stock,
      'qty'   => $stock,
      'count' => array_sum(array_map('intval', $_SESSION['cart'])),
    ]);
    exit;
  }

  $_SESSION['cart'][$pid] = $wanted;

  $count = 0; foreach ($_SESSION['cart'] as $q) { $count += (int)$q; }

  echo json_encode(['ok'=>true,'message'=>'কার্টে যোগ হয়েছে','count'=>$count,'qty'=>$wanted,'stock'=>$stock]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'message'=>'Server error']);
}
