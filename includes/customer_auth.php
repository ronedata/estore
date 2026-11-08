<?php
// includes/customer_auth.php — customer session helpers
require_once __DIR__.'/config.php';

if (!function_exists('current_customer')) {
  function current_customer(): ?array {
    static $cache = null;
    static $loaded = false;
    if ($loaded) return $cache;
    $loaded = true;

    $cid = (int)($_SESSION['customer_id'] ?? 0);
    if ($cid <= 0) return $cache = null;

    try {
      $stm = get_pdo()->prepare("SELECT id, mobile, name, email, address, active FROM customers WHERE id=? LIMIT 1");
      $stm->execute([$cid]);
      $row = $stm->fetch();
      if (!$row || !(int)$row['active']) {
        unset($_SESSION['customer_id'], $_SESSION['customer_name']);
        return $cache = null;
      }
      return $cache = $row;
    } catch (Throwable $e) {
      return $cache = null;
    }
  }
}

if (!function_exists('require_customer')) {
  /** লগইন না থাকলে account.php-তে পাঠায় (AJAX হলে JSON) */
  function require_customer(): array {
    $c = current_customer();
    if ($c) return $c;

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false, 'login_required'=>true, 'message'=>'অনুগ্রহ করে লগইন করুন।']);
      exit;
    }
    header('Location: '.BASE_URL.'/account.php?next='.urlencode($_SERVER['REQUEST_URI'] ?? ''));
    exit;
  }
}

if (!function_exists('normalize_mobile')) {
  /** 01XXXXXXXXX ফরম্যাটে নিয়ে আসে */
  function normalize_mobile(string $m): ?string {
    $d = preg_replace('/\D+/', '', $m);
    if ($d === '') return null;
    if (strlen($d) === 13 && str_starts_with($d, '880')) $d = '0'.substr($d, 3);
    elseif (strlen($d) === 11 && str_starts_with($d, '1')) $d = '0'.$d;
    return preg_match('/^01[3-9]\d{8}$/', $d) ? $d : null;
  }
}
