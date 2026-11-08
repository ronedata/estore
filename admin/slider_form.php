<?php
// admin/slider_form.php — create/edit hero & side slider
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/image_processor.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_sliders');

$pdo = get_pdo();

$id   = (int)($_GET['id'] ?? 0);
$item = [
  'id' => 0, 'placement' => 'hero', 'image' => '', 'title' => '', 'subtitle' => '',
  'btn_label' => 'Shop Now', 'btn_bg' => '#ff5252', 'link_type' => 'product',
  'link_value' => '', 'theme' => 'purple', 'sort_order' => 0, 'active' => 1,
  'btn_size' => 'md', 'btn_pos_x' => 'center', 'btn_pos_y' => 'bottom',
  'show_text' => 0,
];

if ($id > 0) {
  $stm = $pdo->prepare("SELECT * FROM sliders WHERE id=?");
  $stm->execute([$id]);
  $found = $stm->fetch();
  if (!$found) { $_SESSION['flash']=['ok'=>false,'msg'=>'স্লাইডার পাওয়া যায়নি']; header('Location: sliders.php'); exit; }
  $item = array_merge($item, $found);
}

$products   = $pdo->query("SELECT id, name FROM products WHERE active=1 ORDER BY name LIMIT 500")->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $errors[] = 'CSRF token mismatch';
  } else {
    $placement  = in_array($_POST['placement'] ?? '', ['hero','side'], true) ? $_POST['placement'] : 'hero';
    $title      = trim($_POST['title'] ?? '');
    $subtitle   = trim($_POST['subtitle'] ?? '');
    $btn_label  = trim($_POST['btn_label'] ?? 'Shop Now') ?: 'Shop Now';
    $btn_bg     = trim($_POST['btn_bg'] ?? '#ff5252') ?: '#ff5252';
    $link_type  = in_array($_POST['link_type'] ?? '', ['product','category','url'], true) ? $_POST['link_type'] : 'product';
    $link_value = trim($_POST['link_value'] ?? '');
    $theme      = trim($_POST['theme'] ?? 'purple') ?: 'purple';
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $active     = !empty($_POST['active']) ? 1 : 0;
    $btn_size   = in_array($_POST['btn_size'] ?? '', ['sm','md','lg'], true) ? $_POST['btn_size'] : 'md';
    $btn_pos_x  = in_array($_POST['btn_pos_x'] ?? '', ['left','center','right'], true) ? $_POST['btn_pos_x'] : 'center';
    $btn_pos_y  = in_array($_POST['btn_pos_y'] ?? '', ['top','middle','bottom'], true) ? $_POST['btn_pos_y'] : 'bottom';
    $show_text  = !empty($_POST['show_text']) ? 1 : 0;

    // লিংক ভ্যালু ভ্যালিডেশন
    if ($link_type === 'product' && !ctype_digit((string)$link_value)) {
      $errors[] = 'প্রোডাক্ট লিংকের জন্য বৈধ প্রোডাক্ট আইডি দিন।';
    }
    if ($link_type === 'category' && !ctype_digit((string)$link_value)) {
      $errors[] = 'ক্যাটেগরি লিংকের জন্য বৈধ ক্যাটেগরি আইডি দিন।';
    }
    if ($link_type === 'url' && $link_value !== '' && !preg_match('~^https?://~i', $link_value) && $link_value[0] !== '/') {
      $errors[] = 'URL অবশ্যই http(s):// দিয়ে শুরু হবে অথবা "/" দিয়ে শুরু হবে।';
    }

    /* ---------- ইমেজ আপলোড ---------- */
    $imageUrl = (string)($item['image'] ?? '');
    if (!empty($_FILES['image']['name'])) {
      $f = $_FILES['image'];
      if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'ইমেজ আপলোড ব্যর্থ (কোড '.$f['error'].')।';
      } else {
        $maxBytes = 4 * 1024 * 1024;
        if ($f['size'] > $maxBytes) {
          $errors[] = 'ইমেজ ৪MB এর কম হতে হবে।';
        } else {
          $info = @getimagesize($f['tmp_name']);
          $allowed = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF  => 'gif',
          ];
          if (!$info || !isset($allowed[$info[2]])) {
            $errors[] = 'শুধু JPG, PNG, WEBP, GIF ইমেজ গ্রহণ করা হয়।';
          } else {
            $ext = $allowed[$info[2]];
            $dir = __DIR__.'/../uploads/sliders';
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $name = 'slider_'.date('YmdHis').'_'.bin2hex(random_bytes(3)).'.'.$ext;
            $dest = $dir.'/'.$name;

            /* placement অনুযায়ী স্বয়ংক্রিয় রিসাইজ:
               hero → 1200×675 (16:9), side → 600×375 (16:10) */
            [$tw, $th] = estore_slider_target_size($placement);
            $proc = estore_process_image($f['tmp_name'], $dest, $tw, $th, '#ffffff');

            if (empty($proc['ok'])) {
              $errors[] = 'ইমেজ প্রসেস করা যায়নি: ' . ($proc['error'] ?: 'অজানা ত্রুটি');
            } else {
              $imageUrl = 'uploads/sliders/'.$name;
              $imgMode  = $proc['mode'];
              $imgSrc   = $proc['src_w'].'×'.$proc['src_h'];
            }
          }
        }
      }
    }

    if (!$imageUrl) { $errors[] = 'একটি ইমেজ আবশ্যক।'; }

    if (!$errors) {
      try {
        if ($id > 0) {
          $stm = $pdo->prepare("UPDATE sliders SET placement=?, image=?, title=?, subtitle=?, btn_label=?, btn_bg=?,
                                link_type=?, link_value=?, theme=?, sort_order=?, active=?,
                                btn_size=?, btn_pos_x=?, btn_pos_y=?, show_text=? WHERE id=?");
          $stm->execute([$placement,$imageUrl,$title,$subtitle,$btn_label,$btn_bg,
                         $link_type,$link_value,$theme,$sort_order,$active,
                         $btn_size,$btn_pos_x,$btn_pos_y,$show_text,$id]);
        } else {
          $stm = $pdo->prepare("INSERT INTO sliders(placement, image, title, subtitle, btn_label, btn_bg,
                                link_type, link_value, theme, sort_order, active, btn_size, btn_pos_x, btn_pos_y, show_text)
                                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
          $stm->execute([$placement,$imageUrl,$title,$subtitle,$btn_label,$btn_bg,
                         $link_type,$link_value,$theme,$sort_order,$active,
                         $btn_size,$btn_pos_x,$btn_pos_y,$show_text]);
        }
        $_SESSION['flash'] = ['ok'=>true, 'msg'=>'স্লাইডার সংরক্ষণ হয়েছে।'];
        header('Location: sliders.php'); exit;
      } catch (Throwable $e) {
        $errors[] = 'সংরক্ষণ ব্যর্থ: '.$e->getMessage();
      }
    }
  }
}

