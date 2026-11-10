<?php
/**
 * tools/unlock_logins.php — জরুরি: লগইন লক খোলা / দেখা (শুধু CLI)
 *   php tools/unlock_logins.php --list
 *   php tools/unlock_logins.php --all
 *   php tools/unlock_logins.php --ip 203.0.113.10
 *   php tools/unlock_logins.php --user admin
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/login_security.php';
$pdo = get_pdo();
$a = $argv[1] ?? '--list';

if ($a === '--all') {
  echo ls_unlock_all($pdo) ? "সব লক খুলে দেওয়া হয়েছে।\n" : "ব্যর্থ।\n";
} elseif ($a === '--ip' && !empty($argv[2])) {
  echo ls_unlock($pdo, 'ip', $argv[2]) ? "আইপি {$argv[2]} খুলে দেওয়া হয়েছে।\n" : "ব্যর্থ।\n";
} elseif ($a === '--user' && !empty($argv[2])) {
  echo ls_unlock($pdo, 'user', ls_norm_user($argv[2])) ? "ইউজারনেম {$argv[2]} খুলে দেওয়া হয়েছে।\n" : "ব্যর্থ।\n";
} else {
  $locks = ls_locks($pdo);
  if (!$locks) { echo "এখন কোনো লক নেই।\n"; exit(0); }
  foreach ($locks as $l) echo ($l['kind'] === 'ip' ? 'আইপি' : 'ইউজারনেম') . "  {$l['subject']}  খুলবে: {$l['until_at']}\n";
}
