<?php
// contact.php — mFeex style: info card + message form
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/render_page.php';
increment_page_view('contact');

$pdo = get_pdo();

$C = [];
try {
  $C = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN
        ('store_name','store_phone','store_email','store_address','social_whatsapp',
         'social_facebook','social_instagram')")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) {}

$storeName = $C['store_name'] ?? APP_NAME;
$waDigits  = preg_replace('/\D+/', '', $C['social_whatsapp'] ?? '');

/* ---------- POST: save message ---------- */
$sent = false;
$err  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $err = 'সিকিউরিটি টোকেন মিসম্যাচ। আবার চেষ্টা করুন।';
  } else {
    $name    = trim((string)($_POST['name'] ?? ''));
    $contact = trim((string)($_POST['contact'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));

    /* honeypot */
    if (trim((string)($_POST['website'] ?? '')) !== '') {
      $err = 'অবৈধ অনুরোধ।';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
      $err = 'আপনার নাম লিখুন (২–১২০ অক্ষর)।';
    } elseif (mb_strlen($message) < 5 || mb_strlen($message) > 3000) {
      $err = 'মেসেজ লিখুন (৫–৩০০০ অক্ষর)।';
    } elseif ($contact !== '' && mb_strlen($contact) > 160) {
      $err = 'ইমেইল বা ফোন নম্বর অনেক বড়।';
    } else {
      try {
        $stm = $pdo->prepare("INSERT INTO contact_messages(name,contact,message,ip) VALUES(?,?,?,?)");
        $stm->execute([
          $name,
          $contact,
          $message,
          substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
        $sent = true;
      } catch (Throwable $e) {
        $err = 'মেসেজ পাঠানো যায়নি। পরে আবার চেষ্টা করুন।';
      }
    }
  }
}

$page = null;
try {
  $stm = $pdo->prepare("SELECT content FROM pages WHERE slug='contact' AND active=1 LIMIT 1");
  $stm->execute();
  $page = $stm->fetch();
} catch (Throwable $e) {}

include __DIR__.'/includes/partials_header.php';
?>

<section class="pg-hero">
  <h1>যোগাযোগ করুন</h1>
  <p>যেকোনো প্রশ্ন, তথ্য বা সহায়তার জন্য আমাদের সাথে সরাসরি যোগাযোগ করুন</p>
</section>

<div class="pg-wrap">
  <div class="pg-contact-grid">

    <!-- Company info -->
    <div class="pg-contact-card">
      <h2 class="pg-title">আমাদের অফিস তথ্য</h2>

      <div class="pg-info-item">
        <div class="pg-info-icon"><i class="fa-solid fa-building"></i></div>
        <div class="pg-info-text">
          <h4>কোম্পানি / শপ</h4>
          <p><strong><?php echo h($storeName); ?></strong></p>
        </div>
      </div>

      <?php if (!empty($C['store_address'])): ?>
      <div class="pg-info-item">
        <div class="pg-info-icon"><i class="fa-solid fa-location-dot"></i></div>
        <div class="pg-info-text">
          <h4>ঠিকানা</h4>
          <p><?php echo h($C['store_address']); ?></p>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($C['store_phone'])): ?>
      <div class="pg-info-item">
        <div class="pg-info-icon"><i class="fa-solid fa-phone"></i></div>
        <div class="pg-info-text">
          <h4>ফোন</h4>
          <p><a href="tel:<?php echo h($C['store_phone']); ?>"><?php echo h($C['store_phone']); ?></a></p>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($C['store_email'])): ?>
      <div class="pg-info-item">
        <div class="pg-info-icon"><i class="fa-solid fa-envelope"></i></div>
        <div class="pg-info-text">
          <h4>ইমেইল</h4>
          <p><a href="mailto:<?php echo h($C['store_email']); ?>"><?php echo h($C['store_email']); ?></a></p>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($waDigits): ?>
      <div class="pg-info-item">
        <div class="pg-info-icon"><i class="fa-brands fa-whatsapp"></i></div>
        <div class="pg-info-text">
          <h4>হোয়াটসঅ্যাপ সাপোর্ট</h4>
          <p><?php echo h($C['store_phone'] ?: $C['social_whatsapp']); ?></p>
        </div>
      </div>
      <a href="https://wa.me/<?php echo h($waDigits); ?>?text=<?php echo rawurlencode('আমার একটি প্রশ্ন আছে।'); ?>"
         target="_blank" rel="noopener" class="pg-wa-btn">
        <i class="fa-brands fa-whatsapp" style="font-size:1.2rem"></i> সরাসরি হোয়াটসঅ্যাপে মেসেজ দিন
      </a>
      <?php endif; ?>
    </div>

    <!-- Message form -->
    <div class="pg-contact-card">
      <h2 class="pg-title">মেসেজ পাঠান</h2>

      <?php if ($sent): ?>
        <div class="pg-alert ok"><i class="fa-solid fa-circle-check"></i> ধন্যবাদ! আপনার মেসেজ পাঠানো হয়েছে। আমরা শীঘ্রই যোগাযোগ করব।</div>
      <?php elseif ($err !== ''): ?>
        <div class="pg-alert err"><i class="fa-solid fa-circle-exclamation"></i> <?php echo h($err); ?></div>
      <?php endif; ?>

      <form method="post" action="contact.php" novalidate>
        <?php echo csrf_field(); ?>

        <div class="pg-field">
          <label for="cName">আপনার নাম *</label>
          <input type="text" id="cName" name="name" maxlength="120" required
                 placeholder="আপনার নাম লিখুন"
                 value="<?php echo h($_POST['name'] ?? ''); ?>">
        </div>

        <div class="pg-field">
          <label for="cContact">ইমেইল বা ফোন নম্বর</label>
          <input type="text" id="cContact" name="contact" maxlength="160"
                 placeholder="আপনার ইমেইল বা মোবাইল নম্বর"
                 value="<?php echo h($_POST['contact'] ?? ''); ?>">
        </div>

        <div class="pg-field">
          <label for="cMessage">আপনার মেসেজ *</label>
          <textarea id="cMessage" name="message" maxlength="3000" required
                    placeholder="আপনি কী জানতে চান তা সংক্ষেপে লিখুন..."><?php echo h($_POST['message'] ?? ''); ?></textarea>
        </div>

        <!-- honeypot -->
        <div style="position:absolute;left:-9999px" aria-hidden="true">
          <label>Website</label>
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit" class="pg-submit">
          <i class="fa-solid fa-paper-plane"></i> মেসেজ পাঠান
        </button>
      </form>

      <?php if ($page && trim((string)$page['content']) !== ''): ?>
        <hr class="pg-divider">
        <div class="pg-text"><?php echo nl2br(h($page['content'])); ?></div>
      <?php endif; ?>
    </div>

  </div>

  <div class="pg-back">
    <a class="btn-pill ghost" href="<?php echo BASE_URL; ?>/index.php">
      <i class="fa-solid fa-arrow-left"></i> হোম পেজে ফিরে যান
    </a>
  </div>
</div>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
