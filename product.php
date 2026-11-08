<?php
require_once __DIR__.'/includes/config.php';
$pdo = get_pdo();

/* ---------- Product ---------- */
$id = (int)($_GET['id'] ?? 0);
$stm = $pdo->prepare("
  SELECT p.*, c.name AS cat_name
  FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.id = ? AND p.active = 1
  LIMIT 1
");
$stm->execute([$id]);
$p = $stm->fetch();
if (!$p) { http_response_code(404); echo "Not Found"; exit; }
increment_page_view('product');
increment_page_view('product_'.$id);

/* ---------- Stock flags ---------- */
$stock     = max(0, (int)($p['stock'] ?? 0));
$isOut     = ($stock === 0);
$isLimited = (!$isOut && $stock < 10);

/* ---------- Specifications parse ---------- */
$specsRows = [];
$rawSpecs  = trim((string)($p['specifications'] ?? ''));
if ($rawSpecs !== '') {
  $json = json_decode($rawSpecs, true);
  if (is_array($json)) {
    if (array_keys($json) !== range(0, count($json)-1)) {
      foreach ($json as $k=>$v) $specsRows[]=[(string)$k, is_scalar($v)?(string)$v:json_encode($v, JSON_UNESCAPED_UNICODE)];
    } else {
      foreach ($json as $item) {
        if (is_array($item) && isset($item['key'],$item['value'])) $specsRows[]=[(string)$item['key'],(string)$item['value']];
        elseif (is_array($item) && count($item)===2){ $vals=array_values($item); $specsRows[]=[(string)$vals[0],(string)$vals[1]]; }
      }
    }
  }
  if (!$specsRows) {
    $tokens = preg_split('/\r\n|\r|\n|;|,/', $rawSpecs);
    foreach ($tokens as $ln) {
      $ln = trim($ln); if ($ln==='') continue;
      if (preg_match('/^\s*[-*•]?\s*([^:=|—–]+?)\s*[:=\-|—–]\s*(.+)\s*$/u', $ln, $m)) {
        $k=trim($m[1]); $v=trim($m[2]); if ($k!=='' && $v!=='') $specsRows[]=[$k,$v];
      }
    }
  }
}

/* ---------- Images ---------- */
$images = [];
$imgStm = $pdo->prepare("SELECT image FROM product_images WHERE product_id=? ORDER BY COALESCE(sort_order,999999), id");
$imgStm->execute([$id]);
$images = $imgStm->fetchAll(PDO::FETCH_COLUMN);
if (!$images && !empty($p['image'])) $images = [img_url($p['image'])];

/* ---------- Videos (images-এর পরে দেখাব) ---------- */
$videos = [];
try {
  $vstm = $pdo->prepare("SELECT type, url, COALESCE(sort_order,999999) AS s FROM product_videos WHERE product_id=? ORDER BY s, id");
  $vstm->execute([$id]);
  $videos = $vstm->fetchAll();
} catch (Throwable $e) {
}

/* ---------- Related (MAX 4) ---------- */
$related=[];
if (!empty($p['category_id'])) {
  $rel=$pdo->prepare("
    SELECT id,name,price,compare_at_price,image
    FROM products
    WHERE active=1 AND category_id=? AND id<>?
    ORDER BY created_at DESC
    LIMIT 4
  ");
  $rel->execute([(int)$p['category_id'], (int)$p['id']]);
  $related=$rel->fetchAll();
}

/* ---------- Tags ---------- */
$tags     = (string)($p['tags'] ?? '');
$hasFree  = (bool)preg_match('/\bfree(ship(ping)?)?\b/i',$tags);
$tagItems = [];
if ($tags !== '') {
  $parts = preg_split('/[\n\r;,|#]+/u', $tags);
  foreach ($parts as $t) { $t = ltrim(trim($t), '# '); if ($t!=='') $tagItems[]=$t; }
  $tagItems = array_values(array_unique($tagItems));
  if (count($tagItems) > 10) $tagItems = array_slice($tagItems, 0, 10);
}

include __DIR__.'/includes/partials_header.php';
?>

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
  :root{--brand:#0ea5e9;--brand-600:#0284c7;--text:#0f172a;--muted:#64748b;--border:#e5e7eb;--bg:#f6f8fb;--white:#fff;--radius:14px;--shadow:0 10px 24px rgba(2,8,23,.08);}
  #pdp{max-width:1100px;margin:18px auto 24px;padding:0 14px;}
  /* ===== Hero ===== */
  #pdp .hero{display:grid;gap:14px;grid-template-areas:"media" "info";}
  #pdp .hero .media{grid-area:media;} #pdp .hero .info{grid-area:info;}
  /* Gallery */
  #pdp .gallery{--gal-h:300px;position:relative;border:1px solid var(--border);border-radius:var(--radius);background:#f4f6f8;overflow:hidden;cursor:zoom-in;}
  #pdp .slides{display:flex;width:100%;height:var(--gal-h);transform:translateX(0%);transition:transform .25s ease;}
  #pdp .slide{min-width:100%;height:var(--gal-h);display:flex;align-items:center;justify-content:center;background:#fff;}
  #pdp .slide img{width:100%;height:100%;object-fit:contain;display:block;}
  #pdp .slide video{width:100%;height:100%;object-fit:contain;background:#000;}
  #pdp .slide iframe{width:100%;height:100%;border:0;background:#000;}
  #pdp .nav{position:absolute;top:50%;transform:translateY(-50%);width:38px;height:38px;border:none;border-radius:999px;background:rgba(255,255,255,.95);box-shadow:var(--shadow);cursor:pointer;display:grid;place-items:center}
  #pdp .nav.prev{left:8px;} #pdp .nav.next{right:8px;} #pdp .nav:disabled{opacity:.5;cursor:default}
  #pdp .thumbs{display:flex;gap:8px;margin-top:8px;overflow:auto;}
  #pdp .thumbs .th{position:relative;width:60px;height:60px;border:1px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;display:grid;place-items:center;overflow:hidden;}
  #pdp .thumbs img{width:100%;height:100%;object-fit:cover;opacity:.9;}
  #pdp .thumbs .play{position:absolute;inset:auto; color:#fff; background:rgba(0,0,0,.45); padding:4px 6px; border-radius:6px; font-size:12px;}
  #pdp .thumbs .active{outline:2px solid var(--brand);}
  /* Info */
  #pdp h1{margin:6px 0 8px;font-size:clamp(1.1rem,1.6vw + .8rem,1.7rem);line-height:1.25;}
  #pdp .cat{color:var(--muted);font-size:.92rem;margin-bottom:4px;display:block;}
  #pdp .chips{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 4px;}
  #pdp .chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;font-size:.84rem;border-radius:999px;border:1px solid var(--border);background:var(--white)}
  #pdp .chip.red{background:#fee2e2;color:#b91c1c;border-color:#fecaca;}
  #pdp .chip.orange{background:#fff7ed;color:#9a3412;border-color:#fed7aa;}
  #pdp .chip.green{background:#ecfdf5;color:#065f46;border-color:#d1fae5;}
  #pdp .chip.blue{background:#eff6ff;color:#1e40af;border-color:#dbeafe;}
  #pdp .price-row{margin:10px 0;display:flex;align-items:baseline;gap:10px;}
  #pdp .price{font-weight:800;color:var(--text);font-size:clamp(1.2rem,1.8vw + .9rem,1.8rem);}
  #pdp .old{color:#94a3b8;text-decoration:line-through;}
  #pdp .action-row{display:grid;grid-template-columns:1fr;gap:10px;margin:12px 0 6px;}
  #pdp .qty{display:grid;grid-template-columns:44px 1fr 44px;align-items:center;border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#fff;}
  #pdp .qty input{height:44px;text-align:center;border:none;border-inline:1px solid var(--border);font-size:16px;}
  #pdp .qty button{height:44px;border:none;background:#f8fafc;font-size:18px;cursor:pointer;}
  #pdp .qty button:hover{background:#f1f5f9;}
  .btn[disabled], .btn.disabled{opacity:.6; cursor:not-allowed; pointer-events:none;}
  #pdp .qty.disabled{opacity:.6;} #pdp .qty.disabled *{pointer-events:none;}
  #pdp .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 16px;border-radius:12px;font-weight:700;text-decoration:none;cursor:pointer;transition:background .2s ease,box-shadow .2s ease,transform .02s ease;}
  #pdp .btn i{font-size:1rem; width:1em; text-align:center; pointer-events:none;}
  #pdp .btn:active{transform:translateY(1px);}
  #pdp .btn.primary{background:var(--brand);color:#fff;border:none;}
  #pdp .btn.primary:hover{background:var(--brand-600);}
  #pdp .btn.ghost{background:#fff;border:1px solid var(--border);color:var(--text);}
  #pdp .btn.ghost:hover{box-shadow:var(--shadow);}
  #pdp .tabs{margin-top:18px;border-bottom:1px solid var(--border);display:flex;gap:8px;}
  #pdp .tab{background:#fff;border:1px solid var(--border);border-bottom:none;padding:10px 12px;border-top-left-radius:10px;border-top-right-radius:10px;cursor:pointer;font-weight:600;color:#334155;}
  #pdp .tab.active{background:#eff6ff;color:#1e40af;border-color:#cfe3ff;}
  #pdp .panel{display:none;background:#fff;border:1px solid var(--border);border-radius:0 10px 10px 10px;padding:12px;}
  #pdp .panel.active{display:block;}
  #pdp .specs{width:100%;border-collapse:collapse;font-size:.96rem;}
  #pdp .specs th,#pdp .specs td{padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:top;}
  #pdp .specs th{width:42%;color:#475569;text-align:left;background:#f8f8fb;}
  /* Toast */
  :root{
    --toast-success:#16a34a; --toast-error:#dc2626; --toast-info:#2563eb;
    --toast-bg:#111827; --toast-fg:#fff; --toast-shadow:0 12px 24px rgba(0,0,0,.18);
    --toast-radius:14px;
  }
  #toastStack{position:fixed;right:12px;bottom:12px;z-index:2147483647;display:flex;flex-direction:column;gap:10px;max-width:min(92vw,380px);}
  .toast{display:flex;gap:10px;align-items:flex-start;background:var(--toast-bg);color:var(--toast-fg);
    border-radius:var(--toast-radius);box-shadow:var(--toast-shadow);padding:12px 14px;border:1px solid rgba(255,255,255,.08);
    opacity:0;transform:translateY(10px) scale(.98);transition:transform .24s ease, opacity .24s ease;}
  .toast.show{opacity:1;transform:translateY(0) scale(1);}
  .toast .dot{width:10px;height:10px;border-radius:999px;margin-top:6px;flex:0 0 auto;box-shadow:0 0 0 3px rgba(255,255,255,.08) inset;}
  .toast.success .dot{background:var(--toast-success);} .toast.error .dot{background:var(--toast-error);} .toast.info .dot{background:var(--toast-info);}
  .toast .content{flex:1 1 auto;min-width:0;}
  .toast .title{font-weight:700;font-size:14px;line-height:1.2;margin:2px 0 4px;}
  .toast .msg{font-size:13px;line-height:1.35;opacity:.9;overflow-wrap:anywhere;}
  .toast .close{all:unset;cursor:pointer;line-height:0;border-radius:8px;padding:4px;margin-left:4px;color:#cbd5e1;}
  .spinner{width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;display:inline-block;animation:spin .8s linear infinite;vertical-align:-2px;margin-left:6px;}
  @keyframes spin{to{transform:rotate(360deg);}}
  
  /* Modal Styles */
  #modalGal{
      position:fixed;inset:0;background:rgba(0,0,0,.95);z-index:10000;
      padding:14px;backdrop-filter:blur(5px);
      display: none; /* Only display when .open is present */
      align-items: center; justify-content: center; /* Center the dialog */
  }
  #modalGal.open{display:flex;}
  
  /* Modal content wrapper - dictates max size of the visual card */
  #modalGal .content{
      width: 100%; height: 100%; 
      max-width: 600px; /* Constrain max width for dialog effect */
      max-height: 80vh; /* Constrain max height */
      margin: 0 auto;
      position:relative;
      display:flex;align-items:center;justify-content:center;
      flex-direction:column;gap:8px;
  }
  
  /* Slides container - THIS IS THE VISUAL WHITE CARD */
  #modalGal .modal-slides-wrap{
      width:100%;height:100%;
      border-radius:var(--radius);
      overflow:hidden;
      background:var(--white); /* White background like the image */
      border:1px solid var(--border); /* Optional: Border */
      box-shadow: 0 10px 30px rgba(0,0,0,.3); /* Shadow for dialog effect */
      position:relative;
  }

  /* Ensure media elements fit correctly */
  #modalGal .modal-slides{display:flex;width:100%;height:100%;transform:translateX(0%);transition:transform .25s ease;}
  #modalGal .modal-slide{min-width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#fff;position:relative;}
  #modalGal .modal-slide img{max-width:100%;max-height:100%;object-fit:contain;display:block;}
  #modalGal .modal-slide video, #modalGal .modal-slide iframe{width:100%;height:100%;object-fit:contain;display:block; background: #000;} /* Black background for video slides */

  /* Close button positioned top-right of the visual card */
  #modalGal .modal-close{
      position:absolute;
      top: 10px;
      right: 10px;
      width:38px;height:38px;border:none;border-radius:999px;
      background:rgba(255, 255, 255, 0.95); /* White background for the button */
      color:var(--text); /* Dark X icon */
      box-shadow:var(--shadow); /* Shadow */
      cursor:pointer;display:grid;place-items:center;font-size:1.2rem;
      z-index:10003;
  }
  #modalGal .modal-close:hover{background:rgba(255, 255, 255, 1);}

  /* Counter positioned top-left of the visual card */
  #modalGal .modal-counter{
      position:absolute;
      top: 10px;
      left: 10px;
      background:rgba(0,0,0,.5); /* Dark background for counter */
      color:#fff;padding:4px 10px;border-radius:6px;font-size:.9rem;
      z-index:10003;
  }
  
  /* Modal Navigation (Grey Color & High Z-Index - matching unnamed.png button) */
  #modalGal .modal-nav{
      position:absolute;top:50%;transform:translateY(-50%);
      width:48px;height:48px;border:none;border-radius:999px;
      background: #646464; /* Matching unnamed.png button color */
      color:#fff;cursor:pointer;display:grid;place-items:center;
      font-size:1.5rem;transition:background .2s;
      z-index: 10002; /* Ensures buttons are clickable over iframe */
  }
  #modalGal .modal-nav:hover{background:#7a7a7a;} 
  #modalGal .modal-nav.prev{left:14px;} #modalGal .modal-nav.next{right:14px;} 
  #modalGal .modal-nav:disabled{opacity:.5;cursor:default;background:rgba(255,255,255,.1);}
  
  /* Media Queries */
  @media (min-width:768px){ #pdp .hero{grid-template-columns:1.1fr 1fr;grid-template-areas:"media info";gap:24px;} #pdp .gallery{--gal-h:460px;} #pdp .action-row{grid-template-columns:220px auto auto; align-items:center;} }
</style>

<div id="pdp">
  <div class="hero">
    <div class="media">
      <div class="gallery" id="gal" data-index="0">
        <div class="slides" id="slides">
          <?php foreach ($images as $i => $img): ?>
            <div class="slide" data-index="<?php echo $i; ?>">
                <img src="<?php echo h($img); ?>" alt="<?php echo h($p['name']); ?>">
            </div>
          <?php endforeach; ?>

          <?php $base_index = count($images); foreach ($videos as $k => $v): ?>
            <?php
              $type = strtolower(trim($v['type'] ?? ''));
              $url  = (string)$v['url'];
            ?>
            <div class="slide" data-index="<?php echo $base_index + $k; ?>">
              <?php if ($type === 'file'): ?>
                <video controls playsinline preload="metadata" src="<?php echo h($url); ?>"></video>
              <?php else: // youtube ?>
                <?php
                  $yt = $url;
                  // যদি শুধু আইডি দেওয়া থাকে, এমবেড URL বানাই
                  if (!preg_match('~^https?://~i', $yt)) {
                    $yt = 'https://www.youtube.com/embed/'.rawurlencode($yt);
                  } else {
                    // watch?v= → embed/
                    if (preg_match('~youtube\.com/watch\?v=([^&]+)~i', $yt, $m)) {
                      $yt = 'https://www.youtube.com/embed/'.rawurlencode($m[1]);
                    }
                  }
                ?>
                <iframe allowfullscreen src="<?php echo h($yt); ?>"></iframe>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php $totalSlides = count($images) + count($videos); ?>
        <?php if ($totalSlides > 1): ?>
          <button class="nav prev" id="prev" aria-label="পূর্ববর্তী"><i class="fa-solid fa-chevron-left"></i></button>
          <button class="nav next" id="next" aria-label="পরবর্তী"><i class="fa-solid fa-chevron-right"></i></button>
        <?php endif; ?>
      </div>

      <?php if ($totalSlides > 1): ?>
        <div class="thumbs" id="thumbs">
          <?php foreach ($images as $i => $img): ?>
            <div class="th <?php echo $i===0?'active':''; ?>" data-idx="<?php echo $i; ?>">
              <img src="<?php echo h($img); ?>" alt="thumb <?php echo $i+1; ?>">
            </div>
          <?php endforeach; ?>
          <?php $base = count($images); foreach ($videos as $k => $v): ?>
            <div class="th" data-idx="<?php echo $base + $k; ?>">
              <div style="position:absolute;inset:0;background:#000;opacity:.15"></div>
              <i class="fa-solid fa-play play"></i>
              <?php if (strtolower($v['type']) === 'file'): ?>
                <img src="<?php echo h($images[0] ?? (BASE_URL.'/uploads/default.jpg')); ?>" alt="video thumb">
              <?php else: ?>
                <img src="https://img.youtube.com/vi/<?php
                  $url=$v['url'];
                  if (preg_match('~watch\?v=([^&]+)~', $url, $m))      echo h($m[1]);
                  elseif (preg_match('~youtu\.be/([^?&]+)~', $url,$m)) echo h($m[1]);
                  else echo 'dQw4w9WgXcQ';
                ?>/0.jpg" alt="youtube thumb">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="info">
      <h1><?php echo h($p['name']); ?></h1>
      <span class="cat">ক্যাটেগরি: <?php echo h($p['cat_name'] ?: ''); ?></span>

      <?php if (!empty($tagItems)): ?>
        <div class="chips" aria-label="ট্যাগ">
          <?php foreach ($tagItems as $tg): ?>
            <span class="chip"><i class="fa-solid fa-tag"></i> <?php echo h($tg); ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="chips">
        <?php if ($isOut): ?>
          <span class="chip red"><i class="fa-solid fa-circle-xmark"></i> স্টক নেই</span>
        <?php elseif ($isLimited): ?>
          <span class="chip orange"><i class="fa-solid fa-triangle-exclamation"></i> স্টক সীমিত</span>
        <?php else: ?>
          <span class="chip green"><i class="fa-solid fa-check"></i> স্টকে আছে</span>
        <?php endif; ?>
        <?php if ($hasFree): ?><span class="chip blue"><i class="fa-solid fa-truck"></i> Free Shipping</span><?php endif; ?>
      </div>

      <div class="price-row">
        <div class="price"><?php echo money_bd($p['price']); ?></div>
        <?php if(!empty($p['compare_at_price'])): ?><div class="old"><?php echo money_bd($p['compare_at_price']); ?></div><?php endif; ?>
      </div>

      <div class="action-row">
        <div class="qty <?php echo $isOut ? 'disabled' : ''; ?>">
          <button type="button" id="btnDec" aria-label="কমান" <?php echo $isOut ? 'disabled' : ''; ?>>−</button>
          <input id="qty" value="1" inputmode="numeric" aria-label="পরিমাণ" max="<?php echo (int)$stock; ?>" <?php echo $isOut ? 'disabled' : ''; ?>>
          <button type="button" id="btnInc" aria-label="বাড়ান" <?php echo $isOut ? 'disabled' : ''; ?>>+</button>
        </div>
      </div>

      <div class="action-row">
        <button class="btn primary" id="btnAdd"
                <?php echo $isOut ? 'disabled aria-disabled="true"' : ''; ?>
                <?php if(!$isOut): ?>onclick="addToCart(<?php echo (int)$p['id']; ?>, getQty())"<?php endif; ?>>
          <i class="fa-solid fa-cart-plus"></i> ADD TO CART
        </button>

        <?php if($isOut): ?>
          <a class="btn ghost disabled" id="btnBuy" aria-disabled="true" role="button" tabindex="-1">
            <i class="fa-solid fa-bolt"></i> BUY NOW
          </a>
        <?php else: ?>
          <a class="btn ghost" id="btnBuy" href="checkout.php?buy=<?php echo (int)$p['id']; ?>&qty=1">
            <i class="fa-solid fa-bolt"></i> BUY NOW
          </a>
        <?php endif; ?>
      </div>
	  
		<br/>
		
      <?php $phone = setting('store_phone'); ?>
      <?php if ($phone): ?>
        <div class="call">
          <i class="fa-solid fa-phone"></i>
          <strong>Call For Order:</strong>
			<a href="tel:<?php echo h($phone); ?>" style="text-decoration: none; color: blue;">
			  <?php echo h($phone); ?>
			</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="tabs" role="tablist">
    <button class="tab active" data-tab="desc" role="tab" aria-selected="true">Description</button>
    <button class="tab" data-tab="spec" role="tab" aria-selected="false">Specification</button>
  </div>
  <div class="panel active" id="tab-desc" role="tabpanel" aria-hidden="false">
    <?php echo $p['description'] ? nl2br(h($p['description'])) : 'পণ্যের বর্ণনা প্রদান করা হয়নি।'; ?>
  </div>
  <div class="panel" id="tab-spec" role="tabpanel" aria-hidden="true">
    <?php if ($specsRows): ?>
      <table class="specs"><tbody>
        <?php foreach($specsRows as $row): ?>
          <tr><th><?php echo h($row[0]); ?></th><td><?php echo nl2br(h($row[1])); ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    <?php elseif ($rawSpecs!==''): ?>
      <?php echo nl2br(h($rawSpecs)); ?>
    <?php else: ?>স্পেসিফিকেশন পাওয়া যায়নি।<?php endif; ?>
  </div>

  <?php if ($related): ?>
    <h3 style="margin:18px 0 10px;">Related products</h3>
    <div class="rel-wrap">
      <div class="rel-row" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;">
        <?php foreach($related as $r): ?>
          <a class="rel-card" href="product.php?id=<?php echo (int)$r['id']; ?>" style="text-decoration:none;color:inherit;border:1px solid var(--border);border-radius:12px;overflow:hidden;background:#fff;">
            <img class="thumb" loading="lazy" decoding="async" src="<?php echo h($r['image']); ?>" alt="<?php echo h($r['name']); ?>" style="width:100%;aspect-ratio:1/1;object-fit:cover;background:#f4f6f8;">
            <div class="body" style="padding:10px;">
              <div class="ttl" style="font-weight:600;margin:4px 0 6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo h($r['name']); ?></div>
              <div><span class="pr" style="font-weight:800;"><?php echo money_bd($r['price']); ?></span>
                <?php if(!empty($r['compare_at_price'])): ?><span class="old" style="color:#94a3b8;margin-left:6px;text-decoration:line-through;"><?php echo money_bd($r['compare_at_price']); ?></span><?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<div id="modalGal" role="dialog" aria-modal="true" aria-label="Product Media Gallery">
  <div class="content">
    <button class="modal-close" id="modalClose" aria-label="Close Gallery"><i class="fa-solid fa-xmark"></i></button>
    <div class="modal-counter" id="modalCounter"></div>
    <div class="modal-slides-wrap">
      <div class="modal-slides" id="modalSlides">
        <?php foreach ($images as $i => $img): ?>
          <div class="modal-slide">
              <img src="<?php echo h($img); ?>" alt="<?php echo h($p['name']); ?> - <?php echo $i+1; ?>">
          </div>
        <?php endforeach; ?>

        <?php $base_index = count($images); foreach ($videos as $k => $v): ?>
          <?php
            $type = strtolower(trim($v['type'] ?? ''));
            $url  = (string)$v['url'];
          ?>
          <div class="modal-slide" data-original-url="<?php echo h($url); ?>" data-type="<?php echo h($type); ?>">
            <?php if ($type === 'file'): ?>
              <video controls playsinline preload="metadata" src="<?php echo h($url); ?>"></video>
            <?php else: // youtube ?>
              <?php
                $yt = $url;
                if (!preg_match('~^https?://~i', $yt)) {
                  $yt = 'https://www.youtube.com/embed/'.rawurlencode($yt);
                } else {
                  if (preg_match('~youtube\.com/watch\?v=([^&]+)~i', $yt, $m)) {
                    $yt = 'https://www.youtube.com/embed/'.rawurlencode($m[1]);
                  }
                }
              ?>
              <iframe allowfullscreen src="<?php echo h($yt); ?>"></iframe>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($totalSlides > 1): ?>
        <button class="modal-nav prev" id="modalPrev" aria-label="পূর্ববর্তী"><i class="fa-solid fa-chevron-left"></i></button>
        <button class="modal-nav next" id="modalNext" aria-label="পরবর্তী"><i class="fa-solid fa-chevron-right"></i></button>
      <?php endif; ?>
    </div>
  </div>
</div>
<div id="toastStack" aria-live="polite" aria-atomic="false"></div>

<script>
/* ---------- Toast Helper (index.php-র মতো) --------- */
(function(){
  const stack = document.getElementById('toastStack');
  function icon(type){
    if(type==='success'){return '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9.55 18.05L3.5 12l1.4-1.4l4.65 4.6L19.1 5.65L20.5 7.05z"/></svg>';}
    if(type==='error'){return '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="m12 2 9 9-9 9-9-9zm-1 7h2v4h-2zm0 6h2v2h-2z"/></svg>';}
    return '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9a10 10 0 1 0 10 10A10.011 10.011 0 0 0 12 2"/></svg>';
  }
  window.showToast = function({title='নোটিফিকেশন', message='', type='info', duration=1500} = {}){
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.setAttribute('role','status');
    el.innerHTML = `
      <span class="dot" aria-hidden="true"></span>
      <div class="content">
        <div class="title">${title}</div>
        ${message ? `<div class="msg">${message}</div>` : ``}
      </div>
      <button class="close" aria-label="বন্ধ করুন" title="বন্ধ করুন">${icon(type)}</button>
    `;
    const close = ()=>{ el.style.opacity='0'; el.style.transform='translateY(10px) scale(.98)'; setTimeout(()=>el.remove(),220); };
    el.querySelector('.close').addEventListener('click', close);
    const t = setTimeout(close, duration);
    el.addEventListener('mouseenter', ()=>clearTimeout(t));
    el.addEventListener('focusin', ()=>clearTimeout(t));
    stack.appendChild(el);
    requestAnimationFrame(()=> el.classList.add('show'));
  };
})();
</script>

<script>
/* ---------- alert → toast (ডিফল্ট ডায়লগ থামাতে) ---------- */
(function(){
  const _nativeAlert = window.alert;
  window.alert = function(msg){
    try{
      if (typeof window.showToast === 'function') {
        window.showToast({ type:'info', title:'বার্তা', message:String(msg||''), duration:1500 });
      } else {
        _nativeAlert(String(msg||''));
      }
    }catch(e){ _nativeAlert(String(msg||'')); }
  };
})();
</script>

<script>
  // Qty + Buy Now link update
  function getQty(){
    const el = document.getElementById('qty');
    const max = Math.max(1, parseInt(el.getAttribute('max') || '1') || 1);
    const v = Math.min(max, Math.max(1, parseInt(el.value) || 1));
    el.value = v;
    return v;
  }
  function refreshBuyLink(){
    if (<?php echo $isOut ? 'true':'false'; ?>) return;
    var q=getQty();
    var buy=document.getElementById('btnBuy');
    if(buy){ buy.href='checkout.php?buy=<?php echo (int)$p['id']; ?>&qty='+q; }
  }
  document.getElementById('btnInc')?.addEventListener('click', ()=>{
    const i=document.getElementById('qty');
    const max = Math.max(1, parseInt(i.getAttribute('max') || '1') || 1);
    i.value=Math.min(max, (parseInt(i.value)||1)+1);
    refreshBuyLink();
  });
  document.getElementById('btnDec')?.addEventListener('click', ()=>{ const i=document.getElementById('qty'); i.value=Math.max(1,(parseInt(i.value)||1)-1); refreshBuyLink(); });
  document.getElementById('qty')?.addEventListener('input', refreshBuyLink);
  document.addEventListener('DOMContentLoaded', refreshBuyLink);

  // Slider + thumbs
  (function(){
    const slides=document.getElementById('slides'); if(!slides) return;
    const thumbs=document.getElementById('thumbs'); const prev=document.getElementById('prev'); const next=document.getElementById('next');
    const total=slides.children.length; 
    let idx=0; // The current index for both galleries
    
    // Modal elements
    const modal = document.getElementById('modalGal');
    const mSlides = document.getElementById('modalSlides');
    const mPrev = document.getElementById('modalPrev');
    const mNext = document.getElementById('modalNext');
    const mClose = document.getElementById('modalClose');
    const mCounter = document.getElementById('modalCounter');
    
    // Function to safely stop playback (especially for YouTube)
    function stopMediaPlayback(slideElement) {
        const media = slideElement.querySelector('video, iframe');
        if (!media) return;

        if (media.tagName === 'VIDEO') {
            media.pause();
            media.currentTime = 0; // Reset video
        } else if (media.tagName === 'IFRAME') {
            // Stop YouTube/iframe playback by removing autoplay from src
            let src = media.src.replace(/(&|\?)autoplay=1/, '').replace(/(\?|&)$/, '');
            // Only update src if it changed to prevent unnecessary reloads on pure image slides
            if (media.src !== src) {
                media.src = src; 
            }
        }
    }

    /**
     * Updates the main product gallery's position and navigation state.
     * It also syncs the index if the modal is open.
     */
    function go(i){
      idx=Math.max(0, Math.min(total-1, i));
      slides.style.transform='translateX(' + (-idx*100) + '%)';
      if(thumbs){ [...thumbs.querySelectorAll('.th')].forEach((t,ti)=>t.classList.toggle('active', ti===idx)); }
      if(prev) prev.disabled=(idx===0); if(next) next.disabled=(idx===total-1);
      
      // Sync modal position if open
      if(modal?.classList.contains('open')){ updateModal(idx); }
    }
    
    /**
     * Updates the modal gallery's position, counter, and handles media playback.
     */
    function updateModal(i){
      const newIdx = Math.max(0, Math.min(total-1, i));
      
      if(!mSlides) return;
      
      // Stop media on the old slide before moving
      if (idx !== newIdx) {
          stopMediaPlayback(mSlides.children[idx]);
      }
      
      idx = newIdx; // Update global index
      
      mSlides.style.transform='translateX(' + (-idx*100) + '%)';
      mCounter.textContent = `${idx + 1}/${total}`;
      if(mPrev) mPrev.disabled=(idx===0);
      if(mNext) mNext.disabled=(idx===total-1);
      
      // Start media playback on the current slide
      const currentSlide = mSlides.children[idx];
      const media = currentSlide.querySelector('video, iframe');

      if(media){
          if(media.tagName === 'VIDEO') {
              media.play().catch(e => console.log('Video autoplay blocked:', e));
          } else if(media.tagName === 'IFRAME') {
              let src = media.src;
              // Check if autoplay parameter is missing and add it
              if (src.indexOf('autoplay=1') === -1) {
                  src += (src.indexOf('?') > -1 ? '&' : '?') + 'autoplay=1';
                  media.src = src;
              }
          }
      }
      
      // Sync main gallery UI (thumbs)
      if(thumbs){ [...thumbs.querySelectorAll('.th')].forEach((t,ti)=>t.classList.toggle('active', ti===idx)); }
    }

    function openModal(startIndex){
        if(!modal) return;
        go(startIndex); // Sync main gallery
        updateModal(startIndex); // Sync modal gallery and start media
        modal.classList.add('open');
        document.body.style.overflow = 'hidden'; // Prevent body scroll
    }

    function closeModal(){
        if(!modal) return;
        modal.classList.remove('open');
        document.body.style.overflow = '';
        
        // Stop playback for all media when closing
        if (mSlides) {
            for(const slide of mSlides.children){
                 stopMediaPlayback(slide);
            }
        }
    }
    
    // Main gallery navigation
    prev?.addEventListener('click', ()=>go(idx-1));
    next?.addEventListener('click', ()=>go(idx+1));
    thumbs?.addEventListener('click', e=>{ const t=e.target.closest('.th[data-idx]'); if(!t) return; go(parseInt(t.dataset.idx)||0); });
    
    // Click on slide to open modal
    const gallery = document.getElementById('gal');
    gallery?.addEventListener('click', (e) => {
        // Only open modal if click is not on nav buttons
        if (!e.target.closest('.nav')) {
            openModal(idx);
        }
    });

    // Modal navigation
    mPrev?.addEventListener('click', ()=>updateModal(idx-1));
    mNext?.addEventListener('click', ()=>updateModal(idx+1));
    mClose?.addEventListener('click', closeModal);
    
    // FIX: Close on backdrop click and X button click
    modal?.addEventListener('click', (e) => { 
        if(e.target === modal || e.target.closest('#modalClose')) { // Check if click is on backdrop or close button/icon
            closeModal(); 
        }
    });
    
    document.addEventListener('keydown', (e) => {
        if(modal?.classList.contains('open')){
            if (e.key === 'Escape') closeModal();
            if (e.key === 'ArrowLeft') updateModal(idx-1);
            if (e.key === 'ArrowRight') updateModal(idx+1);
        }
    });

    // Main gallery swipe support
    let startX=null;
    slides.addEventListener('touchstart', e=>{ startX=e.touches[0].clientX; }, {passive:true});
    slides.addEventListener('touchmove', e=>{ if(startX==null) return; const dx=e.touches[0].clientX-startX; if(Math.abs(dx)>50){ go(idx+(dx<0?1:-1)); startX=null; } }, {passive:true});
    
    // Modal swipe support 
    let mStartX=null;
    mSlides?.addEventListener('touchstart', e=>{ mStartX=e.touches[0].clientX; }, {passive:true});
    mSlides?.addEventListener('touchmove', e=>{ if(mStartX==null) return; const dx=e.touches[0].clientX-mStartX; if(Math.abs(dx)>50){ updateModal(idx+(dx<0?1:-1)); mStartX=null; } }, {passive:true});

    go(0);
  })();

  /* ---------- Add To Cart (index.php-র মতো Toast) ---------- */
  async function addToCart(productId, qty){
    const btn = document.getElementById('btnAdd');
    const original = btn ? btn.innerHTML : '';
    let soldOut = false;
    try{
      if(btn){ btn.disabled=true; btn.innerHTML = 'যোগ হচ্ছে <span class="spinner"></span>'; }

      const body = new URLSearchParams({ product_id:String(productId), qty:String(qty || 1) });
      const meta = document.querySelector('meta[name="csrf-token"]');
      if (meta && meta.content) body.set('csrf', meta.content);

      const res = await fetch('cart_add.php', {
        method:'POST',
        headers:{ 'X-Requested-With':'fetch', 'Content-Type':'application/x-www-form-urlencoded' },
        body: body
      });

      const data = await res.json().catch(()=> ({}));
      if (data && data.stock_out) {
        soldOut = true;
        showToast({ type:'error', title:'স্টক আউট', message:data.message || 'এই পণ্যটি এখন স্টক আউট (Stock Out)।', duration:2000 });
        if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-ban"></i> স্টক নেই'; }
        return;
      }
      if(!res.ok || !data.ok){
        throw new Error(data.message || 'Failed');
      }

      showToast({ type:'success', title:'কার্টে যুক্ত হয়েছে', message:(data.message||'পণ্যটি আপনার কার্টে যোগ হয়েছে।'), duration:1500 });

    }catch(e){
      showToast({ type:'error', title:'দুঃখিত!', message:e.message || 'কার্টে যোগ করা যায়নি।', duration:1800 });
    }finally{
      if(btn && !soldOut){
        btn.innerHTML = '<i class="fa-solid fa-check"></i> কার্টে যোগ হয়েছে';
        setTimeout(()=>{ btn.disabled=false; btn.innerHTML = original || '<i class="fa-solid fa-cart-plus"></i> ADD TO CART'; }, 1500);
      }
    }
  }

  /* ---------- Tabs click fix (accessible) ---------- */
  (function(){
    const tabs = document.querySelectorAll('.tabs .tab');
    const panels = {
      desc: document.getElementById('tab-desc'),
      spec: document.getElementById('tab-spec')
    };
    function activate(key){
      tabs.forEach(btn=>{
        const on = btn.dataset.tab === key;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Object.entries(panels).forEach(([k,el])=>{
        const on = (k === key);
        if(el){
          el.classList.toggle('active', on);
          el.setAttribute('aria-hidden', on ? 'false' : 'true');
        }
      });
    }
    document.querySelector('.tabs')?.addEventListener('click', (e)=>{
      const btn = e.target.closest('.tab[data-tab]');
      if(!btn) return;
      activate(btn.dataset.tab);
    });
    // কিবোর্ড সাপোর্ট (ঐচ্ছিক, বেসিক)
    document.querySelector('.tabs')?.addEventListener('keydown', (e)=>{
      const order = Array.from(tabs);
      const cur = document.activeElement;
      const idx = order.indexOf(cur);
      if(idx === -1) return;
      if(e.key === 'ArrowRight'){ e.preventDefault(); const n=order[(idx+1)%order.length]; n.focus(); n.click(); }
      if(e.key === 'ArrowLeft'){ e.preventDefault(); const p=order[(idx-1+order.length)%order.length]; p.focus(); p.click(); }
    });
  })();
</script>

<?php include __DIR__.'/includes/partials_footer.php'; ?>