/**
 * tools/check_info_align.js — মোবাইলে info-bar-এর alignment যাচাই
 * node tools/check_info_align.js [baseUrl]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://127.0.0.1:8000';
const PORT = Number(process.env.PORT_I || 9390);
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
  const sec = document.querySelector('.info-bar-section');
  if (!sec) return { missing: true };
  const sr = sec.getBoundingClientRect();
  const cs = getComputedStyle(sec);
  const boxes = [];
  sec.querySelectorAll('.info-box').forEach(function (b, i) {
    const r = b.getBoundingClientRect();
    const icon = b.querySelector('i');
    const ir = icon ? icon.getBoundingClientRect() : null;
    const txt = b.querySelector('div');
    const tr = txt ? txt.getBoundingClientRect() : null;
    boxes.push({
      i: i,
      boxLeft: Math.round(r.left),
      boxWidth: Math.round(r.width),
      justify: getComputedStyle(b).justifyContent,
      textAlign: getComputedStyle(b).textAlign,
      iconLeft: ir ? Math.round(ir.left) : null,
      iconRight: ir ? Math.round(ir.right) : null,
      textLeft: tr ? Math.round(tr.left) : null,
      textWidth: tr ? Math.round(tr.width) : null,
      gapAfterIcon: (ir && tr) ? Math.round(tr.left - ir.right) : null
    });
  });
  return {
    section: { left: Math.round(sr.left), width: Math.round(sr.width), cols: cs.gridTemplateColumns, gap: cs.gap },
    vw: document.documentElement.clientWidth,
    boxes: boxes
  };
})()`;

(async () => {
  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
  const out = [];
  const pass = (m) => out.push(['PASS', m]);
  const fail = (m) => out.push(['FAIL', m]);

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

    // ---- মোবাইল: বাম সারিবদ্ধ থাকা উচিত ----
    for (const w of [320, 375, 414]) {
      await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: w, height: 844, deviceScaleFactor: 2, mobile: true, screenWidth: w, screenHeight: 844 });
      await send(ws, id++, 'Page.navigate', { url: BASE + '/index.php' });
      await sleep(1800);
      await ev(`(async () => { document.querySelectorAll('.reveal').forEach(function(e){e.classList.add('is-visible');}); await new Promise(function(r){setTimeout(r,1200);}); return 1; })()`);
      await sleep(300);

      const r = await ev(PROBE);
      if (r.missing) { fail(`${w}px: info-bar-section নেই`); continue; }

      const cols = (r.section.cols || '').split(' ').filter(Boolean);
      if (cols.length === 1) pass(`${w}px: এক কলাম`);
      else fail(`${w}px: ${cols.length} কলাম — মোবাইলে ১ হওয়া উচিত`);

      r.boxes.forEach(function (b) {
        if (b.justify === 'flex-start' || b.justify === 'normal') pass(`${w}px box${b.i}: justify=${b.justify}`);
        else fail(`${w}px box${b.i}: justify=${b.justify} — flex-start হওয়া উচিত`);

        // আইকন কার্ডের বাম প্রান্তের কাছে আছে কি না
        const iconOffset = b.iconLeft - b.boxLeft;
        if (iconOffset >= -1 && iconOffset <= 6) pass(`${w}px box${b.i}: আইকন বাম প্রান্তে (offset=${iconOffset}px)`);
        else fail(`${w}px box${b.i}: আইকন বাম থেকে ${iconOffset}px দূরে`);
      });

      // সব আইকনের বাম প্রান্ত একই x-এ?
      const iconXs = r.boxes.map(function (b) { return b.iconLeft; });
      const sameX = iconXs.every(function (x) { return Math.abs(x - iconXs[0]) <= 1; });
      if (sameX) pass(`${w}px: সব আইকন একই x-এ (${iconXs[0]}px) — সুন্দর সারিবদ্ধ`);
      else fail(`${w}px: আইকনের x আলাদা ${JSON.stringify(iconXs)}`);
    }

    // ---- ডেস্কটপ: সেন্টার থাকা উচিত ----
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    await send(ws, id++, 'Page.navigate', { url: BASE + '/index.php' });
    await sleep(2000);
    await ev(`(async () => { document.querySelectorAll('.reveal').forEach(function(e){e.classList.add('is-visible');}); await new Promise(function(r){setTimeout(r,1200);}); return 1; })()`);
    await sleep(300);
    const d = await ev(PROBE);
    const dcols = (d.section.cols || '').split(' ').filter(Boolean);
    if (dcols.length === 3) pass(`desktop 1280px: ৩ কলাম`);
    else fail(`desktop 1280px: ${dcols.length} কলাম — ৩ হওয়া উচিত`);
    d.boxes.forEach(function (b) {
      if (b.justify === 'center') pass(`desktop box${b.i}: justify=center (অপরিবর্তিত)`);
      else fail(`desktop box${b.i}: justify=${b.justify} — center থাকা উচিত`);
    });

    ws.close();
  } catch (e) { fail('EXCEPTION: ' + e.message); }
  finally { chrome.kill(); }

  console.log('\n=== Info Bar Alignment ===\n');
  let f = 0;
  for (const [s, m] of out) { console.log((s === 'PASS' ? '  PASS  ' : '  FAIL  ') + m); if (s === 'FAIL') f++; }
  console.log(`\n${f === 0 ? 'ALL PASS' : f + ' FAILED'} (${out.length} checks)\n`);
  process.exit(f === 0 ? 0 : 1);
})();
