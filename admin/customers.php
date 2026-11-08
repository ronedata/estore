<?php
// admin/customers.php — customer list + order history
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_customers');

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) { $_SESSION['flash']=['ok'=>false,'msg'=>'CSRF token mismatch']; header('Location: customers.php'); exit; }

  $action = $_POST['action'] ?? '';
  $id     = (int)($_POST['id'] ?? 0);

  if ($id > 0) {
    if ($action === 'toggle') {
      $pdo->prepare("UPDATE customers SET active = 1 - active WHERE id=?")->execute([$id]);
      $_SESSION['flash'] = ['ok'=>true,'msg'=>'স্ট্যাটাস আপডেট হয়েছে।'];
    } elseif ($action === 'delete') {
      $pdo->prepare("DELETE FROM wishlists WHERE customer_id=?")->execute([$id]);
      $pdo->prepare("UPDATE orders SET customer_id=NULL WHERE customer_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$id]);
      $_SESSION['flash'] = ['ok'=>true,'msg'=>'কাস্টমার মুছে ফেলা হয়েছে (অর্ডার ইতিহাস অটুট)।'];
    }
  }
  header('Location: customers.php'); exit;
}

$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
  $where[] = "(c.mobile LIKE ? OR c.name LIKE ? OR c.email LIKE ?)";
  $like = '%'.$q.'%';
  $params = [$like, $like, $like];
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$stm = $pdo->prepare("
  SELECT c.*,
         (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id OR o.mobile = c.mobile) AS order_count,
         (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE (o.customer_id = c.id OR o.mobile = c.mobile) AND o.status <> 'cancelled') AS total_spent
  FROM customers c
  $whereSql
  ORDER BY c.id DESC
  LIMIT 200
");
$stm->execute($params);
$rows = $stm->fetchAll();

$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

require_once __DIR__.'/header.php';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h4 class="mb-1">কাস্টমার</h4>
    <div class="text-muted small">মোট <?php echo $totalCustomers; ?> জন</div>
  </div>
  <form class="d-flex gap-2" method="get">
    <input name="q" class="form-control" placeholder="মোবাইল / নাম / ইমেইল" value="<?php echo h($q); ?>">
    <button class="btn btn-primary">খুঁজুন</button>
  </form>
</div>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2">
    <?php echo h($f['msg'] ?? $f['message'] ?? ''); ?>
  </div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="alert alert-info">কোনো কাস্টমার নেই।</div>
<?php else: ?>
  <div class="card"><div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>#</th><th>মোবাইল</th><th>নাম</th><th>ইমেইল</th>
            <th class="text-end">অর্ডার</th><th class="text-end">মোট খরচ</th>
            <th>স্ট্যাটাস</th><th class="text-end">অ্যাকশন</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td class="fw-semibold"><?php echo h($r['mobile']); ?></td>
              <td><?php echo h($r['name'] ?: '—'); ?></td>
              <td class="small text-muted"><?php echo h($r['email'] ?: '—'); ?></td>
              <td class="text-end"><?php echo (int)$r['order_count']; ?></td>
              <td class="text-end"><?php echo money_bd((float)$r['total_spent']); ?></td>
              <td>
                <form method="post" class="d-inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn btn-sm <?php echo $r['active']?'btn-success':'btn-outline-secondary'; ?>">
                    <?php echo $r['active']?'Active':'Blocked'; ?>
                  </button>
                </form>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary"
                   href="../admin/orders.php?q=<?php echo urlencode($r['mobile']); ?>">অর্ডার</a>
                <form method="post" class="d-inline" onsubmit="return confirm('কাস্টমারটি মুছে ফেলবেন? অর্ডার ইতিহাস অটুট থাকবে।')">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn btn-sm btn-outline-danger">মুছুন</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div></div>
<?php endif; ?>

<?php require_once __DIR__.'/footer.php'; ?>
