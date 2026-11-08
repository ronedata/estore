<?php
// account_dashboard.php — customer orders, profile, logout
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/customer_auth.php';

$customer = require_customer();
$pdo = get_pdo();

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
  if (csrf_verify()) {
    unset($_SESSION['customer_id'], $_SESSION['customer_name']);
    session_regenerate_id(true);
    header('Location: '.BASE_URL.'/index.php'); exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile') {
  if (!csrf_verify()) {
    $flash = 'err:সেশন মেয়াদোত্তীর্ণ।';
  } else {
    $name    = trim((string)($_POST['name'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $flash = 'err:বৈধ ইমেইল দিন।';
    } else {
      $pdo->prepare("UPDATE customers SET name=?, email=?, address=? WHERE id=?")
          ->execute([$name ?: null, $email ?: null, $address ?: null, (int)$customer['id']]);
      $_SESSION['customer_name'] = $name ?: $customer['mobile'];
      $flash = 'ok:প্রোফাইল সংরক্ষণ হয়েছে।';
      $customer = current_customer();
    }
  }
}

/* Orders — customer_id অথবা মোবাইল মিলিয়ে */
$orders = [];
try {
  $stm = $pdo->prepare("
    SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) AS item_count
    FROM orders o
    WHERE o.customer_id = ? OR o.mobile = ?
    ORDER BY o.id DESC
    LIMIT 50
  ");
  $stm->execute([(int)$customer['id'], $customer['mobile']]);
  $orders = $stm->fetchAll();
} catch (Throwable $e) {}

$wishCount = 0;
try {
  $wq = $pdo->prepare("SELECT COUNT(*) FROM wishlists WHERE customer_id=?");
  $wq->execute([(int)$customer['id']]);
  $wishCount = (int)$wq->fetchColumn();
} catch (Throwable $e) {}

$statusLabels = [
  'pending'    => ['অপেক্ষমাণ', '#fef3c7', '#b45309'],
  'processing' => ['প্রসেসিং', '#dbeafe', '#1d4ed8'],
  'shipped'    => ['শিপড', '#e0e7ff', '#4338ca'],
  'delivered'  => ['ডেলিভার্ড', '#dcfce7', '#15803d'],
  'cancelled'  => ['বাতিল', '#fee2e2', '#b91c1c'],
];

include __DIR__.'/includes/partials_header.php';
?>
<div class="wrap" style="max-width:820px">
  <div class="page-title-bar" style="margin-top:10px">
    <div>
      <h2>স্বাগতম, <?php echo h($customer['name'] ?: $customer['mobile']); ?></h2>
      <p><?php echo h($customer['mobile']); ?></p>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
      <input type="hidden" name="action" value="logout">
      <button class="btn-pill ghost" type="submit"><i class="fa-solid fa-right-from-bracket"></i> লগআউট</button>
    </form>
  </div>

  <?php if ($flash): ?>
    <div class="notice <?php echo flash_is_ok($flash) ? 'ok' : 'err'; ?>">
      <?php echo h(flash_message($flash)); ?>
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px">
    <a href="#orders" class="card-box" style="text-align:center;margin:0;padding:14px 8px">
      <div style="font-size:1.4rem;font-weight:800;color:var(--primary-purple)"><?php echo count($orders); ?></div>
      <div style="font-size:.75rem;color:var(--text-muted);font-weight:600">অর্ডার</div>
    </a>
    <a href="<?php echo BASE_URL; ?>/wishlist.php" class="card-box" style="text-align:center;margin:0;padding:14px 8px">
      <div style="font-size:1.4rem;font-weight:800;color:#e11d48"><?php echo $wishCount; ?></div>
      <div style="font-size:.75rem;color:var(--text-muted);font-weight:600">উইশলিস্ট</div>
    </a>
    <a href="<?php echo BASE_URL; ?>/track_orders.php" class="card-box" style="text-align:center;margin:0;padding:14px 8px">
      <div style="font-size:1.4rem;color:var(--primary-purple)"><i class="fa-solid fa-truck-fast"></i></div>
      <div style="font-size:.75rem;color:var(--text-muted);font-weight:600">ট্র্যাক</div>
    </a>
  </div>

  <div class="card-box" id="orders">
    <h3>আমার অর্ডার</h3>
    <?php if (!$orders): ?>
      <div class="empty-state" style="padding:26px 10px">
        <i class="fa-solid fa-box-open"></i>
        <h3>কোনো অর্ডার নেই</h3>
        <a class="btn-pill" href="<?php echo BASE_URL; ?>/shop.php">শপিং শুরু করুন</a>
      </div>
    <?php else: ?>
      <?php foreach ($orders as $o): ?>
        <?php $sl = $statusLabels[$o['status']] ?? [$o['status'], '#f1f5f9', '#475569']; ?>
        <a href="<?php echo BASE_URL; ?>/single_order_details.php?id=<?php echo (int)$o['id']; ?>&mobile=<?php echo urlencode($o['mobile']); ?>"
           style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid var(--border-color)">
          <div style="min-width:0">
            <div style="font-weight:800;font-size:.85rem"><?php echo h($o['order_code'] ?: ('#'.$o['id'])); ?></div>
            <div style="font-size:.72rem;color:#94a3b8;margin-top:2px">
              <?php echo date('d M Y, h:i A', strtotime($o['created_at'])); ?> · <?php echo (int)$o['item_count']; ?> আইটেম
            </div>
          </div>
          <div style="text-align:right;flex:0 0 auto">
            <div style="font-weight:800;font-size:.88rem;color:var(--primary-dark)"><?php echo money_bd($o['total']); ?></div>
            <span style="display:inline-block;margin-top:3px;padding:2px 9px;border-radius:999px;font-size:10px;font-weight:800;background:<?php echo $sl[1]; ?>;color:<?php echo $sl[2]; ?>">
              <?php echo h($sl[0]); ?>
            </span>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card-box">
    <h3>প্রোফাইল</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
      <input type="hidden" name="action" value="profile">

      <div class="field">
        <label for="pName">নাম</label>
        <input id="pName" name="name" value="<?php echo h($customer['name'] ?? ''); ?>" maxlength="120">
      </div>
      <div class="field">
        <label for="pEmail">ইমেইল</label>
        <input id="pEmail" name="email" type="email" value="<?php echo h($customer['email'] ?? ''); ?>">
      </div>
      <div class="field">
        <label for="pAddr">ঠিকানা</label>
        <textarea id="pAddr" name="address" rows="3"><?php echo h($customer['address'] ?? ''); ?></textarea>
      </div>

      <button class="btn-pill" type="submit"><i class="fa-solid fa-floppy-disk"></i> সংরক্ষণ</button>
    </form>
  </div>
</div>
<?php include __DIR__.'/includes/partials_footer.php'; ?>
