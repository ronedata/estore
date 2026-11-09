<?php
// admin/delivery.php — জেলা-ভিত্তিক ডেলিভারি চার্জ (ফাঁকা = গ্লোবাল, 0 = ফ্রি)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/delivery.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_delivery');

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'সিকিউরিটি টোকেন মিলেনি'];
    header('Location: delivery.php'); exit;
  }
  $scope = is_string($_POST['scope'] ?? null) ? $_POST['scope'] : '';
  $fees  = (isset($_POST['fee']) && is_array($_POST['fee'])) ? $_POST['fee'] : [];
  if ($scope === 'global')    [$ok, $msg] = dl_save_global($pdo, $_POST['global_fee'] ?? '');
  elseif ($scope === 'fees')  [$ok, $msg] = dl_save_fees($pdo, $fees);
  else                        [$ok, $msg] = [false, 'অজানা অ্যাকশন।'];
  $_SESSION['flash'] = ['ok'=>$ok, 'msg'=>$msg];
  header('Location: delivery.php'); exit;
}

$rows    = dl_all($pdo);
$global  = dl_global($pdo);
$divs    = dl_divisions();
$custom  = count(array_filter($rows, fn($r) => $r['fee'] !== null));
$fmt     = fn($n) => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');

require_once __DIR__.'/header.php';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h4 class="mb-1">ডেলিভারি চার্জ</h4>
    <div class="text-muted small">
      ক্রেতা চেকআউটে জেলা বাছলে সেই জেলার চার্জ যোগ হয়। জেলার চার্জ ফাঁকা থাকলে <strong>গ্লোবাল চার্জ</strong> প্রযোজ্য।
    </div>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2" id="dlFlash">
    <?php echo h($f['msg'] ?? $f['message'] ?? ''); ?>
  </div>
<?php endif; ?>

<form method="post" id="dlGlobalForm">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="scope" value="global">
  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label fw-semibold">গ্লোবাল (ডিফল্ট) চার্জ — টাকা</label>
          <div class="input-group">
            <input type="number" min="0" max="<?php echo DL_FEE_MAX; ?>" step="1" inputmode="numeric" class="form-control"
                   name="global_fee" id="dlGlobal" value="<?php echo h($fmt($global)); ?>" required>
            <button class="btn btn-primary" id="dlSaveGlobal" type="submit">সংরক্ষণ</button>
          </div>
          <div class="form-text">যে জেলার আলাদা চার্জ ফাঁকা, সেখানে এটাই লাগবে। শুধু এটি সংরক্ষণ হবে, জেলার তালিকা নয়।</div>
        </div>
        <div class="col-12 col-md-8">
          <div class="small text-muted">
            <span class="badge text-bg-primary">ফাঁকা</span> = গ্লোবাল চার্জ &nbsp;
            <span class="badge text-bg-success">0</span> = ওই জেলায় <strong>ফ্রি ডেলিভারি</strong> &nbsp;
            · আলাদা চার্জ আছে: <strong id="dlCustomCount"><?php echo $custom; ?></strong>/<?php echo count($rows); ?> জেলায়
          </div>
          <div class="small text-muted mt-1">কার্টের সব পণ্যে <code>Free</code> ট্যাগ থাকলে জেলা যাই হোক চার্জ ০।</div>
        </div>
      </div>
    </div>
  </div>
</form>

