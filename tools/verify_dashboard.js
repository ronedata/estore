/**
 * tools/verify_dashboard.js — অ্যাডমিন ড্যাশবোর্ডের নতুন ডিজাইন (হিরো, KPI + তুলনা, মনোযোগ, চার্ট, ডোনাট, দ্রুত অ্যাকশন, সাম্প্রতিক অর্ডার)
 * node tools/verify_dashboard.js [url]
 * "[T]" অর্ডার (আজ/গতকাল/গত মাস) ও অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; আসল ডেটা বদলায় না।
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_DB || 9504);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const SESS_DIR = 'C:/laragon/tmp';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const rid = () => require('crypto').randomBytes(13).toString('hex');
const S = { admin: rid(), orders: rid(), none: rid(), buy: rid(), prod: rid() };
const CSRF = 'a'.repeat(32);
const UNAME = 'রনি';
const mkSess = (sid, role, perms, id) => fs.writeFileSync(`${SESS_DIR}/sess_${sid}`,
  `admin_id|i:${id};admin_username|s:${Buffer.byteLength(UNAME)}:"${UNAME}";admin_role_name|s:${Buffer.byteLength(role)}:"${role}";admin_permissions|a:${perms.length}:{${perms.map((p, i) => `i:${i};s:${p.length}:"${p}";`).join('')}}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
const get = (p, sid) => fetch(BASE + p, { redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + sid } });
const text = async (p, sid) => (await get(p, sid)).text();
const idv = (h, id) => { const m = h.match(new RegExp(`id="${id}"[^>]*>([^<]*)<`)); return m ? m[1].trim() : null; };
const money = (n) => (n < 0 ? '−' : '') + '৳' + Math.round(Math.abs(n)).toLocaleString('en-US');
const num = (q) => Number(sql(q));
const delta = (cur, prev) => { if (prev <= 0 && cur <= 0) return ['none', '—']; if (prev <= 0) return ['new', 'নতুন']; const p = (cur - prev) / prev * 100; if (Math.abs(p) < 0.5) return ['flat', '০%']; return [p > 0 ? 'up' : 'down', Math.round(Math.abs(p)) + '%']; };
const deltaOf = (h, id) => { const m = h.match(new RegExp(`id="${id}"[\\s\\S]*?<span class="delta (\\w+)">([^<]*)<`)); return m ? [m[1], m[2].replace(/^[▲▼■★]\s*/, '').trim()] : null; };

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
    sql("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_name LIKE '[T]%')");
    sql("DELETE FROM orders WHERE customer_name LIKE '[T]%'");
    sql("DELETE FROM products WHERE name LIKE '[T]%'");
  } catch (e) { console.log('cleanup: ' + e.message); }
  for (const s of Object.values(S)) try { fs.unlinkSync(`${SESS_DIR}/sess_${s}`); } catch {}
}

