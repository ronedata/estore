<?php
// place_order.php — Free Shipping: কার্টের সব প্রোডাক্টে 'Free' ট্যাগ থাকলেই শিপিং ফি ০
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/delivery.php';
$pdo = get_pdo();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---------- CSRF ----------
   অর্ডার প্লেস করা সবচেয়ে সংবেদনশীল কাজ — টোকেন ছাড়া কখনোই হবে না। */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
  $_SESSION['flash_error'] = 'নিরাপত্তা টোকেন অবৈধ বা মেয়াদোত্তীর্ণ। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।';
  header('Location: checkout.php');
  exit;
}

/* ---- খালি কার্ট হলে ফেরত ---- */
$cart = $_SESSION['cart'] ?? [];
if (!$cart) { header('Location: cart.php'); exit; }

/* ---- ইনপুট ---- */
/* জেলা: district_id পাঠানো থাকলে অবশ্যই বৈধ হতে হবে। পুরোনো ক্যাশ-করা চেকআউট (district_id নেই) হলে আগের shipping_area থেকে ফলব্যাক */
$district = null;
$districtPosted = array_key_exists('district_id', $_POST);
if ($districtPosted) {
  $did = (is_string($_POST['district_id']) && ctype_digit($_POST['district_id'])) ? (int)$_POST['district_id'] : 0;
  $district = $did > 0 ? dl_get($pdo, $did) : null;
  if (!$district) {
    $_SESSION['flash_error'] = 'অনুগ্রহ করে ডেলিভারির জেলা বেছে নিন।';
    header('Location: checkout.php'); exit;
  }
} elseif (is_string($_POST['shipping_area'] ?? null) && $_POST['shipping_area'] === 'dhaka') {
  $st = $pdo->prepare("SELECT * FROM delivery_districts WHERE name_en='Dhaka' LIMIT 1");
  $st->execute();
  $district = $st->fetch() ?: null;
}
$name    = trim($_POST['customer_name'] ?? '');
$mobile  = trim($_POST['mobile'] ?? '');
$address = trim($_POST['address'] ?? '');
$note    = trim($_POST['note'] ?? '');
$payment_method = trim($_POST['payment_method'] ?? 'COD');

/* ===============================
   BD Mobile Normalize & Validate
   - Accepts: 01XXXXXXXXX | 8801XXXXXXXXX | +8801XXXXXXXXX
   - Returns: ['ok'=>true, 'local'=>'01…', 'e164'=>'+8801…'] অথবা ['ok'=>false,'reason'=>'…']
================================= */
function normalize_bd_mobile($raw){
  $s = preg_replace('/[^\d+]/', '', trim((string)$raw)); // শুধুই ডিজিট ও '+' রাখুন
  if (substr_count($s, '+') > 1) return ['ok'=>false, 'reason'=>'Invalid plus sign'];
  if (strpos($s, '+') === 0) $s = substr($s, 1); // শুরুর '+' বাদ

  // Local: 01XXXXXXXXX
  if (preg_match('/^01[3-9]\d{8}$/', $s)) {
    return ['ok'=>true, 'local'=>$s, 'e164'=>'+880' . substr($s,1)];
  }
  // 880-prefixed: 8801XXXXXXXXX
  if (preg_match('/^8801[3-9]\d{8}$/', $s)) {
    return ['ok'=>true, 'local'=>'0' . substr($s,3), 'e164'=>'+' . $s];
  }
  return ['ok'=>false, 'reason'=>'Invalid BD mobile'];
}

/* ---- ইনপুট ভ্যালিডেশন ---- */
$nv = normalize_bd_mobile($mobile);
if (!$name || !$address || !$nv['ok']) {
  $_SESSION['flash_error'] = !$nv['ok']
    ? 'মোবাইল নম্বর সঠিক নয়। অনুগ্রহ করে 01XXXXXXXXX বা +8801XXXXXXXXX দিন।'
    : 'প্রয়োজনীয় তথ্য পূরণ করুন।';
  header('Location: checkout.php'); exit;
}
$mobile_local = $nv['local'];
$mobile_e164  = $nv['e164']; // ভবিষ্যতে দরকার হলে DB-তে আলাদা কলামে রাখবেন

