<?php
// admin/products.php — name no-link + safe toggle + AJAX delete + toast
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_products'); // <-- পারমিশন চেক

$pdo = get_pdo();

/* CSRF (delete API-এর জন্য) */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

/* --------- Filters --------- */
$q      = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$cat    = is_scalar($_GET['cat'] ?? null) ? (int)$_GET['cat'] : 0;
$status = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['active', 'inactive'], true) ? $_GET['status'] : 'all';
$page   = is_scalar($_GET['page'] ?? null) ? max(1, (int)$_GET['page']) : 1;
$PER_OPTIONS = [10, 20, 50, 100];
$per_page = is_scalar($_GET['per'] ?? null) && in_array((int)$_GET['per'], $PER_OPTIONS, true) ? (int)$_GET['per'] : 20;

$current_url_params = $_GET; // পেজিনেশন লিঙ্কের জন্য

/* --------- Handle status toggle BEFORE any output --------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'CSRF token mismatch'];
    header('Location: products.php'); exit;
  }
  $id = (int)$_POST['toggle_id'];
  if ($id > 0) {
    $pdo->prepare("UPDATE products SET active = 1 - active WHERE id=?")->execute([$id]);
    $_SESSION['flash'] = ['ok'=>true, 'msg'=>'স্ট্যাটাস আপডেট হয়েছে'];
  }
  // আগের ফিল্টার রেখে রিডাইরেক্ট (toggle বাদ)
  $qs = $_POST['return'] ?? '';
  header("Location: products.php".($qs ? "?$qs" : ""));
  exit;
}

/* --------- Load categories --------- */
$cats = $pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* --------- Query --------- */
$where  = "1=1";
$params = [];
if ($q !== '') { $where .= " AND p.name LIKE :q"; $params[':q'] = "%$q%"; }
if ($cat > 0)  { $where .= " AND p.category_id=:cat"; $params[':cat'] = $cat; }
if ($status === 'active')   $where .= " AND p.active=1";
elseif ($status === 'inactive') $where .= " AND p.active=0";

/* --------- Count total rows for pagination --------- */
$count_sql = "SELECT COUNT(p.id) FROM products p WHERE $where";
$count_stm = $pdo->prepare($count_sql);
$count_stm->execute($params);
$total_rows = (int)$count_stm->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

/* --------- CSV এক্সপোর্ট (বর্তমান ফিল্টার অনুযায়ী, সব পৃষ্ঠার পণ্য) --------- */
if (($_GET['export'] ?? '') === 'csv') {
  $ex = $pdo->prepare("SELECT p.id, p.name, c.name AS cat_name, p.sku, p.price, p.compare_at_price, p.stock, p.active, p.sold_count, p.created_at
        FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE $where ORDER BY p.id DESC");
  $ex->execute($params);
  while (ob_get_level() > 0) @ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="products_' . date('Ymd_His') . '.csv"');
  header('Cache-Control: no-store');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['ID', 'নাম', 'ক্যাটেগরি', 'SKU', 'দাম', 'আগের দাম', 'স্টক', 'স্ট্যাটাস', 'বিক্রি', 'তৈরির তারিখ']);
  $safe = function ($v) { $v = (string)$v; return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; };
  while ($r = $ex->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [$r['id'], $safe($r['name']), $safe($r['cat_name']), $safe($r['sku']), $r['price'], $r['compare_at_price'], $r['stock'],
                   $r['active'] ? 'Active' : 'Inactive', $r['sold_count'], $r['created_at']]);
  }
  fclose($out);
  exit;
}

/* --------- Fetch paginated rows --------- */
$sql = "SELECT p.id, p.name, p.price, p.stock, p.active, c.name AS cat_name,
        (SELECT image FROM product_images pi WHERE pi.product_id=p.id ORDER BY sort_order, id LIMIT 1) AS image
        FROM products p
        LEFT JOIN categories c ON c.id=p.category_id
        WHERE $where
        ORDER BY p.id DESC
        LIMIT :limit OFFSET :offset";
$stm = $pdo->prepare($sql);
foreach ($params as $key => $val) $stm->bindValue($key, $val);
$stm->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stm->bindValue(':offset', $offset, PDO::PARAM_INT);
$stm->execute(); $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

