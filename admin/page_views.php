<?php
// admin/page_views.php — পেজ ও প্রোডাক্ট ভিউ রিপোর্ট
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../includes/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('view_page_views');

$pdo = get_pdo();

/* ---------- Clear actions (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok' => false, 'msg' => 'সিকিউরিটি টোকেন মিলছে না।'];
    header('Location: page_views.php'); exit;
  }

  $what = $_POST['clear'] ?? '';
  try {
    if ($what === 'all') {
      $pdo->exec("DELETE FROM page_views");
      $_SESSION['flash'] = ['ok' => true, 'msg' => 'সব পেজ ভিউ কাউন্ট মুছে ফেলা হয়েছে।'];
    } elseif ($what === 'products') {
      $n = $pdo->exec("DELETE FROM page_views WHERE page LIKE 'product\\_%'");
      $_SESSION['flash'] = ['ok' => true, 'msg' => (int)$n.' টি প্রোডাক্ট ভিউ কাউন্ট মুছে ফেলা হয়েছে।'];
    }
  } catch (Throwable $e) {
    $_SESSION['flash'] = ['ok' => false, 'msg' => 'মুছতে ব্যর্থ: '.$e->getMessage()];
  }
  header('Location: page_views.php'); exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- সংখ্যা সংক্ষিপ্ত করা (1000 → 1k) ---------- */
function pv_short($n): string {
  $n = (int)$n;
  if ($n >= 999950) { // ৯৯৯.৯৫k+ → M
    return rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.').'M';
  }
  if ($n >= 1000) {
    return rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.').'k';
  }
  return number_format($n);
}

