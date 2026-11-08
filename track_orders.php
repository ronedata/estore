<?php
// track_orders.php
require_once __DIR__.'/includes/config.php';
$pdo = get_pdo();

/* ---------- Helpers (fallbacks) ---------- */
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money_bd')) { function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); } }
if (!function_exists('bn_digits')) { function bn_digits($s){ return strtr((string)$s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']); } }
if (!function_exists('dateBD')) {
    function dateBD($ts){
        return bn_digits(date('d M, Y g:i A', is_numeric($ts)? (int)$ts : strtotime($ts)));
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

/* ---------- ইনপুট ---------- */
$mobileParam = trim($_GET['m'] ?? '');
$orders = [];

if ($mobileParam !== '') {
  // 11-ডিজিটে নরমালাইজ (BD): 8801xxxx → 01xxxx
  $digits = preg_replace('/\D+/', '', $mobileParam);
  if (strpos($digits,'8801')===0 && strlen($digits)>=13) $digits = '0'.substr($digits,3);
  if (strpos($digits,'881')===0  && strlen($digits)>=12) $digits = '0'.substr($digits,2);
  if (strlen($digits)===10 && $digits[0]!=='0') $digits = '0'.$digits;

  // সাধারণভাবে DB-তে যেভাবে থাকে তার কয়েকটি ক্যান্ডিডেট ফর্ম
  $cands = [$digits, '+88'.$digits, '88'.$digits];
  // ফাঁকা বা খুব ছোট হলে না চালানোই ভালো
  $cands = array_values(array_filter($cands, fn($v)=>strlen($v)>=6));

  if ($cands){
    $in = implode(',', array_fill(0, count($cands), '?'));
    $sql = "SELECT * FROM orders WHERE mobile IN ($in) ORDER BY created_at DESC";
    $stm = $pdo->prepare($sql);
    $stm->execute($cands);
    $orders = $stm->fetchAll();
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
    display:grid; grid-template-columns:1fr auto; gap:10px; align-items:center;
  }
  #trk .search input{
    height:46px; border:1px solid var(--border); border-radius:12px; padding:10px 12px; font-size:16px; outline:none;
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
  #trk .table { margin-bottom: 0; }
  #trk .table th, #trk .table td { vertical-align: middle; }
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
        <input
          type="tel"
          name="m"
          value="<?php echo h($mobileParam); ?>"
          placeholder="মোবাইল নম্বর দিন (01XXXXXXXXX)"
          pattern="^(\+?88)?0?1[0-9]{9}$"
          required
        >
      </div>
      <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> খুঁজুন</button>
    </form>
    <p class="small" style="color:#64748b;margin:8px 2px 0">
      মোবাইল নম্বর দিয়ে আপনার সব অর্ডার দেখুন (সর্বশেষটি আগে)।
    </p>
  </div>

  <?php if ($mobileParam !== ''): ?>
    <?php if (empty($orders)): ?>
      <div class="card no-results" style="margin-top:14px;">
        <p class="m-0">
            <i class="fa-solid fa-circle-exclamation"></i> 
            এই নম্বরে কোনো অর্ডার পাওয়া যায়নি। নম্বরটি ঠিক আছে কি না যাচাই করুন।
        </p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>অর্ডার আইডি</th>
              <th>তারিখ</th>
              <th>নাম</th>
              <th>স্ট্যাটাস</th>
              <th class="right">মোট</th>
              <th class="right">অ্যাকশন</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($orders as $r): 
            $total = $r['total'] ?? 0;
            [$stText, $stClass] = statusBadgeInfo($r['status'] ?? '');
            ?>
            <tr>
              <td data-label="অর্ডার আইডি"><a class="link" href="single_order_details.php?id=<?php echo (int)$r['id']; ?>">#<?php echo h($r['order_code'] ?? $r['id']); ?></a></td>
              <td data-label="তারিখ"><?php echo dateBD($r['created_at'] ?? ''); ?></td>
              <td data-label="নাম"><?php echo h($r['customer_name'] ?? ''); ?></td>
              <td data-label="স্ট্যাটাস"><span class="<?php echo h($stClass); ?>"><?php echo h($stText); ?></span></td>
              <td data-label="মোট" class="right"><?php echo money_bd($total); ?></td>
              <td data-label="অ্যাকশন" class="right td-actions"><a class="btn btn-sm" style="background:#eef2ff; color:#4338ca;" href="single_order_details.php?id=<?php echo (int)$r['id']; ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
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
</script>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
