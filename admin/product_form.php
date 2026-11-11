<?php
// admin/product_form.php — Drag&Drop images + single video + YouTube + confirm modal for video remove
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/image_processor.php';
require_once __DIR__.'/../includes/home_sections.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_products'); // <-- পারমিশন চেক

$pdo = get_pdo();

/* ------------ Local helpers (no conflict with config) ------------ */
function pf_uploads_dir_abs(): string {
  if (defined('PRODUCT_UPLOAD_DIR') && PRODUCT_UPLOAD_DIR) {
    $rp = realpath(PRODUCT_UPLOAD_DIR);
    $dir = $rp !== false ? $rp : PRODUCT_UPLOAD_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
  }
  if (defined('UPLOAD_DIR') && UPLOAD_DIR) { $rp = realpath(UPLOAD_DIR); return $rp!==false?$rp:UPLOAD_DIR; }
  $fallback = __DIR__.'/../uploads'; $rp = realpath($fallback); return $rp!==false?$rp:$fallback;
}
function pf_uploads_url_base(): string {
  return defined('PRODUCT_UPLOAD_URL') && PRODUCT_UPLOAD_URL
    ? rtrim(PRODUCT_UPLOAD_URL, '/')
    : (defined('UPLOAD_URL') ? rtrim(UPLOAD_URL, '/') : '');
}
function pf_is_default(string $pathOrUrl): bool { return strtolower(basename($pathOrUrl)) === 'default.jpg'; }
function pf_path_in_dir(string $path, string $baseDir): bool {
  $rp = realpath($path); $rb = realpath($baseDir);
  if ($rp === false || $rb === false) return false;
  return strpos($rp, $rb) === 0;
}
function pf_is_windows_abs(string $p): bool { return (bool)preg_match('~^[A-Za-z]:\\\\~', $p); }
function pf_to_abs(string $p): ?string {
  $p = trim($p); if ($p==='') return null;
  if (pf_is_windows_abs($p) || (substr($p,0,1)==='/' && file_exists($p))) return $p;
  if (preg_match('~^https?://~i', $p)) { $parts = parse_url($p); $p = $parts['path'] ?? ''; }
  if (defined('BASE_URL') && BASE_URL && strpos($p, BASE_URL)===0)   $p = substr($p, strlen(BASE_URL));
  if (defined('UPLOAD_URL') && UPLOAD_URL && strpos($p, UPLOAD_URL)===0) $p = substr($p, strlen(UPLOAD_URL));
  $p = ltrim($p, '/');
  if (stripos($p, 'uploads/')===0) $p = substr($p, strlen('uploads/'));
  /* products_image/ প্রিফিক্স বাদ — কারণ pf_uploads_dir_abs() নিজেই ওই ফোল্ডার */
  if (stripos($p, 'products_image/')===0) $p = substr($p, strlen('products_image/'));
  return rtrim(pf_uploads_dir_abs(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
}
function pf_unlink_with_variants(string $abs, array &$log): void {
  $uploads = pf_uploads_dir_abs();
  if (!file_exists($abs) || !is_file($abs)) return;
  if (!pf_path_in_dir($abs, $uploads)) return;
  if (pf_is_default($abs)) return;
  @unlink($abs); $log[]=$abs;
  $dir=dirname($abs); $b=basename($abs); $n=pathinfo($b,PATHINFO_FILENAME); $e=pathinfo($b,PATHINFO_EXTENSION);
  foreach (['_sm','_md','_lg','-sm','-md','-lg'] as $suf) {
    $cand = $dir.DIRECTORY_SEPARATOR.$n.$suf.($e?'.'.$e:'');
    if (file_exists($cand) && is_file($cand)) { @unlink($cand); $log[]=$cand; }
  }
}
/* ---- YouTube helpers ---- */
function pf_youtube_id_from_url(string $url): ?string {
  $url = trim($url); if ($url==='') return null;
  $res = [
    '~youtu\.be/([A-Za-z0-9_\-]{6,})~i',
    '~youtube\.com/(?:watch\?v=|embed/|v/|shorts/)([A-Za-z0-9_\-]{6,})~i',
    '~[?&]v=([A-Za-z0-9_\-]{6,})~'
  ];
  foreach($res as $re){ if (preg_match($re,$url,$m)) return $m[1]; }
  return null;
}
function pf_youtube_canonical(string $id): string { return 'https://www.youtube.com/watch?v='.$id; }

/* ---- Tag helpers (for Free Delivery + Hot Item) ---- */
function pf_tags_to_array_preserve(string $tags): array {
  $parts = preg_split('/[\n\r;,|#]+/u', $tags);
  $out = [];
  foreach ($parts as $t) { $t = trim($t); if ($t!=='') $out[]=$t; }
  return $out;
}
function pf_array_casein_remove(array $arr, array $removeList): array {
  $out = [];
  foreach ($arr as $v) {
    $keep = true;
    foreach ($removeList as $r) {
      if (mb_strtolower(trim($v)) === mb_strtolower(trim($r))) { $keep = false; break; }
    }
    if ($keep) $out[] = $v;
  }
  return $out;
}
function pf_array_casein_has(array $arr, array $needles): bool {
  foreach ($arr as $v) {
    $lv = mb_strtolower(trim($v));
    foreach ($needles as $n) if ($lv === mb_strtolower(trim($n))) return true;
  }
  return false;
}

/** মূল পণ্যের ছবির ফাইল নতুন পণ্যের জন্য আলাদা নামে কপি (শেয়ার করলে একটি মুছলে অন্যটির ছবি যেত) */
function pf_copy_images(PDO $pdo, int $srcId, int $newId): int {
  $st = $pdo->prepare("SELECT image FROM product_images WHERE product_id=? ORDER BY sort_order, id");
  $st->execute([$srcId]);
  $list = $st->fetchAll(PDO::FETCH_COLUMN);
  if (!$list) return 0;
  $root = realpath(UPLOAD_DIR);
  $dstDir = rtrim(PRODUCT_UPLOAD_DIR, '/\\');
  if (!is_dir($dstDir)) @mkdir($dstDir, 0755, true);
  $ins = $pdo->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (?,?,?)");
  $n = 0;
  foreach ($list as $p) {
    $p = trim((string)$p);
    if ($p === '' || pf_is_default($p) || preg_match('~^(https?:)?//~i', $p)) continue;
    if (defined('BASE_URL') && BASE_URL && strpos($p, BASE_URL) === 0) $p = substr($p, strlen(BASE_URL));
    $abs = realpath(dirname(UPLOAD_DIR) . '/' . ltrim(str_replace('\\', '/', $p), '/'));
    if ($abs === false || $root === false || strpos($abs, $root) !== 0 || !is_file($abs)) continue;
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true)) continue;
    $fn = 'product_' . $newId . '_' . time() . '_cp' . $n . '.' . $ext;
    if (!@copy($abs, $dstDir . '/' . $fn)) continue;
    $ins->execute([$newId, '/uploads/products_image/' . $fn, $n]);
    $n++;
  }
  return $n;
}

