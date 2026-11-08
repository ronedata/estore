<?php
/**
 * includes/image_processor.php
 * প্রোডাক্ট ইমেজ প্রসেসিং — সব ইমেজ একই বর্গাকার মাপে আনা হয়।
 *
 *  - বড় বা অসম অনুপাতের ছবি → center-crop করে (কোনো বিকৃতি নয়)
 *  - ছোট ছবি               → সাদা ক্যানভাসে মাঝ বরাবর বসিয়ে প্যাডিং
 *  - স্বচ্ছ PNG             → প্যাডিং সাদা (নিরাপদ)
 *
 * ব্যবহার:
 *   $res = estore_process_product_image($tmpPath, $destPath, 700);
 *   if ($res['ok']) { ... }
 */

if (!function_exists('estore_image_target_size')) {
  /** প্রোডাক্ট ইমেজের স্ট্যান্ডার্ড মাপ (px) */
  function estore_image_target_size(): int {
    return (int) (defined('PRODUCT_IMAGE_SIZE') ? PRODUCT_IMAGE_SIZE : 700);
  }
}

if (!function_exists('estore_slider_target_size')) {
  /**
   * slider placement অনুযায়ী লক্ষ্য মাপ [width, height]
   *  hero → 1200×675 (16:9)
   *  side → 600×375  (16:10)
   */
  function estore_slider_target_size(string $placement): array {
    return $placement === 'side' ? [600, 375] : [1200, 675];
  }
}

if (!function_exists('estore_image_label_for')) {
  /** placement অনুযায়ী পাঠযোগ্য নাম */
  function estore_image_label_for(string $placement): string {
    return $placement === 'side' ? 'সাইড কার্ড' : 'হিরো স্লাইডার';
  }
}

if (!function_exists('estore_category_target_size')) {
  /** ক্যাটেগরি আইকন — বর্গাকার 400×400 */
  function estore_category_target_size(): array {
    return [400, 400];
  }
}

if (!function_exists('estore_image_gd_available')) {
  function estore_image_gd_available(): bool {
    return function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
  }
}

if (!function_exists('estore_image_load')) {
  /**
   * ফাইল থেকে GD resource লোড করে।
   * @return array{0:?\GdImage,1:string} [image, error]
   */
  function estore_image_load(string $file): array {
    $info = @getimagesize($file);
    if (!$info) return [null, 'ইমেজ ফাইলটি বৈধ নয়'];

    $img = null;
    switch ($info[2]) {
      case IMAGETYPE_JPEG:
        $img = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file) : null;
        break;
      case IMAGETYPE_PNG:
        $img = function_exists('imagecreatefrompng') ? @imagecreatefrompng($file) : null;
        break;
      case IMAGETYPE_WEBP:
        $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : null;
        break;
      case IMAGETYPE_GIF:
        $img = function_exists('imagecreatefromgif') ? @imagecreatefromgif($file) : null;
        break;
      default:
        return [null, 'সমর্থিত নয় (শুধু JPG/PNG/WEBP/GIF)'];
    }

    if (!$img) return [null, 'ইমেজ পড়া যায়নি (সম্ভবত ফাইলটি করাপ্ট)'];
    return [$img, ''];
  }
}

if (!function_exists('estore_image_save')) {
  /** GD image নির্দিষ্ট এক্সটেনশনে সেভ করে (কোয়ালিটি সহ) */
  function estore_image_save(\GdImage $img, string $dest, string $ext): bool {
    $ext = strtolower($ext);
    switch ($ext) {
      case 'png':
        return function_exists('imagepng') && @imagepng($img, $dest, 7);
      case 'webp':
        return function_exists('imagewebp') && @imagewebp($img, $dest, 86);
      case 'gif':
        return function_exists('imagegif') && @imagegif($img, $dest);
      case 'jpg':
      case 'jpeg':
      default:
        return function_exists('imagejpeg') && @imagejpeg($img, $dest, 88);
    }
  }
}

