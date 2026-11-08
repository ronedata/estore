# SESSION LOG — eStore ডেভেলপমেন্ট ইতিহাস

> এই ফাইলটি প্রতিটি কাজের কালানুক্রমিক রেকর্ড। নতুন সেশনে `AGENTS.md` পড়ার পর
> এটিও পড়ুন — "কী করা হয়েছে এবং কেন" বুঝতে পারবেন।
>
> শেষ আপডেট: ২০২৬-১১-০৪

---

## সেশন ১ — প্রজেক্ট রান

- PHP dev server (`php -S 127.0.0.1:8000`) চালু
- MySQL `estore` DB যাচাই: 12 products, 3 categories, 32 orders
- সব পেজ 200, কোনো PHP error নেই

---

## সেশন ২ — mFeex ডিজাইন রূপান্তর

**রেফারেন্স:** https://mfeex.shop/ + https://demo2.mfeex.shop/

### নতুন CSS ফাইল (সম্পূর্ণ পুনর্লিখিত)
- `assets/css/theme.css` — palette, glass header (multi-colour sweep), sidebar drawer, bottom nav, buttons, toast, cart popup
- `assets/css/components.css` — hero-grid-container, categories-section, fruit-card, info-bar, payment, footer (rainbow glass), product detail, cart, forms, static pages

### Partial পুনর্লিখিত
- `includes/partials_header.php` — glass header, search মাঝে / cart ডানে, sidebar
- `includes/partials_footer.php` — rainbow glass footer + bottom nav
- `includes/partials_product_card.php` — `fruit-card` ডিজাইন

### `index.php`
- `hero-grid-container` (2fr 1fr), categories flex grid, `section-title-wrapper`
- slider না থাকলে featured প্রোডাক্ট থেকে fallback hero

---

## সেশন ৩ — Category image upload

`admin/categories.php` সম্পূর্ণ নতুন লেখা:
- ইমেজ আপলোড (JPG/PNG/WEBP/GIF)
- live preview, remove checkbox
- `sort_order`, `active`
- `cat_unlink()` path traversal guard
- টেস্ট: upload → DB, edit/replace → পুরোনো ফাইল ডিলিট, `.php` reject

---

## সেশন ৪ — Slider button controls

- `includes/schema_v3.php` — নতুন কলাম `btn_size`, `btn_pos_x`, `btn_pos_y`
- `admin/slider_form.php` — বাটন সাইজ (sm/md/lg), পজিশন (left/center/right × top/middle/bottom), লাইভ প্রিভিউ
- `index.php` — `is-sm/md/lg`, `at-left/center/right`, `at-top/middle/bottom`
- ইমেজ সাইজ গাইড: hero 1200×675 (16:9), side 600×375 (16:10)
- বাগ: first implementation-এ `$s['btn_size']` undefined warning → `??` দিয়ে ঠিক

---

## সেশন ৫ — Header layout + search suggestion

- search মাঝে (`margin:0 auto`), cart ডানে (`header-actions` + `margin-left:auto`)
- header `overflow:visible` + `clip-path` → suggestion আর কাটা পড়ে না
- suggestion: `is-open` class, keyboard nav (↑↓/Enter/Esc), "কোনো প্রোডাক্ট পাওয়া যায়নি"
- Swiper CSS non-blocking, JS `defer`

---

## সেশন ৬ — ইমেজ 404 সমস্যা

**কারণ:** DB-তে path আছে কিন্তু disk-এ ফাইল নেই।

**সমাধান:** `includes/config.php`-এ `img_url()`:
```php
function img_url($path, ?string $fallback = null): string
```
- ফাইল disk-এ আছে কিনা যাচাই
- না থাকলে `default.jpg` (বা `$fallback`)
- `uploads/x.jpg` ও `/uploads/x.jpg` — দুটোই হ্যান্ডেল

সব `<img>`-এ `onerror` fallback যোগ।

---

## সেশন ৭ — Category grid center alignment

- `repeat(6, 1fr)` → `display:flex; justify-content:center; flex-wrap:wrap`
- `category-item` fixed width 110px (mobile 84px)

