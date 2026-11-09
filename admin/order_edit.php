<?php
// admin/order_edit.php — Edit order with qty stepper + live totals (mobile & PC friendly)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/delivery.php';
require_once __DIR__.'/../includes/order_discount.php';
require_once __DIR__ . '/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_orders');

$pdo = get_pdo();

/* ---------- Helpers (fallback) ---------- */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money_bd')) {
  function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); }
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ---------- Allowed statuses & shipping areas ---------- */
$STATUSES = ['pending','processing','shipped','delivered','cancelled'];
$AREAS    = ['dhaka' => 'Dhaka', 'nationwide' => 'Nationwide'];

/* ---------- Load order ---------- */
$id       = (int)($_GET['id'] ?? 0);
$returnQS = trim($_GET['return'] ?? '');

$stm = $pdo->prepare("SELECT * FROM orders WHERE id=?");
$stm->execute([$id]);
$o = $stm->fetch();
if (!$o) {
  $_SESSION['flash'] = ['ok'=>false,'message'=>'Order not found'];
  header('Location: orders.php'); exit;
}

/* ---------- Load items ---------- */
$it = $pdo->prepare("
  SELECT oi.id, oi.product_id, oi.qty, oi.price, p.name
  FROM order_items oi
  LEFT JOIN products p ON p.id=oi.product_id
  WHERE oi.order_id=?
  ORDER BY oi.id
");
$it->execute([$id]);
$items = $it->fetchAll();

/* ---------- Handle Save (POST) BEFORE HTML ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save']) || isset($_POST['update_price']))) {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false,'message'=>'CSRF যাচাইকরণ ব্যর্থ হয়েছে'];
    header('Location: orders.php'.($returnQS ? '?'.$returnQS : '')); exit;
  }

  $customer_name = trim($_POST['customer_name'] ?? '');
  $mobile        = trim($_POST['mobile'] ?? '');
  $address       = trim($_POST['address'] ?? '');
  $note          = trim($_POST['note'] ?? '');
  /* জেলা বাছা হলে (বৈধ id) জেলা ও এলাকা বদলায়; ফাঁকা রাখলে (পুরোনো অর্ডার) আগেরটাই থাকে */
  $shipping_area     = (string)($o['shipping_area'] ?? 'dhaka');
  $shipping_district = $o['shipping_district'] ?? null;
  $postedDid = (isset($_POST['district_id']) && is_string($_POST['district_id']) && ctype_digit($_POST['district_id'])) ? (int)$_POST['district_id'] : 0;
  if ($postedDid > 0) {
    $dRow = dl_get($pdo, $postedDid);
    if ($dRow) { $shipping_district = (string)$dRow['name_bn']; $shipping_area = dl_area_of($dRow); }
  }
  $shipping_fee  = (float)($_POST['shipping_fee'] ?? 0);
  $status        = $_POST['status'] ?? $o['status'];

  // Basic validations
  $err = '';
  if ($customer_name === '') $err = 'গ্রাহকের নাম প্রয়োজন';
  elseif ($mobile === '')    $err = 'মোবাইল নম্বর প্রয়োজন';
  elseif (!preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $mobile)) $err = 'সঠিক বাংলাদেশি মোবাইল নম্বর দিন';
  elseif (!in_array($status, $STATUSES, true)) $err = 'অবৈধ স্ট্যাটাস';

  /* ডিসকাউন্ট: ফরম্যাট এখানে, চূড়ান্ত টাকা সাবটোটাল জানার পর (নিচে) */
  $discType = (isset($_POST['discount_type']) && $_POST['discount_type'] === 'percent') ? 'percent' : 'amount';
  $discVal  = $_POST['discount_value'] ?? '';
  $discNote = od_note_clean($_POST['discount_note'] ?? '');
  if ($err === '' && ($de = od_validate_input($discType, $discVal)) !== null) $err = $de;

  // Qty array
  $qtyPosted    = $_POST['qty'] ?? [];      // key = order_item_id, value = qty
  $newQtyPosted = $_POST['new_qty'] ?? [];  // key = product_id, value = qty

  // আইটেম সংখ্যা ভ্যালিডেশন
  $existingItemIds   = array_keys($qtyPosted);
  $removedItemIds    = $_POST['remove_items'] ?? [];
  $remainingItemIds  = array_diff($existingItemIds, $removedItemIds);
  $totalItems        = count($remainingItemIds) + count($newQtyPosted);

  if ($totalItems < 1) $err = 'অর্ডার সেভ করার জন্য অন্তত একটি আইটেম থাকতে হবে।';

  if ($err !== '') {
    $_SESSION['flash'] = ['ok'=>false,'message'=>$err];
    header('Location: order_edit.php?id='.$id.'&return='.urlencode($returnQS)); exit;
  }

  try{
    $pdo->beginTransaction();

    ensure_stock_tracking($pdo);

    /* ---------- অর্ডারের বর্তমান অবস্থা লক করে নিন (স্টক ফ্ল্যাগসহ) ---------- */
    $lk = $pdo->prepare("SELECT * FROM orders WHERE id=? FOR UPDATE");
    $lk->execute([$id]);
    $orderRow = $lk->fetch(PDO::FETCH_ASSOC) ?: $o;

    // স্টক আটকে আছে কি না (ক্যান্সেল/রিটার্ন হলে নতুন করে কাটব না)
    $stockHeld = order_stock_is_held($orderRow);

    /* ---------- পুরনো আইটেমের স্ন্যাপশট ---------- */
    $oldItems = [];
    $oldStm = $pdo->prepare("SELECT id, product_id, qty FROM order_items WHERE order_id=?");
    $oldStm->execute([$id]);
    foreach ($oldStm->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $oldItems[(int)$row['id']] = ['product_id' => (int)$row['product_id'], 'qty' => (int)$row['qty']];
    }

    // শুধুমাত্র 'save' অ্যাকশনে গ্রাহকের তথ্য ও আইটেমের পরিমাণ আপডেট হবে
    if (isset($_POST['save'])) {
      // 1) Update basic order fields (except totals for now)
      $upd1 = $pdo->prepare("
        UPDATE orders
        SET customer_name=?, mobile=?, address=?, note=?, shipping_area=?, shipping_district=?, shipping_fee=?, status=?
        WHERE id=?
      ");
      $upd1->execute([$customer_name, $mobile, $address, $note, $shipping_area, $shipping_district, $shipping_fee, $status, $id]);

      // 2) Update each item's qty (min 1) + স্টক ডেল্টা প্রয়োগ
      if (is_array($qtyPosted)) {
        $u = $pdo->prepare("UPDATE order_items SET qty=? WHERE id=? AND order_id=?");
        foreach ($qtyPosted as $oiId => $q) {
          $oiId = (int)$oiId;
          $q    = max(1, (int)$q);

          if (!isset($oldItems[$oiId])) continue; // অন্য অর্ডারের আইটেম
          $oldQty = (int)$oldItems[$oiId]['qty'];
          $pid    = (int)$oldItems[$oiId]['product_id'];

          if ($stockHeld && $q > $oldQty) {
            stock_deduct_item($pdo, $pid, $q - $oldQty);      // বাড়লে অতিরিক্ত কাটা
          } elseif ($stockHeld && $q < $oldQty) {
            stock_restore_item($pdo, $pid, $oldQty - $q);     // কমলে ফেরত
          }

          $u->execute([$q, $oiId, $id]);
          $oldItems[$oiId]['qty'] = $q; // পরবর্তী ধাপের জন্য আপডেট
        }
      }
    }

    // 2.1) Remove items (উভয় অ্যাকশনে প্রযোজ্য) — স্টক ফেরত
    if (!empty($_POST['remove_items']) && is_array($_POST['remove_items'])) {
      $remIds = array_values(array_filter(array_map('intval', $_POST['remove_items'])));
      if ($remIds) {
        $placeholders = implode(',', array_fill(0, count($remIds), '?'));
        $rem = $pdo->prepare("DELETE FROM order_items WHERE id IN ($placeholders) AND order_id=?");
        $rem->execute(array_merge($remIds, [$id]));

        if ($stockHeld) {
          foreach ($remIds as $rid) {
            if (!isset($oldItems[$rid])) continue;
            stock_restore_item($pdo, (int)$oldItems[$rid]['product_id'], (int)$oldItems[$rid]['qty']);
          }
        }
      }
    }

    // 2.2) Add new items (উভয় অ্যাকশনে প্রযোজ্য) — স্টক কাটা
    if (is_array($newQtyPosted) && !empty($newQtyPosted)) {
      $pids = array_map('intval', array_keys($newQtyPosted));
      $placeholders = implode(',', array_fill(0, count($pids), '?'));
      $prods = $pdo->prepare("SELECT id, price FROM products WHERE id IN ($placeholders)");
      $prods->execute($pids);
      $newProducts = $prods->fetchAll(PDO::FETCH_KEY_PAIR);

      $ins = $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?,?,?,?)");
      foreach ($newProducts as $pid => $price) {
        $q = max(1, (int)$newQtyPosted[$pid]);
        if ($stockHeld) {
          stock_deduct_item($pdo, (int)$pid, $q);
        }
        $ins->execute([$id, $pid, $q, $price]);
      }
    }

    // 2.3) স্ট্যাটাস বদলালে স্টক সমন্বয় (cancel ↔ পুনরায় সক্রিয়)
    if (isset($_POST['save'])) {
      $oldStatus = strtolower((string)($orderRow['status'] ?? ''));

      if ($status === 'cancelled') {
        // বাতিল → বর্তমান (নতুন) আইটেমগুলোর স্টক ফেরত
        if ($stockHeld) {
          stock_restore_order($pdo, $id);
          set_order_stock_flags($pdo, $id, null, 1);
        }
      } elseif ($stockHeld) {
        // সক্রিয়ই আছে → স্টক আটকে আছে, ফ্ল্যাগ নিশ্চিত করুন
        set_order_stock_flags($pdo, $id, 1, 0);
      } elseif ($oldStatus === 'cancelled' && (int)($orderRow['stock_deducted'] ?? 0) === 1) {
        // বাতিল থেকে ফিরিয়ে আনা → আবার স্টক কাটা
        stock_deduct_order($pdo, $id);
        set_order_stock_flags($pdo, $id, 1, 0);
      }
      // legacy অর্ডার (stock_deducted=0): কিছুই করি না, যেন ভুল করে স্টক কম/বেশি না হয়
    }

    // 3) Recalculate subtotal from DB (safe)
    $sum = $pdo->prepare("SELECT SUM(qty * price) FROM order_items WHERE order_id=?");
    $sum->execute([$id]);
    $subtotal = (float)$sum->fetchColumn();

    /* ডিসকাউন্ট: সার্ভারে সাবটোটালের ওপর হিসাব; সাবটোটালের বেশি হলে ছাঁটা হয় (JS-এর মান বিশ্বাস করা হয় না) */
    [$discAmt, $discClamped, ] = od_resolve($discType, $discVal, $subtotal);
    $discAmt = (float)($discAmt ?? 0);
    $oldDisc = (float)($orderRow['discount'] ?? 0);
    if ($discAmt > 0) {
      $discBy = ($orderRow['discount_by'] ?? null);
      if (abs($discAmt - $oldDisc) > 0.004 || empty($discBy)) {
        $discBy = mb_substr((string)($_SESSION['admin_username'] ?? 'admin'), 0, 60, 'UTF-8');
      }
      $discNoteSave = ($discNote !== '') ? $discNote : null;
    } else {
      $discBy = null;
      $discNoteSave = null;
    }
    $total = od_net_total($subtotal, $discAmt, $shipping_fee);

    // 4) Save totals (শিপিং ফি ও ডিসকাউন্টসহ — "Update Price"-এও একই)
    $upd2 = $pdo->prepare("UPDATE orders SET shipping_fee=?, subtotal=?, discount=?, discount_note=?, discount_by=?, total=? WHERE id=?");
    $upd2->execute([$shipping_fee, $subtotal, $discAmt, $discNoteSave, $discBy, $total, $id]);

    $pdo->commit();

    if (isset($_POST['update_price'])) {
        $_SESSION['flash'] = ['ok'=>true,'message'=>'মূল্য সফলভাবে আপডেট হয়েছে।'.(!empty($discClamped) ? ' (ডিসকাউন্ট সাবটোটালের সমান করা হয়েছে।)' : '')];
        header('Location: order_edit.php?id='.$id.'&return='.urlencode($returnQS)); exit;
    } else {
        $_SESSION['flash'] = ['ok'=>true,'message'=>'অর্ডার সফলভাবে আপডেট হয়েছে।'.(!empty($discClamped) ? ' (ডিসকাউন্ট সাবটোটালের সমান করা হয়েছে।)' : '')];
        header('Location: orders.php'.($returnQS ? '?'.$returnQS : '')); exit;
    }
  } catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['ok'=>false,'message'=>'সার্ভার ত্রুটি: আপডেট ব্যর্থ।'];
    header('Location: order_edit.php?id='.$id.'&return='.urlencode($returnQS)); exit;
  }
}

