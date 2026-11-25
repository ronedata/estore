/**
 * tools/verify_reports.js — admin/report_orders.php ও ড্যাশবোর্ডের বিক্রয় অংশ end-to-end
 * node tools/verify_reports.js [url]
 * "[T]" অর্ডার (২০১৯ সালে) ও অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; আসল ডেটা বদলায় না।
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_RP || 9499);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const SESS_DIR = 'C:/laragon/tmp';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const SIDS = { admin: null, rep: null, norep: null };
const rid = () => require('crypto').randomBytes(13).toString('hex');
for (const k of Object.keys(SIDS)) SIDS[k] = rid();
const CSRF = 'a'.repeat(32);
const mkSess = (sid, role, perms, id) => fs.writeFileSync(`${SESS_DIR}/sess_${sid}`,
  `admin_id|i:${id};admin_username|s:2:"tt";admin_role_name|s:${role.length}:"${role}";admin_permissions|a:${perms.length}:{${perms.map((p, i) => `i:${i};s:${p.length}:"${p}";`).join('')}}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
const get = (p, sid) => fetch(BASE + p, { redirect: 'manual', headers: sid ? { Cookie: 'PHPSESSID=' + sid } : {} });
const text = async (p, sid) => (await get(p, sid)).text();
const rowsIn = (h) => { const m = h.match(/id="rpTable"[\s\S]*?<\/table>/); return m ? (m[0].match(/<tr>\s*<td data-label="অর্ডার"/g) || []).length : 0; };
const num = (h, id) => { const m = h.match(new RegExp(`id="${id}">([^<]*)<`)); return m ? m[1].trim() : null; };
const money = (n) => '৳' + Math.round(n).toLocaleString('en-US');

function send(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('timeout: ' + method)), 30000);
    const onMsg = (ev) => { let m; try { m = JSON.parse(ev.data); } catch { return; } if (m.id === id) { clearTimeout(to); ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); } };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}
function cleanup() {
  try {
    sql("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_name LIKE '[T]%' OR customer_name LIKE '=[T]%')");
    sql("DELETE FROM orders WHERE customer_name LIKE '[T]%' OR customer_name LIKE '=[T]%'");
  } catch (e) { console.log('cleanup: ' + e.message); }
  for (const s of Object.values(SIDS)) try { fs.unlinkSync(`${SESS_DIR}/sess_${s}`); } catch {}
}

(async () => {
  cleanup();
  let chrome;
  const before = sql('SELECT COUNT(*) FROM orders');
  try {
    mkSess(SIDS.admin, 'Super Admin', ['view_dashboard'], 1);
    mkSess(SIDS.rep, 'Manager', ['view_dashboard', 'view_reports'], 2);
    mkSess(SIDS.norep, 'Manager', ['view_dashboard', 'manage_orders'], 3);
    const prods = sql('SELECT MIN(id) FROM products GROUP BY category_id ORDER BY category_id LIMIT 2').split('\n');
    const [P1, P2] = prods;
    const mk = (code, when, status, subtotal, disc, ship, items, name) => {
      const total = subtotal - disc + ship;
      sql(`INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_district, shipping_fee, subtotal, discount, total, payment_method, status, created_at) VALUES ('${code}', '${name}', '01700000000', '[T]', 'dhaka', 'ঢাকা', ${ship}, ${subtotal}, ${disc}, ${total}, 'COD', '${status}', '${when}')`);
      const id = sql(`SELECT id FROM orders WHERE order_code='${code}'`);
      for (const [pid, q, pr] of items) sql(`INSERT INTO order_items (order_id, product_id, qty, price) VALUES (${id}, ${pid}, ${q}, ${pr})`);
    };
    mk('ORD9970001', '2019-03-10 10:00:00', 'pending', 1040, 100, 60, [[P1, 2, 400], [P2, 1, 240]], '[T] Alpha');
    mk('ORD9970002', '2019-03-31 23:59:59', 'delivered', 440, 0, 60, [[P1, 1, 440]], '=[T] Formula');
    mk('ORD9970003', '2019-04-01 00:00:00', 'delivered', 240, 0, 60, [[P2, 1, 240]], '[T] Beta');
    mk('ORD9970004', '2019-03-15 09:00:00', 'shipped', 600, 0, 100, [[P1, 1, 600]], '[T] Gamma');
    mk('ORD9970005', '2019-03-20 09:00:00', 'cancelled', 5000, 5, 70, [[P1, 5, 1000]], '[T] Delta');
    for (let i = 0; i < 30; i++) mk('ORD99710' + String(i).padStart(2, '0'), `2019-06-${String(1 + (i % 28)).padStart(2, '0')} 12:00:00`, 'pending', 100, 0, 50, [[P1, 1, 100]], '[T] Bulk' + i);
    const MAR = 'type=monthly&year=2019&month=3';

    console.log('\n=== অ্যাক্সেস ===');
    let r = await get('/admin/report_orders.php');
    check(r.status === 302, 'লগইন ছাড়া বন্ধ (redirect)');
    r = await get('/admin/report_orders.php', SIDS.norep);
    let h = await r.text();
    check(r.status !== 200 || !h.includes('id="rpKpis"'), 'view_reports ছাড়া অ্যাডমিন রিপোর্ট পায় না (' + r.status + ')');
    r = await get('/admin/report_orders.php?export=csv&' + MAR, SIDS.norep);
    check(!(await r.text()).includes('অর্ডার,তারিখ'), 'view_reports ছাড়া CSV-ও পায় না');
    r = await get('/admin/report_orders.php?' + MAR, SIDS.rep);
    check(r.status === 200, 'view_reports পারমিশনওয়ালা অ্যাডমিন পায়');
    r = await get('/admin/report_orders.php?' + MAR, SIDS.admin);
    h = (await r.text());
    check(r.status === 200 && h.includes('id="rpKpis"'), 'Super Admin পায়');
    check(/\/admin\/report_orders\.php"/.test(h) && h.includes('বিক্রয় রিপোর্ট'), 'সাইডবারে "বিক্রয় রিপোর্ট" মেনু আছে');
    const hn = await text('/admin/index.php', SIDS.norep);
    check(!hn.includes('বিক্রয় রিপোর্ট</span>'), 'view_reports ছাড়া অ্যাডমিনের সাইডবারে মেনু নেই');

    console.log('\n=== মার্চ ২০১৯ রিপোর্ট ===');
    h = (await text('/admin/report_orders.php?' + MAR, SIDS.admin)).normalize('NFC');
    check(num(h, 'kOrders') === '4', 'মোট অর্ডার ৪: ' + num(h, 'kOrders'));
    check(num(h, 'kRevenue') === '৳2,200', 'রাজস্ব ৳2,200 (বাতিল বাদ): ' + num(h, 'kRevenue'));
    check(num(h, 'kDiscount') === '৳100', 'ডিসকাউন্ট ৳100: ' + num(h, 'kDiscount'));
    check(num(h, 'kShipping') === '৳220', 'ডেলিভারি ৳220: ' + num(h, 'kShipping'));
    check(rowsIn(h) === 4, 'তালিকায় ৪টি সারি: ' + rowsIn(h));
    check(h.includes('ORD9970002') && !h.includes('ORD9970003'), '৩১ মার্চ রাত 23:59:59 মার্চে, ১ এপ্রিল 00:00 এপ্রিলে');
    check(/id="rpCats"/.test(h) && h.includes('(4টি)'), 'ক্যাটেগরি-অনুযায়ী বিক্রি দেখায় (প্রথম ক্যাটেগরি ৪টি)');
    check(/date|Mar 2019|10 Mar 2019/.test(h) && !/[০-৯]{2} [A-Za-z]{3} /.test(h), 'তারিখ ইংরেজি অঙ্কে (10 Mar 2019)');
    check(/chip|st-chip/.test(h) && /Cancelled <strong>1<\/strong>/.test(h) && /Pending <strong>1<\/strong>/.test(h), 'স্ট্যাটাস-চিপে সংখ্যা (Cancelled 1, Pending 1)');

    console.log('\n=== ফিল্টার ===');
    h = await text('/admin/report_orders.php?' + MAR + '&status=pending', SIDS.admin);
    check(num(h, 'kOrders') === '1' && rowsIn(h) === 1 && num(h, 'kRevenue') === '৳1,000', 'স্ট্যাটাস=pending → ১টি, রাজস্ব ৳1,000');
    h = await text('/admin/report_orders.php?' + MAR + '&status=cancelled', SIDS.admin);
    check(num(h, 'kRevenue') === '৳0' && num(h, 'kOrders') === '1', 'স্ট্যাটাস=cancelled → রাজস্ব ৳0');
    h = await text('/admin/report_orders.php?type=custom&from=2019-03-15&to=2019-03-31', SIDS.admin);
    check(num(h, 'kOrders') === '3', 'কাস্টম 15–31 মার্চ → ৩টি');
    h = await text('/admin/report_orders.php?type=custom&from=2019-03-31&to=2019-03-15', SIDS.admin);
    check(num(h, 'kOrders') === '3' && !h.includes('id="rpError"'), 'শুরু > শেষ হলে অদলবদল (ত্রুটি নয়)');
    h = await text('/admin/report_orders.php?type=yearly&year=2019', SIDS.admin);
    check(num(h, 'kOrders') === String(5 + 30), 'বার্ষিক ২০১৯ → ৩৫টি: ' + num(h, 'kOrders'));
    h = await text('/admin/report_orders.php?type=custom&from=2019-02-30&to=x', SIDS.admin);
    check(h.includes('id="rpError"') && h.includes('id="rpKpis"'), 'অবৈধ তারিখ → সতর্কতা + চালু মাস, পেজ ভাঙে না');
    for (const q of ['type[]=x', 'year[]=2019', 'month[]=3', 'status[]=pending', 'from[]=a&type=custom', 'page[]=2', 'export[]=csv', 'type=%00', "status=pending'%20OR%201=1"]) {
      r = await get('/admin/report_orders.php?' + q, SIDS.admin);
      const t = await r.text();
      check(r.status === 200 && !/fatal|uncaught|warning:|SQLSTATE/i.test(t), `ইনপুট "${q}" → ক্র্যাশ নেই`);
    }
    h = await text('/admin/report_orders.php?type=yearly&year=2019&status=pending', SIDS.admin);
    check(num(h, 'kOrders') === '31', 'বার্ষিক + pending = ৩১ (১ + ৩০)');

    console.log('\n=== পেজিনেশন (প্রতি পৃষ্ঠায় ২৫) ===');
    h = await text('/admin/report_orders.php?type=yearly&year=2019', SIDS.admin);
    check(rowsIn(h) === 25 && /পৃষ্ঠা 1\/2/.test(h.normalize('NFC')), 'প্রথম পৃষ্ঠায় ২৫টি, পৃষ্ঠা 1/2');
    check(/page=2/.test(h) && /type=yearly/.test(h.match(/page-link'[^>]*page=2/)?.[0] || h), 'পৃষ্ঠা-লিংকে ফিল্টার থাকে');
    const h2 = await text('/admin/report_orders.php?type=yearly&year=2019&page=2', SIDS.admin);
    check(rowsIn(h2) === 10, 'পৃষ্ঠা ২-এ বাকি ১০টি');
    const ids1 = [...h.matchAll(/ORD\d+/g)].map((m) => m[0]), ids2 = [...h2.matchAll(/ORD\d+/g)].map((m) => m[0]);
    check(!ids1.some((x) => ids2.includes(x)), 'দুই পৃষ্ঠায় কোনো অর্ডার ডুপ্লিকেট নয়');
    const h9 = await text('/admin/report_orders.php?type=yearly&year=2019&page=999', SIDS.admin);
    check(rowsIn(h9) === 10, 'পৃষ্ঠা ৯৯৯ → শেষ পৃষ্ঠা');

    console.log('\n=== CSV ===');
    r = await get('/admin/report_orders.php?export=csv&' + MAR, SIDS.admin);
    const buf = Buffer.from(await r.arrayBuffer());
    check(r.status === 200 && /text\/csv/.test(r.headers.get('content-type') || '') && /attachment; filename="order_report_2019-03-01_2019-03-31\.csv"/.test(r.headers.get('content-disposition') || ''), 'CSV হেডার ও ফাইল-নাম');
    check(buf[0] === 0xef && buf[1] === 0xbb && buf[2] === 0xbf, 'UTF-8 BOM');
    const lines = buf.toString('utf8').replace(/^\uFEFF/, '').split(/\r?\n/).filter(Boolean);
    check(lines.length === 1 + 4, 'শিরোনাম + ৪টি সারি: ' + lines.length);
    check(lines.some((l) => l.includes("'=[T] Formula")) && !lines.some((l) => /(^|,)"?=\[T\]/.test(l)), 'নাম = দিয়ে শুরু হলে ফর্মুলা-ইনজেকশন আটকানো');
    r = await get('/admin/report_orders.php?export=csv&type=yearly&year=2019', SIDS.admin);
    check((await r.text()).split(/\r?\n/).filter(Boolean).length === 1 + 35, 'CSV-তে পেজিনেশন নেই, সব ৩৫টি সারি');
    r = await get('/admin/report_orders.php?export=csv&' + MAR + '&status=delivered', SIDS.admin);
    check((await r.text()).split(/\r?\n/).filter(Boolean).length === 1 + 1, 'CSV ফিল্টার মানে (delivered → ১টি)');

    console.log('\n=== ড্যাশবোর্ড ===');
    const d = await text('/admin/index.php', SIDS.admin);
    const mRev = Number(sql("SELECT COALESCE(SUM(total),0) FROM orders WHERE status<>'cancelled' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01') AND created_at < DATE_ADD(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 1 MONTH)"));
    check(num(d, 'dMonthRevenue') === money(mRev), 'এই মাসের রাজস্ব ডাটাবেসের সাথে মেলে: ' + num(d, 'dMonthRevenue') + ' = ' + money(mRev));
    const oldP = sql("SELECT COUNT(*) FROM orders WHERE status='pending' AND created_at < NOW() - INTERVAL 3 DAY");
    check(num(d, 'dOldPending') === oldP, '"৩ দিনের বেশি Pending" ডাটাবেসের সাথে মেলে: ' + oldP);
    check((d.match(/title="[A-Z][a-z]{2} \d{2}: /g) || []).length === 6 && d.includes('id="dashChart"'), 'মাসিক চার্টে ৬টি বার');
    const dn = await text('/admin/index.php', SIDS.norep);
    check(!dn.includes('id="dashSales"') && dn.includes('আজকের অর্ডার'), 'view_reports ছাড়া ড্যাশবোর্ডে বিক্রয় অংশ নেই, বাকি ড্যাশবোর্ড ঠিক আছে');
    const dr = await text('/admin/index.php', SIDS.rep);
    check(dr.includes('id="dashSales"'), 'view_reports থাকলে ড্যাশবোর্ডে বিক্রয় অংশ আসে');
    const todayRev = Number(sql("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status<>'cancelled'"));
    check(d.includes(money(todayRev)), 'আজকের রেভিনিউ বাতিল বাদে: ' + money(todayRev));

    console.log('\n=== ব্রাউজার ===');
    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\rp_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((x) => x.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1; const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Network.setCookie', { name: 'PHPSESSID', value: SIDS.admin, domain: new URL(BASE).hostname, path: '/' });
    const ev = async (expr) => { const x = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (x.exceptionDetails) throw new Error(x.exceptionDetails.exception?.description || x.exceptionDetails.text); return x.result.value; };
    const go = async (p, w) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + p }); await sleep(1800); };
    for (const w of [1326, 390]) {
      await go('/admin/report_orders.php?' + MAR, w);
      const m = await ev(`(()=>({hs:document.documentElement.scrollWidth>innerWidth+1,k:document.querySelectorAll('#rpKpis .kpi-card').length,vis:[...document.querySelectorAll('#rpKpis .kpi-value')].every(e=>e.getBoundingClientRect().width>0),cust:getComputedStyle(document.querySelector('.rp-custom')).display}))()`);
      check(!m.hs && m.k === 4 && m.vis, `${w}px: overflow নেই, ৪টি কার্ড দৃশ্যমান`);
      check(m.cust === 'none', `${w}px: মাসিক বাছা থাকলে কাস্টম তারিখ ঘর লুকানো`);
      await ev("(()=>{const s=document.getElementById('rpType');s.value='custom';s.dispatchEvent(new Event('change'))})()");
      const m2 = await ev("(()=>({cust:getComputedStyle(document.querySelector('.rp-custom')).display,mon:getComputedStyle(document.querySelector('.rp-monthly')).display}))()");
      check(m2.cust !== 'none' && m2.mon === 'none', `${w}px: "কাস্টম" বাছলে শুরু/শেষ ঘর আসে, সাল/মাস যায়`);
      const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      fs.writeFileSync(path.join(os.tmpdir(), `report_${w}.png`), Buffer.from(shot.data, 'base64'));
    }
    await go('/admin/index.php', 1326);
    const dm = await ev("(()=>({hs:document.documentElement.scrollWidth>innerWidth+1,bars:document.querySelectorAll('#dashChart > div').length}))()");
    check(!dm.hs && dm.bars === 6, 'ড্যাশবোর্ড (১৩২৬px): overflow নেই, ৬টি বার');
    const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    fs.writeFileSync(path.join(os.tmpdir(), 'dash_1326.png'), Buffer.from(shot.data, 'base64'));
    await go('/admin/index.php', 390);
    check(!(await ev('document.documentElement.scrollWidth>innerWidth+1')), 'ড্যাশবোর্ড (৩৯০px): overflow নেই');
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { try { chrome && chrome.kill(); } catch {} cleanup(); }
  check(sql('SELECT COUNT(*) FROM orders') === before, 'টেস্ট শেষে অর্ডার সংখ্যা আগের মতো (' + before + ')');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
