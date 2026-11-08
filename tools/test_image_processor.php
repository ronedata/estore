<?php
/** tools/test_image_processor.php — প্রসেসরের সব কেস যাচাই */
require __DIR__.'/../includes/config.php';
require __DIR__.'/../includes/image_processor.php';

$dir = 'C:/Users/rone/AppData/Local/Temp/kilo/imgtest';
@mkdir($dir, 0777, true);

$cases = [
  ['big_square', 1400, 1400, 'jpg'],
  ['big_wide',   1600,  900, 'jpg'],
  ['big_tall',    800, 1600, 'jpg'],
  ['small',       200,  200, 'jpg'],
  ['small_wide',  300,  150, 'jpg'],
  ['tiny',         50,   50, 'png'],
  ['exact',       700,  700, 'jpg'],
];

/** দ্রুত প্যাটার্ন ইমেজ — striping দিয়ে, প্রতি পিক্সেল loop নয় */
function make(string $path, int $w, int $h, string $ext): void {
    $im = imagecreatetruecolor($w, $h);
    $blue = imagecolorallocate($im, 30, 90, 200);
    $grey = imagecolorallocate($im, 240, 240, 240);
    imagefilledrectangle($im, 0, 0, $w, $h, $grey);

    $band = max(8, (int) round(min($w, $h) / 12));
    for ($x = 0; $x < $w; $x += $band * 2) {
        imagefilledrectangle($im, $x, 0, min($x + $band, $w - 1), $h, $blue);
    }

    // কেন্দ্রে লাল বর্গ — center-crop যাচাইয়ের জন্য
    $red = imagecolorallocate($im, 220, 40, 40);
    $s = (int) round(min($w, $h) * 0.24);
    $cx = (int) round($w / 2);
    $cy = (int) round($h / 2);
    imagefilledrectangle($im, $cx - (int)($s/2), $cy - (int)($s/2), $cx + (int)($s/2), $cy + (int)($s/2), $red);

    $ext === 'png' ? imagepng($im, $path) : imagejpeg($im, $path, 90);
    imagedestroy($im);
}

echo str_pad('case', 12) . str_pad('source', 12) . str_pad('mode', 8) . str_pad('result', 12) . "center pixel\n";
echo str_repeat('-', 62) . "\n";

foreach ($cases as [$name, $w, $h, $ext]) {
    $src = "$dir/{$name}.{$ext}";
    $dst = "$dir/{$name}_out.{$ext}";
    make($src, $w, $h, $ext);

    $res = estore_process_product_image($src, $dst, 700);
    $outInfo = is_file($dst) ? getimagesize($dst) : null;
    $dim = $outInfo ? "{$outInfo[0]}x{$outInfo[1]}" : 'FAILED';

    $center = 'n/a';
    if (is_file($dst)) {
        $o = @imagecreatefromstring(file_get_contents($dst));
        if ($o) {
            $c = imagecolorat($o, 350, 350);
            $center = (($c >> 16) & 0xFF) . ',' . (($c >> 8) & 0xFF) . ',' . ($c & 0xFF);
            imagedestroy($o);
        }
    }

    printf("%-12s %-12s %-8s %-12s %s %s\n", $name, "{$w}x{$h}", $res['mode'], $dim, $center,
        $res['ok'] ? '' : ' <- ' . $res['error']);
}

echo "\n--- exact 700x700 check ---\n";
$bad = 0;
foreach ($cases as [$name, , , $ext]) {
    $i = @getimagesize("$dir/{$name}_out.{$ext}");
    $ok = $i && $i[0] === 700 && $i[1] === 700;
    if (!$ok) $bad++;
    printf("%-12s %s\n", $name, $ok ? 'PASS' : 'FAIL');
}
echo $bad === 0 ? "\nALL SIZES OK\n" : "\n$bad FAILED\n";
