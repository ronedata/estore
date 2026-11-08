# estore — নতুন প্রোজেক্ট প্ল্যান

**টার্গেট ডিজাইন/ফিচার:** `https://mfeex.shop/` (মূল), `https://demo2.mfeex.shop/` (ডেমো)
**নতুন প্রোজেক্ট:** `C:\laragon\www\estore` (বর্তমানে খালি)
**সোর্স:** `C:\laragon\www\toyShop` (কোর লজিক কপি হবে)

---

## ১. টার্গেট সাইট অ্যানালাইসিস

### mfeex.shop-এ যা আছে (স্ক্র্যাপ করে দেখা)

| # | ফিচার | পেজ | এখন toyShop-এ আছে? |
|---|---|---|---|
| 1 | গ্লাস হেডার (☰ + লোগো + সার্চ + কার্ট) | সব | ❌ |
| 2 | সাইড ড্রয়ার (About/Privacy/Terms/Contact) | সব | ❌ |
| 3 | হিরো Swiper স্লাইডার (৪ স্লাইড, авто ২.৫s) | home | ❌ |
| 4 | হিরো সাইড কার্ড (২টি, মোবাইলে hidden) | home | ❌ |
| 5 | ক্যাটেগরি গোল আইকন রো (৩/৮ কলাম, ৭-রঙ) | home | ❌ (শুধু টেক্সট বাটন) |
| 6 | Featured Products সেকশন (৯টি, ২/৪/৬ কলাম) | home | ⚠️ আছে কিন্তু ভিন্ন |
| 7 | New Arrivals সেকশন (১৮টি) | home | ⚠️ |
| 8 | ডিসকাউন্ট ব্যাজ (৮ রঙের সাইকেল) | home | ⚠️ আছে, এক রঙ |
| 9 | ইনফো বার ৩টি | home | ❌ |
| 10 | পেমেন্ট মেথড বার (COD/bKash/Nagad/Visa/MC) | home | ❌ |
| 11 | স্ক্রল রিভিল অ্যানিমেশন (কিউ ১৫০ms) | home | ❌ |
| 12 | রেইনবো গ্লো গ্লাস ফুটার | সব | ❌ |
| 13 | মোবাইল বটম নেভ (Home/Categories/Deals/Cart/Account) | সব | ❌ |
| 14 | ক্যাটেগরি প্রোডাক্ট পেজ | `category_products.php?id=` | ⚠️ `index.php?cat=` |
| 15 | ডিলস পেজ (সর্বোচ্চ ছাড়) | `deals.php` | ❌ |
| 16 | প্রোডাক্ট ডিটেইলস (গ্যালারি, SKU, স্টক, রেটিং, Sold) | `product_details.php?id=` | ⚠️ `product.php?id=` |
| 17 | **কাস্টমার অ্যাকাউন্ট** (`mobile` + password লগইন/রেজি) | `account.php` | ❌ **নেই** |
| 18 | **প্রোডাক্ট রিভিউ + স্টার রেটিং + ছবি** | product page | ❌ **নেই** |
| 19 | **উইশলিস্ট** | `wishlist.php` | ❌ **নেই** |
| 20 | **SKU** | product | ❌ **নেই** |
| 21 | **Sold Count** | product + card | ❌ **নেই** |
| 22 | About / Privacy / Terms / Contact (৪ পেজ) | — | ❌ **নেই** |
| 23 | লাইভ সার্চ সাজেশন (AJAX) | header | ✅ আছে |
| 24 | ক্যাশ অন ডেলিভারি checkout | — | ✅ আছে |
| 25 | অ্যাডমিন প্যানেল | — | ✅ আছে (ভালো) |

### demo2.mfeex.shop vs mfeex.shop

`demo2` হলো একই থিমের **সরল সংস্করণ** — অ্যাকাউন্ট, রিভিউ, উইশলিস্ট, বটম নেভ ছাড়া।
👉 **mfeex.shop-কে বেস ধরা হবে**, কারণ ওটাই পূর্ণাঙ্গ।

---

## ২. toyShop → estore: কী নিতে হবে

### ✅ A. হুবহু কপি (কোর — হাত লাগবে না)

**কনফিগ ও হেল্পার**
- `includes/config.php` (DB creds নতুন করে লিখতে হবে)
- `includes/stock_helpers.php` ← স্টক/ইনভেন্টরি সিঙ্ক (৩৩টি টেস্ট পাস)
- `includes/functions.php`
- `includes/maintenance.php`

