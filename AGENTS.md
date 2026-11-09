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
| `schema_v8.php` | `announcements` টেবিল (বার/পপআপ), `manage_announcements` পারমিশন |
| `schema_v10.php` | `orders.discount` (DECIMAL, ডিফল্ট ০), `discount_note`, `discount_by` |
| `schema_v9.php` | `delivery_districts` (৬৪ জেলা সিড, `fee` NULL-able), `orders.shipping_district`, `settings.delivery_global`, `manage_delivery` পারমিশন |

চিহ্ন: `settings.schema_v2_at` … `schema_v7_at` (একবার চলে, দ্বিতীয়বার কিছু করে না)।

### ডেলিভারি চার্জ নিয়ম
- `delivery_districts.fee`: **NULL (ফাঁকা) = গ্লোবাল** (`settings.delivery_global`), **0 = ওই জেলায় ফ্রি**; দুটি আলাদা — `''` কে 0 বানাবেন না
- সব পণ্যে `free` ট্যাগ থাকলে জেলা যাই হোক ০ (সার্ভারেই হিসাব, ক্লায়েন্টের ফ্ল্যাগ/ফি বিশ্বাস করা হয় না)
- `orders.shipping_area` ENUM **অক্ষত**; `shipping_district` (বাংলা নাম) আলাদা কলাম; `shipping_area` নিজে ভরে (ঢাকা জেলা→`dhaka`, বাকি→`nationwide`)
- পুরোনো ক্যাশ-করা চেকআউট (`district_id` নেই) → `shipping_area=dhaka` হলে ঢাকা জেলা, নইলে গ্লোবাল; `district_id` পাঠিয়ে অবৈধ হলে অর্ডার বাতিল
- চেকআউটের জেলা বাছাই **খোঁজা-যোগ্য combobox** (`#cDistrictBtn` + প্যানেল): বাংলা/English/পুরোনো বানান (`dl_aliases()`: chittagong, comilla, barisal, jessore, bogra…); ডেস্কটপে ফিল্ডের নিচে ড্রপডাউন, ≤৬৪০px-এ ফুল-স্ক্রিন শিট (সার্চ বক্স ওপরে, ফন্ট ১৬px)। আসল `<select name=district_id>` লুকানো থাকে (ফর্মে জমা এটাই দেয়); সার্ভারের HTML-এ `required` থাকে, JS চললে সরিয়ে নিজস্ব যাচাই (`districtField.invalid` + লাল হিন্ট)। JS না চললে সাধারণ select-ই কাজ করে। প্যানেল `<body>`-তে বসে (`position:fixed`), যাতে কার্ডের transform/overflow-এ না কাটে
- জেলার টেবিল খালি/অনুপস্থিত হলে চেকআউটে জেলা-ফিল্ড বাদ, অর্ডার গ্লোবাল চার্জে চলে (আটকায় না)
- মাইগ্রেশনে আগের আচরণ হুবহু: ঢাকা জেলা = পুরোনো `delivery_dhaka`, গ্লোবাল = পুরোনো `delivery_nationwide`; পুরোনো দুই settings কী ডাটাবেসে পড়ে আছে, আর ব্যবহৃত নয়
- উপজেলা পরে যোগ হবে: `delivery_districts.id`-কে ভিত্তি করে আলাদা `delivery_upazilas` টেবিল

### প্রিন্ট (ইনভয়েস) নিয়ম
- `admin/order_print.php`-এ কার্ড দুটি `col-12 col-sm-6` (আগে `col-lg-6`: প্রিন্টের পাতা ≈৭৯৪px < ৯৯২px, তাই প্রিন্টে নিচে নিচে চলে যেত); প্রিন্টে `@media print`-এ সবসময় পাশাপাশি (`calc(50% - 6px)`, gutter ০ — নইলে Bootstrap `row`-এর ঋণাত্মক margin কনটেন্টের বাইরে ঠেলে)। `@page{size:A4;margin:12mm}`
- প্রিন্ট/ইনভয়েসে **bootstrap `lg`/`xl` breakpoint ভরসা করবেন না** — প্রিন্টের পাতার চওড়া ছোট ধরা পড়ে; `sm` বা সরাসরি `@media print` ব্যবহার করুন

