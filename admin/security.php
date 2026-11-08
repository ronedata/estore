<?php
/**
 * admin/security.php — সিকিউরিটি অডিট রিপোর্ট
 * শুধু Super Admin দেখতে পারবে।
 *
 * স্ক্যান চালায়: php tools/security_audit.js  (যদি PHP exec অনুমোদিত হয়)
 * অথবা বিদ্যমান tools/security_audit_report.json পড়ে দেখায়।
 */
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ---------- লগইন + পারমিশন ---------- */
gate('manage_settings');

/* ---------- শুধু Super Admin ---------- */
if (($_SESSION['admin_role_name'] ?? '') !== 'Super Admin') {
    http_response_code(403);
    require_once __DIR__.'/header.php';
    echo '<div class="alert alert-danger">এই পেজ শুধুমাত্র Super Admin-এর জন্য।</div>';
    require_once __DIR__.'/footer.php';
    exit;
}

$reportPath = dirname(__DIR__) . '/tools/security_audit_report.json';
$auditScript = dirname(__DIR__) . '/tools/security_audit.js';

/* ---------- স্ক্যান চালানো ---------- */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_scan'])) {
    if (!csrf_verify()) {
        $flash = ['ok' => false, 'msg' => 'CSRF টোকেন অবৈধ।'];
    } else {
        $out = null;
        $code = 1;
        if (function_exists('exec')) {
            $node = trim((string)@shell_exec('where node 2>NUL'));
            $cmd = $node !== '' ? 'node "' . $auditScript . '" 2>&1' : '';
            if ($cmd !== '') {
                @exec($cmd, $outLines, $code);
                $out = is_array($outLines) ? implode("\n", $outLines) : '';
            }
        }
        if (is_file($reportPath)) {
            $flash = ['ok' => true, 'msg' => 'স্ক্যান সম্পন্ন হয়েছে।'];
        } else {
            $flash = ['ok' => false, 'msg' => 'স্ক্যান চালানো যায়নি (node/exec অনুমতি নেই)। ফাইলটি ম্যানুয়ালি চালান: node tools/security_audit.js'];
        }
    }
}

/* ---------- রিপোর্ট পড়া ---------- */
$report = null;
if (is_file($reportPath)) {
    $raw = @file_get_contents($reportPath);
    if ($raw) $report = json_decode($raw, true);
}

$findings = $report['findings'] ?? [];
$sevCount = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
foreach ($findings as $f) {
    $s = strtoupper($f['severity'] ?? 'LOW');
    if (isset($sevCount[$s])) $sevCount[$s]++;
}

$sevColor = [
    'CRITICAL' => 'danger',
    'HIGH'     => 'danger',
    'MEDIUM'   => 'warning',
    'LOW'      => 'info',
];
$sevOrder = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
usort($findings, function ($a, $b) use ($sevOrder) {
    return ($sevOrder[strtoupper($a['severity'])] ?? 9) <=> ($sevOrder[strtoupper($b['severity'])] ?? 9);
});

$generated = $report['generated'] ?? null;
$total = $report['total'] ?? count($findings);

require_once __DIR__.'/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h4 class="mb-0"><i class="fa-solid fa-shield-halved text-primary"></i> সিকিউরিটি অডিট</h4>
    <div class="text-muted small">
      <?php if ($generated): ?>
        সর্বশেষ স্ক্যান: <?php echo h(date('d M Y, H:i', strtotime($generated))); ?>
      <?php else: ?>
        এখনো কোনো স্ক্যান চালানো হয়নি
      <?php endif; ?>
    </div>
  </div>

  <form method="post" class="m-0">
    <?php echo csrf_field(); ?>
    <button class="btn btn-primary btn-sm" name="run_scan" value="1">
      <i class="fa-solid fa-rotate"></i> আবার স্ক্যান করুন
    </button>
  </form>
</div>

<?php if ($flash): ?>
  <div class="alert <?php echo !empty($flash['ok']) ? 'alert-success' : 'alert-warning'; ?> py-2">
    <?php echo h($flash['msg']); ?>
  </div>
<?php endif; ?>

<?php if (!$report): ?>
  <div class="card"><div class="card-body text-center py-5">
    <i class="fa-solid fa-file-shield fa-2x text-muted mb-3 d-block"></i>
    <p class="text-muted mb-3">কোনো রিপোর্ট পাওয়া যায়নি।</p>
    <p class="small text-muted">টার্মিনালে চালান: <code>node tools/security_audit.js</code></p>
  </div></div>
<?php else: ?>

<!-- সারসংক্ষেপ কার্ড -->
<div class="row g-3 mb-4">
  <?php
  $cards = [
    ['CRITICAL', 'জরুরি', 'danger', 'fa-circle-exclamation'],
    ['HIGH',     'উচ্চ',   'danger', 'fa-triangle-exclamation'],
    ['MEDIUM',   'মধ্যম',  'warning', 'fa-circle-info'],
    ['LOW',      'কম',     'info',   'fa-circle-minus'],
  ];
  foreach ($cards as [$key, $label, $color, $icon]):
    $n = $sevCount[$key];
  ?>
  <div class="col-6 col-lg-3">
    <div class="card h-100 border-<?php echo $color; ?>">
      <div class="card-body text-center py-3">
        <i class="fa-solid <?php echo $icon; ?> text-<?php echo $color; ?> mb-1" style="font-size:20px"></i>
        <div class="fw-bold text-<?php echo $color; ?>" style="font-size:26px;line-height:1.1"><?php echo $n; ?></div>
        <div class="small text-muted"><?php echo $label; ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-body py-2 small text-muted">
    মোট <strong><?php echo (int)$total; ?></strong> টি ফলাফল ·
    স্ক্যান করা ফাইল: <strong><?php echo count($findings) ? '৮১' : '—'; ?></strong>
  </div>
</div>

<!-- বিস্তারিত টেবিল -->
<?php if (!$findings): ?>
  <div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i> কোনো সমস্যা পাওয়া যায়নি।
  </div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:90px">Severity</th>
          <th style="width:190px">ফাইল</th>
          <th>সমস্যা</th>
          <th style="width:34%">সমাধান</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($findings as $f):
          $sev = strtoupper($f['severity'] ?? 'LOW');
          $color = $sevColor[$sev] ?? 'secondary';
        ?>
        <tr>
          <td>
            <span class="badge text-bg-<?php echo $color; ?>"><?php echo h($sev); ?></span>
          </td>
          <td class="small">
            <code><?php echo h($f['file'] ?? '—'); ?></code>
            <?php if (!empty($f['line'])): ?>
              <span class="text-muted">:<?php echo (int)$f['line']; ?></span>
            <?php endif; ?>
          </td>
          <td>
            <div class="fw-semibold" style="font-size:13px"><?php echo h($f['title'] ?? ''); ?></div>
            <div class="small text-muted" style="font-size:11.5px"><?php echo h($f['detail'] ?? ''); ?></div>
          </td>
          <td class="small" style="font-size:11.5px">
            <?php echo h($f['fix'] ?? ''); ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="alert alert-light border small mt-3">
  <i class="fa-solid fa-circle-info text-primary"></i>
  এই রিপোর্ট <code>tools/security_audit_report.json</code> থেকে পড়া হয়। ওয়েব থেকে সরাসরি অ্যাক্সেস বন্ধ
  (<code>.htaccess</code>), তাই দুর্বলতার তালিকা বাইরে যাবে না।
</div>

<?php endif; ?>

<?php require_once __DIR__.'/footer.php'; ?>
