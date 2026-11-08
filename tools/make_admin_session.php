<?php
/**
 * tools/make_admin_session.php — টেস্টের জন্য অস্থায়ী Super Admin সেশন (পাসওয়ার্ড লাগে না)
 *   php tools/make_admin_session.php create    → PHPSESSID=... ছাপে
 *   php tools/make_admin_session.php destroy <sid>
 * শুধু CLI; টেস্ট শেষে destroy করুন।
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$cmd = $argv[1] ?? '';
$dir = 'C:/laragon/tmp';
if ($cmd === 'create') {
  $sid = bin2hex(random_bytes(13));
  $data = 'admin_id|i:1;admin_username|s:4:"test";admin_role_name|s:11:"Super Admin";admin_permissions|a:1:{i:0;s:14:"view_dashboard";}csrf|s:32:"' . str_repeat('a', 32) . '";__sid_rotated|i:' . time() . ';';
  file_put_contents("$dir/sess_$sid", $data);
  echo "PHPSESSID=$sid\n";
} elseif ($cmd === 'destroy' && !empty($argv[2]) && preg_match('/^[a-f0-9]{26}$/', $argv[2])) {
  @unlink("$dir/sess_{$argv[2]}");
  echo "destroyed\n";
} else {
  fwrite(STDERR, "usage: create | destroy <sid>\n");
  exit(1);
}