**কার্ট ও অর্ডার (স্টক-নিয়ন্ত্রিত, টেস্টেড)**
- `cart_add.php` — স্টক যাচাই + `stock_out`
- `cart_update.php` — qty ক্ল্যাম্প
- `cart_remove.php`
- `cart_count.php`
- `place_order.php` — `FOR UPDATE` লক, oversell প্রতিরোধ
- `search_suggest.php`

**অ্যাডমিন (সম্পূর্ণ)**
- `admin/auth.php`, `auth_functions.php` (role/permission + `gate()` + CSRF)
- `admin/index.php` (ড্যাশবোর্ড/KPI)
- `admin/products.php`, `product_form.php`, `product_delete.php`, `product_toggle.php`, `product_add_for_mobile.php`, `api_product_search.php`
- `admin/categories.php`
- `admin/orders.php`, `order_view.php`, `order_edit.php`, `order_status.php`, `order_print.php`
- `admin/stock_report.php`
- `admin/users.php`, `roles.php`, `role_form.php`, `permissions.php`, `permission_form.php`
- `admin/settings.php`, `settings_save.php`
- `admin/payments.php`
- `admin/login.php`, `logout.php`, `header.php`, `footer.php`

**অ্যাসেট (পুনর্ব্যবহার)**
- `admin/`-এর CSS/JS (অ্যাডমিন UI বদলাবে না)

> **সতর্কতা:** কপি করার আগে toyShop-এর ২টি ক্রিটিক্যাল সিকিউরিটি ফিক্স করে নিতে হবে —
> (ক) `admin/product_delete.php`-এ `gate('manage_products')` + সঠিক CSRF,
> (খ) `uploads/.htaccess` (PHP এক্সিকিউশন ব্লক), (গ) পারমিশন নাম মিসম্যাচ।

### ♻️ B. কপি করে রিরাইট (লজিক রাখা, UI নতুন)

| ফাইল | লজিক থাকবে | UI বদলাবে |
|---|---|---|
| `product.php` → `product_details.php` | গ্যালারি/ভিডিও/স্পেক/রিলেটেড/স্টক | নতুন থিম + রিভিউ + SKU + Sold |
| `checkout.php` | মোবাইল নরমালাইজ, শিপিং, স্টক ক্ল্যাম্প, flash_error | নতুন থিম |
| `cart.php` | qty ক্ল্যাম্প, স্টক হিন্ট, টোটাল | নতুন থিম |
| `success.php` | অর্ডার সারাংশ | নতুন থিম |
| `track_orders.php` | মোবাইল দিয়ে ট্র্যাকিং | নতুন থিম |
| `single_order_details.php` | অর্ডার ডিটেইল | নতুন থিম |
| `index.php` | প্রোডাক্ট কোয়েরি/ফিল্টার | **সম্পূর্ণ নতুন হোমপেজ** |
| `includes/partials_header.php` | সার্চ/কার্ট কাউন্ট/সেটিংস | **সম্পূর্ণ নতুন** |
| `includes/partials_footer.php` | সেটিংস কুয়েরি (৭ → ১) | **সম্পূর্ণ নতুন** |
| `products_more.php` | AJAX লোড-মোর | বাদ বা `shop.php`-তে merge |
| `assets/style.css` | — | `theme.css` নতুন |
| `assets/app.js` | addToCart/সাজেশন/কার্ট কাউন্ট | `home.js` নতুন (XSS ফিক্স সহ) |

### ❌ C. নেওয়া হবে না

- `includes/live.config.php` (সিক্রেট)
- `.git/`, `.kilo/`, `TASK_LIST.md`
- পুরনো `assets/style.css`-এর ভিজ্যুয়াল নিয়ম

---

## ৩. নতুন যা বানাতে হবে (mfeex-এ আছে, আমাদের নেই)

### ফ্রন্টএন্ড পেজ

