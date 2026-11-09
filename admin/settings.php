<?php
// admin/settings.php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('settings'); // <-- পারমিশন চেক
require_once __DIR__.'/header.php';

$pdo = get_pdo();

/* CSRF */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* settings লোড */
$settings = $pdo->query("SELECT `key`,`value` FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
function s($k,$d=''){ global $settings; return isset($settings[$k]) ? $settings[$k] : $d; }
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
?>

<style>
  /* মোবাইল-ফার্স্ট UX টিউন */
  .setting-card{max-width:1100px;margin:0 auto}
  .form-section-title{font-weight:700;margin:8px 0 2px}
  .image-preview-box{border:1px solid #e5e7eb; border-radius:8px; padding:10px; display:inline-block;}
  .image-preview-box img{max-height:80px; max-width:100px; display:block;}
  /* মোবাইলেই স্টিকি সেভ বার */
  @media (max-width: 992px){
    .mobile-savebar{
      position: sticky; bottom: -1px; left: 0; right: 0;
      background: #0f172a; padding: 10px; z-index: 5;
      display:flex; gap:10px; justify-content: flex-end; align-items:center;
      border-top: 1px solid rgba(255,255,255,.1);
    }
    .mobile-savebar .btn{ flex: 0 0 auto; }
    .mobile-savebar .hint{ color:#cbd5e1; font-size:12px; margin-right:auto; }
  }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0">স্টোর সেটিংস</h5>
  <button class="btn btn-primary d-none d-lg-inline-flex" form="settingsForm">Save</button>
</div>

<?php
/* flash — আপলোড রিজেক্ট/এরর বার্তা */
$__flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
if ($__flash):
  $__flashOk = !empty($__flash['ok']);
  $__flashMsg = $__flash['message'] ?? $__flash['msg'] ?? '';
?>
  <div class="alert <?php echo $__flashOk ? 'alert-success' : 'alert-danger'; ?> alert-dismissible fade show" role="alert">
    <?php echo h($__flashMsg); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif (isset($_GET['saved'])): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    ✅ সেটিংস সফলভাবে সেভ হয়েছে।
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="card setting-card">
  <div class="card-body">
    <form id="settingsForm" method="post" action="settings_save.php" class="row g-3" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">

      <div class="col-12"><div class="form-section-title">স্টোর তথ্য</div></div>

      <div class="col-12 col-lg-6">
        <label class="form-label">স্টোর নাম (store_name)</label>
        <input class="form-control" name="store_name" autocomplete="organization" value="<?=h(s('store_name'))?>">
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <label class="form-label">ফোন (store_phone)</label>
        <input class="form-control" name="store_phone" autocomplete="tel" inputmode="tel" value="<?=h(s('store_phone'))?>">
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <label class="form-label">ইমেইল (store_email)</label>
        <input type="email" class="form-control" name="store_email" autocomplete="email" value="<?=h(s('store_email'))?>">
      </div>

      <div class="col-12">
        <label class="form-label">স্টোর সম্পর্কে (store_about)</label>
        <textarea class="form-control" name="store_about" rows="3" placeholder="স্টোরের সংক্ষিপ্ত পরিচিতি..."><?=h(s('store_about'))?></textarea>
      </div>
      
      <div class="col-12"><hr class="my-2"></div>
      <div class="col-12"><div class="form-section-title">লোগো ও আইকন আপলোড</div></div>

      <div class="col-12 col-md-6">
        <label class="form-label">স্টোর লোগো (store_logo)</label>
        <input type="file" class="form-control" name="store_logo" accept="image/*">
        <?php if ($logo_url = s('store_logo')): ?>
          <div class="mt-2 image-preview-box">
            <img src="<?=h($logo_url)?>" alt="Current Logo">
            <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" name="store_logo_remove" value="1" id="store_logo_remove">
                <label class="form-check-label small" for="store_logo_remove">ছবিটি রিমুভ করুন</label>
            </div>
          </div>
        <?php else: ?>
          <div class="form-text">কোনো লোগো আপলোড করা হয়নি।</div>
        <?php endif; ?>
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">ফেভিকন আইকন (store_favicon)</label>
        <input type="file" class="form-control" name="store_favicon" accept="image/x-icon,image/png">
        <?php if ($favicon_url = s('store_favicon')): ?>
          <div class="mt-2 image-preview-box">
            <img src="<?=h($favicon_url)?>" alt="Current Favicon">
            <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" name="store_favicon_remove" value="1" id="store_favicon_remove">
                <label class="form-check-label small" for="store_favicon_remove">ছবিটি রিমুভ করুন</label>
            </div>
          </div>
        <?php else: ?>
          <div class="form-text">কোনো ফেভিকন আপলোড করা হয়নি।</div>
        <?php endif; ?>
      </div>
      <div class="col-12"><hr class="my-2"></div>
      <div class="col-12"><div class="form-section-title">হোম সেকশন শিরোনাম</div></div>
      <div class="col-12">
        <div class="alert alert-light border small mb-0">
          হোমপেজের সেকশনের শিরোনাম, সাবটাইটেল, ক্রম ও পণ্য সংখ্যা এখন
          <a href="home_sections.php"><strong>হোম সেকশন</strong></a> মেনু থেকে ঠিক করুন।
        </div>
      </div>

      <div class="col-12"><hr class="my-2"></div>
      <div class="col-12"><div class="form-section-title">COD নোট</div></div>

      <div class="col-12">
        <label class="form-label">চেকআউট পেজে ক্রেতাকে দেখানো নোট (cod_note)</label>
        <textarea class="form-control" name="cod_note" rows="2" placeholder="সারা দেশে ক্যাশ অন ডেলিভারি..."><?=h(s('cod_note'))?></textarea>
      </div>

      <div class="col-12"><hr class="my-2"></div>
      <div class="col-12"><div class="form-section-title">ডেলিভারি চার্জ</div></div>
      <div class="col-12">
        <div class="alert alert-light border small mb-0">
          ডেলিভারি চার্জ এখন জেলা অনুযায়ী ঠিক হয়। গ্লোবাল চার্জ ও প্রতিটি জেলার চার্জ
          <a href="delivery.php"><strong>ডেলিভারি চার্জ</strong></a> মেনু থেকে ঠিক করুন।
        </div>
      </div>

      <div class="col-12"><hr class="my-2"></div>
      <div class="col-12"><div class="form-section-title">থিম ও হোম লেআউট</div></div>

      <div class="col-6 col-lg-3">
        <label class="form-label">প্রাইমারি রঙ</label>
        <input type="color" class="form-control form-control-color" name="theme_primary"
               value="<?=h(s('theme_primary','#6c5ce7'))?>">
      </div>

      <div class="col-6 col-lg-3">
        <label class="form-label">অ্যাকসেন্ট রঙ</label>
        <input type="color" class="form-control form-control-color" name="theme_secondary"
               value="<?=h(s('theme_secondary','#ff7675'))?>">
      </div>

      <div class="col-12 col-lg-3">
        <label class="form-label">হিরো অটোপ্লে (ms)</label>
        <input type="number" class="form-control" name="hero_autoplay_ms" min="1500" step="500"
               value="<?=h(s('hero_autoplay_ms','2500'))?>">
      </div>

      <div class="col-12 col-lg-3 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="animations_enabled"
                 name="animations_enabled" value="1" <?=((int)s('animations_enabled',1)?'checked':'')?>>
          <label class="form-check-label" for="animations_enabled">স্ক্রল অ্যানিমেশন</label>
        </div>
      </div>

      <div class="col-12"><hr class="my-2"></div>
      <div class="col-12"><div class="form-section-title">ইনফো বার (হোমপেজ)</div></div>

      <?php for ($i = 1; $i <= 3; $i++): ?>
        <div class="col-12 col-lg-4">
          <label class="form-label">বক্স <?=$i?> — শিরোনাম</label>
          <input class="form-control" name="info<?=$i?>_title" value="<?=h(s("info{$i}_title"))?>">
          <input class="form-control mt-2" name="info<?=$i?>_sub" placeholder="সাব-টেক্সট"
                 value="<?=h(s("info{$i}_sub"))?>">
        </div>
      <?php endfor; ?>

      <div class="col-12"><hr class="my-2"></div>
      <div class="col-12"><div class="form-section-title">সোশ্যাল ও ঠিকানা</div></div>

      <div class="col-12 col-lg-4">
        <label class="form-label">ঠিকানা</label>
        <input class="form-control" name="store_address" value="<?=h(s('store_address'))?>">
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label">Facebook URL</label>
        <input class="form-control" name="social_facebook" value="<?=h(s('social_facebook'))?>" placeholder="https://facebook.com/...">
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label">WhatsApp নম্বর</label>
        <input class="form-control" name="social_whatsapp" value="<?=h(s('social_whatsapp'))?>" placeholder="01XXXXXXXXX">
      </div>

      <div class="col-12"><hr class="my-2"></div>
      <div class="col-12"><div class="form-section-title">ডিসপ্লে ও অন্যান্য</div></div>

      <div class="col-12 col-lg-6">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="discount_show_product"
                 name="discount_show_product" value="1" <?=((int)s('discount_show_product',1)?'checked':'')?>>
          <label class="form-check-label" for="discount_show_product">পণ্যে ডিসকাউন্ট ব্যাজ দেখান</label>
        </div>
      </div>

      <div class="col-12 col-lg-6 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="maintenance_enabled"
                 name="maintenance_enabled" value="1" <?=((int)s('maintenance_enabled',0)?'checked':'')?>>
          <label class="form-check-label" for="maintenance_enabled">মেইনটেন্যান্স মোড চালু</label>
        </div>
      </div>

      <div class="col-12 d-none d-lg-block">
        <div class="d-flex justify-content-end">
          <button class="btn btn-primary">Save</button>
        </div>
      </div>
    </form>
  </div>

  <div class="mobile-savebar d-lg-none">
    <span class="hint">পরিবর্তন করলে সেভ করতে ভুলবেন না</span>
    <button class="btn btn-light" type="button" onclick="document.getElementById('settingsForm').reset()">Reset</button>
    <button class="btn btn-primary" type="submit" form="settingsForm">Save</button>
  </div>
</div>

<?php require __DIR__.'/footer.php'; ?>