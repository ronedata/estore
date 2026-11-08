<?php
// admin/product_add_for_mobile.php — Simplified mobile-friendly product add form
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/image_processor.php';
require_once __DIR__.'/../includes/home_sections.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_products_quick');

$pdo = get_pdo();

/* ------------ Local helpers (from product_form.php) ------------ */
function pf_uploads_dir_abs(): string {
  if (defined('PRODUCT_UPLOAD_DIR') && PRODUCT_UPLOAD_DIR) {
    $rp = realpath(PRODUCT_UPLOAD_DIR);
    $dir = $rp !== false ? $rp : PRODUCT_UPLOAD_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
  }
  if (defined('UPLOAD_DIR') && UPLOAD_DIR) { $rp = realpath(UPLOAD_DIR); return $rp!==false?$rp:UPLOAD_DIR; }
  $fallback = __DIR__.'/../uploads'; $rp = realpath($fallback); return $rp!==false?$rp:$fallback;
}
function pf_uploads_url_base(): string {
  return defined('PRODUCT_UPLOAD_URL') && PRODUCT_UPLOAD_URL
    ? rtrim(PRODUCT_UPLOAD_URL, '/')
    : (defined('UPLOAD_URL') ? rtrim(UPLOAD_URL, '/') : '');
}
define('DEFAULT_IMG_URL', pf_uploads_url_base().'/default.jpg');

/* ------------ CSRF ------------ */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
if (!is_dir(pf_uploads_dir_abs())) { @mkdir(pf_uploads_dir_abs(), 0777, true); }

/* ------------ Load categories for dropdown ------------ */
$cats=$pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ------------ হোম সেকশন টগল (new_default টিক দেওয়া থাকবে) ------------ */
$hsSections = [];
try { $hsSections = hs_manual_sections($pdo); } catch (Throwable $e) {}

/* ========================
   Save (Add Only)
   ======================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_product'])){
  if (!csrf_verify()){
    $_SESSION['flash']=['ok'=>false,'msg'=>'Security token mismatch']; header('Location: products.php'); exit;
  }
  $name   = trim($_POST['name'] ?? '');
  $price  = (float)($_POST['price'] ?? 0);
  $cat_id = (int)($_POST['category_id'] ?? 0);
  $stock    = max(0, (int)($_POST['stock'] ?? 0));
  $badge_color = trim($_POST['badge_color'] ?? '');
  if ($badge_color !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $badge_color)) $badge_color = '';

  if (empty($name) || $price <= 0 || $cat_id <= 0) {
    $_SESSION['flash'] = ['ok'=>false,'msg'=>'পণ্যের নাম, মূল্য এবং ক্যাটেগরি আবশ্যক।'];
    header('Location: product_add_for_mobile.php'); exit;
  } else {
    // Only proceed if validation passes
    try{
      $pdo->prepare("INSERT INTO products(name, price, category_id, stock, active, badge_color) VALUES(?,?,?,?,1,?)")
          ->execute([$name, $price, $cat_id, $stock, ($badge_color ?: null)]);
      $newId=(int)$pdo->lastInsertId();

      /* হোম সেকশন সংযোগ (section_ids[]) */
      hs_set_product_sections($pdo, $newId, $_POST['section_ids'] ?? []);

      /* ---- Images: multiple (images[]) ---- */
      $hasUpload = false;
      if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])){
        $f=$_FILES['images']; $total=count($f['name']);
        // Process maximum of 5 images, even if more are submitted
        $limit = min($total, 5); 
        for($i=0; $i < $limit; $i++){
          if (empty($f['name'][$i]) || $f['error'][$i]!==UPLOAD_ERR_OK) continue;
          if (!is_uploaded_file($f['tmp_name'][$i])) continue;
          $ext=strtolower(pathinfo($f['name'][$i], PATHINFO_EXTENSION));
          if (!in_array($ext,['jpg','jpeg','png','webp','heic','heif'])) continue;

          /* সাইজ লিমিট (৫MB) */
          if ((int)($f['size'][$i] ?? 0) > 5 * 1024 * 1024) continue;

          /* আসল ইমেজ কিনা যাচাই (HEIC বাদে) */
          $isHeic = in_array($ext, ['heic','heif'], true);
          if (!$isHeic) {
            $info = @getimagesize($f['tmp_name'][$i]);
            $allowedTypes = [
              IMAGETYPE_JPEG => 'jpg',
              IMAGETYPE_PNG  => 'png',
              IMAGETYPE_WEBP => 'webp',
              IMAGETYPE_GIF  => 'gif',
            ];
            if (!$info || !isset($allowedTypes[$info[2]])) continue;
            $ext = $allowedTypes[$info[2]];
          }

          $fname='product_'.$newId.'_'.time().'_img'.$i.'.'.$ext;
          $dest=rtrim(pf_uploads_dir_abs(),'/').'/'.$fname;

          if ($isHeic) {
            $ok = move_uploaded_file($f['tmp_name'][$i], $dest);
          } else {
            $res = estore_process_product_image($f['tmp_name'][$i], $dest, estore_image_target_size());
            $ok  = !empty($res['ok']);
            if (!$ok) { @unlink($dest); }
          }

          if ($ok){
            $url=pf_uploads_url_base().'/'.$fname;
            $pdo->prepare("INSERT INTO product_images(product_id,image,sort_order) VALUES(?,?,?)")->execute([$newId,$url,$i]);
            $hasUpload = true;
          }
        }
      }

      /* ---- Ensure main image ---- */
      $main = DEFAULT_IMG_URL;
      if ($hasUpload) {
          $m=$pdo->prepare("SELECT image FROM product_images WHERE product_id=? ORDER BY sort_order,id LIMIT 1");
          $m->execute([$newId]);
          $main=$m->fetchColumn() ?: DEFAULT_IMG_URL;
      } else {
          $pdo->prepare("INSERT INTO product_images(product_id,image,sort_order) VALUES(?,?,0)")->execute([$newId, DEFAULT_IMG_URL]);
      }
      $pdo->prepare("UPDATE products SET image=? WHERE id=?")->execute([$main,$newId]);

      $_SESSION['flash'] = ['ok'=>true,'msg'=>'নতুন পণ্য সফলভাবে যোগ হয়েছে।'];
      header('Location: product_add_for_mobile.php'); exit;

    }catch(Throwable $e){
      // For debugging: error_log($e->getMessage());
      $_SESSION['flash'] = ['ok'=>false,'msg'=>'সংরক্ষণ ব্যর্থ হয়েছে।'];
      header('Location: product_add_for_mobile.php'); exit;
    }
  }
}