| # | ফাইল | কাজ |
|---|---|---|
| 1 | `index.php` | হিরো + ক্যাটেগরি + Featured + New Arrivals + ইনফো বার + পেমেন্ট বার |
| 2 | `shop.php` | সব প্রোডাক্ট (সার্চ, সর্ট, ফিল্টার, পেজিনেশন) |
| 3 | `category_products.php` | এক ক্যাটেগরির প্রোডাক্ট + কাউন্ট |
| 4 | `deals.php` | সর্বোচ্চ ছাড়ের প্রোডাক্ট (`(old-price)/old` DESC) |
| 5 | `product_details.php` | গ্যালারি, SKU, স্টক, রেটিং, Sold, রিভিউ লিস্ট + ফর্ম, রিলেটেড |
| 6 | `account.php` | কাস্টমার লগইন/রেজিস্ট্রেশন (মোবাইল+পাসওয়ার্ড) |
| 7 | `account_dashboard.php` | আমার অর্ডার, প্রোফাইল, লগআউট |
| 8 | `wishlist.php` | পছন্দের তালিকা |
| 9 | `about.php` | কোম্পানি পরিচিতি (settings থেকে) |
| 10 | `privacy_policy.php` | প্রাইভেসি পলিসি |
| 11 | `terms.php` | শর্তাবলী |
| 12 | `contact.php` | যোগাযোগ ফর্ম + ম্যাপ/ফোন/ইমেইল |

### AJAX এন্ডপয়েন্ট

| # | ফাইল | কাজ |
|---|---|---|
| 13 | `wishlist_toggle.php` | উইশলিস্ট অ্যাড/রিমুভ (JSON) |
| 14 | `review_submit.php` | রিভিউ + রেটিং + ছবি |
| 15 | `deals_load.php` | ডিলস ইনফিনিট স্ক্রল (ঐচ্ছিক) |
| 16 | `account_login.php` / `account_register.php` | কাস্টমার অথ |

### partials (একটাই সোর্স)

| # | ফাইল |
|---|---|
| 17 | `includes/partials_header.php` — গ্লাস হেডার + ড্রয়ার |
| 18 | `includes/partials_footer.php` — গ্লাস ফুটার |
| 19 | `includes/partials_bottom_nav.php` — মোবাইল বটম নেভ |
| 20 | `includes/partials_product_card.php` — সব জায়গায় এক কার্ড |
| 21 | `includes/partials_hero.php` — Swiper + সাইড কার্ড |
| 22 | `includes/partials_categories.php` |
| 23 | `includes/partials_info_bar.php` |
| 24 | `includes/partials_payment_bar.php` |
| 25 | `includes/partials_cart_popup.php` |

### অ্যাসেট

| # | ফাইল |
|---|---|
| 26 | `assets/css/theme.css` — টোকেন, হেডার, কার্ড, গ্রিড, ফুটার |
| 27 | `assets/css/animations.css` — scroll reveal + reduced-motion |
| 28 | `assets/js/home.js` — Swiper, ড্রয়ার, সার্চ, observer, মোডাল |
| 29 | `assets/js/cart.js` — add/update/wishlist AJAX |
| 30 | `assets/img/` — bkash/nagad/visa/mc লোগো, leaf-line, placeholder |
| 31 | Swiper 11 CDN |

### অ্যাডমিন (নতুন)

| # | ফাইল | কাজ |
|---|---|---|
| 32 | `admin/sliders.php` | হিরো/সাইড স্লাইডার লিস্ট, active টগল, drag sort |
| 33 | `admin/slider_form.php` | ইমেজ আপলোড, placement, লিংক পিকার, বাটন লেবেল/রঙ |
| 34 | `admin/reviews.php` | রিভিউ অ্যাপ্রুভ/রিজেক্ট/ডিলিট |
| 35 | `admin/customers.php` | কাস্টমার লিস্ট + অর্ডার হিস্টোরি |
| 36 | `admin/pages.php` | About/Privacy/Terms/Contact কনটেন্ট এডিটর |
| 37 | `admin/categories.php` (আপডেট) | ইমেজ + sort_order + active |
| 38 | `admin/payments.php` (আপডেট) | লোগো আপলোড |
| 39 | `admin/settings.php` (আপডেট) | ইনফো বার, হিরো অটোপ্লে, অ্যানিমেশন, থিম কালার |

---

## ৪. ডেটাবেস প্ল্যান (১৩ → ২০ টেবিল)

### কপি হবে (তৈরি হয়ে আছে, টেস্টেড)
`admin_users`, `roles`, `permissions`, `role_permissions`, `categories`, `products`, `product_images`, `product_videos`, `orders`, `order_items`, `settings`, `payment_methods`, `page_views`

### নতুন টেবিল

