<?php
// tools/test_login_security.php — অ্যাডমিন লগইন সুরক্ষার লজিক টেস্ট
// php tools/test_login_security.php
// শুধু টেস্ট-আইপি (203.0.113.x / 198.51.100.x) ও ইউজারনেম ("[t]…") ছোঁয়; শেষে ls_* সেটিংস ও রেকর্ড আগের অবস্থায় ফেরায়
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/login_security.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}

$snapSettings = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'ls\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$snapAttempts = (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip NOT LIKE '203.0.113.%' AND ip NOT LIKE '198.51.100.%'")->fetchColumn();
$snapLocks    = (int)$pdo->query("SELECT COUNT(*) FROM login_locks WHERE subject NOT LIKE '203.0.113.%' AND subject NOT LIKE '198.51.100.%' AND subject NOT LIKE '[t]%'")->fetchColumn();
$clean = function () use ($pdo) {
  $pdo->exec("DELETE FROM login_attempts WHERE ip LIKE '203.0.113.%' OR ip LIKE '198.51.100.%' OR username LIKE '[t]%'");
  $pdo->exec("DELETE FROM login_locks WHERE subject LIKE '203.0.113.%' OR subject LIKE '198.51.100.%' OR subject LIKE '[t]%'");
};
$clean();

$base = ['enabled'=>1,'ip_lock_enabled'=>1,'ip_max'=>5,'ip_minutes'=>15,'user_lock_enabled'=>0,'user_max'=>3,'user_minutes'=>10,
  'window_minutes'=>15,'slow_after'=>4,'slow_seconds'=>3,'ip_source'=>'remote_addr','whitelist'=>'','show_remaining'=>1,'retention_days'=>30];
$T0 = 1900000000;

