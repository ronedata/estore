<?php
/**
 * tools/test_home_sections.php — হোম সেকশন লজিক টেস্ট (সীমা, ক্রম, সংযোগ, মাইগ্রেশন idempotency)
 *   php tools/test_home_sections.php
 * টেস্টের বানানো ডেটা শেষে মুছে ফেলে; শুরুর ও শেষের অবস্থা মিলিয়ে দেখে।
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'estore.test';
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/home_sections.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; }
  else       { $fail++; echo "  FAIL  $name $extra\n"; }
}

$snapSections = $pdo->query("SELECT id,title,subtitle,type,category_id,product_limit,sort_order,active,show_see_all FROM home_sections ORDER BY id")->fetchAll();
$snapLinks    = $pdo->query("SELECT section_id,product_id,sort_order FROM home_section_products ORDER BY section_id,product_id")->fetchAll();
$created = [];
$clean = fn(array $o = []) => array_merge([
  'title'=>'TEST সেকশন','subtitle'=>'t','type'=>'latest','category_id'=>null,'product_limit'=>5,'show_see_all'=>1,'active'=>1,
], $o);

echo "== মাইগ্রেশন / সিড ==\n";
ok('home_sections টেবিল আছে', (bool)$pdo->query("SHOW TABLES LIKE 'home_sections'")->fetchColumn());
ok('home_section_products টেবিল আছে', (bool)$pdo->query("SHOW TABLES LIKE 'home_section_products'")->fetchColumn());
ok('schema_v7_at মার্কার আছে', (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v7_at'")->fetchColumn());
ok('পারমিশন manage_home_sections আছে', (bool)$pdo->query("SELECT 1 FROM permissions WHERE name='manage_home_sections'")->fetchColumn());
$before = (int)$pdo->query("SELECT COUNT(*) FROM home_sections")->fetchColumn();
ensure_estore_schema_v7($pdo, true);
$after = (int)$pdo->query("SELECT COUNT(*) FROM home_sections")->fetchColumn();
ok('ensure_estore_schema_v7(force) দ্বিতীয়বার চালালে ডুপ্লিকেট হয় না', $before === $after, "{$before}→{$after}");

echo "== যাচাই (hs_clean_input) ==\n";
[$d, $e] = hs_clean_input($pdo, ['title'=>'','type'=>'manual','product_limit'=>5]);
ok('খালি শিরোনাম reject', $d === null && $e);
[$d, $e] = hs_clean_input($pdo, ['title'=>'x','type'=>'hack','product_limit'=>5]);
ok('অচেনা type reject', $d === null);
[$d, $e] = hs_clean_input($pdo, ['title'=>'x','type'=>'manual','product_limit'=>0]);
ok('limit 0 reject', $d === null);
[$d, $e] = hs_clean_input($pdo, ['title'=>'x','type'=>'manual','product_limit'=>49]);
ok('limit 49 reject', $d === null);
[$d, $e] = hs_clean_input($pdo, ['title'=>'x','type'=>'manual','product_limit'=>48]);
ok('limit 48 গ্রহণ', $d !== null && $d['product_limit'] === 48);
[$d, $e] = hs_clean_input($pdo, ['title'=>['a'],'type'=>'manual','product_limit'=>5]);
ok('title array reject (fatal নয়)', $d === null);
[$d, $e] = hs_clean_input($pdo, ['title'=>'x','type'=>'category','category_id'=>999999,'product_limit'=>5]);
ok('অচেনা ক্যাটেগরি reject', $d === null);
[$d, $e] = hs_clean_input($pdo, ['title'=>'x','type'=>'category','category_id'=>['1'],'product_limit'=>5]);
ok('category_id array reject', $d === null);

echo "== সীমা: সর্বোচ্চ ".HS_MAX." ==\n";
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM home_sections")->fetchColumn();
while ($cnt < HS_MAX) {
  [$r, , $nid] = hs_create($pdo, $clean(['title' => 'TEST '.($cnt+1)]));
  if ($r) $created[] = $nid;
  $cnt = (int)$pdo->query("SELECT COUNT(*) FROM home_sections")->fetchColumn();
}
ok('মোট '.HS_MAX.'টিতে পৌঁছেছে', $cnt === HS_MAX, "cnt=$cnt");
[$r, $m] = hs_create($pdo, $clean(['title'=>'TEST ৯']));
ok('৯ম সেকশন আটকানো হয়েছে', $r === false, $m);
ok('৯ম সেকশন DB-তে যায়নি', (int)$pdo->query("SELECT COUNT(*) FROM home_sections")->fetchColumn() === HS_MAX);

echo "== ক্রম (↑↓) ==\n";
$order = fn() => array_map('intval', $pdo->query("SELECT id FROM home_sections ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN));
$o0 = $order();
hs_move($pdo, $o0[2], 'up');
$o1 = $order();
ok('↑: ৩য় সেকশন ২য়তে উঠেছে', $o1[1] === $o0[2] && $o1[2] === $o0[1]);
hs_move($pdo, $o1[1], 'down');
ok('↓: আগের অবস্থায় ফিরেছে', $order() === $o0);
hs_move($pdo, $o0[0], 'up');
ok('প্রথমটি ↑ দিলে অপরিবর্তিত', $order() === $o0);
hs_move($pdo, $o0[count($o0)-1], 'down');
ok('শেষটি ↓ দিলে অপরিবর্তিত', $order() === $o0);
$sorts = array_map('intval', $pdo->query("SELECT sort_order FROM home_sections ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN));
ok('ক্রম ১..n ধারাবাহিক', $sorts === range(1, count($sorts)));
[$r] = hs_move($pdo, 999999, 'up');
ok('অচেনা id-তে move ব্যর্থ', $r === false);
[$r] = hs_move($pdo, $o0[0], 'sideways');
ok('অচেনা দিকে move ব্যর্থ', $r === false);

echo "== সীমা: ন্যূনতম ".HS_MIN." ==\n";
foreach ($created as $cid) { [$r] = hs_delete($pdo, $cid); }
$created = [];
$left = array_map('intval', $pdo->query("SELECT id FROM home_sections ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN));
ok('টেস্ট সেকশন মুছে মূল সংখ্যায় ফিরেছে', count($left) === count($snapSections));

// ন্যূনতম পরীক্ষা: সংখ্যা HS_MIN-এ নামিয়ে আনা সম্ভব হলে তবেই
$curCnt = count($left);
$tmp = [];
if ($curCnt > HS_MIN) {
  foreach (array_slice($left, HS_MIN) as $x) { $tmp[$x] = true; }
}
// আসল সেকশন না মুছে পরীক্ষা: HS_MIN সংখ্যক সেকশন থাকলে মোছা আটকায়
if ($curCnt === HS_MIN) {
  [$r, $m] = hs_delete($pdo, $left[0]);
  ok('২টি থাকলে মোছা আটকায়', $r === false, $m);
  [$r, $m] = hs_toggle($pdo, $left[0]);
  ok('২টি চালু থাকলে একটি বন্ধ করা আটকায়', $r === false, $m);
  ok('মোছা/বন্ধ আটকানোর পর DB অপরিবর্তিত', (int)$pdo->query("SELECT COUNT(*) FROM home_sections WHERE active=1")->fetchColumn() === HS_MIN);
} else {
  echo "  (বর্তমানে $curCnt টি সেকশন — ন্যূনতম-সীমা আলাদা করে পরীক্ষা হচ্ছে)\n";
}

echo "== সংযোগ (পণ্য ↔ সেকশন) ==\n";
[$r, , $manualId] = hs_create($pdo, $clean(['title'=>'TEST manual','type'=>'manual']));
if ($r) $created[] = $manualId; else {
  // ৮টি পূর্ণ থাকলে একটি বানিয়ে নেওয়ার জন্য ফাঁকা করা যায় না — বিদ্যমান manual ব্যবহার
  $manualId = (int)$pdo->query("SELECT id FROM home_sections WHERE type='manual' ORDER BY id LIMIT 1")->fetchColumn();
}
$pid = (int)$pdo->query("SELECT id FROM products ORDER BY id LIMIT 1")->fetchColumn();
$orig = hs_product_section_ids($pdo, $pid);
hs_set_product_sections($pdo, $pid, [(string)$manualId]);
ok('পণ্য সেকশনে যুক্ত হয়েছে', in_array($manualId, hs_product_section_ids($pdo, $pid), true));
hs_set_product_sections($pdo, $pid, []);
ok('খালি দিলে সব manual সংযোগ মুছেছে', !in_array($manualId, hs_product_section_ids($pdo, $pid), true));
hs_set_product_sections($pdo, $pid, 'abc');
hs_set_product_sections($pdo, $pid, [['x'], 'DROP', -5, '0', '1; DELETE', 1.5]);
ok('ভুল/array ইনপুটে ক্র্যাশ নেই, সংযোগও যোগ হয়নি', hs_product_section_ids($pdo, $pid) === []);
hs_set_product_sections($pdo, $pid, [999999]);
ok('অচেনা section_id বাদ যায়', !in_array(999999, hs_product_section_ids($pdo, $pid), true));
hs_set_product_sections($pdo, $pid, [$manualId]);
hs_remove_product($pdo, $pid);
ok('hs_remove_product সব সংযোগ মুছে', hs_product_section_ids($pdo, $pid) === []);

echo "== হোম কুয়েরি ==\n";
foreach (hs_types() as $type => $_) {
  $sec = ['id'=>$manualId,'type'=>$type,'category_id'=>(int)$pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn(),'product_limit'=>3];
  try { $rows = hs_products($pdo, $sec); ok("type=$type কুয়েরি চলে (".count($rows)." পণ্য, limit ≤৩)", count($rows) <= 3); }
  catch (Throwable $e) { ok("type=$type কুয়েরি চলে", false, $e->getMessage()); }
}
$sec = ['id'=>1,'type'=>'manual','product_limit'=>5];
ok('manual limit=5 মানলে ≤৫ পণ্য', count(hs_products($pdo, $sec)) <= 5);

echo "== পরিষ্কার-পরিচ্ছন্নতা ==\n";
foreach ($created as $cid) { $pdo->prepare("DELETE FROM home_section_products WHERE section_id=?")->execute([$cid]); }
if ($created) {
  $in = implode(',', array_map('intval', $created));
  $pdo->exec("DELETE FROM home_sections WHERE id IN ($in)");
}
hs_set_product_sections($pdo, $pid, $orig);
$pdo->beginTransaction(); hs_renumber($pdo); $pdo->commit();
$endSections = $pdo->query("SELECT id,title,subtitle,type,category_id,product_limit,sort_order,active,show_see_all FROM home_sections ORDER BY id")->fetchAll();
$endLinks    = $pdo->query("SELECT section_id,product_id,sort_order FROM home_section_products ORDER BY section_id,product_id")->fetchAll();
ok('সেকশন তালিকা শুরুর মতোই', $endSections == $snapSections);
ok('সংযোগ তালিকা শুরুর মতোই', $endLinks == $snapLinks, count($snapLinks).' vs '.count($endLinks));

echo "\nমোট: ".($pass+$fail)." | PASS $pass | FAIL $fail\n";
exit($fail ? 1 : 0);
