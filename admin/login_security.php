<?php
// admin/login_security.php — অ্যাডমিন লগইন সুরক্ষা: ব্যর্থ-চেষ্টার লক, ধীর করা, সাদা তালিকা, লক খোলা (শুধু Super Admin)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/login_security.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('settings');

if (($_SESSION['admin_role_name'] ?? '') !== 'Super Admin') {
  http_response_code(403);
  require_once __DIR__.'/header.php';
  echo '<div class="alert alert-danger">এই পেজ শুধুমাত্র Super Admin-এর জন্য।</div>';
  require_once __DIR__.'/footer.php';
  exit;
}

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'সিকিউরিটি টোকেন মিলেনি'];
    header('Location: login_security.php'); exit;
  }
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
  if ($action === 'save') {
    [$clean, $errs] = ls_validate($_POST);
    if ($clean === null) {
      $_SESSION['flash'] = ['ok'=>false, 'msg'=>implode(' ', $errs)];
    } else {
      $ok = ls_save($pdo, $clean);
      $_SESSION['flash'] = ['ok'=>$ok, 'msg'=>$ok ? 'লগইন সুরক্ষার সেটিংস সংরক্ষণ হয়েছে।' : 'সংরক্ষণ করা যায়নি।'];
    }
  } elseif ($action === 'unlock') {
    $kind = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
    $subject = is_string($_POST['subject'] ?? null) ? $_POST['subject'] : '';
    $ok = ls_unlock($pdo, $kind, $subject);
    $_SESSION['flash'] = ['ok'=>$ok, 'msg'=>$ok ? 'লক খুলে দেওয়া হয়েছে।' : 'লক খোলা যায়নি।'];
  } elseif ($action === 'unlock_all') {
    $ok = ls_unlock_all($pdo);
    $_SESSION['flash'] = ['ok'=>$ok, 'msg'=>$ok ? 'সব লক খুলে দেওয়া হয়েছে।' : 'লক খোলা যায়নি।'];
  } elseif ($action === 'purge') {
    $cfgP = ls_load($pdo);
    $n = ls_purge($pdo, (int)$cfgP['retention_days']);
    $_SESSION['flash'] = ['ok'=>true, 'msg'=>"পুরোনো $n টি রেকর্ড মুছে ফেলা হয়েছে।"];
  } else {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'অজানা অ্যাকশন।'];
  }
  header('Location: login_security.php'); exit;
}

