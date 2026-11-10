<?php
// track_orders.php
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/order_access.php';
require_once __DIR__.'/includes/login_security.php';
require_once __DIR__.'/includes/customer_auth.php';
$customer = current_customer();
$pdo = get_pdo();

/* ---------- Helpers (fallbacks) ---------- */
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money_bd')) { function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); } }
if (!function_exists('bn_digits')) { function bn_digits($s){ return strtr((string)$s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']); } }
if (!function_exists('dateBD')) {
    function dateBD($ts){
        return date('d M Y, g:i A', is_numeric($ts)? (int)$ts : strtotime((string)$ts));
    }
}

function statusBadgeInfo($status){
  $s = strtolower(trim((string)$status));
  // আপনার সিস্টেমের status মান অনুযায়ী প্রয়োজন হলে ম্যাপিং বদলান
  $map = [
    'pending'    => ['পেন্ডিং','badge gray'],
    'processing' => ['প্রসেসিং','badge blue'],
    'packed'     => ['প্যাকড','badge blue'],
    'shipped'    => ['শিপড','badge indigo'],
    'delivered'  => ['ডেলিভারড','badge green'],
    'cancelled'  => ['ক্যানসেল্ড','badge red'],
    'canceled'   => ['ক্যানসেল্ড','badge red'],
    'returned'   => ['রিটার্নড','badge orange'],
  ];
  return $map[$s] ?? [$status ?: 'স্ট্যাটাস নেই', 'badge gray'];
}

/* ---------- ইনপুট (লগইন লাগে) ----------
   নিজের মোবাইল: গত ৭ দিনের অর্ডার, তারপর "আরও দেখুন" (১০টি করে)।
   অন্য মোবাইল: অর্ডার কোডও লাগে (গোপনীয়তা), একটি অর্ডারই দেখায়। */
const TRK_DAYS = 7;
const TRK_PAGE = 10;
$ownMobile   = $customer ? oa_norm_mobile($customer['mobile'] ?? '') : null;
$mobileParam = is_string($_GET['m'] ?? null) ? trim($_GET['m']) : '';
$codeParam   = is_string($_GET['c'] ?? null) ? trim($_GET['c']) : '';
if ($mobileParam === '' && $codeParam === '' && $ownMobile) { $mobileParam = $ownMobile; }
$rows = []; $hasMore = false; $trackMsg = ''; $trackKind = 'warn';

function trk_fetch(PDO $pdo, string $mobile, int $before, bool $older): array {
  $cut = date('Y-m-d H:i:s', time() - TRK_DAYS * 86400);
  $sql = "SELECT * FROM orders WHERE mobile IN (?,?,?)";
  $par = [$mobile, '+88'.$mobile, '88'.$mobile];
  if ($older) {
    $sql .= " AND created_at < ?"; $par[] = $cut;
    if ($before > 0) { $sql .= " AND id < ?"; $par[] = $before; }
    $lim = TRK_PAGE + 1;
  } else {
    $sql .= " AND created_at >= ?"; $par[] = $cut; $lim = 100;
  }
  $sql .= " ORDER BY id DESC LIMIT " . (int)$lim;
  try { $st = $pdo->prepare($sql); $st->execute($par); return $st->fetchAll(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return []; }
}
function trk_row_html(PDO $pdo, array $o): string {
  [$stText, $stClass] = statusBadgeInfo($o['status'] ?? '');
  $url = BASE_URL.'/single_order_details.php?id='.(int)$o['id'];
  $code = $url ? '<a class="link" href="'.h($url).'">'.h($o['order_code']).'</a>' : h($o['order_code']);
  $act  = $url ? '<a class="btn btn-sm" style="background:#eef2ff; color:#4338ca;" href="'.h($url).'">বিস্তারিত দেখুন</a>' : '';
  return '<tr data-id="'.(int)$o['id'].'">'
    .'<td data-label="অর্ডার কোড">'.$code.'</td>'
    .'<td data-label="তারিখ">'.dateBD($o['created_at'] ?? '').'</td>'
    .'<td data-label="স্ট্যাটাস"><span class="'.h($stClass).'">'.h($stText).'</span></td>'
    .'<td data-label="মোট" class="right">'.money_bd($o['total'] ?? 0).'</td>'
    .'<td data-label="অ্যাকশন" class="right td-actions">'.$act.'</td></tr>';
}

$nm = oa_norm_mobile($mobileParam);
if (isset($_GET['more'])) {
  header('Content-Type: application/json; charset=utf-8');
  if ($nm === null) { echo json_encode(['ok' => false]); exit; }
  $list = trk_fetch($pdo, $nm, (int)($_GET['before'] ?? 0), true);
  $more = count($list) > TRK_PAGE; $list = array_slice($list, 0, TRK_PAGE);
  $html = ''; foreach ($list as $o) $html .= trk_row_html($pdo, $o);
  echo json_encode(['ok' => true, 'html' => $html, 'more' => $more, 'last' => $list ? (int)end($list)['id'] : 0], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($mobileParam !== '') {
  if ($nm === null) {
    $trackMsg = 'মোবাইল নম্বরটি সঠিক নয়। 01XXXXXXXXX ফরম্যাটে দিন।';
  } elseif ($codeParam === '') {
    $rows = trk_fetch($pdo, $nm, 0, false);
    $hasMore = count(trk_fetch($pdo, $nm, 0, true)) > 0;
    if (!$rows && !$hasMore) { $trackMsg = 'এই নম্বরে কোনো অর্ডার নেই।'; $trackKind = 'info'; }
    elseif (!$rows) { $trackMsg = 'গত ' . TRK_DAYS . ' দিনে কোনো অর্ডার নেই। পুরোনো অর্ডার নিচে দেখুন।'; $trackKind = 'info'; }
  } elseif (oa_norm_code($codeParam) === null) {
    $trackMsg = 'অর্ডার কোডটি সঠিক নয়। কোডটি এরকম: ORD0000034';
  } else {
    $trkIp  = ls_client_ip(ls_load($pdo)['ip_source']);
    $trkLim = oa_limit_check($pdo, $trkIp);
    if ($trkLim['blocked']) {
      $trackMsg = 'অনেকবার ভুল চেষ্টা হয়েছে। ' . ls_wait_text(time() + $trkLim['wait']) . ' পরে আবার চেষ্টা করুন।';
    } else {
      $o = oa_find($pdo, $mobileParam, $codeParam);
      if (!$o) {
        oa_limit_fail($pdo, $trkIp);
        $trackMsg = 'এই মোবাইল নম্বর ও অর্ডার কোড মিলছে না। দুটোই ঠিক আছে কি না আবার দেখুন।';
      } else { $rows = [$o]; }
    }
  }
}

include __DIR__.'/includes/partials_header.php';
?>

<style>
  :root{
    --brand:#0ea5e9; --brand-600:#0284c7;
    --text:#0f172a; --muted:#64748b; --border:#e5e7eb; --bg:#f6f8fb; --white:#fff;
    --radius:14px; --shadow:0 10px 24px rgba(2,8,23,.08);
  }
  #trk{ max-width:1100px; margin:18px auto 28px; padding:0 14px; }
  #trk h2 { font-weight: 800; }
  /* Search Card */
  #trk .card{
    background:var(--white); border:1px solid var(--border); border-radius:var(--radius);
    box-shadow:0 2px 6px rgba(2,8,23,.02); padding:14px;
  }
  #trk .search{
    display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto; gap:10px; align-items:center;
  }
  #trk .search input{
    width:100%; min-width:0; box-sizing:border-box; height:46px; border:1px solid var(--border); border-radius:12px; padding:10px 12px; font-size:16px; outline:none;
    padding-left: 38px; /* Icon space */
  }
  #trk .search input:focus{ border-color:#cfe3ff; box-shadow:0 0 0 4px #e6f0ff; }
  #trk .search-wrap { position: relative; }
  #trk .search-wrap .icon {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: #9ca3af; pointer-events: none;
  }
  #trk .btn{
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    min-height:46px; padding:0 16px; border:none; border-radius:12px; background:var(--brand); color:#fff; font-weight:800; cursor:pointer;
  }
  #trk .btn:hover{ background:var(--brand-600); }

  #trk .no-results { background: #fff7ed; border-color: #fed7aa; color: #9a3412; margin-top: 14px; }
  #trk .no-results.info { background:#eff6ff; border-color:#dbeafe; color:#1e40af; }
  /* Badges */
  .badge{ display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border:1px solid var(--border); border-radius:999px; font-weight:700; background:#fff; }
  .badge.gray{ color:#334155; }
  .badge.blue{ color:#1e40af; background:#eff6ff; border-color:#dbeafe; }
  .badge.indigo{ color:#3730a3; background:#eef2ff; border-color:#e0e7ff; }
  .badge.green{ color:#065f46; background:#ecfdf5; border-color:#d1fae5; }
  .badge.red{ color:#b91c1c; background:#fee2e2; border-color:#fecaca; }
  .badge.orange{ color:#9a3412; background:#fff7ed; border-color:#fed7aa; }

  /* Result: desktop table */
  #trk .table-responsive { border-radius: 12px; overflow: hidden; border: 1px solid var(--border); margin-top: 14px; }
  #trk .table { width:100%; margin-bottom: 0; border-collapse: collapse; }
  #trk .table th { background:#f8fafc; color:var(--muted); font-weight:700; font-size:13px; text-align:left; white-space:nowrap; }
  #trk .table th, #trk .table td { padding:12px 14px; vertical-align: middle; border-bottom:1px solid var(--border); }
  #trk .table tbody tr:last-child td { border-bottom:0; }
  #trk .table th.right { text-align:right; }
  #trk .right{text-align:right;}
  #trk .link{ color:#0f172a; font-weight:800; text-decoration:none; }
  #trk .link:hover{ text-decoration:underline; }

  /* Mobile: card list */
  @media (max-width: 768px){
    #trk .table-responsive { border: 0; }
    #trk .table { display: block; }
    #trk .table thead { display: none; }
    #trk .table tbody { display: block; }
    #trk .table tr {
      display: block; background: var(--white); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 12px; margin-bottom: 12px;
      box-shadow: 0 4px 12px rgba(2,8,23,.05);
    }
    #trk .table td {
      display: flex; justify-content: space-between; gap: 12px;
      padding: 8px 0 !important; border: 0 !important;
    }
    #trk .table td::before {
      content: attr(data-label); font-weight: 600; color: var(--muted);
      min-width: 100px; text-align: left;
    }
    #trk .td-actions { display: flex; flex-direction: column; gap: 8px; }
    #trk .td-actions::before { display: none; }
    #trk .search{ grid-template-columns:minmax(0,1fr); }
    #trk .search .btn{ width:100%; }
    #trk .td-actions .btn { width: 100%; }
  }
</style>

<div id="trk">
  <h2>অর্ডার ট্র্যাক করুন</h2>

  <!-- Search -->
  <div class="card">
    <form class="search" method="get" action="track_orders.php" autocomplete="off" id="trackForm">
      <div class="search-wrap">
        <i class="fa-solid fa-mobile-screen-button icon"></i>
        <input type="tel" name="m" id="trkMobile" value="<?php echo h($mobileParam); ?>"
               placeholder="মোবাইল নম্বর (01XXXXXXXXX)" inputmode="tel" aria-label="মোবাইল নম্বর" required>
      </div>
      <div class="search-wrap">
        <i class="fa-solid fa-hashtag icon"></i>
        <input type="text" name="c" id="trkCode" value="<?php echo h($codeParam); ?>"
               placeholder="অর্ডার কোড (ঐচ্ছিক)" autocapitalize="characters" spellcheck="false" aria-label="অর্ডার কোড (ঐচ্ছিক)">
      </div>
      <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> খুঁজুন</button>
    </form>
    <p class="small" style="color:#64748b;margin:8px 2px 0" id="trkHint">মোবাইল নম্বর দিয়ে খুঁজুন — গত 7 দিনের অর্ডার প্রথমে দেখাবে, পুরোনোগুলো "আরও দেখুন" বাটনে (10টি করে)। অর্ডারের <strong>বিস্তারিত দেখতে লগইন</strong> করতে হবে।</p>
  </div>

  <?php if ($trackMsg !== ''): ?>
    <div class="card no-results <?php echo h($trackKind); ?>" style="margin-top:14px;" id="trkMsg">
      <p class="m-0"><i class="fa-solid fa-circle-<?php echo $trackKind === 'info' ? 'info' : 'exclamation'; ?>"></i> <?php echo h($trackMsg); ?></p>
    </div>
  <?php endif; ?>

  <?php if ($rows): ?>
    <div class="table-responsive" id="trkResult">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>অর্ডার কোড</th>
            <th>তারিখ</th>
                        <th>স্ট্যাটাস</th>
            <th class="right">মোট</th>
            <th class="right">অ্যাকশন</th>
          </tr>
        </thead>
        <tbody id="trkBody"><?php foreach ($rows as $o) echo trk_row_html($pdo, $o); ?></tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php if ($hasMore): ?>
    <div style="text-align:center;margin-top:14px">
      <button type="button" class="btn" id="trkMore" data-m="<?php echo h($nm); ?>" data-before="0"><i class="fa-solid fa-angles-down"></i> আরও দেখুন</button>
    </div>
  <?php endif; ?>
</div>

<script>
// লাস্ট সার্চ করা মোবাইল লোকালস্টোরে রেখে দেই (UX)
(function(){
  const f = document.getElementById('trackForm');
  const i = f?.querySelector('input[name="m"]');
  if(!i) return;
  try{
    if(!i.value && localStorage.getItem('lastMobile')) i.value = localStorage.getItem('lastMobile');
    f.addEventListener('submit', ()=> localStorage.setItem('lastMobile', i.value || ''));
  }catch(e){}
})();
(function(){
  const b = document.getElementById('trkMore'); if(!b) return;
  b.addEventListener('click', async ()=>{
    b.disabled = true;
    try{
      const r = await fetch('track_orders.php?more=1&m='+encodeURIComponent(b.dataset.m)+'&before='+encodeURIComponent(b.dataset.before), {headers:{'X-Requested-With':'XMLHttpRequest'}});
      const j = await r.json();
      if(j.ok){
        let body = document.getElementById('trkBody');
        if(!body){
          const w = document.createElement('div'); w.className='table-responsive'; w.id='trkResult';
          w.innerHTML = '<table class="table align-middle"><thead class="table-light"><tr><th>অর্ডার কোড</th><th>তারিখ</th><th>স্ট্যাটাস</th><th class="right">মোট</th><th class="right">অ্যাকশন</th></tr></thead><tbody id="trkBody"></tbody></table>';
          b.parentNode.parentNode.insertBefore(w, b.parentNode);
          body = document.getElementById('trkBody');
        }
        body.insertAdjacentHTML('beforeend', j.html);
        b.dataset.before = j.last;
        if(!j.more) b.parentNode.remove(); else b.disabled = false;
      } else b.disabled = false;
    }catch(e){ b.disabled = false; }
  });
})();
</script>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
