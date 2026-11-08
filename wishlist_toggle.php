<?php
// wishlist_toggle.php — add/remove from wishlist (JSON)
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/customer_auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'message'=>'Method not allowed']);
  exit;
}

/* ---------- CSRF ---------- */
if (!csrf_verify()) {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'message'=>'নিরাপত্তা টোকেন অবৈধ। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।']);
  exit;
}

$customer = current_customer();
if (!$customer) {
  echo json_encode(['ok'=>false, 'login_required'=>true, 'message'=>'অনুগ্রহ করে লগইন করুন।']);
  exit;
}

$pid = (int)($_POST['product_id'] ?? 0);
if ($pid <= 0) {
  echo json_encode(['ok'=>false,'message'=>'অবৈধ প্রোডাক্ট']);
  exit;
}

$pdo = get_pdo();

try {
  $chk = $pdo->prepare("SELECT id FROM wishlists WHERE customer_id=? AND product_id=? LIMIT 1");
  $chk->execute([(int)$customer['id'], $pid]);
  $existingId = $chk->fetchColumn();

  if ($existingId) {
    $pdo->prepare("DELETE FROM wishlists WHERE id=?")->execute([(int)$existingId]);
    $inWishlist = false;
    $msg = 'উইশলিস্ট থেকে সরানো হয়েছে।';
  } else {
    $pdo->prepare("INSERT INTO wishlists(customer_id, product_id) VALUES(?,?)")
        ->execute([(int)$customer['id'], $pid]);
    $inWishlist = true;
    $msg = 'উইশলিস্টে যোগ হয়েছে।';
  }

  $cnt = $pdo->prepare("SELECT COUNT(*) FROM wishlists WHERE customer_id=?");
  $cnt->execute([(int)$customer['id']]);

  echo json_encode([
    'ok'          => true,
    'in_wishlist' => $inWishlist,
    'count'       => (int)$cnt->fetchColumn(),
    'message'     => $msg,
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'message'=>'সার্ভার ত্রুটি']);
}
