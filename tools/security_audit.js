/**
 * tools/security_audit.js
 * স্ট্যাটিক সিকিউরিটি অডিট — কোড পড়ে ঝুঁকির তালিকা তৈরি করে।
 *
 *   node tools/security_audit.js
 *
 * কোনো ফাইল পরিবর্তন করে না; শুধু রিপোর্ট দেয়।
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SKIP_DIRS = new Set(['uploads', 'database', 'tools', 'node_modules', '.git', 'vendor']);

/* ---------------- helpers ---------------- */
function walk(dir, out = []) {
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    if (ent.isDirectory()) {
      if (SKIP_DIRS.has(ent.name)) continue;
      walk(path.join(dir, ent.name), out);
    } else if (ent.isFile() && /\.(php|js|css|htaccess)$/i.test(ent.name)) {
      out.push(path.join(dir, ent.name));
    }
  }
  return out;
}

function rel(p) { return path.relative(ROOT, p).replace(/\\/g, '/'); }
function read(p) { return fs.readFileSync(p, 'utf8'); }
function countMatches(str, re) { const m = str.match(re); return m ? m.length : 0; }

/* ---------------- rules ---------------- */
const findings = [];
function add(severity, category, file, line, title, detail, fix) {
  findings.push({ severity, category, file, line, title, detail, fix });
}

const files = walk(ROOT);

