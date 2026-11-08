<?php
/**
 * fix_settings_encoding.php — mojibake হওয়া settings মান ঠিক করে (UTF-8)
 * ব্যবহার: php fix_settings_encoding.php [--dry]
 */
require 'C:/laragon/www/estore/includes/config.php';

$dry = in_array('--dry', $argv ?? [], true);

$correct = [
    'cod_note'    => 'সারা দেশে ক্যাশ অন ডেলিভারি প্রযোজ্য।',
    'home_notice' => 'বাংলাদেশের সেরা অনলাইন শপিং প্ল্যাটফর্ম। ক্যাশ অন ডেলিভারি সুবিধা সহ।',
    'store_about' => 'বাংলাদেশের সেরা অনলাইন শপিং প্ল্যাটফর্ম। ক্যাশ অন ডেলিভারি সুবিধা সহ।',
];

$pdo = get_pdo();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$stm = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
$upd = $pdo->prepare("UPDATE settings SET `value`=? WHERE `key`=?");

foreach ($correct as $key => $val) {
    $stm->execute([$key]);
    $cur = (string)($stm->fetchColumn() ?: '');
    $isMojibake = (strpos($cur, 'à¦') !== false) || (strpos($cur, 'à§') !== false);

    printf("%-14s mojibake=%-3s len=%d -> len=%d\n", $key, $isMojibake ? 'YES' : 'no', strlen($cur), strlen($val));

    if ($isMojibake && !$dry) {
        $upd->execute([$val, $key]);
    }
}

/* পুরো DB-তে আর কোথাও mojibake আছে কি না দেখি */
echo "\n--- scan other text columns ---\n";
$targets = [
    'products'   => ['name', 'description'],
    'categories' => ['name'],
    'pages'      => ['title', 'content'],
    'sliders'    => ['title', 'subtitle', 'btn_label'],
    'payment_methods' => ['label'],
];
foreach ($targets as $table => $cols) {
    foreach ($cols as $col) {
        try {
            $rows = $pdo->query("SELECT `id`,`$col` AS v FROM `$table` WHERE `$col` IS NOT NULL AND `$col` <> '' LIMIT 500")->fetchAll();
        } catch (Throwable $e) { continue; }
        $bad = [];
        foreach ($rows as $r) {
            $v = (string)$r['v'];
            if (strpos($v, 'à¦') !== false || strpos($v, 'à§') !== false || strpos($v, 'Ã') !== false) {
                $bad[] = (int)$r['id'];
            }
        }
        printf("%-16s.%-12s rows=%-5d mojibake=%d %s\n", $table, $col, count($rows), count($bad), $bad ? implode(',', array_slice($bad,0,10)) : '');
    }
}

echo $dry ? "\n(DRY RUN — কিছুই লেখা হয়নি)\n" : "\nDONE\n";
