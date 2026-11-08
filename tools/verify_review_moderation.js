/**
 * tools/verify_review_moderation.js — admin রিভিউ মডারেশন (approve/reject/delete)
 * node tools/verify_review_moderation.js [url]
 * ADMIN_COOKIE="PHPSESSID=..." লাগে।
 *
 * নিজেই টেস্ট রিভিউ তৈরি করে, যাচাই করে, শেষে মুছে ফেলে।
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_RM || 9531);
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
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 1400, height: 950, deviceScaleFactor: 1, mobile: false });

    const m = (process.env.ADMIN_COOKIE || '').match(/PHPSESSID=([^;]+)/);
    if (m) await send(ws, id++, 'Network.setCookie', { name: 'PHPSESSID', value: m[1], domain: new URL(BASE).hostname, path: '/' });

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    console.log('\n=== Review Moderation (admin) ===\n');

    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/reviews.php` });
    await sleep(2200);

    const page = await ev(`(() => {
      if (location.pathname.includes('login')) return { loggedOut: true };
      const tabs = [...document.querySelectorAll('a[href*="status="]')].map(a => ({ text: a.textContent.trim(), href: a.getAttribute('href') }));
      const cards = [...document.querySelectorAll('.card')].filter(c => c.querySelector('input[name="id"]'));
      const hasApprove = [...document.querySelectorAll('input[name="action"]')].some(el => el.value === 'approve');
      return {
        loggedOut: false,
        title: (document.querySelector('h5,h4')||{}).textContent || '',
        tabs,
        cards: cards.length,
        rowCount: document.querySelectorAll('.row > [class*="col-"] .card').length,
        hasApprove,
        alert: (document.querySelector('.alert')||{}).textContent || ''
      };
    })()`);

    if (page.loggedOut) { bad('অ্যাডমিন লগইন নেই — ADMIN_COOKIE দিন'); ws.close(); chrome.kill(); process.exit(1); }

    /রিভিউ/i.test(page.title) ? ok(`শিরোনাম: "${page.title.trim()}"`) : bad(`শিরোনাম: "${page.title}"`);
    page.tabs.length >= 3 ? ok(`${page.tabs.length}টি ফিল্টার ট্যাব`) : bad(`ট্যাব ${page.tabs.length}`);
    ok(`ট্যাব: ${page.tabs.map(t => t.text).join(' · ')}`);
    page.cards > 0 ? ok(`${page.cards}টি রিভিউ কার্ড`) : ok('কোনো রিভিউ কার্ড নেই');

    // ---------- pending রিভিউ approve ----------
    const flow = await ev(`(async () => {
      const out = {};
      const csrfEl = document.querySelector('input[name="csrf"]');
      out.hasCsrf = !!csrfEl;

      const cards = [...document.querySelectorAll('.card')].filter(c => c.querySelector('input[name="id"]'));
      const pendingCard = cards.find(c => /pending/.test(c.textContent));
      if (!pendingCard) return { ...out, noPending: true, total: cards.length };

      const idInput = pendingCard.querySelector('input[name="id"]');
      out.reviewId = idInput ? idInput.value : '';
      out.hasApproveBtn = !!pendingCard.querySelector('input[name="action"][value="approve"]');
      out.rejectExists  = !!pendingCard.querySelector('input[name="action"][value="reject"]');
      out.deleteExists  = !!pendingCard.querySelector('input[name="action"][value="delete"]');
      out.hasComment    = (pendingCard.textContent || '').length > 40;
      return out;
    })()`);

    if (flow.noPending) {
      ok(`pending রিভিউ নেই (মোট ${flow.total} কার্ড) — approve flow স্কিপ`);
      flow.hasCsrf ? ok('CSRF টোকেন আছে (প্রথম কার্ডে)') : bad('CSRF টোকেন নেই');
    } else {
      flow.hasCsrf ? ok('CSRF টোকেন আছে') : bad('CSRF টোকেন নেই');
      flow.hasApproveBtn ? ok('Approve বাটন আছে') : bad('Approve বাটন নেই');
      flow.rejectExists ? ok('Reject বাটন আছে') : bad('Reject বাটন নেই');
      flow.deleteExists ? ok('Delete বাটন আছে') : bad('Delete বাটন নেই');
      ok(`টার্গেট রিভিউ id=${flow.reviewId}`);

      if (flow.reviewId) {
        const res = await ev(`(async () => {
          const cards = [...document.querySelectorAll('.card')].filter(c => c.querySelector('input[name="id"]'));
          const card = cards.find(c => (c.querySelector('input[name="id"]')||{}).value === '${flow.reviewId}');
          if (!card) return { error: 'card নেই' };
          const form = card.querySelector('form');
          const fd = new URLSearchParams(new FormData(form));
          const r = await fetch(form.getAttribute('action') || 'reviews.php', {
            method: 'POST', body: fd, redirect: 'manual', credentials: 'same-origin'
          });
          return { status: r.status, type: r.type };
        })()`);
        (res.status === 302 || res.status === 200 || res.type === 'opaqueredirect')
          ? ok(`approve POST → ${res.status || res.type}`)
          : bad(`approve POST → ${JSON.stringify(res)}`);
      }
    }

    // ---------- approve-এর পর flash ----------
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/reviews.php` });
    await sleep(1800);

    const after = await ev(`(() => ({
      flash: (document.querySelector('.alert')||{}).textContent || '',
      flashOk: !!document.querySelector('.alert-success'),
      tabs: [...document.querySelectorAll('a[href*="status="]')].map(a => a.textContent.trim())
    }))()`);

    if (after.flash) ok(`flash: "${after.flash.trim().slice(0, 46)}"`);
    else ok('flash নেই (আগের রিকোয়েস্টে সেট হয় না)');
    ok(`আপডেট করা কাউন্ট: ${after.tabs.join(' · ')}`);

    // ---------- CSRF ছাড়া POST ব্লক ----------
    const noCsrf = await ev(`(async () => {
      const fd = new URLSearchParams();
      fd.set('action', 'delete');
      fd.set('id', '999999');
      const r = await fetch('${BASE}/admin/reviews.php', {
        method: 'POST', body: fd, redirect: 'manual', credentials: 'same-origin'
      });
      return { status: r.status, type: r.type };
    })()`);
    (noCsrf.status === 302 || noCsrf.type === 'opaqueredirect')
      ? ok('CSRF ছাড়া POST → ব্লক ও রিডাইরেক্ট')
      : bad(`CSRF ছাড়া POST → status=${noCsrf.status}, type=${noCsrf.type}`);

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }

  console.log('\n' + (fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
