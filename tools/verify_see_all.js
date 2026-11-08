/**
 * tools/verify_see_all.js — "সব দেখুন" বাটন ও payment/info section-এর লেআউট যাচাই
 * node tools/verify_see_all.js [url]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_SA || 9461);
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

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    const measure = async (width) => {
      await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width <= 600 });
      await send(ws, id++, 'Page.navigate', { url: BASE + '/index.php' });
      await sleep(2600);
      await ev(`(async()=>{
        for (const sel of ['#featuredSection','.payment-service-section','.info-bar-section']) {
          const el = document.querySelector(sel);
          if (el) el.scrollIntoView({block:'center',behavior:'instant'});
          await new Promise(r=>setTimeout(r,200));
        }
        for (let i=0;i<80;i++){
          const secs=[...document.querySelectorAll('.reveal')];
          const done = secs.every(el=>{const c=getComputedStyle(el);const t=c.transform;
            return c.opacity==='1'&&(t==='none'||t==='matrix(1, 0, 0, 1, 0, 0)');});
          if (done) return 'ok';
          await new Promise(r=>setTimeout(r,100));
        }
        return 'timeout';
      })()`);
      await sleep(250);

      return await ev(`(() => {
        const out = {};

        // ---- "সব দেখুন" বাটন ----
        const btns = [...document.querySelectorAll('.section-see-all')];
        out.seeAllCount = btns.length;
        out.seeAll = btns.map(b => {
          const cs = getComputedStyle(b), r = b.getBoundingClientRect();
          return {
            text: b.textContent.trim().replace(/\\s+/g,' '),
            href: b.getAttribute('href'),
            h: Math.round(r.height), w: Math.round(r.width),
            radius: cs.borderRadius, bg: cs.backgroundImage || cs.backgroundColor,
            color: cs.color, fontSize: cs.fontSize, fontWeight: cs.fontWeight,
            hasIcon: !!b.querySelector('i'),
            title: (b.closest('section')?.querySelector('h2')?.textContent || '').trim(),
            belowGrid: (() => { const g = b.closest('section')?.querySelector('.products-grid-container'); return !!g && r.top >= g.getBoundingClientRect().bottom - 1; })(),
            centered: Math.abs((r.left + r.right) / 2 - window.innerWidth / 2) <= 2,
            inViewport: r.width > 0 && r.left >= 0 && r.right <= window.innerWidth + 1
          };
        });

        // ---- payment / info section ----
        const check = (sel) => {
          const s = document.querySelector(sel);
          if (!s) return null;
          const cs = getComputedStyle(s), r = s.getBoundingClientRect();
          const first = s.firstElementChild, last = s.lastElementChild;
          const fR = first ? first.getBoundingClientRect() : null;
          const lR = last ? last.getBoundingClientRect() : null;
          return {
            left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width),
            padL: parseFloat(cs.paddingLeft), padR: parseFloat(cs.paddingRight),
            padT: parseFloat(cs.paddingTop), padB: parseFloat(cs.paddingBottom),
            marTop: parseFloat(cs.marginTop), marBottom: parseFloat(cs.marginBottom),
            innerLeft: fR ? Math.round(fR.left - r.left) : null,
            innerRight: lR ? Math.round(r.right - lR.right) : null,
            overflowX: document.documentElement.scrollWidth > window.innerWidth + 1
          };
        };
        out.payment = check('.payment-service-section');
        out.infoBar = check('.info-bar-section');
        out.viewport = window.innerWidth;
        out.hScroll = document.documentElement.scrollWidth > window.innerWidth + 1;
        return out;
      })()`);
    };

    console.log('\n=== "সব দেখুন" ও Section Layout যাচাই ===\n');

    for (const w of [1440, 1024, 390]) {
      const d = await measure(w);
      console.log(`--- ${w}px ---`);

      if (d.seeAllCount >= 1) ok(`${d.seeAllCount}টি "সব দেখুন" বাটন আছে (সেকশন সংখ্যা ডাইনামিক)`);
      else bad('কোনো "সব দেখুন" বাটন নেই');

      for (const b of d.seeAll) {
        const label = b.title || b.href;
        if (b.belowGrid) ok(`${label}: বাটন প্রোডাক্ট গ্রিডের নিচে`);
        else bad(`${label}: বাটন গ্রিডের নিচে নেই`);
        if (b.centered) ok(`${label}: বাটন মাঝখানে`);
        else bad(`${label}: বাটন মাঝখানে নেই`);
        if (b.hasIcon) ok(`${label}: arrow icon আছে`);
        else bad(`${label}: icon নেই`);
        if (b.radius === '999px') ok(`${label}: pill shape (${b.radius})`);
        else bad(`${label}: radius ${b.radius}`);
        if (b.h >= 28) ok(`${label}: বাটন উচ্চতা ${b.h}px (ক্লিকযোগ্য)`);
        else bad(`${label}: উচ্চতা কম (${b.h}px)`);
        if (b.inViewport) ok(`${label}: viewport-এ আছে`);
        else bad(`${label}: viewport-এর বাইরে`);
      }

      for (const [name, s] of [['payment', d.payment], ['infoBar', d.infoBar]]) {
        if (!s) { bad(`${name} section পাওয়া যায়নি`); continue; }
        if (s.innerLeft >= 12) ok(`${name}: ভেতরের বাম padding ${s.innerLeft}px`);
        else bad(`${name}: ভেতরের বাম padding মাত্র ${s.innerLeft}px`);
        if (s.innerRight >= 12) ok(`${name}: ভেতরের ডান padding ${s.innerRight}px`);
        else bad(`${name}: ভেতরের ডান padding মাত্র ${s.innerRight}px`);
        if (s.padT >= 12) ok(`${name}: উপরে padding ${s.padT}px`);
        else bad(`${name}: উপরে padding কম (${s.padT}px)`);
        if (s.padB >= 12) ok(`${name}: নিচে padding ${s.padB}px`);
        else bad(`${name}: নিচে padding কম (${s.padB}px)`);
      }

      if (!d.hScroll) ok('horizontal overflow নেই');
      else bad('horizontal overflow আছে');
      console.log('');
    }

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }

  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
