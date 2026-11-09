<?php
// includes/announcements.php — ঘোষণা বার ও পপআপ (helper)

if (!defined('ANN_MAX_IMAGE')) define('ANN_MAX_IMAGE', 5 * 1024 * 1024);
if (!defined('ANN_DELAY_MAX')) define('ANN_DELAY_MAX', 60);
if (!defined('ANN_DAYS_MAX'))  define('ANN_DAYS_MAX', 365);

if (!function_exists('ann_kinds')) {

  function ann_kinds(): array {
    return ['bar' => 'বার (সব পেজের ওপরে)', 'popup' => 'পপআপ (শুধু হোমে)'];
  }

  function ann_colors(): array {
    return ['blue' => ['নীল', '#0284c7'], 'orange' => ['কমলা', '#ff7043'], 'green' => ['সবুজ', '#2e7d32']];
  }

  function ann_reshow_modes(): array {
    return [
      'never'   => 'কেটে দিলে আর দেখাবে না (লেখা বদলালে আবার আসবে)',
      'session' => 'কেটে দিলে ব্রাউজার বন্ধ না করা পর্যন্ত দেখাবে না',
      'days'    => 'কেটে দিলে নির্দিষ্ট দিন পর আবার দেখাবে',
      'always'  => 'কেটে দিলেও পরের বার পেজ খুললেই দেখাবে',
    ];
  }

  function ann_uploads_dir(): string {
    $dir = dirname(__DIR__) . '/uploads/announcements';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
  }

  function ann_all(PDO $pdo): array {
    try { return $pdo->query("SELECT * FROM announcements ORDER BY kind ASC, id DESC")->fetchAll() ?: []; }
    catch (Throwable $e) { return []; }
  }

  function ann_get(PDO $pdo, int $id): ?array {
    try {
      $st = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
      $st->execute([$id]);
      return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
  }

  /** সময়সীমা অনুযায়ী এখন দেখানোর মতো কি না */
  function ann_is_live(array $a, ?int $now = null): bool {
    if ((int)($a['active'] ?? 0) !== 1) return false;
    $now = $now ?? time();
    if (!empty($a['start_at']) && strtotime((string)$a['start_at']) > $now) return false;
    if (!empty($a['end_at'])   && strtotime((string)$a['end_at'])   < $now) return false;
    return true;
  }

  /** প্রতি ধরনে একটিই দেখায়: সর্বশেষ (বড় id) চালু ও সময়সীমার ভেতরের */
  function ann_current(PDO $pdo, string $kind): ?array {
    if (!isset(ann_kinds()[$kind])) return null;
    try {
      $st = $pdo->prepare("SELECT * FROM announcements WHERE kind = ? AND active = 1 ORDER BY id DESC");
      $st->execute([$kind]);
      foreach ($st->fetchAll() as $a) { if (ann_is_live($a)) return $a; }
    } catch (Throwable $e) {}
    return null;
  }

  /** '' = লিংক নেই, null = অবৈধ */
  function ann_clean_url(string $u): ?string {
    $u = trim($u);
    if ($u === '') return '';
    if (strlen($u) > 500 || preg_match('/[\x00-\x1F\s\\\\]/', $u) || substr($u, 0, 2) === '//') return null;
    if (preg_match('#^https?://#i', $u)) return $u;
    if ($u[0] === '/' && substr($u, 0, 2) !== '//') return $u;
    if (preg_match('#^[A-Za-z0-9_\-./]+(\?\S*)?$#', $u) && !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $u)) return $u;
    return null;
  }

  function ann_link_href(string $u): string {
    if ($u === '') return '';
    if (preg_match('#^https?://#i', $u)) return $u;
    return ($u[0] === '/') ? $u : (defined('BASE_URL') ? BASE_URL . '/' . $u : '/' . $u);
  }

  /** null = খালি, false = অবৈধ */
  function ann_dt($v) {
    $v = is_scalar($v) ? trim((string)$v) : '';
    if ($v === '') return null;
    $t = strtotime(str_replace('T', ' ', $v));
    return $t ? date('Y-m-d H:i:s', $t) : false;
  }

  /** @return array{0:?array,1:?string} [পরিষ্কার ডেটা, ত্রুটি] */
  function ann_clean_input(array $in): array {
    $s = function ($k, $max) use ($in) {
      $v = isset($in[$k]) && is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
      return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    };
    $kind = $s('kind', 10);
    if (!isset(ann_kinds()[$kind])) return [null, 'ঘোষণার ধরন সঠিক নয়।'];

    $title   = $s('title', 160);
    $message = $s('message', $kind === 'bar' ? 200 : 2000);
    $btn     = $s('btn_label', 60);
    $color   = $s('color', 10);
    if (!isset(ann_colors()[$color])) $color = 'blue';

    $link = ann_clean_url($s('link_url', 500));
    if ($link === null) return [null, 'লিংক সঠিক নয় (http://, https:// বা / দিয়ে শুরু হতে হবে)।'];

    $delay = is_scalar($in['delay_sec'] ?? null) ? (int)$in['delay_sec'] : 3;
    if ($delay < 0 || $delay > ANN_DELAY_MAX) return [null, 'দেরি ০ থেকে ' . ANN_DELAY_MAX . ' সেকেন্ডের মধ্যে হতে হবে।'];

    $reshow = $s('reshow', 10);
    if (!isset(ann_reshow_modes()[$reshow])) return [null, 'আবার দেখানোর নিয়ম সঠিক নয়।'];
    $days = is_scalar($in['reshow_days'] ?? null) ? (int)$in['reshow_days'] : 0;
    if ($reshow === 'days') {
      if ($days < 1 || $days > ANN_DAYS_MAX) return [null, 'দিনের সংখ্যা ১ থেকে ' . ANN_DAYS_MAX . '-এর মধ্যে হতে হবে।'];
    } elseif ($days < 1 || $days > ANN_DAYS_MAX) {
      $days = 7;
    }

    $start = ann_dt($in['start_at'] ?? ''); $end = ann_dt($in['end_at'] ?? '');
    if ($start === false || $end === false) return [null, 'তারিখ সঠিক নয়।'];
    if ($start && $end && strtotime($end) <= strtotime($start)) return [null, 'শেষের সময় শুরুর পরে হতে হবে।'];

    if ($kind === 'bar' && $message === '') return [null, 'বারের বার্তা লিখুন।'];

    return [[
      'kind' => $kind, 'title' => $title, 'message' => $message, 'link_url' => $link,
      'btn_label' => $btn, 'color' => $color,
      'closable' => !empty($in['closable']) ? 1 : 0,
      'delay_sec' => $delay, 'reshow' => $reshow, 'reshow_days' => $days,
      'start_at' => $start, 'end_at' => $end,
      'active' => !empty($in['active']) ? 1 : 0,
    ], null];
  }

  /** @return array{0:?string,1:?string} [image path, error] */
  function ann_handle_upload(string $field): array {
    if (empty($_FILES[$field]['name'])) return [null, null];
    $f = $_FILES[$field];
    if (!is_string($f['name']) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [null, 'ইমেজ আপলোড ব্যর্থ।'];
    if ((int)$f['size'] > ANN_MAX_IMAGE) return [null, 'ইমেজ ৫MB-এর কম হতে হবে।'];
    $info = @getimagesize($f['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($allowed[$info[2]])) return [null, 'শুধু JPG, PNG, WEBP, GIF ইমেজ গ্রহণ করা হয়।'];

    $ext  = $allowed[$info[2]];
    $name = 'ann_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = ann_uploads_dir() . DIRECTORY_SEPARATOR . $name;

    $saved = false;
    if ($ext !== 'gif' && $info[0] > 900 && function_exists('imagecreatetruecolor')) {
      $src = $ext === 'jpg' ? @imagecreatefromjpeg($f['tmp_name'])
           : ($ext === 'png' ? @imagecreatefrompng($f['tmp_name']) : @imagecreatefromwebp($f['tmp_name']));
      if ($src) {
        $nw = 900; $nh = (int)round($info[1] * 900 / $info[0]);
        $dst = imagecreatetruecolor($nw, $nh);
        if ($ext !== 'jpg') { imagealphablending($dst, false); imagesavealpha($dst, true); }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $info[0], $info[1]);
        $saved = $ext === 'jpg' ? imagejpeg($dst, $dest, 85)
               : ($ext === 'png' ? imagepng($dst, $dest, 6) : imagewebp($dst, $dest, 85));
        imagedestroy($src); imagedestroy($dst);
      }
    }
    if (!$saved && !@move_uploaded_file($f['tmp_name'], $dest) && !@copy($f['tmp_name'], $dest)) {
      return [null, 'ইমেজ সেভ করা যায়নি।'];
    }
    return ['uploads/announcements/' . $name, null];
  }

  function ann_unlink_image(string $path): void {
    $path = trim($path);
    if ($path === '' || stripos(ltrim($path, '/'), 'uploads/announcements/') !== 0) return;
    $abs  = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
    $real = realpath($abs); $root = realpath(ann_uploads_dir());
    if ($real && $root && strpos($real, $root) === 0 && is_file($real)) @unlink($real);
  }

  /** @return array{0:?int,1:string} [id, বার্তা] */
  function ann_save(PDO $pdo, array $in, int $id = 0): array {
    [$d, $err] = ann_clean_input($in);
    if ($err) return [null, $err];

    $old = $id ? ann_get($pdo, $id) : null;
    if ($id && !$old) return [null, 'ঘোষণা পাওয়া যায়নি।'];

    $img = (string)($old['image'] ?? '');
    $newImg = null;
    if ($d['kind'] === 'popup') {
      [$newImg, $uerr] = ann_handle_upload('image');
      if ($uerr) return [null, $uerr];
      $removeOld = $newImg || !empty($in['remove_image']);
      $keep = $removeOld ? ($newImg ?: '') : $img;
      if ($d['title'] === '' && $d['message'] === '' && $keep === '') {
        if ($newImg) ann_unlink_image($newImg);
        return [null, 'পপআপে অন্তত শিরোনাম, লেখা বা ছবি দিন।'];
      }
      $finalImg = $keep;
    } else {
      $finalImg = '';
    }
    $d['image'] = $finalImg;

    try {
      $cols = array_keys($d);
      if ($id) {
        $sql = "UPDATE announcements SET " . implode(',', array_map(fn($c) => "`$c`=?", $cols)) . " WHERE id=?";
        $pdo->prepare($sql)->execute(array_merge(array_values($d), [$id]));
        $res = [$id, 'ঘোষণা আপডেট হয়েছে।'];
      } else {
        $sql = "INSERT INTO announcements(`" . implode('`,`', $cols) . "`) VALUES(" . implode(',', array_fill(0, count($cols), '?')) . ")";
        $pdo->prepare($sql)->execute(array_values($d));
        $res = [(int)$pdo->lastInsertId(), 'নতুন ঘোষণা যোগ হয়েছে।'];
      }
      if ($img !== '' && $img !== $finalImg) ann_unlink_image($img);
      return $res;
    } catch (Throwable $e) {
      error_log('[announcements save] ' . $e->getMessage());
      if ($newImg) ann_unlink_image($newImg);
      return [null, 'সেভ করা যায়নি।'];
    }
  }

  function ann_toggle(PDO $pdo, int $id): array {
    try {
      $st = $pdo->prepare("UPDATE announcements SET active = 1 - active WHERE id = ?");
      $st->execute([$id]);
      return [$st->rowCount() > 0, 'অবস্থা বদলেছে।'];
    } catch (Throwable $e) { return [false, 'বদলানো যায়নি।']; }
  }

  function ann_delete(PDO $pdo, int $id): array {
    $a = ann_get($pdo, $id);
    if (!$a) return [false, 'ঘোষণা পাওয়া যায়নি।'];
    try {
      $pdo->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
      if (!empty($a['image'])) ann_unlink_image((string)$a['image']);
      return [true, 'ঘোষণা মুছে ফেলা হয়েছে।'];
    } catch (Throwable $e) { return [false, 'মোছা যায়নি।']; }
  }

  /** "কেটে দেওয়া" মনে রাখার নিয়ম — লেখা বদলালে (updated_at) চাবি বদলায়, তাই আবার দেখায় */
  function ann_memory_attrs(array $a): string {
    $key = 'ann_' . (int)$a['id'] . '_' . (int)strtotime((string)($a['updated_at'] ?? ''));
    return ' data-ann-key="' . h($key) . '" data-ann-reshow="' . h($a['reshow']) . '" data-ann-days="' . (int)$a['reshow_days'] . '"';
  }

  function ann_render_bar(PDO $pdo): void {
    $a = ann_current($pdo, 'bar');
    if (!$a) return;
    $hex  = ann_colors()[$a['color']][1] ?? '#0284c7';
    $href = ann_link_href((string)$a['link_url']);
    $ext  = preg_match('#^https?://#i', $href) ? ' target="_blank" rel="noopener"' : '';
    echo '<div class="ann-bar" id="annBar" role="region" aria-label="ঘোষণা" style="--ann-bg:' . h($hex) . '"' . ann_memory_attrs($a) . '>';
    echo '<div class="ann-bar-inner">';
    $msg = h($a['message']);
    echo $href !== '' ? '<a class="ann-bar-text" href="' . h($href) . '"' . $ext . '>' . $msg . '</a>'
                      : '<span class="ann-bar-text">' . $msg . '</span>';
    if ((int)$a['closable'] === 1) {
      echo '<button type="button" class="ann-bar-close" id="annBarClose" aria-label="বন্ধ করুন"><i class="fa-solid fa-xmark"></i></button>';
    }
    echo '</div></div>';
  }

  function ann_render_popup(PDO $pdo): void {
    $a = ann_current($pdo, 'popup');
    if (!$a) return;
    $href = ann_link_href((string)$a['link_url']);
    $ext  = preg_match('#^https?://#i', $href) ? ' target="_blank" rel="noopener"' : '';
    $img  = !empty($a['image']) ? img_url((string)$a['image'], '') : '';
    echo '<div class="ann-pop" id="annPop" hidden data-ann-delay="' . (int)$a['delay_sec'] . '"' . ann_memory_attrs($a) . '>';
    echo '<div class="ann-pop-backdrop" data-ann-close></div>';
    echo '<div class="ann-pop-box" role="dialog" aria-modal="true"'
       . ((string)$a['title'] !== '' ? ' aria-labelledby="annPopTitle"' : ' aria-label="ঘোষণা"') . ' tabindex="-1">';
    echo '<button type="button" class="ann-pop-x" data-ann-close aria-label="বন্ধ করুন"><i class="fa-solid fa-xmark"></i></button>';
    if ($img !== '') {
      $im = '<img class="ann-pop-img" src="' . h($img) . '" alt="' . h($a['title']) . '">';
      echo $href !== '' ? '<a href="' . h($href) . '"' . $ext . ' data-ann-close>' . $im . '</a>' : $im;
    }
    $hasBtn = $href !== '' && (string)$a['btn_label'] !== '';
    if ((string)$a['title'] !== '' || (string)$a['message'] !== '' || $hasBtn) {
      echo '<div class="ann-pop-body">';
      if ((string)$a['title'] !== '')   echo '<h3 id="annPopTitle">' . h($a['title']) . '</h3>';
      if ((string)$a['message'] !== '') echo '<p>' . nl2br(h($a['message'])) . '</p>';
      if ($hasBtn) {
        $hex = ann_colors()[$a['color']][1] ?? '#0284c7';
        echo '<a class="ann-pop-btn" style="background:' . h($hex) . '" href="' . h($href) . '"' . $ext . ' data-ann-close>' . h($a['btn_label']) . '</a>';
      }
      echo '</div>';
    }
    echo '</div></div>';
  }
}