/* কোন পণ্য কোন হোম সেকশনে আছে */
$secTags = [];
$secColors = [['#dbeafe','#1e40af'],['#dcfce7','#166534'],['#fef3c7','#92400e'],['#fce7f3','#9d174d'],['#ede9fe','#5b21b6'],['#ffedd5','#9a3412'],['#cffafe','#155e75'],['#fee2e2','#991b1b']];
$secColorOf = [];
try {
  foreach ($pdo->query("SELECT id FROM home_sections ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN) as $i => $sid) {
    $secColorOf[(int)$sid] = $secColors[$i % count($secColors)];
  }
} catch (Throwable $e) {}
if ($rows) {
  try {
    $ids = array_map('intval', array_column($rows, 'id'));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ts = $pdo->prepare("SELECT hsp.product_id, hs.title, hs.id FROM home_section_products hsp JOIN home_sections hs ON hs.id=hsp.section_id WHERE hsp.product_id IN ($ph) ORDER BY hs.sort_order, hs.id");
    $ts->execute($ids);
    foreach ($ts->fetchAll(PDO::FETCH_NUM) as [$pid, $ttl, $sid]) $secTags[(int)$pid][] = [$ttl, (int)$sid];
  } catch (Throwable $e) {}
}
$from_n = $total_rows ? $offset + 1 : 0;
$to_n   = min($offset + $per_page, $total_rows);

/* --------- Helpers --------- */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money_bd')) {
  function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); }
}

