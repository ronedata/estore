# AGENTS.md — eStore প্রজেক্ট কনটেক্সট

> নতুন সেশন শুরু হলে এই ফাইলটি প্রথমে পড়ুন। এখানে সব সিদ্ধান্ত, পরিবর্তন ও
> বর্তমান অবস্থার সারসংক্ষেপ আছে।

---

## ১. প্রজেক্ট পরিচিতি

| বিষয় | মান |
|---|---|
| নাম | eStore (Toy Shop / Toy BD) |
| লোকাল পাথ | `C:\laragon\www\estore` |
| লোকাল URL | `http://estore.test` (Apache/Laragon) অথবা `http://127.0.0.1:8000` (PHP built-in) |
| লাইভ হোস্ট | InfinityFree — হোস্ট/DB নাম `includes/live.config.php`-এ (গিট-এ নেই) |
| স্ট্যাক | PHP 8.1 + MySQL/MariaDB, vanilla CSS/JS, Font Awesome 6.5 CDN |
| ডিজাইন রেফারেন্স | **https://mfeex.shop/** / **https://demo2.mfeex.shop/** |

### DB কনফিগ
- `includes/config.php` → লোকাল (`root`, পাসওয়ার্ড নেই, DB `estore`)
- `includes/live.config.php` → লাইভ (InfinityFree credentials)
- **দুই ফাইলের কোড এক, শুধু DB ব্লক আলাদা।** যাচাই: `php tools/compare_configs.php` (মুছে ফেলা হয়েছে, দরকার হলে আবার বানাতে হবে)

---

## ২. ব্যবহারকারীর পছন্দ ও নিয়ম

- ভাষা: **বাংলায় উত্তর দিন**
- কাজ ধাপে ধাপে, প্রতিটি ধাপ শেষে যাচাই করে রিপোর্ট
- কোনো ফিচার যোগ করলে **টেস্ট করে প্রমাণ** দেখাতে হবে (headless Chrome + curl)
- অপ্রয়োজনীয় কমেন্ট কোডে নয়
- লাইভে কী আপলোড করতে হবে তা প্রতিবার স্পষ্ট করে বলা
- UI টেক্সট বাংলায়, কিন্তু টেকনিক্যাল টার্ম ইংরেজিতে চলবে

---

## ৩. ডিজাইন সিস্টেম (mFeex)

| জিনিস | মান |
|---|---|
| primary | `#0284c7` |
| accent | `#ff7043` |
| success | `#2e7d32` |
| ফন্ট | Poppins + Hind Siliguri |
| shell | `--shell-max: 1240px`, `--shell-pad: clamp(14px,3.2vw,26px)` |
| glass header | `backdrop-filter: blur(22px)` + `headerSweep` animation |
| footer | rainbow glass (`rainbowBoxGlow`, `rainbowBg`) |

### সংবেদনশীল প্যাটার্ন (ভাঙবেন না)
- `.main-header`-এ `overflow: visible` → **মোবাইলে horizontal overflow হয়ে bottom-nav ভাঙে**। `overflow: hidden` রাখতে হবে।
- sweep animation-এ `left:-50%; width:200%` **ব্যবহার করবেন না** — `inset:0` + `background-position` ব্যবহার করা হয়েছে।
- `body`-তে `overflow-x: hidden` দিলে `position:fixed` ভাঙে → `html`-এ `overflow-x: clip` রাখা হয়েছে।
- CSS grid-এ সব জায়গায় `minmax(0, 1fr)` — নইলে min-content overflow হয়।
- **ছবির বক্সে স্থির `height` দেবেন না** — আপলোড করা ছবির অনুপাত স্থির, বক্স স্থির উচ্চতার হলে `object-fit:cover` ছবি কেটে ফেলে। বক্স `aspect-ratio` দিয়ে ছবির অনুপাতের সাথে মেলাতে হবে: `.fruit-img` ১:১, `.hero-card-content` ১৬:৯, সাইড কার্ড ~১৬:১০ (কলাম `2.3fr 1fr`, কার্ড `flex:1` দিয়ে হিরোর উচ্চতা ভাগ করে)।
- হিরো/সাইড কার্ডের কলাম অনুপাত (`2.3fr 1fr`) বদলালে সাইড কার্ডের ১৬:১০ ভেঙে যায় — বদলালে `verify_hero.js` ও ৩টি প্রস্থে অনুপাত আবার মাপুন।
- CSS বদলালে ব্রাউজার পুরোনো ফাইল ক্যাশ করে রাখে (মাপার সময়ও) → hard refresh / cache-bypass; `partials_header.php`-এ `?v=` নেই।