### অর্ডার ডিসকাউন্ট নিয়ম
- মোট = সাবটোটাল − ডিসকাউন্ট + ডেলিভারি; `orders.total` আগের মতোই **নিট** সংখ্যা, তাই ড্যাশবোর্ড/রিপোর্টের আয় নিজে ঠিক থাকে
- অ্যাডমিন অর্ডার এডিটে ৳ (টাকা) বা % (শতাংশ) দেয়; **শতাংশ দিলে টাকার অঙ্কই জমা হয়** (শতাংশ নয়); পরে পণ্য/পরিমাণ বদলালে টাকা একই থাকে, সাবটোটালের বেশি হলে সাবটোটালে ছাঁটা হয় (বার্তা সহ)
- সার্ভারে আবার হিসাব (`od_resolve()`); JS-এর মান বিশ্বাস করা হয় না; ঋণাত্মক/অক্ষর/অ্যারে/১০০%-এর বেশি/১ কোটির বেশি বাতিল
- `discount_by` = যে অ্যাডমিন টাকার অঙ্ক বদলিয়েছে (নতুন বা বদলালে); `discount_note` সর্বোচ্চ ১৬০ অক্ষর; ডিসকাউন্ট ০ হলে দুটোই খালি (NULL)
- ক্রেতার পেজে (`single_order_details.php`) শুধু টাকার অঙ্ক ("ডিসকাউন্ট −৳100"); **কারণ ও দাতার নাম দেখায় না** — অ্যাডমিন order_view/print-এ দেখায়
- "Update Price" এখন শিপিং ফিও সংরক্ষণ করে (আগে শুধু সাবটোটাল/মোট লিখত, ফি লিখত না)
- কার্ট-ব্যাজ (`#cartCount`, `#bnCartCount`) = **আলাদা পণ্যের সংখ্যা**, পরিমাণের যোগফল নয় (`partials_header/footer`, `cart_count.php`, `cart_add.php`, `cart_remove.php`, `cart.php` JS একই নিয়মে)

