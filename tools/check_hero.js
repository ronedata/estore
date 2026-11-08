/**
 * tools/check_hero.js — homepage hero/side slider রেন্ডার যাচাই
 * node tools/check_hero.js [baseUrl]
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_H || 9361);
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
  const out = { hero: [], side: [], swiper: {} };
  document.querySelectorAll('.hero-card-content').forEach(function (el, i) {
    const r = el.getBoundingClientRect();
    const im = el.querySelector('.hero-img img');
    out.hero.push({
      i: i,
      box: Math.round(r.width) + 'x' + Math.round(r.height),
      cls: el.className,
      imgNatural: im ? im.naturalWidth + 'x' + im.naturalHeight : null,
      imgRendered: im ? Math.round(im.getBoundingClientRect().width) + 'x' + Math.round(im.getBoundingClientRect().height) : null,
      imgComplete: im ? im.complete : null,
      imgSrc: im ? im.getAttribute('src') : null,
      imgObjectFit: im ? getComputedStyle(im).objectFit : null,
      ratio: im ? (im.naturalWidth / im.naturalHeight).toFixed(2) : null,
      boxRatio: (r.width / r.height).toFixed(2)
    });
  });
  document.querySelectorAll('.side-card').forEach(function (el, i) {
    const r = el.getBoundingClientRect();
    const im = el.querySelector('.side-img img');
    out.side.push({
      i: i,
      box: Math.round(r.width) + 'x' + Math.round(r.height),
      imgNatural: im ? im.naturalWidth + 'x' + im.naturalHeight : null,
      imgComplete: im ? im.complete : null,
      imgSrc: im ? im.getAttribute('src') : null,
      ratio: im && im.naturalHeight ? (im.naturalWidth / im.naturalHeight).toFixed(2) : null
    });
  });
  const sw = document.querySelector('.hero-swiper');
  out.swiper = {
    found: !!sw,
    slides: document.querySelectorAll('.hero-swiper .swiper-slide').length,
    initialized: sw ? sw.classList.contains('swiper-initialized') : false,
    wrapperW: sw ? Math.round(sw.querySelector('.swiper-wrapper').getBoundingClientRect().width) : null,
    sliderH: sw ? Math.round(sw.getBoundingClientRect().height) : null,
    swiperGlobal: typeof window.Swiper
  };
  return out;
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
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    await send(ws, id++, 'Page.navigate', { url: `${BASE}/index.php` });
    await sleep(4000);

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    console.log('\n=== HERO RENDER ===');
    console.log(JSON.stringify(await ev(PROBE), null, 2));

    const shot = await send(ws, id++, 'Page.captureScreenshot', { format: 'png' });
    const dir = 'C:\\Users\\rone\\AppData\\Local\\Temp\\kilo\\shot';
    fs.mkdirSync(dir, { recursive: true });
    const f = path.join(dir, 'hero_desktop.png');
    fs.writeFileSync(f, Buffer.from(shot.data, 'base64'));
    console.log('\nscreenshot: ' + f);

    ws.close();
  } catch (e) { console.error('ERROR:', e.message); }
  finally { chrome.kill(); }
  console.log('');
})();
