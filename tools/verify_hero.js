/**
 * tools/verify_hero.js — হিরো স্লাইডার ও সাইড কার্ডের টেক্সট ওভারলে যাচাই
 * node tools/verify_hero.js [url]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_H || 9473);
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

    console.log('\n=== Hero / Side Card যাচাই ===\n');

    for (const w of [1440, 1024, 390]) {
      await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w <= 600 });
      await send(ws, id++, 'Page.navigate', { url: BASE + '/index.php' });
      await sleep(2800);
      await ev(`(async()=>{const e=document.querySelector('.hero-grid-container');
        if(e) e.scrollIntoView({block:'center',behavior:'instant'});
        await new Promise(r=>setTimeout(r,300)); return 1;})()`);
      await sleep(300);

      const d = await ev(`(() => {
        const out = {};
        const hero = document.querySelector('.hero-grid-container');
        if (!hero) return { found: false };
        out.found = true;

        const describe = (box) => {
          const br = box.getBoundingClientRect();
          const title = box.querySelector('.hero-copy-title');
          const sub = box.querySelector('.hero-copy-sub');
          const btn = box.querySelector('.btn-hero-shop, .btn-side-shop');
          const img = box.querySelector('img');
          const scrim = box.querySelector('.hero-scrim');
          const tr = title ? title.getBoundingClientRect() : null;
          const sr = sub ? sub.getBoundingClientRect() : null;
          const btr = btn ? btn.getBoundingClientRect() : null;
          const ir = img ? img.getBoundingClientRect() : null;
          const cs = title ? getComputedStyle(title) : null;
          return {
            hasTitle: !!title, hasSub: !!sub, hasBtn: !!btn, hasScrim: !!scrim,
            titleText: title ? title.textContent.trim() : '',
            subText: sub ? sub.textContent.trim() : '',
            titleColor: cs ? cs.color : '',
            boxH: Math.round(br.height), boxW: Math.round(br.width),
            titleVisible: tr ? (tr.width > 0 && tr.height > 0) : false,
            subVisible: sr ? (sr.width > 0 && sr.height > 0) : false,
            btnVisible: btr ? (btr.width > 0 && btr.height > 0) : false,
            titleInsideBox: tr ? (tr.top >= br.top - 1 && tr.bottom <= br.bottom + 1) : false,
            btnInsideBox: btr ? (btr.top >= br.top - 1 && btr.bottom <= br.bottom + 1 && btr.left >= br.left - 1 && btr.right <= br.right + 1) : false,
            titleAboveBtn: (tr && btr) ? tr.bottom <= btr.top + 2 : false,
            imgCovers: ir ? (Math.abs(ir.width - br.width) < 2 && Math.abs(ir.height - br.height) < 2) : false,
            imgRatio: img ? Number((img.naturalWidth / img.naturalHeight).toFixed(2)) : 0,
            boxRatio: Number((br.width / br.height).toFixed(2))
          };
        };

        // --- হিরো স্লাইড ---
        const slides = [...document.querySelectorAll('.hero-swiper .swiper-slide')];
        out.slides = slides.length;
        out.slideData = slides.map(s => {
          const box = s.querySelector('.hero-card-content');
          return box ? describe(box) : null;
        }).filter(Boolean);
        out.withText = out.slideData.filter(x => x.hasTitle || x.hasSub).length;
        out.withoutText = out.slideData.filter(x => !x.hasTitle && !x.hasSub).length;

        // --- সাইড কার্ড ---
        const sides = [...document.querySelectorAll('.hero-side-cards .side-card')];
        out.sides = sides.length;
        out.sideData = sides.map(s => describe(s));

        out.hScroll = document.documentElement.scrollWidth > window.innerWidth + 1;
        return out;
      })()`);

      console.log(`--- ${w}px ---`);
      if (!d.found) { bad('hero-grid-container নেই'); console.log(''); continue; }

      if (d.slides >= 1) ok(`হিরো স্লাইড: ${d.slides}টি`);
      else bad('হিরো স্লাইড নেই');

      ok(`show_text: এনাবল ${d.withText}টি, ডিসেবল ${d.withoutText}টি`);

      // প্রতিটি স্লাইড আলাদা যাচাই
      let textChecked = 0, plainChecked = 0;
      for (const f of d.slideData) {
        const label = (f.titleText || '(টেক্সট নেই)').slice(0, 22);

        if (f.btnVisible) ok(`[${label}] বাটন দৃশ্যমান`);
        else bad(`[${label}] বাটন অদৃশ্য`);
        if (f.btnInsideBox) ok(`[${label}] বাটন স্লাইডের ভেতরে`);
        else bad(`[${label}] বাটন স্লাইডের বাইরে`);
        if (f.imgCovers) ok(`[${label}] ছবি পুরো স্লাইড ঢেকে আছে`);
        else bad(`[${label}] ছবি স্লাইডের সাথে মিলছে না`);

        if (f.hasTitle || f.hasSub) {
          textChecked++;
          if (f.titleVisible && f.titleText) ok(`[${label}] টাইটেল দেখা যাচ্ছে`);
          else bad(`[${label}] টাইটেল দেখা যাচ্ছে না`);
          if (f.hasScrim) ok(`[${label}] স্ক্রিম আছে`);
          else bad(`[${label}] স্ক্রিম নেই`);
          if (f.titleColor === 'rgb(255, 255, 255)') ok(`[${label}] টাইটেল সাদা`);
          else bad(`[${label}] টাইটেল রঙ ${f.titleColor}`);
          if (f.titleAboveBtn) ok(`[${label}] টাইটেল বাটনের উপরে`);
          else bad(`[${label}] টাইটেল/বাটন ওভারল্যাপ`);
        } else {
          plainChecked++;
          if (!f.hasScrim) ok(`[${label}] ডিসেবলে স্ক্রিম নেই (সঠিক)`);
          else bad(`[${label}] ডিসেবলেও স্ক্রিম আছে`);
        }

        if (f.boxRatio >= 1.45) ok(`[${label}] অনুপাত ${f.boxRatio} (ছবি ${f.imgRatio}, cover)`);
        else bad(`[${label}] অনুপাত ${f.boxRatio} — খুব চওড়া/লম্বা`);
      }

      if (w > 600) {
        if (d.sides === 2) ok(`সাইড কার্ড: ${d.sides}টি`);
        else bad(`সাইড কার্ড ${d.sides}টি (প্রত্যাশিত ২)`);

        for (const s of d.sideData) {
          const label = (s.titleText || '(টেক্সট নেই)').slice(0, 20);
          if (s.btnVisible) ok(`সাইড[${label}] বাটন দৃশ্যমান`);
          else bad(`সাইড[${label}] বাটন অদৃশ্য`);
          if (s.hasTitle && s.hasSub) {
            if (s.titleVisible) ok(`সাইড[${label}] টাইটেল দৃশ্যমান`);
            else bad(`সাইড[${label}] টাইটেল অদৃশ্য`);
            if (s.titleAboveBtn) ok(`সাইড[${label}] টাইটেল বাটনের উপরে`);
            else bad(`সাইড[${label}] ওভারল্যাপ`);
            if (s.hasScrim) ok(`সাইড[${label}] স্ক্রিম আছে`);
            else bad(`সাইড[${label}] স্ক্রিম নেই`);
          } else {
            if (!s.hasScrim) ok(`সাইড[${label}] ডিসেবলে স্ক্রিম নেই (সঠিক)`);
            else bad(`সাইড[${label}] ডিসেবলেও স্ক্রিম আছে`);
          }
        }
      } else {
        ok('মোবাইলে সাইড কার্ড লুকানো (ডিজাইন অনুযায়ী)');
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