```sql
-- 1. হিরো স্লাইডার + সাইড কার্ড
CREATE TABLE sliders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  placement ENUM('hero','side') DEFAULT 'hero',
  image VARCHAR(400) NOT NULL,
  title VARCHAR(255) NULL,
  subtitle VARCHAR(255) NULL,
  btn_label VARCHAR(60) DEFAULT 'Shop Now',
  btn_bg VARCHAR(20) DEFAULT '#ff5252',
  link_type ENUM('product','category','url') DEFAULT 'product',
  link_value VARCHAR(255) NULL,
  theme VARCHAR(30) NULL,
  sort_order INT DEFAULT 0,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. কাস্টমার
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mobile VARCHAR(20) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NULL,
  email VARCHAR(160) NULL,
  address TEXT NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. রিভিউ
CREATE TABLE product_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  customer_id INT NULL,
  name VARCHAR(120) NOT NULL,
  rating TINYINT NOT NULL,
  comment TEXT NOT NULL,
  image VARCHAR(400) NULL,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- 4. উইশলিস্ট
CREATE TABLE wishlists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  product_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (customer_id, product_id)
);

-- 5. স্ট্যাটিক পেজ (About/Privacy/Terms/Contact)
CREATE TABLE pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL UNIQUE,
  title VARCHAR(200) NOT NULL,
  content LONGTEXT NULL,
  meta_description VARCHAR(255) NULL,
  active TINYINT(1) DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 6. নিউজলেটার (ফুটারে সাবস্ক্রাইব)
CREATE TABLE subscribers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### বিদ্যমান টেবিলে নতুন কলাম (সব nullable/DEFAULT — পুরনো ডেটা নিরাপদ)

```sql
ALTER TABLE products
  ADD COLUMN sku VARCHAR(40) NULL,
  ADD COLUMN sold_count INT NOT NULL DEFAULT 0,
  ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN badge_color VARCHAR(20) NULL;

ALTER TABLE categories
  ADD COLUMN image VARCHAR(400) NULL,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0,
  ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE payment_methods
  ADD COLUMN logo VARCHAR(400) NULL;

ALTER TABLE orders
  ADD COLUMN customer_id INT NULL;

ALTER TABLE settings
  -- নতুন কী: hero_autoplay_ms, animations_enabled, theme_primary,
  -- theme_secondary, info1-3_title/sub, sold_count_label,
  -- social_facebook/instagram/whatsapp, address, map_embed
