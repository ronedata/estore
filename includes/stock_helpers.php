<?php
// includes/stock_helpers.php
// অর্ডার ↔ প্রোডাক্ট স্টক সামঞ্জস্য রাখার হেল্পার।
// orders টেবিলে দুটি ফ্ল্যাগ ব্যবহার করা হয়:
//   stock_deducted = 1  → অর্ডারের সময় স্টক কমানো হয়েছে
//   stock_returned = 1  → ক্যান্সেলের সময় স্টক ফেরত দেওয়া হয়েছে
// ফ্ল্যাগ কলাম না থাকলে কোড ডিফল্ট 0 ধরে নিরাপদে চলবে।

if (!function_exists('ensure_stock_tracking')) {
  function ensure_stock_tracking(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
      $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
      if (!in_array('stock_deducted', $cols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN stock_deducted TINYINT(1) NOT NULL DEFAULT 0");
      }
      if (!in_array('stock_returned', $cols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN stock_returned TINYINT(1) NOT NULL DEFAULT 0");
      }
    } catch (Throwable $e) {
      // কলাম না থাকলেও অ্যাপ চলবে; রিডগুলো ?? 0 দিয়ে করা হয়।
    }
  }
}

if (!function_exists('order_stock_is_held')) {
  /** এই অর্ডারের জন্য বর্তমানে স্টক আটকে রাখা আছে কি না */
  function order_stock_is_held(array $order): bool {
    return ((int)($order['stock_deducted'] ?? 0) === 1)
        && ((int)($order['stock_returned'] ?? 0) === 0);
  }
}

if (!function_exists('stock_deduct_item')) {
  /** স্টক কমানো (পর্যাপ্ত স্টক না থাকলে RuntimeException) */
  function stock_deduct_item(PDO $pdo, int $productId, int $qty): void {
    if ($productId <= 0 || $qty <= 0) return;

    $stm = $pdo->prepare("SELECT name, stock FROM products WHERE id=? FOR UPDATE");
    $stm->execute([$productId]);
    $p = $stm->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
      throw new RuntimeException('পণ্য পাওয়া যায়নি (#' . $productId . ')');
    }

    $available = (int)$p['stock'];
    if ($available < $qty) {
      throw new RuntimeException(
        '“' . $p['name'] . '” এর স্টক যথেষ্ট নয় (স্টকে ' . $available . ', দরকার ' . $qty . ')'
      );
    }

    $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id=?")->execute([$qty, $productId]);
  }
}

if (!function_exists('stock_restore_item')) {
  /** স্টক ফেরত যোগ করা */
  function stock_restore_item(PDO $pdo, int $productId, int $qty): void {
    if ($productId <= 0 || $qty <= 0) return;
    $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id=?")->execute([$qty, $productId]);
  }
}

if (!function_exists('stock_deduct_order')) {
  /** অর্ডারের সব আইটেমের স্টক কমানো */
  function stock_deduct_order(PDO $pdo, int $orderId): void {
    $stm = $pdo->prepare("SELECT product_id, qty FROM order_items WHERE order_id=?");
    $stm->execute([$orderId]);
    foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) {
      stock_deduct_item($pdo, (int)$r['product_id'], (int)$r['qty']);
    }
  }
}

if (!function_exists('stock_restore_order')) {
  /** অর্ডারের সব আইটেমের স্টক ফেরত যোগ */
  function stock_restore_order(PDO $pdo, int $orderId): void {
    $stm = $pdo->prepare("SELECT product_id, qty FROM order_items WHERE order_id=?");
    $stm->execute([$orderId]);
    foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $r) {
      stock_restore_item($pdo, (int)$r['product_id'], (int)$r['qty']);
    }
  }
}

if (!function_exists('set_order_stock_flags')) {
  /** orders টেবিলের স্টক ফ্ল্যাগ আপডেট (null = অপরিবর্তিত) */
  function set_order_stock_flags(PDO $pdo, int $orderId, ?int $deducted = null, ?int $returned = null): void {
    $sets = [];
    $params = [];
    if ($deducted !== null) { $sets[] = 'stock_deducted=?'; $params[] = $deducted ? 1 : 0; }
    if ($returned !== null) { $sets[] = 'stock_returned=?'; $params[] = $returned ? 1 : 0; }
    if (!$sets) return;

    $params[] = $orderId;
    try {
      $pdo->prepare("UPDATE orders SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
    } catch (Throwable $e) {
      // কলাম না থাকলে নীরবে বাদ
    }
  }
}

if (!function_exists('sync_order_stock_status')) {
  /**
   * স্ট্যাটাস বদলালে স্টক সমন্বয় করে।
   * - cancelled-এ গেলে (স্টক আটকে থাকলে) সব আইটেম ফেরত
   * - cancelled থেকে ফিরলে আবার স্টক কেটে নেয়
   * @param array $order  পুরনো অর্ডার রো (অন্তত id, status, stock_deducted, stock_returned)
   * @return array আপডেটেড (ইন-মেমরি) অর্ডার রো
   */
  function sync_order_stock_status(PDO $pdo, array $order, string $newStatus): array {
    $oldStatus = strtolower(trim((string)($order['status'] ?? '')));
    $newStatus = strtolower(trim($newStatus));
    $orderId   = (int)($order['id'] ?? 0);
    if ($orderId <= 0 || $oldStatus === $newStatus) return $order;

    $held = order_stock_is_held($order);

    // ক্যান্সেল → স্টক ফেরত
    if ($newStatus === 'cancelled') {
      if ($held) {
        stock_restore_order($pdo, $orderId);
        set_order_stock_flags($pdo, $orderId, null, 1);
      }
      $order['stock_returned'] = 1;
      $order['status'] = $newStatus;
      return $order;
    }

    // ক্যান্সেল থেকে ফিরিয়ে আনা → আবার স্টক কাটা
    if ($oldStatus === 'cancelled' && $newStatus !== 'cancelled') {
      $wasHeld = ((int)($order['stock_deducted'] ?? 0) === 1);
      if ($wasHeld) {
        stock_deduct_order($pdo, $orderId);
        set_order_stock_flags($pdo, $orderId, 1, 0);
        $order['stock_returned'] = 0;
      }
      $order['status'] = $newStatus;
      return $order;
    }

    $order['status'] = $newStatus;
    return $order;
  }
}