### কার্ট ও চেকআউটের নিয়ম
- কার্টের `+`/`−`/ইনপুট বদলালে ৩৫০ms পর `cart_update.php`-এ নিজে সেভ (fetch, `redirect:'manual'`); "চেকআউট করুন" একটি **submit বাটন** (`name=next value=checkout.php`) — আগে সাধারণ লিংক ছিল, তাই "আপডেট" না চাপলে সার্ভারে পুরোনো পরিমাণ (১) থেকে যেত
- **ফ্রন্ট-সাইটের সব সংখ্যা ইংরেজি অঙ্কে** (টাকা, পরিমাণ, মোবাইল, তারিখ — `dateBD()` = `09 Nov 2026, 4:26 PM`)। সাইটের ফন্টে বাংলা অঙ্ক ছোট আকারে গুলিয়ে যায় (`১`/`৬`, `৪`/`৮`) আর পাশাপাশি দুই রকম অঙ্ক অমিল দেখায়। এমোজি (📦) লিখবেন না — আলাদা ফলব্যাক ফন্ট (Segoe UI Emoji) আসে; Font Awesome আইকন ব্যবহার করুন
- কার্টের সারিতে **ইউনিট-দাম** (`.cr-price` + "প্রতিটি") ও আলাদা **লাইন-মোট** (`.cr-line`, ডানে); `+`/`−`-এ লাইন-মোট, সাবটোটাল ও হেডার/bottom-nav ব্যাজ (`#cartCount`, `#bnCartCount`) রিলোড ছাড়াই বদলায়; সেভের পর `refreshCartCount()` সার্ভার থেকে সত্য মান আনে। ব্যাজ এখন **মোট অংশ** (পরিমাণের যোগফল), `cart_count.php`/`partials_header.php` একই নিয়মে
- চেকআউটের সংখ্যা **ইংরেজি অঙ্কে** (`bn_money()`/JS `moneyBD()` = `number_format`/`en-US`), কার্ট ও পণ্য পেজের মতো। বাংলা অঙ্ক (`১২৩`) ও আরবি বিভাজক `٬` ব্যবহার করবেন না — সাইটের ফন্টে (Poppins/Hind Siliguri) `٬` নেই, ব্রাউজার Times New Roman/Segoe UI থেকে আলাদা গ্লিফ এনে লেখা ভাঙা দেখায়
- টেস্ট স্ক্রিপ্ট ডাটাবেসের মান বদলালে **শুরুর মান snapshot করে শেষে ফেরাতে হয়**; "আগে এটা ছিল" ভেবে হাতে ডিফল্ট বসাবেন না (ব্যবহারকারী নিজেও লোকালে সেটিংস বদলান)

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
- লোকাল ডাম্প: `database/database_estore_26-11-05.sql` (v7 টেবিলসহ, ২২ টেবিল; পুরোনো); আগেরটি `database_estore_26-11-02.sql`
- নতুন ডাম্প: `mysqldump -uroot --default-character-set=utf8mb4 --single-transaction --add-drop-table estore > database/database_estore_YY-MM-DD.sql`
- লাইভের ব্যাকআপ আলাদা — phpMyAdmin → Export (আপলোডের আগে নিন)
- `database/` লাইভে আপলোড করবেন না
- **GitHub (Public):** https://github.com/ronedata/estore — `live.config.php`, `database/*.sql`, `logs/` গিটে যায় না (`.gitignore`)
- গিটে একমাত্র ডাম্প: `database/estore_schema_demo.sql` — সব টেবিলের কাঠামো + ডেমো ডেটা; গ্রাহক/অর্ডার/মেসেজ/রিভিউ/অ্যাডমিন/`store_phone`/`store_email` **নেই**। ফাইল বদলালে ব্যক্তিগত তথ্য ঢুকল কি না যাচাই করুন; ইমপোর্ট টেস্ট: অস্থায়ী DB-তে ইমপোর্ট করে টেবিল ২৪টি দেখুন
- **ডেমো ডাম্প নতুন করে বানানোর নিয়ম** (নতুন মাইগ্রেশন/টেবিল যোগ হলে): আসল লোকাল DB-তে হাত না দিয়ে (১) অস্থায়ী DB `estore_demo_build`-এ `mysqldump estore | mysql` দিয়ে কপি; (২) সেখানে `TRUNCATE` — customers, orders, order_items, contact_messages, product_reviews, page_views, subscribers, wishlists, admin_users, announcements; `settings`-এ `store_phone`/`store_email` মুছুন; ডেলিভারি ডেমো মান (গ্লোবাল ১২০, ঢাকা ৬০, বাকি NULL); (৩) `mysqldump --skip-comments --skip-add-locks --skip-disable-keys --add-drop-table` + আগের হেডার-কমেন্ট; (৪) খালি অস্থায়ী DB-তে ইমপোর্ট, টেবিল সংখ্যা/পারমিশন/মাইগ্রেশন-চিহ্ন যাচাই, `ensure_estore_schema_vN($pdo, true)` আবার চালিয়ে ডেটা অপরিবর্তিত কি দেখুন; (৫) ফাইলে ফোন (`01[3-9]\d{8}`), ইমেইল, লাইভ ডোমেইন, `$2y$` হ্যাশ, আইপি স্ক্যান; (৬) অস্থায়ী DB মুছুন। বর্তমান ডাম্প: ২৪ টেবিল, ১৪টির ডেটা (পণ্য ২৪, জেলা ৬৪ ইত্যাদি)
- গিটে পুশের আগে `git diff --cached --name-only | grep -E "live.config|database/.*\.sql|logs/"` চালান (শুধু `estore_schema_demo.sql` থাকা ঠিক)

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
| ডেলিভারি চার্জ (জেলা-ভিত্তিক) | `admin/delivery.php`, helper `includes/delivery.php` (`dl_quote()` — একমাত্র হিসাব), `checkout.php` (জেলা ড্রপডাউন), `place_order.php` (আসল হিসাব), অর্ডার ভিউ: `dl_order_area_label()` (পারমিশন `manage_delivery`) |
| অর্ডার ডিসকাউন্ট (অ্যাডমিন) | `admin/order_edit.php` (ডিসকাউন্ট ঘর), helper `includes/order_discount.php` (`od_resolve()`), ভিউ: `order_view.php`/`order_print.php`/`single_order_details.php` (পারমিশন: বিদ্যমান `manage_orders`) |
| জেলা বাছাই-বক্স (খোঁজা-যোগ্য) | শেয়ার করা `assets/js/district-picker.js` (`DistrictPicker.enhance()`) — চেকআউট ও `admin/order_edit.php` দুই জায়গায়; CSS স্ক্রিপ্টই নিজে ইনজেক্ট করে |
| COD নোট (`settings.cod_note`) | `admin/settings.php`-এ সম্পাদনা, `checkout.php`-এ পেমেন্টের নিচে দেখায়। `home_heading`/`home_notice` সরানো হয়েছে (ঘোষণা মেনু ব্যবহার করুন) |
| ঘোষণা (বার + পপআপ) | `admin/announcements.php`, `admin/announcement_form.php`, helper `includes/announcements.php`, ফ্রন্ট `partials_header.php` (বার, সব পেজ) / `partials_footer.php` (পপআপ, শুধু `index.php`), `assets/js/announcements.js`, CSS `.ann-*` (`components.css` শেষে) (পারমিশন `manage_announcements`) |
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
| `node tools/verify_permissions_ui.js <url>` | পারমিশন↔মেনু নাম মিল ১৪টি চেক, পারমিশন সংখ্যা ১৭ ধরে (`ADMIN_COOKIE` লাগে) |
| `node tools/verify_order_print.js <url>` | admin/order_print: স্ক্রিন (১৩২৬/৮০০/৩৯০px) ও প্রিন্ট-মিডিয়া (A4 ৭৯৪/৭০০/৬০০px)-এ কাস্টমার+অর্ডার কার্ড পাশাপাশি, ইংরেজি অঙ্কের তারিখ, আসল PDF ১ পাতা ২৯টি চেক (অস্থায়ী অ্যাডমিন সেশন; ডাটাবেস বদলায় না) |
| `php tools/test_order_discount.php` | ডিসকাউন্ট লজিক ৪৬টি চেক (সংখ্যা যাচাই, টাকা/শতাংশ, সাবটোটালে ছাঁটা, মোট, কারণ-লেখা, মাইগ্রেশন; ডাটাবেস বদলায় না) |
| `node tools/verify_discount.js <url>` | অ্যাডমিন ডিসকাউন্ট end-to-end ৪৩টি চেক (টেস্ট অর্ডার বানায়: টাকা/শতাংশ, Update/Save, ভুল-জাল মান, CSRF, ক্রেতা/অ্যাডমিন/প্রিন্ট পেজ, তুলে নেওয়া; শেষে মুছে স্টক ফেরায়) |
| `node tools/verify_order_edit_ui.js <url>` | admin/order_edit-এর পরিমাণ-বক্স, সংখ্যা ইংরেজি অঙ্কে, জেলা খোঁজা-বাছাই (খোঁজা, ক্লিকে ফি বসা, জমায় district_id) ৪২টি চেক (অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; Save চাপে না) |
| `node tools/verify_order_font.js <url>` | অর্ডার ডিটেইলস ও ট্র্যাকিং পেজে বাংলা অঙ্ক/এমোজি/ফলব্যাক ফন্ট নেই, তারিখ-পরিমাণ ইংরেজি অঙ্কে, overflow নেই ২০টি চেক (ডাটাবেস বদলায় না) |
| `node tools/verify_cart_qty.js <url>` | কার্ট পরিমাণ স্বয়ংক্রিয় সেভ, ব্যাজ = আলাদা পণ্যের সংখ্যা, "চেকআউট করুন" বাটনে নতুন পরিমাণ, স্টক সীমা, CSRF, চেকআউট সামারির অঙ্ক/ফন্ট (Times/Segoe ফলব্যাক নেই), কার্ট সারির ইউনিট-দাম/লাইভ লাইন-মোট হেডার ব্যাজ ও "সরান" বাটন ৫৩টি চেক (ডাটাবেস বদলায় না) |
| `php tools/test_delivery.php` | ডেলিভারি লজিক ৬৫টি চেক (৬৪ জেলা, ফাঁকা=গ্লোবাল/0=ফ্রি, যাচাই, সব-অথবা-কিছুই-না সেভ; শেষে চার্জ আগের অবস্থায় ফেরায়) |
| `node tools/verify_delivery.js <url>` | চেকআউট জেলা→চার্জ, সত্যিকারের অর্ডার, জাল ফিল্ড/অবৈধ জেলা, পুরোনো ক্যাশ-ফর্ম, অ্যাডমিন ফিল্টার/বাল্ক/সেভ, অর্ডার এডিট, আলাদা গ্লোবাল সেভ, জেলা খোঁজা (combobox) ১০৫টি চেক (mysql CLI+php লাগে; টেস্ট অর্ডার মুছে স্টক ও চার্জ ফেরায়) |
| `php tools/test_announcements.php` | ঘোষণা লজিক ৫৩টি চেক (যাচাই, লিংক-গার্ড, সময়সীমা, একটিই দেখানো, XSS escape; নিজে পরিষ্কার করে) |
| `node tools/verify_announcements.js <url>` | বার/পপআপ ব্রাউজার টেস্ট ৫৬টি চেক (২ প্রস্থ: delay, ✕/Esc/ব্যাকড্রপ, reshow never/session/days/always, শুধু হোমে পপআপ; mysql CLI লাগে, নিজে পরিষ্কার করে) |
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
- নতুন পারমিশন যোগ হলে `tools/verify_permissions_ui.js`-এর সংখ্যা (এখন ১৭) বদলাতে হবে
- বড় পরিবর্তনের আগে ফাইলের ব্যাকআপ কপি রাখুন (প্রজেক্ট git-এ নেই); এবারেরটি `C:\laragon\wwwstore_backup_pre_homesections\`
