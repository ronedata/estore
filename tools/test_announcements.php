<?php
// tools/test_announcements.php — ঘোষণা (বার/পপআপ) লজিক টেস্ট; নিজের তৈরি ডেটা শেষে মুছে ফেলে
// php tools/test_announcements.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/announcements.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}

$pdo->exec("DELETE FROM announcements WHERE message LIKE '[T]%' OR title LIKE '[T]%'");
$made = [];
$snapReal = $pdo->query("SELECT id, active, kind, IFNULL(message,''), IFNULL(title,'') FROM announcements WHERE message NOT LIKE '[T]%' AND IFNULL(title,'') NOT LIKE '[T]%' ORDER BY id")->fetchAll(PDO::FETCH_NUM);
$base = ['kind'=>'bar','message'=>'[T] বার','active'=>1,'color'=>'blue','closable'=>1,'delay_sec'=>3,'reshow'=>'days','reshow_days'=>7];

echo "\n=== মাইগ্রেশন ও পারমিশন ===\n";
t('announcements টেবিল আছে', (bool)$pdo->query("SHOW TABLES LIKE 'announcements'")->fetchColumn());
t('schema_v8_at চিহ্ন আছে', (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v8_at'")->fetchColumn());
t('manage_announcements পারমিশন আছে', (bool)$pdo->query("SELECT 1 FROM permissions WHERE name='manage_announcements'")->fetchColumn());
t('Super Admin রোলে পারমিশন যুক্ত', (bool)$pdo->query("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=1 AND p.name='manage_announcements'")->fetchColumn());
t('দ্বিতীয়বার চালালে ত্রুটি নেই', ensure_estore_schema_v8($pdo, true) === true);

echo "\n=== ইনপুট যাচাই ===\n";
t('ঠিক ইনপুট গ্রহণ', ann_clean_input($base)[1] === null);
t('অবৈধ ধরন বাতিল', ann_clean_input(['kind'=>'x']+$base)[1] !== null);
t('বারে খালি বার্তা বাতিল', ann_clean_input(['message'=>'']+$base)[1] !== null);
t('javascript: লিংক বাতিল', ann_clean_input(['link_url'=>'javascript:alert(1)']+$base)[1] !== null);
t('data: লিংক বাতিল', ann_clean_input(['link_url'=>'data:text/html,x']+$base)[1] !== null);
t('//evil.com বাতিল', ann_clean_input(['link_url'=>'//evil.com']+$base)[1] !== null);
t('https লিংক ঠিক', ann_clean_input(['link_url'=>'https://a.com/x?y=1']+$base)[1] === null);
t('shop.php?section=1 ঠিক', ann_clean_input(['link_url'=>'shop.php?section=1']+$base)[1] === null);
t('/shop.php ঠিক', ann_clean_input(['link_url'=>'/shop.php']+$base)[1] === null);
t('দেরি -১ বাতিল', ann_clean_input(['delay_sec'=>-1]+$base)[1] !== null);
t('দেরি ৬১ বাতিল', ann_clean_input(['delay_sec'=>61]+$base)[1] !== null);
t('দেরি ০ ঠিক', ann_clean_input(['delay_sec'=>0]+$base)[1] === null);
t('দেরি ৬০ ঠিক', ann_clean_input(['delay_sec'=>60]+$base)[1] === null);
t('reshow অবৈধ বাতিল', ann_clean_input(['reshow'=>'zzz']+$base)[1] !== null);
t('reshow=days-এ দিন ০ বাতিল', ann_clean_input(['reshow_days'=>0]+$base)[1] !== null);
t('reshow=session-এ খালি দিন বাধা নয়', ann_clean_input(['reshow'=>'session','reshow_days'=>'']+$base)[1] === null);
t('reshow=session-এ খালি দিন → ৭', ann_clean_input(['reshow'=>'session','reshow_days'=>'']+$base)[0]['reshow_days'] === 7);
t('reshow=days-এ খালি দিন বাতিল', ann_clean_input(['reshow'=>'days','reshow_days'=>'']+$base)[1] !== null);
t('reshow=days-এ দিন ৩৬৬ বাতিল', ann_clean_input(['reshow_days'=>366]+$base)[1] !== null);
t('শেষ ≤ শুরু বাতিল', ann_clean_input(['start_at'=>'2030-01-02T10:00','end_at'=>'2030-01-01T10:00']+$base)[1] !== null);
t('অবৈধ তারিখ বাতিল', ann_clean_input(['start_at'=>'not-a-date']+$base)[1] !== null);
t('অ্যারে ইনপুটে ফ্যাটাল নেই', is_array(ann_clean_input(['kind'=>['x'],'message'=>['y']]+$base)));
t('অবৈধ রং → blue', ann_clean_input(['color'=>'pink']+$base)[0]['color'] === 'blue');
t('বার্তা ২০০ অক্ষরে কাটে (বার)', mb_strlen(ann_clean_input(['message'=>str_repeat('অ', 500)]+$base)[0]['message'], 'UTF-8') === 200);

echo "\n=== সেভ / এডিট / টগল / মোছা ===\n";
[$id1, ] = ann_save($pdo, $base); $made[] = $id1;
t('বার তৈরি হলো', (bool)$id1);
[$idp, $m] = ann_save($pdo, ['kind'=>'popup','title'=>'[T] পপআপ','message'=>'লেখা','delay_sec'=>5,'reshow'=>'never','active'=>1] + $base); $made[] = $idp;
t('পপআপ তৈরি হলো', (bool)$idp);
[$bad, ] = ann_save($pdo, ['kind'=>'popup','title'=>'','message'=>'','active'=>1] + $base);
t('শিরোনাম-লেখা-ছবি সব খালি পপআপ বাতিল', $bad === null);
$row = ann_get($pdo, (int)$idp);
t('পপআপের delay ও reshow সংরক্ষিত', (int)$row['delay_sec'] === 5 && $row['reshow'] === 'never');
t('বারে image খালি', (string)ann_get($pdo, (int)$id1)['image'] === '');
ann_save($pdo, ['message'=>'[T] বার নতুন'] + $base, (int)$id1);
t('এডিটে বার্তা বদলালো', ann_get($pdo, (int)$id1)['message'] === '[T] বার নতুন');
$before = ann_get($pdo, (int)$id1)['active'];
ann_toggle($pdo, (int)$id1);
t('টগলে active উল্টালো', ann_get($pdo, (int)$id1)['active'] != $before);
ann_toggle($pdo, (int)$id1);

echo "\n=== কোনটি দেখাবে (ann_current) ===\n";
$cur = ann_current($pdo, 'bar');
t('একটিই বার আসে', $cur && (int)$cur['id'] === (int)$id1);
[$id2, ] = ann_save($pdo, ['message'=>'[T] বার দুই'] + $base); $made[] = $id2;
t('একাধিক হলে নতুনটি', (int)ann_current($pdo, 'bar')['id'] === (int)$id2);
[$id3, ] = ann_save($pdo, ['message'=>'[T] ভবিষ্যৎ','start_at'=>date('Y-m-d\TH:i', time()+86400)] + $base); $made[] = $id3;
t('ভবিষ্যতের শিডিউল দেখায় না', (int)ann_current($pdo, 'bar')['id'] === (int)$id2);
[$id4, ] = ann_save($pdo, ['message'=>'[T] মেয়াদোত্তীর্ণ','start_at'=>date('Y-m-d\TH:i', time()-7200),'end_at'=>date('Y-m-d\TH:i', time()-3600)] + $base); $made[] = $id4;
t('মেয়াদ শেষ দেখায় না', (int)ann_current($pdo, 'bar')['id'] === (int)$id2);
ann_toggle($pdo, (int)$id2);
t('নতুনটি বন্ধ করলে আগেরটি আসে', (int)ann_current($pdo, 'bar')['id'] === (int)$id1);
t('পপআপ ও বার আলাদা', (int)ann_current($pdo, 'popup')['id'] === (int)$idp);

echo "\n=== রেন্ডার ও নিরাপত্তা ===\n";
ann_save($pdo, ['kind'=>'popup','title'=>'[T] <script>alert(1)</script>','message'=>'<img src=x onerror=1>','link_url'=>'shop.php','btn_label'=>'যান','reshow'=>'session','delay_sec'=>5,'active'=>1] + $base, (int)$idp);
ob_start(); ann_render_popup($pdo); $html = ob_get_clean();
t('পপআপে <script> escape', strpos($html, '<script>alert') === false && strpos($html, '&lt;script&gt;') !== false);
t('পপআপে onerror ট্যাগ হয়নি', strpos($html, '<img src=x') === false);
t('data-ann-delay আছে', strpos($html, 'data-ann-delay="5"') !== false);
t('data-ann-reshow=session আছে', strpos($html, 'data-ann-reshow="session"') !== false);
t('বাটন লিংক আছে', strpos($html, 'class="ann-pop-btn"') !== false);
ob_start(); ann_render_bar($pdo); $bhtml = ob_get_clean();
t('বার রেন্ডার হয়', strpos($bhtml, 'id="annBar"') !== false && strpos($bhtml, 'annBarClose') !== false);
ann_save($pdo, ['message'=>'[T] বার','closable'=>0] + $base, (int)$id1);
ob_start(); ann_render_bar($pdo); $bhtml = ob_get_clean();
t('closable=0 হলে ✕ নেই', strpos($bhtml, 'annBarClose') === false);
echo "\n=== লেখা যেমন দেওয়া হবে তেমনই দেখায় (বাংলা অঙ্কও; পরিষ্কার দেখানো ফন্টের কাজ) ===\n";
[$bd, ] = ann_save($pdo, ['message'=>'[T] ঈদ অফার: ১০০০৳+ অর্ডারে ফ্রি','link_url'=>'shop.php?section=12','closable'=>1] + $base);
ob_start(); ann_render_bar($pdo); $dh = ob_get_clean();
t('বারে বাংলা অঙ্ক যেমন লেখা তেমনই (১০০০৳+)', strpos($dh, '১০০০৳+') !== false);
t('বারে অঙ্ক বদলে ইংরেজি করা হয়নি', strpos($dh, '1000') === false);
t('লিংকের URL অক্ষত (section=12)', strpos($dh, 'shop.php?section=12') !== false);
t('ডাটাবেসে সংরক্ষিত লেখাও বাংলা অঙ্কেই', mb_strpos(ann_get($pdo, (int)$bd)['message'], '১০০০', 0, 'UTF-8') !== false);
[$bd2, ] = ann_save($pdo, ['message'=>'[T] 1000+ order, free delivery'] + $base);
ob_start(); ann_render_bar($pdo); $dh2 = ob_get_clean();
t('ইংরেজি অঙ্কে লিখলে ইংরেজি অঙ্কেই দেখায়', strpos($dh2, '1000+ order') !== false);
[$pd, ] = ann_save($pdo, ['kind'=>'popup','title'=>'[T] ১০% ছাড়!','message'=>'৫০০৳ এর বেশি কিনুন','btn_label'=>'৩০ দিন','link_url'=>'shop.php','reshow'=>'never','active'=>1] + $base);
ob_start(); ann_render_popup($pdo); $ph = ob_get_clean();
t('পপআপের শিরোনাম/লেখা/বাটনে বাংলা অঙ্ক যেমন লেখা', strpos($ph, '১০% ছাড়') !== false && strpos($ph, '৫০০৳') !== false && strpos($ph, '৩০ দিন') !== false);

t('ann_unlink_image পাথ-গার্ড (বাইরের পাথ উপেক্ষা)', (function () { $f = sys_get_temp_dir() . '/ann_guard_test.txt'; file_put_contents($f, 'x'); ann_unlink_image($f); ann_unlink_image('../config.php'); $ok = is_file($f) && is_file(__DIR__ . '/../includes/config.php'); @unlink($f); return $ok; })());

echo "\n=== মোছা ===\n";
[$okDel, ] = ann_delete($pdo, (int)$id2);
t('মোছা হলো', $okDel && ann_get($pdo, (int)$id2) === null);
t('অজানা id মোছা ব্যর্থ', ann_delete($pdo, 99999999)[0] === false);

$pdo->exec("DELETE FROM announcements WHERE message LIKE '[T]%' OR title LIKE '[T]%'");
t('টেস্ট ডেটা পরিষ্কার', (int)$pdo->query("SELECT COUNT(*) FROM announcements WHERE message LIKE '[T]%' OR title LIKE '[T]%'")->fetchColumn() === 0);
$snapRealAfter = $pdo->query("SELECT id, active, kind, IFNULL(message,''), IFNULL(title,'') FROM announcements WHERE message NOT LIKE '[T]%' AND IFNULL(title,'') NOT LIKE '[T]%' ORDER BY id")->fetchAll(PDO::FETCH_NUM);
t('টেস্ট শেষে আসল (টেস্ট-নয় এমন) ঘোষণাগুলো অপরিবর্তিত — চালু/বন্ধ, লেখা সব', $snapReal == $snapRealAfter);

echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
