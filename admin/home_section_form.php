<?php
// admin/home_section_form.php — হোম সেকশন যোগ/এডিট + (manual হলে) সেকশনের পণ্য বাছাই
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/home_sections.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_home_sections');

$pdo = get_pdo();

$id   = is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0;
$item = [
  'id' => 0, 'title' => '', 'subtitle' => '', 'type' => 'manual', 'category_id' => null,
  'product_limit' => 12, 'active' => 1, 'show_see_all' => 1, 'new_default' => 0,
];

if ($id > 0) {
  $found = hs_get($pdo, $id);
  if (!$found) { $_SESSION['flash']=['ok'=>false,'msg'=>'সেকশন পাওয়া যায়নি']; header('Location: home_sections.php'); exit; }
  $item = array_merge($item, $found);
}

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$types      = hs_types();
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'সিকিউরিটি টোকেন মিলেনি'];
    header('Location: home_sections.php'); exit;
  }
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'save';

  /* ---- manual সেকশনে পণ্য যোগ / বাদ ---- */
  if ($id > 0 && in_array($action, ['add_product', 'remove_product'], true) && $item['type'] === 'manual') {
    $pid = is_scalar($_POST['product_id'] ?? null) ? (int)$_POST['product_id'] : 0;
    if ($pid > 0) {
      if ($action === 'add_product') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id=?");
        $chk->execute([$pid]);
        if ((int)$chk->fetchColumn()) {
          $pdo->prepare("INSERT IGNORE INTO home_section_products(section_id, product_id, sort_order) VALUES(?,?,0)")->execute([$id, $pid]);
          $_SESSION['flash'] = ['ok'=>true, 'msg'=>'পণ্য সেকশনে যোগ হয়েছে।'];
        }
      } else {
        $pdo->prepare("DELETE FROM home_section_products WHERE section_id=? AND product_id=?")->execute([$id, $pid]);
        $_SESSION['flash'] = ['ok'=>true, 'msg'=>'পণ্য সেকশন থেকে বাদ দেওয়া হয়েছে।'];
      }
    }
    header('Location: home_section_form.php?id='.$id); exit;
  }

  /* ---- সংরক্ষণ ---- */
  [$clean, $err] = hs_clean_input($pdo, $_POST);
  if ($err) {
    $errors[] = $err;
    $item = array_merge($item, [
      'title' => is_scalar($_POST['title'] ?? null) ? (string)$_POST['title'] : '',
      'subtitle' => is_scalar($_POST['subtitle'] ?? null) ? (string)$_POST['subtitle'] : '',
      'type' => is_scalar($_POST['type'] ?? null) ? (string)$_POST['type'] : 'manual',
      'category_id' => is_scalar($_POST['category_id'] ?? null) ? (int)$_POST['category_id'] : null,
      'product_limit' => is_scalar($_POST['product_limit'] ?? null) ? (int)$_POST['product_limit'] : 12,
      'active' => !empty($_POST['active']) ? 1 : 0,
      'show_see_all' => !empty($_POST['show_see_all']) ? 1 : 0,
      'new_default' => !empty($_POST['new_default']) ? 1 : 0,
    ]);
  } else {
    if ($id > 0) { [$ok, $msg] = hs_update($pdo, $id, $clean); $newId = $id; }
    else         { [$ok, $msg, $newId] = hs_create($pdo, $clean); }
    if ($ok) {
      $_SESSION['flash'] = ['ok'=>true, 'msg'=>$msg];
      header('Location: home_sections.php'); exit;
    }
    $errors[] = $msg;
    $item = array_merge($item, $clean);
  }
}

