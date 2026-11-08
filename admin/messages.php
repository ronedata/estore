<?php
// admin/messages.php — contact form থেকে আসা মেসেজ
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_pages');

$pdo = get_pdo();

/* ---------- POST: mark read / delete ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'CSRF token mismatch'];
    header('Location: messages.php'); exit;
  }

  if (isset($_POST['delete_id'])) {
    $pdo->prepare("DELETE FROM contact_messages WHERE id=?")->execute([(int)$_POST['delete_id']]);
    $_SESSION['flash'] = ['ok'=>true, 'msg'=>'মেসেজ ডিলিট হয়েছে'];
    header('Location: messages.php'); exit;
  }

  if (isset($_POST['toggle_id'])) {
    $id = (int)$_POST['toggle_id'];
    $pdo->prepare("UPDATE contact_messages SET is_read = 1 - is_read WHERE id=?")->execute([$id]);
    header('Location: messages.php'); exit;
  }

  if (isset($_POST['mark_all'])) {
    $pdo->exec("UPDATE contact_messages SET is_read=1 WHERE is_read=0");
    $_SESSION['flash'] = ['ok'=>true, 'msg'=>'সব মেসেজ পড়া হয়েছে হিসেবে চিহ্নিত'];
    header('Location: messages.php'); exit;
  }
}

$rows = [];
try {
  $rows = $pdo->query("SELECT * FROM contact_messages ORDER BY id DESC LIMIT 200")->fetchAll();
} catch (Throwable $e) {}

$unread = 0;
foreach ($rows as $r) { if ((int)$r['is_read'] === 0) $unread++; }

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

require_once __DIR__.'/header.php';
?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h4 class="mb-0">যোগাযোগ মেসেজ</h4>
    <div class="text-muted small">
      মোট <?php echo count($rows); ?> — অপঠিত <?php echo $unread; ?>
    </div>
  </div>
  <?php if ($unread > 0): ?>
    <form method="post" class="m-0">
      <?php echo csrf_field(); ?>
      <button class="btn btn-sm btn-outline-primary" name="mark_all" value="1">
        <i class="fa-solid fa-check-double"></i> সব পড়া হয়েছে
      </button>
    </form>
  <?php endif; ?>
</div>

<?php if ($flash): ?>
  <div class="alert <?php echo !empty($flash['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2">
    <?php echo h($flash['msg'] ?? ''); ?>
  </div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="card"><div class="card-body text-center text-muted py-5">
    <i class="fa-regular fa-envelope-open fa-2x mb-2 d-block"></i>
    এখনো কোনো মেসেজ আসেনি।
  </div></div>
<?php else: ?>

<div class="card">
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th style="width:60px">ID</th>
        <th>প্রেরক</th>
        <th>মেসেজ</th>
        <th style="width:150px">সময়</th>
        <th style="width:170px" class="text-end">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr class="<?php echo (int)$r['is_read'] === 0 ? 'fw-semibold' : ''; ?>">
        <td>#<?php echo (int)$r['id']; ?></td>
        <td>
          <div><?php echo h($r['name']); ?></div>
          <div class="small text-muted"><?php echo h($r['contact']); ?></div>
        </td>
        <td>
          <div class="small" style="white-space:pre-wrap;max-width:520px">
            <?php echo h(mb_substr((string)$r['message'], 0, 400)); ?><?php echo mb_strlen((string)$r['message']) > 400 ? '…' : ''; ?>
          </div>
        </td>
        <td class="small text-muted"><?php echo h(date('d M Y, H:i', strtotime((string)$r['created_at']))); ?></td>
        <td class="text-end">
          <form method="post" class="d-inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="toggle_id" value="<?php echo (int)$r['id']; ?>">
            <button class="btn btn-sm btn-outline-secondary" title="<?php echo (int)$r['is_read'] === 0 ? 'পড়া হয়েছে' : 'অপঠিত করুন'; ?>">
              <i class="fa-solid <?php echo (int)$r['is_read'] === 0 ? 'fa-check' : 'fa-envelope'; ?>"></i>
            </button>
          </form>
          <form method="post" class="d-inline" onsubmit="return confirm('মেসেজ ডিলিট করবেন?')">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="delete_id" value="<?php echo (int)$r['id']; ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__.'/footer.php'; ?>