require_once __DIR__.'/header.php';
?>
<h4 class="mb-3"><?php echo $id ? 'স্লাইডার এডিট' : 'নতুন স্লাইডার'; ?></h4>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="row g-3">
  <?php echo csrf_field(); ?>

  <div class="col-md-6">
    <label class="form-label">প্লেসমেন্ট <span class="text-danger">*</span></label>
    <select name="placement" class="form-select" required id="placementSelect">
      <option value="hero" <?php echo $item['placement']==='hero'?'selected':''; ?>>হিরো স্লাইডার (বড়)</option>
      <option value="side" <?php echo $item['placement']==='side'?'selected':''; ?>>সাইড কার্ড (ছোট)</option>
    </select>
    <div class="form-text">সাইড কার্ড মোবাইলে লুকানো থাকে।</div>
  </div>

  <div class="col-md-6">
    <label class="form-label">সর্ট অর্ডার</label>
    <input type="number" name="sort_order" class="form-control" value="<?php echo (int)$item['sort_order']; ?>">
  </div>

  <div class="col-12">
    <label class="form-label">ইমেজ <?php echo $id?'(নতুন দিলে পুরনোটি বদলে যাবে)':'<span class="text-danger">*</span>'; ?></label>
    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" <?php echo $id?'':'required'; ?>>

    <div class="border rounded-3 p-3 mt-2" style="background:#f8fafc;border-color:#e2e8f0!important">
      <div class="fw-bold mb-2" style="font-size:13.5px">
        <i class="fa-solid fa-ruler-combined text-primary"></i> ছবির সাইজ
      </div>

      <div class="d-flex gap-3 flex-wrap">
        <div class="flex-shrink-0 text-center">
          <div id="sizeBox" style="width:100px;height:56px;border:2px dashed #94a3b8;border-radius:8px;
                      display:flex;align-items:center;justify-content:center;
                      background:#fff;font-size:11px;font-weight:700;color:#475569;line-height:1.25">
            <span id="sizeBoxText">1200×675</span>
          </div>
        </div>

        <div class="flex-grow-1" style="min-width:220px">
          <table class="table table-sm table-borderless mb-1" style="font-size:12px">
            <tr>
              <td class="py-0 ps-0" style="width:80px"><strong>হিরো</strong></td>
              <td class="py-0"><strong>1200 × 675 px</strong> <span class="text-muted">(16:9)</span></td>
            </tr>
            <tr>
              <td class="py-0 ps-0"><strong>সাইড</strong></td>
              <td class="py-0"><strong>600 × 375 px</strong> <span class="text-muted">(16:10)</span></td>
            </tr>
          </table>

          <ul class="mb-2 ps-3 text-muted" style="font-size:11.5px;line-height:1.6">
            <li>ফরম্যাট: <strong>JPG, PNG, WEBP, GIF</strong> · সর্বোচ্চ <strong>৪MB</strong></li>
            <li>বড় বা অসম অনুপাতের ছবি → <strong>স্বয়ংক্রিয় center-crop</strong></li>
            <li>ছোট ছবি → <strong>সাদা ক্যানভাসে মাঝ বরাবর</strong> বসবে</li>
          </ul>

          <div class="small" id="sizeHint"
               style="font-size:11.5px;color:#0369a1;background:#e0f2fe;border:1px solid #bae6fd;border-radius:8px;padding:6px 9px">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            <span id="sizeHintText">হিরো স্লাইডারের ছবি অটোমেটিক <strong>1200×675</strong> বানানো হবে।</span>
          </div>
        </div>
      </div>
    </div>

    <?php if (!empty($item['image'])): ?>
      <img src="<?php echo h(img_url($item['image'])); ?>" alt="" class="mt-2 rounded" style="max-height:120px" onerror="this.onerror=null;this.src='<?php echo h(img_fallback_url()); ?>'">
    <?php endif; ?>
  </div>

  <div class="col-md-6">
    <label class="form-label">টাইটেল (ঐচ্ছিক)</label>
    <input name="title" class="form-control" id="slideTitle" value="<?php echo h($item['title']); ?>">
  </div>
  <div class="col-md-6">
    <label class="form-label">সাবটাইটেল (ঐচ্ছিক)</label>
    <input name="subtitle" class="form-control" id="slideSubtitle" value="<?php echo h($item['subtitle']); ?>">
  </div>

  <div class="col-md-6">
    <label class="form-label d-block">টেক্সট দেখাবে?</label>
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch"
             name="show_text" value="1" id="showTextChk"
             <?php echo (int)($item['show_text'] ?? 0) === 1 ? 'checked' : ''; ?>>
      <label class="form-check-label" for="showTextChk">
        ছবির ওপর টাইটেল ও সাবটাইটেল দেখাও
      </label>
    </div>
    <div class="form-text">
      ডিফল্টে বন্ধ — শুধু ছবি ও বাটন দেখাবে। টাইটেল বা সাবটাইটেল খালি থাকলে কিছুই দেখাবে না।
    </div>
  </div>

  <div class="col-md-6 d-flex align-items-end">
    <div class="alert alert-light border w-100 mb-0 py-2 px-3" style="font-size:12.5px">
      <i class="fa-solid fa-circle-info text-primary"></i>
      <span id="textModeHint">ডিসেবল — ছবির ওপর কোনো লেখা দেখাবে না।</span>
    </div>
  </div>

  <div class="col-md-4">
    <label class="form-label">লিংক টাইপ</label>
    <select name="link_type" class="form-select" id="linkType">
      <option value="product"  <?php echo $item['link_type']==='product'?'selected':''; ?>>প্রোডাক্ট</option>
      <option value="category" <?php echo $item['link_type']==='category'?'selected':''; ?>>ক্যাটেগরি</option>
      <option value="url"      <?php echo $item['link_type']==='url'?'selected':''; ?>>কাস্টম URL</option>
    </select>
  </div>

  <div class="col-md-8">
    <label class="form-label">লিংক ভ্যালু</label>

    <div id="pickProduct">
      <select name="link_value_product" class="form-select" onchange="document.getElementById('linkValue').value=this.value">
        <option value="">— প্রোডাক্ট বেছে নিন —</option>
        <?php foreach ($products as $p): ?>
          <option value="<?php echo (int)$p['id']; ?>" <?php echo ($item['link_type']==='product' && (string)$item['link_value']===(string)$p['id'])?'selected':''; ?>>
            #<?php echo (int)$p['id']; ?> — <?php echo h($p['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="pickCategory" style="display:none">
      <select class="form-select" onchange="document.getElementById('linkValue').value=this.value">
        <option value="">— ক্যাটেগরি বেছে নিন —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo ($item['link_type']==='category' && (string)$item['link_value']===(string)$c['id'])?'selected':''; ?>>
            #<?php echo (int)$c['id']; ?> — <?php echo h($c['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <input type="text" id="linkValue" name="link_value" class="form-control mt-2"
           value="<?php echo h($item['link_value']); ?>" placeholder="আইডি বা URL" required>
  </div>

  <div class="col-md-4">
    <label class="form-label">বাটন লেবেল</label>
    <input name="btn_label" class="form-control" value="<?php echo h($item['btn_label']); ?>">
  </div>

  <div class="col-md-4">
    <label class="form-label">বাটন সাইজ</label>
    <select name="btn_size" class="form-select">
      <option value="sm" <?php echo $item['btn_size']==='sm'?'selected':''; ?>>ছোট (Small)</option>
      <option value="md" <?php echo $item['btn_size']==='md'?'selected':''; ?>>মাঝারি (Medium)</option>
      <option value="lg" <?php echo $item['btn_size']==='lg'?'selected':''; ?>>বড় (Large)</option>
    </select>
  </div>

  <div class="col-md-4">
    <label class="form-label">বাটন রঙ</label>
    <input type="color" name="btn_bg" class="form-control form-control-color" value="<?php echo h($item['btn_bg']); ?>">
  </div>

  <div class="col-12">
    <label class="form-label mb-2">বাটন পজিশন</label>
    <div class="row g-3">
      <div class="col-md-6">
        <div class="small text-muted mb-1">ডানে / বামে / মাঝে</div>
        <div class="btn-group w-100" role="group">
          <?php foreach (['left'=>'বামে','center'=>'মাঝে','right'=>'ডানে'] as $val=>$lbl): ?>
            <input type="radio" class="btn-check" name="btn_pos_x" id="posx_<?php echo $val; ?>"
                   value="<?php echo $val; ?>" <?php echo $item['btn_pos_x']===$val?'checked':''; ?>>
            <label class="btn btn-outline-primary" for="posx_<?php echo $val; ?>"><?php echo $lbl; ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-md-6">
        <div class="small text-muted mb-1">উপরে / মাঝে / নিচে</div>
        <div class="btn-group w-100" role="group">
          <?php foreach (['top'=>'উপরে','middle'=>'মাঝে','bottom'=>'নিচে'] as $val=>$lbl): ?>
            <input type="radio" class="btn-check" name="btn_pos_y" id="posy_<?php echo $val; ?>"
                   value="<?php echo $val; ?>" <?php echo $item['btn_pos_y']===$val?'checked':''; ?>>
            <label class="btn btn-outline-primary" for="posy_<?php echo $val; ?>"><?php echo $lbl; ?></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="form-text">প্রিভিউ নিচে আপডেট হবে — বাটন কোথায় বসবে দেখে নিন।</div>

    <div id="btnPreview" class="position-relative mt-2"
         style="height:170px;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;background:#f1f5f9">
      <img id="btnPreviewImg" src="<?php echo h(img_url($item['image'] ?? '', '')); ?>" alt=""
           style="width:100%;height:100%;object-fit:cover;<?php echo empty($item['image'])?'display:none':''; ?>">
      <span id="btnPreviewScrim"
            style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(15,23,42,0) 35%,rgba(15,23,42,.28) 62%,rgba(15,23,42,.72) 100%);display:none"></span>
      <div id="btnPreviewText"
           style="position:absolute;left:12px;right:12px;top:12px;z-index:2;color:#fff;text-shadow:0 2px 8px rgba(15,23,42,.45);display:none">
        <div id="btnPreviewTitle" style="font-weight:800;font-size:13px;line-height:1.3"></div>
        <div id="btnPreviewSub" style="font-size:10.5px;opacity:.92;margin-top:2px"></div>
      </div>
      <span id="btnPreviewBtn"
            style="position:absolute;padding:10px 24px;border-radius:10px;font-weight:600;color:#fff;background:<?php echo h($item['btn_bg']); ?>;z-index:3">
        <?php echo h($item['btn_label'] ?: 'Shop Now'); ?>
      </span>
    </div>
  </div>

  <div class="col-md-4">
    <label class="form-label">সাইড কার্ড থিম</label>
    <select name="theme" class="form-select">
      <?php foreach (['purple','green','blue','orange','pink'] as $t): ?>
        <option value="<?php echo $t; ?>" <?php echo $item['theme']===$t?'selected':''; ?>><?php echo ucfirst($t); ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-12">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" name="active" value="1" id="activeChk" <?php echo $item['active']?'checked':''; ?>>
      <label class="form-check-label" for="activeChk">অ্যাকটিভ</label>
    </div>
  </div>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> সংরক্ষণ</button>
    <a href="sliders.php" class="btn btn-secondary">বাতিল</a>
  </div>
