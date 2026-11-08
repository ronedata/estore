<?php
// admin/sliders.php — hero/side slider list with active toggle, sort, delete
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_sliders');

$pdo = get_pdo();

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'CSRF token mismatch'];
    header('Location: sliders.php'); exit;
  }

  $action = $_POST['action'] ?? '';

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $stm = $pdo->prepare("SELECT image FROM sliders WHERE id=?");
      $stm->execute([$id]);
      $img = (string)($stm->fetchColumn() ?: '');
      $pdo->prepare("DELETE FROM sliders WHERE id=?")->execute([$id]);
      if ($img !== '') {
        $abs = realpath(__DIR__.'/../'.ltrim($img, '/'));
        if ($abs && is_file($abs) && strpos(realpath(__DIR__.'/../uploads'), $abs) === 0) @unlink($abs);
      }
      $_SESSION['flash'] = ['ok'=>true, 'msg'=>'স্লাইডার মুছে ফেলা হয়েছে।'];
    }
    header('Location: sliders.php'); exit;
  }

  if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("UPDATE sliders SET active = 1 - active WHERE id=?")->execute([$id]);
      $_SESSION['flash'] = ['ok'=>true, 'msg'=>'স্ট্যাটাস আপডেট হয়েছে।'];
    }
    header('Location: sliders.php'); exit;
  }

  if ($action === 'sort') {
    $order = $_POST['order'] ?? [];
    if (is_array($order)) {
      $u = $pdo->prepare("UPDATE sliders SET sort_order=? WHERE id=?");
      foreach ($order as $pos => $sid) {
        $u->execute([(int)$pos, (int)$sid]);
      }
      $_SESSION['flash'] = ['ok'=>true, 'msg'=>'ক্রম সংরক্ষিত হয়েছে।'];
    }
    header('Location: sliders.php'); exit;
  }
}

$placement = $_GET['placement'] ?? '';
$where = [];
$params = [];
if (in_array($placement, ['hero','side'], true)) { $where[] = 'placement=?'; $params[] = $placement; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
$stm = $pdo->prepare("SELECT * FROM sliders $whereSql ORDER BY placement ASC, sort_order ASC, id DESC");
$stm->execute($params);
$rows = $stm->fetchAll();

$heroCount = (int)$pdo->query("SELECT COUNT(*) FROM sliders WHERE placement='hero'")->fetchColumn();
$sideCount = (int)$pdo->query("SELECT COUNT(*) FROM sliders WHERE placement='side'")->fetchColumn();

require_once __DIR__.'/header.php';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h4 class="mb-1">স্লাইডার / ব্যানার</h4>
    <div class="text-muted small">
      হিরো স্লাইডার: <strong><?php echo $heroCount; ?></strong> টি ·
      সাইড কার্ড: <strong><?php echo $sideCount; ?></strong> টি
    </div>
  </div>
  <a class="btn btn-primary" href="slider_form.php">
    <i class="fa-solid fa-plus"></i> নতুন স্লাইডার
  </a>
</div>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2">
    <?php echo h($f['msg'] ?? $f['message'] ?? ''); ?>
  </div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?php echo $placement===''?'active':''; ?>" href="sliders.php">সব</a></li>
  <li class="nav-item"><a class="nav-link <?php echo $placement==='hero'?'active':''; ?>" href="sliders.php?placement=hero">হিরো</a></li>
  <li class="nav-item"><a class="nav-link <?php echo $placement==='side'?'active':''; ?>" href="sliders.php?placement=side">সাইড কার্ড</a></li>
</ul>

<?php if (!$rows): ?>
  <div class="alert alert-info">
    এখনো কোনো স্লাইডার নেই। <a href="slider_form.php">একটা যোগ করুন</a>।
    স্লাইডার না থাকলে হোমপেজে হিরো সেকশন দেখাবে না (বাকি সাইট স্বাভাবিক চলবে)।
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th style="width:90px">ক্রম</th>
              <th style="width:120px">ইমেজ</th>
              <th>টাইটেল / লিংক</th>
              <th style="width:110px">প্লেসমেন্ট</th>
              <th style="width:90px">অ্যাকটিভ</th>
              <th style="width:150px" class="text-end">অ্যাকশন</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <input type="number" class="form-control form-control-sm sort-input"
                       data-id="<?php echo (int)$r['id']; ?>"
                       value="<?php echo (int)$r['sort_order']; ?>" style="width:70px">
              </td>
              <td>
                <?php if (!empty($r['image'])): ?>
                  <img src="<?php echo h(img_url($r['image'])); ?>" alt="" style="height:48px;width:80px;object-fit:cover;border-radius:6px">
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
              </td>
              <td>
                <div class="fw-semibold"><?php echo h($r['title'] ?: '(শিরোনাম নেই)'); ?></div>
                <div class="small text-muted">
                  <?php echo h($r['link_type']); ?>:
                  <?php echo h((string)$r['link_value']); ?>
                </div>
              </td>
              <td>
                <span class="badge <?php echo $r['placement']==='hero'?'text-bg-primary':'text-bg-secondary'; ?>">
                  <?php echo $r['placement']==='hero' ? 'হিরো' : 'সাইড'; ?>
                </span>
              </td>
              <td>
                <form method="post" class="d-inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn btn-sm <?php echo $r['active']?'btn-success':'btn-outline-secondary'; ?>">
                    <?php echo $r['active'] ? 'On' : 'Off'; ?>
                  </button>
                </form>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="slider_form.php?id=<?php echo (int)$r['id']; ?>">Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('স্লাইডারটি মুছে ফেলবেন?')">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <form method="post" id="sortForm" class="mt-3">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="sort">
    <div id="sortInputs"></div>
    <button class="btn btn-outline-secondary btn-sm">
      <i class="fa-solid fa-arrow-down-1-9"></i> ক্রম সংরক্ষণ করুন
    </button>
  </form>
<?php endif; ?>

<div class="alert alert-light border mt-4 small">
  <strong>টিপস:</strong> হিরো স্লাইডারের ছবি <code>16:9</code> এবং সাইড কার্ডের ছবি <code>16:10</code> রাখলে সবচেয়ে ভালো দেখাবে।
  মোবাইলে সাইট কার্ড লুকানো থাকে, শুধু হিরো স্লাইডার দেখায়।
</div>

<script>
document.getElementById('sortForm')?.addEventListener('submit', function(){
  const wrap = document.getElementById('sortInputs');
  wrap.innerHTML = '';
  document.querySelectorAll('.sort-input').forEach(inp => {
    const h = document.createElement('input');
    h.type = 'hidden';
    h.name = 'order[' + inp.value + ']';
    h.value = inp.dataset.id;
    wrap.appendChild(h);
  });
});
</script>

<?php require_once __DIR__.'/footer.php'; ?>
