<?php
// admin/announcements.php — ঘোষণা বার ও পপআপের তালিকা, চালু/বন্ধ, মুছুন
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/announcements.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_announcements');

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'সিকিউরিটি টোকেন মিলেনি'];
    header('Location: announcements.php'); exit;
  }
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
  $id     = is_scalar($_POST['id'] ?? null) ? (int)$_POST['id'] : 0;

  $res = [false, 'অজানা অ্যাকশন।'];
  if ($action === 'delete')     $res = ann_delete($pdo, $id);
  elseif ($action === 'toggle') $res = ann_toggle($pdo, $id);

  $_SESSION['flash'] = ['ok'=>(bool)$res[0], 'msg'=>$res[1]];
  header('Location: announcements.php'); exit;
}

$rows   = ann_all($pdo);
$kinds  = ann_kinds();
$colors = ann_colors();
$modes  = ann_reshow_modes();
$curBar = ann_current($pdo, 'bar');
$curPop = ann_current($pdo, 'popup');

function ann_state_badge(array $a, ?array $cur): string {
  if ((int)$a['active'] !== 1) return '<span class="badge text-bg-secondary">বন্ধ</span>';
  if (!ann_is_live($a)) {
    $future = !empty($a['start_at']) && strtotime((string)$a['start_at']) > time();
    return '<span class="badge text-bg-warning">' . ($future ? 'শিডিউল করা' : 'মেয়াদ শেষ') . '</span>';
  }
  if ($cur && (int)$cur['id'] === (int)$a['id']) return '<span class="badge text-bg-success">এখন দেখাচ্ছে</span>';
  return '<span class="badge text-bg-light border">অপেক্ষায় (নতুনটি আগে)</span>';
}

require_once __DIR__.'/header.php';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h4 class="mb-1">ঘোষণা</h4>
    <div class="text-muted small">
      প্রতি ধরনে একটিই দেখায় — চালু ও সময়সীমার ভেতরের সবচেয়ে নতুনটি।
    </div>
  </div>
  <a class="btn btn-primary" href="announcement_form.php" id="btnNewAnn"><i class="fa-solid fa-plus"></i> নতুন ঘোষণা</a>
</div>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2" id="annFlash">
    <?php echo h($f['msg'] ?? $f['message'] ?? ''); ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="annTable">
        <thead>
          <tr>
            <th style="width:150px">ধরন</th>
            <th>বার্তা</th>
            <th style="width:210px">সময়সীমা</th>
            <th style="width:170px">অবস্থা</th>
            <th style="width:90px">চালু</th>
            <th style="width:170px" class="text-end">অ্যাকশন</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">এখনো কোনো ঘোষণা নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $a): $rid=(int)$a['id']; $cur = $a['kind']==='bar' ? $curBar : $curPop; ?>
          <tr data-id="<?php echo $rid; ?>" data-kind="<?php echo h($a['kind']); ?>">
            <td>
              <span class="badge <?php echo $a['kind']==='bar'?'text-bg-primary':'text-bg-info'; ?>">
                <?php echo $a['kind']==='bar' ? 'বার' : 'পপআপ'; ?>
              </span>
              <?php if ($a['kind']==='popup'): ?>
                <div class="small text-muted"><?php echo (int)$a['delay_sec']; ?> সেকেন্ড পর</div>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold"><?php echo h($a['kind']==='popup' && $a['title']!=='' ? $a['title'] : mb_strimwidth((string)$a['message'], 0, 80, '…', 'UTF-8')); ?></div>
              <div class="small text-muted">
                <?php echo h($modes[$a['reshow']] ?? ''); ?><?php echo $a['reshow']==='days' ? ' ('.(int)$a['reshow_days'].' দিন)' : ''; ?>
              </div>
            </td>
            <td class="small">
              <?php echo $a['start_at'] ? h(date('d M Y, h:i A', strtotime($a['start_at']))) : 'এখনই'; ?><br>
              <?php echo $a['end_at']   ? h(date('d M Y, h:i A', strtotime($a['end_at'])))   : 'সীমাহীন'; ?>
            </td>
            <td><?php echo ann_state_badge($a, $cur); ?></td>
            <td>
              <form method="post" class="d-inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?php echo $rid; ?>">
                <button class="btn btn-sm <?php echo $a['active']?'btn-success':'btn-outline-secondary'; ?>"><?php echo $a['active'] ? 'On' : 'Off'; ?></button>
              </form>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="announcement_form.php?id=<?php echo $rid; ?>">Edit</a>
              <form method="post" class="d-inline" onsubmit="return confirm('ঘোষণাটি মুছে ফেলবেন?')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo $rid; ?>">
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

<div class="alert alert-light border mt-4 small">
  <strong>নিয়ম:</strong> বার সব পেজের ওপরে দেখায়, পপআপ শুধু হোমপেজে।
  দর্শক কেটে দিলে আবার কখন দেখাবে তা প্রতিটি ঘোষণায় আলাদা ঠিক করা যায়।
  লেখা এডিট করলে আগে কেটে দেওয়া দর্শকেরাও নতুন করে দেখবেন।
</div>

<?php require_once __DIR__.'/footer.php'; ?>