</form>

<script>
(function(){
  const sel = document.getElementById('linkType');
  const p   = document.getElementById('pickProduct');
  const c   = document.getElementById('pickCategory');
  function sync(){
    p.style.display = sel.value === 'product'  ? '' : 'none';
    c.style.display = sel.value === 'category' ? '' : 'none';
  }
  sel.addEventListener('change', sync); sync();
})();

/* ---------- placement অনুযায়ী সাইজ গাইড আপডেট ---------- */
(function () {
  const sel  = document.getElementById('placementSelect');
  const box  = document.getElementById('sizeBox');
  const txt  = document.getElementById('sizeBoxText');
  const hint = document.getElementById('sizeHintText');
  if (!sel) return;

  const SPEC = {
    hero: { w: 1200, h: 675, ratio: '16:9', boxW: 100, boxH: 56, label: 'হিরো স্লাইডার' },
    side: { w: 600,  h: 375, ratio: '16:10', boxW: 100, boxH: 62, label: 'সাইড কার্ড' },
  };

  function render() {
    const s = SPEC[sel.value] || SPEC.hero;
    if (txt)  txt.textContent = s.w + '×' + s.h;
    if (box) { box.style.width = s.boxW + 'px'; box.style.height = s.boxH + 'px'; }
    if (hint) hint.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> ' +
      s.label + '-এর ছবি অটোমেটিক <strong>' + s.w + '×' + s.h + '</strong> (' + s.ratio + ') বানানো হবে।';
  }

  sel.addEventListener('change', render);
  render();
})();