/* ------------ View ------------ */
require_once __DIR__.'/header.php';
?>
<style>
  .form-card { max-width: 600px; margin: 0 auto; }
  .btn-pill { border-radius: 999px; }
  .preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 8px;
    margin-top: 10px;
  }
  .preview-chip {
    position: relative;
    width: 100%;
    aspect-ratio: 1/1;
  }
  .preview-chip img {
    width: 100%; height: 100%; object-fit: cover;
    border-radius: 10px;
  }
  .preview-chip .btn-remove {
    position: absolute; top: -8px; right: -8px;
    width: 22px; height: 22px;
    background: #ef4444; color: #fff;
    border: none; border-radius: 50%;
    font-weight: 700; font-size: 14px; line-height: 20px;
    text-align: center; cursor: pointer;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
  }
  /* Toast container (from products.php) */
  #toastStack{ position:fixed; right:16px; bottom:16px; z-index:1080; }
  /* Backdrop for toast */
  .toast-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.4);
    z-index: 1070; /* Below toast (1080), above content */
    display: none;
    opacity: 0;
    transition: opacity .15s linear;
  }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0">
    <i class="fa-solid fa-mobile-screen-button me-1"></i> দ্রুত পণ্য যোগ
  </h5>
  <a href="products.php" class="btn btn-outline-secondary btn-pill">পণ্য তালিকা</a>
</div>