/* ------------ CSRF & constants ------------ */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
if (!is_dir(pf_uploads_dir_abs())) { @mkdir(pf_uploads_dir_abs(), 0777, true); }
define('DEFAULT_IMG_URL', pf_uploads_url_base().'/default.jpg');

/* ------------ ID ------------ */
$id = (int)($_GET['id'] ?? 0);
$copySrcId = ($id === 0 && isset($_GET['copy']) && is_string($_GET['copy']) && ctype_digit($_GET['copy'])) ? (int)$_GET['copy'] : 0;

/* ========================
   Delete a single image (DB + file) — (GET keep as-is)
   ======================== */
if (isset($_GET['delimg']) && $id>0){
  $imgId=(int)$_GET['delimg'];
  $stm=$pdo->prepare("SELECT image FROM product_images WHERE id=? AND product_id=?");
  $stm->execute([$imgId,$id]); $imgUrl=$stm->fetchColumn();
  if ($imgUrl){
    $pdo->prepare("DELETE FROM product_images WHERE id=? AND product_id=?")->execute([$imgId,$id]);
    if (!pf_is_default($imgUrl)) { $abs=pf_to_abs($imgUrl); if($abs){ $tmp=[]; pf_unlink_with_variants($abs,$tmp); } }
    // main fix
    $m=$pdo->prepare("SELECT image FROM product_images WHERE product_id=? ORDER BY sort_order,id LIMIT 1");
    $m->execute([$id]); $main=$m->fetchColumn();
    if (!$main){
      $pdo->prepare("INSERT INTO product_images(product_id,image,sort_order) VALUES(?,?,0)")
          ->execute([$id, DEFAULT_IMG_URL]);
      $main=DEFAULT_IMG_URL;
    }
    $pdo->prepare("UPDATE products SET image=? WHERE id=?")->execute([$main,$id]);
  }
  header('Location: product_form.php?id='.$id.'&updated=1'); exit;
}

