/**
 * tools/measure_mobile_margins.js — মোবাইলে প্রতিটি সেকশনের ডান-বাম ফাঁকা মাপে
 * node tools/measure_mobile_margins.js [baseUrl]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PAGE = process.argv[3] || '/index.php';
const PORT = Number(process.env.PORT_M || 9370);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

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
  const sel = [
    ['header',        '.main-header'],
    ['hero',          '.hero-grid-container'],
    ['categories',    '.categories-section'],
    ['products grid', '.products-grid-container, .product-grid'],
    ['section title', '.section-title-wrapper'],
    ['info bar',      '.info-bar-section'],
    ['payment',       '.payment-service-section'],
    ['footer box',    '.footer-glass-box'],
    ['bottom nav',    '.bottom-nav']
  ];
  const out = [];
  sel.forEach(function ([name, s]) {
    const el = document.querySelector(s);
    if (!el) { out.push({ name: name, missing: true }); return; }
    const r = el.getBoundingClientRect();
    out.push({
      name: name,
      left: Math.round(r.left),
      right: Math.round(vw - r.right),
      width: Math.round(r.width),
      padL: getComputedStyle(el).paddingLeft,
      padR: getComputedStyle(el).paddingRight
    });
  });
  // card ভেতরের কনটেন্ট
  const card = document.querySelector('.fruit-card');
  let cardInfo = null;
  if (card) {
    const cr = card.getBoundingClientRect();
    cardInfo = { left: Math.round(cr.left), right: Math.round(vw - cr.right), width: Math.round(cr.width) };
  }
  return { vw: vw, sections: out, firstCard: cardInfo };
})()`;

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

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    // বিভিন্ন মোবাইল প্রস্থ
    const widths = [320, 360, 390, 414];
    for (const w of widths) {
      await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: w, height: 844, deviceScaleFactor: 2, mobile: true, screenWidth: w, screenHeight: 844 });
      await send(ws, id++, 'Page.navigate', { url: BASE + PAGE });
      await sleep(2600);
      // reveal অ্যানিমেশন শেষ করে নইলে transform:scale() থাকায় মাপ ভুল আসে
      await ev(`(async () => {
        document.querySelectorAll('.reveal').forEach(function (e) { e.classList.add('is-visible'); });
        await new Promise(function (r) { setTimeout(r, 1300); });
        return 1;
      })()`);
      await sleep(500);
      const r = await ev(PROBE);
      console.log(`\n=== viewport ${w}px (clientWidth ${r.vw}) ===`);
      for (const s of r.sections) {
        if (s.missing) { console.log('  ' + s.name.padEnd(15) + ' নেই'); continue; }
        const flag = (s.left < 1) ? '  <-- স্পর্শ করছে' : '';
        console.log('  ' + s.name.padEnd(15) +
          'L=' + String(s.left).padStart(3) +
          '  R=' + String(s.right).padStart(3) +
          '  w=' + String(s.width).padStart(4) +
          '  pad=' + s.padL + '/' + s.padR + flag);
      }
      if (r.firstCard) console.log('  ' + 'first card'.padEnd(15) + 'L=' + String(r.firstCard.left).padStart(3) + '  R=' + String(r.firstCard.right).padStart(3) + '  w=' + r.firstCard.width);
    }

    ws.close();
  } catch (e) { console.error('ERROR:', e.message); }
  finally { chrome.kill(); }
  console.log('');
})();