/* ---- Free tag detector ---- */
function has_free_tag($tags){
  // product.php-র সাথে কম্প্যাটিবল ডিটেকশন
  return (bool)preg_match('/\bfree(ship(ping)?)?\b/i', (string)$tags);
}

/* ---- কার্ট আইডি/রো ---- */
$ids = array_keys($cart);
$rows = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stm = $pdo->prepare("SELECT id,name,price,tags,stock FROM products WHERE id IN ($placeholders) AND active=1");
    $stm->execute($ids);
    $rows = $stm->fetchAll();
}
if (!$rows) { header('Location: cart.php'); exit; }

/* ---- স্টক আউট চেক (স্টক ০ হলে অর্ডার হবে না) ---- */
foreach ($rows as $r) {
    if ((int)($r['stock'] ?? 0) <= 0) {
        $_SESSION['flash_error'] = '“'.$r['name'].'” এখন স্টক আউট (Stock Out)। কার্ট থেকে সরিয়ে আবার চেষ্টা করুন।';
        header('Location: cart.php'); exit;
    }
}

/* ---- সাবটোটাল/আইটেমস ---- */
$items = [];
$subtotal = 0.0;
foreach ($rows as $r) {
  $pid = (int)$r['id'];
  $qty = max(1, (int)($cart[$pid] ?? 0));
  if ($qty < 1) continue;
  $line = $qty * (float)$r['price'];
  $subtotal += $line;
  $items[] = ['id'=>$pid, 'price'=>(float)$r['price'], 'qty'=>$qty, 'tags'=>($r['tags'] ?? '')];
}
if (!$items) { header('Location: cart.php'); exit; }

/* ---- FREE SHIPPING RULE (ALL items must be free) ----
   - একাধিক প্রোডাক্ট থাকলে: সবগুলোর tags-এ Free থাকলেই free shipping
   - যেকোনো একটিতেও না থাকলে: free shipping নয়
   - ক্লায়েন্টের POST free_shipping ফ্ল্যাগকে ট্রাস্ট না করে এখানে নিজে হিসাব করছি
------------------------------------------------------- */
$all_have_free = count($items) > 0;
foreach ($items as $it) {
  if (!has_free_tag($it['tags'])) { $all_have_free = false; break; }
}
$free_shipping = $all_have_free;

/* ---- shipping fee: জেলার চার্জ (০ = ফ্রি), ফাঁকা হলে গ্লোবাল; Free ট্যাগে ০ ---- */
$quote = dl_quote($pdo, $district ? (int)$district['id'] : null, $free_shipping);
$shipping_fee      = (float)$quote['fee'];
$shipping_area     = $quote['area'];
$shipping_district = $district ? (string)$district['name_bn'] : null;

$total = $subtotal + $shipping_fee;

/* ===============================
   12-hour Local DateTime (Asia/Dhaka)
   Example: 2025-Aug-27 09:05 PM
================================= */
$tzDhaka = new DateTimeZone('Asia/Dhaka');
$createdLocal = new DateTime('now', $tzDhaka);
$created_local_str = $createdLocal->format('Y-M-d h:i A'); // <-- 12h ফরম্যাট
// success.php-তে দেখানোর জন্য সেশনে রেখে দিচ্ছি (ইচ্ছা করলে ব্যবহার/মুছে দিন)
$_SESSION['last_order_time_local'] = $created_local_str;

/* ---- DB ট্রানজ্যাকশন ---- */

// স্টক ট্র্যাকিং কলাম নিশ্চিত করুন (DDL implicit commit করে, তাই ট্রানজ্যাকশনের আগে)
ensure_stock_tracking($pdo);

// পেমেন্ট মেথড ভ্যালিডেশন
$enabled_methods_stm = $pdo->query("SELECT code FROM payment_methods WHERE is_enabled = 1");
$enabled_methods = $enabled_methods_stm->fetchAll(PDO::FETCH_COLUMN);

