<?php
// admin/reviews.php — moderate product reviews
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_reviews');

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false,'msg'=>'CSRF token mismatch'];
    header('Location: reviews.php'); exit;
  }
  $action = $_POST['action'] ?? '';
  $id     = (int)($_POST['id'] ?? 0);

  if ($id > 0) {
    if ($action === 'approve') {
      $pdo->prepare("UPDATE product_reviews SET status='approved' WHERE id=?")->execute([$id]);
      $_SESSION['flash'] = ['ok'=>true,'msg'=>'রিভিউ অনুমোদিত হয়েছে।'];
    } elseif ($action === 'reject') {
      $pdo->prepare("UPDATE product_reviews SET status='rejected' WHERE id=?")->execute([$id]);
      $_SESSION['flash'] = ['ok'=>true,'msg'=>'রিভিউ বাতিল হয়েছে।'];
    } elseif ($action === 'delete') {
      $pdo->prepare("DELETE FROM product_reviews WHERE id=?")->execute([$id]);
      $_SESSION['flash'] = ['ok'=>true,'msg'=>'রিভিউ মুছে ফেলা হয়েছে।'];
    }
  }
  $back = $_POST['back'] ?? 'reviews.php';
  if ($back === '' || preg_match('~^(https?:)?//~i', $back)) $back = 'reviews.php';
  header('Location: '.$back); exit;
}

$status = $_GET['status'] ?? '';
$allowedStatus = ['pending','approved','rejected'];
$where = [];
$params = [];
if (in_array($status, $allowedStatus, true)) { $where[] = 'r.status=?'; $params[] = $status; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$stm = $pdo->prepare("
  SELECT r.*, p.name AS product_name, p.image AS product_image
  FROM product_reviews r
  LEFT JOIN products p ON p.id = r.product_id
  $whereSql
  ORDER BY FIELD(r.status,'pending','approved','rejected'), r.created_at DESC
  LIMIT 200
");
$stm->execute($params);
$rows = $stm->fetchAll();

$counts = ['pending'=>0,'approved'=>0,'rejected'=>0,'all'=>0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM product_reviews GROUP BY status")->fetchAll() as $c) {
  $counts[$c['status']] = (int)$c['c'];
  $counts['all'] += (int)$c['c'];
}

function stars(int $n): string {
  $n = max(0, min(5, $n));
  return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
}

require_once __DIR__.'/header.php';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
  <div>
    <h4 class="mb-1">প্রোডাক্ট রিভিউ</h4>
    <div class="text-muted small">
      অপেক্ষমাণ: <strong><?php echo $counts['pending']; ?></strong> ·
      অনুমোদিত: <strong><?php echo $counts['approved']; ?></strong> ·
      বাতিল: <strong><?php echo $counts['rejected']; ?></strong>
    </div>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2">
    <?php echo h($f['msg'] ?? $f['message'] ?? ''); ?>
  </div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?php echo $status===''?'active':''; ?>" href="reviews.php">সব (<?php echo $counts['all']; ?>)</a></li>
  <?php foreach (['pending'=>'অপেক্ষমাণ','approved'=>'অনুমোদিত','rejected'=>'বাতিল'] as $k=>$lbl): ?>
    <li class="nav-item"><a class="nav-link <?php echo $status===$k?'active':''; ?>" href="reviews.php?status=<?php echo $k; ?>">
      <?php echo $lbl; ?> (<?php echo $counts[$k]; ?>)
    </a></li>
  <?php endforeach; ?>
</ul>

<?php if (!$rows): ?>
  <div class="alert alert-info">কোনো রিভিউ নেই।</div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($rows as $r): ?>
      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <div class="d-flex flex-wrap gap-3">
              <div style="width:64px;flex:0 0 auto">
                <?php if ($r['product_image']): ?>
                  <img src="<?php echo h($r['product_image']); ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px">
                <?php endif; ?>
              </div>
              <div class="flex-grow-1">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                  <span class="badge text-bg-<?php echo $r['status']==='approved'?'success':($r['status']==='pending'?'warning':'secondary'); ?>">
                    <?php echo $r['status']; ?>
                  </span>
                  <span class="text-warning fw-bold"><?php echo stars((int)$r['rating']); ?></span>
                  <strong><?php echo h($r['name']); ?></strong>
                  <span class="text-muted small"><?php echo h($r['created_at']); ?></span>
                </div>
                <div class="small text-muted mb-1">
                  প্রোডাক্ট: <?php echo h($r['product_name'] ?: '(মুছে ফেলা)'); ?>
                </div>
                <p class="mb-2"><?php echo nl2br(h($r['comment'])); ?></p>
                <?php if (!empty($r['image'])): ?>
                  <a href="<?php echo h($r['image']); ?>" target="_blank" rel="noopener">
                    <img src="<?php echo h($r['image']); ?>" alt="" style="height:70px;border-radius:6px">
                  </a>
                <?php endif; ?>
              </div>
              <div class="d-flex flex-column gap-1" style="min-width:110px">
                <?php if ($r['status'] !== 'approved'): ?>
                  <form method="post"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="back" value="reviews.php<?php echo $status?'?status='.$status:''; ?>">
                    <button class="btn btn-sm btn-success w-100">অনুমোদন</button>
                  </form>
                <?php endif; ?>
                <?php if ($r['status'] !== 'rejected'): ?>
                  <form method="post"><?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="back" value="reviews.php<?php echo $status?'?status='.$status:''; ?>">
                    <button class="btn btn-sm btn-outline-secondary w-100">বাতিল</button>
                  </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('রিভিউটি স্থায়ীভাবে মুছে ফেলবেন?')">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="back" value="reviews.php<?php echo $status?'?status='.$status:''; ?>">
                  <button class="btn btn-sm btn-outline-danger w-100">মুছুন</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__.'/footer.php'; ?>
