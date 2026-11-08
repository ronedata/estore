<?php
// admin/product_toggle.php
// লক্ষ্য: কোনো HTML আউটপুট নয়—শুধু টগল করে রিডাইরেক্ট
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../includes/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_products');

$pdo = get_pdo();

/* Guard: POST only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); exit('Method Not Allowed');
}

/* CSRF */
if (!csrf_verify()) {
  $_SESSION['flash'] = ['ok'=>false,'message'=>'Invalid CSRF token'];
  header('Location: stock_report.php'); exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  $_SESSION['flash'] = ['ok'=>false,'message'=>'Invalid product'];
  header('Location: stock_report.php'); exit;
}

try {
  $stm = $pdo->prepare("UPDATE products SET active = 1 - active WHERE id=?");
  $stm->execute([$id]);
  $_SESSION['flash'] = ['ok'=>true,'message'=>'স্ট্যাটাস আপডেট হয়েছে'];
} catch (Throwable $e) {
  $_SESSION['flash'] = ['ok'=>false,'message'=>'আপডেট ব্যর্থ হয়েছে'];
}

/* নিরাপদ রিডাইরেক্ট */
$redirect = $_POST['redirect'] ?? 'stock_report.php';
if (preg_match('~^(https?:)?//~i', $redirect)) { // বাহিরের URL হলে ব্লক
  $redirect = 'stock_report.php';
}
header('Location: ' . $redirect);
exit;
