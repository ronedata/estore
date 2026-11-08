<?php
// review_submit.php — customer submits a product review (moderated)
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/customer_auth.php';

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: '.BASE_URL.'/shop.php'); exit;
}

$pid  = (int)($_POST['product_id'] ?? 0);
$back = BASE_URL.'/product_details.php?id='.$pid;

if ($pid <= 0) { header('Location: '.BASE_URL.'/shop.php'); exit; }

if (!csrf_verify()) {
  $_SESSION['review_flash'] = 'err:সেশন মেয়াদোত্তীর্ণ। আবার চেষ্টা করুন।';
  header('Location: '.$back); exit;
}

$customer = current_customer();
if (!$customer) {
  header('Location: '.BASE_URL.'/account.php?next='.urlencode('/product_details.php?id='.$pid)); exit;
}

/* শুধু কিনেছেন এমন কাস্টমার রিভিউ দিতে পারবেন */
$chk = $pdo->prepare("SELECT COUNT(*) FROM orders o
                      JOIN order_items oi ON oi.order_id = o.id
                      WHERE o.customer_id = ? AND oi.product_id = ?
                        AND o.status IN ('delivered','shipped','processing')");
$chk->execute([(int)$customer['id'], $pid]);
if (!(int)$chk->fetchColumn()) {
  $_SESSION['review_flash'] = 'err:রিভিউ দিতে হলে আগে এই পণ্যটি কিনতে হবে।';
  header('Location: '.$back); exit;
}

/* একই পণ্যে একবারই */
$dup = $pdo->prepare("SELECT id FROM product_reviews WHERE product_id=? AND customer_id=? LIMIT 1");
$dup->execute([$pid, (int)$customer['id']]);
if ($dup->fetchColumn()) {
  $_SESSION['review_flash'] = 'err:আপনি ইতিমধ্যে এই পণ্যের রিভিউ দিয়েছেন।';
  header('Location: '.$back); exit;
}

$rating  = max(1, min(5, (int)($_POST['rating'] ?? 5)));
$name    = trim((string)($_POST['name'] ?? ''));
$comment = trim((string)($_POST['comment'] ?? ''));

if (mb_strlen($name) > 120 || $name === '') $name = $customer['name'] ?: $customer['mobile'];
if ($comment === '') {
  $_SESSION['review_flash'] = 'err:রিভিউ লিখুন।';
  header('Location: '.$back); exit;
}
$comment = mb_substr($comment, 0, 2000);

/* ছবি (ঐচ্ছিক) */
$imageUrl = null;
if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? 1) === UPLOAD_ERR_OK) {
  $f = $_FILES['image'];
  if ($f['size'] <= 2 * 1024 * 1024) {
    $info = @getimagesize($f['tmp_name']);
    $allowed = [IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png', IMAGETYPE_WEBP=>'webp'];
    if ($info && isset($allowed[$info[2]])) {
      $dir = __DIR__.'/uploads/reviews';
      if (!is_dir($dir)) @mkdir($dir, 0777, true);
      $fname = 'rv_'.date('YmdHis').'_'.bin2hex(random_bytes(3)).'.'.$allowed[$info[2]];
      if (move_uploaded_file($f['tmp_name'], $dir.'/'.$fname)) {
        $imageUrl = 'uploads/reviews/'.$fname;
      }
    }
  }
}

try {
  $ins = $pdo->prepare("INSERT INTO product_reviews(product_id, customer_id, name, rating, comment, image, status)
                        VALUES(?,?,?,?,?,?, 'pending')");
  $ins->execute([$pid, (int)$customer['id'], $name, $rating, $comment, $imageUrl]);
  $_SESSION['review_flash'] = 'ok:ধন্যবাদ! আপনার রিভিউ পর্যালোচনার পর প্রকাশিত হবে।';
} catch (Throwable $e) {
  $_SESSION['review_flash'] = 'err:রিভিউ সংরক্ষণ করা যায়নি।';
}

header('Location: '.$back);
