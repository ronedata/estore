<?php
require_once __DIR__.'/includes/config.php';

/* ---------- CSRF ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
  http_response_code(403);
  $next = $_POST['next'] ?? 'cart.php';
  if (!is_string($next) || $next === '' || preg_match('~^(https?:)?//~i', $next)) $next = 'cart.php';
  header('Location: '.$next.'?csrf_error=1');
  exit;
}

// POST থেকে qty নিয়ে সেশন কার্ট আপডেট (স্টক সীমার ভিতরে)
if (isset($_POST['qty']) && is_array($_POST['qty'])) {
    $pdo = get_pdo();

    $posted = [];
    foreach ($_POST['qty'] as $pid => $qty) {
        $pid = (int)$pid;
        $q   = max(1, (int)$qty);
        if ($pid > 0) $posted[$pid] = $q;
    }

    $stockMap = [];
    if ($posted) {
        $ids = array_keys($posted);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stm = $pdo->prepare("SELECT id, stock FROM products WHERE id IN ($placeholders) AND active=1");
        $stm->execute($ids);
        foreach ($stm->fetchAll(PDO::FETCH_KEY_PAIR) as $pid => $stock) {
            $stockMap[(int)$pid] = max(0, (int)$stock);
        }
    }

    $new = [];
    $adjusted = [];
    foreach ($posted as $pid => $q) {
        $stock = $stockMap[$pid] ?? 0;
        if ($stock <= 0) { $adjusted[] = $pid; continue; } // স্টক আউট → কার্ট থেকে বাদ
        if ($q > $stock) { $q = $stock; $adjusted[] = $pid; }
        $new[$pid] = $q;
    }

    if ($new) {
        $_SESSION['cart'] = $new;
    } else {
        unset($_SESSION['cart']);
    }

    if ($adjusted) {
        $_SESSION['cart_notice'] = 'কিছু পণ্যের স্টক সীমিত/শেষ হওয়ায় পরিমাণ সমন্বয় করা হয়েছে।';
    }
}

// রিডাইরেক্ট টার্গেট (শুধু লোকাল পাথ অনুমোদন)
$next = $_POST['next'] ?? 'cart.php';
if (!is_string($next) || $next === '') {
    $next = 'cart.php';
} elseif (parse_url($next, PHP_URL_HOST) || parse_url($next, PHP_URL_SCHEME)) {
    $next = 'cart.php'; // এক্সটার্নাল ব্লক
}

header("Location: {$next}");
exit;