if (!function_exists('estore_process_image')) {
  /**
   * যেকোনো অনুপাতে ইমেজ ফিট করে (বর্গ বা আয়তাকার)।
   *
   *  - সোর্স বড়/অসম → center-crop করে টার্গেট অনুপাতে
   *  - সোর্স ছোট      → পুরো ছবি দেখিয়ে প্যাডিং
   *
   * @param string $tmpPath  source
   * @param string $destPath destination
   * @param int    $tw       target width
   * @param int    $th       target height
   * @param string $bg       padding colour (hex)
   * @return array{ok:bool,error:string,mode:string,src_w:int,src_h:int,w:int,h:int}
   */
  function estore_process_image(string $tmpPath, string $destPath, int $tw, int $th, string $bg = '#ffffff'): array {
    $tw = max(1, $tw);
    $th = max(1, $th);
    $out = ['ok' => false, 'error' => '', 'mode' => 'none', 'src_w' => 0, 'src_h' => 0, 'w' => $tw, 'h' => $th];

    if (!estore_image_gd_available()) {
      if (@copy($tmpPath, $destPath)) { $out['ok'] = true; $out['mode'] = 'raw'; }
      else { $out['error'] = 'ফাইল সংরক্ষণ ব্যর্থ'; }
      return $out;
    }

    [$src, $err] = estore_image_load($tmpPath);
    if (!$src) { $out['error'] = $err; return $out; }

    $sw = imagesx($src);
    $sh = imagesy($src);
    $out['src_w'] = $sw;
    $out['src_h'] = $sh;

    $canvas = imagecreatetruecolor($tw, $th);
    if (!$canvas) { imagedestroy($src); $out['error'] = 'লক্ষ্য ইমেজ তৈরি ব্যর্থ'; return $out; }

    // প্যাডিং রঙ
    $bg = trim($bg);
    if ($bg === '' || !preg_match('/^#?[0-9a-f]{6}$/i', $bg)) $bg = '#ffffff';
    $bg = ltrim($bg, '#');
    $bgColor = imagecolorallocate(
      $canvas,
      hexdec(substr($bg, 0, 2)),
      hexdec(substr($bg, 2, 2)),
      hexdec(substr($bg, 4, 2))
    );
    imagefilledrectangle($canvas, 0, 0, $tw, $th, $bgColor);

    $srcRatio = $sw / max(1, $sh);
    $dstRatio = $tw / max(1, $th);

    if ($sw >= $tw && $sh >= $th) {
      // ---- যথেষ্ট বড় → center crop টার্গেট অনুপাতে ----
      if ($srcRatio > $dstRatio) {
        // সোর্স বেশি চওড়া → পাশ কাটব
        $cropH = $sh;
        $cropW = (int) round($sh * $dstRatio);
      } else {
        // সোর্স বেশি লম্বা → উপর-নিচ কাটব
        $cropW = $sw;
        $cropH = (int) round($sw / $dstRatio);
      }
      $cropW = max(1, min($cropW, $sw));
      $cropH = max(1, min($cropH, $sh));
      $sx = (int) floor(($sw - $cropW) / 2);
      $sy = (int) floor(($sh - $cropH) / 2);

      imagecopyresampled($canvas, $src, 0, 0, $sx, $sy, $tw, $th, $cropW, $cropH);
      $out['mode'] = 'crop';
    } else {
      // ---- ছোট → পুরো ছবি, প্যাডিং সহ ----
      $scale = min($tw / $sw, $th / $sh);
      $nw = max(1, (int) round($sw * $scale));
      $nh = max(1, (int) round($sh * $scale));
      $dx = (int) floor(($tw - $nw) / 2);
      $dy = (int) floor(($th - $nh) / 2);

      imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);
      $out['mode'] = 'pad';
    }

    imagedestroy($src);

    $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION)) ?: 'jpg';
    $ok = estore_image_save($canvas, $destPath, $ext);
    imagedestroy($canvas);

    if (!$ok) { $out['error'] = 'প্রসেস করা ইমেজ সেভ ব্যর্থ'; return $out; }

    @chmod($destPath, 0644);
    $out['ok'] = true;
    return $out;
  }
}

if (!function_exists('estore_process_product_image')) {
  /**
   * প্রোডাক্ট ইমেজ — বর্গাকার (ডিফল্ট 700×700)।
   * পুরোনো কল-সাইটের সাথে সামঞ্জস্য রাখতে রাখা হয়েছে।
   */
  function estore_process_product_image(string $tmpPath, string $destPath, int $size = 0, string $bg = '#ffffff'): array {
    $size = $size > 0 ? $size : estore_image_target_size();
    $r = estore_process_image($tmpPath, $destPath, $size, $size, $bg);
    $r['size'] = $size;           // পুরোনো API compat
    return $r;
  }
}

if (!function_exists('estore_image_describe')) {
  /** আপলোড করা ইমেজের প্রকৃত মাপ বর্ণনা (ইন্সট্রাকশন/প্রিভিউর জন্য) */
  function estore_image_describe(string $file): array {
    $info = @getimagesize($file);
    if (!$info) return ['ok' => false];
    return [
      'ok' => true,
      'w'  => (int)$info[0],
      'h'  => (int)$info[1],
      'mime' => image_type_to_mime_type($info[2]),
    ];
  }
}