/* ========================
   Remove Video (POST + CSRF) with confirm modal
   ======================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rm_video_file'])){
  if (!csrf_verify()) { $_SESSION['flash']=['ok'=>false,'msg'=>'CSRF token mismatch']; header('Location: products.php'); exit; }
  $pid=(int)($_POST['id'] ?? 0);
  if ($pid>0){
    try{
      $row=$pdo->prepare("SELECT id,url FROM product_videos WHERE product_id=? AND type='file' LIMIT 1");
      $row->execute([$pid]); $v=$row->fetch(PDO::FETCH_ASSOC);
      if ($v){
        $pdo->prepare("DELETE FROM product_videos WHERE id=?")->execute([$v['id']]);
        $abs=pf_to_abs($v['url'] ?? ''); if($abs){ $lg=[]; pf_unlink_with_variants($abs,$lg); }
      }
    }catch(Throwable $e){}
  }
  header('Location: product_form.php?id='.$pid.'&updated=1'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rm_video_yt'])){
  if (!csrf_verify()) { $_SESSION['flash']=['ok'=>false,'msg'=>'CSRF token mismatch']; header('Location: products.php'); exit; }
  $pid=(int)($_POST['id'] ?? 0);
  if ($pid>0){
    try{ $pdo->prepare("DELETE FROM product_videos WHERE product_id=? AND type='youtube'")->execute([$pid]); }catch(Throwable $e){}
  }
  header('Location: product_form.php?id='.$pid.'&updated=1'); exit;
}

/* ========================
   Save (Add / Update) BEFORE output
   ======================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_product'])){
  if (!csrf_verify()){
    $_SESSION['flash']=['ok'=>false,'msg'=>'সিকিউরিটি টোকেন মিলেনি']; header('Location: products.php'); exit;
  }
  $pid    = (int)($_POST['id'] ?? 0);
  $name   = trim($_POST['name'] ?? '');
  $cat    = (int)($_POST['category_id'] ?? 0);
  $price  = (float)($_POST['price'] ?? 0);
  $compare= ($_POST['compare_at_price']!=='') ? (float)$_POST['compare_at_price'] : null;
  $stock  = (int)($_POST['stock'] ?? 0);
  $tagsIn = trim($_POST['tags'] ?? '');
  $specs  = trim($_POST['specifications'] ?? '');
  $desc   = trim($_POST['description'] ?? '');
  $active = isset($_POST['active']) ? 1 : 0;
  $badge_color = trim($_POST['badge_color'] ?? '');
  if ($badge_color !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $badge_color)) $badge_color = '';
  $youtube_url = trim($_POST['youtube_url'] ?? '');

  // NEW: flags from checkboxes
  $flag_free = isset($_POST['flag_free']) ? 1 : 0;
  $flag_hot  = isset($_POST['flag_hot'])  ? 1 : 0;

  // Normalize tags wrt flags
  $tagArr = pf_tags_to_array_preserve($tagsIn);
  // remove synonyms if unchecked (or to re-add clean)
  $tagArr = pf_array_casein_remove($tagArr, ['free','free shipping','free delivery']);
  $tagArr = pf_array_casein_remove($tagArr, ['hot','hot item']);

  if ($flag_free) { if (!pf_array_casein_has($tagArr, ['Free Shipping'])) $tagArr[]='Free Shipping'; }
  if ($flag_hot)  { if (!pf_array_casein_has($tagArr, ['Hot'])) $tagArr[]='Hot'; }

  $tags = implode(', ', array_values(array_unique($tagArr)));

  try{
    if ($pid>0){
      $pdo->prepare("UPDATE products SET category_id=?, name=?, description=?, price=?, compare_at_price=?, stock=?, tags=?, specifications=?, active=?, badge_color=? WHERE id=?")
          ->execute([$cat,$name,$desc,$price,$compare,$stock,$tags,$specs,$active,($badge_color ?: null),$pid]);
      $newId=$pid; $flash='পণ্য আপডেট হয়েছে';
    }else{
      $pdo->prepare("INSERT INTO products(category_id,name,description,price,compare_at_price,stock,tags,specifications,active,badge_color) VALUES(?,?,?,?,?,?,?,?,?,?)")
          ->execute([$cat,$name,$desc,$price,$compare,$stock,$tags,$specs,$active,($badge_color ?: null)]);
      $newId=(int)$pdo->lastInsertId(); $flash='নতুন পণ্য সংরক্ষণ হয়েছে';
    }

    /* ---- হোম সেকশন সংযোগ (section_ids[] — যেকোনো ইনপুট নিরাপদে ফিল্টার হয়) ---- */
    hs_set_product_sections($pdo, $newId, $_POST['section_ids'] ?? []);

    /* ---- Images: multiple (images[]) ----
       প্রতিটি ছবি 700×700 বর্গাকারে প্রসেস হয়:
       বড় হলে center-crop, ছোট হলে সাদা প্যাডিং। */
    $imgReport = [];
    $imgErrors = [];
    $copyFrom = ($pid === 0 && is_string($_POST['copy_from'] ?? null) && ctype_digit($_POST['copy_from'])) ? (int)$_POST['copy_from'] : 0;
    $newSaved = 0;
    if ($copyFrom > 0 && isset($_POST['copy_images'])) {
      try { $newSaved += pf_copy_images($pdo, $copyFrom, $newId); } catch (Throwable $e) {}
    }
    $sortBase = 0;
    try {
      $sb = $pdo->prepare("SELECT COALESCE(MAX(sort_order)+1,0) FROM product_images WHERE product_id=? AND LOWER(image) NOT LIKE '%/default.jpg'");
      $sb->execute([$newId]); $sortBase = (int)$sb->fetchColumn();
    } catch (Throwable $e) {}
    if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])){
      $f=$_FILES['images']; $total=count($f['name']);
      for($i=0;$i<$total;$i++){
        if (empty($f['name'][$i]) || $f['error'][$i]!==UPLOAD_ERR_OK) continue;
        if (!is_uploaded_file($f['tmp_name'][$i])) continue;
        $ext=strtolower(pathinfo($f['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext,['jpg','jpeg','png','webp','heic','heif'])) continue;

        /* ---------- সাইজ লিমিট (৫MB) ---------- */
        if ((int)($f['size'][$i] ?? 0) > 5 * 1024 * 1024) {
          $imgErrors[] = ($f['name'][$i] ?? 'ফাইল') . ' — ৫MB এর বেশি, বাদ দেওয়া হয়েছে।';
          continue;
        }

        // HEIC/HEIF → GD সাধারণত পড়তে পারে না; আগের মতো raw save
        $isHeic = in_array($ext, ['heic','heif'], true);
        $saveExt = $isHeic ? $ext : ($ext === 'jpeg' ? 'jpg' : $ext);

        /* ---------- আসল ইমেজ কিনা যাচাই (HEIC বাদে) ---------- */
        if (!$isHeic) {
          $info = @getimagesize($f['tmp_name'][$i]);
          $allowedTypes = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF  => 'gif',
          ];
          if (!$info || !isset($allowedTypes[$info[2]])) {
            $imgErrors[] = ($f['name'][$i] ?? 'ফাইল') . ' — বৈধ ইমেজ নয়, বাদ দেওয়া হয়েছে।';
            continue;
          }
          $saveExt = $allowedTypes[$info[2]];
        }

        $fname='product_'.$newId.'_'.time().'_img'.$i.'.'.$saveExt;
        $dest=rtrim(pf_uploads_dir_abs(),'/').'/'.$fname;

        if ($isHeic) {
          $ok = move_uploaded_file($f['tmp_name'][$i], $dest);
          $mode = 'raw';
        } else {
          $res = estore_process_product_image($f['tmp_name'][$i], $dest, estore_image_target_size());
          $ok   = !empty($res['ok']);
          $mode = $res['mode'] ?? 'none';
          if (!$ok) {
            // GD ব্যর্থ হলে raw ফাইল রেখে দিই যাতে আপলোড হারিয়ে না যায়
            $ok = @move_uploaded_file($f['tmp_name'][$i], $dest);
            $mode = 'raw-fallback';
          }
          if ($ok) $imgReport[] = ['mode'=>$mode, 'src'=>$res['src_w'].'x'.$res['src_h']];
        }

        if ($ok){
          $url=pf_uploads_url_base().'/'.$fname;
          $pdo->prepare("INSERT INTO product_images(product_id,image,sort_order) VALUES(?,?,?)")->execute([$newId,$url,$sortBase+$i]);
          $newSaved++;
        }
      }

      /* বাদ পড়া ফাইলের তালিকা flash-এ দেখাই */
      if (!empty($imgErrors)) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => implode(' | ', $imgErrors)];
      }
    }

    /* আসল ছবি (আপলোড বা কপি) এলে ডিফল্ট প্লেসহোল্ডার সারি সরে যায় (default.jpg ফাইল থাকে) */
    if ($newSaved > 0) {
      $pdo->prepare("DELETE FROM product_images WHERE product_id=? AND LOWER(image) LIKE '%/default.jpg'")->execute([$newId]);
    }

    /* ---- Video: single file ---- */
    if (!empty($_FILES['video']['name']) && $_FILES['video']['error']===UPLOAD_ERR_OK && is_uploaded_file($_FILES['video']['tmp_name'])){
      $ext=strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
      if (in_array($ext,['mp4','webm','ogg','ogv','mov','m4v'])){
        // delete old file if exists
        try{
          $old=$pdo->prepare("SELECT id,url FROM product_videos WHERE product_id=? AND type='file' LIMIT 1");
          $old->execute([$newId]); $ov=$old->fetch(PDO::FETCH_ASSOC);
          if ($ov){ $abs=pf_to_abs($ov['url']??''); if($abs){ $lg=[]; pf_unlink_with_variants($abs,$lg); } $pdo->prepare("DELETE FROM product_videos WHERE id=?")->execute([$ov['id']]); }
        }catch(Throwable $e){}
        // save new
        $fname='product_'.$newId.'_'.time().'_video.'.$ext;
        $dest=rtrim(pf_uploads_dir_abs(),'/').'/'.$fname;
        if (move_uploaded_file($_FILES['video']['tmp_name'], $dest)){
          $url=pf_uploads_url_base().'/'.$fname;
          $pdo->prepare("INSERT INTO product_videos(product_id,type,url,sort_order) VALUES(?,'file',?,0)")->execute([$newId,$url]);
        }
      }
    }

    /* ---- YouTube: single ---- */
    try{
      if ($youtube_url===''){
        $pdo->prepare("DELETE FROM product_videos WHERE product_id=? AND type='youtube'")->execute([$newId]);
      }else{
        $vid=pf_youtube_id_from_url($youtube_url);
        if ($vid){
          $canon=pf_youtube_canonical($vid);
          $pdo->prepare("DELETE FROM product_videos WHERE product_id=? AND type='youtube'")->execute([$newId]);
          $pdo->prepare("INSERT INTO product_videos(product_id,type,url,sort_order) VALUES(?,'youtube',?,0)")->execute([$newId,$canon]);
        }
      }
    }catch(Throwable $e){}

    /* ---- Ensure main image ---- */
    $m=$pdo->prepare("SELECT image FROM product_images WHERE product_id=? ORDER BY sort_order,id LIMIT 1");
    $m->execute([$newId]); $main=$m->fetchColumn();
    if (!$main){
      $pdo->prepare("INSERT INTO product_images(product_id,image,sort_order) VALUES(?,?,0)")->execute([$newId, DEFAULT_IMG_URL]);
      $main=DEFAULT_IMG_URL;
    }
    $pdo->prepare("UPDATE products SET image=? WHERE id=?")->execute([$main,$newId]);

    $_SESSION['flash']=['ok'=>true,'msg'=>$flash];
    header('Location: products.php'); exit;

  }catch(Throwable $e){
    $_SESSION['flash']=['ok'=>false,'msg'=>'সংরক্ষণ ব্যর্থ হয়েছে'];
    header('Location: products.php'); exit;
  }
}

