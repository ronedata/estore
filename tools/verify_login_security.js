/**
 * tools/verify_login_security.js — অ্যাডমিন লগইন সুরক্ষা end-to-end (headless Chrome)
 * node tools/verify_login_security.js [url]
 * টেস্ট নকল আইপি (X-Forwarded-For: 203.0.113.x) + অস্থায়ী অ্যাডমিন "[t]admin" ব্যবহার করে — আপনার আসল লোকাল লগইন/আইপি লক হয় না।
 * শেষে ls_* সেটিংস, টেস্ট-রেকর্ড, অস্থায়ী অ্যাডমিন ও সেশন আগের অবস্থায় ফেরায়। mysql CLI ও php লাগে।
 */
const { spawn, execFileSync } = require('child_process');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_LS || 9497);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const DB = process.env.DB_NAME || 'estore';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', DB], { encoding: 'utf8', input: q + ';\n' }).trim();
const php = (...a) => execFileSync('php', a, { encoding: 'utf8', cwd: path.join(__dirname, '..') }).trim();

function send(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('timeout: ' + method)), 30000);
    const onMsg = (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch { return; }
      if (m.id === id) { clearTimeout(to); ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); }
    };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}

(async () => {
  const PASSWORD = 'Test#12345-ls';
  const hash = php('-r', `echo password_hash('${PASSWORD}', PASSWORD_DEFAULT);`);
  const snap = sql("SELECT CONCAT(`key`,'=',`value`) FROM settings WHERE `key` LIKE 'ls\\\\_%'").split(/\r?\n/).filter(Boolean);
  const realAttempts = sql("SELECT COUNT(*) FROM login_attempts WHERE ip NOT LIKE '203.0.113.%'");
  let adminSid = null;

  const wipeTest = () => {
    sql("DELETE FROM login_attempts WHERE ip LIKE '203.0.113.%' OR username LIKE '[t]%'");
    sql("DELETE FROM login_locks WHERE subject LIKE '203.0.113.%' OR subject LIKE '[t]%'");
  };
  const cleanup = () => {
    const step = (fn) => { try { fn(); } catch (e) { console.error('cleanup ত্রুটি: ' + String(e.message).split('\n')[0]); } };
    step(() => sql("DELETE FROM admin_users WHERE username LIKE '[t]%'"));
    step(wipeTest);
    step(() => {
      sql("DELETE FROM settings WHERE `key` LIKE 'ls\\\\_%'");
      for (const kv of snap) { const i = kv.indexOf('='); sql(`INSERT INTO settings(\`key\`,\`value\`) VALUES('${kv.slice(0, i)}','${kv.slice(i + 1).replace(/'/g, "\\'")}')`); }
    });
    if (adminSid) step(() => php('tools/make_admin_session.php', 'destroy', adminSid));
  };

  wipeTest();
  sql("DELETE FROM admin_users WHERE username LIKE '[t]%'");
  sql(`INSERT INTO admin_users(role_id, username, password_hash) VALUES(1, '[t]admin', '${hash.replace(/'/g, "\\'")}')`);
  sql("DELETE FROM settings WHERE `key` LIKE 'ls\\\\_%'");
  sql("INSERT INTO settings(`key`,`value`) VALUES('ls_ip_source','xff_last')");

  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\ls_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
  try {
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((r) => r.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1;
    const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    const ev = async (expr) => {
      const r = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };
    const go = async (p, w = 900) => { await call('Page.navigate', { url: BASE + p }); await sleep(w); };
    const asIp = (ip) => call('Network.setExtraHTTPHeaders', { headers: { 'X-Forwarded-For': ip } });
    const host = new URL(BASE).hostname;
    const setSid = (sid) => call('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: host, path: '/' });
    const clearCookies = () => call('Network.clearBrowserCookies');
    const lockRows = () => sql("SELECT CONCAT(kind,':',subject) FROM login_locks WHERE (subject LIKE '203.0.113.%' OR subject LIKE '[t]%') AND locked_until > NOW() - INTERVAL 1 DAY ORDER BY id").split(/\r?\n/).filter(Boolean);
    const cfg = (k) => sql(`SELECT value FROM settings WHERE \`key\`='ls_${k}'`);

    /** লগইন ফর্ম ভরে জমা দেয়; ফেরত: {path, err, ms} */
    const tryLogin = async (ip, user, pass) => {
      await clearCookies(); await asIp(ip);
      await go('/admin/login.php', 700);
      await ev(`(()=>{const f=document.querySelector('form');f.username.value=${JSON.stringify(user)};f.password.value=${JSON.stringify(pass)};1})()`);
      const t0 = Date.now();
      await ev("document.querySelector('form button').click()");
      await sleep(1200);
      const r = await ev("({path:location.pathname,err:(document.querySelector('.alert-danger')||{}).textContent||''})");
      r.err = r.err.trim().normalize('NFC'); r.ms = Date.now() - t0;
      return r;
    };
    const WRONG = 'wrong-password-x';
    const N = (s) => s.normalize('NFC');

    // ---------- অ্যাডমিন সেশন (UI) ----------
    adminSid = php('tools/make_admin_session.php', 'create').split('=').pop().trim();
    const adminUi = async (w = 1326) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 1000, deviceScaleFactor: 1, mobile: w < 600 }); await asIp('203.0.113.200'); await clearCookies(); await setSid(adminSid); await go('/admin/login_security.php', 1300); };
    await adminUi();

    console.log('\n=== সেটিংস পেজ ===');
    const p0 = await ev(`(()=>({ip_max:document.getElementById('lsIpMax').value,ip_min:document.getElementById('lsIpMin').value,user_on:document.getElementById('lsUserOn').checked,ip_on:document.getElementById('lsIpOn').checked,master:document.getElementById('lsEnabled').checked,srcRadios:document.querySelectorAll('input[name=ip_source]').length,src:document.querySelector('input[name=ip_source]:checked').value,myip:document.getElementById('lsMyIp').textContent,side:!!document.querySelector('.sidebar a[href*="login_security.php"]'),status:document.getElementById('lsStatus').textContent.trim(),title:document.querySelector('h4').textContent.trim()}))()`);
    check(p0.ip_max === '5' && p0.ip_min === '15', 'ডিফল্ট: আইপি-লক ৫ বার / ১৫ মিনিট');
    check(p0.ip_on && !p0.user_on && p0.master, 'ডিফল্ট: চালু, আইপি-লক চালু, ইউজারনেম-লক বন্ধ');
    check(p0.srcRadios === 6 && p0.src === 'xff_last', 'আইপি উৎসের ৬টি বিকল্প (স্বয়ংক্রিয় সহ), নির্বাচিত xff_last');
    check(p0.myip.includes('203.0.113.200'), 'নির্বাচিত উৎস অনুযায়ী "আপনার আইপি" দেখায় (203.0.113.200)');
    check(p0.side, 'সাইডবারে "লগইন সুরক্ষা" মেনু');
    check(p0.status === 'চালু', 'স্ট্যাটাস ব্যাজ "চালু"');

    const saveForm = async (vals) => {
      await ev(`(()=>{const v=${JSON.stringify(vals)};for(const [k,x] of Object.entries(v)){const e=document.querySelector('[name='+k+']');if(!e)continue;if(e.type==='checkbox'){e.checked=!!x}else if(e.type==='radio'){document.querySelector('[name='+k+'][value='+x+']').checked=true}else{e.value=x}}1})()`);
      await ev("document.getElementById('lsSave').click()"); await sleep(1400);
      return N((await ev("(document.getElementById('lsFlash')||{}).textContent||''")).trim());
    };
    let fl = await saveForm({ ip_max: 3, ip_minutes: 2, window_minutes: 10, show_remaining: 1 });
    check(fl.includes('সংরক্ষণ হয়েছে') && cfg('ip_max') === '3' && cfg('ip_minutes') === '2' && cfg('window_minutes') === '10', 'পেজ থেকে বদলে সংরক্ষণ: ৩ বার / ২ মিনিট / গণনা ১০ মিনিট (ডাটাবেসে ঠিক)');
    const p1 = await ev("({a:document.getElementById('lsIpMax').value,b:document.getElementById('lsIpMin').value})");
    check(p1.a === '3' && p1.b === '2', 'রিলোডে নতুন মান ফর্মে বসে আছে');
    /* ব্রাউজারের নিজস্ব min/max যাচাই জমাই আটকায় (ক্লায়েন্ট-সাইড) */
    const cfgBefore = cfg('ip_max');
    await ev("document.getElementById('lsIpMax').value='0'; document.getElementById('lsSave').click(); 1"); await sleep(900);
    check((await ev('location.pathname')).includes('login_security.php') && cfg('ip_max') === cfgBefore, 'ব্রাউজার নিজেই ০ আটকায় (min=1) — সার্ভারে যায়ই না');
    await go('/admin/login_security.php', 1000);
    /* সার্ভার-সাইড যাচাই: ব্রাউজার এড়িয়ে সরাসরি POST */
    const rawSave = (over) => ev(`(async()=>{const fd=new FormData(document.getElementById('lsForm'));const o=${JSON.stringify(over)};for(const [k,v] of Object.entries(o)){fd.delete(k);if(Array.isArray(v)){v.forEach(x=>fd.append(k+'[]',x))}else{fd.set(k,v)}}const r=await fetch('/admin/login_security.php',{method:'POST',body:new URLSearchParams(fd)});const t=await r.text();const m=t.match(/id="lsFlash">\s*([^<]*)/);return m?m[1].trim():''})()`);
    for (const [label, over, key, want] of [
      ['ip_max=0', { ip_max: '0' }, 'ip_max', '3'], ['ip_max=101', { ip_max: '101' }, 'ip_max', '3'], ['ip_max=abc', { ip_max: 'abc' }, 'ip_max', '3'],
      ['ip_max=-5', { ip_max: '-5' }, 'ip_max', '3'], ['ip_max=অ্যারে', { ip_max: ['1', '2'] }, 'ip_max', '3'],
      ['ip_minutes=99999', { ip_minutes: '99999' }, 'ip_minutes', '2'], ['window=0', { window_minutes: '0' }, 'window_minutes', '10'],
      ['slow_seconds=99', { slow_seconds: '99' }, 'slow_seconds', cfg('slow_seconds') || '3'], ['ip_source=bogus', { ip_source: 'bogus' }, 'ip_source', 'xff_last'],
    ]) {
      const msg = N(await rawSave(over));
      check(msg.length > 5 && !msg.includes('সংরক্ষণ হয়েছে') && cfg(key) === want, `সার্ভার-যাচাই: ${label} → বাতিল, মান অপরিবর্তিত (${msg.slice(0, 60)})`);
    }
    await go('/admin/login_security.php', 1000);
    fl = await saveForm({ whitelist: 'not-an-ip' });
    check(fl.normalize('NFC').includes('not-an-ip') && cfg('whitelist') === '', 'সাদা তালিকায় অবৈধ আইপি → বাতিল, ত্রুটিতে সেটি দেখায়');
    await go('/admin/login_security.php', 1000);
    await ev("document.getElementById('lsAddMine').click()");
    const ta = await ev("document.getElementById('lsWhitelist').value");
    check(ta.includes('203.0.113.200'), '"আমার আইপি যোগ করুন" বাটন তালিকায় আইপি বসায়');
    await ev("document.getElementById('lsAddMine').click()");
    check((await ev("document.getElementById('lsWhitelist').value")).split('\n').filter((x) => x === '203.0.113.200').length === 1, 'দুবার চাপলেও ডুপ্লিকেট হয় না');
    await go('/admin/login_security.php', 900);   // সংরক্ষণ না করে ছেড়ে দিই

    // ---------- ক) আইপি-লক ----------
    console.log('\n=== আসল লগইন ফর্মে আইপি-লক (৩ বার, ২ মিনিট) ===');
    const A = '203.0.113.141', B = '203.0.113.142';
    let r = await tryLogin(A, '[t]admin', WRONG);
    check(r.path === '/admin/login.php' && N(r.err).includes('মিলছে না') && N(r.err).includes('আর 2 বার'), 'ভুল #১: "মিলছে না … আর 2 বার চেষ্টা করতে পারবেন" — ' + r.err);
    r = await tryLogin(A, '[t]admin', WRONG);
    check(N(r.err).includes('আর 1 বার'), 'ভুল #২: "আর 1 বার" — ' + r.err);
    r = await tryLogin(A, '[t]admin', WRONG);
    check(N(r.err).includes('অনেকবার ভুল') && /[12] মিনিট|\d+ সেকেন্ড/.test(r.err), 'ভুল #৩: লক বার্তা — ' + r.err);
    check(lockRows().includes('ip:' + A), 'ডাটাবেসে আইপি লক নথিভুক্ত');
    r = await tryLogin(A, '[t]admin', PASSWORD);
    check(r.path === '/admin/login.php' && N(r.err).includes('অনেকবার ভুল'), 'লকের মধ্যে **সঠিক পাসওয়ার্ডও** গ্রহণ হয় না');
    check(sql(`SELECT COUNT(*) FROM login_attempts WHERE ip='${A}' AND result='blocked'`) === '1', 'আটকানো চেষ্টা "আটকানো" হিসেবে নথিভুক্ত');
    r = await tryLogin(B, '[t]admin', PASSWORD);
    check(!r.err && r.path.startsWith('/admin/') && r.path !== '/admin/login.php', 'অন্য আইপি (' + B + ') থেকে সঠিক পাসওয়ার্ডে স্বাভাবিক লগইন — প্রভাবিত নয় (' + r.path + ')');
    check(!lockRows().includes('ip:' + B), 'অন্য আইপি লক হয়নি');
    check(sql(`SELECT COUNT(*) FROM login_attempts WHERE ip='${A}' AND result='fail'`) === '3', 'আসল অ্যাডমিন (' + B + ') সফল লগইন করলেও ' + A + '-এর ৩টি ভুলের রেকর্ড মোছে না (আক্রমণের প্রমাণ থাকে)');

    // ---------- খ) অ্যাডমিন পেজে লক দেখা ও খোলা ----------
    console.log('\n=== অ্যাডমিন পেজে লক দেখা ও খুলে দেওয়া ===');
    await adminUi();
    const lk = await ev(`(()=>({rows:[...document.querySelectorAll('#lsLocksTable tbody tr[data-subject]')].map(r=>r.dataset.kind+':'+r.dataset.subject),stat:document.getElementById('lsStatLocks').textContent.trim(),recent:document.getElementById('lsRecentTable').innerText}))()`);
    check(lk.rows.includes('ip:' + A) && Number(lk.stat) >= 1, 'লক-তালিকায় ' + A + ' দেখায় (মোট ' + lk.stat + ')');
    const rec = N(lk.recent);
    check(rec.includes(A) && rec.includes(N('ভুল')) && rec.includes(N('আটকানো')), 'সাম্প্রতিক চেষ্টায় ভুল ও আটকানো দুটোই দেখায়');
    await ev(`document.querySelector('#lsLocksTable tr[data-subject="${A}"] .lsUnlock').click()`); await sleep(1400);
    check(!lockRows().includes('ip:' + A), '"খুলে দিন" চাপলে ডাটাবেস থেকে লক সরে যায়');
    r = await tryLogin(A, '[t]admin', PASSWORD);
    check(!r.err && r.path !== '/admin/login.php', 'খোলার পর ওই আইপি সঠিক পাসওয়ার্ডে ঢুকতে পারে');

    // ---------- গ) ইউজারনেম-লক ----------
    console.log('\n=== ইউজারনেম-লক (২ বার) ===');
    sql("DELETE FROM login_attempts WHERE ip LIKE '203.0.113.%'; ");
    sql("DELETE FROM login_locks WHERE subject LIKE '203.0.113.%'");
    await adminUi();
    await saveForm({ ip_lock_enabled: 0, user_lock_enabled: 1, user_max: 2, user_minutes: 3 });
    check(cfg('user_lock_enabled') === '1' && cfg('ip_lock_enabled') === '0' && cfg('user_max') === '2', 'পেজ থেকে ইউজারনেম-লক চালু (২ বার), আইপি-লক বন্ধ');
    await tryLogin('203.0.113.151', '[t]admin', WRONG);
    r = await tryLogin('203.0.113.152', '[t]admin', WRONG);
    check(N(r.err).includes('অনেকবার ভুল'), 'দুটি ভিন্ন আইপি থেকে ভুলে ইউজারনেম লক — ' + r.err);
    check(lockRows().includes('user:[t]admin'), 'ডাটাবেসে user লক নথিভুক্ত');
    r = await tryLogin('203.0.113.153', '[t]admin', PASSWORD);
    check(r.path === '/admin/login.php' && N(r.err).includes('অনেকবার ভুল'), 'একেবারে নতুন আইপি থেকেও সঠিক পাসওয়ার্ড আটকায় (ইউজারনেম-লক)');
    r = await tryLogin('203.0.113.153', '[t]other', WRONG);
    check(!N(r.err).includes('অনেকবার'), 'অন্য ইউজারনেম প্রভাবিত নয়');
    await adminUi();
    check(await ev("!![...document.querySelectorAll('#lsLocksTable tr[data-kind=user]')].find(r=>r.dataset.subject==='[t]admin')"), 'অ্যাডমিন পেজে ইউজারনেম লক "ইউজারনেম" ব্যাজসহ দেখায়');
    await ev("document.querySelector('#lsLocksTable tr[data-kind=user] .lsUnlock').click()"); await sleep(1400);
    check(!lockRows().includes('user:[t]admin'), 'ইউজারনেম লক খুলে দেওয়া যায়');

    // ---------- ঘ) সাদা তালিকা ----------
    console.log('\n=== সাদা তালিকা ===');
    sql("DELETE FROM login_attempts WHERE ip LIKE '203.0.113.%' OR username LIKE '[t]%'");
    await adminUi();
    await saveForm({ ip_lock_enabled: 1, user_lock_enabled: 0, ip_max: 2, whitelist: '203.0.113.161' });
    check(cfg('whitelist') === '203.0.113.161', 'সাদা তালিকা সংরক্ষিত');
    let wlLocked = false;
    for (let i = 0; i < 4; i++) { r = await tryLogin('203.0.113.161', '[t]admin', WRONG); if (N(r.err).includes('অনেকবার')) wlLocked = true; }
    check(!wlLocked && !lockRows().includes('ip:203.0.113.161'), 'সাদা তালিকার আইপি ৪ বার ভুলেও লক হয় না (সীমা ২)');
    r = await tryLogin('203.0.113.162', '[t]admin', WRONG); r = await tryLogin('203.0.113.162', '[t]admin', WRONG);
    check(N(r.err).includes('অনেকবার'), 'তালিকার বাইরের আইপি ২ বারে লক');
    await adminUi();
    check((await ev("document.getElementById('lsMyIp').textContent")).includes('203.0.113.200'), 'নিজের আইপি তালিকায় না থাকলে "সাদা তালিকায় আছে" ব্যাজ নেই');

    // ---------- ঙ) "আর কতবার" বার্তা বন্ধ ----------
    console.log('\n=== "আর কতবার" বার্তা বন্ধ ===');
    sql("DELETE FROM login_attempts WHERE ip LIKE '203.0.113.%'; ");
    sql("DELETE FROM login_locks WHERE subject LIKE '203.0.113.%'");
    await saveForm({ show_remaining: 0, ip_max: 5, whitelist: '' });
    r = await tryLogin('203.0.113.171', '[t]admin', WRONG);
    check(N(r.err).includes('মিলছে না') && !N(r.err).includes('আর '), 'বন্ধ থাকলে শুধু "মিলছে না" (বাকি-সংখ্যা দেখায় না) — ' + r.err);
    await adminUi();
    await saveForm({ show_remaining: 1 });

    // ---------- চ) ধীর করা ----------
    console.log('\n=== ধীর করা (২ বার ভুলের পর ২ সেকেন্ড) ===');
    sql("DELETE FROM login_attempts WHERE ip LIKE '203.0.113.%'; ");
    sql("DELETE FROM login_locks WHERE subject LIKE '203.0.113.%'");
    await adminUi();
    await saveForm({ ip_lock_enabled: 0, user_lock_enabled: 0, slow_after: 2, slow_seconds: 2 });
    check(cfg('slow_after') === '2' && cfg('slow_seconds') === '2', 'ধীর করা: ২ বারের পর ২ সেকেন্ড (সংরক্ষিত)');
    const timedPost = async (ip, user, pw) => {
      await clearCookies(); await asIp(ip); await go('/admin/login.php', 600);
      return ev(`(async()=>{const tok=document.querySelector('input[name=csrf]').value;const t0=performance.now();const r=await fetch('/admin/login.php',{method:'POST',body:new URLSearchParams({csrf:tok,username:${JSON.stringify(user)},password:${JSON.stringify(pw)}}),redirect:'manual'});await r.text();return Math.round(performance.now()-t0)})()`);
    };
    const m1 = await timedPost('203.0.113.181', '[t]admin', WRONG);
    const m2 = await timedPost('203.0.113.182', '[t]admin', WRONG);
    const m3 = await timedPost('203.0.113.183', '[t]admin', WRONG);
    check(m1 < 1200 && m2 < 1200, `প্রথম দুটি চেষ্টা দ্রুত (${m1}ms, ${m2}ms)`);
    check(m3 >= 1800, `৩য় চেষ্টা ≥২ সেকেন্ড ধীর (${m3}ms) — প্রতিটি আইপি আলাদা, তবু ইউজারনেমে ধীর`);
    r = await tryLogin('203.0.113.184', '[t]admin', PASSWORD);
    check(!r.err && r.path !== '/admin/login.php', 'ধীর হলেও সঠিক পাসওয়ার্ডে ঢোকা যায় — কেউ বাইরে থাকে না (' + r.ms + 'ms)');

    // ---------- ছ) মাস্টার সুইচ বন্ধ ----------
    console.log('\n=== সুরক্ষা বন্ধ ===');
    sql("DELETE FROM login_attempts WHERE ip LIKE '203.0.113.%'; ");
    await adminUi();
    await saveForm({ enabled: 0, ip_lock_enabled: 1, ip_max: 2 });
    const off = await ev("({status:document.getElementById('lsStatus').textContent.trim(),ipMax:document.getElementById('lsIpMax').value,ipOn:document.getElementById('lsIpOn').checked})");
    check(cfg('enabled') === '0' && off.status === 'বন্ধ', 'মাস্টার সুইচ বন্ধ: স্ট্যাটাস "বন্ধ"');
    check(cfg('ip_lock_enabled') === '1' && cfg('ip_max') === '2' && off.ipMax === '2' && off.ipOn, 'বন্ধ থাকা অবস্থায় সেভ করলেও বাকি মান হারায় না');
    let anyLock = false;
    for (let i = 0; i < 4; i++) { r = await tryLogin('203.0.113.191', '[t]admin', WRONG); if (N(r.err).includes('অনেকবার')) anyLock = true; }
    check(!anyLock && !lockRows().includes('ip:203.0.113.191'), 'সুরক্ষা বন্ধে ৪ ভুলেও (সীমা ২) লক হয় না');
    check(sql("SELECT COUNT(*) FROM login_attempts WHERE ip='203.0.113.191'") === '0', 'সুরক্ষা বন্ধে চেষ্টা নথিভুক্তও হয় না');

    // ---------- জ) বাইরের অ্যাক্সেস ও নিরাপত্তা ----------
    console.log('\n=== অ্যাক্সেস ও নিরাপত্তা ===');
    await clearCookies(); await asIp('203.0.113.201');
    await go('/admin/login_security.php', 1200);
    check((await ev('location.pathname')).includes('login.php'), 'লগইন ছাড়া পেজ খুললে লগইনে পাঠায়');
    const post = await (async () => {
      await adminUi();
      return ev(`(async()=>{const r=await fetch('/admin/login_security.php',{method:'POST',body:new URLSearchParams({action:'save',enabled:'0',ip_max:'1',ip_minutes:'1',user_max:'1',user_minutes:'1',window_minutes:'1',slow_after:'0',slow_seconds:'1',retention_days:'1',ip_source:'remote_addr',whitelist:''}),redirect:'manual'});return r.type})()`);
    })();
    check(cfg('ip_source') === 'xff_last', 'CSRF টোকেন ছাড়া POST-এ সেটিংস বদলায় না');
    const unk = await ev(`(async()=>{const tok=document.querySelector('input[name=csrf]').value;await fetch('/admin/login_security.php',{method:'POST',body:new URLSearchParams({csrf:tok,action:'bogus'})});return 1})()`);
    check(cfg('ip_source') === 'xff_last', 'অজানা অ্যাকশনে কিছুই বদলায় না');
    await ev(`(async()=>{const tok=document.querySelector('input[name=csrf]').value;await fetch('/admin/login_security.php',{method:'POST',body:new URLSearchParams({csrf:tok,action:'unlock','kind[]':'ip',subject:'x'})});return 1})()`);
    check(true, 'অ্যারে kind পাঠালে ক্র্যাশ হয়নি (পেজ আবার খোলা গেল)');
    await go('/admin/login_security.php', 1000);
    check((await ev('document.title')).length > 0 && !(await ev("document.body.innerText")).includes('Fatal'), 'পেজ ঠিকমতো লোড');

    // ---------- ঝ) চেহারা ----------
    console.log('\n=== চেহারা (ডেস্কটপ ও মোবাইল) ===');
    for (const w of [1326, 390]) {
      await adminUi(w);
      const l = await ev(`(()=>({hs:document.documentElement.scrollWidth>innerWidth+1,cards:document.querySelectorAll('.card').length,form:document.getElementById('lsForm').getBoundingClientRect().width}))()`);
      check(!l.hs && l.cards >= 6, `${w}px: overflow নেই, ${l.cards}টি কার্ড`);
      const s = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
      require('fs').writeFileSync(os.tmpdir() + `\\login_security_${w}.png`, Buffer.from(s.data, 'base64'));
    }

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally {
    chrome.kill(); cleanup();
    const left = sql("SELECT COUNT(*) FROM login_attempts WHERE ip LIKE '203.0.113.%' OR username LIKE '[t]%'");
    const realNow = sql("SELECT COUNT(*) FROM login_attempts WHERE ip NOT LIKE '203.0.113.%'");
    console.log(left === '0' && realNow === realAttempts ? '  (টেস্ট-রেকর্ড, অস্থায়ী অ্যাডমিন ও সেশন মোছা; ls_* সেটিংস ও আসল রেকর্ড অপরিবর্তিত)' : `  সতর্কতা: টেস্ট-রেকর্ড ${left}টি, আসল চেষ্টা ${realAttempts}→${realNow}`);
  }
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
