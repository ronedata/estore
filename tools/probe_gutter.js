/**
 * tools/probe_gutter.js — নির্দিষ্ট এলিমেন্টের gutter কোথা থেকে আসছে
 * node tools/probe_gutter.js [baseUrl] [selector]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const SEL = process.argv[3] || '.info-bar-section';
const PAGE = process.argv[4] || '/index.php';
const PORT = Number(process.env.PORT_G || 9372);
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
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true, screenWidth: 390, screenHeight: 844 });
    await send(ws, id++, 'Page.navigate', { url: BASE + PAGE });
    await sleep(2800);

    const ev = async (e) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    // reveal animation সম্পূর্ণ শেষ করে মাপ নিই
    await ev(`(async () => {
      document.querySelectorAll('.reveal').forEach(function (e) { e.classList.add('is-visible'); });
      await new Promise(function (r) { setTimeout(r, 1400); });
      return 1;
    })()`);
    await sleep(600);

    const r = await ev(`(() => {
      const el = document.querySelector('${SEL}');
      if (!el) return { missing: true };
      const vw = document.documentElement.clientWidth;
      const chain = [];
      let n = el;
      while (n && n !== document.documentElement) {
        const cs = getComputedStyle(n), b = n.getBoundingClientRect();
        chain.push({
          tag: n.tagName.toLowerCase(),
          cls: (n.className || '').toString().slice(0, 42),
          left: +b.left.toFixed(1),
          right: +(vw - b.right).toFixed(1),
          w: +b.width.toFixed(1),
          padL: cs.paddingLeft, padR: cs.paddingRight,
          marL: cs.marginLeft, marR: cs.marginRight,
          borderL: cs.borderLeftWidth, borderR: cs.borderRightWidth,
          transform: cs.transform, opacity: cs.opacity
        });
        n = n.parentElement;
      }
      return { vw: vw, chain: chain };
    })()`);

    console.log(JSON.stringify(r, null, 2));
    ws.close();
  } catch (e) { console.error('ERR', e.message); }
  finally { chrome.kill(); }
})();