---

## ৪. ডেটাবেস স্কিমা (v2–v7 অটো-মাইগ্রেশন)

`includes/config.php` (ও `live.config.php`) শেষে ছয়টি মাইগ্রেটর (v2–v7) চলে:

| ফাইল | যোগ করে |
|---|---|
| `schema_v2.php` | sliders, customers, reviews, wishlists, pages টেবিল |
| `schema_v3.php` | `sliders.btn_size`, `btn_pos_x`, `btn_pos_y` |
| `schema_v4.php` | `products.is_new_arrival` |
| `schema_v5.php` | `contact_messages` টেবিল |
| `schema_v6.php` | `sliders.show_text` (টেক্সট ওভারলে টগল, default 0) |
| `schema_v7.php` | `home_sections` + `home_section_products` টেবিল, `manage_home_sections` পারমিশন, বিদ্যমান Featured/New Arrivals সিড (`is_featured`/`is_new_arrival` থেকে কপি) |

চিহ্ন: `settings.schema_v2_at` … `schema_v7_at` (একবার চলে, দ্বিতীয়বার কিছু করে না)।

### হোম সেকশন নিয়ম
- সর্বোচ্চ **৮**, ন্যূনতম **২** সেকশন (`HS_MAX`/`HS_MIN`); মোছা/বন্ধ করা সার্ভারে ট্রানজাকশনের ভেতরে আটকানো হয়
- type: `manual` (প্রোডাক্ট ফর্ম থেকে বাছাই), `category`, `discount`, `latest`, `bestseller`; পণ্য সংখ্যা ১–৪৮
- `home_sections.new_default=1` সেকশনে নতুন পণ্যে ডিফল্ট টিক (সিডে New Arrivals)
- পণ্য না থাকলে সেকশন হোম থেকে লুকায়
- `products.is_featured`/`is_new_arrival` কলাম **আর লেখা হয় না** (শুধু মাইগ্রেশনে কপি); সত্য-উৎস `home_section_products`
- `settings.featured_title` ইত্যাদি আর ব্যবহৃত নয় (DB-তে পড়ে আছে)
- পণ্য মুছলে `home_section_products` সারিও মুছে (FK/CASCADE নেই — কোডে স্পষ্ট DELETE)

### বর্তমান ডেটা
```
products: 24   categories: 3   sliders: 5
orders: 32     contact_messages: 1
home_sections: 2   home_section_products: 28 (Featured 7 + New Arrivals 21)
```

### ডেটাবেস ব্যাকআপ
- লোকাল ডাম্প: `database/database_estore_26-11-05.sql` (v7 টেবিলসহ, ২২ টেবিল); আগেরটি `database_estore_26-11-02.sql`
- নতুন ডাম্প: `mysqldump -uroot --default-character-set=utf8mb4 --single-transaction --add-drop-table estore > database/database_estore_YY-MM-DD.sql`
- লাইভের ব্যাকআপ আলাদা — phpMyAdmin → Export (আপলোডের আগে নিন)
- `database/` লাইভে আপলোড করবেন না

---

## ৫. মূল ফিচার ম্যাপ

| ফিচার | ফাইল |
|---|---|
| গ্লাস হেডার + search + cart | `includes/partials_header.php` |
| সাইডবার ড্রয়ার | `includes/partials_header.php` |
| Bottom nav (mobile) | `includes/partials_footer.php` |
| Rainbow glass footer | `includes/partials_footer.php` |
| প্রোডাক্ট কার্ড (`fruit-card`) | `includes/partials_product_card.php` |
| হোম (hero/categories/grids/info bar) | `index.php` |
| স্ট্যাটিক পেজ রেন্ডারার | `includes/render_page.php` |
| ইমেজ প্রসেসিং (crop/pad) | `includes/image_processor.php` |
| ইমেজ URL রিজলভার | `includes/config.php` → `img_url()` |
| সিকিউরিটি রিপোর্ট | `admin/security.php` |
| Page Views রিপোর্ট | `admin/page_views.php` (পারমিশন `view_page_views`) |
| হোম সেকশন ম্যানেজার | `admin/home_sections.php`, `admin/home_section_form.php`, helper `includes/home_sections.php` (পারমিশন `manage_home_sections`) |
| প্রোডাক্ট ফর্মের সেকশন টগল | `admin/product_form.php`, `admin/product_add_for_mobile.php` (`section_ids[]`) |
| সেকশনের "সব দেখুন" | `shop.php?section=ID` |

