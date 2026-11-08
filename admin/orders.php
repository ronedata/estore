<?php
// admin/orders.php — Status dropdown works (auto-submit), mobile+PC friendly
require_once __DIR__.'/../includes/config.php';
require_once __DIR__ . '/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_orders');

$pdo = get_pdo();

/* ---------- Helpers (fallback, to avoid redeclare) ---------- */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money_bd')) {
  function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); }
}
if (!function_exists('today')) {
  function today(){ return date('Y-m-d'); }
}
function bn_digits($s){
  return strtr((string)$s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']);
}
function dateBD($ts){
  // d M, Y g:i A → শুধুই ডিজিটগুলো বাংলা হবে
  return bn_digits(date('d M, Y g:i A', is_numeric($ts)? (int)$ts : strtotime($ts)));
}
/* ---------- Allowed statuses ---------- */
$STATUSES = ['pending','processing','shipped','delivered','cancelled'];

/* ---------- Handle Status Update (POST before any HTML) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok' => false, 'message' => 'CSRF token mismatch'];
    header('Location: orders.php'); exit;
  }
  $oid    = (int)($_POST['order_id'] ?? 0);
  $status = $_POST['status'] ?? '';
  $return = $_POST['return'] ?? ''; // keep filters via querystring

  if ($oid > 0 && in_array($status, $STATUSES, true)) {
    try {
      $pdo->beginTransaction();
      ensure_stock_tracking($pdo);

      $stm = $pdo->prepare("SELECT * FROM orders WHERE id=? FOR UPDATE");
      $stm->execute([$oid]);
      $order = $stm->fetch(PDO::FETCH_ASSOC);

      if ($order) {
        // cancelled → স্টক ফেরত; cancelled থেকে ফিরলে আবার কাটা
        sync_order_stock_status($pdo, $order, $status);
        $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$status, $oid]);
        $_SESSION['flash'] = ['ok'=>true, 'message'=>'স্ট্যাটাস ও স্টক আপডেট হয়েছে।'];
      } else {
        $_SESSION['flash'] = ['ok'=>false, 'message'=>'অর্ডার পাওয়া যায়নি।'];
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['flash'] = ['ok'=>false, 'message'=>'স্টক সমন্বয় ব্যর্থ: '.$e->getMessage()];
    }
  } else {
    $_SESSION['flash'] = ['ok'=>false, 'message'=>'স্ট্যাটাস আপডেট ব্যর্থ।'];
  }
  header('Location: orders.php'.($return ? ('?'.$return) : ''));
  exit;
}

/* ---------- Filters (GET) ---------- */
$q      = trim($_GET['q'] ?? '');
$from   = $_GET['from'] ?? today();
$to     = $_GET['to']   ?? today();
$fstat  = $_GET['status'] ?? 'all';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10; // প্রতি পৃষ্ঠায় আইটেম সংখ্যা
$offset = ($page - 1) * $per_page;
$current_url_params = $_GET; // পেজিনেশন লিঙ্কের জন্য

$where  = "DATE(created_at) BETWEEN ? AND ?";
$params = [$from, $to];

if ($q !== '') {
  $where .= " AND (order_code LIKE ? OR customer_name LIKE ? OR mobile LIKE ?)";
  $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($fstat !== 'all' && in_array($fstat, $STATUSES, true)) {
  $where .= " AND status = ?";
  $params[] = $fstat;
}

/* ---------- Count total rows for pagination ---------- */
$count_sql = "SELECT COUNT(*) FROM orders WHERE $where";
$count_stm = $pdo->prepare($count_sql);
$count_stm->execute($params);
$total_rows = (int)$count_stm->fetchColumn();
$total_pages = ceil($total_rows / $per_page);

/* ---------- Fetch paginated rows ---------- */
$sql = "SELECT o.*, COALESCE(SUM(oi.qty), 0) AS item_count
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE $where
        GROUP BY o.id
        ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
$stm = $pdo->prepare($sql);

// Bind all parameters positionally with correct types
foreach ($params as $key => $val) $stm->bindValue($key + 1, $val);
$stm->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
$stm->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stm->execute();
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Badge & dropdown class helpers ---------- */
function status_badge_class($s){
  switch ($s) {
    case 'pending':    return 'bg-warning text-dark';
    case 'processing': return 'bg-info text-dark';
    case 'shipped':    return 'bg-primary';
    case 'delivered':  return 'bg-success';
    case 'cancelled':  return 'bg-secondary';
    default:           return 'bg-light text-dark';
  }
}
function status_option_class($s){
  switch ($s) {
    case 'pending':    return 'text-warning';
    case 'processing': return 'text-info';
    case 'shipped':    return 'text-primary';
    case 'delivered':  return 'text-success';
    case 'cancelled':  return 'text-secondary';
    default:           return 'text-body';
  }
}

/* ---------- Now render page ---------- */
require_once __DIR__ . '/header.php';
?>
<style>
  /* Mobile card view */
  @media (max-width:576px){
    .table-responsive{border:0}
    table.table{display:block}
    table.table thead{display:none}
    table.table tbody{display:block}
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
    .td-actions{ display:flex; flex-direction:column; gap:8px; }
    .td-actions .btn{ width:100%; }
    .status-wrap{ display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .status-wrap .form-select{ min-width:150px; }
  }
  .badge-status{ min-width:100px; display:inline-flex; justify-content:center; align-items:center; }
</style>

<?php if(!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert alert-<?php echo $f['ok']?'success':'danger'; ?> alert-dismissible fade show" role="alert">
    <?php echo h($f['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0">অর্ডার ব্যবস্থাপনা</h5>
  <a class="btn btn-outline-secondary" href="orders.php?from=<?php echo h(today()); ?>&to=<?php echo h(today()); ?>&status=all">
    আজকের অর্ডার
  </a>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-12 col-md-3">
    <input class="form-control" name="q" placeholder="Order # / Customer / Mobile" value="<?php echo h($q); ?>">
  </div>
  <div class="col-6 col-md-2">
    <input type="date" class="form-control" name="from" value="<?php echo h($from); ?>">
  </div>
  <div class="col-6 col-md-2">
    <input type="date" class="form-control" name="to" value="<?php echo h($to); ?>">
  </div>
  <div class="col-6 col-md-2">
    <select class="form-select" name="status">
      <option value="all" <?php echo $fstat==='all'?'selected':''; ?>>সব স্ট্যাটাস</option>
      <?php foreach($STATUSES as $s): ?>
        <option value="<?php echo h($s); ?>" <?php echo $fstat===$s?'selected':''; ?>>
          <?php echo ucfirst($s); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-md-3 d-grid">
    <button class="btn btn-outline-secondary">ফিল্টার</button>
  </div>
</form>

<div class="card"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-striped align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>গ্রাহক</th>
          <th>মোবাইল</th>
          <th class="text-center">আইটেম</th>
          <th class="text-end">মোট</th>
          <th style="min-width:240px">স্ট্যাটাস</th>
          <th>তারিখ</th>
          <th class="text-end">অ্যাকশন</th>
        </tr>
      </thead>
      <tbody>
      <?php $returnQS = h($_SERVER['QUERY_STRING'] ?? ''); ?>
      <?php foreach($rows as $o): ?>
        <tr>
          <td data-label="অর্ডার">#<?php echo h($o['order_code']); ?></td>
          <td data-label="গ্রাহক" class="fw-semibold"><?php echo h($o['customer_name']); ?></td>
          <td data-label="মোবাইল"><?php echo h($o['mobile']); ?></td>
          <td data-label="আইটেম" class="text-center"><?php echo h((int)($o['item_count'] ?? 0)); ?></td>
          <td data-label="মোট" class="text-end"><?php echo money_bd($o['total']); ?></td>

          <td data-label="স্ট্যাটাস">
            <div class="status-wrap">
              <span class="badge badge-status <?php echo status_badge_class($o['status']); ?>">
                <?php echo ucfirst(h($o['status'])); ?>
              </span>

              <!-- Inline form: auto-submit on change -->
              <form method="post" class="d-flex gap-2 align-items-center m-0">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                <input type="hidden" name="return"   value="<?php echo $returnQS; ?>">
                <select name="status" class="form-select form-select-sm"
                        onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit();">
                  <?php foreach($STATUSES as $s): ?>
                    <option class="<?php echo status_option_class($s); ?>"
                            value="<?php echo h($s); ?>"
                            <?php echo $o['status']===$s?'selected':''; ?>>
                      <?php echo ucfirst($s); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-primary" name="update_status" value="1" type="submit">Save</button>
                <input type="hidden" name="update_status" value="1">
              </form>
            </div>
          </td>

          <td data-label="তারিখ"><?php echo dateBD(h($o['created_at'])); ?></td>
          <td data-label="অ্যাকশন" class="text-end td-actions">
			<a class="btn btn-sm btn-outline-warning"
			   href="order_edit.php?id=<?php echo (int)$o['id']; ?>&return=<?php echo urlencode($_SERVER['QUERY_STRING'] ?? ''); ?>">
			  Edit
			</a>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="order_print.php?id=<?php echo (int)$o['id']; ?>">Print</a>
            <a class="btn btn-sm btn-outline-primary" href="order_view.php?id=<?php echo (int)$o['id']; ?>">View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted">কোন অর্ডার পাওয়া যায়নি</td></tr>
        <tr><td colspan="8" class="text-center text-muted">কোন অর্ডার পাওয়া যায়নি</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
    <div class="card-footer">
      <?php render_pagination($page, $total_pages, $current_url_params); ?>
    </div>
  <?php endif; ?>
</div></div>

<script>
// সেফ: কিছু ব্রাউজারে requestSubmit না থাকলে submit ব্যবহার
document.querySelectorAll('form select[name="status"]').forEach(function(sel){
  sel.addEventListener('change', function(){
    var f = sel.form;
    if (!f) return;
    // hidden input ensure (older Safari ইত্যাদি)
    if (!f.querySelector('input[name="update_status"]')) {
      var h = document.createElement('input');
      h.type='hidden'; h.name='update_status'; h.value='1';
      f.appendChild(h);
    }
    if (f.requestSubmit) f.requestSubmit();
    else f.submit();
  });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
