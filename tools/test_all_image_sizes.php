<?php
/** tools/test_all_image_sizes.php — product / slider / category সব টার্গেট যাচাই */
require __DIR__.'/../includes/config.php';
require __DIR__.'/../includes/image_processor.php';

$dir = 'C:/Users/rone/AppData/Local/Temp/kilo/imgtest2';
@mkdir($dir, 0777, true);

function mk(string $path, int $w, int $h, string $ext = 'jpg'): void {
    $im = imagecreatetruecolor($w, $h);
    $grey = imagecolorallocate($im, 240, 240, 240);
    imagefilledrectangle($im, 0, 0, $w, $h, $grey);

    $blue = imagecolorallocate($im, 30, 90, 200);
    $band = max(8, (int) round(min($w, $h) / 12));
    for ($x = 0; $x < $w; $x += $band * 2) {
        imagefilledrectangle($im, $x, 0, min($x + $band, $w - 1), $h, $blue);
    }

    $red = imagecolorallocate($im, 220, 40, 40);
    $s = (int) round(min($w, $h) * 0.20);
    $cx = (int) round($w / 2);
    $cy = (int) round($h / 2);
    imagefilledrectangle($im, $cx - (int)($s/2), $cy - (int)($s/2), $cx + (int)($s/2), $cy + (int)($s/2), $red);

    $ext === 'png' ? imagepng($im, $path) : imagejpeg($im, $path, 90);
    imagedestroy($im);
}

$targets = [
  ['product 700x700', 700, 700],
  ['hero 1200x675', 1200, 675],
  ['side 600x375',   600, 375],
  ['category 400x400', 400, 400],
];

$sources = [
  ['sq_1500',     1500, 1500],
  ['wide_2000',   2000,  800],
  ['tall_900',     900, 1800],
  ['small_150',    150,  150],
  ['tiny_60',       60,   60],
];

$fail = 0;
foreach ($targets as [$tName, $tw, $th]) {
    echo "\n=== $tName ===\n";
    printf("%-12s %-12s %-6s %-12s %s\n", 'source', 'size', 'mode', 'result', 'verdict');
    echo str_repeat('-', 58) . "\n";

    foreach ($sources as [$sName, $sw, $sh]) {
        $src = "$dir/{$sName}.jpg";
        mk($src, $sw, $sh);

        $dst = "$dir/{$sName}_{$tw}x{$th}.jpg";
        $res = estore_process_image($src, $dst, $tw, $th);

        $info = @getimagesize($dst);
        $ok = $info && (int)$info[0] === $tw && (int)$info[1] === $th;
        if (!$ok) $fail++;

        printf("%-12s %-12s %-6s %-12s %s\n",
            $sName, "{$sw}x{$sh}", $res['mode'],
            $info ? "{$info[0]}x{$info[1]}" : 'FAILED',
            $ok ? 'PASS' : 'FAIL');
    }
}

echo "\n" . ($fail === 0 ? "ALL TARGETS OK\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);
