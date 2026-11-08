/**
 * tools/verify_admin_sidebar.js — admin sidebar scroll/overflow যাচাই
 * node tools/verify_admin_sidebar.js [url]
 * ADMIN_COOKIE="PHPSESSID=..." দিলে লগইন ছাড়াও মাপা যাবে।
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_SB || 9494);
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

    const cookie = process.env.ADMIN_COOKIE || '';
    const m = cookie.match(/PHPSESSID=([^;]+)/);
    if (m) {
      await send(ws, id++, 'Network.setCookie', { name: 'PHPSESSID', value: m[1], domain: new URL(BASE).hostname, path: '/' });
    }

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    console.log('\n=== Admin Sidebar Scroll যাচাই ===\n');

    for (const [w, h] of [[1440, 900], [1440, 600], [1280, 520], [390, 720]]) {
      await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w <= 992 });
      await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/index.php` });
      await sleep(1800);

      const d = await ev(`(() => {
        const sb = document.querySelector('.sidebar');
        if (!sb) return { found: false };
        const cs = getComputedStyle(sb);
        const navy = sb.querySelector('.navy') || sb;
        const ncs = getComputedStyle(navy);
        const logout = [...sb.querySelectorAll('a')].find(a => /logout/i.test(a.getAttribute('href')||''));
        const r = sb.getBoundingClientRect();

        // যে এলিমেন্টটি আসলে স্ক্রল করে
        const scroller = (ncs.overflowY === 'auto' || ncs.overflowY === 'scroll') ? navy : sb;
        const scs = getComputedStyle(scroller);

        const before = scroller.scrollTop;
        scroller.scrollTop = scroller.scrollHeight;
        const after = scroller.scrollTop;
        const maxScroll = scroller.scrollHeight - scroller.clientHeight;
        const canScroll = after > before + 1;

        const lr = logout ? logout.getBoundingClientRect() : null;
        return {
          found: true,
          sidebarOverflowY: cs.overflowY,
          scrollerTag: scroller === navy ? '.navy' : '.sidebar',
          overflowY: scs.overflowY,
          height: Math.round(r.height),
          clientH: scroller.clientHeight,
          scrollH: scroller.scrollHeight,
          maxScroll: Math.round(maxScroll),
          canScroll,
          maxReached: Math.round(after),
          logoutVisibleAfterScroll: lr ? (lr.bottom <= window.innerHeight + 2 && lr.top >= -2) : null,
          logoutExists: !!logout,
          linkCount: sb.querySelectorAll('.navy a').length,
          viewportH: window.innerHeight
        };
      })()`);

      console.log(`--- ${w}×${h} ---`);
      if (!d.found) { bad('sidebar পাওয়া যায়নি (লগইন প্রয়োজন?)'); console.log(''); continue; }

      if (d.overflowY === 'auto' || d.overflowY === 'scroll') ok(`${d.scrollerTag}-এ overflow-y: ${d.overflowY}`);
      else bad(`${d.scrollerTag}-এ overflow-y: ${d.overflowY} — স্ক্রল হবে না`);

      ok(`links ${d.linkCount}, clientH=${d.clientH}, scrollH=${d.scrollH}`);

      if (d.maxScroll <= 1) {
        ok('কনটেন্ট ফিট করে — স্ক্রলে সব দেখা যায়');
      } else if (d.canScroll) {
        ok(`স্ক্রল কাজ করে (${d.maxScroll}px অতিরিক্ত কনটেন্ট)`);
      } else {
        bad(`স্ক্রলে ${d.maxScroll}px কনটেন্ট কাটা পড়ছে`);
      }

      if (d.logoutExists) {
        if (d.logoutVisibleAfterScroll) ok('স্ক্রল করে লগআউট বাটনে পৌঁছানো যায়');
        else bad('লগআউট বাটনে পৌঁছানো যায় না');
      } else {
        bad('লগআউট লিংক পাওয়া যায়নি');
      }

      console.log('');
    }

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }

  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
