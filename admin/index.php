<?php
// পারমিশন চেক আগে করতে হবে
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('view_dashboard'); // <-- পারমিশন চেক
require_once __DIR__.'/header.php';
$pdo = get_pdo();
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/finance.php';

/* ---- Fallback helpers (if not loaded globally) ---- */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money_bd')) {
  function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); }
}
if (!function_exists('today')) {
  function today(){ return date('Y-m-d'); }
}

/* ---- Settings / thresholds ---- */
$LOW_STOCK_DEFAULT = 5;
try {
  $stm = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` = ?");
  $stm->execute(['low_stock_threshold']);
  $cfg = $stm->fetchAll(PDO::FETCH_KEY_PAIR);
  $LOW_STOCK = isset($cfg['low_stock_threshold']) && (int)$cfg['low_stock_threshold'] > 0
    ? (int)$cfg['low_stock_threshold'] : $LOW_STOCK_DEFAULT;
} catch (Throwable $e) {
  $LOW_STOCK = $LOW_STOCK_DEFAULT;
}

/* ---- আজ ও গতকাল ---- */
$td = today();
$yd = date('Y-m-d', strtotime('-1 day'));
$dayStat = function (string $d) use ($pdo): array {
  $s = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0), COALESCE(SUM(status='pending'),0) FROM orders WHERE DATE(created_at)=?");
  $s->execute([$d]);
  [$n, $rev, $pend] = $s->fetch(PDO::FETCH_NUM);
  return ['orders' => (int)$n, 'revenue' => (float)$rev, 'pending' => (int)$pend];
};
$dToday = $dayStat($td); $dYest = $dayStat($yd);
$orders_today = $dToday['orders']; $revenue_today = $dToday['revenue']; $pending_today = $dToday['pending'];

$stm = $pdo->prepare("SELECT COUNT(*) FROM products WHERE stock <= ?");
$stm->execute([$LOW_STOCK]); $low_stock_count = (int)$stm->fetchColumn();
$out_of_stock = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE active=1 AND stock<=0")->fetchColumn();

/** পরিবর্তনের শতাংশ: [দিক: up|down|flat|new|none, লেখা] */
$delta = function (float $cur, float $prev): array {
  if ($prev <= 0 && $cur <= 0) return ['none', '—'];
  if ($prev <= 0) return ['new', 'নতুন'];
  $p = ($cur - $prev) / $prev * 100;
  if (abs($p) < 0.5) return ['flat', '০%'];
  return [$p > 0 ? 'up' : 'down', number_format(abs($p), 0) . '%'];
};

/* ---- বিক্রয় বিশ্লেষণ (শুধু রিপোর্ট-পারমিশন থাকলে) ---- */
$showSales = can('view_reports');
$mSum = $pSum = $mCats = $series = $mPnl = $mStatus = null;
if ($showSales) {
  try {
    $mRange = rp_parse_range(['type' => 'monthly']);
    $pRange = rp_parse_range(['type' => 'monthly', 'year' => (string)date('Y', strtotime('first day of last month')), 'month' => (string)date('n', strtotime('first day of last month'))]);
    $mSum = rp_summary($pdo, $mRange);
    $pSum = rp_summary($pdo, $pRange);
    $mCats = array_slice(rp_sales_by_category($pdo, $mRange), 0, 5);
    $series = rp_monthly_series($pdo, 6);
    $mPnl = fi_pnl($pdo, $mRange, 'all');
    $mStatus = rp_by_status($pdo, $mRange);
  } catch (Throwable $e) { $showSales = false; }
}

/* ---- মনোযোগ দরকার ---- */
$oldPending = 0; $supplierDue = 0.0;
$canOrders = can('manage_orders') || can('view_reports');
if ($canOrders) { try { $oldPending = rp_old_pending($pdo, 3); } catch (Throwable $e) {} }
$canDue = can('manage_purchases');
if ($canDue) { try { $supplierDue = pu_totals($pdo, null, null)['due']; } catch (Throwable $e) {} }

/* ---- সাম্প্রতিক অর্ডার (সর্বশেষ ৮টি) ---- */
$stm = $pdo->prepare("SELECT o.id, o.order_code, o.customer_name, o.mobile, o.total, o.status, o.created_at,
                             COALESCE(SUM(oi.qty), 0) AS item_count
                      FROM orders o
                      LEFT JOIN order_items oi ON o.id = oi.order_id
                      GROUP BY o.id
                      ORDER BY o.created_at DESC, o.id DESC
                      LIMIT 8");
$stm->execute();
$recent = $stm->fetchAll();

/* ---- Badge class ---- */
function status_badge_class($s){
  switch ($s) {
    case 'pending':    return 'bg-warning text-dark';
    case 'processing': return 'bg-info text-dark';
    case 'shipped':    return 'bg-primary';
    case 'delivered':  return 'bg-success';
    case 'cancelled':  return 'bg-secondary';
    default:           return 'bg-light text-dark';
  }
}

$hr = (int)date('G');
$greet = $hr < 12 ? 'শুভ সকাল' : ($hr < 15 ? 'শুভ দুপুর' : ($hr < 18 ? 'শুভ বিকাল' : ($hr < 20 ? 'শুভ সন্ধ্যা' : 'শুভ রাত্রি')));
$uname = (string)($_SESSION['admin_username'] ?? '');

/* দ্রুত অ্যাকশন: [পারমিশন, লিংক, আইকন, লেখা, রঙ-ক্লাস] */
$qa = [
  ['manage_products', 'product_form.php', 'fa-plus', 'নতুন পণ্য', 'qa-blue'],
  ['manage_products', 'products.php', 'fa-boxes-stacked', 'পণ্য তালিকা', 'qa-indigo'],
  ['manage_orders', 'orders.php?from=' . $td . '&to=' . $td . '&status=all', 'fa-list-ul', 'আজকের অর্ডার', 'qa-slate'],
  ['manage_purchases', 'purchase_form.php', 'fa-cart-flatbed', 'নতুন ক্রয়', 'qa-teal'],
  ['manage_expenses', 'expenses.php', 'fa-wallet', 'খরচ যোগ', 'qa-rose'],
  ['manage_categories', 'categories.php', 'fa-tags', 'ক্যাটাগরি', 'qa-green'],
  ['view_stock_report', 'stock_report.php', 'fa-warehouse', 'স্টক রিপোর্ট', 'qa-cyan'],
  ['view_reports', 'report_profit.php', 'fa-chart-line', 'লাভ-ক্ষতি', 'qa-violet'],
  ['payments', 'payments.php', 'fa-credit-card', 'Payment', 'qa-amber'],
];
$qa = array_values(array_filter($qa, fn($x) => can($x[0])));
?>
<!-- Font Awesome (safe inject if missing) -->
<script>
(function(){
  if(!document.querySelector('link[href*="font-awesome"][href*="cdnjs"]')){
    var l=document.createElement('link');
    l.rel='stylesheet';
    l.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
    document.head.appendChild(l);
  }
})();
</script>

<style>
  :root{ --dash-radius:16px; --dash-shadow:0 1px 2px rgba(15,23,42,.04), 0 8px 24px rgba(15,23,42,.06); }

  /* হিরো */
  .dash-hero{
    background:linear-gradient(120deg,#0f172a 0%,#1e3a8a 55%,#4f46e5 100%);
    color:#fff; border-radius:var(--dash-radius); padding:20px 22px; position:relative; overflow:hidden;
    box-shadow:0 10px 30px rgba(30,58,138,.25);
  }
  .dash-hero::after{ content:""; position:absolute; right:-40px; top:-60px; width:220px; height:220px; border-radius:50%;
    background:radial-gradient(circle at 30% 30%, rgba(255,255,255,.22), rgba(255,255,255,0) 70%); }
  .dash-hero h4{ font-weight:800; margin:0 0 4px; }
  .dash-hero .sub{ opacity:.85; font-size:.92rem; }
  .dash-hero .chip{ display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.18);
    border-radius:999px; padding:4px 12px; font-size:.82rem; margin-right:6px; margin-top:10px; }

  /* কার্ড */
  .dcard{ background:#fff; border:1px solid #eaeef5; border-radius:var(--dash-radius); box-shadow:var(--dash-shadow); }
  .kpi-card{ transition:transform .15s ease, box-shadow .15s ease; height:100%; }
  .kpi-card:hover{ transform:translateY(-2px); box-shadow:0 2px 4px rgba(15,23,42,.05), 0 14px 30px rgba(15,23,42,.10); }
  .kpi-icon{ width:46px; height:46px; border-radius:14px; display:inline-flex; align-items:center; justify-content:center; font-size:1.1rem; color:#fff; flex:0 0 auto; }
  .ic-blue{ background:linear-gradient(135deg,#60a5fa,#2563eb); }
  .ic-cyan{ background:linear-gradient(135deg,#22d3ee,#0891b2); }
  .ic-amber{ background:linear-gradient(135deg,#fbbf24,#d97706); }
  .ic-green{ background:linear-gradient(135deg,#4ade80,#16a34a); }
  .ic-indigo{ background:linear-gradient(135deg,#818cf8,#4f46e5); }
  .ic-emerald{ background:linear-gradient(135deg,#34d399,#059669); }
  .ic-rose{ background:linear-gradient(135deg,#fb7185,#e11d48); }
  .ic-violet{ background:linear-gradient(135deg,#a78bfa,#7c3aed); }
  .kpi-label{ color:#64748b; font-size:.82rem; }
  .kpi-value{ font-weight:800; font-size:1.4rem; line-height:1.2; color:#0f172a; }
  .kpi-sub{ font-size:.78rem; color:#94a3b8; }
  .delta{ display:inline-flex; align-items:center; gap:3px; font-weight:700; font-size:.76rem; border-radius:999px; padding:1px 8px; }
  .delta.up{ background:#dcfce7; color:#15803d; } .delta.down{ background:#fee2e2; color:#b91c1c; }
  .delta.flat,.delta.none{ background:#f1f5f9; color:#64748b; } .delta.new{ background:#e0e7ff; color:#4338ca; }
  .sec-title{ font-weight:800; font-size:1rem; color:#0f172a; margin:0; }
  .sec-sub{ font-size:.8rem; color:#94a3b8; }

  /* মনোযোগ দরকার */
  .alert-row{ display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:12px; text-decoration:none; color:inherit; border:1px solid #f1f5f9; margin-bottom:8px; transition:background .12s; }
  .alert-row:hover{ background:#f8fafc; color:inherit; }
  .alert-row .ai{ width:36px; height:36px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto; }
  .alert-row .cnt{ margin-left:auto; font-weight:800; font-size:1.05rem; }
  .ai-red{ background:#fee2e2; color:#dc2626; } .ai-amber{ background:#fef3c7; color:#d97706; } .ai-slate{ background:#e2e8f0; color:#475569; } .ai-blue{ background:#dbeafe; color:#2563eb; }

  /* চার্ট */
  #dashChart{ position:relative; height:190px; padding-top:6px;
    background:repeating-linear-gradient(to top, transparent 0, transparent 37px, #eef2f7 37px, #eef2f7 38px); }
  #dashChart .bar-col{ height:100%; min-width:0; }
  #dashChart .bar{ width:68%; max-width:56px; border-radius:10px 10px 0 0; background:linear-gradient(180deg,#818cf8,#4f46e5); transition:filter .15s; }
  #dashChart .bar-col:hover .bar{ filter:brightness(1.1); }
  #dashChart .bar.now{ background:linear-gradient(180deg,#34d399,#059669); }
  #dashChart .bar.zero{ opacity:.25; }

  /* ডোনাট */
  .donut{ width:132px; height:132px; border-radius:50%; position:relative; flex:0 0 auto; }
  .donut::after{ content:""; position:absolute; inset:24px; background:#fff; border-radius:50%; box-shadow:inset 0 0 0 1px #eef2f7; }
  .legend-dot{ width:10px; height:10px; border-radius:50%; display:inline-block; flex:0 0 auto; }

  /* স্ট্যাটাস বার */
  .stbar{ display:flex; height:12px; border-radius:999px; overflow:hidden; background:#eef2f7; }
  .stbar span{ display:block; height:100%; }

  /* দ্রুত অ্যাকশন */
  .qa-tile{ display:flex; flex-direction:column; align-items:center; gap:8px; padding:14px 8px; border-radius:14px; text-decoration:none; color:#334155;
    background:#f8fafc; border:1px solid #eef2f7; font-weight:600; font-size:.86rem; text-align:center; transition:all .15s ease; height:100%; }
  .qa-tile:hover{ transform:translateY(-2px); background:#fff; box-shadow:0 10px 24px rgba(15,23,42,.10); color:#0f172a; }
  .qa-tile .qi{ width:44px; height:44px; border-radius:14px; display:inline-flex; align-items:center; justify-content:center; color:#fff; font-size:1.05rem; }
  .qa-blue .qi{ background:linear-gradient(135deg,#60a5fa,#2563eb); } .qa-indigo .qi{ background:linear-gradient(135deg,#818cf8,#4f46e5); }
  .qa-slate .qi{ background:linear-gradient(135deg,#94a3b8,#475569); } .qa-teal .qi{ background:linear-gradient(135deg,#2dd4bf,#0d9488); }
  .qa-rose .qi{ background:linear-gradient(135deg,#fb7185,#e11d48); } .qa-green .qi{ background:linear-gradient(135deg,#4ade80,#16a34a); }
  .qa-cyan .qi{ background:linear-gradient(135deg,#22d3ee,#0891b2); } .qa-violet .qi{ background:linear-gradient(135deg,#a78bfa,#7c3aed); }
  .qa-amber .qi{ background:linear-gradient(135deg,#fbbf24,#d97706); }

  /* অর্ডার-টেবিল */
  .ord-table th{ font-size:.78rem; color:#64748b; font-weight:700; text-transform:none; background:#f8fafc; border-bottom:1px solid #eef2f7; }
  .ord-table td{ border-color:#f1f5f9; }
  .badge-status{ min-width:94px; display:inline-flex; align-items:center; justify-content:center; border-radius:999px; font-weight:600; padding:.4em .8em; }
  .table-responsive{ overflow-x:auto; }
  @media (max-width: 576px){
    .dash-hero{ padding:16px; } .kpi-value{ font-size:1.2rem; } .kpi-icon{ width:40px; height:40px; }
    .table-responsive{ border:0; }
    table.ord-table{ display:block; } table.ord-table thead{ display:none; } table.ord-table tbody{ display:block; }
    table.ord-table tr{ display:block; background:#fff; border:1px solid #e9ecef; border-radius:12px; padding:12px; margin:10px; box-shadow:0 6px 16px rgba(2,8,23,.04); }
    table.ord-table td{ display:flex; justify-content:space-between; gap:12px; padding:6px 0 !important; border:0 !important; }
    table.ord-table td::before{ content:attr(data-label); font-weight:600; color:#64748b; min-width:90px; text-align:left; }
    .td-actions{ display:flex; flex-direction:row; gap:8px; justify-content:flex-end !important; }
    .donut{ width:110px; height:110px; } .donut::after{ inset:20px; }
  }
</style>

<!-- হিরো -->
<div class="dash-hero mb-3" id="dashHero">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
      <h4><?php echo h($greet); ?><?php echo $uname !== '' ? ', ' . h($uname) : ''; ?></h4>
      <div class="sub">আজকের দোকানের অবস্থা এক নজরে</div>
      <div>
        <span class="chip"><i class="fa-regular fa-calendar"></i> <?php echo h(date('l, d M Y')); ?></span>
        <span class="chip"><i class="fa-solid fa-receipt"></i> আজ <?php echo $orders_today; ?>টি অর্ডার</span>
        <span class="chip"><i class="fa-solid fa-sack-dollar"></i> <?php echo money_bd($revenue_today); ?></span>
      </div>
    </div>
  </div>
</div>

<!-- আজকের KPI -->
<?php [$dOrdDir, $dOrdTxt] = $delta((float)$dToday['orders'], (float)$dYest['orders']); [$dRevDir, $dRevTxt] = $delta($dToday['revenue'], $dYest['revenue']);
$arrow = ['up' => '▲', 'down' => '▼', 'flat' => '■', 'new' => '★', 'none' => '']; ?>
<div class="row g-3 mb-3" id="dashToday">
  <div class="col-6 col-xl-3">
    <div class="dcard kpi-card"><div class="p-3 d-flex align-items-center gap-3">
      <span class="kpi-icon ic-blue"><i class="fa-solid fa-receipt"></i></span>
      <div class="min-w-0">
        <div class="kpi-label">আজকের অর্ডার</div>
        <div class="kpi-value" id="kOrdersToday"><?php echo $orders_today; ?></div>
        <div class="kpi-sub"><span class="delta <?php echo $dOrdDir; ?>"><?php echo $arrow[$dOrdDir] . ' ' . h($dOrdTxt); ?></span> গতকাল <?php echo $dYest['orders']; ?>টি</div>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="dcard kpi-card"><div class="p-3 d-flex align-items-center gap-3">
      <span class="kpi-icon ic-cyan"><i class="fa-solid fa-sack-dollar"></i></span>
      <div class="min-w-0">
        <div class="kpi-label">আজকের রেভিনিউ</div>
        <div class="kpi-value" id="kRevenueToday"><?php echo money_bd($revenue_today); ?></div>
        <div class="kpi-sub"><span class="delta <?php echo $dRevDir; ?>"><?php echo $arrow[$dRevDir] . ' ' . h($dRevTxt); ?></span> গতকাল <?php echo money_bd($dYest['revenue']); ?></div>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="dcard kpi-card"><div class="p-3 d-flex align-items-center gap-3">
      <span class="kpi-icon ic-amber"><i class="fa-solid fa-clock"></i></span>
      <div class="min-w-0">
        <div class="kpi-label">পেন্ডিং (আজ)</div>
        <div class="kpi-value" id="kPendingToday"><?php echo $pending_today; ?></div>
        <div class="kpi-sub">আজকের অর্ডারের মধ্যে</div>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="dcard kpi-card"><div class="p-3 d-flex align-items-center gap-3">
      <span class="kpi-icon ic-green"><i class="fa-solid fa-box-open"></i></span>
      <div class="min-w-0">
        <div class="kpi-label">লো-স্টক (≤ <?php echo (int)$LOW_STOCK; ?>)</div>
        <div class="kpi-value" id="kLowStock"><?php echo $low_stock_count; ?></div>
        <div class="kpi-sub"><?php echo $out_of_stock; ?>টি স্টক-আউট</div>
      </div>
    </div></div>
  </div>
</div>

<?php if ($showSales):
  [$mRevDir, $mRevTxt] = $delta((float)$mSum['revenue'], (float)$pSum['revenue']);
  [$mOrdDir, $mOrdTxt] = $delta((float)$mSum['valid'], (float)$pSum['valid']);
  $unk = (int)($mPnl['unknown_lines'] ?? 0);
?>
<!-- এই মাসের বিক্রয় -->
<div class="row g-3 mb-3" id="dashSales">
  <div class="col-6 col-xl-3">
    <div class="dcard kpi-card"><div class="p-3 d-flex align-items-center gap-3">
      <span class="kpi-icon ic-indigo"><i class="fa-solid fa-chart-line"></i></span>
      <div class="min-w-0"><div class="kpi-label">এই মাসের রাজস্ব</div><div class="kpi-value" id="dMonthRevenue"><?php echo money_bd($mSum['revenue']); ?></div>
        <div class="kpi-sub"><span class="delta <?php echo $mRevDir; ?>"><?php echo $arrow[$mRevDir] . ' ' . h($mRevTxt); ?></span> গত মাসের তুলনায়</div></div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="dcard kpi-card"><div class="p-3 d-flex align-items-center gap-3">
      <span class="kpi-icon ic-emerald"><i class="fa-solid fa-coins"></i></span>
      <div class="min-w-0"><div class="kpi-label">এই মাসের নিট লাভ<?php echo $unk > 0 ? ' *' : ''; ?></div>
        <div class="kpi-value <?php echo $mPnl['net'] < 0 ? 'text-danger' : ''; ?>" id="dMonthNet"><?php echo ($mPnl['net'] < 0 ? '−' : '') . money_bd(abs($mPnl['net'])); ?></div>
        <div class="kpi-sub"><a href="report_profit.php"><?php echo $unk > 0 ? '* ক্রয়মূল্য অজানা আইটেম আছে — ' : ''; ?>লাভ-ক্ষতি দেখুন</a></div></div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="dcard kpi-card"><div class="p-3 d-flex align-items-center gap-3">
      <span class="kpi-icon ic-violet"><i class="fa-solid fa-cart-shopping"></i></span>
      <div class="min-w-0"><div class="kpi-label">এই মাসের অর্ডার</div><div class="kpi-value" id="dMonthOrders"><?php echo (int)$mSum['valid']; ?></div>
        <div class="kpi-sub"><span class="delta <?php echo $mOrdDir; ?>"><?php echo $arrow[$mOrdDir] . ' ' . h($mOrdTxt); ?></span> বাতিল <?php echo (int)$mSum['cancelled']; ?>টি</div></div>
    </div></div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="dcard kpi-card"><div class="p-3 d-flex align-items-center gap-3">
      <span class="kpi-icon ic-rose"><i class="fa-solid fa-scale-balanced"></i></span>
      <div class="min-w-0"><div class="kpi-label">এই মাসের গড় অর্ডার</div><div class="kpi-value" id="dMonthAvg"><?php echo money_bd($mSum['avg']); ?></div>
        <div class="kpi-sub"><a href="report_orders.php">পূর্ণ রিপোর্ট</a></div></div>
    </div></div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="dcard h-100"><div class="p-3">
      <div class="d-flex justify-content-between align-items-center mb-2"><div><h6 class="sec-title">মাসিক বিক্রি</h6><div class="sec-sub">শেষ ৬ মাসের রাজস্ব (বাতিল বাদে)</div></div></div>
      <?php $maxRev = 0; foreach ($series as $sp) $maxRev = max($maxRev, $sp['revenue']); $lastIdx = count($series) - 1; ?>
      <div class="d-flex align-items-end gap-2" id="dashChart">
        <?php foreach ($series as $i => $sp): $hp = $maxRev > 0 ? max(3, round($sp['revenue'] / $maxRev * 78)) : 3; ?>
          <div class="bar-col flex-fill d-flex flex-column justify-content-end align-items-center">
            <div class="small text-muted" style="font-size:11px;min-height:16px"><?php echo $sp['revenue'] > 0 ? money_bd($sp['revenue']) : ''; ?></div>
            <div class="bar<?php echo $i === $lastIdx ? ' now' : ''; ?><?php echo $sp['revenue'] > 0 ? '' : ' zero'; ?>" style="height:<?php echo $hp; ?>%" title="<?php echo h($sp['label'] . ': ' . money_bd($sp['revenue']) . ' (' . $sp['orders'] . 'টি)'); ?>"></div>
            <div class="small mt-1" style="font-size:11px;<?php echo $i === $lastIdx ? 'font-weight:700;color:#059669' : ''; ?>"><?php echo h($sp['label']); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div></div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="dcard h-100"><div class="p-3">
      <h6 class="sec-title">ক্যাটেগরি অনুযায়ী বিক্রি</h6><div class="sec-sub mb-3">এই মাস</div>
      <?php if (!$mCats): ?><div class="text-muted small" id="dashNoCats">এই মাসে এখনো কোনো বিক্রি নেই</div>
      <?php else:
        $palette = ['#4f46e5', '#0891b2', '#16a34a', '#d97706', '#e11d48'];
        $sumC = array_sum(array_column($mCats, 'amount')); $acc = 0; $stops = [];
        foreach ($mCats as $k => $c) { $from = $acc; $acc += $sumC > 0 ? $c['amount'] / $sumC * 100 : 0; $stops[] = $palette[$k % 5] . ' ' . round($from, 2) . '% ' . round($acc, 2) . '%'; }
      ?>
        <div class="d-flex align-items-center gap-3 flex-wrap" id="dashCats">
          <div class="donut" style="background:conic-gradient(<?php echo implode(',', $stops); ?>)"></div>
          <div class="flex-fill" style="min-width:150px">
            <?php foreach ($mCats as $k => $c): $pc = $sumC > 0 ? $c['amount'] / $sumC * 100 : 0; ?>
              <div class="d-flex align-items-center gap-2 small mb-2"><span class="legend-dot" style="background:<?php echo $palette[$k % 5]; ?>"></span><span class="flex-fill text-truncate"><?php echo h($c['name']); ?></span><strong><?php echo money_bd($c['amount']); ?></strong><span class="text-muted" style="width:38px;text-align:right"><?php echo number_format($pc, 0); ?>%</span></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-12">
    <div class="dcard"><div class="p-3">
      <div class="d-flex justify-content-between align-items-center mb-2"><div><h6 class="sec-title">অর্ডারের অবস্থা</h6><div class="sec-sub">এই মাসে সব অর্ডার (<?php echo (int)$mSum['orders']; ?>টি)</div></div><a class="small" href="report_orders.php">বিস্তারিত</a></div>
      <?php $stColors = ['pending' => '#f59e0b', 'processing' => '#22d3ee', 'shipped' => '#3b82f6', 'delivered' => '#16a34a', 'cancelled' => '#94a3b8']; $stTotal = max(1, (int)$mSum['orders']); ?>
      <div class="stbar mb-2" id="dashStatus"><?php foreach ($mStatus as $sk => $sv): if ($sv['count'] > 0): ?><span style="width:<?php echo round($sv['count'] / $stTotal * 100, 2); ?>%;background:<?php echo $stColors[$sk]; ?>" title="<?php echo h(rp_status_label($sk) . ': ' . $sv['count']); ?>"></span><?php endif; endforeach; ?></div>
      <div class="d-flex flex-wrap gap-3 small">
        <?php foreach ($mStatus as $sk => $sv): ?><span><span class="legend-dot" style="background:<?php echo $stColors[$sk]; ?>"></span> <?php echo h(rp_status_label($sk)); ?> <strong><?php echo $sv['count']; ?></strong></span><?php endforeach; ?>
      </div>
    </div></div>
  </div>
</div>
<?php endif; ?>

<?php
  $alerts = [];
  if ($canOrders) $alerts[] = ['id' => 'dOldPending', 'cls' => $oldPending > 0 ? 'ai-red' : 'ai-slate', 'icon' => 'fa-hourglass-half', 'text' => '৩ দিনের বেশি পুরোনো Pending অর্ডার', 'n' => $oldPending, 'href' => 'orders.php?status=pending'];
  if (can('view_stock_report') || can('manage_products')) {
    $alerts[] = ['id' => 'aOutStock', 'cls' => $out_of_stock > 0 ? 'ai-red' : 'ai-slate', 'icon' => 'fa-box-open', 'text' => 'স্টক-আউট পণ্য (Active)', 'n' => $out_of_stock, 'href' => can('view_stock_report') ? 'stock_report.php?low=0&low_only=1' : 'products.php'];
    $alerts[] = ['id' => 'aLowStock', 'cls' => $low_stock_count > 0 ? 'ai-amber' : 'ai-slate', 'icon' => 'fa-triangle-exclamation', 'text' => 'কম স্টক (≤ ' . $LOW_STOCK . ') পণ্য', 'n' => $low_stock_count, 'href' => can('view_stock_report') ? 'stock_report.php' : 'products.php'];
  }
  if ($canDue) $alerts[] = ['id' => 'aSupplierDue', 'cls' => $supplierDue > 0 ? 'ai-blue' : 'ai-slate', 'icon' => 'fa-truck-ramp-box', 'text' => 'সরবরাহকারীদের কাছে মোট বাকি', 'n' => money_bd($supplierDue), 'href' => 'suppliers.php'];
  $allClear = $alerts && !array_filter($alerts, fn($a) => $a['cls'] !== 'ai-slate');
?>
<div class="row g-3 mb-3">
  <?php if ($alerts): ?>
  <div class="col-12 col-lg-5">
    <div class="dcard h-100"><div class="p-3">
      <h6 class="sec-title">মনোযোগ দরকার</h6><div class="sec-sub mb-3"><?php echo $allClear ? 'এই মুহূর্তে সব ঠিক আছে' : 'যেসব বিষয় দেখা দরকার'; ?></div>
      <div id="dashAlerts">
      <?php foreach ($alerts as $a): ?>
        <a class="alert-row" href="<?php echo h($a['href']); ?>"><span class="ai <?php echo $a['cls']; ?>"><i class="fa-solid <?php echo $a['icon']; ?>"></i></span><span><?php echo h($a['text']); ?></span><span class="cnt" id="<?php echo $a['id']; ?>"><?php echo h((string)$a['n']); ?></span></a>
      <?php endforeach; ?>
      </div>
    </div></div>
  </div>
  <?php endif; ?>
  <div class="col-12 <?php echo $alerts ? 'col-lg-7' : ''; ?>">
    <div class="dcard h-100"><div class="p-3">
      <div class="d-flex justify-content-between align-items-center mb-3"><h6 class="sec-title">দ্রুত অ্যাকশন</h6></div>
      <div class="row g-2" id="dashQuick">
        <?php foreach ($qa as $x): ?>
          <div class="col-4 col-md-3 col-xl-<?php echo $alerts ? '4' : '2'; ?>"><a class="qa-tile <?php echo $x[4]; ?>" href="<?php echo h($x[1]); ?>"><span class="qi"><i class="fa-solid <?php echo $x[2]; ?>"></i></span><span><?php echo h($x[3]); ?></span></a></div>
        <?php endforeach; ?>
        <?php if (!$qa): ?><div class="col-12 text-muted small">আপনার জন্য কোনো দ্রুত অ্যাকশন নেই</div><?php endif; ?>
      </div>
    </div></div>
  </div>
</div>

<!-- সাম্প্রতিক অর্ডার -->
<div class="dcard mb-3">
  <div class="d-flex justify-content-between align-items-center p-3 pb-2">
    <div><h6 class="sec-title">সাম্প্রতিক অর্ডার</h6><div class="sec-sub">সর্বশেষ ৮টি</div></div>
    <a class="small" href="orders.php?from=<?php echo h($td); ?>&to=<?php echo h($td); ?>&status=all">আজকের সব অর্ডার <i class="fa-solid fa-arrow-up-right-from-square ms-1"></i></a>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0 ord-table" id="dashOrders">
      <thead>
        <tr>
          <th style="min-width:90px">অর্ডার</th>
          <th>গ্রাহক</th>
          <th>মোবাইল</th>
          <th class="text-center">আইটেম</th>
          <th class="text-end">মোট</th>
          <th style="min-width:130px">স্ট্যাটাস</th>
          <th>সময়</th>
          <th class="text-end">অ্যাকশন</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($recent as $o): ?>
        <tr>
          <td data-label="অর্ডার">#<?php echo h($o['order_code']); ?></td>
          <td data-label="গ্রাহক" class="fw-semibold"><?php echo h($o['customer_name']); ?></td>
          <td data-label="মোবাইল"><?php echo h($o['mobile']); ?></td>
          <td data-label="আইটেম" class="text-center"><?php echo h((int)($o['item_count'] ?? 0)); ?></td>
          <td data-label="মোট" class="text-end fw-semibold"><?php echo money_bd($o['total']); ?></td>
          <td data-label="স্ট্যাটাস">
            <span class="badge badge-status <?php echo status_badge_class($o['status']); ?>"><?php echo ucfirst(h($o['status'])); ?></span>
          </td>
          <td data-label="সময়"><?php echo h(substr($o['created_at'], 0, 10) === $td ? 'আজ ' . date('g:i A', strtotime($o['created_at'])) : date('d M, g:i A', strtotime($o['created_at']))); ?></td>
          <td data-label="অ্যাকশন" class="text-end td-actions">
            <a class="btn btn-sm btn-outline-secondary" href="order_print.php?id=<?php echo (int)$o['id']; ?>" target="_blank" title="Print"><i class="fa-solid fa-print"></i></a>
            <a class="btn btn-sm btn-outline-primary" href="order_view.php?id=<?php echo (int)$o['id']; ?>" title="View"><i class="fa-solid fa-eye"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$recent): ?>
        <tr><td colspan="8" class="text-center text-muted py-4"><i class="fa-regular fa-circle-check me-1"></i> এখনো কোনো অর্ডার নেই</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__.'/footer.php'; ?>
