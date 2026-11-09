<?php
// admin/announcement_form.php — ঘোষণা (বার / পপআপ) যোগ ও এডিট
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/announcements.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_announcements');

$pdo = get_pdo();

$id   = is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0;
$item = [
  'id'=>0, 'kind'=>'bar', 'title'=>'', 'message'=>'', 'link_url'=>'', 'btn_label'=>'', 'image'=>'',
  'color'=>'blue', 'closable'=>1, 'delay_sec'=>3, 'reshow'=>'days', 'reshow_days'=>7,
  'start_at'=>null, 'end_at'=>null, 'active'=>1,
];
if (is_string($_GET['kind'] ?? null) && isset(ann_kinds()[$_GET['kind']])) $item['kind'] = $_GET['kind'];

if ($id > 0) {
  $found = ann_get($pdo, $id);
  if (!$found) { $_SESSION['flash']=['ok'=>false,'msg'=>'ঘোষণা পাওয়া যায়নি']; header('Location: announcements.php'); exit; }
  $item = array_merge($item, $found);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'সিকিউরিটি টোকেন মিলেনি'];
    header('Location: announcements.php'); exit;
  }
  [$savedId, $msg] = ann_save($pdo, $_POST, $id);
  if ($savedId) {
    $_SESSION['flash'] = ['ok'=>true, 'msg'=>$msg];
    header('Location: announcements.php'); exit;
  }
  $errors[] = $msg;
  foreach (['kind','title','message','link_url','btn_label','color','reshow','start_at','end_at'] as $k) {
    if (isset($_POST[$k]) && is_scalar($_POST[$k])) $item[$k] = (string)$_POST[$k];
  }
  $item['delay_sec']   = is_scalar($_POST['delay_sec'] ?? null) ? (int)$_POST['delay_sec'] : 3;
  $item['reshow_days'] = is_scalar($_POST['reshow_days'] ?? null) ? (int)$_POST['reshow_days'] : 7;
  $item['closable']    = !empty($_POST['closable']) ? 1 : 0;
  $item['active']      = !empty($_POST['active']) ? 1 : 0;
}

$kinds  = ann_kinds();
$colors = ann_colors();
$modes  = ann_reshow_modes();
$dtVal  = function ($v) { $t = $v ? strtotime(str_replace('T', ' ', (string)$v)) : 0; return $t ? date('Y-m-d\TH:i', $t) : ''; };
$imgUrl = !empty($item['image']) ? img_url((string)$item['image'], '') : '';

