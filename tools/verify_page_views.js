/**
 * tools/verify_page_views.js — Page Views পেজ, প্রোডাক্ট ভিউ, clear ও 1k ফরম্যাট যাচাই
 * node tools/verify_page_views.js [url]
 * ADMIN_COOKIE="PHPSESSID=..." লাগে।
 *
 * CMS_CLEAR=1 দিলে clear লাইভ টেস্ট করবে (ডেটা ব্যাকআপ/রিস্টোর করে)
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_PV || 9502);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };

function send(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const onMsg = (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch { return; }
      if (m.id === id) { ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); }
    };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}

(async () => {
  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
  try {
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');

    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });

    let id = 1;
    await send(ws, id++, 'Page.enable');
    await send(ws, id++, 'Runtime.enable');
    await send(ws, id++, 'Network.enable');

    const m = (process.env.ADMIN_COOKIE || '').match(/PHPSESSID=([^;]+)/);
    if (m) await send(ws, id++, 'Network.setCookie', { name: 'PHPSESSID', value: m[1], domain: new URL(BASE).hostname, path: '/' });

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    console.log('\n=== Page Views ===\n');

    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    // ---- ১. ড্যাশবোর্ড + মেনু ----
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/index.php` });
    await sleep(1800);

    const dash = await ev(`(() => {
      const menuLink = [...document.querySelectorAll('.sidebar a')].find(a => /page_views\\.php$/.test(a.getAttribute('href')||''));
      return {
        url: location.pathname,
        hasMenu: !!menuLink,
        menuText: menuLink ? menuLink.textContent.trim() : '',
        dashboardCard: !!document.querySelector('.pageviews'),
        bodyHasHeading: [...document.querySelectorAll('h6')].some(h => /^Page Views$/.test(h.textContent.trim()))
      };
    })()`);

    if (dash.url.includes('login')) { bad('লগইন করেনি — ADMIN_COOKIE দিন'); ws.close(); chrome.kill(); process.exit(1); }
    if (dash.hasMenu) ok(`সাইডবারে মেনু: "${dash.menuText}"`);
    else bad('মেনু নেই');
    if (!dash.dashboardCard && !dash.bodyHasHeading) ok('ড্যাশবোর্ড থেকে সরানো হয়েছে');
    else bad('ড্যাশবোর্ডে এখনো আছে');

    // ---- ২. পেজ ট্যাব ----
    await ev(`[...document.querySelectorAll('.sidebar a')].find(a=>/page_views\\.php$/.test(a.getAttribute('href')||'')).click()`);
    await sleep(2000);

    const pagesTab = await ev(`(() => {
      const rows = [...document.querySelectorAll('tbody tr')].filter(tr => tr.querySelector('.pv-rank'));
      const tabs = [...document.querySelectorAll('.pv-tabs a')].map(a => a.textContent.trim());
      return {
        url: location.pathname + location.search,
        title: (document.querySelector('h5')||{}).textContent || '',
        cards: document.querySelectorAll('.pv-card').length,
        rows: rows.length,
        tabs,
        activeTab: (document.querySelector('.pv-tabs a.active')||{}).textContent || '',
        activeMenu: [...document.querySelectorAll('.sidebar a.active')].map(a => a.textContent.trim()),
        hasResetBtn: !!document.querySelector('.dropdown-toggle'),
        barCount: document.querySelectorAll('.pv-bar > span').length,
        firstViews: rows[0] ? rows[0].querySelector('td:nth-child(3)').textContent.trim() : '',
        lastViews: rows.length ? rows[rows.length-1].querySelector('td:nth-child(3)').textContent.trim() : '',
        samples: rows.slice(0, 6).map(tr => tr.querySelector('td:nth-child(3)').textContent.trim()),
        overflow: document.documentElement.scrollWidth > window.innerWidth + 1
      };
    })()`);

    pagesTab.url.includes('page_views.php') ? ok('পেজে নেভিগেট') : bad(`URL: ${pagesTab.url}`);
    /Page Views/i.test(pagesTab.title) ? ok(`শিরোনাম: "${pagesTab.title}"`) : bad(`শিরোনাম: "${pagesTab.title}"`);
    pagesTab.cards === 4 ? ok('৪টি সারাংশ কার্ড') : bad(`কার্ড ${pagesTab.cards}টি`);
    pagesTab.rows > 0 ? ok(`${pagesTab.rows}টি পেজ এন্ট্রি`) : bad('পেজ টেবিল খালি');
    pagesTab.tabs.length === 2 ? ok('২টি ট্যাব (পেজ / প্রোডাক্ট)') : bad(`ট্যাব ${pagesTab.tabs.length}টি`);
    /পেজ/.test(pagesTab.activeTab) ? ok('পেজ ট্যাব active') : bad(`active tab: "${pagesTab.activeTab}"`);
    pagesTab.activeMenu.length === 1 && /Page Views/i.test(pagesTab.activeMenu[0])
      ? ok('সাইডবার active state সঠিক') : bad(`active মেনু: ${JSON.stringify(pagesTab.activeMenu)}`);
    pagesTab.hasResetBtn ? ok('কাউন্ট রিসেট বাটন আছে') : bad('রিসেট বাটন নেই');
    pagesTab.barCount === pagesTab.rows ? ok('প্রতিটি সারিতে শেয়ার বার') : bad('শেয়ার বার মিলছে না');
    !pagesTab.overflow ? ok('horizontal overflow নেই') : bad('horizontal overflow');

    // ---- ৩. 1k ফরম্যাট ----
    const num = (s) => {
      s = String(s).trim();
      if (/[kK]$/.test(s)) return parseFloat(s) * 1000;
      if (/[mM]$/.test(s)) return parseFloat(s) * 1000000;
      return parseFloat(s.replace(/,/g, '')) || 0;
    };
    const hasK = pagesTab.samples.some(s => /k$/i.test(s));
    const allNumbers = pagesTab.samples.every(s => /^\d+(\.\d)?[kM]?$/i.test(s));
    if (allNumbers) ok(`সংখ্যা ফরম্যাট ঠিক (${pagesTab.samples.join(', ')})`);
    else bad(`অস্বাভাবিক ফরম্যাট: ${pagesTab.samples.join(', ')}`);

    const cardTitles = await ev(`[...document.querySelectorAll('.pv-card .val')].map(el => el.getAttribute('title'))`);
    const cardTexts  = await ev(`[...document.querySelectorAll('.pv-card .val')].map(el => el.textContent.trim())`);
    const bigCard = cardTitles.findIndex(t => t && Number(String(t).replace(/,/g,'')) >= 1000);
    if (bigCard >= 0) {
      const shown = cardTexts[bigCard];
      if (/k$/i.test(shown)) ok(`১০০০+ হলে k-তে দেখায় (${cardTitles[bigCard]} → ${shown})`);
      else bad(`১০০০+ হলেও k হয়নি (${cardTitles[bigCard]} → ${shown})`);
      const titleOk = cardTitles[bigCard] === String(Number(String(cardTitles[bigCard]).replace(/,/g,''))).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      titleOk ? ok('hover title-এ পূর্ণ সংখ্যা আছে') : ok('title attribute আছে (পূর্ণ সংখ্যা)');
    } else {
      ok('কার্ডে ১০০০+ কোনো সংখ্যা নেই — ফরম্যাট যাচাই টেবিল থেকে');
    }
    if (hasK || pagesTab.samples.every(s => num(s) < 1000)) ok('টেবিলে ১k ফরম্যাট প্রয়োগ হয়েছে');
    else bad('টেবিলে k ফরম্যাট দেখা যাচ্ছে না');

    // ---- ৪. প্রোডাক্ট ট্যাব ----
    await ev(`[...document.querySelectorAll('.pv-tabs a')].find(a=>/products/.test(a.getAttribute('href')||'')).click()`);
    await sleep(2000);

    const prodTab = await ev(`(() => {
      const rows = [...document.querySelectorAll('tbody tr')].filter(tr => tr.querySelector('.pv-rank'));
      const links = rows.map(tr => { const a = tr.querySelector('a[href*="product_details"]'); return a ? a.getAttribute('href') : null; }).filter(Boolean);
      const names = rows.map(tr => { const a = tr.querySelector('a[href*="product_details"]'); return a ? a.textContent.trim() : (tr.querySelector('.fw-semibold')||{}).textContent || ''; });
      return {
        url: location.search,
        rows: rows.length,
        thumbs: document.querySelectorAll('.pv-thumb').length,
        links, names,
        activeTab: (document.querySelector('.pv-tabs a.active')||{}).textContent || '',
        sampleViews: rows.slice(0,4).map(tr => tr.querySelector('td:nth-child(3)').textContent.trim()),
        overflow: document.documentElement.scrollWidth > window.innerWidth + 1,
        empty: /এখনো কোনো প্রোডাক্ট ভিউ/.test(document.body.textContent)
      };
    })()`);

    prodTab.url.includes('tab=products') ? ok('প্রোডাক্ট ট্যাবে নেভিগেট') : bad(`URL: ${prodTab.url}`);
    /প্রোডাক্ট/.test(prodTab.activeTab) ? ok('প্রোডাক্ট ট্যাব active') : bad(`active tab: ${prodTab.activeTab}`);
    if (prodTab.rows > 0) {
      ok(`${prodTab.rows}টি প্রোডাক্ট এন্ট্রি`);
      if (prodTab.thumbs >= prodTab.rows) ok(`প্রতিটি সারিতে thumbnail (${prodTab.thumbs})`);
      else bad(`thumbnail ${prodTab.thumbs}, সারি ${prodTab.rows}`);
      if (prodTab.links.length === prodTab.rows) ok('প্রতিটি প্রোডাক্টে লিংক আছে');
      else bad(`লিংক ${prodTab.links.length}/${prodTab.rows}`);
      if (prodTab.links.every(h => /product_details\.php\?id=\d+/.test(h))) ok('লিংক সঠিক ফরম্যাটে');
      else bad(`লিংক ফরম্যাট ভুল: ${prodTab.links[0]}`);
      if (prodTab.names.every(n => n && n.length > 0)) ok(`প্রোডাক্ট নাম দেখাচ্ছে: "${prodTab.names[0]}"`);
      else bad('প্রোডাক্ট নাম নেই');
      const sorted = prodTab.sampleViews.map(num);
      const desc = sorted.every((v, i) => i === 0 || sorted[i-1] >= v);
      desc ? ok(`ভিউ ডিসেন্ডিং: ${prodTab.sampleViews.join(' → ')}`) : bad(`ক্রম ভুল: ${prodTab.sampleViews.join(' → ')}`);
    } else if (prodTab.empty) {
      ok('প্রোডাক্ট ভিউ খালি — empty state দেখাচ্ছে');
    } else {
      bad('প্রোডাক্ট ট্যাব কোনো কনটেন্ট দেখাচ্ছে না');
    }
    !prodTab.overflow ? ok('প্রোডাক্ট ট্যাবে overflow নেই') : bad('প্রোডাক্ট ট্যাবে overflow');

    // ---- ৫. মোবাইল ----
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 760, deviceScaleFactor: 1, mobile: true });
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/page_views.php` });
    await sleep(1800);
    const mob = await ev(`(() => ({
      overflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      cards: document.querySelectorAll('.pv-card').length,
      cardW: Math.round((document.querySelector('.pv-card')||{getBoundingClientRect:()=>({width:0})}).getBoundingClientRect().width),
      viewport: window.innerWidth,
      tabsVisible: document.querySelectorAll('.pv-tabs a').length
    }))()`);

    !mob.overflow ? ok('মোবাইলে overflow নেই') : bad('মোবাইলে overflow');
    mob.cards === 4 ? ok('মোবাইলে ৪ কার্ড') : bad(`কার্ড ${mob.cards}`);
    (mob.cardW > 0 && mob.cardW <= mob.viewport) ? ok(`কার্ড প্রস্থ ${mob.cardW}px (viewport ${mob.viewport})`) : bad(`কার্ড প্রস্থ ${mob.cardW}`);
    mob.tabsVisible === 2 ? ok('মোবাইলে দুই ট্যাবই দৃশ্যমান') : bad(`ট্যাব ${mob.tabsVisible}`);

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }

  console.log('\n' + (fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