### ইমেজ টার্গেট সাইজ
| ধরন | সাইজ | অনুপাত |
|---|---|---|
| Product | 700×700 | 1:1 |
| Slider hero | 1200×675 | 16:9 |
| Slider side | 600×375 | 16:10 |
| Category | 400×400 | 1:1 |
| Site logo | repeater | — |

বড় → center-crop, ছোট → সাদা প্যাডিং। `estore_process_image($tmp,$dest,$w,$h)`।

- `product_form.php` আপলোডে প্রতিটি ছবি (jpg/png/webp) অটো ৭০০×৭০০ হয় (`estore_process_product_image`)।
- ব্যতিক্রম: **HEIC/HEIF** (GD পড়তে পারে না) হুবহু সেভ হয়; GD না থাকলে/ব্যর্থ হলে raw fallback; ৫MB-এর বেশি বা আসল ছবি নয় → বাদ।
- আপলোডে কাটা ছবি CSS দিয়ে ফেরানো যায় না — আবার আপলোড করতে হয়।
- **সিদ্ধান্ত:** `object-fit:cover` + অনুপাত-মেলানো বক্স রাখা হয়েছে; `contain`/fit বা আপলোডে fit (সাদা প্যাডিং) করা হয়নি, কারণ ছবি আগে থেকেই বক্সের অনুপাতে প্রসেস হয়, `contain` দিলে কোনো লাভ নেই, উল্টে ফাঁকা অংশ আসে।

---

## ৬. নিরাপত্তা — সম্পন্ন কাজ