echo "\n=== মাইগ্রেশন ===\n";
t('login_attempts টেবিল আছে', (bool)$pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchColumn());
t('login_locks টেবিল আছে', (bool)$pdo->query("SHOW TABLES LIKE 'login_locks'")->fetchColumn());
t('schema_v11_at চিহ্ন আছে', (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v11_at'")->fetchColumn());
t('আবার চালালে ত্রুটি নেই', ensure_estore_schema_v11($pdo, true) === true);

echo "\n=== ডিফল্ট ও সেটিংস যাচাই ===\n";
$d = ls_defaults();
t('ডিফল্ট: চালু, আইপি-লক ৫/১৫, ইউজার-লক বন্ধ', $d['enabled'] === 1 && $d['ip_lock_enabled'] === 1 && $d['ip_max'] === 5 && $d['ip_minutes'] === 15 && $d['user_lock_enabled'] === 0);
$in = ['enabled'=>'1','ip_lock_enabled'=>'1','ip_max'=>'7','ip_minutes'=>'30','user_max'=>'10','user_minutes'=>'20','window_minutes'=>'30','slow_after'=>'0','slow_seconds'=>'2','retention_days'=>'60','ip_source'=>'xff_last','whitelist'=>"203.0.113.5\n203.0.113.5, 2001:db8::1"];
[$c, $e] = ls_validate($in);
t('ঠিক ইনপুট গ্রহণ', $c !== null && !$e);
t('অচিহ্নিত চেকবক্স = বন্ধ (user_lock, show_remaining)', $c['user_lock_enabled'] === 0 && $c['show_remaining'] === 0);
t('সাদা তালিকা: ডুপ্লিকেট বাদ, IPv6 ঠিক', $c['whitelist'] === "203.0.113.5\n2001:db8::1");
foreach (['ip_max'=>['0','101','abc','-1','','1.5',['1']], 'ip_minutes'=>['0','1441'], 'user_max'=>['0','201'], 'window_minutes'=>['0','1441'], 'slow_after'=>['-1','501'], 'slow_seconds'=>['0','11'], 'retention_days'=>['0','366']] as $k => $bad) {
  foreach ($bad as $b) { [$c2, $e2] = ls_validate([$k=>$b] + $in); t("অবৈধ $k=" . json_encode($b) . ' বাতিল', $c2 === null && count($e2) >= 1); }
}
[$c2, $e2] = ls_validate(['ip_source'=>'bogus'] + $in);   t('অবৈধ ip_source বাতিল', $c2 === null);
[$c2, $e2] = ls_validate(['whitelist'=>"203.0.113.5\nnot-an-ip"] + $in); t('সাদা তালিকায় অবৈধ আইপি বাতিল', $c2 === null && strpos(implode(' ', $e2), 'not-an-ip') !== false);
[$c2, $e2] = ls_validate(['whitelist'=>"<script>"] + $in); t('সাদা তালিকায় HTML বাতিল', $c2 === null);
$many = implode("\n", array_map(fn($i) => "203.0.113." . ($i % 250 + 1) . '/' . $i, range(1, 3)));
[$c2, $e2] = ls_validate(['whitelist'=>$many] + $in); t('আইপি/মাস্ক ("1.2.3.4/24") গ্রহণ নয়', $c2 === null);
[$c2, ] = ls_validate(['slow_after'=>'0'] + $in); t('slow_after=0 (বন্ধ) ঠিক', $c2 !== null && $c2['slow_after'] === 0);
[$c2, $e2] = ls_validate(['ip_max'=>['x']] + $in); t('অ্যারে ইনপুটে ক্র্যাশ নয়', $c2 === null);
ls_save($pdo, ls_validate($in)[0]);
$ld = ls_load($pdo);
t('সংরক্ষণ → লোড একই (ip_max=7, source=xff_last, slow_after=0)', $ld['ip_max'] === 7 && $ld['ip_source'] === 'xff_last' && $ld['slow_after'] === 0 && $ld['user_lock_enabled'] === 0);
$pdo->exec("DELETE FROM settings WHERE `key` LIKE 'ls\\_%'");
$ld = ls_load($pdo);
t('সেটিংস না থাকলে ডিফল্ট', $ld == ls_defaults());
$pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('ls_ip_source','junk') ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute();
t('ডাটাবেসে জঞ্জাল ip_source → auto (নিরাপদ ডিফল্ট)', ls_load($pdo)['ip_source'] === 'auto');
t('ডিফল্ট ip_source = auto', ls_defaults()['ip_source'] === 'auto');
$pdo->exec("DELETE FROM settings WHERE `key` LIKE 'ls\\_%'");

echo "\n=== আইপি শনাক্তকরণ ===\n";
$S = ['REMOTE_ADDR'=>'10.0.0.1', 'HTTP_X_FORWARDED_FOR'=>'1.1.1.1, 2.2.2.2, 203.0.113.9', 'HTTP_X_REAL_IP'=>'203.0.113.7', 'HTTP_CF_CONNECTING_IP'=>'198.51.100.4'];
t('remote_addr → REMOTE_ADDR', ls_client_ip('remote_addr', $S) === '10.0.0.1');
t('auto: সরাসরি আইপি ব্যক্তিগত (10.0.0.1, প্রক্সি) + XFF → XFF-এর শেষ আইপি', ls_client_ip('auto', $S) === '203.0.113.9');
t('auto: সরাসরি আইপি পাবলিক (প্রক্সি নয়) + জাল XFF → হেডার উপেক্ষা, সরাসরি আইপি', ls_client_ip('auto', ['REMOTE_ADDR'=>'198.51.100.7','HTTP_X_FORWARDED_FOR'=>'6.6.6.6']) === '198.51.100.7');
t('auto: ব্যক্তিগত আইপি কিন্তু XFF নেই (লোকাল) → সরাসরি আইপি', ls_client_ip('auto', ['REMOTE_ADDR'=>'127.0.0.1']) === '127.0.0.1');
t('auto: 192.168.x ও 172.16.x ও লুপব্যাক ::1 ব্যক্তিগত ধরা', ls_is_private_ip('192.168.1.5') && ls_is_private_ip('172.20.0.1') && ls_is_private_ip('::1') && ls_is_private_ip('127.0.0.1') && !ls_is_private_ip('198.51.100.7') && !ls_is_private_ip('8.8.8.8'));
t('auto: প্রক্সির পেছনে হলেও XFF জঞ্জাল হলে সরাসরি আইপি', ls_client_ip('auto', ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_FOR'=>'<script>']) === '10.0.0.1');
t('auto: সরাসরি আইপিও না থাকলে 0.0.0.0', ls_client_ip('auto', []) === '0.0.0.0');
t('xff_last → শেষের আইপি (প্রক্সির দেখা)', ls_client_ip('xff_last', $S) === '203.0.113.9');
t('xff_first → প্রথম আইপি', ls_client_ip('xff_first', $S) === '1.1.1.1');
t('x_real_ip', ls_client_ip('x_real_ip', $S) === '203.0.113.7');
t('cf_connecting_ip', ls_client_ip('cf_connecting_ip', $S) === '198.51.100.4');
t('জাল XFF-এ আক্রমণকারীর বসানো প্রথম আইপি xff_last-এ প্রভাব ফেলে না', ls_client_ip('xff_last', ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_FOR'=>'6.6.6.6, 203.0.113.9']) === '203.0.113.9');
t('হেডার না থাকলে REMOTE_ADDR-এ ফিরে যায়', ls_client_ip('xff_last', ['REMOTE_ADDR'=>'10.0.0.1']) === '10.0.0.1');
t('জঞ্জাল হেডার ("<script>") উপেক্ষা', ls_client_ip('x_real_ip', ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_REAL_IP'=>'<script>alert(1)</script>']) === '10.0.0.1');
t('খালি XFF উপেক্ষা', ls_client_ip('xff_last', ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_FOR'=>' , ,']) === '10.0.0.1');
t('IPv6 ঠিক', ls_client_ip('remote_addr', ['REMOTE_ADDR'=>'2001:db8::5']) === '2001:db8::5');
t('কিছুই না থাকলে 0.0.0.0 (ক্র্যাশ নয়)', ls_client_ip('remote_addr', []) === '0.0.0.0');
t('অজানা উৎস নাম → REMOTE_ADDR', ls_client_ip('whatever', $S) === '10.0.0.1');
t('অ্যারে হেডার ক্র্যাশ নয়', ls_client_ip('x_real_ip', ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_REAL_IP'=>['1']]) === '10.0.0.1');

echo "\n=== আইপি-লক (৫ বার, ১৫ মিনিট) ===\n";
$ip = '203.0.113.50'; $u = '[t]admin';
t('শুরুতে লক নেই', ls_active_lock($pdo, $ip, $u, $base, $T0) === null);
for ($i = 1; $i <= 4; $i++) {
  $r = ls_on_fail($pdo, $ip, $u, $base, $T0 + $i);
  t("ভুল #$i: লক নয়, বাকি " . (5 - $i) . 'টি', $r['locked'] === false && $r['remaining'] === 5 - $i);
}
$r = ls_on_fail($pdo, $ip, $u, $base, $T0 + 5);
t('৫ম ভুলে লক, সময় = +১৫ মিনিট', $r['locked'] === true && $r['by'] === 'ip' && $r['until'] === $T0 + 5 + 900 && $r['remaining'] === 0);
$lk = ls_active_lock($pdo, $ip, $u, $base, $T0 + 60);
t('লকের মধ্যে ls_active_lock সক্রিয় (ধরন ip)', $lk && $lk['by'] === 'ip' && $lk['until'] === $T0 + 905);
t('অন্য আইপি লক নয় (শুধু যে ভুল করেছে)', ls_active_lock($pdo, '203.0.113.51', $u, $base, $T0 + 60) === null);
t('অন্য ইউজারনেমে একই আইপি লক (আইপি-ভিত্তিক)', ls_active_lock($pdo, $ip, '[t]other', $base, $T0 + 60) !== null);
t('লক শেষ হওয়ার ঠিক পরে লক নেই', ls_active_lock($pdo, $ip, $u, $base, $T0 + 906) === null);
$r = ls_on_fail($pdo, $ip, $u, $base, $T0 + 1000);
t('লক শেষে আবার শুরু থেকে গোনা (১টি ভুলে সঙ্গে সঙ্গে আবার লক নয়, বাকি ৪)', $r['locked'] === false && $r['remaining'] === 4);
ls_on_success($pdo, $ip, $u, $T0 + 1010);
t('সফল লগইনে ব্যর্থ-গণনা শূন্য', ls_fail_count($pdo, 'ip', $ip, $base, $T0 + 1011) === 0);
$r = ls_on_fail($pdo, $ip, $u, $T0 + 1020 > 0 ? $base : $base, $T0 + 1020);
t('সফলের পর ভুল আবার ১ থেকে (বাকি ৪)', $r['remaining'] === 4);
$clean();

echo "\n=== সফল লগইনের প্রভাব (শুধু নিজের আইপি, প্রমাণ থাকে) ===\n";
$cu2 = ['user_lock_enabled'=>1, 'user_max'=>10] + $base;
ls_on_fail($pdo, '203.0.113.31', '[t]shared', $cu2, $T0 + 1);
ls_on_fail($pdo, '203.0.113.31', '[t]shared', $cu2, $T0 + 2);
ls_on_fail($pdo, '203.0.113.32', '[t]shared', $cu2, $T0 + 3);
ls_on_success($pdo, '203.0.113.32', '[t]shared', $T0 + 4);
t('সফল লগইনে নিজের আইপির (.32) গণনা শূন্য', ls_fail_count($pdo, 'ip', '203.0.113.32', $cu2, $T0 + 5) === 0);
t('অন্য আইপির (.31 — আক্রমণকারী) ভুল গণনা **অক্ষত** (২টি)', ls_fail_count($pdo, 'ip', '203.0.113.31', $cu2, $T0 + 5) === 2);
t('ইউজারনেমের গণনায় আক্রমণকারীর ২টি থাকে (আসল অ্যাডমিনের সফল লগইনে মোছে না)', ls_fail_count($pdo, 'user', '[t]shared', $cu2, $T0 + 5) === 2);
t('নিজের আইপির ভুল মোছা হয়নি — failx হিসেবে লগে আছে', (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip='203.0.113.32' AND result='failx'")->fetchColumn() === 1);
for ($i = 0; $i < 3; $i++) $r = ls_on_fail($pdo, '203.0.113.31', '[t]shared', $base, $T0 + 10 + $i);
t('আক্রমণকারী আইপি আগের ২টি + ৩ = ৫ম ভুলে লক (সফল লগইনে রিসেট হয়নি)', $r['locked'] === true && $r['by'] === 'ip');
ls_unlock($pdo, 'ip', '203.0.113.31');
t('হাতে খোলার পর ভুলগুলো মোছে না, failx হয়ে লগে থাকে', (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip='203.0.113.31' AND result='failx'")->fetchColumn() === 5 && (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip='203.0.113.31' AND result='fail'")->fetchColumn() === 0);
$clean();

echo "\n=== গণনার সময়সীমা ===\n";
$ip = '203.0.113.60';
for ($i = 0; $i < 4; $i++) ls_on_fail($pdo, $ip, '[t]w', $base, $T0 + $i);
$r = ls_on_fail($pdo, $ip, '[t]w', $base, $T0 + 16 * 60);
t('১৬ মিনিট পরের ভুল আগের ৪টির সাথে যোগ হয় না (বাকি ৪)', $r['locked'] === false && $r['remaining'] === 4);
$clean();

echo "\n=== সাদা তালিকা ===\n";
$cw = ['whitelist'=>"203.0.113.70\n203.0.113.71"] + $base;
for ($i = 0; $i < 10; $i++) $r = ls_on_fail($pdo, '203.0.113.70', '[t]x', $cw, $T0 + $i);
t('সাদা তালিকার আইপি ১০ বার ভুলেও লক নয়', $r['locked'] === false && ls_active_lock($pdo, '203.0.113.70', '[t]x', $cw, $T0 + 20) === null);
t('সাদা তালিকার আইপির ভুল নথিভুক্তও হয় না', (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip='203.0.113.70'")->fetchColumn() === 0);
for ($i = 0; $i < 5; $i++) ls_on_fail($pdo, '203.0.113.72', '[t]x', $cw, $T0 + $i);
t('সাদা তালিকার বাইরের আইপি লক হয়', ls_active_lock($pdo, '203.0.113.72', '[t]x', $cw, $T0 + 10) !== null);
t('ls_is_whitelisted', ls_is_whitelisted('203.0.113.71', $cw) && !ls_is_whitelisted('203.0.113.72', $cw));
$clean();

echo "\n=== মাস্টার সুইচ ও আইপি-লক বন্ধ ===\n";
$coff = ['enabled'=>0] + $base;
for ($i = 0; $i < 10; $i++) ls_on_fail($pdo, '203.0.113.80', '[t]m', $coff, $T0 + $i);
t('সুরক্ষা বন্ধ হলে ১০ ভুলেও লক নয়', ls_active_lock($pdo, '203.0.113.80', '[t]m', $coff, $T0 + 20) === null);
$cnoip = ['ip_lock_enabled'=>0] + $base;
for ($i = 0; $i < 10; $i++) ls_on_fail($pdo, '203.0.113.81', '[t]n', $cnoip, $T0 + $i);
t('আইপি-লক বন্ধ (ইউজার-লকও বন্ধ) হলে লক নয়', ls_active_lock($pdo, '203.0.113.81', '[t]n', $cnoip, $T0 + 20) === null);
$clean();

echo "\n=== ইউজারনেম-লক (৩ বার, ১০ মিনিট) ===\n";
$cu = ['user_lock_enabled'=>1, 'ip_lock_enabled'=>0] + $base;
ls_on_fail($pdo, '203.0.113.90', '[t]victim', $cu, $T0 + 1);
ls_on_fail($pdo, '203.0.113.91', '[t]victim', $cu, $T0 + 2);
$r = ls_on_fail($pdo, '203.0.113.92', '[t]Victim ', $cu, $T0 + 3);
t('৩টি ভিন্ন আইপি থেকে ভুলে ইউজারনেম লক (বড়-ছোট হাত ও ফাঁকা উপেক্ষা)', $r['locked'] === true && $r['by'] === 'user' && $r['until'] === $T0 + 3 + 600);
t('একেবারে নতুন আইপি থেকেও ওই ইউজারনেম লক', ls_active_lock($pdo, '203.0.113.99', '[t]victim', $cu, $T0 + 5) !== null);
t('অন্য ইউজারনেম (একই আইপি) লক নয়', ls_active_lock($pdo, '203.0.113.90', '[t]someone', $cu, $T0 + 5) === null);
t('১০ মিনিট পর ইউজারনেম লক খোলে', ls_active_lock($pdo, '203.0.113.99', '[t]victim', $cu, $T0 + 3 + 601) === null);
$cb = ['user_lock_enabled'=>1] + $base;
$clean();
for ($i = 0; $i < 3; $i++) $r = ls_on_fail($pdo, '203.0.113.95', '[t]both', $cb, $T0 + $i);
t('দুটি মোড চালু: ইউজারনেম আগে সীমা ছোঁয় (৩ < ৫) → user লক', $r['locked'] === true && $r['by'] === 'user');
t('বাকি-সংখ্যা দুই মোডের ছোটটি (আইপি ৫-৪=১, ইউজার ৩-১=২ → ১)', (function () use ($pdo, $cb, $T0) { $GLOBALS['pdo']->exec("DELETE FROM login_attempts WHERE ip LIKE '203.0.113.%'"); $GLOBALS['pdo']->exec("DELETE FROM login_locks WHERE subject LIKE '203.0.113.%' OR subject LIKE '[t]%'"); for ($i = 0; $i < 3; $i++) $r = ls_on_fail($pdo, '203.0.113.96', '[t]rem' . $i, $cb, $T0 + $i); return $r['remaining'] === 2; })());
$clean();

echo "\n=== ধীর করা ===\n";
for ($i = 0; $i < 3; $i++) ls_on_fail($pdo, '203.0.113.10' . $i, '[t]slow', $base, $T0 + $i);
t('৩ ভুলে (সীমা ৪) ধীর নয়', ls_slow_seconds($pdo, '[t]slow', $base, $T0 + 5) === 0);
ls_on_fail($pdo, '203.0.113.104', '[t]slow', $base, $T0 + 6);
t('৪ ভুলে (বিভিন্ন আইপি মিলিয়ে) ৩ সেকেন্ড ধীর', ls_slow_seconds($pdo, '[t]slow', $base, $T0 + 7) === 3);
t('ধীর করা বন্ধ (slow_after=0) হলে ০', ls_slow_seconds($pdo, '[t]slow', ['slow_after'=>0] + $base, $T0 + 7) === 0);
t('সুরক্ষা বন্ধ হলে ০', ls_slow_seconds($pdo, '[t]slow', ['enabled'=>0] + $base, $T0 + 7) === 0);
t('অন্য ইউজারনেমে ০', ls_slow_seconds($pdo, '[t]fast', $base, $T0 + 7) === 0);
t('সময়সীমা পেরোলে আবার ০', ls_slow_seconds($pdo, '[t]slow', $base, $T0 + 7 + 16 * 60) === 0);
$clean();

echo "\n=== লক খোলা ===\n";
for ($i = 0; $i < 5; $i++) ls_on_fail($pdo, '203.0.113.110', '[t]u1', $cu = ['user_lock_enabled'=>1,'user_max'=>5] + $base, $T0 + $i);
t('লক হয়েছে (আইপি + ইউজারনেম দুটোই)', count(array_filter(ls_locks($pdo, $T0 + 10), fn($l) => in_array($l['subject'], ['203.0.113.110', '[t]u1'], true))) === 2);
t('ls_unlock(ip) খোলে', ls_unlock($pdo, 'ip', '203.0.113.110') && ls_active_lock($pdo, '203.0.113.110', '[t]zz', $cu, $T0 + 10) === null);
t('ইউজারনেম লক তখনও বহাল (আলাদা)', ls_active_lock($pdo, '203.0.113.199', '[t]u1', $cu, $T0 + 10) !== null);
t('ls_unlock(user) খোলে', ls_unlock($pdo, 'user', '[t]u1') && ls_active_lock($pdo, '203.0.113.199', '[t]u1', $cu, $T0 + 10) === null);
t('খোলার পর ব্যর্থ-গণনাও শূন্য', ls_fail_count($pdo, 'ip', '203.0.113.110', $base, $T0 + 11) === 0);
t('অবৈধ ধরন/খালি subject বাতিল', ls_unlock($pdo, 'bogus', 'x') === false && ls_unlock($pdo, 'ip', '') === false);
ls_on_fail($pdo, '203.0.113.111', '[t]a', ['ip_max'=>1] + $base, $T0);
t('ls_locks শুধু সক্রিয় লক দেখায়', count(ls_locks($pdo, $T0 + 10)) >= 1 && count(array_filter(ls_locks($pdo, $T0 + 5000), fn($l) => $l['subject'] === '203.0.113.111')) === 0);
$clean();

echo "\n=== রেকর্ড ও পরিষ্কার ===\n";
ls_record($pdo, '203.0.113.120', '[t]rec', 'blocked', $T0);
ls_record($pdo, '203.0.113.120', '[t]rec', 'weird', $T0 + 1);
$rows = $pdo->query("SELECT result FROM login_attempts WHERE ip='203.0.113.120' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
t('ফলাফল blocked রাখে, অজানা ফলাফল fail হয়ে যায়', $rows === ['blocked', 'fail']);
t('ls_recent সাম্প্রতিক চেষ্টা দেয়', count(ls_recent($pdo, 5)) >= 1);
$pdo->exec("INSERT INTO login_attempts(ip, username, result, created_at) VALUES('203.0.113.121','[t]old','fail','2000-01-01 00:00:00')");
$pdo->exec("INSERT INTO login_locks(kind, subject, locked_until, created_at) VALUES('ip','203.0.113.121','2000-01-01 01:00:00','2000-01-01 00:00:00')");
$n = ls_purge($pdo, 30);
t('ls_purge পুরোনো লগ ও পুরোনো লক মুছে', $n >= 2 && (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip='203.0.113.121'")->fetchColumn() === 0);
t('ls_purge নতুন রেকর্ড রাখে', (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip='203.0.113.120'")->fetchColumn() === 2);
t('ls_wait_text: ৯০ সেকেন্ডের কম → সেকেন্ড', ls_wait_text($T0 + 45, $T0) === '45 সেকেন্ড');
t('ls_wait_text: বেশি → মিনিট (ওপরের দিকে গোল)', ls_wait_text($T0 + 900, $T0) === '15 মিনিট' && ls_wait_text($T0 + 901, $T0) === '16 মিনিট');
t('ls_norm_user: ছোট হাত, ফাঁকা বাদ, ১০০ অক্ষর', ls_norm_user('  AdMiN ') === 'admin' && mb_strlen(ls_norm_user(str_repeat('অ', 300)), 'UTF-8') === 100 && ls_norm_user(['x']) === '');
$clean();

echo "\n=== ডাটাবেস সমস্যায় লগইন আটকায় না (fail-open) ===\n";
$bad = new PDO('mysql:host=127.0.0.1;dbname=information_schema;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
t('টেবিল না থাকলে ls_active_lock → লক নয় (ক্র্যাশ নয়)', ls_active_lock($bad, '203.0.113.130', 'x', $base, $T0) === null);
$r = ls_on_fail($bad, '203.0.113.130', 'x', $base, $T0);
t('টেবিল না থাকলে ls_on_fail → লক নয়, ক্র্যাশ নয়', $r['locked'] === false);
t('টেবিল না থাকলে ls_slow_seconds → ০', ls_slow_seconds($bad, 'x', $base, $T0) === 0);
t('টেবিল না থাকলে ls_load → ডিফল্ট', ls_load($bad) == ls_defaults());
t('টেবিল না থাকলে ls_locks/ls_recent → খালি', ls_locks($bad) === [] && ls_recent($bad) === []);

/* ---- আগের অবস্থা ফিরিয়ে দিই ---- */
$clean();
$pdo->exec("DELETE FROM settings WHERE `key` LIKE 'ls\\_%'");
$ins = $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?)");
foreach ($snapSettings as $k => $v) $ins->execute([$k, $v]);
t('টেস্ট শেষে ls_* সেটিংস আগের অবস্থায়', $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'ls\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR) == $snapSettings);
t('টেস্ট শেষে আসল (টেস্ট-নয় এমন) চেষ্টা/লক রেকর্ড অপরিবর্তিত', (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE ip NOT LIKE '203.0.113.%' AND ip NOT LIKE '198.51.100.%'")->fetchColumn() === $snapAttempts
  && (int)$pdo->query("SELECT COUNT(*) FROM login_locks WHERE subject NOT LIKE '203.0.113.%' AND subject NOT LIKE '198.51.100.%' AND subject NOT LIKE '[t]%'")->fetchColumn() === $snapLocks);

echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
