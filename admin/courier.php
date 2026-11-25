<?php
// admin/courier.php — কুরিয়ার (Steadfast): চালু/বন্ধ, API কী, সংযোগ পরীক্ষা, সব সিঙ্ক, লগ
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/steadfast.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_courier');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$by = (string)($_SESSION['admin_username'] ?? '');
$flashSet = function (bool $ok, string $msg) { $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg]; };
$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
  if (!csrf_verify()) { $flashSet(false, 'সিকিউরিটি টোকেন মিলেনি'); header('Location: courier.php'); exit; }
  if ($action === 'save') {
    [$ok, $errs] = sf_save_settings($pdo, $_POST);
    if ($ok) { $flashSet(true, 'কুরিয়ার সেটিংস সংরক্ষণ হয়েছে।'); header('Location: courier.php'); exit; }
    $formErrors = $errs;
  } elseif ($action === 'test') {
    $r = sf_balance($pdo, $by);
    $flashSet($r['ok'], $r['ok'] ? 'সংযোগ ঠিক আছে — কুরিয়ার ব্যালেন্স: ৳' . number_format($r['balance'], 2) : 'সংযোগ ব্যর্থ: ' . $r['error']);
    header('Location: courier.php'); exit;
  } elseif ($action === 'sync_all') {
    if (!sf_ready($pdo)) { $flashSet(false, 'কুরিয়ার সংযোগ চালু নেই।'); }
    else { $r = sf_sync_all($pdo, $by); $flashSet($r['fail'] === 0, "সিঙ্ক শেষ: মোট {$r['total']}, সফল {$r['ok']}, ব্যর্থ {$r['fail']}"); }
    header('Location: courier.php'); exit;
  } else { $flashSet(false, 'অজানা অ্যাকশন।'); header('Location: courier.php'); exit; }
}

