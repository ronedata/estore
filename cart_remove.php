<?php
require_once __DIR__.'/includes/config.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
       && in_array(strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']), ['xmlhttprequest', 'fetch'], true);

/* ---------- CSRF ----------
   POST হলে টোকেন আবশ্যক। পুরোনো GET লিংক (?id=) এখনো কাজ করবে,
   কারণ GET শুধু নিজের সেশন কার্ট থেকে আইটেম বাদ দেয় (CSRF ঝুঁকি নেই)। */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
  http_response_code(403);
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'নিরাপত্তা টোকেন অবৈধ।']);
    exit;
  }
  header('Location: cart.php?csrf_error=1');
  exit;
}

/* POST অগ্রাধিকার; পুরোনো GET লিংকও কাজ করবে */
$id = (int)($_POST['product_id'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);

if ($id > 0 && !empty($_SESSION['cart'][$id])) {
  unset($_SESSION['cart'][$id]);
  if (empty($_SESSION['cart'])) unset($_SESSION['cart']);
}

/* রিডাইরেক্ট টার্গেট (শুধু লোকাল) */
$next = $_POST['next'] ?? 'cart.php';
if (!is_string($next) || $next === '' || preg_match('~^(https?:)?//~i', $next)) $next = 'cart.php';

if ($isAjax) {
  header('Content-Type: application/json; charset=utf-8');
  $count = 0;
  if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $q) { if ((int)$q > 0) $count++; }
  }
  echo json_encode(['ok' => true, 'count' => $count]);
  exit;
}

header('Location: '.$next);
exit;
