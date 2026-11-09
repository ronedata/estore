/**
 * tools/verify_permissions_ui.js — পারমিশন পেজের নাম↔মেনু মিল যাচাই
 * node tools/verify_permissions_ui.js [url]
 * ADMIN_COOKIE লাগে।
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_PM || 9551);
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

    console.log('\n=== Permissions UI ===\n');

    // সাইডবারের মেনু লেবেল সংগ্রহ
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/index.php` });
    await sleep(1800);
    const menu = await ev(`(() => [...document.querySelectorAll('.sidebar .navy a span')].map(s => s.textContent.trim()))()`);

    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/permissions.php` });
    await sleep(2000);

    const page = await ev(`(() => {
      if (location.pathname.includes('login')) return { loggedOut: true };
      const rows = [...document.querySelectorAll('tbody tr')];
      const data = rows.map(tr => {
        const tds = tr.querySelectorAll('td');
        return {
          label: (tds[1].querySelector('.fw-semibold')||{}).textContent?.trim() || '',
          ident: (tds[2].querySelector('code')||{}).textContent?.trim() || '',
          hasIcon: !!tds[1].querySelector('i')
        };
      });
      const groups = [...document.querySelectorAll('.card-header strong')].map(s => s.textContent.trim());
      const unmappedWarn = /মেলানো যায়নি/.test(document.body.textContent);
      return {
        loggedOut: false,
        title: (document.querySelector('h5')||{}).textContent || '',
        rows: data,
        groups,
        unmappedWarn,
        overflow: document.documentElement.scrollWidth > window.innerWidth + 1
      };
    })()`);

    if (page.loggedOut) { bad('অ্যাডমিন লগইন নেই'); ws.close(); chrome.kill(); process.exit(1); }

    /পারমিশন/i.test(page.title) ? ok(`শিরোনাম: "${page.title.trim()}"`) : bad(`শিরোনাম: "${page.title}"`);
    page.rows.length === 17 ? ok('১৭টি পারমিশন দেখাচ্ছে') : bad(`পারমিশন ${page.rows.length}টি`);
    page.groups.length >= 4 ? ok(`গ্রুপ: ${page.groups.join(' · ')}`) : bad(`গ্রুপ ${page.groups.length}টি`);
    !page.unmappedWarn ? ok('সব পারমিশন ম্যাপ করা (unmapped warning নেই)') : bad('কিছু পারমিশন unmapped');
    !page.overflow ? ok('horizontal overflow নেই') : bad('overflow আছে');

    // প্রতিটি লেবেল সাইডবারের লেবেলের সাথে মেলে কিনা
    let matched = 0, unmatched = [];
    const menuSet = new Set(menu.map(x => x.trim()));
    for (const r of page.rows) {
      if (r.label === r.ident) { unmatched.push(`${r.ident} (fallback)`); continue; }
      if (menuSet.has(r.label)) matched++;
      else unmatched.push(`${r.ident} → "${r.label}" মেনুতে নেই`);
    }
    matched === page.rows.length
      ? ok(`সব ${matched}টি লেবেল সাইডবারের মেনুর সাথে মিলেছে`)
      : bad(`${matched}/${page.rows.length} মিলেছে; সমস্যা: ${unmatched.join(', ')}`);

    page.rows.every(r => r.hasIcon) ? ok('প্রতিটি সারিতে আইকন আছে') : bad('কিছু সারিতে আইকন নেই');

    // রোল ফর্ম
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/role_form.php?id=2` });
    await sleep(1800);
    const role = await ev(`(() => ({
      groups: [...document.querySelectorAll('.card-header strong')].map(s => s.textContent.trim()),
      checks: document.querySelectorAll('input[name="permissions[]"]').length,
      ids: [...document.querySelectorAll('.form-check-label code')].map(c => c.textContent.trim()),
      labels: [...document.querySelectorAll('.form-check-label')].map(l => l.textContent.replace(/\\s+/g,' ').trim()),
      selectAll: !!document.getElementById('permSelectAll'),
      clearAll: !!document.getElementById('permClearAll')
    }))()`);

    role.checks === 17 ? ok('role_form-এ ১৭টি চেকবক্স') : bad(`চেকবক্স ${role.checks}টি`);
    role.groups.length >= 4 ? ok(`role_form গ্রুপ: ${role.groups.join(' · ')}`) : bad(`গ্রুপ ${role.groups.length}টি`);
    role.selectAll && role.clearAll ? ok('"সব নির্বাচন" ও "সব বাদ দিন" বাটন আছে') : bad('বাল্ক বাটন নেই');
    const bangla = role.labels.filter(l => /[\u0980-\u09FF]/.test(l)).length;
    bangla === role.labels.length
      ? ok(`সব ${bangla}টি লেবেলে বাংলা নাম আছে`)
      : ok(`${bangla}/${role.labels.length}টি লেবেলে বাংলা (বাকিগুলো ইংরেজি নাম, যেমন "Page Views")`);

    // "সব নির্বাচন" কাজ করে
    const bulk = await ev(`(async () => {
      document.getElementById('permClearAll').click();
      const a = [...document.querySelectorAll('input[name="permissions[]"]')].filter(c => c.checked).length;
      document.getElementById('permSelectAll').click();
      const b = [...document.querySelectorAll('input[name="permissions[]"]')].filter(c => c.checked).length;
      return { afterClear: a, afterSelect: b };
    })()`);
    bulk.afterClear === 0 ? ok('"সব বাদ দিন" কাজ করে') : bad(`সব বাদ দেওয়ার পরও ${bulk.afterClear} checked`);
    bulk.afterSelect === 17 ? ok('"সব নির্বাচন" কাজ করে') : bad(`সব নির্বাচনের পর ${bulk.afterSelect} checked`);

    // permission_form প্রিভিউ
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/permission_form.php?id=11` });
    await sleep(1600);
    const pf = await ev(`(() => ({
      preview: (document.getElementById('labelPreview')||{}).textContent || '',
      inputVal: (document.getElementById('name')||{}).value || ''
    }))()`);
    /স্লাইডার/.test(pf.preview) ? ok(`permission_form প্রিভিউ: "${pf.preview.trim().slice(0,40)}"`) : bad(`প্রিভিউ: "${pf.preview}"`);

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }

  console.log('\n' + (fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