/* ---------- Render page ---------- */
require_once __DIR__ . '/header.php';
?>
<style>
  .o-card{ margin-bottom: 16px; }
  .qtyBox{
    display:inline-grid; grid-template-columns:38px 60px 38px; align-items:center; width:max-content; max-width:100%;
    border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#fff; vertical-align:middle;
    transition:border-color .15s, box-shadow .15s;
  }
  .qtyBox:focus-within{ border-color:#86b7fe; box-shadow:0 0 0 3px rgba(13,110,253,.15); }
  .qtyBox input{ height:38px; text-align:center; border:none; border-inline:1px solid #e5e7eb; width:100%; font-weight:600; font-variant-numeric:tabular-nums; outline:none; background:#fff; -moz-appearance:textfield; appearance:textfield; }
  .qtyBox input::-webkit-outer-spin-button, .qtyBox input::-webkit-inner-spin-button{ -webkit-appearance:none; margin:0; }
  .qtyBox button{ height:38px; border:none; background:#f8fafc; font-size:18px; cursor:pointer; color:#334155; }
  .qtyBox button:hover{ background:#eef2f7; }
  .qtyBox button:active{ background:#e2e8f0; }
  #itemsTable .priceCell, #itemsTable .lineCell, .summary-kv .v{ font-variant-numeric:tabular-nums; }
  .summary-kv .k{ color:#64748b; }
  .summary-kv .v{ font-weight:700; text-align:right; }

  /* Product search dropdown */
  .search-wrap { position: relative; }
  .search-results {
    position: absolute; top: 100%; left: 0; right: 0;
    background: #fff; border: 1px solid #dee2e6; border-radius: 0 0 10px 10px;
    z-index: 10; max-height: 250px; overflow-y: auto;
    box-shadow: 0 8px 16px rgba(0,0,0,.1);
  }
  .search-results .list-group-item {
    cursor: pointer;
    border-radius: 0;
    border-left: 0; border-right: 0; border-top: 0;
  }
  .search-results .list-group-item:hover { background: #f8f9fa; }
  .search-results .list-group-item:last-child { border-bottom: 0; border-radius: 0 0 8px 8px; }

  /* Responsive table */
  @media (max-width:576px){
    .table-responsive{ border:0 }
    table.table{ display:block }
    table.table thead{ display:none }
    table.table tbody{ display:block }
    table.table tr{
      display:block; background:#fff; border:1px solid #e9ecef; border-radius:12px;
      padding:12px; margin-bottom:10px; box-shadow:0 6px 16px rgba(2,8,23,.04);
    }
    table.table td{
      display:flex; justify-content:space-between; gap:12px;
      padding:6px 0 !important; border:0 !important;
    }
    table.table td::before{
      content:attr(data-label);
      font-weight:600; color:#64748b; min-width:120px; text-align:left;
    }
  }

  /* Buttons initial state */
  #updatePriceButton { display: none; } /* Dirty হলে JS দেখাবে */
</style>

<?php if(!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert alert-<?php echo $f['ok']?'success':'danger'; ?> alert-dismissible fade show" role="alert">
    <?php echo h($f['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0">Edit Order — #<?php echo h($o['order_code']); ?></h5>
  <div class="d-flex gap-2">
    <a class="btn btn-secondary"
       href="orders.php<?php echo $returnQS ? ('?'.h($returnQS)) : ''; ?>">Back to Orders</a>
    <a class="btn btn-outline-primary" target="_blank"
       href="order_print.php?id=<?php echo (int)$o['id']; ?>">Print</a>
  </div>
</div>

<form method="post" id="editForm" class="row">
  <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
  <input type="hidden" name="return" value="<?php echo h($returnQS); ?>">

  <!-- Left: Customer -->
  <div class="col-lg-6 o-card">
    <div class="card"><div class="card-body">
      <h6 class="mb-3">গ্রাহকের তথ্য</h6>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">নাম</label>
          <input name="customer_name" class="form-control" required value="<?php echo h($o['customer_name']); ?>">
        </div>
        <div class="col-12">
          <label class="form-label">মোবাইল</label>
          <input name="mobile" class="form-control" required
                 pattern="^(?:\+?88)?01[3-9]\d{8}$"
                 title="বাংলাদেশি মোবাইল: 01XXXXXXXXX বা +8801XXXXXXXXX"
                 value="<?php echo h($o['mobile']); ?>">
        </div>
        <div class="col-12">
          <label class="form-label">ঠিকানা</label>
          <textarea name="address" class="form-control" rows="3" required><?php echo h($o['address']); ?></textarea>
        </div>

        <!-- ✅ ঠিকানা’র নিচে নোট -->
        <div class="col-12">
          <label class="form-label">নোট</label>
          <textarea name="note" class="form-control" rows="2" placeholder="অর্ডার সংক্রান্ত নোট..."><?php echo h($o['note']); ?></textarea>
        </div>
      </div>
    </div></div>
  </div>

  <!-- Right: Shipping/Status & Summary -->
  <div class="col-lg-6 o-card">
    <div class="card"><div class="card-body">
      <h6 class="mb-3">শিপিং ও স্ট্যাটাস</h6>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">ডেলিভারির জেলা</label>
          <?php
            $dlAll = dl_all($pdo); $dlGlobal = dl_global($pdo); $dlCur = null;
            foreach ($dlAll as $dd) { if ((string)$dd['name_bn'] === (string)($o['shipping_district'] ?? '')) { $dlCur = (int)$dd['id']; break; } }
          ?>
          <select name="district_id" id="districtSel" class="form-select">
            <option value=""><?php echo ($o['shipping_district'] ?? '') !== '' ? h($o['shipping_district']).' (অপরিবর্তিত)' : 'জেলা নির্ধারিত নেই ('.h(dl_order_area_label($o)).')'; ?></option>
            <?php $dlDiv = null; foreach ($dlAll as $dd):
              if ($dd['division'] !== $dlDiv) { if ($dlDiv !== null) echo '</optgroup>'; echo '<optgroup label="'.h($dd['division']).' বিভাগ">'; $dlDiv = $dd['division']; } ?>
              <option value="<?php echo (int)$dd['id']; ?>" data-fee="<?php echo (float)dl_effective_fee($dd, $dlGlobal); ?>"
                      data-en="<?php echo h($dd['name_en']); ?>" data-alt="<?php echo h(dl_aliases((string)$dd['name_en'])); ?>" <?php echo $dlCur === (int)$dd['id'] ? 'selected' : ''; ?>>
                <?php echo h($dd['name_bn']); ?>
              </option>
            <?php endforeach; if ($dlDiv !== null) echo '</optgroup>'; ?>
          </select>
          <div class="form-text">জেলা বদলালে শিপিং ফি নিজে বসে যায় (হাতে বদলানো যাবে)।</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">শিপিং ফি</label>
          <input type="number" step="0.01" name="shipping_fee" id="shippingFee" class="form-control"
                 value="<?php echo h($o['shipping_fee']); ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">স্ট্যাটাস</label>
          <select name="status" class="form-select" required>
            <?php foreach($STATUSES as $s): ?>
              <option value="<?php echo h($s); ?>" <?php echo $o['status']===$s?'selected':''; ?>>
                <?php echo ucfirst($s); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">পেমেন্ট</label>
          <input class="form-control" value="<?php echo h(payment_method_label($o['payment_method'] ?? '')); ?>" readonly>
        </div>
        <?php $curDisc = (float)($o['discount'] ?? 0); $curDiscTxt = $curDisc > 0 ? rtrim(rtrim(number_format($curDisc, 2, '.', ''), '0'), '.') : ''; ?>
        <div class="col-md-6">
          <label class="form-label" for="discVal">ডিসকাউন্ট</label>
          <div class="input-group">
            <select name="discount_type" id="discType" class="form-select" style="max-width:132px" aria-label="ডিসকাউন্টের ধরন">
              <option value="amount">৳ টাকা</option>
              <option value="percent">% শতাংশ</option>
            </select>
            <input type="number" name="discount_value" id="discVal" class="form-control" min="0" step="0.01" inputmode="decimal"
                   placeholder="0" value="<?php echo h($curDiscTxt); ?>">
          </div>
          <div class="form-text" id="discHint">সাবটোটাল থেকে কাটা হবে। ৳ (টাকা) বা % (শতাংশ)।</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="discNote">ডিসকাউন্টের কারণ <span class="text-muted fw-normal">(ঐচ্ছিক)</span></label>
          <input type="text" name="discount_note" id="discNote" class="form-control" maxlength="160"
                 placeholder="যেমন: নিয়মিত ক্রেতা" value="<?php echo h($o['discount_note'] ?? ''); ?>">
          <?php if ($curDisc > 0 && !empty($o['discount_by'])): ?>
            <div class="form-text">দিয়েছেন: <?php echo h($o['discount_by']); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <hr class="my-3">

      <h6 class="mb-2">অর্ডার সারসংক্ষেপ</h6>
      <div class="row summary-kv small gy-1">
        <div class="col-6 k">Subtotal</div><div class="col-6 v" id="sumSubtotal"><?php echo money_bd($o['subtotal']); ?></div>
        <div class="col-6 k">Shipping</div><div class="col-6 v" id="sumShipping"><?php echo money_bd($o['shipping_fee']); ?></div>
        <div class="col-6 k" id="sumDiscLabel" <?php echo ((float)($o['discount'] ?? 0)) > 0 ? '' : 'hidden'; ?>>Discount</div>
        <div class="col-6 v text-success" id="sumDiscount" <?php echo ((float)($o['discount'] ?? 0)) > 0 ? '' : 'hidden'; ?>>&minus;<?php echo money_bd($o['discount'] ?? 0); ?></div>
        <div class="col-12"><hr class="my-2"></div>
        <div class="col-6 k">Total</div><div class="col-6 v" id="sumTotal" style="font-weight:800"><?php echo money_bd($o['total']); ?></div>
      </div>
    </div></div>
  </div>

  <!-- Items -->
  <div class="col-12">
    <div class="card"><div class="card-body">
      <h6 class="mb-3">আইটেমসমূহ</h6>
      <div class="table-responsive">
        <table class="table table-striped align-middle mb-0" id="itemsTable">
          <thead class="table-light">
            <tr>
              <th>পণ্য</th>
              <th class="text-end">দাম</th>
              <th class="text-center">পরিমাণ</th>
              <th class="text-center">অ্যাকশন</th>
              <th class="text-end">লাইন</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($items as $r): ?>
              <tr data-id="<?php echo (int)$r['id']; ?>" data-product-id="<?php echo (int)$r['product_id']; ?>">
                <td data-label="পণ্য"><?php echo h($r['name']); ?></td>
                <td data-label="দাম" class="text-end priceCell" data-price="<?php echo (float)$r['price']; ?>">
                  <?php echo money_bd($r['price']); ?>
                </td>
                <td data-label="পরিমাণ" class="text-center">
                  <div class="qtyBox">
                    <button type="button" class="btnDec" aria-label="কমান">−</button>
                    <input type="number" name="qty[<?php echo (int)$r['id']; ?>]"
                           class="qtyInput" value="<?php echo (int)$r['qty']; ?>" min="1" inputmode="numeric">
                    <button type="button" class="btnInc" aria-label="বাড়ান">+</button>
                  </div>
                </td>
                <td data-label="অ্যাকশন" class="text-center">
                  <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item"
                          data-item-id="<?php echo (int)$r['id']; ?>">Remove</button>
                </td>
                <td data-label="লাইন" class="text-end lineCell">
                  <?php echo money_bd($r['qty'] * $r['price']); ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$items): ?>
              <tr><td colspan="5" class="text-center text-muted">কোন আইটেম নেই</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Add Product Section -->
      <div class="mt-4 pt-3 border-top">
        <h6 class="mb-2">নতুন পণ্য যোগ করুন</h6>
        <div class="row">
          <div class="col-md-8 col-lg-6">
            <div class="search-wrap">
              <input type="text" id="productSearch" class="form-control" placeholder="পণ্য খুঁজুন...">
              <div class="search-results" id="searchResults" style="display:none;"></div>
            </div>
          </div>
        </div>
      </div>
      <!-- End Add Product Section -->

      <div class="mt-3 d-flex gap-2">
        <button type="submit" name="update_price" value="1" class="btn btn-info" id="updatePriceButton">Update Price</button>
        <button type="submit" name="save" value="1" class="btn btn-primary" id="saveButton">Save</button>
        <a class="btn btn-outline-secondary" href="orders.php<?php echo $returnQS ? ('?'.h($returnQS)) : ''; ?>">Cancel</a>
      </div>
    </div></div>
  </div>
</form>

<script>
// ---- Helpers ----
function bdMoney(n){
  n = Math.round(Number(n)||0);
  return '৳' + n.toLocaleString('en-US');
}

// ---- Order editor (global module) ----
window.orderEditor = (function(){
  const tbl   = document.getElementById('itemsTable');
  const feeEl = document.getElementById('shippingFee');
  const sumSub = document.getElementById('sumSubtotal');
  const sumShip= document.getElementById('sumShipping');
  const sumTot = document.getElementById('sumTotal');
  const saveBtn = document.getElementById('saveButton');
  const updatePriceBtn = document.getElementById('updatePriceButton');

  let isPriceDirtyState = false;

  function toggleButtons(itemCount){
    if (saveBtn) {
      saveBtn.disabled = (itemCount === 0) || isPriceDirtyState;
    }
    if (updatePriceBtn) {
      updatePriceBtn.disabled = !isPriceDirtyState;
      updatePriceBtn.style.display = isPriceDirtyState ? 'inline-flex' : 'none';
    }
  }

  function recalc(){
    if (!tbl) return;
    let subtotal = 0;
    let itemCount = 0;

    tbl.querySelectorAll('tbody tr').forEach(row=>{
      if (row.style.display === 'none') return;
      const price = parseFloat(row.querySelector('.priceCell')?.dataset.price || 0);
      const qtyEl = row.querySelector('.qtyInput');
      const qty   = Math.max(1, parseInt(qtyEl?.value)||1);
      const line  = price * qty;
      row.querySelector('.lineCell').textContent = bdMoney(line);
      subtotal += line;
      itemCount++;
    });

    const ship = parseFloat(feeEl?.value || 0) || 0;

    /* ডিসকাউন্ট (সার্ভারেও একই নিয়মে আবার হিসাব হয়) */
    const dType = document.getElementById('discType'), dVal = document.getElementById('discVal'), dHint = document.getElementById('discHint');
    let disc = 0, capped = false, bad = false;
    const dv = parseFloat(dVal?.value);
    if (dVal && dVal.value.trim() !== '' && (isNaN(dv) || dv < 0 || (dType?.value === 'percent' && dv > 100))) bad = true;
    else if (!isNaN(dv) && dv > 0) disc = (dType?.value === 'percent') ? Math.round(subtotal * dv) / 100 : dv;
    if (disc > subtotal) { disc = subtotal; capped = true; }
    const sdl = document.getElementById('sumDiscLabel'), sdv = document.getElementById('sumDiscount');
    if (sdl && sdv) { sdl.hidden = !(disc > 0); sdv.hidden = !(disc > 0); sdv.textContent = '\u2212' + bdMoney(disc); }
    if (dHint) {
      dHint.classList.toggle('text-danger', bad || capped);
      dHint.textContent = bad ? 'ডিসকাউন্টের মান সঠিক নয় (০ বা ধনাত্মক সংখ্যা; % হলে সর্বোচ্চ ১০০)।'
        : capped ? 'ডিসকাউন্ট সাবটোটালের বেশি হতে পারে না — সাবটোটালের সমান ধরা হলো।'
        : (disc > 0 && dType?.value === 'percent') ? ('= ' + bdMoney(disc) + ' ছাড় (সাবটোটালের ' + dv + '%)')
        : 'সাবটোটাল থেকে কাটা হবে। ৳ (টাকা) বা % (শতাংশ)।';
    }

    if (sumSub)  sumSub.textContent = bdMoney(subtotal);
    if (sumShip) sumShip.textContent= bdMoney(ship);
    if (sumTot)  sumTot.textContent = bdMoney(Math.max(0, subtotal - disc + ship));

    toggleButtons(itemCount);
  }

  function setDirty(){
    isPriceDirtyState = true;
    recalc();
  }

  // qty input typing/change (delegation)
  tbl?.addEventListener('input', function(e){
    const inp = e.target.closest('.qtyInput');
    if (!inp) return;
    if ((inp.value||'').trim()==='') return;
    if (parseInt(inp.value) < 1) inp.value = 1;
    setDirty();
  });

  tbl?.addEventListener('change', function(e){
    const inp = e.target.closest('.qtyInput');
    if (!inp) return;
    const v = parseInt(inp.value);
    if (isNaN(v) || v < 1) inp.value = 1;
    setDirty();
  });

  // + / - buttons (delegation)
  tbl?.addEventListener('click', function(e){
    const dec = e.target.closest('.btnDec');
    const inc = e.target.closest('.btnInc');
    if (!dec && !inc) return;
    const wrap = e.target.closest('.qtyBox');
    const inp = wrap?.querySelector('.qtyInput');
    if (!inp) return;
    let v = parseInt(inp.value)||1;
    v += inc ? 1 : -1;
    if (v < 1) v = 1;
    inp.value = v;
    setDirty();
  });

  // Remove item button (delegation)
  tbl?.addEventListener('click', function(e){
    const btn = e.target.closest('.btn-remove-item');
    if (!btn) return;
    const row = btn.closest('tr');
    if (!row) return;

    const itemId = btn.dataset.itemId;
    if (itemId) { // Existing item
      const hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = 'remove_items[]';
      hiddenInput.value = itemId;
      document.getElementById('editForm').appendChild(hiddenInput);
    }
    row.remove();
    setDirty();
  });

  // Shipping fee change
  document.getElementById('districtSel')?.addEventListener('change', function(){
    const opt = this.selectedOptions[0];
    if (opt && opt.value !== '' && opt.dataset.fee !== undefined && feeEl) {
      feeEl.value = opt.dataset.fee;
      feeEl.dispatchEvent(new Event('input', {bubbles:true}));
    }
  });
  feeEl?.addEventListener('input', setDirty);
  feeEl?.addEventListener('change', setDirty);
  document.getElementById('discVal')?.addEventListener('input', setDirty);
  document.getElementById('discType')?.addEventListener('change', setDirty);

  // public API
  return { recalc, setDirty };
})();

// init once on load
window.orderEditor.recalc();

// ---- Product Search & Add ----
(function(){
  const searchInput = document.getElementById('productSearch');
  const resultsDiv  = document.getElementById('searchResults');
  const itemsTbody  = document.querySelector('#itemsTable tbody');
  let searchTimeout;

  if (!searchInput || !resultsDiv || !itemsTbody) return;

  searchInput.addEventListener('input', function(){
    clearTimeout(searchTimeout);
    const query = this.value.trim();
    if (query.length < 2) {
      resultsDiv.style.display = 'none';
      return;
    }
    searchTimeout = setTimeout(async () => {
      try {
        const res = await fetch(`api_product_search.php?q=${encodeURIComponent(query)}`);
        if (!res.ok) throw new Error('Network response was not ok');
        const products = await res.json();
        renderResults(products);
      } catch (e) {
        console.error("Search failed:", e);
        resultsDiv.style.display = 'none';
      }
    }, 300);
  });

  function renderResults(products) {
    if (!products || products.length === 0) {
      resultsDiv.style.display = 'none';
      return;
    }
    let html = '<div class="list-group list-group-flush">';
    products.forEach(p => {
      html += `<a class="list-group-item list-group-item-action" data-product='${JSON.stringify(p)}'>
        ${p.name} <span class="price float-end">${bdMoney(p.price)}</span>
      </a>`;
    });
    html += '</div>';
    resultsDiv.innerHTML = html;
    resultsDiv.style.display = 'block';
  }

  resultsDiv.addEventListener('click', function(e){
    const item = e.target.closest('.list-group-item');
    if (!item) return;
    const product = JSON.parse(item.dataset.product);

    // Check if product already in list
    let alreadyExists = false;
    document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
      if (row.dataset.productId == product.id) {
        alreadyExists = true;
      }
    });

    if (alreadyExists) {
      alert('This product is already in the list.');
      return;
    }

    const newRowHtml = `
      <tr data-product-id="${product.id}">
        <td data-label="পণ্য">${product.name}</td>
        <td data-label="দাম" class="text-end priceCell" data-price="${product.price}">${bdMoney(product.price)}</td>
        <td data-label="পরিমাণ" class="text-center">
          <div class="qtyBox">
            <button type="button" class="btnDec" aria-label="কমান">−</button>
            <input type="number" name="new_qty[${product.id}]" class="qtyInput" value="1" min="1">
            <button type="button" class="btnInc" aria-label="বাড়ান">+</button>
          </div>
        </td>
        <td data-label="অ্যাকশন" class="text-center">
          <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">Remove</button>
        </td>
        <td data-label="লাইন" class="text-end lineCell">${bdMoney(product.price)}</td>
      </tr>`;
    itemsTbody.insertAdjacentHTML('beforeend', newRowHtml);
    searchInput.value = '';
    resultsDiv.style.display = 'none';

    document.getElementById('sumTotal')?.scrollIntoView({ behavior: 'smooth', block: 'center' });

    // ✅ নতুন পণ্য যোগ হলে dirty করে দাও → Update Price দেখাবে + Save disable হবে
    window.orderEditor.setDirty();
  });

  document.addEventListener('click', e => {
    if (!resultsDiv.contains(e.target) && e.target !== searchInput) {
      resultsDiv.style.display = 'none';
    }
  });
})();
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/district-picker.js"></script>
<script>
/* ডেলিভারির জেলা: খোঁজা-যোগ্য বাছাই (শেয়ার করা assets/js/district-picker.js) */
(function(){
  var sel = document.getElementById('districtSel');
  if (!sel || !window.DistrictPicker) return;
  var col = sel.closest('.col-md-6');
  DistrictPicker.enhance(sel, {
    buttonId: 'districtBtn',
    labelEl: col ? col.querySelector('label') : null,
    showFees: true,
    fmtMoney: bdMoney,
    admin: true,
    blankPlain: true,
    minWidth: 300,
    placeholder: 'জেলা খুঁজুন (বাংলা/English)',
    removeRequired: false,
    title: 'ডেলিভারির জেলা'
  });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