<form method="post" id="dlForm">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="scope" value="fees">

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label small mb-1">জেলা খুঁজুন</label>
          <input type="search" class="form-control" id="dlSearch" placeholder="জেলার নাম (বাংলা/English)">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1">বিভাগ</label>
          <select class="form-select" id="dlDivision">
            <option value="">সব বিভাগ</option>
            <?php foreach ($divs as $d): ?><option value="<?php echo h($d); ?>"><?php echo h($d); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">এই বিভাগের সবাইকে</label>
          <input type="number" min="0" max="<?php echo DL_FEE_MAX; ?>" class="form-control" id="dlBulkAmount" placeholder="টাকা">
        </div>
        <div class="col-12 col-md-3 d-flex gap-2">
          <button type="button" class="btn btn-outline-primary" id="dlBulkApply" title="শুধু ফর্মে বসায়, সংরক্ষণ করতে হবে">প্রয়োগ</button>
          <button type="button" class="btn btn-outline-secondary" id="dlBulkClear" title="বিভাগের সব জেলা ফাঁকা (গ্লোবাল) করে">ফাঁকা করুন</button>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="dlTable">
          <thead>
            <tr>
              <th style="width:60px">#</th>
              <th>জেলা</th>
              <th style="width:150px" class="d-none d-md-table-cell">বিভাগ</th>
              <th style="width:200px">চার্জ (টাকা)</th>
              <th style="width:170px">কার্যকর চার্জ</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $i => $r): $rid=(int)$r['id']; ?>
            <tr data-division="<?php echo h($r['division']); ?>"
                data-q="<?php echo h(mb_strtolower($r['name_bn'].' '.$r['name_en'], 'UTF-8')); ?>">
              <td class="text-muted"><?php echo $i + 1; ?></td>
              <td>
                <span class="fw-semibold"><?php echo h($r['name_bn']); ?></span>
                <span class="small text-muted"><?php echo h($r['name_en']); ?></span>
              </td>
              <td class="small d-none d-md-table-cell"><?php echo h($r['division']); ?></td>
              <td>
                <input type="number" min="0" max="<?php echo DL_FEE_MAX; ?>" step="1" inputmode="numeric"
                       class="form-control form-control-sm dl-fee" name="fee[<?php echo $rid; ?>]"
                       value="<?php echo $r['fee'] === null ? '' : h($fmt($r['fee'])); ?>" placeholder="গ্লোবাল">
              </td>
              <td class="dl-eff small"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer d-flex gap-2 align-items-center">
      <button class="btn btn-primary" id="dlSave" name="save_delivery" value="1"><i class="fa-solid fa-floppy-disk"></i> জেলার চার্জ সংরক্ষণ</button>
      <span class="small text-muted" id="dlShown"></span>
    </div>
  </div>
</form>

<script>
(function(){
  var rows = [].slice.call(document.querySelectorAll('#dlTable tbody tr'));
  var g = document.getElementById('dlGlobal');
  function fmt(n){ n = Number(n) || 0; return '৳' + Math.round(n).toLocaleString('bn-BD'); }
  function eff(tr){
    var v = tr.querySelector('.dl-fee').value.trim(), cell = tr.querySelector('.dl-eff');
    if (v === '') { cell.innerHTML = fmt(g.value) + ' <span class="badge text-bg-primary">গ্লোবাল</span>'; }
    else if (Number(v) === 0) { cell.innerHTML = '৳০ <span class="badge text-bg-success">ফ্রি</span>'; }
    else { cell.textContent = fmt(v); }
  }
  function refresh(){
    rows.forEach(eff);
    document.getElementById('dlCustomCount').textContent = rows.filter(function(t){ return t.querySelector('.dl-fee').value.trim() !== ''; }).length;
  }
  function filter(){
    var q = document.getElementById('dlSearch').value.trim().toLowerCase(), dv = document.getElementById('dlDivision').value, n = 0;
    rows.forEach(function(t){
      var show = (!q || t.dataset.q.indexOf(q) !== -1) && (!dv || t.dataset.division === dv);
      t.hidden = !show; if (show) n++;
    });
    document.getElementById('dlShown').textContent = n + 'টি জেলা দেখাচ্ছে';
  }
  function bulk(val){
    var dv = document.getElementById('dlDivision').value;
    if (!dv) { alert('আগে একটি বিভাগ বাছুন।'); return; }
    rows.forEach(function(t){ if (t.dataset.division === dv) t.querySelector('.dl-fee').value = val; });
    refresh();
  }
  document.getElementById('dlBulkApply').addEventListener('click', function(){
    var a = document.getElementById('dlBulkAmount').value.trim();
    if (a === '' || Number(a) < 0) { alert('টাকার সংখ্যা দিন।'); return; }
    bulk(a);
  });
  document.getElementById('dlBulkClear').addEventListener('click', function(){ bulk(''); });
  document.getElementById('dlSearch').addEventListener('input', filter);
  document.getElementById('dlDivision').addEventListener('change', filter);
  g.addEventListener('input', refresh);
  document.getElementById('dlTable').addEventListener('input', function(e){ if (e.target.classList.contains('dl-fee')) refresh(); });
  refresh(); filter();
})();
</script>

<?php require_once __DIR__.'/footer.php'; ?>