/* ---- 1. POST handler ছাড়া CSRF check ---- */
for (const f of files.filter(f => f.endsWith('.php'))) {
  const src = read(f);
  const name = rel(f);
  const hasPost = /\$_SERVER\['REQUEST_METHOD'\]\s*===?\s*'POST'|isset\(\$_POST\[/.test(src);
  if (!hasPost) continue;

  // কোনো ফর্ম/মিউটেশন আছে?
  const hasMutation = /->(exec|prepare)\s*\(|move_uploaded_file|INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM/i.test(src);
  if (!hasMutation) continue;

  // CSRF প্রতিরক্ষা: helper ফাংশন, hash_equals, অথবা সরাসরি টোকেন তুলনা
  const hasCsrf = /csrf_verify|csrf_check/i.test(src)
               || /hash_equals\s*\([^)]*csrf/i.test(src)
               || /\$_POST\s*\[\s*['"]csrf['"]\s*\]/i.test(src)
               || /\$_SESSION\s*\[\s*['"]csrf['"]\s*\]/.test(src) && /!==|!=|hash_equals/.test(src);
  if (!hasCsrf) {
    const line = src.split('\n').findIndex(l => /\$_SERVER\['REQUEST_METHOD'\]/.test(l)) + 1;
    add('HIGH', 'CSRF', name, line || 1,
      'POST mutation ছাড়া CSRF যাচাই নেই',
      'ফর্ম/API থেকে আসা পরিবর্তনকারী অনুরোধে CSRF টোকেন যাচাই করা হচ্ছে না।',
      "প্রথম লাইনে যোগ করুন: if (!csrf_verify()) { http_response_code(419); exit('CSRF'); }");
  }
}

/* ---- 2. সেশন ছাড়া admin পেজ ---- */
const ADMIN_GUARD = /gate\s*\(|require_(admin|role|permission)|check_permission|can\s*\(/;
for (const f of files.filter(f => f.endsWith('.php') && rel(f).startsWith('admin/'))) {
  const name = rel(f);
  // পাবলিক/হেল্পার ফাইল — এদের gate() থাকা উচিত নয়
  if (/\/(header|footer|auth|auth_functions|api_product_search|login|logout|permission_labels)\.php$/.test(name)) continue;
  const src = read(f);
  if (!ADMIN_GUARD.test(src)) {
    add('HIGH', 'AUTH', name, 1,
      'অ্যাডমিন গার্ড ছাড়া পেজ',
      'gate()/can() পাওয়া যায়নি — এই ফাইল সরাসরি খুললে অনুমতিহীন অ্যাক্সেস হতে পারে।',
      "শুরুর দিকে যোগ করুন: gate('manage_xyz'); বা can('...') চেক।");
  }
}

/* ---- 3. সরাসরি $_GET/$_POST ইন্টারপোলেশন কুয়েরিতে ---- */
const INTERP = /(?:query|exec|prepare)\s*\(\s*["'][^"']*\$_(GET|POST|REQUEST|COOKIE)/;
for (const f of files.filter(f => f.endsWith('.php'))) {
  const src = read(f);
  const lines = src.split('\n');
  lines.forEach((l, i) => {
    if (INTERP.test(l)) {
      add('CRITICAL', 'SQLi', rel(f), i + 1,
        'ইউজার ইনপুট সরাসরি SQL-এ',
        l.trim().slice(0, 120),
        'prepared statement ব্যবহার করুন: $pdo->prepare("... WHERE id=?")->execute([$id]);');
    }
  });
}

/* ---- 4. echo of raw request data (XSS) ---- */
const RAW_ECHO = /echo\s+\$_?(GET|POST|REQUEST|COOKIE)\s*\[|<\?=\s*\$_?(GET|POST|REQUEST|COOKIE)\s*\[/;
for (const f of files.filter(f => f.endsWith('.php'))) {
  const src = read(f);
  src.split('\n').forEach((l, i) => {
    if (RAW_ECHO.test(l)) {
      add('HIGH', 'XSS', rel(f), i + 1,
        'ইউজার ইনপুট escape ছাড়া আউটপুট',
        l.trim().slice(0, 120),
        'h() ফাংশন দিয়ে escape করুন: <?= h($_GET["x"]) ?>');
    }
  });
}

/* ---- 5. ফাইল আপলোডে টাইপ/সাইজ যাচাই ---- */
for (const f of files.filter(f => f.endsWith('.php'))) {
  const src = read(f);
  if (!/move_uploaded_file/.test(src)) continue;
  const name = rel(f);
  const checks = {
    getimagesize: /getimagesize/.test(src),
    extWhitelist: /in_array\(\s*\$ext|allowed|IMAGETYPE_/.test(src),
    sizeLimit: /\$f\['size'\]|filesize|maxBytes|\* 1024 \* 1024/.test(src),
  };
  const missing = Object.entries(checks).filter(([, v]) => !v).map(([k]) => k);
  if (missing.length) {
    add('MEDIUM', 'UPLOAD', name, 1,
      'আপলোডে যাচাই অসম্পূর্ণ',
      'পাওয়া যায়নি: ' + missing.join(', '),
      'getimagesize() + এক্সটেনশন হোয়াইটলিস্ট + সাইজ লিমিট — তিনটাই রাখুন।');
  }
}

/* ---- 6. পাসওয়ার্ড হ্যাশিং ---- */
for (const f of files.filter(f => f.endsWith('.php'))) {
  const src = read(f);
  if (/md5\s*\(\s*\$(pass|password)/i.test(src) || /sha1\s*\(\s*\$(pass|password)/i.test(src)) {
    add('CRITICAL', 'PASSWORD', rel(f), 1,
      'দুর্বল পাসওয়ার্ড হ্যাশিং',
      'md5/sha1 দিয়ে পাসওয়ার্ড হ্যাশ করা হচ্ছে।',
      'password_hash($p, PASSWORD_DEFAULT) এবং password_verify() ব্যবহার করুন।');
  }
}

/* ---- 7. hardcoded credentials ---- */
const SECRET = /(?:password|passwd|pass|secret|api_?key|token)\s*[=:]\s*['"][^'"$\s]{6,}['"]/i;
for (const f of files.filter(f => f.endsWith('.php'))) {
  const src = read(f);
  src.split('\n').forEach((l, i) => {
    if (SECRET.test(l) && !/\$|password_verify|password_hash|PASSWORD_|placeholder|example/i.test(l.replace(/^[^=:]*/, ''))) {
      if (/define\(\s*['"]DB_PASS['"]/.test(l)) return; // config এ DB pass থাকবেই
      add('MEDIUM', 'SECRET', rel(f), i + 1,
        'কোডে হার্ডকোডেড গোপন মান',
        l.trim().slice(0, 100),
        'পরিবেশ ভেরিয়েবল বা soiled config ফাইলে সরান; git থেকে সরান।');
    }
  });
}

/* ---- 8. error_reporting / debug leak ---- */
for (const f of files.filter(f => f.endsWith('.php'))) {
  const src = read(f);
  /* শুধু তখনই ধরবে যখন display_errors unconditional ভাবে চালু হয়।
     APP_DEBUG ? '1' : '0' এর মতো conditional guard থাকলে leak নয়। */
  const lines = src.split(/\r?\n/);
  let badLine = -1;
  for (let i = 0; i < lines.length; i++) {
    const l = lines[i];
    if (!/display_errors\s*[,=]\s*(1|true|'1'|On)/i.test(l)) continue;
    if (/\?|APP_DEBUG|getenv|env\(|if\s*\(/i.test(l)) continue; // conditional guard
    badLine = i + 1; break;
  }
  if (badLine > 0) {
    add('LOW', 'DEBUG', rel(f), badLine,
      'ডিবাগ আউটপুট চালু',
      'প্রোডাকশনে error দেখালে পাথ/কুয়েরি ফাঁস হতে পারে।',
      'প্রোডাকশনে display_errors=Off রাখুন।');
  }
}

/* ---- 9. root .htaccess ---- */
const htaccess = path.join(ROOT, '.htaccess');
if (!fs.existsSync(htaccess)) {
  add('MEDIUM', 'CONFIG', '.htaccess', 1,
    'root .htaccess নেই',
    'includes/, tools/, database/ ফোল্ডার ওয়েব থেকে সরাসরি খোলা যেতে পারে।',
    'root .htaccess-এ blocks করুন: RewriteRule ^(includes|tools|database)/ - [F,L]');
}

/* ---- 10. uploads .htaccess ---- */
const upHt = path.join(ROOT, 'uploads', '.htaccess');
if (!fs.existsSync(upHt)) {
  add('HIGH', 'CONFIG', 'uploads/.htaccess', 1,
    'uploads ফোল্ডারে PHP execution বন্ধ নয়',
    'আপলোড করা .php ফাইল চলতে পারলে RCE সম্ভব।',
    'uploads/.htaccess-এ php_flag engine off ও FilesMatch deny দিন।');
}

/* ---- 11. SQL dump ওয়েব-অ্যাক্সেসযোগ্য ---- */
for (const f of files.filter(f => f.endsWith('.sql'))) {
  add('HIGH', 'EXPOSURE', rel(f), 1,
    'SQL ডাম্প প্রজেক্ট ফোল্ডারে',
    'ডাম্পে সব ডেটা/হ্যাশ আছে; ওয়েবে সerved হলে catastrophe।',
    'প্রজেক্টের বাইরে রাখুন, অথবা .htaccess-এ *.sql deny করুন।');
}

/* ---- 12. session cookie flags ---- */
const cfg = path.join(ROOT, 'includes', 'config.php');
if (fs.existsSync(cfg)) {
  const src = read(cfg);
  if (/session_start\s*\(\s*\)/.test(src) && !/session_set_cookie_params/.test(src)) {
    add('MEDIUM', 'SESSION', 'includes/config.php', 1,
      'সেশন কুকিতে HttpOnly/Secure/SameSite নেই',
      'XSS হলে সেশন কুকি চুরি হতে পারে।',
      'session_set_cookie_params(["httponly"=>true,"samesite"=>"Lax","secure"=>!empty($_SERVER["HTTPS"])]);');
  }
}

/* ---------------- report ---------------- */
const SEV_ORDER = { CRITICAL: 0, HIGH: 1, MEDIUM: 2, LOW: 3 };
const COLORS = {
  CRITICAL: '\x1b[41;97m', HIGH: '\x1b[31m', MEDIUM: '\x1b[33m', LOW: '\x1b[36m', RESET: '\x1b[0m',
};

findings.sort((a, b) => SEV_ORDER[a.severity] - SEV_ORDER[b.severity] || a.file.localeCompare(b.file));

console.log('\n========================================');
console.log('  SECURITY AUDIT — ' + new Date().toISOString().slice(0, 10));
console.log('  Scanned: ' + files.length + ' files');
console.log('========================================\n');

const bySev = { CRITICAL: [], HIGH: [], MEDIUM: [], LOW: [] };
for (const f of findings) bySev[f.severity].push(f);

for (const sev of ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW']) {
  const list = bySev[sev];
  if (!list.length) continue;
  console.log(`${COLORS[sev]} ${sev} (${list.length}) ${COLORS.RESET}`);
  for (const f of list) {
    console.log(`  ${f.file}:${f.line}`);
    console.log(`    ${f.title}`);
    console.log(`    → ${f.detail}`);
    console.log(`    FIX: ${f.fix}\n`);
  }
}

const total = findings.length;
console.log('========================================');
console.log(`  Total: ${total}  ` +
  `(C:${bySev.CRITICAL.length} H:${bySev.HIGH.length} M:${bySev.MEDIUM.length} L:${bySev.LOW.length})`);
console.log('========================================\n');

/* JSON out */
fs.writeFileSync(path.join(__dirname, 'security_audit_report.json'),
  JSON.stringify({ generated: new Date().toISOString(), total, bySeverity: { critical: bySev.CRITICAL.length, high: bySev.HIGH.length, medium: bySev.MEDIUM.length, low: bySev.LOW.length }, findings }, null, 2));

process.exit(bySev.CRITICAL.length > 0 ? 2 : (bySev.HIGH.length > 0 ? 1 : 0));