(async () => {
  cleanup();
  let chrome;
  const before = sql('SELECT COUNT(*) FROM orders') + '/' + sql('SELECT COUNT(*) FROM products');
  try {
    mkSess(S.admin, 'Super Admin', ['view_dashboard'], 1);
    mkSess(S.orders, 'Manager', ['view_dashboard', 'manage_orders'], 2);
    mkSess(S.none, 'Manager', ['view_dashboard'], 3);
    mkSess(S.buy, 'Manager', ['view_dashboard', 'manage_purchases', 'manage_expenses'], 4);
    mkSess(S.prod, 'Manager', ['view_dashboard', 'manage_products', 'view_stock_report'], 5);
    const cat = sql('SELECT id FROM categories ORDER BY id LIMIT 1');
    sql(`INSERT INTO products (category_id, name, price, stock, active) VALUES (${cat}, '[T] Dash Out', 100, 0, 1), (${cat}, '[T] Dash Low', 100, 2, 1)`);
    const mk = (code, when, status, total) => sql(`INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_fee, subtotal, total, payment_method, status, created_at) VALUES ('${code}', '[T] Dash', '01700000000', '[T]', 'dhaka', 0, ${total}, ${total}, 'COD', '${status}', ${when})`);
    mk('ORD9940001', 'NOW()', 'pending', 1000);
    mk('ORD9940002', 'NOW()', 'delivered', 500);
    mk('ORD9940003', 'NOW()', 'cancelled', 9999);
    mk('ORD9940004', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', 'delivered', 600);
    mk('ORD9940005', 'DATE_SUB(DATE_FORMAT(NOW(),"%Y-%m-01"), INTERVAL 5 DAY)', 'delivered', 800);
    mk('ORD9940006', 'DATE_SUB(NOW(), INTERVAL 10 DAY)', 'pending', 50);

    console.log('\n=== কাঠামো (Super Admin) ===');
    let h = await text('/admin/index.php', S.admin);
    check(h.includes('id="dashHero"') && /শুভ (সকাল|দুপুর|বিকাল|সন্ধ্যা|রাত্রি), রনি/.test(h), 'হিরো: সময় অনুযায়ী শুভেচ্ছা + ইউজারের নাম');
    check(/<span class="chip"><i class="fa-regular fa-calendar"><\/i> [A-Z][a-z]+, \d{2} [A-Z][a-z]{2} \d{4}<\/span>/.test(h), 'হিরোতে আজকের তারিখ ইংরেজি অঙ্কে (Wednesday, 25 Nov 2026)');
    for (const idn of ['dashToday', 'dashSales', 'dashChart', 'dashCats', 'dashStatus', 'dashAlerts', 'dashQuick', 'dashOrders']) check(h.includes(`id="${idn}"`), `সেকশন #${idn} আছে`);

    console.log('\n=== আজকের KPI ও গতকালের সাথে তুলনা ===');
    const ordToday = num("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()"), ordYest = num("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
    const revToday = num("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status<>'cancelled'"), revYest = num("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND status<>'cancelled'");
    check(idv(h, 'kOrdersToday') === String(ordToday) && idv(h, 'kRevenueToday') === money(revToday), `আজকের অর্ডার ${ordToday}, রেভিনিউ ${money(revToday)} (বাতিল ৳9,999 বাদ) ডাটাবেসের সাথে মেলে`);
    check(idv(h, 'kPendingToday') === String(num("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE() AND status='pending'")), 'পেন্ডিং (আজ) মেলে');
    const dOrd = deltaOf(h, 'kOrdersToday'), dRev = deltaOf(h, 'kRevenueToday');
    check(JSON.stringify(dOrd) === JSON.stringify(delta(ordToday, ordYest)), `অর্ডারের তুলনা গতকালের সাথে: ${dOrd} = ${delta(ordToday, ordYest)}`);
    check(JSON.stringify(dRev) === JSON.stringify(delta(revToday, revYest)), `রেভিনিউর তুলনা: ${dRev} = ${delta(revToday, revYest)}`);
    check(h.includes('গতকাল ' + money(revYest)) && h.includes(`গতকাল ${ordYest}টি`), 'গতকালের সংখ্যা সাথে দেখায়');
    const thr = Number(sql("SELECT COALESCE((SELECT value FROM settings WHERE `key`='low_stock_threshold'),5)")) || 5;
    check(idv(h, 'kLowStock') === String(num(`SELECT COUNT(*) FROM products WHERE stock <= ${thr}`)) && h.includes(`${num('SELECT COUNT(*) FROM products WHERE active=1 AND stock<=0')}টি স্টক-আউট`), 'লো-স্টক ও স্টক-আউট সংখ্যা মেলে');

    console.log('\n=== এই মাসের কার্ড ও গত মাসের সাথে তুলনা ===');
    const mStart = "DATE_FORMAT(NOW(),'%Y-%m-01')", pStart = "DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 1 MONTH)";
    const rMonth = num(`SELECT COALESCE(SUM(total),0) FROM orders WHERE status<>'cancelled' AND created_at >= ${mStart} AND created_at < DATE_ADD(${mStart}, INTERVAL 1 MONTH)`);
    const rPrev = num(`SELECT COALESCE(SUM(total),0) FROM orders WHERE status<>'cancelled' AND created_at >= ${pStart} AND created_at < ${mStart}`);
    const nMonth = num(`SELECT COUNT(*) FROM orders WHERE status<>'cancelled' AND created_at >= ${mStart} AND created_at < DATE_ADD(${mStart}, INTERVAL 1 MONTH)`);
    const nPrev = num(`SELECT COUNT(*) FROM orders WHERE status<>'cancelled' AND created_at >= ${pStart} AND created_at < ${mStart}`);
    check(idv(h, 'dMonthRevenue') === money(rMonth) && idv(h, 'dMonthOrders') === String(nMonth), `এই মাস: রাজস্ব ${money(rMonth)}, অর্ডার ${nMonth}`);
    check(JSON.stringify(deltaOf(h, 'dMonthRevenue')) === JSON.stringify(delta(rMonth, rPrev)), `রাজস্বের তুলনা গত মাসের (${money(rPrev)}) সাথে: ${deltaOf(h, 'dMonthRevenue')} = ${delta(rMonth, rPrev)}`);
    check(JSON.stringify(deltaOf(h, 'dMonthOrders')) === JSON.stringify(delta(nMonth, nPrev)), `অর্ডার-সংখ্যার তুলনা: ${deltaOf(h, 'dMonthOrders')} = ${delta(nMonth, nPrev)}`);
    check(idv(h, 'dMonthAvg') === money(nMonth > 0 ? rMonth / nMonth : 0), 'গড় অর্ডার = রাজস্ব ÷ অর্ডার');
    check(/id="dMonthNet"/.test(h) && h.includes('href="report_profit.php"'), 'নিট লাভ কার্ড ও লাভ-ক্ষতি লিংক আছে');

    console.log('\n=== অর্ডারের অবস্থা বার ও ক্যাটেগরি ডোনাট ===');
    const bar = (h.match(/id="dashStatus"[\s\S]*?<\/div>/) || [''])[0];
    const widths = [...bar.matchAll(/width:([\d.]+)%/g)].map((m) => Number(m[1]));
    check(Math.abs(widths.reduce((a, b) => a + b, 0) - 100) < 1.5, `অবস্থা-বারের অংশগুলো মিলে ১০০% (${widths.reduce((a, b) => a + b, 0).toFixed(1)})`);
    const stCounts = { pending: 0, processing: 0, shipped: 0, delivered: 0, cancelled: 0 };
    for (const l of sql(`SELECT status, COUNT(*) FROM orders WHERE created_at >= ${mStart} AND created_at < DATE_ADD(${mStart}, INTERVAL 1 MONTH) GROUP BY status`).split(String.fromCharCode(10)).filter(Boolean)) { const [s, c] = l.split('\t'); stCounts[s] = Number(c); }
    const legendOk = Object.entries(stCounts).every(([s, c]) => new RegExp(`${s[0].toUpperCase() + s.slice(1)} <strong>${c}</strong>`).test(h));
    check(legendOk, 'অবস্থার সংখ্যা (Pending/Processing/Shipped/Delivered/Cancelled) ডাটাবেসের সাথে মেলে');
    if (h.includes('class="donut"')) {
      const pcts = [...(h.match(/id="dashCats"[\s\S]*?<\/div>\s*<\/div>\s*<\/div>/) || [''])[0].matchAll(/>(\d+)%</g)].map((m) => Number(m[1]));
      check(/conic-gradient\(/.test(h) && pcts.length >= 1 && Math.abs(pcts.reduce((a, b) => a + b, 0) - 100) <= pcts.length, `ডোনাট conic-gradient আছে, লেজেন্ডের শতাংশ মিলে ~১০০ (${pcts.join('+')})`);
    } else check(h.includes('id="dashNoCats"'), 'এই মাসে বিক্রি না থাকলে ডোনাটের বদলে বার্তা');
    const bars = (h.match(/title="[A-Z][a-z]{2} \d{2}: /g) || []).length;
    check(bars === 6 && /class="bar now"/.test(h), 'চার্টে ৬টি বার; চলতি মাসের বার আলাদা রঙে ("now")');

    console.log('\n=== মনোযোগ দরকার ===');
    const old = num("SELECT COUNT(*) FROM orders WHERE status='pending' AND created_at < NOW() - INTERVAL 3 DAY");
    check(idv(h, 'dOldPending') === String(old), `৩ দিনের বেশি Pending: ${old} (ডাটাবেসের সাথে মেলে; [T] ১০ দিন আগের pending সহ)`);
    check(idv(h, 'aOutStock') === String(num('SELECT COUNT(*) FROM products WHERE active=1 AND stock<=0')) && idv(h, 'aLowStock') === String(num(`SELECT COUNT(*) FROM products WHERE stock <= ${thr}`)), 'স্টক-আউট ও কম-স্টকের সংখ্যা মেলে');
    const due = num("SELECT COALESCE(SUM(total),0) - COALESCE((SELECT SUM(x.amount) FROM purchase_payments x JOIN purchases q ON q.id=x.purchase_id WHERE q.status='active'),0) FROM purchases WHERE status='active'");
    check(idv(h, 'aSupplierDue') === money(due), 'সরবরাহকারীর মোট বাকি মেলে: ' + idv(h, 'aSupplierDue'));
    check(/href="orders\.php\?status=pending"/.test(h) && /href="suppliers\.php"/.test(h) && /href="stock_report\.php/.test(h), 'প্রতিটি সারি সংশ্লিষ্ট পেজের লিংক');

    console.log('\n=== দ্রুত অ্যাকশন (সেটিংস নেই) ===');
    const quick = (h.match(/id="dashQuick"[\s\S]*?<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/) || [''])[0] || (h.match(/id="dashQuick"[\s\S]*?<!-- সাম্প্রতিক/) || [''])[0];
    check(!/settings\.php/.test(quick) && !/সেটিংস/.test(quick), 'দ্রুত অ্যাকশনে "সেটিংস" নেই (সরানো হয়েছে)');
    const tiles = [...quick.matchAll(/<a class="qa-tile [^"]*" href="([^"]+)"><span class="qi"><i class="fa-solid ([^"]+)"><\/i><\/span><span>([^<]+)<\/span>/g)].map((m) => m[3]);
    check(JSON.stringify(tiles) === JSON.stringify(['নতুন পণ্য', 'পণ্য তালিকা', 'আজকের অর্ডার', 'নতুন ক্রয়', 'খরচ যোগ', 'ক্যাটাগরি', 'স্টক রিপোর্ট', 'লাভ-ক্ষতি', 'Payment']), 'Super Admin: ৯টি টাইল: ' + tiles.join(', '));
    h = await text('/admin/index.php', S.prod);
    const qp = [...(h.match(/id="dashQuick"[\s\S]*?<!-- সাম্প্রতিক/) || [''])[0].matchAll(/<span>([^<]+)<\/span><\/a>/g)].map((m) => m[1]);
    check(JSON.stringify(qp) === JSON.stringify(['নতুন পণ্য', 'পণ্য তালিকা', 'স্টক রিপোর্ট']), 'পণ্য+স্টক পারমিশন: শুধু ৩টি টাইল (' + qp.join(', ') + ')');
    h = await text('/admin/index.php', S.buy);
    const qb = [...(h.match(/id="dashQuick"[\s\S]*?<!-- সাম্প্রতিক/) || [''])[0].matchAll(/<span>([^<]+)<\/span><\/a>/g)].map((m) => m[1]);
    check(JSON.stringify(qb) === JSON.stringify(['নতুন ক্রয়', 'খরচ যোগ']) && h.includes('id="aSupplierDue"') && !h.includes('id="dOldPending"') && !h.includes('id="dashSales"'), 'ক্রয়+খরচ পারমিশন: ২টি টাইল, সরবরাহকারীর বাকি দেখায়; Pending/বিক্রয় অংশ নেই');
    h = await text('/admin/index.php', S.orders);
    check(h.includes('id="dOldPending"') && !h.includes('id="aSupplierDue"') && !h.includes('id="dashSales"') && h.includes('আজকের অর্ডার'), 'শুধু অর্ডার-পারমিশন: Pending সতর্কতা আছে; বিক্রয়/বাকি নেই');
    h = await text('/admin/index.php', S.none);
    check(h.includes('আপনার জন্য কোনো দ্রুত অ্যাকশন নেই') && !h.includes('id="dashAlerts"') && h.includes('id="dashToday"') && h.includes('id="dashOrders"'), 'কোনো পারমিশন না থাকলে: দ্রুত অ্যাকশনে বার্তা, সতর্কতা-প্যানেল নেই, বাকি ড্যাশবোর্ড ঠিক');

    console.log('\n=== সাম্প্রতিক অর্ডার ===');
    h = await text('/admin/index.php', S.admin);
    const tbl = (h.match(/id="dashOrders"[\s\S]*?<\/table>/) || [''])[0];
    const codes = [...tbl.matchAll(/<td data-label="অর্ডার">#(ORD\d+)<\/td>/g)].map((m) => m[1]);
    const dbCodes = sql('SELECT order_code FROM orders ORDER BY created_at DESC, id DESC LIMIT 8').split(String.fromCharCode(10)).filter(Boolean);
    check(codes.length === 8 && JSON.stringify(codes) === JSON.stringify(dbCodes), 'সর্বশেষ ৮টি অর্ডার, নতুন আগে (ডাটাবেসের ক্রমের সাথে হুবহু)');
    check(/আজ \d{1,2}:\d{2} (AM|PM)/.test(tbl), 'আজকের অর্ডারের সময় "আজ 4:26 PM" ধরনে');
    check(/<span class="badge badge-status bg-\w+/.test(tbl) && !/[০-৯]/.test(tbl.replace(/data-label="[^"]*"/g, '')), 'স্ট্যাটাস ব্যাজ আছে; সংখ্যা ইংরেজি অঙ্কে');
    check(/href="order_view\.php\?id=\d+"/.test(tbl) && /href="order_print\.php\?id=\d+"/.test(tbl), 'প্রতি সারিতে View ও Print লিংক');

    console.log('\n=== ব্রাউজার ===');
    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\db_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((x) => x.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1; const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Network.setCookie', { name: 'PHPSESSID', value: S.admin, domain: new URL(BASE).hostname, path: '/' });
    const ev = async (expr) => { const x = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (x.exceptionDetails) throw new Error(x.exceptionDetails.exception?.description || x.exceptionDetails.text); return x.result.value; };
    const go = async (p, w) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + p }); await sleep(1900); };
    for (const w of [1326, 390]) {
      await go('/admin/index.php', w);
      const m = await ev(`(()=>{const vis=e=>{const r=e.getBoundingClientRect();return r.width>0&&r.height>0};return {hs:document.documentElement.scrollWidth>innerWidth+1,
        kpi:[...document.querySelectorAll('#dashToday .kpi-card, #dashSales .kpi-card')].length,kvis:[...document.querySelectorAll('.kpi-value')].every(vis),
        donut:(()=>{const d=document.querySelector('.donut');if(!d)return 'none';const r=d.getBoundingClientRect();return Math.round(r.width)+'x'+Math.round(r.height)})(),
        bars:[...document.querySelectorAll('#dashChart .bar')].every(vis),chartH:Math.round(document.getElementById('dashChart').getBoundingClientRect().height),
        tiles:[...document.querySelectorAll('.qa-tile')].every(vis),ordersVis:vis(document.getElementById('dashOrders')),
        overflowKpi:[...document.querySelectorAll('.kpi-card')].some(c=>c.scrollWidth>c.clientWidth+1)}})()`);
      check(!m.hs && m.kpi === 8 && m.kvis && m.bars && m.tiles && m.ordersVis && !m.overflowKpi, `${w}px: overflow নেই, ৮টি KPI কার্ড দৃশ্যমান ও লেখা কার্ড ছাড়ায় না, বার/টাইল/অর্ডার-টেবিল দৃশ্যমান (চার্টের উচ্চতা ${m.chartH}px, ডোনাট ${m.donut})`);
      const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
      fs.writeFileSync(path.join(os.tmpdir(), `dashboard_${w}.png`), Buffer.from(shot.data, 'base64'));
    }
    await go('/admin/index.php', 1326);
    const hv = await ev("(()=>{const c=document.querySelector('.kpi-card');const a=getComputedStyle(c).transform;return a})()");
    check(typeof hv === 'string', 'KPI কার্ডে hover-স্টাইল আছে (transition)');
    const ord = await ev("document.querySelectorAll('#dashOrders tbody tr').length");
    check(ord === 8, 'ব্রাউজারে অর্ডার-টেবিলে ৮ সারি');
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { try { chrome && chrome.kill(); } catch {} cleanup(); }
  check(sql('SELECT COUNT(*) FROM orders') + '/' + sql('SELECT COUNT(*) FROM products') === before, 'টেস্ট শেষে অর্ডার/পণ্যের সংখ্যা আগের মতো (' + before + ')');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