| # | কাজ | ফাইল |
|---|---|---|
| 1 | CSRF: cart_add, cart_update, cart_remove, wishlist_toggle, place_order | ওই ৫টি + `partials_header.php` (meta tag) + `assets/js/home.js` |
| 2 | root `.htaccess` — includes/database/tools/*.sql block | `.htaccess` |
| 3 | `admin/security.php` — রিপোর্ট পেজ (Super Admin only) | `admin/security.php`, `admin/header.php` |
| 4 | Session: HttpOnly/Secure/SameSite + fixation + ৩০মিন ID রোটেশন | `includes/config.php`, `live.config.php` |
| 5 | Upload: `getimagesize()` + ৫MB লিমিট | `admin/settings_save.php`, `product_form.php`, `product_add_for_mobile.php` |
| 6 | `hash_equals()` (৬ জায়গা) | `product_form.php` (৩), `product_add_for_mobile.php`, `product_toggle.php`, `settings_save.php` |
| 7 | CSRF array-safe: সব CSRF চেক এখন `csrf_verify()` (array দিলে reject, fatal/path leak নেই) | `includes/config.php`, `live.config.php`, `admin/auth_functions.php` (dead code সরানো), `admin/login.php`, `account.php`, `account_dashboard.php`, `review_submit.php`, `wishlist.php`, `admin/order_edit.php`, `admin/payments.php`, ৪টি admin guard |
| 8 | Production error handling: `APP_DEBUG` auto-detect (host ভিত্তিক), লাইভে `display_errors=0` + `logs/php_error.log`, ফ্যাটালে generic 500 পেজ | `includes/config.php`, `live.config.php` |
| 9 | `logs/` ওয়েব থেকে ব্লক | `.htaccess` |

### ⚠️ গুরুত্বপূর্ণ
- **`http_response_code(419)` ব্যবহার করবেন না** — Apache 500 দেয়। `403` ব্যবহার করুন।
- **`hash_equals('', '')` → `true`** — খালি টোকেন গার্ড ছাড়া CSRF সম্পূর্ণ বাইপাস হয়।
- **`csrf_verify()` ছাড়া raw `hash_equals` লিখবেন না** — `csrf[]=1` পাঠালে TypeError → ফ্যাটাল + full path leak। `csrf_verify()` দুটোই (array + empty) হ্যান্ডেল করে।
- অডিট রেজাল্ট: **Total 0 (C:0 H:0 M:0 L:0)**

### বাকি কাজ
| # | কাজ | অগ্রাধিকার |
|---|---|---|
| 8 | অ্যাডমিন লগইনে rate limiting (৫ ব্যর্থতায় ১৫ মিনিট) | HIGH |
| — | অ্যাডমিন পাসওয়ার্ড শক্ত করা | **ব্যবহারকারী নিজে করবেন** |

### প্রস্তাবিত আপডেট (এখনও করা হয়নি)
| কাজ | নোট |
|---|---|
| CSS/JS ক্যাশ ভার্সন `?v=` (ফাইলের mtime থেকে) | আপডেটের পর পুরোনো CSS দেখানো বন্ধ হবে; ছোট কাজ |
| `git init` (`.gitignore`-এ `live.config.php`, `uploads/`, `logs/`, `database/*.sql`) | এখন ভার্সন কন্ট্রোল নেই, হাতে ব্যাকআপ কপি রাখতে হয় |
| `tools/compare_configs.php` ফিরিয়ে আনা | দুই config-এ ১৩ লাইন পার্থক্য, হাতে মেলানো হয় |
| `product.php` (37KB) বনাম `product_details.php` (21KB) যাচাই | একটি সম্ভবত পুরোনো/ডুপ্লিকেট — এখনও যাচাই হয়নি |
| manual সেকশনে পণ্যের ক্রম (`home_section_products.sort_order` কলাম আছে, UI নেই) | নতুন পণ্য আগে আসে |
| সেকশন ম্যানেজারে drag-and-drop ক্রম | এখন ↑↓ বাটন |
| ছোট (~৩৫০px) ও WebP ছবি | হোম দ্রুত হবে; কার্ডে ২৪৮px দেখায়, ফাইল ৭০০px |
| হোমে ৮টি সেকশনে ৮টি কুয়েরি (প্রতিটিতে sold সাবকুয়েরি) | সীমিত হোস্টে ধীর হতে পারে — আগে মাপুন |
| `database/*.sql` ওয়েব-রুটের বাইরে নেওয়া; `assets/style.css`/`app.js` ব্যবহৃত কি না যাচাই | পরিচ্ছন্নতা |
| অর্ডার নোটিফিকেশন, কুপন, SEO/Open Graph, sitemap | অনুমান — এখনও যাচাই/পরিকল্পনা হয়নি |

---

## ৭. টেস্ট টুল

| ফাইল | কাজ |
|---|---|
| `node tools/security_audit.js` | সিকিউরিটি স্ক্যান → `security_audit_report.json` |
| `node tools/test_csrf.js http://estore.test` | CSRF ১৮টি চেক (array CSRF, raw endpoint সহ) |
| `php tools/test_stock_flow.php` | অর্ডার↔স্টক ট্রানজিশন ১০টি চেক (rollback-safe) |
| `node tools/test_cart_wishlist.js http://estore.test` | cart/wishlist remove ৯টি চেক |
| `php tools/test_wishlist_ui.php` | wishlist remove মার্কআপ/JS/CSS ৭টি চেক |
| `node tools/verify_bottom_nav.js <url>` | bottom nav ২০টি চেক (মোবাইল) |
| `node tools/verify_slider_edit.js <url> <id> <user> <pass>` | slider ইমেজ লোড |
| `node tools/check_hero.js <url>` | হোম hero অনুপাত যাচাই |
| `node tools/audit_gutters.js <url>` | ১৪ পেজ × ৩ প্রস্থ gutter |
| `node tools/measure_mobile_margins.js <url> [page]` | নির্দিষ্ট পেজের margin |
| `node tools/check_info_align.js <url>` | info bar alignment |
| `node tools/verify_see_all.js <url>` | "সব দেখুন" বাটন (গ্রিডের **নিচে** ও মাঝখানে আছে কি), section padding ৮৪টি চেক (সেকশন সংখ্যা ডাইনামিক, অন্তত ১টি বাটন) |
| `node tools/verify_hero.js <url>` | হিরো স্লাইডার + সাইড কার্ড টেক্সট ওভারলে চেক (per-slide, show_text সাপেক্ষে; চেক সংখ্যা স্লাইডার ডেটার ওপর নির্ভর করে — ৮৩ ও ৬৫ দেখা গেছে, দুটোই সব পাস) |
| `php tools/test_home_sections.php` | হোম সেকশন লজিক ৪০টি চেক (সীমা ৮/২, ক্রম, সংযোগ, মাইগ্রেশন; rollback-safe না, নিজে পরিষ্কার করে) |
| `node tools/verify_home_sections.js <url>` | হোম সেকশন end-to-end ৫৮টি চেক (`ADMIN_COOKIE` লাগে) |
| `php tools/make_admin_session.php create/destroy <sid>` | টেস্টের অস্থায়ী Super Admin সেশন (পাসওয়ার্ড লাগে না, শুধু CLI) |
| `node tools/verify_slider_form.js <url> [id]` | slider_form-এর show_text checkbox ১৩টি চেক (`ADMIN_COOKIE` env লাগে) |
| `node tools/verify_admin_sidebar.js <url>` | admin sidebar scroll ১৬টি চেক (`ADMIN_COOKIE` লাগে) |
| `node tools/verify_page_views.js <url>` | Page Views পেজ, প্রোডাক্ট ট্যাব, k-ফরম্যাট ২৯টি চেক (`ADMIN_COOKIE` লাগে) |
| `node tools/verify_search_suggest.js <url>` | লাইভ সার্চ সাজেশন ৪১টি চেক (দৃশ্যমানতা, পজিশন, কীবোর্ড) |
| `node tools/verify_review_flow.js <url> [pid]` | রিভিউ ফর্ম→সাবমিট→flash→ডুপ্লিকেট ১৪টি চেক (`CUSTOMER_COOKIE` লাগে) |
| `node tools/verify_review_moderation.js <url>` | Admin approve/reject/delete ১৩টি চেক (`ADMIN_COOKIE` লাগে) |
| `node tools/verify_permissions_ui.js <url>` | পারমিশন↔মেনু নাম মিল ১৪টি চেক, পারমিশন সংখ্যা ১৫ ধরে (`ADMIN_COOKIE` লাগে) |
| `node tools/measure_payment_section.js <url>` | নির্দিষ্ট section-এর box/padding/anchor chain ডায়াগনস্টিক |
| `php tools/test_all_image_sizes.php` | ৩৫টি ইমেজ কেস |
| `php tools/test_image_processor.php` | ৭টি প্রসেসিং কেস |
| `php tools/test_session_security.php` | সেশন ini ফ্ল্যাগ |
| `php tools/seed_pages.php` | pages টেবিলে কনটেন্ট সিড |
| `php tools/seed_products.php [--dry-run]` | ১২টি ডেমো প্রোডাক্ট + 700×700 ইমেজ সিড (SKU ভিত্তিক idempotent) |
| `php tools/seed_sliders.php [--dry-run]` | ৩ হিরো + ২ সাইড কার্ড সিড (পুরোনো deactivate, idempotent) |
| `php tools/diagnose_images.php` | লাইভ ইমেজ ডায়াগনস্টিক (ব্রাউজারে খুলতে হয়) |

---

## ৮. অ্যাডমিন লগইন

```
URL: /admin/login.php
users: (admin_users টেবিলে দেখুন; সবই role = Super Admin)
পাসওয়ার্ড: ব্যবহারকারী নিজে পরিবর্তন করবেন — কোডে লিখবেন না
```

### পারমিশন সিস্টেম
- `admin/auth_functions.php` → `can()`, `gate()`
- `admin/permission_labels.php` → `permission_label()`, `permission_icon()`, `permission_group()`, `permission_grouped()` — identifier → সাইডবারের বাংলা নাম/গ্রুপের ম্যাপ
- **নতুন মেনু যোগ করলে `permission_labels.php`-এ এন্ট্রিও দিন**, নাহলে permissions/role_form-এ identifier দেখাবে
- Role `Super Admin` = সব পারমিশন বাইপাস
- `admin/security.php` শুধু Super Admin

---

## ৯. পুরোনো সিদ্ধান্ত ও সমাধান (যাতে আবার ভুল না হয়)

| সমস্যা | সমাধান |
|---|---|
| মোবাইলে bottom nav স্ক্রলে সরে যায় | `body`-র `overflow-x:hidden` সরিয়ে `html`-এ `clip`; nav-এ solid bg |
| Bottom nav দেরিতে আসে | Swiper CSS non-blocking + JS `defer` |
| হোমে info bar ডানে ফাঁকা | `repeat(3, 1fr)` (auto-fit নয়) |
| info bar মোবাইলে ২৩px | reveal animation-এর `scale(.96)` — মাপার আগে animation শেষ করতে হবে |
| `uploads/.htaccess`-এ 500 | `Options` ডাইরেক্টিভ InfinityFree-এ নিষিদ্ধ — কমেন্ট করে দিতে হবে |
| স্লাইডার এডিটে ইমেজ ভাঙা | relative path → `img_url()` (absolute) |
| লোগো আপলোডে পুরোনো ফাইল মুছে যাওয়া | `sf_delete_logo_file()` path guard |
| বাংলা mojibake | `sf_clean_text()` + `SET NAMES utf8mb4` |
| `image_processor.php` missing require | `product_add_for_mobile.php`-এ যোগ করা হয়েছে |
| কার্ট থেকে পণ্য মুছত না | `cart.php`-এর JS-এ CSRF টোকেন পাঠানো হয়নি → এখন `meta[name=csrf-token]` থেকে পাঠায় |
| `next[]=x` দিলে ফ্যাটাল + path leak | `next` সব জায়গায় `is_string()` গার্ড; `h()` non-scalar ইনপুটে খালি স্ট্রিং দেয় |
| প্রতি ভিজিটে ২টি PHPSESSID | খালি সেশনে regenerate বন্ধ — শুধু ৩০ মিনিট পর rotate |
| অর্ডারে customer_id NULL | `place_order.php` এখন সেশনের customer যাচাই করে `customer_id` লেখে |
| wishlist-এ remove বাটন ছিল না | `$cardRemoveWishlist` ফ্ল্যাগ + `removeFromWishlist()` JS যোগ |
| `payment-service-section`/`info-bar-section`-এর কনটেন্ট কিনারায় লেগে থাকে | `main.wrap .X{padding-left/right:0}` ভেতরের padding-ও মুছে দিত → কার্ড-সেকশন দুটোকে আলাদা রুলে `padding-left/right:24px` |
| "সব দেখুন" বাটন শিরোনামের নিচে, মোবাইলে প্রোডাক্ট নিচে ঠেলে দিত | বাটন এখন সেকশনের গ্রিডের **পরে** `.section-see-all-wrap`-এ (মাঝখানে; ≤৬০০px-এ প্রস্থ `min(100%,320px)`, উচ্চতা ~৪৪px) |
| কার্ডের ছবি কাটা (গ্যাসের চুলা, ট্রাইপডের ওপর-নিচ) | `.fruit-img`-এর `height:160/120/85px` → `aspect-ratio:1/1;height:auto` (৭০০×৭০০ ছবি পুরো দেখায়; কার্ড ডেস্কটপে ~৮৮px লম্বা) |
| সাইড কার্ড/হিরো ছবি কাটা (বক্স ১.৪৭–১.৮৯, ছবি ১.৬/১.৭৮) | হিরো `aspect-ratio:16/9`; কলাম `2.3fr 1fr`; সাইড কার্ড `flex:1 1 0;height:auto` → সব প্রস্থে হিরো ১.৭৮, সাইড ১.৬০–১.৬৩, দুই সাইড কার্ডের মোট উচ্চতা = হিরো |
| হোমপেজে ১৮-এর বেশি featured/new প্রোডাক্ট অদৃশ্য | দুই সেকশনে `.section-see-all` → `shop.php?filter=featured` / `?sort=new` |
| Admin সাইডবার স্ক্রল হয় না | `.sidebar` flex-column + `.navy`-তে `flex:1;min-height:0;overflow-y:auto` |
| Page Views ড্যাশবোর্ডে ছোট Top-10 ছিল | আলাদা `admin/page_views.php` + `view_page_views` পারমিশন + সাইডবার মেনু |
| লাইভ সার্চ সাজেশন দেখা যায় না | `#suggestBox` header-এর **বাইরে** (header-এ `overflow:hidden` থাকে) + `position:fixed` + JS `positionSuggest()` |
| ফ্ল্যাশ বার্তায় বাড়তি `:` (`err:`) | prefix অফসেট ভিন্ন (`ok:`=৩, `err:`=৪) → `flash_message()` helper ব্যবহার করুন, `substr($flash,3)` নয় |
| রিভিউ দেওয়ার পরেও ফর্ম দেখা যায় | `$alreadyReviewed` চেক — approved/pending অনুযায়ী ফর্ম লুকিয়ে বার্তা |
| হোমে সেকশন হার্ডকোড (Featured/New Arrivals), প্রোডাক্ট ফর্মে হার্ডকোড দুই টগল | `home_sections` + `home_section_products` (v7); ফর্মে `section_ids[]` লুপ; `hs_set_product_sections()` ইনপুট নিজে ফিল্টার করে |
| `LIMIT 18` হার্ডকোড | সেকশনের `product_limit` (১–৪৮), `hs_products()` |
| দুই ট্যাবে একসাথে সেকশন যোগ করলে ৮-এর বেশি/২-এর কম হতে পারত | সব লেখা ট্রানজাকশনে, `SELECT COUNT(*) … FOR UPDATE` দিয়ে সীমা যাচাই |
| `shop.php`-এ named প্যারামিটারের সাথে positional `?` মেশানো যায় না | `hs_query_parts($sec, true)` → `:hs_sid` / `:hs_cat` |
| `product_form.php` / `product_add_for_mobile.php`-এ POST টেস্টে কিছু সেভ হয় না | সাবমিট বাটনের `save_product` ফিল্ড না পাঠালে সেভ ব্লকটাই চলে না |
| config ফাইলে `\n` অ্যাঙ্কর দিয়ে স্ক্রিপ্ট-এডিট মেলে না | ফাইলে CRLF আছে (live.config.php-এ CRLF+LF মিশ্র) — এক-লাইনের অ্যাঙ্কর / Edit টুল ব্যবহার করুন |
| PHP ডাবল-কোট স্ট্রিংয়ে `"$var→x"` এ Undefined variable | বহু-বাইট অক্ষরও ভেরিয়েবল নামের অংশ হয় → `{$var}` ব্যবহার করুন |
| `can()` খালি `admin_permissions` হলে Super Admin-কেও false দেয় | টেস্ট সেশনে অন্তত একটি পারমিশন রাখুন (`make_admin_session.php` তাই করে) |

---

## ১০. লাইভ ডিপ্লয় চেকলিস্ট

আপলোডের পর চেক করুন:

```
https://yoursite.com/index.php              → 200
https://yoursite.com/database/              → 403
https://yoursite.com/includes/config.php    → 403
https://yoursite.com/tools/                 → 403
https://yoursite.com/admin/login.php        → 200
https://yoursite.com/uploads/default.jpg    → 200
https://yoursite.com/assets/css/theme.css   → 200
```

**স্বাভাবিক সমস্যা:** CSS আপডেট হয় না → hard refresh (`Ctrl+Shift+R`)।

### v7 (হোম সেকশন) আপলোড
1. লাইভ DB ব্যাকআপ (phpMyAdmin → Export)
2. নতুন: `includes/schema_v7.php`, `includes/home_sections.php`, `admin/home_sections.php`, `admin/home_section_form.php`
3. বদলানো: `includes/live.config.php` (লাইভে `config.php` নামে), `index.php`, `shop.php`, `admin/header.php`, `permission_labels.php`, `auth_functions.php`, `product_form.php`, `product_add_for_mobile.php`, `product_delete.php`, `settings.php`, `settings_save.php`
4. হোমপেজ একবার খুললে মাইগ্রেশন নিজে চলে; phpMyAdmin-এ `home_sections` (২ সারি) ও `schema_v7_at` দেখুন
5. অ্যাডমিনে লগআউট→লগইন (পারমিশন সেশনে জমা থাকে), তারপর "হোম সেকশন" মেনু
6. চেক: `/admin/home_sections.php` → 200, `/shop.php?section=1` → 200, `/includes/schema_v7.php` → 403

---

## ১১. ফাইল পরিবর্তনের নিয়ম

- নতুন ফিচার → টেস্ট টুলও বানান (`tools/`)
- PHP ফাইল বদলালে `php -l` চালান
- CSS বদলালে brace balance যাচাই করুন
- ডেটাবেসে টেস্ট ডেটা তৈরি করলে **অবশ্যই মুছে ফেলুন**
- `includes/config.php` ও `includes/live.config.php` সবসময় সিঙ্ক রাখুন
- নতুন admin মেনু যোগ করলে ৪ জায়গা: `admin/header.php`, `permission_labels.php`, `auth_functions.php` (`get_first_allowed_page`), এবং মাইগ্রেশনে পারমিশন insert
- নতুন পারমিশন যোগ হলে `tools/verify_permissions_ui.js`-এর সংখ্যা (এখন ১৫) বদলাতে হবে
- বড় পরিবর্তনের আগে ফাইলের ব্যাকআপ কপি রাখুন (প্রজেক্ট git-এ নেই); এবারেরটি `C:\laragon\wwwstore_backup_pre_homesections\`