```

### পারমিশন যোগ

`manage_sliders`, `manage_reviews`, `manage_customers`, `manage_pages`

---

## ৫. মোবাইল-ফ্রেন্ডলি স্পেসিফিকেশন

| উপাদান | <480 | 480–767 | ≥768 | ≥1024 |
|---|---|---|---|---|
| হেডার | full-width, লোগো শুধু, বাটন ৪০px | একই + শিরোনাম | floating glass radius 18 | একই |
| বটম নেভ | **দেখাবে** (৫ আইকন) | দেখাবে | **লুকাবে** | লুকাবে |
| প্রোডাক্ট গ্রিড | **২** | ২ | **৪** | ৪ / ৫ |
| ক্যাটেগরি | **৩** | ৩ | **৮** | ৮ |
| হিরো | ১ কলাম | ১ | `2fr 1fr` | একই |
| সাইড কার্ড | **hidden** | hidden | দেখাবে | দেখাবে |
| ইনফো বার | ১ | ১ | ৩ | ৩ |

**বাধ্যতামূলক**
- টাচ টার্গেট ≥ ৪০×৪০px
- `100dvh` (পুরনো `100vh` নয়)
- `env(safe-area-inset-*)` — নচ/হোম বার
- ইনপুট `font-size: 16px` (iOS zoom রোধ)
- হরিজন্টাল স্ক্রল = ০
- `prefers-reduced-motion` respect
- মোবাইলে ভারী ব্লার/গ্লো অ্যানিমেশন বন্ধ
- `loading="lazy"` + `decoding="async"` সব ছবিতে
- `width/height` অ্যাট্রিবিউট → CLS কমাতে

---

## ৬. ধাপে ধাপে কাজ

| ধাপ | কাজ | ফল |
|---|---|---|
| **০** | toyShop-এর ২টি সিকিউরিটি ফিক্স, DB ব্যাকআপ, `estore` ফোল্ডার সেটআপ, `git init` | পরিষ্কার কোর |
| **১** | কোর কপি (A অংশ) + নতুন `config.php` + `toysbd_estore` DB | অ্যাডমিন কাজ করে, পুরনো সাইটের সমান |
| **২** | DB migration (sliders/customers/reviews/wishlists/pages/subscribers + কলাম) | স্কিমা প্রস্তুত |
| **৩** | `admin/sliders.php` + `slider_form.php` + categories ইমেজ + payments লোগো | কনটেন্ট দেওয়ার পথ |
| **৪** | `theme.css` + `partials_header/footer/bottom_nav` | **সব পেজ নতুন লুক** |
| **৫** | `index.php` (হিরো, ক্যাটেগরি, ২ সেকশন, ইনফো, পেমেন্ট) + partials | হোমপেজ সম্পূর্ণ |
| **৬** | `shop.php`, `category_products.php`, `deals.php`, `product_details.php` | ব্রাউজ/ডিটেইল |
| **৭** | `account.php`, `account_dashboard.php`, `wishlist.php` + কাস্টমার অথ | অ্যাকাউন্ট সিস্টেম |
| **৮** | রিভিউ সিস্টেম (`review_submit.php` + `admin/reviews.php`) | সোশ্যাল প্রুফ |
| **৯** | `about/privacy/terms/contact` + `admin/pages.php` | স্ট্যাটিক পেজ |
| **১০** | `admin/customers.php` + `admin/settings.php` নতুন সেকশন | সম্পূর্ণ অ্যাডমিন কন্ট্রোল |
| **১১** | রেসপন্সিভ টেস্ট (৩২০/৩৭৫/৪১৪/৭৬৮/১০২৪/১৪৪০) + টেস্ট স্যুট মাইগ্রেট | যাচাই |
| **১২** | `changelog` + রিলিজ | লঞ্চ |

---

## ৭. সিদ্ধান্ত দরকার

| # | প্রশ্ন | সুপারিশ |
|---|---|---|
| D1 | কাস্টমার অ্যাকাউন্ট লাগবে? | **হ্যাঁ** — mfeex-এ আছে, গেস্ট চেকআউটও থাকবে |
| D2 | রিভিউর জন্য কিনতে হবে? | mfeex-এ "আগে কিনুন তারপর রিভিউ" — **এটাই রাখা** |
| D3 | রিভিউ অটো-অ্যাপ্রুভ নাকি মডারেশন? | **মডারেশন** (স্প্যাম ঠেকাতে) |
| D4 | উইশলিস্ট গেস্টের জন্য? | শুধু লগইন করা কাস্টমার (সেশন-ভিত্তিক চাইলে বাড়তি কাজ) |
| D5 | মোবাইল বটম নেভ? | **হ্যাঁ** — mfeex-এ আছে, কনভার্সনে ভালো |
| D6 | ডেস্কটপ গ্রিড | ২/৪/**৫** (৬ নয়, বাংলা নাম কাটে) |
| D7 | থিম কালার | বেগুনি+কমলা, অ্যাডমিন থেকে বদলানো যাবে |
| D8 | হেডার | sticky |
| D9 | ANIMATION মোবাইলে | বন্ধ |
| D10 | ডিলস পেজে infinite scroll? | হ্যাঁ (mfeex-এর মতো) |
| D11 | পেমেন্ট গেটওয়ে | আপাতত COD + ম্যানুয়াল bKash/Nagad নম্বর (আসল API পরে) |
| D12 | নিউজলেটার | ফুটারে রাখা (সাবস্ক্রাইবার টেবিল সহ) |

---

## ৮. গুরুত্বপূর্ণ নোট

1. **toyShop ফ্রিজ থাকবে** — estore-এ কাজ শুরু হলে পুরনো প্রোজেক্টে নতুন ফিচার যোগ হবে না।
2. **কোর লজিক পুনরায় লিখবেন না** — স্টক/ইনভেন্টরি/oversell লজিক ৩৩+৩৯ টেস্টে পাস করা, ওটাই ব্যবহার করুন।
3. **প্রথম দিনেই সিকিউরিটি ঠিক** — `gate()`, CSRF, `.htaccess`, পারমিশন নাম — নতুন প্রজেক্টে ঋণ নিয়ে শুরু নয়।
4. **ফ্রন্টএন্ড আর অ্যাডমিন আলাদা** — অ্যাডমিন Bootstrap-এ থাকবে, ফ্রন্টএন্ডে নতুন থিম। দুটো একসাথে মেশাবেন না।
5. **ডেটা মাইগ্রেশন** — পুরনো `toysbd` DB থেকে product/category/orders কপি করা যাবে; শুধু নতুন কলামে ডিফল্ট বসবে।