/* ---------- পেজ ভিউ ---------- */
$rows = [];
try {
  $rows = $pdo->query("SELECT page, views FROM page_views ORDER BY views DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }

$totalViews = 0;
foreach ($rows as $r) $totalViews += (int)$r['views'];

/* ---------- প্রোডাক্ট ভিউ ---------- */
$productRows = [];
try {
  $productRows = $pdo->query("
    SELECT pv.page, pv.views, p.id, p.name, p.image, p.price, p.stock, p.active
    FROM page_views pv
    LEFT JOIN products p ON p.id = CAST(SUBSTRING(pv.page, 9) AS UNSIGNED)
    WHERE pv.page REGEXP '^product_[0-9]+$'
    ORDER BY pv.views DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $productRows = []; }

$totalProductViews = 0;
foreach ($productRows as $r) $totalProductViews += (int)$r['views'];

$sumAll = $totalViews + $totalProductViews;
$grand = max(1, $sumAll);

/* সর্বোচ্চ ভিউ (দুই টেবিল মিলিয়ে) */
$topPage = '—'; $topPageViews = 0;
foreach (array_merge($rows, $productRows) as $r) {
  if ((int)$r['views'] > $topPageViews) { $topPageViews = (int)$r['views']; $topPage = (string)$r['page']; }
}

/* প্রোডাক্ট আইডি → নাম ম্যাপ (লেবেলের জন্য) */
$prodName = [];
foreach ($productRows as $r) {
  if (!empty($r['name'])) $prodName[(int)$r['id']] = $r['name'];
}

/* পেজ নামকে বন্ধুত্বপূর্ণ লেবেলে রূপান্তর */
function pv_label(string $page, array $prodName = []): string {
  static $map = [
    'index'    => 'হোম',
    'shop'     => 'শপ / সব পণ্য',
    'product'  => 'প্রোডাক্ট (পুরোনো লিংক)',
    'about'    => 'আমাদের সম্পর্কে',
    'contact'  => 'যোগাযোগ',
    'privacy'  => 'প্রাইভেসি পলিসি',
    'terms'    => 'শর্তাবলী',
    'deals'    => 'ডিলস',
    'wishlist' => 'উইশলিস্ট',
    'cart'     => 'কার্ট',
    'checkout' => 'চেকআউট',
    'track'    => 'অর্ডার ট্র্যাক',
    'search'   => 'সার্চ',
  ];
  if (isset($map[$page])) return $map[$page];
  if (preg_match('~^category_(\d+)$~', $page, $m)) return 'ক্যাটাগরি #'.$m[1];
  if (preg_match('~^product_(\d+)$~', $page, $m)) {
    $pid = (int)$m[1];
    return isset($prodName[$pid]) ? $prodName[$pid] : ('প্রোডাক্ট #'.$pid);
  }
  return $page;
}

$tab = ($_GET['tab'] ?? 'pages') === 'products' ? 'products' : 'pages';

require_once __DIR__.'/header.php';
?>
<style>
  .pv-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
  @media(max-width:991px){.pv-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:575px){.pv-cards{grid-template-columns:1fr}}
  .pv-card{background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:14px 16px}
  .pv-card .lbl{font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
  .pv-card .val{font-size:1.45rem;font-weight:800;color:#0f172a;line-height:1.3;margin-top:2px}
  .pv-card .ico{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
  .pv-bar{height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden;min-width:60px}
  .pv-bar > span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#0284c7,#38bdf8)}
  .pv-rank{width:26px;height:26px;border-radius:8px;background:#f1f5f9;color:#475569;
           display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
  .pv-rank.top1{background:#fef3c7;color:#b45309}
  .pv-rank.top2{background:#e2e8f0;color:#475569}
  .pv-rank.top3{background:#ffe4e6;color:#be123c}
  .pv-tabs{display:flex;gap:6px;background:#f1f5f9;padding:4px;border-radius:10px}
  .pv-tabs a{flex:1;text-align:center;padding:7px 14px;border-radius:8px;text-decoration:none;
             font-size:.85rem;font-weight:700;color:#64748b;transition:background .2s,color .2s}
  .pv-tabs a.active{background:#fff;color:#0284c7;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .pv-thumb{width:38px;height:38px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;background:#fff}
  .pv-empty{text-align:center;color:#94a3b8;padding:44px 0}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="m-0"><i class="fa-solid fa-chart-simple text-primary me-1"></i> Page Views</h5>
    <div class="text-muted small">পেজ ও প্রোডাক্ট কতবার দেখা হয়েছে</div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <span class="text-muted small d-none d-sm-inline">আজ: <?php echo h(date('d M Y')); ?></span>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-danger dropdown-toggle" data-bs-toggle="dropdown">
        <i class="fa-regular fa-trash-can"></i> কাউন্ট রিসেট
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li>
          <form method="post" onsubmit="return confirm('প্রোডাক্ট ভিউ কাউন্ট মুছে ফেলতে চান?')" class="px-2 py-1">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="clear" value="products">
            <button class="dropdown-item text-danger rounded">
              <i class="fa-solid fa-box"></i> শুধু প্রোডাক্ট ভিউ
            </button>
          </form>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
          <form method="post" onsubmit="return confirm('সব পেজ ও প্রোডাক্ট ভিউ কাউন্ট মুছে যাবে। নিশ্চিত?')" class="px-2 py-1">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="clear" value="all">
            <button class="dropdown-item text-danger rounded">
              <i class="fa-solid fa-trash"></i> সব কাউন্ট
            </button>
          </form>
        </li>
      </ul>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert <?php echo !empty($flash['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2">
    <?php echo h($flash['msg'] ?? ''); ?>
  </div>
<?php endif; ?>

<div class="pv-cards">
  <div class="pv-card d-flex justify-content-between align-items-start">
    <div>
      <div class="lbl">মোট ভিউ</div>
      <div class="val" title="<?php echo number_format($sumAll); ?>"><?php echo pv_short($sumAll); ?></div>
    </div>
    <div class="ico" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-eye"></i></div>
  </div>
  <div class="pv-card d-flex justify-content-between align-items-start">
    <div>
      <div class="lbl">পেজ ভিউ</div>
      <div class="val" title="<?php echo number_format($totalViews); ?>"><?php echo pv_short($totalViews); ?></div>
      <div class="small text-muted"><?php echo count($rows); ?> পেজ</div>
    </div>
    <div class="ico" style="background:#dcfce7;color:#16a34a"><i class="fa-regular fa-file-lines"></i></div>
  </div>
  <div class="pv-card d-flex justify-content-between align-items-start">
    <div>
      <div class="lbl">প্রোডাক্ট ভিউ</div>
      <div class="val" title="<?php echo number_format($totalProductViews); ?>"><?php echo pv_short($totalProductViews); ?></div>
      <div class="small text-muted"><?php echo count($productRows); ?> প্রোডাক্ট</div>
    </div>
    <div class="ico" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-box-open"></i></div>
  </div>
  <div class="pv-card d-flex justify-content-between align-items-start">
    <div>
      <div class="lbl">সর্বোচ্চ</div>
      <div class="val" title="<?php echo number_format($topPageViews); ?>"><?php echo pv_short($topPageViews); ?></div>
      <div class="small text-muted text-truncate" style="max-width:150px">
        <?php echo h(pv_label($topPage, $prodName)); ?>
      </div>
    </div>
    <div class="ico" style="background:#f3e8ff;color:#7c3aed"><i class="fa-solid fa-trophy"></i></div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="p-3 pb-2">
      <div class="pv-tabs">
        <a class="<?php echo $tab === 'pages' ? 'active' : ''; ?>" href="page_views.php?tab=pages">
          <i class="fa-regular fa-file-lines"></i> পেজ (<?php echo count($rows); ?>)
        </a>
        <a class="<?php echo $tab === 'products' ? 'active' : ''; ?>" href="page_views.php?tab=products">
          <i class="fa-solid fa-box-open"></i> প্রোডাক্ট (<?php echo count($productRows); ?>)
        </a>
      </div>
    </div>

    <div class="table-responsive">
      <?php if ($tab === 'pages'): ?>
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:56px">#</th>
            <th>পেজ</th>
            <th class="text-end" style="width:100px">ভিউ</th>
            <th style="width:170px" class="d-none d-md-table-cell">শেয়ার</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="pv-empty">
            <i class="fa-regular fa-chart-bar d-block mb-2" style="font-size:1.6rem"></i>
            এখনো কোনো পেজ ভিউ রেকর্ড হয়নি।
          </td></tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $r):
            $views = (int)$r['views'];
            $pct   = $grand > 0 ? ($views / $grand) * 100 : 0;
            $cls   = $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : ''));
          ?>
          <tr>
            <td><span class="pv-rank <?php echo $cls; ?>"><?php echo $i + 1; ?></span></td>
            <td>
              <div class="fw-semibold"><?php echo h(pv_label((string)$r['page'], $prodName)); ?></div>
              <div class="text-muted small"><code><?php echo h($r['page']); ?></code></div>
            </td>
            <td class="text-end fw-bold" title="<?php echo number_format($views); ?>"><?php echo pv_short($views); ?></td>
            <td class="d-none d-md-table-cell">
              <div class="d-flex align-items-center gap-2">
                <div class="pv-bar flex-grow-1"><span style="width:<?php echo number_format($pct, 1, '.', ''); ?>%"></span></div>
                <span class="small text-muted" style="min-width:42px;text-align:right"><?php echo number_format($pct, 1); ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
      <?php else: ?>
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:56px">#</th>
            <th>প্রোডাক্ট</th>
            <th class="text-end" style="width:100px">ভিউ</th>
            <th style="width:170px" class="d-none d-md-table-cell">শেয়ার</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$productRows): ?>
          <tr><td colspan="4" class="pv-empty">
            <i class="fa-solid fa-box-open d-block mb-2" style="font-size:1.6rem"></i>
            এখনো কোনো প্রোডাক্ট ভিউ রেকর্ড হয়নি।
          </td></tr>
        <?php else: ?>
          <?php foreach ($productRows as $i => $r):
            $views = (int)$r['views'];
            $pct   = $grand > 0 ? ($views / $grand) * 100 : 0;
            $cls   = $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : ''));
            $pid   = (int)($r['id'] ?? 0);
          ?>
          <tr>
            <td><span class="pv-rank <?php echo $cls; ?>"><?php echo $i + 1; ?></span></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <?php if (!empty($r['image'])): ?>
                  <img src="<?php echo h(img_url($r['image'])); ?>" alt="" class="pv-thumb"
                       onerror="this.onerror=null;this.src='<?php echo h(img_fallback_url()); ?>'">
                <?php else: ?>
                  <div class="pv-thumb d-flex align-items-center justify-content-center text-muted">
                    <i class="fa-regular fa-image"></i>
                  </div>
                <?php endif; ?>
                <div class="min-w-0">
                  <?php if ($pid > 0 && !empty($r['name'])): ?>
                    <a href="<?php echo BASE_URL; ?>/product_details.php?id=<?php echo $pid; ?>"
                       target="_blank" rel="noopener" class="fw-semibold text-decoration-none text-dark d-block text-truncate"
                       style="max-width:320px">
                      <?php echo h($r['name']); ?>
                    </a>
                    <div class="text-muted small">
                      #<?php echo $pid; ?>
                      <?php if (isset($r['active']) && (int)$r['active'] === 0): ?>
                        <span class="badge bg-secondary ms-1">নিষ্ক্রিয়</span>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div class="fw-semibold text-muted">মুছে ফেলা প্রোডাক্ট</div>
                    <div class="text-muted small"><code><?php echo h($r['page']); ?></code></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td class="text-end fw-bold" title="<?php echo number_format($views); ?>"><?php echo pv_short($views); ?></td>
            <td class="d-none d-md-table-cell">
              <div class="d-flex align-items-center gap-2">
                <div class="pv-bar flex-grow-1"><span style="width:<?php echo number_format($pct, 1, '.', ''); ?>%"></span></div>
                <span class="small text-muted" style="min-width:42px;text-align:right"><?php echo number_format($pct, 1); ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__.'/footer.php'; ?>
