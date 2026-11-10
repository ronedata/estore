/**
 * tools/verify_order_tracking.js — অর্ডার ট্র্যাকিং end-to-end (headless Chrome + fetch)
 * node tools/verify_order_tracking.js [url]
 * লগইন লাগে; নিজের মোবাইলে গত ৭ দিন + "আরও দেখুন" (১০টি করে); অন্য মোবাইলে কোড লাগে; ভুল চেষ্টার সীমা; গোপন লিংক।
 * নিজে "[T]" গ্রাহক/অর্ডার বানায় ও শেষে মুছে ফেলে; আইপি 203.0.113.x ব্যবহার করে।
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_OT || 9493);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const DB = process.env.DB_NAME || 'estore';
const SESS_DIR = 'C:/laragon/tmp';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', DB], { encoding: 'utf8', input: q + ';\n' }).trim();

const MOB = '01799000001', OTHER = '01799000002';
const SID = require('crypto').randomBytes(13).toString('hex');
const CK = 'PHPSESSID=' + SID;

function send(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('timeout: ' + method)), 20000);
    const onMsg = (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch { return; }
      if (m.id === id) { clearTimeout(to); ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); }
    };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}
const get = (path, opt = {}) => fetch(BASE + path, { redirect: 'manual', ...opt, headers: { ...(opt.headers || {}) } });
const cleanup = () => {
  try {
    sql("DELETE FROM orders WHERE customer_name LIKE '[T]%'");
    sql("DELETE FROM customers WHERE name LIKE '[T]%'");
    sql("DELETE FROM tracking_attempts WHERE ip LIKE '203.0.113.%'");
  } catch {}
  try { fs.unlinkSync(`${SESS_DIR}/sess_${SID}`); } catch {}
};

(async () => {
  cleanup();
  let chrome;
  try {
    sql(`INSERT INTO customers (mobile, password_hash, name, active) VALUES ('${MOB}', 'x', '[T] Cust', 1)`);
    const CID = sql(`SELECT id FROM customers WHERE mobile='${MOB}' AND name LIKE '[T]%' LIMIT 1`);
    const ins = (code, mob, days, i) => sql(`INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_fee, subtotal, total, payment_method, status, created_at, customer_id, public_token) VALUES ('${code}', '[T] O${i}', '${mob}', 'x', 'dhaka', 0, 100, 100, 'COD', 'pending', DATE_SUB(NOW(), INTERVAL ${days} DAY), NULL, MD5(CONCAT('${code}', RAND())))`);
    ins('ORD9980001', MOB, 0, 1); ins('ORD9980002', MOB, 3, 2);
    for (let i = 0; i < 25; i++) ins('ORD99801' + String(i).padStart(2, '0'), MOB, 10 + i, 10 + i);
    ins('ORD9980201', OTHER, 1, 99);
    fs.writeFileSync(`${SESS_DIR}/sess_${SID}`, `customer_id|i:${CID};customer_name|s:6:"[T] Cu";csrf|s:32:"${'a'.repeat(32)}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);

    const rowsIn = (h) => (h.match(/<tr data-id=/g) || []).length;
    console.log('\n=== লগইন ছাড়া (পেজ খোলা, শুধু বিস্তারিতে লগইন) ===');
    let r = await get('/track_orders.php');
    check(r.status === 200, 'গেস্ট ট্র্যাকিং পেজ খুলতে পারে (' + r.status + ')');
    r = await get(`/track_orders.php?m=${MOB}`);
    let html = await r.text();
    check(r.status === 200 && rowsIn(html) === 2, 'গেস্ট মোবাইল দিয়ে খুঁজলে গত ৭ দিনের ২টি অর্ডার (' + rowsIn(html) + ')');
    check(html.includes('id="trkMore"'), '"আরও দেখুন" বাটন আছে');
    check(!html.includes('[T] O') && !html.includes('ORD9980201'), 'তালিকায় ক্রেতার নাম নেই, অন্য নম্বরের অর্ডার নেই');
    const m1 = html.match(/single_order_details\.php\?id=(\d+)/);
    check(!!m1 && !/single_order_details\.php\?t=/.test(html), 'ভিউ বাটনের লিংক বিস্তারিত পেজে (লগইন-সুরক্ষিত)');
    r = await get(`/single_order_details.php?id=${m1[1]}`);
    check(r.status === 302 && /account\.php\?next=/.test(r.headers.get('location') || ''), 'গেস্ট ভিউতে চাপলে লগইন পেজে যায়');
    const tok = sql(`SELECT public_token FROM orders WHERE id=${m1[1]}`);
    r = await get(`/single_order_details.php?t=${tok}`);
    check(r.status === 302, 'গোপন কোড দিয়েও গেস্ট বিস্তারিত দেখতে পারে না');
    r = await get(`/track_orders.php?more=1&m=${MOB}&before=0`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const gj = await r.json();
    check(gj.ok === true && (gj.html.match(/<tr data-id=/g) || []).length === 10, 'গেস্টের "আরও দেখুন" ১০টি আনে');
    r = await get(`/track_orders.php?m=${MOB}&c=ORD9980001`);
    html = await r.text();
    check(rowsIn(html) === 1 && html.includes('ORD9980001'), 'মোবাইল+কোড → ১টি অর্ডার');
    r = await get(`/track_orders.php?m=${MOB}&c=ORD9989999`, { headers: { 'X-Forwarded-For': '203.0.113.61' } });
    html = await r.text();
    check(rowsIn(html) === 0 && html.includes('মিলছে না'), 'ভুল কোড → সাধারণ ত্রুটি');
    r = await get(`/track_orders.php?m=0123`);
    check(rowsIn(await r.text()) === 0, 'অবৈধ মোবাইলে কিছু আসে না');
    r = await get(`/track_orders.php?m[]=1&c[]=2`);
    check(r.status === 200 && !/fatal|warning:/i.test(await r.text()), 'অ্যারে ইনপুটে ক্র্যাশ নেই');

    console.log('\n=== লগইন করা ক্রেতা: শুধু নিজের অর্ডারের বিস্তারিত ===');
    r = await get('/track_orders.php', { headers: { Cookie: CK } });
    html = await r.text();
    check(rowsIn(html) === 2, 'লগইন করা অবস্থায় ডিফল্টে নিজের নম্বরের ২টি অর্ডার');
    const own = sql(`SELECT id FROM orders WHERE order_code='ORD9980001'`), other = sql(`SELECT id FROM orders WHERE order_code='ORD9980201'`);
    r = await get(`/single_order_details.php?id=${own}`, { headers: { Cookie: CK } });
    check(r.status === 200 && (await r.text()).includes('[T] O1'), 'নিজের নম্বরের অর্ডারের বিস্তারিত খোলে');
    r = await get(`/single_order_details.php?id=${other}`, { headers: { Cookie: CK } });
    html = (await r.text()).normalize('NFC');
    check(r.status === 200 && !html.includes('[T] O99') && html.includes('অর্ডার পাওয়া যায়নি'.normalize('NFC')), 'অন্যের (অন্য নম্বরের) অর্ডার খোলে না');
    r = await get(`/single_order_details.php?id=${own}x`, { headers: { Cookie: CK } });
    check(!(await r.text()).includes('[T] O1'), 'আইডিতে জাল মান বাতিল');

    console.log('\n=== ভুল চেষ্টার সীমা ===');
    const IPH = { 'X-Forwarded-For': '203.0.113.70' };
    for (let i = 0; i < 10; i++) await (await get(`/track_orders.php?m=${OTHER}&c=ORD99899${String(i).padStart(2, '0')}`, { headers: IPH })).text();
    html = await (await get(`/track_orders.php?m=${OTHER}&c=ORD9980201`, { headers: IPH })).text();
    check(rowsIn(html) === 0 && html.includes('অনেকবার ভুল চেষ্টা'), '১০ ভুলের পর সঠিক জোড়াও আটকায়');
    html = await (await get(`/track_orders.php?m=${OTHER}&c=ORD9980201`, { headers: { 'X-Forwarded-For': '203.0.113.71' } })).text();
    check(rowsIn(html) === 1, 'অন্য আইপি অপ্রভাবিত');
    html = await (await get(`/track_orders.php?m=${MOB}`, { headers: IPH })).text();
    check(rowsIn(html) === 2, 'আটকানো আইপিতেও শুধু-মোবাইলের তালিকা চলে');

    console.log('\n=== ব্রাউজার: আরও দেখুন ===');
    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\ot_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((x) => x.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1; const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Network.setCookie', { name: 'PHPSESSID', value: SID, domain: new URL(BASE).hostname, path: '/' });
    const ev = async (expr) => { const x = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (x.exceptionDetails) throw new Error(x.exceptionDetails.exception?.description || x.exceptionDetails.text); return x.result.value; };
    const go = async (path, w) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + path }); await sleep(1500); };
    const n = () => ev("document.querySelectorAll('#trkBody tr').length");
    for (const w of [1326, 390]) {
      console.log(`  -- ${w}px --`);
      await go('/track_orders.php', w);
      check((await n()) === 2, 'শুরুতে ২টি সারি');
      const counts = [];
      for (let i = 0; i < 4; i++) {
        const has = await ev("!!document.getElementById('trkMore')");
        if (!has) break;
        await ev("document.getElementById('trkMore').click()"); await sleep(900);
        counts.push(await n());
      }
      check(JSON.stringify(counts) === JSON.stringify([12, 22, 27]), 'ক্লিকে ১০টি করে: ' + counts.join(' → ') + ' (আশা: 12 → 22 → 27)');
      check(!(await ev("!!document.getElementById('trkMore')")), 'সব দেখানো হলে বাটন নেই');
      const dup = await ev("(()=>{const ids=[...document.querySelectorAll('#trkBody tr')].map(r=>r.dataset.id);return ids.length-new Set(ids).size})()");
      check(dup === 0, 'কোনো অর্ডার দুইবার আসেনি');
      const txt = await ev("document.getElementById('trk').innerText");
      check(!/[০-৯]/.test(txt.replace(/[^০-৯]/g, '') ? txt.split('\n').filter((l) => /\d{2} [A-Z][a-z]{2} \d{4}/.test(l)).join('') : ''), 'তারিখ ইংরেজি অঙ্কে');
      check(!(await ev('document.documentElement.scrollWidth>innerWidth+1')), 'horizontal overflow নেই');
      if (w === 1326) {
        const href = await ev("document.querySelector('#trkBody a.link').href");
        const rr = await fetch(href, { redirect: 'manual', headers: { Cookie: CK } });
        check(rr.status === 200 && (await rr.text()).includes('[T] O'), 'লগইন করা মালিকের জন্য সারির লিংক বিস্তারিত খোলে');
      }
    }
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { try { chrome && chrome.kill(); } catch {} cleanup(); }
  const left = sql("SELECT (SELECT COUNT(*) FROM orders WHERE customer_name LIKE '[T]%') + (SELECT COUNT(*) FROM customers WHERE name LIKE '[T]%') + (SELECT COUNT(*) FROM tracking_attempts WHERE ip LIKE '203.0.113.%')");
  check(left === '0', 'টেস্ট ডেটা মুছে ফেলা হয়েছে');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
