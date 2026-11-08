/**
 * tools/verify_slider_edit.js — admin slider edit পেজে ইমেজ লোড হচ্ছে কি না
 * node tools/verify_slider_edit.js [baseUrl] [sliderId] [user] [pass]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://127.0.0.1:8000';
const SLIDER_ID = process.argv[3] || '4';
const USER = process.argv[4] || 'rone';
const PASS = process.argv[5] || '123456';
const PORT = Number(process.env.PORT_SL || 9350);
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
  const out = [];
  const pass = (m) => out.push(['PASS', m]);
  const fail = (m) => out.push(['FAIL', m]);

  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });

  try {
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools endpoint নেই');

    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });

    let id = 1;
    await send(ws, id++, 'Page.enable');
    await send(ws, id++, 'Runtime.enable');
    await send(ws, id++, 'Network.enable');

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    // ---- লগইন (ফর্ম সাবমিট, সেশন কুকি ব্রাউজারেই থাকে) ----
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/login.php` });
    await sleep(1800);

    const loginResult = await ev(`(async () => {
      const f = document.querySelector('form');
      if (!f) return { ok:false, reason:'login form নেই' };
      f.querySelector('input[name=username]').value = ${JSON.stringify(USER)};
      f.querySelector('input[name=password]').value = ${JSON.stringify(PASS)};
      f.submit();
      return { ok:true };
    })()`);
    if (!loginResult.ok) throw new Error(loginResult.reason);
    await sleep(2200);

    const afterLogin = await ev(`({ url: location.pathname, loggedIn: location.pathname.indexOf('/admin/') === 0 && location.pathname !== '/admin/login.php' })`);
    if (afterLogin.loggedIn) pass(`লগইন সফল → ${afterLogin.url}`);
    else fail(`লগইন ব্যর্থ → ${afterLogin.url}`);

    // ---- slider edit পেজ ----
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/slider_form.php?id=${SLIDER_ID}` });
    await sleep(2600);

    const imgs = await ev(`(() => {
      return Array.from(document.images).map(function (im) {
        return {
          src: im.getAttribute('src') || '',
          currentSrc: im.currentSrc || '',
          resolved: im.src,
          complete: im.complete,
          naturalW: im.naturalWidth,
          naturalH: im.naturalHeight,
          isFallback: /\\/uploads\\/default\\.jpg$/.test(im.src)
        };
      });
    })()`);

    if (!imgs.length) fail('পেজে কোনো <img> নেই');
    else pass(`পেজে ${imgs.length}টি <img> পাওয়া গেছে`);

    let broken = 0;
    for (const im of imgs) {
      const ok = im.complete && im.naturalW > 0;
      const name = im.resolved.replace(BASE, '');
      if (ok) pass(`লোড হয়েছে: ${im.naturalW}×${im.naturalH}  ${name}`);
      else { fail(`ভাঙা: ${name}  (complete=${im.complete}, w=${im.naturalW})`); broken++; }

      // relative path হলে admin/ থেকে ভাঙবে
      if (!/^(\/|https?:\/\/)/.test(im.src)) {
        fail(`relative src — /admin/ থেকে ভাঙবে: ${im.src}`);
      }
    }

    // ---- Network: ইমেজ রিকোয়েস্টের স্টেটাস ----
    const perf = await ev(`(async () => {
      const entries = performance.getEntriesByType('resource').filter(function (e) { return e.initiatorType === 'img'; });
      return entries.map(function (e) { return { name: e.name.replace(location.origin, ''), size: Math.round(e.transferSize), duration: Math.round(e.duration) }; });
    })()`);
    for (const p of perf) {
      if (p.size > 0) pass(`HTTP OK ${p.size}B  ${p.name}`);
      else fail(`HTTP ব্যর্থ (size=0) ${p.name}`);
    }

    ws.close();
  } catch (e) {
    fail(`EXCEPTION: ${e.message}`);
  } finally {
    chrome.kill();
  }

  console.log('\n=== Slider Edit Image Check ===');
  console.log(`URL: ${BASE}/admin/slider_form.php?id=${SLIDER_ID}\n`);
  let failed = 0;
  for (const [s, m] of out) {
    console.log((s === 'PASS' ? '  PASS  ' : '  FAIL  ') + m);
    if (s === 'FAIL') failed++;
  }
  console.log(`\n${failed === 0 ? 'ALL PASS' : failed + ' FAILED'} (${out.length} checks)\n`);
  process.exit(failed === 0 ? 0 : 1);
})();
