/**
 * tools/verify_admin_nav.js — admin সাইডবারের মেনু-গ্রুপ (ভাঁজ-করা) end-to-end (headless Chrome)
 * node tools/verify_admin_nav.js [url]
 * অস্থায়ী অ্যাডমিন সেশন (Super Admin ও সীমিত-পারমিশন) নিজে বানায়-মোছে; ডাটাবেসে কিছু লেখে না।
 */
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_NV || 9503);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const SESS_DIR = 'C:/laragon/tmp';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const rid = () => require('crypto').randomBytes(13).toString('hex');
const S = { admin: rid(), prod: rid(), acct: rid(), none: rid(), nonsuper: rid() };
const CSRF = 'a'.repeat(32);
const mkSess = (sid, role, perms, id) => fs.writeFileSync(`${SESS_DIR}/sess_${sid}`,
  `admin_id|i:${id};admin_username|s:2:"tt";admin_role_name|s:${role.length}:"${role}";admin_permissions|a:${perms.length}:{${perms.map((p, i) => `i:${i};s:${p.length}:"${p}";`).join('')}}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);

function send(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('timeout: ' + method)), 30000);
    const onMsg = (ev) => { let m; try { m = JSON.parse(ev.data); } catch { return; } if (m.id === id) { clearTimeout(to); ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); } };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}

const EXPECT = {
  main: ['ড্যাশবোর্ড'],
  sales: ['অর্ডার ব্যবস্থাপনা', 'পেমেন্ট', 'ডেলিভারি চার্জ', 'কুরিয়ার', 'কাস্টমার', 'রিভিউ', 'মেসেজ'],
  catalog: ['পণ্য ব্যবস্থাপনা', 'দ্রুত পণ্য যোগ', 'ক্যাটেগরি'],
  accounts: ['ক্রয়', 'সরবরাহকারী', 'খরচ', 'স্টক রিপোর্ট'],
  reports: ['বিক্রয় রিপোর্ট', 'Page Views'],
  site: ['স্লাইডার', 'হোম সেকশন', 'ঘোষণা', 'পেজ'],
  system: ['সেটিংস', 'ইউজার ও রোল', 'লগইন সুরক্ষা', 'সিকিউরিটি', 'ব্যাকআপ'],
};
const GROUP_LABELS = { sales: 'বিক্রয়', catalog: 'পণ্য ও ক্যাটালগ', accounts: 'ক্রয় ও হিসাব', reports: 'রিপোর্ট', site: 'সাইট সাজানো', system: 'সিস্টেম' };
const PAGE_GROUP = {
  'index.php': 'main', 'products.php': 'catalog', 'product_form.php': 'catalog', 'categories.php': 'catalog', 'product_add_for_mobile.php': 'catalog',
  'orders.php': 'sales', 'payments.php': 'sales', 'delivery.php': 'sales', 'courier.php': 'sales', 'customers.php': 'sales', 'reviews.php': 'sales', 'messages.php': 'sales',
  'purchases.php': 'accounts', 'purchase_form.php': 'accounts', 'suppliers.php': 'accounts', 'expenses.php': 'accounts', 'stock_report.php': 'accounts',
  'report_orders.php': 'reports', 'report_profit.php': 'reports', 'report_purchases.php': 'reports', 'report_expenses.php': 'reports', 'page_views.php': 'reports',
  'sliders.php': 'site', 'home_sections.php': 'site', 'announcements.php': 'site', 'pages.php': 'site',
  'settings.php': 'system', 'users.php': 'system', 'roles.php': 'system', 'login_security.php': 'system', 'security.php': 'system', 'backup.php': 'system',
};

(async () => {
  let chrome;
  try {
    mkSess(S.admin, 'Super Admin', ['view_dashboard'], 1);
    mkSess(S.prod, 'Manager', ['view_dashboard', 'manage_products'], 2);
    mkSess(S.acct, 'Manager', ['view_dashboard', 'manage_purchases', 'manage_expenses'], 3);
    mkSess(S.none, 'Manager', ['view_dashboard'], 4);
    mkSess(S.nonsuper, 'Manager', ['view_dashboard', 'settings', 'manage_admin_users'], 5);

    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\nv_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((x) => x.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1; const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    const setSid = (sid) => call('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: new URL(BASE).hostname, path: '/' });
    const ev = async (expr) => { const x = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (x.exceptionDetails) throw new Error(x.exceptionDetails.exception?.description || x.exceptionDetails.text); return x.result.value; };
    const go = async (p, w = 1326, wait = 1300) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + p }); await sleep(wait); };
    const snap = () => ev(`(()=>{
      const nav=document.getElementById('adminNav');
      const out={top:[...nav.querySelectorAll(':scope > a')].map(a=>a.querySelector('span').textContent.trim()).filter(x=>x!=='লগআউট'),groups:{}};
      nav.querySelectorAll('.nav-group').forEach(g=>{
        const body=g.querySelector('.nav-group-body');
        out.groups[g.dataset.group]={label:g.querySelector('.ng-label').textContent.trim(),items:[...body.querySelectorAll('a span')].map(s=>s.textContent.trim()),open:g.classList.contains('open'),hasActive:g.classList.contains('has-active'),
          visible:getComputedStyle(body).display!=='none',expanded:g.querySelector('.nav-group-toggle').getAttribute('aria-expanded'),active:[...body.querySelectorAll('a.active span')].map(s=>s.textContent.trim())};
      });
      out.order=[...nav.querySelectorAll('.nav-group')].map(g=>g.dataset.group);
      out.allSpans=[...document.querySelectorAll('.sidebar .navy a span')].map(s=>s.textContent.trim());
      out.activeLinks=[...document.querySelectorAll('.sidebar a.active')].map(a=>a.textContent.trim());
      return out;})()`);

    console.log('\n=== Super Admin: গ্রুপ ও মেনুর বিন্যাস ===');
    await setSid(S.admin);
    await go('/admin/index.php');
    let n = await snap();
    check(JSON.stringify(n.top) === JSON.stringify(EXPECT.main), 'ড্যাশবোর্ড গ্রুপের বাইরে, সবার ওপরে: ' + n.top.join());
    check(JSON.stringify(n.order) === JSON.stringify(['sales', 'catalog', 'accounts', 'reports', 'site', 'system']), 'গ্রুপের ক্রম: বিক্রয় → পণ্য ও ক্যাটালগ → ক্রয় ও হিসাব → রিপোর্ট → সাইট সাজানো → সিস্টেম');
    for (const g of Object.keys(GROUP_LABELS)) {
      check(n.groups[g] && n.groups[g].label === GROUP_LABELS[g] && JSON.stringify(n.groups[g].items) === JSON.stringify(EXPECT[g]), `গ্রুপ "${GROUP_LABELS[g]}": ${EXPECT[g].join(', ')}`);
    }
    const flat = [...EXPECT.main, ...Object.keys(GROUP_LABELS).flatMap((g) => EXPECT[g]), 'লগআউট'];
    check(n.allSpans.length === flat.length && new Set(n.allSpans).size === n.allSpans.length && flat.every((x) => n.allSpans.includes(x)), `মোট ${n.allSpans.length}টি মেনু-লিংক (২৬ + লগআউট), কোনোটি বাদ বা ডুপ্লিকেট নয়`);
    check(Object.values(n.groups).every((g) => !g.open && !g.visible && g.expanded === 'false'), 'ড্যাশবোর্ডে কোনো গ্রুপ খোলা নেই (সব ভাঁজ-করা), aria-expanded=false');
    check(n.activeLinks.join() === 'ড্যাশবোর্ড', 'সক্রিয় মেনু: ড্যাশবোর্ড');

    console.log('\n=== প্রতিটি পেজে নিজের গ্রুপ খোলা ও মেনু সক্রিয় ===');
    for (const [pg, grp] of Object.entries(PAGE_GROUP)) {
      if (pg === 'index.php') continue;
      await go('/admin/' + pg, 1326, 900);
      const s2 = await snap();
      const g = s2.groups[grp];
      check(g && g.open && g.hasActive && g.visible && g.active.length === 1 && Object.entries(s2.groups).every(([k, v]) => k === grp || !v.open), `${pg} → "${GROUP_LABELS[grp]}" খোলা, ১টি সক্রিয় মেনু (${g ? g.active.join() : '—'}), বাকি গ্রুপ বন্ধ`);
    }

    console.log('\n=== খোলা-বন্ধ করা ও মনে রাখা ===');
    await go('/admin/products.php');
    await ev("document.querySelector('[data-group=reports] .nav-group-toggle').click()");
    n = await snap();
    check(n.groups.reports.open && n.groups.reports.visible && n.groups.reports.expanded === 'true', 'গ্রুপের নামে ক্লিক → খোলে (মেনু দেখা যায়, aria-expanded=true)');
    check(n.groups.catalog.open, 'বর্তমান পেজের গ্রুপ খোলাই থাকে');
    await ev("document.querySelector('[data-group=reports] .nav-group-toggle').click()");
    n = await snap();
    check(!n.groups.reports.open && !n.groups.reports.visible && n.groups.reports.expanded === 'false', 'আবার ক্লিক → বন্ধ');
    await ev("document.querySelector('[data-group=accounts] .nav-group-toggle').click()");
    await go('/admin/products.php');
    n = await snap();
    check(n.groups.accounts.open && n.groups.accounts.visible, 'খোলা রাখা গ্রুপ রিলোডের পরও খোলা (localStorage-এ মনে থাকে)');
    await go('/admin/sliders.php');
    n = await snap();
    check(n.groups.accounts.open && n.groups.site.open && !n.groups.sales.open, 'অন্য পেজে গেলেও মনে-রাখা গ্রুপ ও নতুন পেজের গ্রুপ খোলা, বাকি বন্ধ');
    await ev("document.querySelector('[data-group=accounts] .nav-group-toggle').click()");
    await go('/admin/sliders.php');
    n = await snap();
    check(!n.groups.accounts.open, 'বন্ধ করলে রিলোডে বন্ধই থাকে');
    await ev("document.querySelector('[data-group=site] .nav-group-toggle').click()");
    check(!(await snap()).groups.site.open, 'বর্তমান পেজের গ্রুপও হাতে বন্ধ করা যায়');
    await go('/admin/sliders.php');
    check((await snap()).groups.site.open, 'কিন্তু রিলোডে সেটি আবার খোলে (বর্তমান পেজের মেনু কখনো লুকানো থাকে না)');
    await ev("localStorage.setItem('adminNavOpen','not-json{')");
    await go('/admin/products.php');
    n = await snap();
    check(n.groups.catalog.open && !n.groups.sales.open, 'localStorage নষ্ট/অবৈধ হলেও ক্র্যাশ নয়, স্বাভাবিক চলে');
    await ev("localStorage.setItem('adminNavOpen', JSON.stringify(['nope','sales',5,null]))");
    await go('/admin/products.php');
    n = await snap();
    check(n.groups.sales.open && n.order.length === 6, 'অজানা/ভুল মান উপেক্ষা, বৈধ ("sales") খোলে');
    await ev("localStorage.removeItem('adminNavOpen')");

    console.log('\n=== JS ছাড়া: সব মেনু দেখা যায় ===');
    await go('/admin/products.php');
    const js1 = await ev("(()=>{const b=document.querySelector('[data-group=reports] .nav-group-body');const had=document.documentElement.classList.contains('js');const closedHidden=getComputedStyle(b).display==='none';document.documentElement.classList.remove('js');const noJsVisible=getComputedStyle(b).display!=='none';document.documentElement.classList.add('js');return {had,closedHidden,noJsVisible}})()");
    check(js1.had && js1.closedHidden && js1.noJsVisible, 'html.js ক্লাস না থাকলে (JS চলেনি) বন্ধ-গ্রুপের মেনুও দেখা যায় — JS ছাড়া কোনো মেনু হারায় না');

    console.log('\n=== পারমিশন অনুযায়ী ===');
    await setSid(S.prod); await go('/admin/products.php');
    n = await snap();
    check(JSON.stringify(n.order) === JSON.stringify(['catalog']) && JSON.stringify(n.groups.catalog.items) === JSON.stringify(['পণ্য ব্যবস্থাপনা']) && JSON.stringify(n.top) === JSON.stringify(['ড্যাশবোর্ড']), 'শুধু manage_products: ড্যাশবোর্ড + "পণ্য ও ক্যাটালগ" (১টি মেনু); বাকি সব গ্রুপ লুকানো');
    await setSid(S.acct); await go('/admin/purchases.php');
    n = await snap();
    check(JSON.stringify(n.order) === JSON.stringify(['accounts']) && JSON.stringify(n.groups.accounts.items) === JSON.stringify(['ক্রয়', 'সরবরাহকারী', 'খরচ']), 'manage_purchases + manage_expenses: "ক্রয় ও হিসাব"-এ ৩টি (স্টক রিপোর্ট পারমিশন নেই বলে নেই)');
    await setSid(S.none); await go('/admin/index.php');
    n = await snap();
    check(n.order.length === 0 && n.top.join() === 'ড্যাশবোর্ড' && n.allSpans.join() === 'ড্যাশবোর্ড,লগআউট', 'শুধু ড্যাশবোর্ড-পারমিশন: কোনো গ্রুপ-হেডারই নেই (খালি গ্রুপ লুকানো)');
    await setSid(S.nonsuper); await go('/admin/settings.php');
    n = await snap();
    check(JSON.stringify(n.groups.system.items) === JSON.stringify(['সেটিংস', 'ইউজার ও রোল']) && !n.allSpans.includes('ব্যাকআপ') && !n.allSpans.includes('সিকিউরিটি') && !n.allSpans.includes('লগইন সুরক্ষা'), 'Super Admin নন: "সিস্টেম"-এ শুধু সেটিংস ও ইউজার ও রোল; সিকিউরিটি/লগইন সুরক্ষা/ব্যাকআপ নেই');
    check(n.groups.system.active.join() === 'সেটিংস', 'সেটিংস পেজে "সেটিংস" মেনু সক্রিয় (আগে এটি কখনো সক্রিয় দেখাত না — ফাইলের নাম মিলত না)');

    console.log('\n=== মোবাইল (৩৯০px) ===');
    await setSid(S.admin);
    await go('/admin/orders.php', 390);
    await ev("document.getElementById('btnSidebarToggle').click()"); await sleep(500);
    const sb = await ev("(()=>{const s=document.querySelector('.sidebar');const r=s.getBoundingClientRect();return {left:Math.round(r.left),shown:s.classList.contains('show'),hs:document.documentElement.scrollWidth>innerWidth+1}})()");
    check(sb.shown && sb.left >= 0 && !sb.hs, 'মোবাইলে ড্রয়ার খোলে, overflow নেই');
    await ev("document.querySelector('[data-group=system] .nav-group-toggle').click()"); await sleep(300);
    n = await snap();
    check(n.groups.system.open && n.groups.system.visible && n.groups.sales.open, 'মোবাইলে গ্রুপ খোলা-বন্ধ কাজ করে; বর্তমান গ্রুপ (বিক্রয়) খোলা');
    const nv = await ev("(()=>{const nv=document.getElementById('adminNav');return {scroll:nv.scrollHeight>nv.clientHeight,canScroll:getComputedStyle(nv).overflowY}})()");
    check(nv.canScroll === 'auto' || nv.canScroll === 'scroll', 'মেনু-অংশ স্ক্রল হয় (overflow-y: ' + nv.canScroll + ')');
    const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    fs.writeFileSync(path.join(os.tmpdir(), 'admin_nav_390.png'), Buffer.from(shot.data, 'base64'));
    await go('/admin/orders.php', 1326);
    await ev("document.querySelector('[data-group=system] .nav-group-toggle').click()"); await sleep(300);
    const shot2 = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    fs.writeFileSync(path.join(os.tmpdir(), 'admin_nav_1326.png'), Buffer.from(shot2.data, 'base64'));
    const hasLinks = await ev("[...document.querySelectorAll('.sidebar a')].every(a=>/\\/admin\\//.test(a.getAttribute('href')))");
    check(hasLinks, 'সব মেনু-লিংক /admin/ পাথে');
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { try { chrome && chrome.kill(); } catch {} for (const s of Object.values(S)) try { fs.unlinkSync(`${SESS_DIR}/sess_${s}`); } catch {} }
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