require_once __DIR__.'/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0"><?php echo $id ? 'ঘোষণা এডিট' : 'নতুন ঘোষণা'; ?></h4>
  <a class="btn btn-outline-secondary btn-sm" href="announcements.php">← তালিকায় ফিরুন</a>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger py-2" id="annError"><?php echo h($e); ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" id="annForm" class="card">
  <?php echo csrf_field(); ?>
  <div class="card-body">
    <div class="row g-3">

      <div class="col-12 col-md-6">
        <label class="form-label">ধরন</label>
        <select class="form-select" name="kind" id="annKind">
          <?php foreach ($kinds as $k => $lbl): ?>
            <option value="<?php echo h($k); ?>" <?php echo $item['kind']===$k?'selected':''; ?>><?php echo h($lbl); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-6 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="active" id="annActive" value="1" <?php echo $item['active']?'checked':''; ?>>
          <label class="form-check-label" for="annActive">চালু</label>
        </div>
      </div>

      <div class="col-12 ann-popup-only">
        <label class="form-label">শিরোনাম</label>
        <input class="form-control" name="title" maxlength="160" value="<?php echo h($item['title']); ?>" placeholder="যেমন: ঈদ সেল শুরু!">
      </div>

      <div class="col-12">
        <label class="form-label" id="annMsgLabel">বার্তা</label>
        <textarea class="form-control" name="message" id="annMessage" rows="3" maxlength="2000"><?php echo h($item['message']); ?></textarea>
        <div class="form-text ann-bar-only">বারে সর্বোচ্চ ২০০ অক্ষর, এক লাইনে দেখায়।</div>
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">লিংক (ঐচ্ছিক)</label>
        <input class="form-control" name="link_url" maxlength="500" value="<?php echo h($item['link_url']); ?>" placeholder="shop.php?section=1 অথবা https://...">
      </div>

      <div class="col-12 col-md-6 ann-popup-only">
        <label class="form-label">বাটনের লেখা (লিংক থাকলে)</label>
        <input class="form-control" name="btn_label" maxlength="60" value="<?php echo h($item['btn_label']); ?>" placeholder="যেমন: এখনই কিনুন">
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">রং</label>
        <select class="form-select" name="color" id="annColor">
          <?php foreach ($colors as $k => $c): ?>
            <option value="<?php echo h($k); ?>" <?php echo $item['color']===$k?'selected':''; ?>><?php echo h($c[0]); ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text ann-popup-only">পপআপে এই রং বাটনে লাগে।</div>
      </div>

      <div class="col-12 col-md-6 ann-bar-only">
        <div class="d-flex align-items-end h-100">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="closable" id="annClosable" value="1" <?php echo $item['closable']?'checked':''; ?>>
            <label class="form-check-label" for="annClosable">✕ বাটন থাকবে (দর্শক বন্ধ করতে পারবে)</label>
          </div>
        </div>
      </div>

      <div class="col-12 ann-popup-only">
        <label class="form-label">ছবি (ঐচ্ছিক, সর্বোচ্চ ৫MB)</label>
        <?php if ($imgUrl): ?>
          <div class="mb-2">
            <img src="<?php echo h($imgUrl); ?>" alt="" style="max-width:220px;border-radius:10px;border:1px solid #e5e7eb" id="annCurImg">
            <div class="form-check mt-1">
              <input class="form-check-input" type="checkbox" name="remove_image" id="annRemoveImg" value="1">
              <label class="form-check-label" for="annRemoveImg">ছবি সরিয়ে ফেলুন</label>
            </div>
          </div>
        <?php endif; ?>
        <input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
        <div class="form-text">ছবির অনুপাত যেমন আছে তেমনই দেখাবে (কাটা হয় না); ৯০০px-এর বেশি চওড়া হলে ছোট করা হয়।</div>
      </div>

      <div class="col-12 col-md-4 ann-popup-only">
        <label class="form-label">কত সেকেন্ড পর দেখাবে</label>
        <input class="form-control" type="number" min="0" max="<?php echo ANN_DELAY_MAX; ?>" name="delay_sec" id="annDelay" value="<?php echo (int)$item['delay_sec']; ?>">
        <div class="form-text">০ = পেজ খুললেই। সর্বোচ্চ <?php echo ANN_DELAY_MAX; ?>।</div>
      </div>

      <div class="col-12 col-md-5">
        <label class="form-label">দর্শক কেটে দিলে আবার দেখাবে?</label>
        <select class="form-select" name="reshow" id="annReshow">
          <?php foreach ($modes as $k => $lbl): ?>
            <option value="<?php echo h($k); ?>" <?php echo $item['reshow']===$k?'selected':''; ?>><?php echo h($lbl); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-3" id="annDaysWrap">
        <label class="form-label">কত দিন পর</label>
        <input class="form-control" type="number" min="1" max="<?php echo ANN_DAYS_MAX; ?>" name="reshow_days" id="annDays" value="<?php echo (int)$item['reshow_days']; ?>">
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">শুরু (ঐচ্ছিক)</label>
        <input class="form-control" type="datetime-local" name="start_at" value="<?php echo h($dtVal($item['start_at'])); ?>">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">শেষ (ঐচ্ছিক)</label>
        <input class="form-control" type="datetime-local" name="end_at" value="<?php echo h($dtVal($item['end_at'])); ?>">
      </div>

    </div>
  </div>
  <div class="card-footer d-flex gap-2">
    <button class="btn btn-primary" name="save_announcement" value="1" id="annSave">সংরক্ষণ</button>
    <a class="btn btn-outline-secondary" href="announcements.php">বাতিল</a>
  </div>
</form>

<script>
(function(){
  var kind = document.getElementById('annKind'), reshow = document.getElementById('annReshow');
  function sync(){
    var popup = kind.value === 'popup';
    document.querySelectorAll('.ann-popup-only').forEach(function(el){ el.hidden = !popup; });
    document.querySelectorAll('.ann-bar-only').forEach(function(el){ el.hidden = popup; });
    document.getElementById('annMessage').rows = popup ? 4 : 2;
    document.getElementById('annDaysWrap').hidden = reshow.value !== 'days';
  }
  kind.addEventListener('change', sync); reshow.addEventListener('change', sync); sync();
})();
</script>

<?php require_once __DIR__.'/footer.php'; ?>