$secProducts = [];
$addable     = [];
if ($id > 0 && $item['type'] === 'manual') {
  $st = $pdo->prepare("SELECT p.id, p.name, p.active FROM home_section_products sp
                       JOIN products p ON p.id = sp.product_id
                       WHERE sp.section_id = ? ORDER BY sp.sort_order ASC, p.id DESC");
  $st->execute([$id]);
  $secProducts = $st->fetchAll();
  $st = $pdo->prepare("SELECT id, name FROM products WHERE active=1
                       AND id NOT IN (SELECT product_id FROM home_section_products WHERE section_id = ?)
                       ORDER BY name LIMIT 500");
  $st->execute([$id]);
  $addable = $st->fetchAll();
}

require_once __DIR__.'/header.php';
?>
<h4 class="mb-3"><?php echo $id ? 'সেকশন এডিট' : 'নতুন সেকশন'; ?></h4>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2"><?php echo h($f['msg'] ?? ''); ?></div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" class="row g-3" id="hsForm">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="save">

  <div class="col-md-6">
    <label class="form-label">শিরোনাম <span class="text-danger">*</span></label>
    <input class="form-control" name="title" maxlength="120" required value="<?php echo h($item['title']); ?>"
           placeholder="যেমন: Hot Deals, ছেলেদের খেলনা">
  </div>

  <div class="col-md-6">
    <label class="form-label">সাবটাইটেল (ঐচ্ছিক)</label>
    <input class="form-control" name="subtitle" maxlength="255" value="<?php echo h((string)$item['subtitle']); ?>">
  </div>

  <div class="col-md-4">
    <label class="form-label">সেকশনের ধরন</label>
    <select class="form-select" name="type" id="hsType">
      <?php foreach ($types as $k => [$label, $desc]): ?>
        <option value="<?php echo h($k); ?>" <?php echo $item['type'] === $k ? 'selected' : ''; ?>>
          <?php echo h($label); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text" id="hsTypeHelp"></div>
  </div>

  <div class="col-md-4" id="hsCatWrap">
    <label class="form-label">ক্যাটেগরি</label>
    <select class="form-select" name="category_id">
      <option value="">— বাছুন —</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$item['category_id'] === (int)$c['id'] ? 'selected' : ''; ?>>
          <?php echo h($c['name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-4">
    <label class="form-label">কতটি পণ্য দেখাবে (<?php echo HS_LIMIT_MIN; ?>–<?php echo HS_LIMIT_MAX; ?>)</label>
    <input type="number" class="form-control" name="product_limit" id="hsLimit"
           min="<?php echo HS_LIMIT_MIN; ?>" max="<?php echo HS_LIMIT_MAX; ?>" required
           value="<?php echo (int)$item['product_limit']; ?>">
  </div>

  <div class="col-12">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" name="show_see_all" value="1" id="seeAllChk" <?php echo $item['show_see_all']?'checked':''; ?>>
      <label class="form-check-label" for="seeAllChk">“সব দেখুন” বাটন দেখান</label>
    </div>
  </div>

  <div class="col-12" id="hsNewDefaultWrap">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" name="new_default" value="1" id="newDefaultChk" <?php echo !empty($item['new_default'])?'checked':''; ?>>
      <label class="form-check-label" for="newDefaultChk">নতুন পণ্য যোগ করার সময় এই সেকশনে ডিফল্টে টিক থাকবে</label>
    </div>
  </div>

  <div class="col-12">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" name="active" value="1" id="activeChk" <?php echo $item['active']?'checked':''; ?>>
      <label class="form-check-label" for="activeChk">চালু (হোমপেজে দেখাবে)</label>
    </div>
  </div>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> সংরক্ষণ</button>
    <a href="home_sections.php" class="btn btn-secondary">বাতিল</a>
  </div>
</form>

<?php if ($id > 0 && $item['type'] === 'manual'): ?>
  <hr class="my-4">
  <h5 class="mb-2">এই সেকশনের পণ্য (<?php echo count($secProducts); ?>)</h5>
  <div class="text-muted small mb-2">প্রোডাক্ট ফর্ম থেকেও টিক দিয়ে পণ্য যোগ/বাদ দেওয়া যায়। হোমে সর্বোচ্চ <?php echo (int)$item['product_limit']; ?>টি দেখায়।</div>

  <form method="post" class="d-flex gap-2 mb-3" id="hsAddProduct">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="add_product">
    <select name="product_id" class="form-select" style="max-width:420px" required>
      <option value="">— পণ্য বাছুন —</option>
      <?php foreach ($addable as $p): ?>
        <option value="<?php echo (int)$p['id']; ?>"><?php echo h($p['name']); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline-primary"><i class="fa-solid fa-plus"></i> যোগ করুন</button>
  </form>

  <?php if (!$secProducts): ?>
    <div class="alert alert-info">এখনো কোনো পণ্য নেই — পণ্য না থাকলে সেকশনটি হোমপেজে দেখাবে না।</div>
  <?php else: ?>
    <div class="card"><div class="table-responsive">
      <table class="table table-sm align-middle mb-0" id="hsProducts">
        <tbody>
        <?php foreach ($secProducts as $p): ?>
          <tr>
            <td>#<?php echo (int)$p['id']; ?></td>
            <td><?php echo h($p['name']); ?><?php echo (int)$p['active'] ? '' : ' <span class="badge text-bg-secondary">বন্ধ</span>'; ?></td>
            <td class="text-end">
              <form method="post" class="d-inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remove_product">
                <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                <button class="btn btn-sm btn-outline-danger">বাদ দিন</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  <?php endif; ?>
<?php endif; ?>

<script>
(function(){
  var help = <?php echo json_encode(array_map(fn($t) => $t[1], $types), JSON_UNESCAPED_UNICODE); ?>;
  var sel = document.getElementById('hsType');
  var cat = document.getElementById('hsCatWrap');
  var txt = document.getElementById('hsTypeHelp');
  function sync(){
    cat.style.display = sel.value === 'category' ? '' : 'none';
    document.getElementById('hsNewDefaultWrap').style.display = sel.value === 'manual' ? '' : 'none';
    txt.textContent = help[sel.value] || '';
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>

<?php require_once __DIR__.'/footer.php'; ?>
