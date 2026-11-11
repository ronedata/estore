/**
 * tools/verify_backup_ui.js — ব্যাকআপ পেজ আসল ব্রাউজারে (headless Chrome): বাটনে ক্লিক → ফাইল নামে, লেআউট, overflow
 * node tools/verify_backup_ui.js [url]     (জিপ বাটন দেখতে ZipArchive-সহ সার্ভার লাগে, যেমন php -S 127.0.0.1:8123)
 * অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; settings.backup_last_* আগের মানে ফেরায়।
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:8123';
const PORT = Number(process.env.PORT_BU || 9497);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const SESS_DIR = 'C:/laragon/tmp';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const SID = require('crypto').randomBytes(13).toString('hex');

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
  const snap = {};
  for (const k of ['backup_last_db', 'backup_last_code', 'backup_last_uploads']) snap[k] = sql(`SELECT value FROM settings WHERE \`key\`='${k}'`);
  const dl = fs.mkdtempSync(path.join(os.tmpdir(), 'bku_'));
  let chrome;
  try {
    fs.writeFileSync(`${SESS_DIR}/sess_${SID}`, `admin_id|i:1;admin_username|s:4:"test";admin_role_name|s:11:"Super Admin";admin_permissions|a:1:{i:0;s:14:"view_dashboard";}csrf|s:32:"${'a'.repeat(32)}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\bu_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((r) => r.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1; const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: dl, eventsEnabled: true });
    await call('Network.setCookie', { name: 'PHPSESSID', value: SID, domain: new URL(BASE).hostname, path: '/' });
    const ev = async (expr) => { const x = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (x.exceptionDetails) throw new Error(x.exceptionDetails.exception?.description || x.exceptionDetails.text); return x.result.value; };
    const go = async (w) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + '/admin/backup.php' }); await sleep(1800); };
    const files = () => fs.readdirSync(dl).filter((f) => !f.endsWith('.crdownload'));
    const waitFile = async (re) => { for (let i = 0; i < 60; i++) { const f = files().find((x) => re.test(x)); if (f && !fs.readdirSync(dl).some((x) => x.endsWith('.crdownload'))) return f; await sleep(500); } return null; };

    for (const w of [1326, 390]) {
      console.log(`\n=== ${w}px ===`);
      await go(w);
      const info = await ev(`(()=>{const ids=['bkDbFull','bkDbDemo','bkZipCode','bkZipUploads'];return {btns:ids.map(i=>{const e=document.getElementById(i);if(!e)return null;const r=e.getBoundingClientRect();return {w:Math.round(r.width),h:Math.round(r.height),vis:r.width>0&&r.height>0}}),hs:document.documentElement.scrollWidth>innerWidth+1,cards:document.querySelectorAll('.card').length,stale:!!document.getElementById('bkStale')}})()`);
      check(info.btns.every((b) => b && b.vis), '৪টি বাটনই দৃশ্যমান: ' + info.btns.map((b) => b && b.w + '×' + b.h).join(', '));
      check(!info.hs, 'horizontal overflow নেই');
      const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
      const sp = path.join(os.tmpdir(), `backup_${w}.png`); fs.writeFileSync(sp, Buffer.from(shot.data, 'base64'));
      console.log('  স্ক্রিনশট: ' + sp);
    }

    console.log('\n=== আসল ক্লিক → ডাউনলোড (1326px) ===');
    await go(1326);
    const clickDl = async (btn, re, label) => {
      await ev(`document.getElementById('${btn}').click()`);
      const f = await waitFile(re);
      check(!!f, label + ' → ফাইল নেমেছে' + (f ? ': ' + f + ' (' + fs.statSync(path.join(dl, f)).size + ' B)' : ''));
      await sleep(600);
      return f;
    };
    const f1 = await clickDl('bkDbFull', /^estore_db_\d{8}_\d{6}\.sql$/, 'সম্পূর্ণ ডাটাবেস বাটন');
    if (f1) { const txt = fs.readFileSync(path.join(dl, f1), 'utf8'); check(txt.includes('CREATE TABLE `products`') && txt.trim().endsWith('-- শেষ'), 'ফাইল সম্পূর্ণ (শুরুতে কাঠামো, শেষে "-- শেষ" চিহ্ন)'); }
    const f2 = await clickDl('bkDbDemo', /^estore_demo_\d{8}_\d{6}\.sql$/, 'ডেমো ডাটাবেস বাটন');
    const f3 = await clickDl('bkZipCode', /^estore_code_\d{8}_\d{6}\.zip$/, 'কোড জিপ বাটন');
    if (f3) check(fs.readFileSync(path.join(dl, f3)).slice(0, 2).toString() === 'PK', 'কোড জিপ বৈধ');
    const f4 = await clickDl('bkZipUploads', /^estore_uploads_\d{8}_\d{6}\.zip$/, 'ছবির জিপ বাটন');
    const stillOnPage = await ev("location.pathname.endsWith('/admin/backup.php') && !!document.getElementById('bkDbFull')");
    check(stillOnPage, 'ডাউনলোডের পরও ব্রাউজার পেজেই থাকে');
    await go(1326);
    const txt = await ev("document.getElementById('bkLastDb').textContent");
    check(/\d{2} [A-Z][a-z]{2} \d{4}/.test(txt) && !(await ev("!!document.getElementById('bkStale')")), 'পেজ রিলোডে "শেষ ব্যাকআপ" তারিখ দেখায়: ' + txt + ', সতর্কতা নেই');
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally {
    try { chrome && chrome.kill(); } catch {}
    for (const [k, v] of Object.entries(snap)) {
      if (v) sql(`INSERT INTO settings(\`key\`,\`value\`) VALUES('${k}','${v}') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
      else sql(`DELETE FROM settings WHERE \`key\`='${k}'`);
    }
    try { fs.unlinkSync(`${SESS_DIR}/sess_${SID}`); } catch {}
    try { fs.rmSync(dl, { recursive: true, force: true }); } catch {}
  }
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
