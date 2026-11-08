/**
 * tools/shot_review.js — রিভিউ সেকশনের স্ক্রিনশট নেয় (প্রমাণের জন্য)
 * node tools/shot_review.js [url] [pid]
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PID = Number(process.argv[3] || 92);
const PORT = Number(process.env.PORT_SHOT || 9541);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const OUT = process.env.SHOT_DIR || path.join(require('os').tmpdir(), 'kilo', 'shot');
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
  if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });
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
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 1280, height: 1000, deviceScaleFactor: 1, mobile: false });
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/product_details.php?id=${PID}` });
    await sleep(2600);

    // Reviews ট্যাব খুলি
    await send(ws, id++, 'Runtime.evaluate', {
      expression: `document.querySelector('.pd-tabs button[data-tab="reviews"]')?.click()`,
      returnByValue: true
    });
    await sleep(700);

    // রিভিউ কার্ডে স্ক্রল
    await send(ws, id++, 'Runtime.evaluate', {
      expression: `document.getElementById('tab-reviews')?.scrollIntoView({block:'start',behavior:'instant'})`,
      returnByValue: true
    });
    await sleep(900);

    const shotPanel = await send(ws, id++, 'Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(path.join(OUT, 'review_panel.png'), Buffer.from(shotPanel.data, 'base64'));

    // পূর্ণ পেজ
    const { cssContentSize } = await send(ws, id++, 'Page.getLayoutMetrics');
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', {
      width: 1280, height: Math.min(Math.ceil(cssContentSize.height), 4000), deviceScaleFactor: 1, mobile: false
    });
    await sleep(600);
    const shotFull = await send(ws, id++, 'Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    fs.writeFileSync(path.join(OUT, 'review_full.png'), Buffer.from(shotFull.data, 'base64'));

    console.log('screenshot panel: ' + path.join(OUT, 'review_panel.png'));
    console.log('screenshot full : ' + path.join(OUT, 'review_full.png'));
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }
})();
