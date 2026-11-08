/**
 * tools/measure_payment_section.js — payment-service-section-এর বাস্তব box মাপে
 * node tools/measure_payment_section.js [url]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_P || 9455);
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
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    await send(ws, id++, 'Page.navigate', { url: BASE + '/index.php' });
    await sleep(3000);

    // reveal observer ট্রিগার করতে সেকশনে স্ক্রল করি, তারপর animation শেষ হওয়া পর্যন্ত অপেক্ষা
    await ev(`(async()=>{
      const el = document.querySelector('.payment-service-section');
      if (!el) return 'no-el';
      el.scrollIntoView({ block: 'center', behavior: 'instant' });
      await new Promise(r => setTimeout(r, 200));
      for (let i = 0; i < 80; i++) {
        const cs = getComputedStyle(el);
        const t = cs.transform;
        if (cs.opacity === '1' && (t === 'none' || t === 'matrix(1, 0, 0, 1, 0, 0)')) return 'settled';
        await new Promise(r => setTimeout(r, 100));
      }
      return 'timeout:' + getComputedStyle(el).transform;
    })()`).then((s) => { if (s !== 'settled') console.log('  (সতর্কতা: ' + s + ')'); });
    await sleep(300);

    const data = await ev(`(() => {
      const sec = document.querySelector('.payment-service-section');
      if (!sec) return { found: false };
      const cs = getComputedStyle(sec);
      const r  = sec.getBoundingClientRect();

      // সব পাশের section ও container-এর সাথে তুলনা
      const siblings = [];
      let p = sec.parentElement;
      while (p && p !== document.body) {
        const s = getComputedStyle(p);
        const pr = p.getBoundingClientRect();
        siblings.push({
          tag: p.tagName.toLowerCase() + (p.className ? '.' + String(p.className).trim().split(/\\s+/).slice(0,2).join('.') : ''),
          left: Math.round(pr.left), width: Math.round(pr.width),
          padL: s.paddingLeft, padR: s.paddingRight,
          marginL: s.marginLeft, marginR: s.marginRight
        });
        p = p.parentElement;
      }

      // তুলনার জন্য info-bar-section
      const info = document.querySelector('.info-bar-section');
      const infoR = info ? info.getBoundingClientRect() : null;
      // তুলনার জন্য একটি product grid container
      const grid = document.querySelector('.products-grid-container');
      const gridR = grid ? grid.getBoundingClientRect() : null;

      const firstChild = sec.firstElementChild;
      const lastChild  = sec.lastElementChild;
      const fcR = firstChild ? firstChild.getBoundingClientRect() : null;
      const lcR = lastChild ? lastChild.getBoundingClientRect() : null;

      return {
        found: true,
        viewport: window.innerWidth,
        section: {
          left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width),
          top: Math.round(r.top), height: Math.round(r.height),
          padding: cs.padding, margin: cs.margin,
          gap: cs.gap, borderRadius: cs.borderRadius
        },
        firstChildLeft: fcR ? Math.round(fcR.left) : null,
        lastChildRight: lcR ? Math.round(lcR.right) : null,
        innerPaddingLeft: fcR ? Math.round(fcR.left - r.left) : null,
        innerPaddingRight: lcR ? Math.round(r.right - lcR.right) : null,
        infoBar: infoR ? { left: Math.round(infoR.left), right: Math.round(infoR.right), width: Math.round(infoR.width) } : null,
        productGrid: gridR ? { left: Math.round(gridR.left), right: Math.round(gridR.right), width: Math.round(gridR.width) } : null,
        ancestors: siblings
      };
    })()`);

    console.log('\n=== Payment Service Section (desktop 1440px) ===\n');
    if (!data.found) { console.log('  section পাওয়া যায়নি'); process.exitCode = 1; }
    else {
      console.log('  section box  : left=' + data.section.left + ' right=' + data.section.right + ' width=' + data.section.width);
      console.log('  padding      : ' + data.section.padding);
      console.log('  margin       : ' + data.section.margin);
      console.log('  gap          : ' + data.section.gap);
      console.log('');
      console.log('  ভেতরের বাম ফাঁকা (first child → section edge) : ' + data.innerPaddingLeft + 'px');
      console.log('  ভেতরের ডান ফাঁকা (last child → section edge) : ' + data.innerPaddingRight + 'px');
      console.log('');
      if (data.infoBar)     console.log('  info-bar-section   : left=' + data.infoBar.left + ' right=' + data.infoBar.right + ' width=' + data.infoBar.width);
      if (data.productGrid) console.log('  products-grid      : left=' + data.productGrid.left + ' right=' + data.productGrid.right + ' width=' + data.productGrid.width);
      console.log('');
      console.log('  ancestor chain:');
      for (const a of data.ancestors) console.log('    ' + a.tag.padEnd(30) + ' left=' + a.left + ' width=' + a.width + ' padL=' + a.padL + ' padR=' + a.padR);
    }

    console.log('');
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }
})();