/* ---------- টেক্সট দেখাবে কিনা — হিন্ট + প্রিভিউ ---------- */
(function(){
  const chk     = document.getElementById('showTextChk');
  const hint    = document.getElementById('textModeHint');
  const titleIn = document.getElementById('slideTitle');
  const subIn   = document.getElementById('slideSubtitle');
  const wrap    = document.getElementById('btnPreviewText');
  const scrim   = document.getElementById('btnPreviewScrim');
  const pvTitle = document.getElementById('btnPreviewTitle');
  const pvSub   = document.getElementById('btnPreviewSub');
  if (!chk) return;

  function render() {
    const on = chk.checked;
    if (hint) {
      hint.textContent = on
        ? 'চালু — ছবির ওপর টাইটেল ও সাবটাইটেল দেখাবে।'
        : 'বন্ধ — ছবির ওপর কোনো লেখা দেখাবে না।';
    }
    if (titleIn) titleIn.disabled = !on;
    if (subIn)   subIn.disabled   = !on;

    const t = (titleIn ? titleIn.value : '').trim();
    const s = (subIn ? subIn.value : '').trim();
    const visible = on && (t !== '' || s !== '');

    if (wrap)  wrap.style.display  = visible ? 'block' : 'none';
    if (scrim) scrim.style.display = visible ? 'block' : 'none';
    if (pvTitle) { pvTitle.textContent = t; pvTitle.style.display = t ? 'block' : 'none'; }
    if (pvSub)   { pvSub.textContent   = s; pvSub.style.display   = s ? 'block' : 'none'; }
  }

  chk.addEventListener('change', render);
  titleIn && titleIn.addEventListener('input', render);
  subIn   && subIn.addEventListener('input', render);
  render();
})();