/* ------------ Load data for render ------------ */
$cats=$pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$edit=null; $images=[]; $video_file=null; $video_yt=null;

if ($id>0){
  $stm=$pdo->prepare("SELECT * FROM products WHERE id=?"); $stm->execute([$id]); $edit=$stm->fetch(PDO::FETCH_ASSOC);
  try{ $q=$pdo->prepare("SELECT * FROM product_images WHERE product_id=? ORDER BY sort_order,id"); $q->execute([$id]); $images=$q->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable $e){}
  try{
    $vf=$pdo->prepare("SELECT * FROM product_videos WHERE product_id=? AND type='file' LIMIT 1"); $vf->execute([$id]); $video_file=$vf->fetch(PDO::FETCH_ASSOC) ?: null;
    $vy=$pdo->prepare("SELECT * FROM product_videos WHERE product_id=? AND type='youtube' LIMIT 1"); $vy->execute([$id]); $video_yt=$vy->fetch(PDO::FETCH_ASSOC) ?: null;
  }catch(Throwable $e){}
}

$copyRow = null; $copyImages = []; $ytPrefill = '';
if (!$edit && $copySrcId > 0) {
  $cs = $pdo->prepare("SELECT * FROM products WHERE id=?"); $cs->execute([$copySrcId]); $copyRow = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($copyRow) {
    $suffix = ' (কপি)';
    $copyRow['name'] = mb_substr((string)$copyRow['name'], 0, 255 - mb_strlen($suffix)) . $suffix;
    try {
      $ci = $pdo->prepare("SELECT image FROM product_images WHERE product_id=? ORDER BY sort_order, id"); $ci->execute([$copySrcId]);
      foreach ($ci->fetchAll(PDO::FETCH_COLUMN) as $im) { if (!pf_is_default((string)$im)) $copyImages[] = $im; }
      $cy = $pdo->prepare("SELECT url FROM product_videos WHERE product_id=? AND type='youtube' LIMIT 1"); $cy->execute([$copySrcId]); $ytPrefill = (string)($cy->fetchColumn() ?: '');
    } catch (Throwable $e) {}
  }
}
$fv = $edit ?: ($copyRow ?: []);