$st = sf_settings($pdo);
$prof = sf_profile();
$colsOk = sf_columns_ok($pdo);
$ready = sf_ready($pdo);
$logs = [];
try { $logs = $pdo->query("SELECT l.*, o.order_code FROM courier_logs l LEFT JOIN orders o ON o.id=l.order_id ORDER BY l.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$inFlight = 0;
try { $inFlight = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE courier_consignment_id IS NOT NULL AND courier_consignment_id<>'' AND (courier_status IS NULL OR courier_status NOT IN ('delivered','cancelled','partial_delivered'))")->fetchColumn(); } catch (Throwable $e) {}

require_once __DIR__.'/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><h5 class="m-0">কুরিয়ার (Steadfast)</h5><div class="text-muted small">অর্ডার সরাসরি কুরিয়ারে পাঠানো ও অবস্থা মেলানো</div></div>
  <span class="badge <?php echo $ready ? 'bg-success' : 'bg-secondary'; ?>" id="cStatus"><?php echo $ready ? 'চালু' : 'বন্ধ'; ?></span>
</div>

<?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($fl['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2" id="cFlash"><?php echo h($fl['msg'] ?? ''); ?></div>
<?php endif; ?>
<?php if ($formErrors): ?>
  <div class="alert alert-danger py-2" id="cErrors"><ul class="mb-0"><?php foreach ($formErrors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$colsOk): ?>
  <div class="alert alert-warning py-2" id="cNoCols">ডাটাবেসে কুরিয়ার-কলাম নেই (মাইগ্রেশন চলেনি) — হোমপেজ একবার খুলুন।</div>
<?php endif; ?>
<?php if (empty($prof['verified'])): ?>
  <div class="alert alert-warning py-2" id="cUnverified"><strong>সতর্কতা:</strong> API-র খুঁটিনাটি (URL, হেডার, ফিল্ডের নাম) এখনো অফিসিয়াল ডকুমেন্ট থেকে যাচাই করা নয়। চালু করার আগে "সংযোগ পরীক্ষা" দিন এবং একটি ছোট অর্ডার পাঠিয়ে মিলিয়ে নিন।</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <form method="post" class="card" id="cForm" autocomplete="off">
      <div class="card-body">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <h6 class="fw-bold mb-3">সংযোগ সেটিংস</h6>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="cEnabled" name="enabled" value="1" <?php echo $st['enabled'] ? 'checked' : ''; ?>>
          <label class="form-check-label" for="cEnabled">কুরিয়ার সংযোগ চালু (বন্ধ থাকলে কখনো বাইরে কল যায় না)</label>
        </div>
        <div class="mb-3">
          <label class="form-label" for="cBase">Base URL</label>
          <input class="form-control" id="cBase" name="base_url" maxlength="150" value="<?php echo h($st['base_url']); ?>">
          <div class="form-text">https:// দিয়ে শুরু; ফাঁকা রাখলে ডিফল্ট।</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="cApi">API Key</label>
          <input class="form-control" type="password" id="cApi" name="api_key" maxlength="128" placeholder="<?php echo $st['api_key'] !== '' ? h(sf_mask($st['api_key'])) : 'এখনো দেওয়া হয়নি'; ?>" autocomplete="new-password">
          <div class="form-text" id="cApiHint"><?php echo $st['api_key'] !== '' ? 'সংরক্ষিত আছে: ' . h(sf_mask($st['api_key'])) . ' — ফাঁকা রাখলে আগেরটাই থাকবে' : 'কী নেই'; ?></div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="cSecret">Secret Key</label>
          <input class="form-control" type="password" id="cSecret" name="secret_key" maxlength="128" placeholder="<?php echo $st['secret'] !== '' ? h(sf_mask($st['secret'])) : 'এখনো দেওয়া হয়নি'; ?>" autocomplete="new-password">
          <div class="form-text" id="cSecretHint"><?php echo $st['secret'] !== '' ? 'সংরক্ষিত আছে: ' . h(sf_mask($st['secret'])) . ' — ফাঁকা রাখলে আগেরটাই থাকবে' : 'কী নেই'; ?></div>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="cClear" name="clear_keys" value="1">
          <label class="form-check-label" for="cClear">সংরক্ষিত দুটি কী-ই মুছে ফেলুন (সংযোগ বন্ধ হবে)</label>
        </div>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="cAuto" name="auto_status" value="1" <?php echo $st['auto_status'] ? 'checked' : ''; ?>>
          <label class="form-check-label" for="cAuto">অর্ডারের স্ট্যাটাস নিজে বদলাক (পাঠালে Shipped; কুরিয়ারে Delivered/Cancelled হলে সেটাই। বাতিলে স্টক ফেরে)</label>
        </div>
        <button class="btn btn-primary" type="submit" id="cSave"><i class="fa-solid fa-floppy-disk"></i> সংরক্ষণ</button>
      </div>
    </form>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card mb-3"><div class="card-body">
      <h6 class="fw-bold mb-2">পরীক্ষা ও সিঙ্ক</h6>
      <form method="post" class="mb-2"><?php echo csrf_field(); ?><input type="hidden" name="action" value="test">
        <button class="btn btn-outline-primary btn-sm" id="cTest" type="submit" <?php echo $ready ? '' : 'disabled'; ?>><i class="fa-solid fa-plug"></i> সংযোগ পরীক্ষা (ব্যালেন্স)</button>
      </form>
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="sync_all">
        <button class="btn btn-outline-secondary btn-sm" id="cSyncAll" type="submit" <?php echo $ready ? '' : 'disabled'; ?>><i class="fa-solid fa-rotate"></i> চলমান অর্ডারের স্ট্যাটাস মেলান</button>
      </form>
      <div class="text-muted small mt-2" id="cInFlight">কুরিয়ারে চলমান অর্ডার: <?php echo $inFlight; ?>টি</div>
      <?php if (!$ready): ?><div class="text-muted small mt-1">বাটন দুটি চালু হবে সংযোগ চালু ও দুই কী দেওয়ার পর।</div><?php endif; ?>
    </div></div>
    <div class="card"><div class="card-body small text-muted">
      <h6 class="fw-bold mb-2 text-body">নিয়ম</h6>
      <ul class="mb-0 ps-3">
        <li>COD অর্ডারে কুরিয়ার মোট টাকা তুলবে; অনলাইনে পরিশোধিত হলে ০।</li>
        <li>একই অর্ডার দ্বিতীয়বার পাঠানো যায় না।</li>
        <li>পাঠানোর পর নাম/মোবাইল/ঠিকানা/পরিমাণ/ডেলিভারি চার্জ বদলানো বন্ধ।</li>
        <li>স্ট্যাটাস মেলানো বাটন চাপলে হয় (ওয়েবহুক নয়)।</li>
        <li>API Key ও Secret লগে বা বার্তায় আসে না।</li>
      </ul>
    </div></div>
  </div>
</div>

<div class="card mt-3"><div class="card-body p-0">
  <div class="px-3 pt-3"><h6 class="fw-bold">সাম্প্রতিক লগ (শেষ ৫০)</h6></div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="cLogs">
    <thead><tr><th>সময়</th><th>অর্ডার</th><th>কাজ</th><th>ফল</th><th>HTTP</th><th>বার্তা</th><th>কে</th></tr></thead>
    <tbody>
    <?php if (!$logs): ?><tr><td colspan="7" class="text-muted text-center py-3">কোনো লগ নেই</td></tr><?php endif; ?>
    <?php foreach ($logs as $l): ?>
      <tr><td><?php echo h($l['created_at']); ?></td><td><?php echo $l['order_id'] ? '<a href="order_view.php?id=' . (int)$l['order_id'] . '">' . h($l['order_code'] ?: '#' . $l['order_id']) . '</a>' : '—'; ?></td>
        <td><?php echo h($l['action']); ?></td><td><span class="badge <?php echo $l['ok'] ? 'bg-success' : 'bg-danger'; ?>"><?php echo $l['ok'] ? 'সফল' : 'ব্যর্থ'; ?></span></td>
        <td><?php echo h($l['http_code'] ?? '—'); ?></td><td><?php echo h($l['message']); ?></td><td><?php echo h($l['created_by'] ?? ''); ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div></div>
<?php require __DIR__.'/footer.php'; ?>