/* ---------- বাটন প্রিভিউ ---------- */
(function(){
  const box   = document.getElementById('btnPreview');
  const btn   = document.getElementById('btnPreviewBtn');
  const img   = document.getElementById('btnPreviewImg');
  const label = document.querySelector('input[name="btn_label"]');
  const color = document.querySelector('input[name="btn_bg"]');
  const file  = document.querySelector('input[name="image"]');
  if (!box || !btn) return;

  const SIZES = {
    sm: { padding: '6px 14px', fontSize: '12px', radius: '8px' },
    md: { padding: '10px 24px', fontSize: '14px', radius: '10px' },
    lg: { padding: '14px 34px', fontSize: '17px', radius: '12px' },
  };

  function posX() {
    const v = document.querySelector('input[name="btn_pos_x"]:checked');
    return v ? v.value : 'center';
  }
  function posY() {
    const v = document.querySelector('input[name="btn_pos_y"]:checked');
    return v ? v.value : 'bottom';
  }
  function size() {
    const v = document.querySelector('input[name="btn_size"]');
    return v ? v.value : 'md';
  }

  function render() {
    const s = SIZES[size()] || SIZES.md;
    btn.style.padding  = s.padding;
    btn.style.fontSize = s.fontSize;
    btn.style.borderRadius = s.radius;
    btn.style.background = color ? color.value : '#ff5252';
    if (label) btn.textContent = label.value || 'Shop Now';

    const x = posX(), y = posY();
    btn.style.left = x === 'left' ? '16px' : (x === 'right' ? 'auto' : '50%');
    btn.style.right = x === 'right' ? '16px' : 'auto';
    btn.style.transform = x === 'center' ? 'translateX(-50%)' : 'none';

    btn.style.top = y === 'top' ? '16px' : (y === 'middle' ? '50%' : 'auto');
    btn.style.bottom = y === 'bottom' ? '16px' : 'auto';
    if (y === 'middle') {
      btn.style.transform = (x === 'center' ? 'translate(-50%,-50%)' : 'translateY(-50%)');
    }
  }

  [label, color].forEach(el => el && el.addEventListener('input', render));
  document.querySelectorAll('input[name="btn_pos_x"],input[name="btn_pos_y"]').forEach(el => el.addEventListener('change', render));
  const sizeSel = document.querySelector('select[name="btn_size"]');
  sizeSel && sizeSel.addEventListener('change', render);

  file && file.addEventListener('change', function(){
    const f = this.files && this.files[0];
    if (!f || !img) return;
    img.src = URL.createObjectURL(f);
    img.style.display = 'block';
  });

  render();
})();
</script>

<?php require_once __DIR__.'/footer.php'; ?>