/* ---- হোম সেকশন টগল: active manual সেকশন; এডিটে বর্তমান সংযোগ, নতুনে new_default ---- */
$hsSections = [];
$hsChecked  = [];
try {
  $hsSections = hs_manual_sections($pdo);
  if ($edit) {
    $hsChecked = hs_product_section_ids($pdo, (int)$edit['id']);
  } else {
    foreach ($hsSections as $hs) { if ((int)$hs['new_default'] === 1) $hsChecked[] = (int)$hs['id']; }
  }
} catch (Throwable $e) { /* home_sections টেবিল না থাকলে টগল দেখাবে না */ }

/* ---- Derive flags from existing tags for checkbox default ---- */
$tagsExisting = (string)($fv['tags'] ?? '');
$tagArrExist  = pf_tags_to_array_preserve($tagsExisting);
$is_free_checked = pf_array_casein_has($tagArrExist, ['free','free shipping','free delivery']);
$is_hot_checked  = pf_array_casein_has($tagArrExist, ['hot','hot item']);

/* ------------ View ------------ */
require_once __DIR__.'/header.php';
?>
<style>
  /* UPDATED: Increased thumb size for consistency */
  .thumb{height:100px;width:100px;object-fit:cover;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
  .btn-pill{border-radius:999px}
  .img-chip{position:relative;display:inline-block}
  .img-chip .btn-del{position:absolute;top:-8px;right:-8px;border-radius:50%;line-height:1;padding:2px 7px}
  .dropzone{border:2px dashed #cbd5e1;border-radius:12px;padding:14px;text-align:center;background:#f8fafc;cursor:pointer}
  .dropzone.drag{background:#eef2ff;border-color:#6366f1}
  .dz-previews{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
  
  /* UPDATED CHIP SIZE AND REMOVED 'overflow: hidden' to fix button visibility */
  .dz-previews .chip{position:relative;width:100px;height:100px;border:1px solid #e5e7eb;border-radius:10px;background:#fff} /* Removed overflow:hidden */
  .dz-previews img{width:100%;height:100%;object-fit:cover;display:block}
  
  /* ADJUSTED REMOVE BUTTON POSITION: Adjusted coordinates for better visibility */
  .dz-previews .x{position:absolute;top:-8px;right:-8px;background:#ef4444;color:#fff;border:none;border-radius:50%;width:22px;height:22px;line-height:20px;text-align:center;font-weight:700;cursor:pointer;font-size:14px;}
  @media(max-width:576px){ .btn-toolbar.gap-2>.btn{width:100%} }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0"><?php echo $edit ? 'পণ্য এডিট' : ($copyRow ? 'পণ্য কপি' : 'নতুন পণ্য'); ?></h5>
  <div class="btn-toolbar gap-2">
    <a href="products.php" class="btn btn-outline-secondary btn-pill">পণ্য তালিকা</a>
    <?php if($edit): ?>
      <button type="button" class="btn btn-danger btn-pill ms-2" data-bs-toggle="modal" data-bs-target="#delModal"
              data-id="<?php echo (int)$edit['id']; ?>" data-name="<?php echo h($edit['name'] ?? ''); ?>">ডিলিট</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($copyRow): ?>
  <div class="alert alert-info py-2" id="copyBanner"><i class="fa-solid fa-copy"></i> <strong>"<?php echo h($copySrcId ? mb_substr(preg_replace('/ \(কপি\)$/u', '', $copyRow['name']), 0, 80) : ''); ?>"</strong> পণ্যের কপি। নাম, দাম, স্টক ঠিক করে <strong>Save</strong> চাপলে তবেই নতুন পণ্য তৈরি হবে — Save না করলে কিছুই তৈরি হবে না।</div>
<?php endif; ?>
<div class="card"><div class="card-body">
  <form method="post" enctype="multipart/form-data" id="productForm">
    <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
    <?php if ($copyRow): ?><input type="hidden" name="copy_from" value="<?php echo (int)$copySrcId; ?>"><?php endif; ?>

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">নাম</label>
        <input name="name" class="form-control" required value="<?php echo h($fv['name'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">ক্যাটেগরি</label>
        <select name="category_id" class="form-select">
          <option value="">-- None --</option>
          <?php foreach($cats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo (isset($fv['category_id']) && (int)$fv['category_id']===(int)$c['id'])?'selected':''; ?>>
              <?php echo h($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">মূল্য</label>
        <input type="number" step="0.01" name="price" class="form-control" required value="<?php echo h($fv['price'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">তুলনামূলক দাম</label>
        <input type="number" step="0.01" name="compare_at_price" class="form-control" value="<?php echo h($fv['compare_at_price'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">স্টক</label>
        <input type="number" name="stock" class="form-control" required value="<?php echo h($fv['stock'] ?? '0'); ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">ট্যাগ (কমা দিয়ে আলাদা)</label>
        <input type="text" name="tags" class="form-control" value="<?php echo h($fv['tags'] ?? ''); ?>">
        <div class="form-text">উদাহরণ: <code>new, best, discount</code>. নিচের চেকবক্স টিক দিলে ট্যাগে স্বয়ংক্রিয়ভাবে যোগ/বিয়োগ হবে।</div>
        <div class="mt-2 d-flex flex-wrap gap-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="flag_free" name="flag_free" <?php echo $is_free_checked?'checked':''; ?>>
            <label class="form-check-label" for="flag_free">Free Delivery</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="flag_hot" name="flag_hot" <?php echo $is_hot_checked?'checked':''; ?>>
            <label class="form-check-label" for="flag_hot">Hot Item</label>
          </div>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label">স্পেসিফিকেশন</label>
        <textarea name="specifications" class="form-control" rows="3" placeholder="বিস্তারিত স্পেসিফিকেশন..."><?php echo h($fv['specifications'] ?? ''); ?></textarea>
      </div>
      <div class="col-12">
        <label class="form-label">বিবরণ</label>
        <textarea name="description" class="form-control" rows="4"><?php echo h($fv['description'] ?? ''); ?></textarea>
      </div>

      <div class="col-12">
        <label class="form-label">ছবি (Drag & Drop / Multiple)</label>

        <!-- ===== ইমেজ সাইজ ইন্সট্রাকশন ===== -->
        <div class="border rounded-3 p-3 mb-2" style="background:#f8fafc;border-color:#e2e8f0!important">
          <div class="d-flex align-items-start gap-3 flex-wrap">
            <div class="flex-shrink-0 text-center">
              <div style="width:76px;height:76px;border:2px dashed #94a3b8;border-radius:10px;
                          display:flex;align-items:center;justify-content:center;
                          background:#fff;font-size:11px;font-weight:700;color:#475569;line-height:1.25">
                700<br>×<br>700
              </div>
              <div class="small text-muted mt-1" style="font-size:10px">বর্গাকার</div>
            </div>

            <div class="flex-grow-1" style="min-width:240px">
              <div class="fw-bold mb-1" style="font-size:14px">
                <i class="fa-solid fa-circle-info text-primary"></i>
                সেরা ফলাফলের জন্য ছবি <strong>৭০০ × ৭০০ px</strong> (বর্গাকার) দিন
              </div>
              <ul class="mb-2 ps-3 small text-muted" style="font-size:12px;line-height:1.65">
                <li>ফরম্যাট: <strong>JPG, PNG, WEBP</strong> · সর্বোচ্চ <strong>৫MB</strong> প্রতি ছবি</li>
                <li>বড় বা অসম অনুপাতের ছবি → <strong>স্বয়ংক্রিয়ভাবে center-crop</strong> হবে</li>
                <li>ছোট ছবি → <strong>সাদা ক্যানভাসে মাঝ বরাবর</strong> বসবে (চারপাশে ফাঁকা)</li>
                <li>ছবির গুরুত্বপূর্ণ অংশ <strong>কেন্দ্রের ৮০%-এর ভেতরে</strong> রাখুন</li>
              </ul>
              <div class="small" style="font-size:11.5px;color:#0369a1;background:#e0f2fe;border:1px solid #bae6fd;border-radius:8px;padding:6px 9px">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                আপলোডের পর সব ছবি অটোমেটিক ৭০০×৭০০ বানিয়ে দেওয়া হবে — আলাদা করে রিসাইজ করার দরকার নেই।
              </div>
            </div>
          </div>
        </div>

        <div id="dropzone" class="dropzone" tabindex="0">
          <div class="small text-muted">এখানে ছবি টেনে আনুন বা ক্লিক করে সিলেক্ট করুন (jpg, jpeg, png, webp)</div>
          <div class="dz-previews" id="dzPreviews"></div>
        </div>
        
        <div class="input-group mt-2">
          <input type="file" id="imagesInput" name="images[]" class="form-control" 
                 accept=".jpg,.jpeg,.png,.webp,image/*" multiple>
          <button type="button" class="btn btn-primary" id="cameraBtn" title="ছবি তুলুন">
            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" fill="currentColor" viewBox="0 0 16 16">
              <path d="M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0"/>
              <path d="M2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4zm.5-1A1.5 1.5 0 0 1 4 2.5h8A1.5 1.5 0 0 1 13.5 4v8a1.5 1.5 0 0 1-1.5 1.5H4A1.5 1.5 0 0 1 2.5 12zM2 6a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11A.5.5 0 0 1 2 6"/>
            </svg>
          </button>
        </div>
        
        <input type="file" id="cameraInput" style="display:none;" accept="image/*" capture="environment">
        
        <?php if ($copyRow && $copyImages): ?>
          <div class="mt-2 d-flex flex-wrap gap-2" id="copyImgs">
            <?php foreach ($copyImages as $im): ?>
              <div class="img-chip"><img src="<?php echo h($im); ?>" class="thumb" alt=""></div>
            <?php endforeach; ?>
          </div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="copy_images" id="copyImagesChk" value="1" checked>
            <label class="form-check-label" for="copyImagesChk">মূল পণ্যের <?php echo count($copyImages); ?>টি ছবি এই নতুন পণ্যে কপি হোক (Save করলে)</label>
          </div>
        <?php elseif(!empty($images)): ?>
          <div class="mt-2 d-flex flex-wrap gap-2">
            <?php foreach($images as $im): ?>
              <div class="img-chip">
                <img src="<?php echo h($im['image']); ?>" class="thumb" alt="">
                <a href="?id=<?php echo (int)($edit['id']); ?>&delimg=<?php echo (int)$im['id']; ?>"
                   class="btn btn-sm btn-danger btn-del" title="এই ছবি ডিলিট করুন">×</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-muted small mt-1">ছবি না থাকলে ডিফল্ট <code>uploads/default.jpg</code> সেট হবে।</div>
        <?php endif; ?>
      </div>

      <div class="col-12">
        <label class="form-label">ভিডিও আপলোড (mp4/webm/ogg/mov/m4v, ১টি)</label>
        <input type="file" name="video" class="form-control" accept="video/mp4,video/webm,video/ogg,video/ogv,video/quicktime,video/x-m4v">
        <?php if($video_file): ?>
          <div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
            <video controls preload="metadata" src="<?php echo h($video_file['url']); ?>" style="max-width:340px;width:100%"></video>
            <button type="button" class="btn btn-outline-danger btn-pill"
                    data-bs-toggle="modal" data-bs-target="#rmVideoFileModal">
              ভিডিও রিমুভ
            </button>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-12">
        <label class="form-label">YouTube লিংক (১টি)</label>
        <div class="input-group">
          <input type="url" name="youtube_url" class="form-control"
                 placeholder="https://youtu.be/... বা https://www.youtube.com/watch?v=..."
                 value="<?php echo h($video_yt['url'] ?? $ytPrefill); ?>">
          <?php if($video_yt): ?>
            <button type="button" class="btn btn-outline-danger"
                    data-bs-toggle="modal" data-bs-target="#rmVideoYtModal">
              রিমুভ
            </button>
          <?php endif; ?>
        </div>
        <div class="form-text">খালি রেখে Save করলে YouTube লিংক মুছে যাবে।</div>
      </div>

      <div class="col-12" id="hsSectionToggles">
        <label class="form-label d-block mb-2">হোমপেজের কোন কোন সেকশনে দেখাবেন</label>
        <?php if (!$hsSections): ?>
          <div class="form-text">
            কোনো “হাতে বাছাই” সেকশন নেই।
            <?php if (can('manage_home_sections')): ?><a href="home_sections.php">হোম সেকশন</a> মেনু থেকে যোগ করুন।<?php endif; ?>
          </div>
        <?php else: ?>
          <div class="row g-2" style="max-height:260px;overflow-y:auto">
            <?php foreach ($hsSections as $hs): $hsid = (int)$hs['id']; ?>
              <div class="col-md-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="section_ids[]" id="hsec<?php echo $hsid; ?>"
                         value="<?php echo $hsid; ?>" <?php echo in_array($hsid, $hsChecked, true) ? 'checked' : ''; ?>>
                  <label for="hsec<?php echo $hsid; ?>" class="form-check-label"><?php echo h($hs['title']); ?></label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="form-text">টিক দেওয়া সেকশনগুলোতে এই পণ্য হোমপেজে দেখাবে। কোনোটাতে টিক না দিলে হোমে কোথাও দেখাবে না (শপ পেজে থাকবে)।</div>
        <?php endif; ?>
      </div>

      <div class="col-md-4">
        <label class="form-label">কার্ড/স্টিকারের রঙ</label>
        <input type="color" name="badge_color" class="form-control form-control-color"
               value="<?php echo h(!empty($fv['badge_color']) ? $fv['badge_color'] : '#ff5252'); ?>">
        <div class="form-text">ডিসকাউন্ট/Buy বাটনে ব্যবহার হয়।</div>
      </div>

      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="active" id="activeChk"
            <?php echo (isset($fv['active']) ? ((int)$fv['active'] ? 'checked' : '') : 'checked'); ?>>
          <label for="activeChk" class="form-check-label">Active</label>
        </div>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary btn-pill" name="save_product" value="1"><?php echo $edit ? 'Update' : 'Save'; ?></button>
      <a href="products.php" class="btn btn-outline-secondary btn-pill">ক্যান্সেল</a>
    </div>
  </form>
</div></div>

<?php if($edit): ?>
<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header py-2">
      <h6 class="modal-title">পণ্য ডিলিট</h6>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="বন্ধ করুন"></button>
    </div>
    <div class="modal-body">
      আপনি কি নিশ্চিত?<br>
      <strong id="delName"></strong> ডিলিট হলে সংশ্লিষ্ট ইমেজ/ভিডিও (default.jpg ছাড়া) মুছে যাবে।
    </div>
    <div class="modal-footer py-2">
      <button type="button" class="btn btn-secondary btn-pill" data-bs-dismiss="modal">বাতিল</button>
      <form id="delForm" method="post" action="product_delete.php" class="d-inline">
        <input type="hidden" name="id"   value="<?php echo (int)($edit['id'] ?? 0); ?>">
        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
        <button type="submit" class="btn btn-danger btn-pill">ডিলিট</button>
      </form>
    </div>
  </div></div>
</div>
<?php endif; ?>

<?php if($edit && $video_file): ?>
<div class="modal fade" id="rmVideoFileModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header py-2">
      <h6 class="modal-title">ভিডিও ফাইল রিমুভ</h6>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="বন্ধ করুন"></button>
    </div>
    <div class="modal-body">
      এই আপলোডেড ভিডিওটি ডিলিট করবেন? (ফাইলসহ স্থায়ীভাবে মুছে যাবে)
    </div>
    <div class="modal-footer py-2">
      <button type="button" class="btn btn-secondary btn-pill" data-bs-dismiss="modal">বাতিল</button>
      <form method="post" action="product_form.php" class="d-inline">
        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
        <input type="hidden" name="id"   value="<?php echo (int)($edit['id'] ?? 0); ?>">
        <input type="hidden" name="rm_video_file" value="1">
        <button type="submit" class="btn btn-danger btn-pill">হ্যাঁ, ডিলিট করুন</button>
      </form>
    </div>
  </div></div>
</div>
<?php endif; ?>

<?php if($edit && $video_yt): ?>
<div class="modal fade" id="rmVideoYtModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header py-2">
      <h6 class="modal-title">YouTube লিংক রিমুভ</h6>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="বন্ধ করুন"></button>
    </div>
    <div class="modal-body">
      এই YouTube লিংকটি ডিলিট করবেন?
    </div>
    <div class="modal-footer py-2">
      <button type="button" class="btn btn-secondary btn-pill" data-bs-dismiss="modal">বাতিল</button>
      <form method="post" action="product_form.php" class="d-inline">
        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
        <input type="hidden" name="id"   value="<?php echo (int)($edit['id'] ?? 0); ?>">
        <input type="hidden" name="rm_video_yt" value="1">
        <button type="submit" class="btn btn-danger btn-pill">হ্যাঁ, ডিলিট করুন</button>
      </form>
    </div>
  </div></div>
</div>
<?php endif; ?>

<script>
/* Product delete modal fill */
(function(){
  const delModal=document.getElementById('delModal'); if(!delModal) return;
  delModal.addEventListener('show.bs.modal', function (ev) {
    const btn=ev.relatedTarget; const pid=btn?.getAttribute('data-id')||''; const name=btn?.getAttribute('data-name')||'';
    delModal.querySelector('#delName').textContent = name ? `“${name}”` : `#${pid}`;
    delModal.querySelector('#delForm input[name="id"]').value = pid;
  });
})();

/* Drag & Drop (multiple) with live preview + removable chips */
(function(){
  const dz = document.getElementById('dropzone');
  const input = document.getElementById('imagesInput');
  const previews = document.getElementById('dzPreviews');
  const cameraBtn = document.getElementById('cameraBtn');
  const cameraInput = document.getElementById('cameraInput'); // Isolated camera input
  
  if(!dz || !input || !previews || !cameraInput) return;

  let dt = new DataTransfer();
  function syncInput(){ input.files = dt.files; }
  
  function addFiles(files){
    for (const file of files){
      if (!file || !file.type.match(/^image\/(jpeg|png|webp|heic|heif)$/i)) continue; 
      const key = file.name+'|'+file.size+'|'+file.lastModified;
      let exists=false;
      for (let i=0;i<dt.files.length;i++){
        const f=dt.files[i];
        const k=f.name+'|'+f.size+'|'+f.lastModified;
        if (k===key){ exists=true; break; }
      }
      if (exists) continue;
      dt.items.add(file);
      const reader = new FileReader();
      reader.onload = e=>{
        const chip = document.createElement('div');
        chip.className='chip'; chip.dataset.key=key;
        chip.innerHTML = '<img src="'+e.target.result+'"><button class="x" type="button" aria-label="Remove">×</button>';
        previews.appendChild(chip);
      };
      reader.readAsDataURL(file);
    }
    syncInput();
  }
  
  function removeByKey(key){
    const ndt = new DataTransfer();
    for (let i=0;i<dt.files.length;i++){
      const f=dt.files[i];
      const k=f.name+'|'+f.size+'|'+f.lastModified;
      if (k!==key) ndt.items.add(f);
    }
    dt = ndt; syncInput();
  }
  
  // DRAG & DROP & REGULAR FILE SELECTORS still trigger the main input
  dz.addEventListener('click', (e)=>{ 
      // Ensure clicks on existing chips or buttons inside the dropzone are ignored
      if (e.target.closest('.chip')) return; 
      input.click(); 
  });
  dz.addEventListener('dragover', (e)=>{ e.preventDefault(); dz.classList.add('drag'); });
  dz.addEventListener('dragleave', ()=> dz.classList.remove('drag'));
  dz.addEventListener('drop', (e)=>{ e.preventDefault(); dz.classList.remove('drag'); if (e.dataTransfer?.files?.length) addFiles(e.dataTransfer.files); });
  
  // Input change event handles file selection (main input)
  input.addEventListener('change', (e)=>{ if (e.target.files?.length) addFiles(e.target.files); });
  
  // Camera button click handler triggers the ISOLATED camera input
  if (cameraBtn) {
    cameraBtn.addEventListener('click', (e)=>{ 
      e.preventDefault();
      cameraInput.click();
    });
  }
  
  // Handle file selection from the ISOLATED camera input
  cameraInput.addEventListener('change', (e)=>{ 
    if (e.target.files?.length) addFiles(e.target.files);
    e.target.value = null; // Clear input value so the camera can be re-opened immediately
  });

  // FIX: Stop propagation when clicking the remove button or the chip itself
  previews.addEventListener('click', (e)=>{
    // Stop event from propagating to the parent dropzone (which would open file explorer)
    e.stopPropagation(); 
      
    if (!e.target.classList.contains('x')) return;
    const chip = e.target.closest('.chip'); if (!chip) return;
    const key = chip.dataset.key; chip.remove(); removeByKey(key);
  });
})();
</script>

<?php require __DIR__.'/footer.php'; ?>