<div class="card form-card"><div class="card-body">
  <form method="post" enctype="multipart/form-data" id="productForm">
    <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">

    <div class="row g-3">
      <div class="col-12">
        <label class="form-label">পণ্যের নাম</label>
        <input name="name" class="form-control" required>
      </div>

      <div class="col-12">
        <label class="form-label">মূল্য</label>
        <input type="number" step="0.01" name="price" class="form-control" required>
      </div>

      <div class="col-12">
        <label class="form-label">ক্যাটেগরি</label>
        <select name="category_id" class="form-select" required>
          <option value="">-- ক্যাটেগরি সিলেক্ট করুন --</option>
          <?php foreach($cats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label">স্টক</label>
        <input type="number" min="0" name="stock" class="form-control" value="0">
        <div class="form-text">০ দিলে কার্ডে “স্টক নেই” দেখাবে।</div>
      </div>

      <div class="col-12" id="hsSectionToggles">
        <label class="form-label d-block mb-2">হোমপেজের কোন কোন সেকশনে দেখাবেন</label>
        <?php if (!$hsSections): ?>
          <div class="form-text">কোনো “হাতে বাছাই” সেকশন নেই।</div>
        <?php else: ?>
          <div style="max-height:220px;overflow-y:auto">
            <?php foreach ($hsSections as $hs): $hsid = (int)$hs['id']; ?>
              <div class="form-check form-switch mb-1">
                <input class="form-check-input" type="checkbox" name="section_ids[]" id="hsec<?php echo $hsid; ?>"
                       value="<?php echo $hsid; ?>" <?php echo ((int)$hs['new_default'] === 1) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="hsec<?php echo $hsid; ?>"><?php echo h($hs['title']); ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-12">
        <label class="form-label">কার্ডের রঙ (ঐচ্ছিক)</label>
        <input type="color" name="badge_color" class="form-control form-control-color" value="#ff5252">
        <div class="form-text">ডিসকাউন্ট ব্যাজ ও “Shop Now” বাটনের রঙ।</div>
      </div>

      <div class="col-12">
        <label class="form-label">ছবি (একাধিক)</label>
        <div class="input-group">
          <input type="file" id="imagesInput" name="images[]" class="form-control"
                 accept="image/*" multiple>
          <button type="button" class="btn btn-primary" id="cameraBtn" title="ছবি তুলুন">
            <i class="fa-solid fa-camera"></i>
          </button>
        </div>
        <input type="file" id="cameraInput" style="display:none;" accept="image/*" capture="environment">

        <div class="form-text">
          ছবি সিলেক্ট করুন অথবা ক্যামেরা বাটন চেপে ছবি তুলুন।
          ছবি না দিলে ডিফল্ট ছবি সেট হবে।
        </div>
        <div class="preview-grid" id="previews"></div>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary btn-pill" name="save_product" value="1">Save Product</button>
      <a href="products.php" class="btn btn-outline-secondary btn-pill">Cancel</a>
    </div>
  </form>
</div></div>

<!-- Backdrop for Toast -->
<div id="toastBackdrop" class="toast-backdrop"></div>

<!-- Toast container -->
<div id="toastStack" aria-live="polite" aria-atomic="false"></div>

<script>
/* --- Image preview with remove button (adapted from product_form.php) --- */
(function(){
  const input = document.getElementById('imagesInput');
  const previews = document.getElementById('previews');
  const cameraBtn = document.getElementById('cameraBtn');
  const cameraInput = document.getElementById('cameraInput');
  if(!input || !previews || !cameraBtn || !cameraInput) return;
  
  const MAX_IMAGES = 5;

  let dt = new DataTransfer();
  function syncInput(){ input.files = dt.files; }

  function addFiles(files){
    const currentCount = dt.files.length;
    if (currentCount >= MAX_IMAGES) {
      showToast({type:'error', title:'সীমাবদ্ধতা', message:`আপনি সর্বোচ্চ ${MAX_IMAGES}টি ছবি আপলোড করতে পারবেন।`});
      return;
    }

    // যদি ব্যবহারকারী ৫টির বেশি ফাইল সিলেক্ট করেন, তাহলে শুধুমাত্র প্রথম ৫টি নেওয়া হবে।
    const filesToAdd = Array.from(files).slice(0, MAX_IMAGES - currentCount);

    for (const file of filesToAdd){
      if (dt.files.length >= MAX_IMAGES) {
        break;
      }

      if (!file || !file.type.match(/^image\//i)) continue;

      const key = file.name+'|'+file.size+'|'+file.lastModified;
      let exists=false;
      for (let i=0;i<dt.files.length;i++){
        const f=dt.files[i];
        const k=f.name+'|'+f.size+'|'+f.lastModified;
        if (k===key){ exists=true; break; }
      }
      if (exists) continue;
      dt.items.add(file);
      const reader = new FileReader();
      reader.onload = e=>{
        const chip = document.createElement('div');
        chip.className='preview-chip'; chip.dataset.key=key;
        chip.innerHTML = `<img src="${e.target.result}"><button type="button" class="btn-remove" aria-label="Remove">×</button>`;
        previews.appendChild(chip);
      };
      reader.readAsDataURL(file);
    }

    if (files.length > filesToAdd.length) {
      showToast({type:'info', title:'সীমাবদ্ধতা', message:`সর্বোচ্চ ${MAX_IMAGES}টি ছবি যোগ করা হয়েছে। অতিরিক্ত ছবিগুলো বাদ দেওয়া হয়েছে।`});
    }

    syncInput();

    // যদি ৫টি ছবি পূর্ণ হয়ে যায়, তাহলে নতুন ছবি যোগ করার বাটনগুলো নিষ্ক্রিয় করুন।
    // মূল ইনপুট ফিল্ডটি فعال থাকবে যাতে ছবিগুলো সাবমিট হয়।
    if (dt.files.length >= MAX_IMAGES) {
      input.style.display = 'none'; // ইনপুটটি লুকিয়ে ফেলুন
      cameraBtn.disabled = true;    // ক্যামেরা বাটন নিষ্ক্রিয় করুন
    }
  }

  function removeByKey(key){
    const ndt = new DataTransfer();
    for (let i=0;i<dt.files.length;i++){
      const f=dt.files[i];
      const k=f.name+'|'+f.size+'|'+f.lastModified;
      if (k!==key) ndt.items.add(f);
    }
    dt = ndt; syncInput();
    if (dt.files.length < MAX_IMAGES) {
      input.style.display = 'block'; // ইনপুটটি আবার দেখান
      cameraBtn.disabled = false;    // ক্যামেরা বাটন فعال করুন
    }
  }

  // Main file input change
  input.addEventListener('change', (e)=>{ if (e.target.files?.length) addFiles(e.target.files); });

  // Camera button triggers isolated camera input
  cameraBtn.addEventListener('click', (e)=>{ e.preventDefault(); cameraInput.click(); });

  // Handle files from camera
  cameraInput.addEventListener('change', (e)=>{
    if (e.target.files?.length) addFiles(e.target.files);
    e.target.value = null; // Clear to allow re-opening camera
  });

  // Remove button click (delegation)
  previews.addEventListener('click', (e)=>{
    if (!e.target.classList.contains('btn-remove')) return;
    const chip = e.target.closest('.preview-chip'); if (!chip) return;
    const key = chip.dataset.key; chip.remove(); removeByKey(key);
  });

})();

/* --- PHP Flash to Toast --- */
<?php if (isset($_SESSION['flash'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    window.showToast({
        type: '<?php echo $_SESSION['flash']['ok'] ? 'success' : 'error'; ?>',
        title: '<?php echo $_SESSION['flash']['ok'] ? 'সফল' : 'ত্রুটি'; ?>',
        message: '<?php echo h($_SESSION['flash']['msg']); ?>'
    });
});
<?php unset($_SESSION['flash']); endif; ?>


/* --- Toast Helper (Bootstrap 5) --- */
(function(){
  const backdrop = document.getElementById('toastBackdrop');
  window.showToast = function({title='বার্তা', message='', type='info'} = {}){
    const id = 't'+Math.random().toString(36).slice(2);
    const variant = (type === 'error') ? 'danger' : type; // Map 'error' to 'danger'
    const html = `
      <div id="${id}" class="toast align-items-center text-bg-${variant} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body"><strong>${title}:</strong> ${message}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>`;
    const wrap = document.getElementById('toastStack');
    if (!wrap || !backdrop) return;

    wrap.insertAdjacentHTML('beforeend', html);
    const el = document.getElementById(id);
    const t = new bootstrap.Toast(el, { autohide: false }); // autohide: false ensures it stays until closed
    
    // Show backdrop
    backdrop.style.display = 'block';
    requestAnimationFrame(() => backdrop.style.opacity = '1');

    t.show();
    el.addEventListener('hidden.bs.toast', ()=> {
      el.remove();
      // Hide backdrop only if no other toasts are visible
      if (wrap.childElementCount === 0) { backdrop.style.opacity = '0'; setTimeout(()=>backdrop.style.display='none', 150); }
    });
  };
})();
</script>

<?php require __DIR__.'/footer.php'; ?>