---

## সেশন ৮ — Logo/favicon → `uploads/site_logo/`

`admin/settings_save.php`:
- `sf_site_logo_dir_abs()` — ফোল্ডার অটো তৈরি
- `sf_delete_logo_file()` — path traversal guard সহ পুরোনো ফাইল ডিলিট
- ফিল্ড না পাঠালে পুরনো মান অক্ষত

⚠️ **দুর্ঘটনা:** প্রথম টেস্টে আসল লোগো ডিলিট হয়ে গিয়েছিল। `C:\laragon\www\toyShop\uploads\` থেকে ফিরিয়ে আনা হয়েছে।

---

## সেশন ৯ — বাংলা mojibake

**কারণ:** PowerShell pipeline দিয়ে SQL পাঠালে double-encoding।
**সংশ্লিষ্ট:** `cod_note`, `home_notice`, `store_about`

**সমাধান:**
- `tools/fix_settings_encoding.php` — ৩টি মান ঠিক
- `admin/settings_save.php`-এ `sf_clean_text()` + `SET NAMES utf8mb4`

---

## সেশন ১০ — Featured / New Arrivals

- `includes/schema_v4.php` — `products.is_new_arrival` (ডিফল্ট ১)
- `admin/product_form.php` — দুটো চেকবক্স (New Arrivals ডিফল্ট checked + Featured) + `badge_color`
- `admin/product_add_for_mobile.php` — একই + stock ফিল্ড
- `admin/settings.php` — `featured_title/subtitle`, `newest_title/subtitle`
- `index.php` — New Arrivals query-তে `is_new_arrival = 1`

---

## সেশন ১১ — Static pages (About/Privacy/Terms/Contact)

- `includes/render_page.php` — `pg-hero` + `pg-card`, placeholder replace
- `about.php`, `privacy_policy.php`, `terms.php` — সরল, DB থেকে কনটেন্ট
- `contact.php` — দুই কলাম (info + message form), honeypot, CSRF
- `includes/schema_v5.php` — `contact_messages` টেবিল
- `admin/messages.php` — মেসেজ ম্যানেজার
- `admin/header.php` — "মেসেজ" লিংক
- CSS: `pg-hero`, `pg-card`, `pg-features`, `pg-stats`, `pg-contact-grid`

### Placeholder সিস্টেম
| Placeholder | মান |
|---|---|
| `{STORE}` / `{{store_name}}` / `{STORE_NAME}` | store_name |
| `{PHONE}` / `{EMAIL}` / `{ADDRESS}` | সংশ্লিষ্ট সেটিং |
| `{YEAR}` | বর্তমান বছর |

`admin/pages.php`-এ placeholder টেবিল + HTML ক্লাস গাইড + quick-insert বাটন।

---

## সেশন ১২ — Sidebar থেকে ক্যাটেগরি বাদ

`includes/partials_header.php` — sidebar-এর ক্যাটেগরি ব্লক + অব্যবহৃত `$cats` query সরানো।

---

## সেশন ১৩ — BASE_URL auto-detect

**কারণ:** লাইভে সাবফোল্ডারে `/uploads/...` ভুল পাথ।
`includes/config.php` + `live.config.php`:
```php
$APP_BASE_URL = null;  // null = auto (DOCUMENT_ROOT থেকে)
```

---

## সেশন ১৪ — `.htaccess` 500 সমাধান (uploads)

**কারণ:** InfinityFree-এ `Options -Indexes -ExecCGI` নিষিদ্ধ → পুরো ফোল্ডারে 500।
**সমাধান:** `uploads/.htaccess`-এ `Options` কমেন্ট, শুধু `FilesMatch` deny রাখা।

---

## সেশন ১৫ — Bottom nav সমস্যা (দুই ধাপ)

### ১৫ক. দেরিতে আসা
কারণ: Swiper CSS/JS পেজের মাঝে blocking।
সমাধান: CSS `<head>`-এ `media="print" onload`, JS `defer` + `DOMContentLoaded`।

### ১৫খ. স্ক্রলে সরে যাওয়া
**আসল কারণ (ধাপে ধাপে খুঁজে পাওয়া):**
1. `.main-header::before` — `left:-50%; width:200%` + `overflow:visible`
2. → layout viewport 390 → 1360px ফুলে যায়
3. → media query `max-width:768px` আর match করে না
4. → nav `display:none` হয়ে যায়

**সমাধান:**
- sweep → `inset:0` + `background-position` animation
- header `overflow:hidden`
- `html { overflow-x: clip }` (body-তে নয়)
- সব grid `1fr` → `minmax(0,1fr)`
- nav-এ solid background (backdrop-filter সরানো — Android bug)

**টেস্ট:** `tools/verify_bottom_nav.js` → Chrome 20/20, Edge 20/20, estore.test 20/20

---

## সেশন ১৬ — Slider edit-এ ইমেজ না দেখা

**কারণ:** relative path `uploads/sliders/x.jpg` — `/admin/` থেকে `/admin/uploads/...` খোঁজে → 404
**সমাধান:** `img_url()` সব `<img>`-এ
**টেস্ট:** `tools/verify_slider_edit.js` — ৫টি slider-ই ALL PASS

---

## সেশন ১৭ — Mobile gutter (margin)

**কারণ:** `main.wrap { padding: 0 }` + `.wrap .product-grid { padding: 0 }` — gutter খেয়ে ফেলছিল
**সমাধান:** ওই দুটো রুল সরানো + `.info-bar-section` `repeat(3, 1fr)`
**টেস্ট:** `tools/audit_gutters.js` → ১৪ পেজ × ৩ প্রস্থ = ৪২ কম্বিনেশন ALL OK

### শিক্ষণীয়
reveal animation-এর `transform: scale(.96)` থাকা অবস্থায় মাপ নিলে ভুল আসে — মাপার আগে `.is-visible` যোগ করে অপেক্ষা করতে হবে।

---

## সেশন ১৮ — ইমেজ প্রসেসিং (crop/pad)

### নতুন ফাইল `includes/image_processor.php`
```php
estore_process_image($tmp, $dest, $width, $height, $bg)   // যেকোনো অনুপাত
estore_process_product_image($tmp, $dest, $size)          // বর্গ (compat)
estore_slider_target_size($placement)                     // hero/side
estore_category_target_size()                             // 400×400
```

**লজিক:**
- বড়/অসম + দুই মাত্রাই টার্গেটের সমান/বড় → **center-crop**
- ছোট → পুরো ছবি + সাদা প্যাডিং

### সংযুক্ত
- `admin/product_form.php` — ৭০০×৭০০ গাইড + অটো প্রসেস
- `admin/slider_form.php` — placement অনুযায়ী (1200×675 / 600×375) + dynamic গাইড
- `admin/categories.php` — ৪০০×৪০০

### টেস্ট
`tools/test_all_image_sizes.php` — ৪ টার্গেট × ৫ সোর্স = **৩৫/৩৫ PASS**

### জানা সীমাবদ্ধতা
- `tall_900` (900×1800) hero-তে `pad` মোডে যায় (কারণ চওড়া < ১২০০)
- HEIC/HEIF প্রসেস হয় না (GD সাপোর্ট নেই) — raw সেভ

---

## সেশন ১৯ — product_details ইমেজ উচ্চতা

`assets/css/components.css`:
```css
.pd-main img { max-height: min(440px, 58vh); aspect-ratio: 1/1; object-fit: contain; }
```

---

## সেশন ২০ — সিকিউরিটি অডিট ও ফিক্স

### অডিট টুল
`tools/security_audit.js` — ১২টি নিয়ম, JSON রিপোর্ট।

### ধাপ ১: CSRF (HIGH → শেষ)
| ফাইল | পরিবর্তন |
|---|---|
| `cart_add.php` | `csrf_verify()` → 403 |
| `cart_update.php` | একই |
| `cart_remove.php` | POST-এ 403, GET লিংক অক্ষত |
| `wishlist_toggle.php` | একই |
| `place_order.php` | একই → checkout-এ redirect |
| `includes/partials_header.php` | `<meta name="csrf-token">` |
| `cart.php` | `csrf_field()` |
| `assets/js/home.js` | fetch-এ টোকেন |
| `product_details.php` | wishlist fetch-এ টোকেন |

টেস্ট: `tools/test_csrf.js` → **10/10 PASS**

### ⚠️ গুরুত্বপূর্ণ আবিষ্কার
`http_response_code(419)` → Apache **500** দেয়। **`403` ব্যবহার করতে হবে।**

### ধাপ ২: root `.htaccess` (HIGH → শেষ)
**আগে:** `/database/database_estore_26-11-02.sql` → 200 (৪৮,৭২৯ bytes ডাউনলোড হচ্ছিল!)
**এখন:** 403। includes/, tools/, *.sql, *.md, .git, composer.json — সব ব্লক।

### ধাপ ৩: `admin/security.php` (নতুন)
Super Admin only রিপোর্ট পেজ — summary কার্ড + findings টেবিল + "আবার স্ক্যান" বাটন।

### ধাপ ৪: Session সিকিউরিটি
`includes/config.php` + `live.config.php`:
```php
session_set_cookie_params(['httponly'=>true,'secure'=>$https,'samesite'=>'Lax']);
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
// + ৩০ মিনিটে SID রোটেশন
```
যাচাই: `tools/test_session_security.php` → ALL PASS

### ধাপ ৫-৬: Upload ভ্যালিডেশন + `hash_equals()`
- `settings_save.php`, `product_form.php`, `product_add_for_mobile.php` — `getimagesize()` + ৫MB
- ICO আলাদা ম্যাজিক-বাইট চেক
- ৬ জায়গায় `!==` → `hash_equals()`
- বোনাস ফিক্স: flash message overwrite হওয়া বন্ধ

**টেস্ট:** ভুয়া `.jpg` (PHP কোড) reject; বৈধ PNG/JPEG ঠিকমতো 700×700/400×400

### চূড়ান্ত অডিট
```
Total: 0  (C:0 H:0 M:0 L:0)
```

---

## সেশন ২১ — 🚨 CRITICAL: CSRF সম্পূর্ণ বাইপাস বাগ

> **এই সেশনটি সবচেয়ে গুরুত্বপূর্ণ** — এখানে আবিষ্কৃত বাগটি সেশন ২০-এর
> "সম্পন্ন" CSRF সুরক্ষাকে সম্পূর্ণ অর্থহীন করে দিয়েছিল।

### কীভাবে ধরা পড়ল
সম্পূর্ণ রিগ্রেশন টেস্ট চালানোর সময়। একটি curl টেস্টে `token` খালি ছিল
কিন্তু `cart_add.php` তবুও **200 OK** ফিরিয়েছিল।

### মূল কারণ
```php
// ভুল কোড (সেশন ২০-এ লেখা)
hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$_POST['csrf'])
```

| পরিস্থিতি | `$expected` | `$given` | `hash_equals()` | ফল |
|---|---|---|---|---|
| নতুন সেশন (টোকেন তৈরি হয়নি) | `''` | `''` | **`true`** | ❌ পাস |
| সেশন আছে, POST খালি | `'abc...'` | `''` | `false` | ✅ ব্লক |

নতুন সেশনে `$_SESSION['csrf']` তখনো তৈরি হয়নি, আর আক্রমণকারী `csrf=` (খালি)
পাঠালে দুটোই `''` → `hash_equals('','') === true` → **সম্পূর্ণ বাইপাস**।

**প্রমাণ (ফিক্সের আগে):**
```
status=200
{"ok":true,"message":"কার্টে যোগ হয়েছে","count":1}
```

### সমাধান
`includes/config.php` + `includes/live.config.php`:
```php
function csrf_verify(): bool {
  $expected = (string)($_SESSION['csrf'] ?? '');
  $given    = (string)($_POST['csrf'] ?? '');
  if ($expected === '' || $given === '') return false;   // ← গার্ড
  return hash_equals($expected, $given);
}
```

`admin/`-এর ৬ জায়গাতেও একই ইনলাইন গার্ড:
```php
if (empty($_SESSION['csrf']) || empty($_POST['csrf']) || !hash_equals(...))
```

### ফিক্স করা ফাইল
| ফাইল | জায়গা |
|---|---|
| `includes/config.php` | `csrf_verify()` |
| `includes/live.config.php` | `csrf_verify()` |
| `admin/settings_save.php` | ১ |
| `admin/product_toggle.php` | ১ |
| `admin/product_add_for_mobile.php` | ১ |
| `admin/product_form.php` | ৩ |

### পুনঃযাচাই — সব আক্রমণ পথ
| পথ | আগে | এখন |
|---|---|---|
| নতুন সেশন + `csrf=` | **200 পাস** | **403** |
| `csrf` ফিল্ড না পাঠানো | 403 | 403 |
| `csrf[]=x` (array) | 403 | 403 |
| সেশন টোকেন + POST খালি | **200 পাস** | **403** |

`tools/test_csrf.js`-এ ৩টি নতুন টেস্ট যোগ → **13/13 PASS**

### অন্যান্য ফ্লো টেস্ট (এই সেশন)
| ফ্লো | ফলাফল |
|---|---|
| কার্ট add → update → remove | ✅ 200 → 302 → 302 |
| Contact form | ✅ DB-তে যোগ, সফল মেসেজ |
| Admin settings save | ✅ 302, ডেটা অপরিবর্তিত |
| Admin product toggle | ✅ 1→0→1 |
| Admin product form save | ✅ ডেটা অপরিবর্তিত |

### 💡 শিক্ষণীয়
`hash_equals()` ব্যবহার করলেই নিরাপদ নয় — **উভয় পক্ষ খালি হলে সেটা `true`**
রিটার্ন করে। সবসময় খালি-মানের গার্ড দিতে হবে।

---

## সেশন ২২ — চ্যাট ইতিহাস সংরক্ষণ

- `AGENTS.md` — স্বয়ংক্রিয়ভাবে পড়া হয় (প্রজেক্ট কনটেক্সট)
- `docs/SESSION_LOG.md` — এই ফাইল (বিস্তারিত ইতিহাস)
- `.htaccess`-এ `docs/` যোগ → 403


---

## বর্তমান অবস্থা (২০২৬-১১-০৪)

| বিষয় | অবস্থা |
|---|---|
| সিকিউরিটি অডিট | ০ ফাইন্ডিং |
| ফ্রন্ট পেজ | ১৪টি, সব 200, ০ error |
| অ্যাডমিন পেজ | ১৩টি, সব 200, ০ error |
| ইমেজ প্রসেসিং | ৩৫/৩৫ কেস PASS |
| CSRF | ১০/১০ PASS |
| Bottom nav | ২০/২০ PASS |
| Gutter | ৪২/৪২ PASS |
| Session | ALL PASS |

### বাকি কাজ
1. **অ্যাডমিন rate limiting** (৫ ব্যর্থতায় ১৫ মিনিট) — HIGH
2. **অ্যাডমিন পাসওয়ার্ড শক্ত করা** — ব্যবহারকারী নিজে করবেন

---

## ডিবাগিং টিপস (এই সেশন থেকে শেখা)

1. **Bottom nav / layout ভাঙলে:** `window.innerWidth` চেক করুন। ৩৯০-এর বদলে অনেক বড় হলে horizontal overflow — `document.documentElement.scrollWidth` দেখুন।
2. **কোন এলিমেন্ট overflow করছে:** element-গুলো একটি একটি `display:none` করে `scrollWidth` মাপুন।
3. **reveal animation থাকলে:** মাপার আগে `.is-visible` class যোগ করে ১.৩s অপেক্ষা করুন।
4. **Admin পেজে relative img path:** সবসময় `img_url()` ব্যবহার করুন।
5. **PowerShell-এ বাংলা SQL:** সরাসরি pipeline করবেন না — PHP স্ক্রিপ্ট দিয়ে করুন।
6. **headless Chrome টেস্ট:** `--remote-debugging-port` + CDP (WebSocket) — puppeteer ছাড়াই কাজ করে।