/* --------- Now load layout header (HTML starts) --------- */
require_once __DIR__.'/header.php';
?>
<style>
  .thumb{width:60px;height:60px;object-fit:cover;border:1px solid #e5e7eb;border-radius:8px;background:#fff}
  .btn-pill{border-radius:999px}
  .thumb-zoom{cursor:zoom-in;transition:transform .12s}
  .thumb-zoom:hover{transform:scale(1.05)}
  .sec-tag{font-weight:600;font-size:11px;border-radius:999px;padding:.3em .65em}

  /* ডেস্কটপ/ট্যাবলেট: কলামের ফিক্সড প্রস্থ, যাতে "নাম" পুরো টেবিল টেনে না ফেলে */
  @media (min-width: 577px){
    /* table থাকবে পুরো প্রস্থ জুড়ে (width:100%); শুধু "নাম" কলামই (width:auto)
       বাকি জায়গাটুকু নেবে, বাকি সব কলাম ফিক্সড — তাই stretching বাগও হয় না,
       আবার ডানদিকে ফাঁকা জায়গাও থাকে না */
    table.table{ table-layout: fixed; width: 100%; }
    table.table th:nth-child(1), table.table td:nth-child(1){ width:60px; }   /* ID */
    table.table th:nth-child(2), table.table td:nth-child(2){ width:84px; }   /* ছবি */
    table.table th:nth-child(3), table.table td:nth-child(3){ width:auto; }   /* নাম: বাকি জায়গা নেবে, র‍্যাপ হবে */
    table.table th:nth-child(4), table.table td:nth-child(4){ width:140px; } /* ক্যাটেগরি */
    table.table th:nth-child(5), table.table td:nth-child(5){ width:100px; } /* দাম */
    table.table th:nth-child(6), table.table td:nth-child(6){ width:80px; }  /* স্টক */
    table.table th:nth-child(7), table.table td:nth-child(7){ width:110px; } /* স্ট্যাটাস */
    table.table th:nth-child(8), table.table td:nth-child(8){ width:170px; } /* অ্যাকশন */

    .cell-name .name{
      display:block; white-space:normal; line-height:1.35; word-break:break-word;
    }
  }

  /* Mobile card table */
  @media (max-width: 576px){
    .table-responsive{border:0}
    table.table{display:block}
    table.table thead{display:none}
    table.table tbody{display:block}
    table.table tr{
      display:block; background:#fff; border:1px solid #e9ecef; border-radius:12px;
      padding:12px; margin-bottom:10px; box-shadow:0 6px 16px rgba(2,8,23,.04);
    }
    table.table td{
      display:flex; justify-content:space-between; gap:12px;
      padding:8px 0 !important; border:0 !important; vertical-align:middle !important;
    }
    table.table td::before{
      content:attr(data-label); font-weight:600; color:#64748b; min-width:110px; text-align:left;
    }
    .td-actions{ display:flex; flex-direction:column; gap:8px; }
    .td-actions .btn{ width:100%; }
    .cell-img{ display:grid; grid-template-columns:64px 1fr; gap:10px; align-items:center; }
    .cell-img::before{ content:''; display:none; }
    .cell-name .name{ font-weight:700; }
  }

  /* Toast area */
  #toastArea{ position:fixed; right:16px; bottom:16px; z-index:1080; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0">পণ্য ব্যবস্থাপনা</h5>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-pill" id="exportCsv" href="products.php?<?php echo h(http_build_query(array_merge(array_diff_key($_GET, ['page'=>1,'export'=>1]), ['export'=>'csv']))); ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
    <a class="btn btn-primary btn-pill" href="product_form.php">+ নতুন পণ্য</a>
  </div>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-12 col-md-3">
    <input class="form-control" name="q" placeholder="পণ্য খুঁজুন..." value="<?php echo h($q); ?>">
  </div>
  <div class="col-6 col-md-2">
    <select class="form-select" name="cat">
      <option value="0">সব ক্যাটেগরি</option>
      <?php foreach($cats as $c): ?>
        <option value="<?php echo (int)$c['id']; ?>" <?php echo $cat===(int)$c['id']?'selected':''; ?>>
          <?php echo h($c['name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-md-2">
    <select class="form-select" name="status">
      <option value="all" <?php echo $status==='all'?'selected':''; ?>>সব স্ট্যাটাস</option>
      <option value="active" <?php echo $status==='active'?'selected':''; ?>>Active</option>
      <option value="inactive" <?php echo $status==='inactive'?'selected':''; ?>>Inactive</option>
    </select>
  </div>
  <div class="col-6 col-md-2">
    <select class="form-select" name="per" id="perPage" title="প্রতি পৃষ্ঠায় কতটি" onchange="this.form.submit()">
      <?php foreach ($PER_OPTIONS as $o): ?><option value="<?php echo $o; ?>" <?php echo $per_page===$o?'selected':''; ?>>প্রতি পৃষ্ঠায় <?php echo $o; ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-md-2 d-grid">
    <button class="btn btn-outline-secondary btn-pill">ফিল্টার</button>
  </div>
</form>
<div class="small text-muted mb-2" id="pgInfo">মোট <?php echo $total_rows; ?>টি পণ্য<?php if ($total_rows): ?> — <?php echo $from_n; ?>–<?php echo $to_n; ?> দেখানো হচ্ছে (পৃষ্ঠা <?php echo $page; ?>/<?php echo $total_pages; ?>)<?php endif; ?></div>

<div class="card"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>ছবি</th>
          <th>নাম</th>
          <th>ক্যাটেগরি</th>
          <th class="text-end">দাম</th>
          <th class="text-end">স্টক</th>
          <th>স্ট্যাটাস</th>
          <th class="text-end">অ্যাকশন</th>
        </tr>
      </thead>
      <tbody id="rowsBody">
      <?php foreach($rows as $r):
        $img = img_url($r['image'] ?? '');
      ?>
        <tr id="row-<?php echo (int)$r['id']; ?>">
          <td data-label="ID">#<?php echo (int)$r['id']; ?></td>

          <td data-label="ছবি" class="cell-img">
            <img class="thumb thumb-zoom" src="<?php echo h($img); ?>" alt="" data-full="<?php echo h($img); ?>" data-title="<?php echo h($r['name']); ?>">
          </td>

          <!-- ✅ নাম: আর কোনো লিঙ্ক নেই -->
          <td data-label="নাম" class="cell-name">
            <span class="name"><?php echo h($r['name']); ?></span>
            <?php foreach (($secTags[(int)$r['id']] ?? []) as [$ttl, $sid]): $cl = $secColorOf[$sid] ?? ['#e5e7eb','#374151']; ?><span class="badge sec-tag" style="background:<?php echo $cl[0]; ?>;color:<?php echo $cl[1]; ?>"><?php echo h($ttl); ?></span> <?php endforeach; ?>
          </td>

          <td data-label="ক্যাটেগরি"><?php echo h($r['cat_name']); ?></td>

          <td data-label="দাম" class="text-end"><?php echo money_bd($r['price']); ?></td>

          <td data-label="স্টক" class="text-end"><?php echo (int)$r['stock']; ?></td>

          <td data-label="স্ট্যাটাস">
            <!-- টগল ব্যাজ-বাটন -->
            <form method="post" class="d-inline">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="toggle_id" value="<?php echo (int)$r['id']; ?>">
              <input type="hidden" name="return" value="<?php echo h(http_build_query($_GET)); ?>">
              <button type="submit" class="btn btn-sm btn-pill <?php echo $r['active']?'btn-success':'btn-outline-secondary'; ?>" title="স্ট্যাটাস টগল করুন">
                <?php echo $r['active'] ? 'Active' : 'Inactive'; ?>
              </button>
            </form>
          </td>

          <td data-label="অ্যাকশন" class="text-end td-actions">
            <div class="btn-group">
              <a class="btn btn-sm btn-outline-primary btn-pill" href="product_form.php?id=<?php echo (int)$r['id']; ?>">Edit</a>
              <a class="btn btn-sm btn-outline-secondary btn-pill btn-dup" title="নতুন ট্যাবে কপি-ফর্ম খুলবে (Save না করলে কিছুই তৈরি হবে না)" href="product_form.php?copy=<?php echo (int)$r['id']; ?>" target="_blank" rel="noopener">কপি</a>
              <button type="button" class="btn btn-sm btn-outline-danger btn-pill btn-del"
                      data-id="<?php echo (int)$r['id']; ?>"
                      data-name="<?php echo h($r['name']); ?>">
                Delete
              </button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">কোনো পণ্য পাওয়া যায়নি</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  
  <?php if ($total_pages > 1): ?>
    <div class="card-footer">
      <?php render_pagination($page, $total_pages, $current_url_params); ?>
    </div>
  <?php endif; ?>
</div></div>

<!-- Delete confirm modal -->
<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">পণ্য ডিলিট</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        আপনি কি নিশ্চিত? <br>
        <strong id="delName"></strong> ডিলিট হলে সংশ্লিষ্ট ইমেজগুলোও (default.jpg ছাড়া) ডিলিট হবে।
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-pill" data-bs-dismiss="modal">বাতিল</button>
        <button type="button" class="btn btn-danger btn-pill" id="confirmDel">ডিলিট</button>
      </div>
    </div>
  </div>
</div>

<!-- Image preview modal -->
<div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="imgModalTitle"></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center"><img id="imgModalImg" src="" alt="" style="max-width:100%;max-height:70vh"></div>
    </div>
  </div>
</div>

<!-- Toast area -->
<div id="toastArea"></div>

<script>
(function(){
  /* ------- Delete with AJAX ------- */
  const delModalEl = document.getElementById('delModal');
  const delNameEl  = document.getElementById('delName');
  const confirmBtn = document.getElementById('confirmDel');
  let delId = null;

  document.querySelectorAll('.btn-del').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      delId = btn.dataset.id;
      delNameEl.textContent = btn.dataset.name || ('#'+delId);
      bootstrap.Modal.getOrCreateInstance(delModalEl).show();
    });
  });

  document.querySelectorAll('.thumb-zoom').forEach(im=>{
    im.addEventListener('click', ()=>{
      document.getElementById('imgModalImg').src = im.dataset.full || im.src;
      document.getElementById('imgModalTitle').textContent = im.dataset.title || '';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('imgModal')).show();
    });
  });

  confirmBtn.addEventListener('click', async ()=>{
    if(!delId) return;
    confirmBtn.disabled = true; confirmBtn.textContent = 'মুছে ফেলা হচ্ছে...';
    try{
      const fd = new FormData();
      fd.set('id', delId);
      fd.set('csrf', '<?php echo h($_SESSION["csrf"]); ?>');

		const res = await fetch('product_delete.php', {
		  method: 'POST',
		  body: fd,
		  headers: {
			'X-Requested-With': 'XMLHttpRequest',   // ✅ Ajax ডিটেক্ট হবে
			'Accept': 'application/json'            // ✅ JSON চান
		  }
		});
      const data = await res.json().catch(()=>({ok:false}));
      if(!res.ok || !data.ok){ throw new Error(data.message||'Failed'); }

      // ✅ DOM থেকে রো রিমুভ → সাথে সাথে আর দেখাবে না
      document.getElementById('row-'+delId)?.remove();
      showToast('সফল', 'পণ্যটি ডিলিট হয়েছে।', 'success');
    }catch(e){
      showToast('ব্যর্থ', 'ডিলিট করা যায়নি। পরে চেষ্টা করুন।', 'danger');
    }finally{
      confirmBtn.disabled=false; confirmBtn.textContent='ডিলিট';
      bootstrap.Modal.getOrCreateInstance(delModalEl).hide();
      delId=null;
    }
  });

  /* ------- Bootstrap toast helper ------- */
  window.showToast = function(title, msg, variant){
    const id = 't'+Math.random().toString(36).slice(2);
    const html = `
      <div id="${id}" class="toast align-items-center text-bg-${variant||'secondary'} border-0 mb-2" role="status" aria-live="polite" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body"><strong>${title}:</strong> ${msg}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>`;
    const wrap = document.getElementById('toastArea');
    wrap.insertAdjacentHTML('beforeend', html);
    const el = document.getElementById(id);
    const t = new bootstrap.Toast(el, {delay: 2000});
    t.show();
    el.addEventListener('hidden.bs.toast', ()=> el.remove());
  };

  /* ------- Flash from PHP (delete via POST redirect, toggle etc.) ------- */
  <?php if (!empty($_SESSION['flash'])):
        $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    showToast('<?php echo $f['ok']?'সফল':'বার্তা'; ?>', '<?php echo h($f['msg']); ?>', '<?php echo $f['ok']?'success':'secondary'; ?>');
  <?php endif; ?>
})();
</script>

<?php require __DIR__.'/footer.php'; ?>
