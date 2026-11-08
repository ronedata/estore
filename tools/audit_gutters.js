/**
 * tools/audit_gutters.js — সব পেজে মোবাইল gutter যাচাই
 * node tools/audit_gutters.js [baseUrl]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://127.0.0.1:8000';
const PORT = Number(process.env.PORT_A || 9380);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const PAGES = [
  '/index.php', '/shop.php', '/deals.php', '/wishlist.php', '/cart.php',
  '/checkout.php', '/about.php', '/contact.php', '/privacy_policy.php',
  '/terms.php', '/account.php', '/track_orders.php',
  '/product_details.php?id=55', '/category_products.php?id=1',
];

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

const PROBE = `(() => {
  const vw = document.documentElement.clientWidth;
  const out = [];
  // পেজের শীর্ষ-স্তরের দৃশ্যমান সেকশন/কার্ড
  const nodes = document.querySelectorAll(
    '.hero-grid-container, .categories-section, .products-grid-container, .product-grid, ' +
    '.section-title-wrapper, .info-bar-section, .payment-service-section, ' +
    '.page-title-bar, .pg-hero, .pg-wrap, .cart-list, .cart-summary, .card-box, ' +
    '.empty-state, .pd-wrap, .pd-gallery, .pd-info, .pg-card, ' +
    '.shop-toolbar, .pagination, .footer-glass-box, .success-card'
  );
  const seen = new Set();
  nodes.forEach(function (el) {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') return;
    const r = el.getBoundingClientRect();
    if (r.width < 20 || r.height < 5) return;
    // প্যারেন্ট-চেইনে কোনো nodenode ইতিমধ্যে মাপা হলে বাদ (ভেতরের নেস্টেড)
    if (seen.has(el)) return;

    const name = (el.className || '').toString().trim().split(/\\s+/)[0] || el.tagName.toLowerCase();
    const left = Math.round(r.left);
    const right = Math.round(vw - r.right);
    out.push({ name: name, left: left, right: right, w: Math.round(r.width) });
  });
  return { vw: vw, nodes: out };
})()`;

(async () => {
  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
  const problems = [];

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

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    for (const w of [320, 375, 414]) {
      await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: w, height: 844, deviceScaleFactor: 2, mobile: true, screenWidth: w, screenHeight: 844 });

      for (const page of PAGES) {
        await send(ws, id++, 'Page.navigate', { url: BASE + page });
        await sleep(1500);
        await ev(`(async () => {
          document.querySelectorAll('.reveal').forEach(function (e) { e.classList.add('is-visible'); });
          await new Promise(function (r) { setTimeout(r, 1100); });
          return 1;
        })()`);
        await sleep(250);

        const r = await ev(PROBE);
        const bad = r.nodes.filter(function (n) { return n.left < 10 || n.right < 10; });
        if (bad.length) {
          problems.push({ w: w, page: page, bad: bad });
        }
      }
    }

    ws.close();
  } catch (e) {
    console.error('ERROR:', e.message);
  } finally {
    chrome.kill();
  }

  if (!problems.length) {
    console.log('\n=== ALL GUTTERS OK (10px+) ===');
    console.log('checked ' + PAGES.length + ' pages × 3 widths = ' + (PAGES.length * 3) + ' combinations\n');
  } else {
    console.log('\n=== PROBLEMS ===\n');
    for (const p of problems) {
      console.log(`[${p.w}px] ${p.page}`);
      for (const b of p.bad) {
        console.log(`    ${b.name.padEnd(26)} L=${String(b.left).padStart(3)}  R=${String(b.right).padStart(3)}  w=${b.w}`);
      }
    }
    console.log(`\n${problems.length} page/width combos with tight gutters\n`);
  }
})();