if (!in_array($payment_method, $enabled_methods)) {
    $_SESSION['flash_error'] = 'অনুগ্রহ করে একটি বৈধ পেমেন্ট পদ্ধতি নির্বাচন করুন।';
    header('Location: checkout.php');
    exit;
}

try {
  $pdo->beginTransaction();

  /* ---- স্টক লক করে পুনরায় যাচাই (concurrent অর্ডারে over-sell রোধ) ---- */
  $itemIds = array_column($items, 'id');
  $ph = implode(',', array_fill(0, count($itemIds), '?'));
  $lockStm = $pdo->prepare("SELECT id, name, stock FROM products WHERE id IN ($ph) FOR UPDATE");
  $lockStm->execute($itemIds);
  $lockRows = [];
  foreach ($lockStm->fetchAll() as $lr) { $lockRows[(int)$lr['id']] = $lr; }

  foreach ($items as $it) {
    $pid = (int)$it['id'];
    $available = isset($lockRows[$pid]) ? (int)$lockRows[$pid]['stock'] : 0;
    if ($available <= 0) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = '“'.($lockRows[$pid]['name'] ?? 'পণ্য').'” এখন স্টক আউট (Stock Out)।';
      header('Location: cart.php'); exit;
    }
    if ($it['qty'] > $available) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = '“'.$lockRows[$pid]['name'].'” এর স্টক মাত্র '.$available.' টি। পরিমাণ কমিয়ে আবার চেষ্টা করুন।';
      header('Location: cart.php'); exit;
    }
  }

  // orders টেবিলে created_at (DATETIME / TIMESTAMP) কলামে ডিফল্ট CURRENT_TIMESTAMP থাকায় এখানে তা দেওয়ার প্রয়োজন নেই।
  // না থাকলে created_at অংশটি ড্রপ/কমেন্ট করুন।
  /* লগইন করা কাস্টমার হলে customer_id লিংক করে রাখি — dashboard-এ অর্ডার দেখাতে দরকার */
  $customer_id = null;
  $sessionCid = (int)($_SESSION['customer_id'] ?? 0);
  if ($sessionCid > 0) {
    try {
      $chk = $pdo->prepare("SELECT id FROM customers WHERE id=? AND active=1 LIMIT 1");
      $chk->execute([$sessionCid]);
      if ($chk->fetchColumn()) $customer_id = $sessionCid;
    } catch (Throwable $e) {}
  }

  $ins = $pdo->prepare("INSERT INTO orders
    (customer_name, mobile, address, shipping_area, shipping_district, shipping_fee, subtotal, total, payment_method, note, customer_id)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  // mobile হিসেবে লোকাল 01… ফরম্যাট সেভ করছি
  $ins->execute([$name, $mobile_local, $address, $shipping_area, $shipping_district, $shipping_fee, $subtotal, $total, $payment_method, $note, $customer_id]);

  $order_id = (int)$pdo->lastInsertId();
  $code = 'ORD' . str_pad((string)$order_id, 7, '0', STR_PAD_LEFT);
  $pdo->prepare("UPDATE orders SET order_code=? WHERE id=?")->execute([$code, $order_id]);

  $stm = $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?,?,?,?)");
  $dec = $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?");
  foreach ($items as $it) {
    $stm->execute([$order_id, $it['id'], $it['qty'], $it['price']]);
    $dec->execute([(int)$it['qty'], (int)$it['id']]);
  }

  // স্টক কাটা হয়েছে চিহ্নিত করুন (পরে ক্যান্সেলে ফেরত দেওয়ার জন্য)
  set_order_stock_flags($pdo, $order_id, 1, 0);

  $pdo->commit();

  // অর্ডার সম্পন্ন: কার্ট খালি করে success
  unset($_SESSION['cart']);
  header('Location: success.php?id='.(int)$order_id);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash_error'] = 'দুঃখিত, অর্ডার সম্পন্ন হয়নি। আবার চেষ্টা করুন।';
  header('Location: checkout.php'); exit;
}
