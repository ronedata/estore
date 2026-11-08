/* ==========================================================================
   eStore — front-end behaviour
   ========================================================================== */
(function () {
  'use strict';

  var BASE = (window.ESTORE_BASE || '').replace(/\/$/, '');

  /* ---------------- Toast ---------------- */
  function toast(msg, type) {
    var stack = document.getElementById('toastStack');
    if (!stack) return;
    var el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.textContent = msg;
    stack.appendChild(el);
    setTimeout(function () {
      el.style.opacity = '0';
      el.style.transform = 'translateY(8px)';
      el.style.transition = 'all .25s';
      setTimeout(function () { el.remove(); }, 260);
    }, 2200);
  }
  window.estoreToast = toast;

  /* ---------------- Cart popup ---------------- */
  var popupOverlay = document.getElementById('cartPopupOverlay');
  var popupIcon    = document.getElementById('cartPopupIcon');
  var popupTitle   = document.getElementById('cartPopupTitle');
  var popupMsg     = document.getElementById('cartPopupMsg');
  var popupClose   = document.getElementById('cartPopupClose');

  function showPopup(ok, title, message) {
    if (!popupOverlay) { toast(message, ok ? 'ok' : 'err'); return; }
    popupIcon.className = 'cart-popup-icon ' + (ok ? 'ok' : 'err');
    popupIcon.innerHTML = ok
      ? '<i class="fa-solid fa-check"></i>'
      : '<i class="fa-solid fa-triangle-exclamation"></i>';
    popupTitle.textContent = title;
    popupMsg.textContent = message;
    popupOverlay.classList.add('show');
    popupClose && popupClose.focus();
  }
  window.estorePopup = showPopup;

  function hidePopup() { popupOverlay && popupOverlay.classList.remove('show'); }
  popupClose && popupClose.addEventListener('click', hidePopup);
  popupOverlay && popupOverlay.addEventListener('click', function (e) {
    if (e.target === popupOverlay) hidePopup();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { hidePopup(); closeDrawer(); }
  });

  /* ---------------- Cart count ---------------- */
  function setCount(n) {
    n = parseInt(n, 10) || 0;
    ['cartCount', 'bnCartCount'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.textContent = n;
      el.classList.toggle('show', n > 0);
    });
  }
  window.refreshCartCount = function () {
    fetch(BASE + '/cart_count.php', { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && typeof d.count !== 'undefined') setCount(d.count); })
      .catch(function () {});
  };

  /* ---------------- Add to cart (XSS-safe) ---------------- */
  window.addToCart = function (productId, ev, btn) {
    if (ev && ev.preventDefault) ev.preventDefault();

    var originalHTML = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>যোগ হচ্ছে…</span>';
    }

    var body = new URLSearchParams();
    body.set('product_id', String(productId));
    body.set('qty', String((btn && btn.dataset && btn.dataset.qty) || 1));

    /* CSRF টোকেন — <meta name="csrf-token"> থেকে */
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) body.set('csrf', meta.content);

    fetch(BASE + '/cart_add.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'fetch', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (data) {
        if (data && data.stock_out) {
          if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-ban"></i> <span>স্টক নেই</span>';
            btn.classList.remove('green');
            btn.classList.add('ghost');
          }
          showPopup(false, 'স্টক আউট', data.message || 'এই পণ্যটি এখন স্টক আউট।');
          return false;
        }

        if (data && data.count !== undefined) setCount(data.count);

        if (!data || !data.ok) {
          if (btn) { btn.disabled = false; btn.innerHTML = originalHTML; }
          var msg = (data && data.message) ? data.message : 'কার্টে যোগ করা যায়নি।';
          var adjusted = data && data.qty ? ' (পরিমাণ ' + data.qty + ' টি করা হয়েছে)' : '';
          showPopup(false, 'সমন্বয় হয়েছে', msg + adjusted);
          return false;
        }

        if (btn) {
          btn.classList.add('green');
          btn.innerHTML = '<i class="fa-solid fa-check"></i> <span>কার্টে যোগ হয়েছে</span>';
        }
        showPopup(true, 'সফল', data.message || 'পণ্যটি কার্টে যোগ হয়েছে।');
        setTimeout(function () {
          if (!btn) return;
          btn.disabled = false;
          btn.innerHTML = originalHTML;
          btn.classList.remove('green');
        }, 1500);
        return true;
      })
      .catch(function () {
        if (btn) { btn.disabled = false; btn.innerHTML = originalHTML; }
        showPopup(false, 'ত্রুটি', 'সার্ভারে সমস্যা হয়েছে। আবার চেষ্টা করুন।');
      });

    return false;
  };

  /* ---------------- Drawer ---------------- */
  var drawer  = document.getElementById('sidebarDrawer');
  var overlay = document.getElementById('sidebarOverlay');
  var toggle  = document.getElementById('menuToggle');
  var closeBtn = document.getElementById('closeSidebar');

  function openDrawer() {
    if (!drawer) return;
    drawer.classList.add('active');
    drawer.setAttribute('aria-hidden', 'false');
    if (overlay) { overlay.hidden = false; overlay.classList.add('active'); }
    toggle && toggle.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    closeBtn && closeBtn.focus();
  }
  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('active');
    drawer.setAttribute('aria-hidden', 'true');
    if (overlay) overlay.classList.remove('active');
    toggle && toggle.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }
  window.estoreCloseDrawer = closeDrawer;

  toggle  && toggle.addEventListener('click', openDrawer);
  closeBtn && closeBtn.addEventListener('click', closeDrawer);
  overlay && overlay.addEventListener('click', closeDrawer);

  /* ---------------- Live search ---------------- */
  var input = document.getElementById('liveSearchInput');
  var box   = document.getElementById('suggestBox');
  var timer = null;
  var activeIdx = -1;

  function hideSuggest() {
    if (!box) return;
    box.classList.remove('is-open');
    box.innerHTML = '';
    activeIdx = -1;
    input && input.setAttribute('aria-expanded', 'false');
  }

  /* fixed বক্সকে ইনপুটের নিচে বসাই (header-এর overflow:hidden এড়াতে) */
  function positionSuggest() {
    if (!box || !input) return;
    var ir = input.getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var gap = 8;
    var w = Math.min(560, Math.max(240, ir.width));
    // ইনপুটের সাথে কেন্দ্র করে, viewport-এর ভেতরে থাকতে clamp
    var left = ir.left + (ir.width - w) / 2;
    left = Math.max(12, Math.min(left, vw - w - 12));

    box.style.width = w + 'px';
    box.style.left = Math.round(left) + 'px';

    // নিচে জায়গা না থাকলে উপরে খুলবে
    var belowTop = ir.bottom + gap;
    var spaceBelow = vh - belowTop - 12;
    var spaceAbove = ir.top - gap - 12;
    if (spaceBelow < 160 && spaceAbove > spaceBelow) {
      box.style.top = 'auto';
      box.style.bottom = Math.round(vh - ir.top + gap) + 'px';
    } else {
      box.style.bottom = 'auto';
      box.style.top = Math.round(belowTop) + 'px';
    }
  }

  function showSuggest() {
    if (!box) return;
    positionSuggest();
    box.classList.add('is-open');
    input && input.setAttribute('aria-expanded', 'true');
  }

  function setActive(idx) {
    if (!box) return;
    var items = box.querySelectorAll('.suggest-item');
    if (!items.length) return;
    activeIdx = (idx + items.length) % items.length;
    items.forEach(function (el, i) {
      el.classList.toggle('is-active', i === activeIdx);
    });
    items[activeIdx].scrollIntoView({ block: 'nearest' });
  }

  function renderSuggest(items) {
    if (!box) return;
    box.innerHTML = '';
    activeIdx = -1;

    if (!items.length) {
      var empty = document.createElement('div');
      empty.className = 'suggest-empty';
      empty.textContent = 'কোনো প্রোডাক্ট পাওয়া যায়নি';
      box.appendChild(empty);
      showSuggest();
      return;
    }

    items.forEach(function (it) {
      var row = document.createElement('div');
      row.className = 'suggest-item';
      row.setAttribute('role', 'option');
      row.tabIndex = 0;

      var img = document.createElement('img');
      img.className = 'suggest-thumb';
      img.loading = 'lazy';
      img.alt = '';
      img.width = 42;
      img.height = 42;
      img.src = it.image || (BASE + '/assets/img/placeholder.png');
      img.onerror = function () { this.style.visibility = 'hidden'; };

      var name = document.createElement('div');
      name.className = 'suggest-name';
      name.textContent = it.name;                 // ← textContent: XSS নিরাপদ

      var price = document.createElement('div');
      price.className = 'suggest-price';
      price.textContent = '৳' + Math.round(it.price);

      row.appendChild(img);
      row.appendChild(name);
      row.appendChild(price);

      var go = function () { window.location.href = BASE + '/product_details.php?id=' + encodeURIComponent(it.id); };
      row.addEventListener('click', go);
      row.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); go(); }
      });

      box.appendChild(row);
    });
    showSuggest();
  }

  if (input && box) {
    input.addEventListener('input', function () {
      var q = (input.value || '').trim();
      clearTimeout(timer);
      if (q.length < 2) { hideSuggest(); return; }

      timer = setTimeout(function () {
        fetch(BASE + '/search_suggest.php?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'fetch' } })
          .then(function (r) { return r.json(); })
          .then(function (js) {
            if (!Array.isArray(js)) { hideSuggest(); return; }
            renderSuggest(js);
          })
          .catch(hideSuggest);
      }, 180);
    });

    input.addEventListener('focus', function () {
      if (box.children.length) showSuggest();
    });

    input.addEventListener('keydown', function (e) {
      var items = box.querySelectorAll('.suggest-item');
      if (e.key === 'Escape') { hideSuggest(); input.blur(); return; }

      if (e.key === 'ArrowDown' && items.length) {
        e.preventDefault(); setActive(activeIdx + 1); return;
      }
      if (e.key === 'ArrowUp' && items.length) {
        e.preventDefault(); setActive(activeIdx - 1); return;
      }
      if (e.key === 'Enter') {
        if (activeIdx > -1 && items[activeIdx]) {
          e.preventDefault();
          items[activeIdx].click();
          return;
        }
        e.preventDefault();
        var q = (input.value || '').trim();
        window.location.href = BASE + '/shop.php' + (q ? ('?q=' + encodeURIComponent(q)) : '');
      }
    });

    document.addEventListener('click', function (e) {
      if (!box.contains(e.target) && e.target !== input) hideSuggest();
    });

    /* বক্স খোলা থাকলে স্ক্রল/রিসাইজে পজিশন ঠিক রাখি */
    function repositionIfOpen() {
      if (box && box.classList.contains('is-open')) positionSuggest();
    }
    window.addEventListener('scroll', repositionIfOpen, { passive: true });
    window.addEventListener('resize', repositionIfOpen);
    window.addEventListener('orientationchange', repositionIfOpen);
  }

  /* ---------------- Scroll reveal ---------------- */
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduceMotion && 'IntersectionObserver' in window) {
    document.body.classList.add('js-enabled');

    var queue = [];
    var busy = false;

    function drain() {
      if (!queue.length) { busy = false; return; }
      busy = true;
      var el = queue.shift();
      el.classList.add('is-visible');
      setTimeout(drain, 110);
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        if (!el.classList.contains('is-visible') && queue.indexOf(el) === -1) queue.push(el);
        if (!busy) drain();
        io.unobserve(el);
      });
    }, { rootMargin: '0px 0px -40px 0px', threshold: 0.05 });

    document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
  }

  /* ---------------- Initial cart count ---------------- */
  window.refreshCartCount();
})();
