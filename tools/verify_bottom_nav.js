/**
 * tools/verify_bottom_nav.js — মোবাইলে bottom-nav-এর সম্পূর্ণ যাচাই
 * node tools/verify_bottom_nav.js [url]
 */
const { spawn } = require('child_process');
const URL_TO_TEST = process.argv[2] || 'http://127.0.0.1:8000/index.php';
const PORT = Number(process.env.NAV_PORT || 9338);
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
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

const METRICS = `(() => {
  const nav = document.querySelector('.bottom-nav');
  if (!nav) return { found:false };
  const cs = getComputedStyle(nav);
  const r = nav.getBoundingClientRect();
  const de = document.documentElement;
  return {
    found: true,
    position: cs.position, display: cs.display, zIndex: parseInt(cs.zIndex,10)||0,
    backdrop: (cs.backdropFilter && cs.backdropFilter !== 'none') ? cs.backdropFilter : (cs.webkitBackdropFilter && cs.webkitBackdropFilter !== 'none' ? cs.webkitBackdropFilter : 'none'),
    top:+r.top.toFixed(1), bottom:+r.bottom.toFixed(1),
    w: Math.round(r.width), h: Math.round(r.height),
    vw: window.innerWidth, vh: window.innerHeight,
    clientW: de.clientWidth, scrollW: de.scrollWidth,
    scrollY: Math.round(window.scrollY),
    maxScroll: Math.round(de.scrollHeight - window.innerHeight),
    navCount: document.querySelectorAll('.bottom-nav').length
  };
})()`;

(async () => {
  const results = [];
  const pass = (m) => results.push({ ok: true, msg: m });
  const fail = (m) => results.push({ ok: false, msg: m });

  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });

  try {
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('Chrome DevTools endpoint খুলছে না');

    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });

    let id = 1;
    await send(ws, id++, 'Page.enable');
    await send(ws, id++, 'Runtime.enable');
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 3, mobile: true, screenWidth: 390, screenHeight: 844 });

    await send(ws, id++, 'Page.navigate', { url: URL_TO_TEST });
    await sleep(3000);

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    const m0 = await ev(METRICS);
    if (!m0.found) throw new Error('.bottom-nav DOM-এ নেই');

    // ---- Horizontal overflow (মূল কারণ) ----
    if (m0.vw === 390) pass(`innerWidth=390 (জুম-আউট হচ্ছে না)`);
    else fail(`innerWidth=${m0.vw} — layout viewport ফুলে গেছে (horizontal overflow)`);

    if (m0.scrollW <= m0.clientW + 1) pass(`horizontal overflow নেই (scrollWidth=${m0.scrollW}, clientWidth=${m0.clientW})`);
    else fail(`horizontal overflow ${m0.scrollW - m0.clientW}px`);

    // ---- Desktop breakpoint যেন ভুল না ধরে ----
    if (m0.display !== 'none') pass(`mobile-এ দৃশ্যমান (display=${m0.display})`);
    else fail('mobile-এ display:none');

    if (m0.position === 'fixed') pass('position: fixed');
    else fail(`position=${m0.position}`);

    if (Math.abs(m0.bottom - m0.vh) <= 2) pass(`scrollTop=0 → bottom=${m0.bottom}px = viewport ${m0.vh}px`);
    else fail(`scrollTop=0 → bottom=${m0.bottom}px, viewport=${m0.vh}px`);

    if (m0.w >= m0.vw - 2) pass(`width=${m0.w}px — পুরো প্রস্থ`);
    else fail(`width=${m0.w}px < viewport ${m0.vw}px`);

    if (m0.zIndex >= 900) pass(`z-index=${m0.zIndex}`);
    else fail(`z-index=${m0.zIndex}`);

    if (m0.navCount === 1) pass('.bottom-nav ঠিক ১টি');
    else fail(`.bottom-nav ${m0.navCount}টি`);

    if (m0.backdrop === 'none') pass('backdrop-filter নেই (Android fixed-bug এড়ানো)');
    else fail(`backdrop-filter=${m0.backdrop}`);

    // ---- স্ক্রল টেস্ট: প্রতিটি পজিশনে nav একই জায়গায়? ----
    const positions = [0, 200, 600, 1200, Math.max(0, m0.maxScroll - 10), m0.maxScroll];
    for (const y of positions) {
      await ev(`(() => { document.documentElement.style.scrollBehavior='auto'; window.scrollTo(0, ${y}); return 1; })()`);
      await sleep(320);
      const m = await ev(METRICS);
      const bottomOk = Math.abs(m.bottom - m.vh) <= 2;
      const topOk = Math.abs(m.top - (m.vh - m.h)) <= 3;
      if (bottomOk && topOk && m.display !== 'none') pass(`scrollY=${m.scrollY}/${m.maxScroll} → top=${m.top}, bottom=${m.bottom} (আটকে আছে)`);
      else fail(`scrollY=${m.scrollY}/${m.maxScroll} → top=${m.top}, bottom=${m.bottom}, viewport=${m.vh}`);
    }

    // ---- Desktop-এ লুকানো ----
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 1024, height: 768, deviceScaleFactor: 1, mobile: false, screenWidth: 1024, screenHeight: 768 });
    await sleep(600);
    const d = await ev(METRICS);
    if (d.display === 'none') pass('1024px → display:none');
    else fail(`1024px → display=${d.display}`);

    // ---- 다른 পেজেও একই আচরণ ----
    for (const p of ['/shop.php', '/cart.php', '/about.php', '/contact.php']) {
      await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 3, mobile: true, screenWidth: 390, screenHeight: 844 });
      await send(ws, id++, 'Page.navigate', { url: new URL(p, URL_TO_TEST).href });
      await sleep(2200);
      const m = await ev(METRICS);
      if (!m.found) { fail(`${p}: nav নেই`); continue; }
      const ok = m.vw === 390 && m.scrollW <= m.clientW + 1 && Math.abs(m.bottom - m.vh) <= 2 && m.display !== 'none';
      if (ok) pass(`${p}: translucent-free, overflow-free, bottom=${m.bottom}/${m.vh}`);
      else fail(`${p}: vw=${m.vw}, scrollW=${m.scrollW}, clientW=${m.clientW}, bottom=${m.bottom}/${m.vh}, display=${m.display}`);
    }

    ws.close();
  } catch (e) {
    fail(`EXCEPTION: ${e.message}`);
  } finally {
    chrome.kill();
  }

  console.log('\n=== Bottom Nav Verification ===');
  console.log('URL:', URL_TO_TEST, '\n');
  let failed = 0;
  for (const r of results) {
    console.log((r.ok ? '  PASS  ' : '  FAIL  ') + r.msg);
    if (!r.ok) failed++;
  }
  console.log(`\n${failed === 0 ? 'ALL PASS' : failed + ' FAILED'} (${results.length} checks)\n`);
  process.exit(failed === 0 ? 0 : 1);
})();