$cfg    = ls_load($pdo);
$cands  = ls_candidates();
$myIp   = ls_client_ip($cfg['ip_source']);
$cands['auto'] = ls_client_ip('auto');
$locks  = ls_locks($pdo);
$recent = ls_recent($pdo, 50);
$sources = ls_ip_sources();
$since24 = date('Y-m-d H:i:s', time() - 86400);
$cnt = function (string $result) use ($pdo, $since24): int {
  try { $s = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE result=? AND created_at >= ?"); $s->execute([$result, $since24]); return (int)$s->fetchColumn(); }
  catch (Throwable $e) { return 0; }
};
$fail24 = $cnt('fail'); $blocked24 = $cnt('blocked'); $ok24 = $cnt('ok');
$proxyHint = false;
foreach (['xff_last', 'x_real_ip', 'cf_connecting_ip'] as $k) {
  if ($cands[$k] && $cands[$k] !== $cands['remote_addr']) $proxyHint = true;
}
$wlCount = count(ls_whitelist($cfg));
$myWl = ls_is_whitelisted($myIp, $cfg);

require_once __DIR__.'/header.php';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h4 class="mb-1">লগইন সুরক্ষা</h4>
    <div class="text-muted small">অ্যাডমিন লগইনে বারবার ভুল পাসওয়ার্ড দিলে কাকে, কতক্ষণ আটকাবে — এখান থেকে ঠিক করুন।</div>
  </div>
  <span class="badge <?php echo $cfg['enabled'] ? 'text-bg-success' : 'text-bg-secondary'; ?> fs-6" id="lsStatus"><?php echo $cfg['enabled'] ? 'চালু' : 'বন্ধ'; ?></span>
</div>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2" id="lsFlash"><?php echo h($f['msg'] ?? ''); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-3"><div class="text-muted small">এখন লক হয়ে আছে</div><div class="fs-4 fw-bold" id="lsStatLocks"><?php echo count($locks); ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-3"><div class="text-muted small">ব্যর্থ চেষ্টা (২৪ ঘণ্টা)</div><div class="fs-4 fw-bold"><?php echo $fail24; ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-3"><div class="text-muted small">আটকানো চেষ্টা (২৪ ঘণ্টা)</div><div class="fs-4 fw-bold"><?php echo $blocked24; ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-3"><div class="text-muted small">সফল লগইন (২৪ ঘণ্টা)</div><div class="fs-4 fw-bold"><?php echo $ok24; ?></div></div></div></div>
</div>

<?php if ($proxyHint && $cfg['ip_source'] === 'remote_addr' && ls_client_ip('auto') !== $cands['remote_addr']): ?>
  <div class="alert alert-warning" id="lsProxyWarn">
    <strong>সতর্কতা:</strong> সার্ভার প্রক্সির পেছনে আছে বলে মনে হচ্ছে — সরাসরি সংযোগের আইপি (<code><?php echo h($cands['remote_addr']); ?></code>) আর হেডারের আইপি আলাদা।
    এই অবস্থায় "সরাসরি সংযোগ" বেছে রাখলে <strong>সবার আইপি এক ধরা পড়তে পারে</strong> — একজনের ভুলে সব অ্যাডমিন আটকে যাবে।
    নিচের "আইপি শনাক্তকরণ" থেকে সঠিক উৎস বেছে নিন।
  </div>
<?php endif; ?>

<form method="post" id="lsForm">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="save">

  <div class="card mb-3">
    <div class="card-body">
      <div class="form-check form-switch fs-6">
        <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="lsEnabled" value="1" <?php echo $cfg['enabled'] ? 'checked' : ''; ?>>
        <label class="form-check-label fw-semibold" for="lsEnabled">লগইন সুরক্ষা চালু</label>
      </div>
      <div class="form-text">বন্ধ করলে ব্যর্থ চেষ্টা গোনা বা আটকানো কিছুই হবে না।</div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="card h-100"><div class="card-body">
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" name="ip_lock_enabled" id="lsIpOn" value="1" <?php echo $cfg['ip_lock_enabled'] ? 'checked' : ''; ?>>
          <label class="form-check-label fw-semibold" for="lsIpOn">আইপি-লক <span class="badge text-bg-success">সুপারিশকৃত</span></label>
        </div>
        <div class="small text-muted mb-3">যে আইপি থেকে বারবার ভুল হচ্ছে <strong>শুধু সেটাই</strong> আটকাবে। অন্য অ্যাডমিনরা অন্য আইপি থেকে স্বাভাবিকভাবে ঢুকতে পারবেন।</div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small mb-1" for="lsIpMax">কতবার ভুলে লক</label>
            <input type="number" class="form-control" name="ip_max" id="lsIpMax" min="1" max="100" value="<?php echo (int)$cfg['ip_max']; ?>"></div>
          <div class="col-6"><label class="form-label small mb-1" for="lsIpMin">কত মিনিট বন্ধ</label>
            <input type="number" class="form-control" name="ip_minutes" id="lsIpMin" min="1" max="1440" value="<?php echo (int)$cfg['ip_minutes']; ?>"></div>
        </div>
      </div></div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100"><div class="card-body">
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" name="user_lock_enabled" id="lsUserOn" value="1" <?php echo $cfg['user_lock_enabled'] ? 'checked' : ''; ?>>
          <label class="form-check-label fw-semibold" for="lsUserOn">ইউজারনেম-লক <span class="badge text-bg-warning">ঝুঁকি আছে</span></label>
        </div>
        <div class="small text-muted mb-3">কোনো ইউজারনেমে বারবার ভুল হলে <strong>যেকোনো জায়গা থেকে</strong> সেই নামে ঢোকা বন্ধ। কেউ আপনার ইউজারনেম জানলে ইচ্ছে করে ভুল দিয়ে <strong>আসল অ্যাডমিনকেই বাইরে রাখতে পারে</strong> — তাই সাধারণত বন্ধ রাখা ভালো।</div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small mb-1" for="lsUserMax">কতবার ভুলে লক</label>
            <input type="number" class="form-control" name="user_max" id="lsUserMax" min="1" max="200" value="<?php echo (int)$cfg['user_max']; ?>"></div>
          <div class="col-6"><label class="form-label small mb-1" for="lsUserMin">কত মিনিট বন্ধ</label>
            <input type="number" class="form-control" name="user_minutes" id="lsUserMin" min="1" max="1440" value="<?php echo (int)$cfg['user_minutes']; ?>"></div>
        </div>
      </div></div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100"><div class="card-body">
        <div class="fw-semibold mb-1">ধীর করা (আসল অ্যাডমিনকে না আটকে)</div>
        <div class="small text-muted mb-3">কোনো ইউজারনেমে (যেকোনো আইপি মিলিয়ে) বহু ভুল চেষ্টা হলে সেই নামে প্রতিটি চেষ্টায় কয়েক সেকেন্ড অপেক্ষা করতে হয়। বিভিন্ন আইপি ঘুরিয়ে পাসওয়ার্ড আন্দাজ করা অচল হয়, অথচ কেউ বাইরে থাকে না।</div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small mb-1" for="lsSlowAfter">কতবার ভুলের পর (০ = বন্ধ)</label>
            <input type="number" class="form-control" name="slow_after" id="lsSlowAfter" min="0" max="500" value="<?php echo (int)$cfg['slow_after']; ?>"></div>
          <div class="col-6"><label class="form-label small mb-1" for="lsSlowSec">কত সেকেন্ড অপেক্ষা</label>
            <input type="number" class="form-control" name="slow_seconds" id="lsSlowSec" min="1" max="10" value="<?php echo (int)$cfg['slow_seconds']; ?>"></div>
        </div>
      </div></div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100"><div class="card-body">
        <div class="fw-semibold mb-1">গণনা ও বার্তা</div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label small mb-1" for="lsWindow">ব্যর্থ চেষ্টা কত মিনিটের মধ্যে গোনা হবে</label>
            <input type="number" class="form-control" name="window_minutes" id="lsWindow" min="1" max="1440" value="<?php echo (int)$cfg['window_minutes']; ?>"></div>
          <div class="col-6"><label class="form-label small mb-1" for="lsRetention">লগ কত দিন রাখবে</label>
            <input type="number" class="form-control" name="retention_days" id="lsRetention" min="1" max="365" value="<?php echo (int)$cfg['retention_days']; ?>"></div>
        </div>
        <div class="form-check form-switch mt-3">
          <input class="form-check-input" type="checkbox" role="switch" name="show_remaining" id="lsShowRem" value="1" <?php echo $cfg['show_remaining'] ? 'checked' : ''; ?>>
          <label class="form-check-label" for="lsShowRem">ভুল হলে "আর কতবার চেষ্টা করা যাবে" দেখাবে</label>
        </div>
      </div></div>
    </div>

    <div class="col-12">
      <div class="card"><div class="card-body">
        <div class="fw-semibold mb-1">আইপি শনাক্তকরণ</div>
        <div class="small text-muted mb-3">সার্ভার ব্যবহারকারীর আসল আইপি কোথা থেকে পাবে, তা ঠিক না হলে আইপি-লক ভুল কাজ করে। নিচের টেবিলে <strong>এই মুহূর্তে সার্ভার কী দেখছে</strong> তা আছে — আপনার আসল আইপির সাথে মেলে এমন উৎসটি বেছে নিন।</div>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle mb-0" id="lsSrcTable">
            <thead><tr><th style="width:36px"></th><th>উৎস</th><th>এখন যা দেখছে</th><th class="d-none d-md-table-cell">কখন ব্যবহার করবেন</th></tr></thead>
            <tbody>
            <?php foreach ($sources as $key => [$label, $hint]): ?>
              <tr>
                <td><input class="form-check-input" type="radio" name="ip_source" value="<?php echo h($key); ?>" id="src_<?php echo h($key); ?>" <?php echo $cfg['ip_source'] === $key ? 'checked' : ''; ?>></td>
                <td><label for="src_<?php echo h($key); ?>" class="mb-0"><?php echo h($label); ?></label></td>
                <td><code><?php echo $cands[$key] ? h($cands[$key]) : '—'; ?></code></td>
                <td class="small text-muted d-none d-md-table-cell"><?php echo h($hint); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="alert alert-info py-2 mb-3" id="lsMyIp">নির্বাচিত উৎস অনুযায়ী <strong>আপনার আইপি: <code><?php echo h($myIp); ?></code></strong>
          <?php if ($myWl): ?> <span class="badge text-bg-success">সাদা তালিকায় আছে — কখনো লক হবেন না</span><?php endif; ?></div>

        <label class="form-label fw-semibold" for="lsWhitelist">সাদা তালিকা (এই আইপিগুলো কখনো আটকানো হবে না)</label>
        <textarea class="form-control font-monospace" name="whitelist" id="lsWhitelist" rows="3" placeholder="প্রতি লাইনে একটি আইপি, যেমন: 203.0.113.10"><?php echo h($cfg['whitelist']); ?></textarea>
        <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="lsAddMine" data-ip="<?php echo h($myIp); ?>">আমার আইপি যোগ করুন (<?php echo h($myIp); ?>)</button>
          <span class="small text-muted">অফিস বা বাসার স্থির আইপি এখানে দিলে নিজে ভুলে আটকে যাবেন না। (<?php echo $wlCount; ?>টি আছে)</span>
        </div>
      </div></div>
    </div>
  </div>

  <div class="d-flex gap-2 mt-3 mb-4">
    <button class="btn btn-primary" id="lsSave" type="submit"><i class="fa-solid fa-floppy-disk"></i> সংরক্ষণ</button>
  </div>
</form>

<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <strong>এখন লক হয়ে আছে</strong>
    <?php if ($locks): ?>
      <form method="post" class="d-inline" onsubmit="return confirm('সব লক খুলে দেবেন?')">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="unlock_all">
        <button class="btn btn-sm btn-outline-danger" id="lsUnlockAll">সব লক খুলুন</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="lsLocksTable">
      <thead><tr><th>ধরন</th><th>আইপি / ইউজারনেম</th><th>কখন খুলবে</th><th class="text-end">অ্যাকশন</th></tr></thead>
      <tbody>
      <?php if (!$locks): ?>
        <tr><td colspan="4" class="text-center text-muted py-3">এখন কোনো লক নেই।</td></tr>
      <?php endif; ?>
      <?php foreach ($locks as $l): $until = strtotime($l['until_at']); ?>
        <tr data-kind="<?php echo h($l['kind']); ?>" data-subject="<?php echo h($l['subject']); ?>">
          <td><span class="badge <?php echo $l['kind']==='ip' ? 'text-bg-primary' : 'text-bg-warning'; ?>"><?php echo $l['kind']==='ip' ? 'আইপি' : 'ইউজারনেম'; ?></span></td>
          <td><code><?php echo h($l['subject']); ?></code><?php if ($l['kind']==='ip' && $l['subject']===$myIp): ?> <span class="badge text-bg-info">আপনি</span><?php endif; ?></td>
          <td class="small"><?php echo h(date('d M Y, g:i A', $until)); ?> <span class="text-muted">(<?php echo h(ls_wait_text($until)); ?> পরে)</span></td>
          <td class="text-end">
            <form method="post" class="d-inline">
              <?php echo csrf_field(); ?><input type="hidden" name="action" value="unlock">
              <input type="hidden" name="kind" value="<?php echo h($l['kind']); ?>"><input type="hidden" name="subject" value="<?php echo h($l['subject']); ?>">
              <button class="btn btn-sm btn-outline-success lsUnlock">খুলে দিন</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <strong>সাম্প্রতিক লগইন চেষ্টা (শেষ ৫০টি)</strong>
    <form method="post" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="purge">
      <button class="btn btn-sm btn-outline-secondary" id="lsPurge" title="<?php echo (int)$cfg['retention_days']; ?> দিনের পুরোনো রেকর্ড মুছবে">পুরোনো লগ মুছুন</button></form>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" id="lsRecentTable">
      <thead><tr><th>সময়</th><th>আইপি</th><th>ইউজারনেম</th><th>ফলাফল</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?><tr><td colspan="4" class="text-center text-muted py-3">এখনো কোনো চেষ্টা নথিভুক্ত হয়নি।</td></tr><?php endif; ?>
      <?php foreach ($recent as $r):
        $badge = ['fail'=>['text-bg-danger','ভুল'], 'ok'=>['text-bg-success','সফল'], 'blocked'=>['text-bg-warning','আটকানো'], 'failx'=>['text-bg-secondary','ভুল (রিসেট)']][$r['result']] ?? ['text-bg-secondary', $r['result']]; ?>
        <tr>
          <td class="small"><?php echo h(date('d M, g:i:s A', strtotime($r['created_at']))); ?></td>
          <td><code><?php echo h($r['ip']); ?></code></td>
          <td><?php echo h($r['username']); ?></td>
          <td><span class="badge <?php echo $badge[0]; ?>"><?php echo h($badge[1]); ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="alert alert-light border small">
  <strong>নিজে আটকে গেলে (জরুরি উপায়):</strong> অন্য আইপি/মোবাইল ডাটা থেকে ঢুকে এখান থেকে "খুলে দিন" চাপুন। কোনোভাবেই ঢুকতে না পারলে phpMyAdmin-এ
  <code>DELETE FROM login_locks;</code> চালান (সঙ্গে <code>DELETE FROM login_attempts;</code> চাইলে), অথবা লোকালে <code>php tools/unlock_logins.php --all</code>।
  সব সময় ১৫ মিনিট বা নির্ধারিত সময় পরে লক নিজে নিজে খুলে যায়।
</div>

<script>
(function(){
  var btn = document.getElementById('lsAddMine'), ta = document.getElementById('lsWhitelist');
  if (btn && ta) btn.addEventListener('click', function(){
    var ip = btn.getAttribute('data-ip');
    var lines = ta.value.split(/[\r\n,;\s]+/).filter(Boolean);
    if (lines.indexOf(ip) === -1) { lines.push(ip); ta.value = lines.join('\n'); }
  });
  /* শুধু ম্লান দেখায় — ফিল্ড disabled করলে ফর্মে জমা হয় না, আর সেভে মান হারাত */
  function dim(on, ids){ ids.forEach(function(i){ var e = document.getElementById(i); if (e) e.classList.toggle('opacity-50', !on); }); }
  var ipOn = document.getElementById('lsIpOn'), userOn = document.getElementById('lsUserOn'), master = document.getElementById('lsEnabled');
  function sync(){
    var m = master.checked;
    dim(m && ipOn.checked, ['lsIpMax','lsIpMin']);
    dim(m && userOn.checked, ['lsUserMax','lsUserMin']);
    dim(m, ['lsSlowAfter','lsSlowSec','lsWindow','lsShowRem','lsIpOn','lsUserOn']);
    var st = document.getElementById('lsStatus'); if (st) { st.textContent = m ? 'চালু' : 'বন্ধ'; st.className = 'badge fs-6 ' + (m ? 'text-bg-success' : 'text-bg-secondary'); }
  }
  [master, ipOn, userOn].forEach(function(e){ if (e) e.addEventListener('change', sync); });
  if (master) sync();
})();
</script>

<?php require_once __DIR__.'/footer.php'; ?>
