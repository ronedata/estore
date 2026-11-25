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
| `schema_v11.php` | `login_attempts` (চেষ্টার লগ), `login_locks` (লক) — অ্যাডমিন লগইন সুরক্ষা |
| `schema_v16.php` | `orders.courier_consignment_id/tracking_code/status/sent_at/synced_at`, `courier_logs`, `manage_courier` পারমিশন, `settings.steadfast_enabled=0`/`steadfast_auto_status=0` (INSERT IGNORE — **বন্ধ অবস্থায় আসে**) |
| `schema_v15.php` | `suppliers`, `purchases` (বাতিল = `status='cancelled'`), `purchase_items` (পণ্যের নাম snapshot), `purchase_payments`, `manage_purchases` পারমিশন |
| `schema_v14.php` | `products.cost_price` (গড় ক্রয়মূল্য, **NULL = অজানা, ০ নয়**), `order_items.cost` (অর্ডারের সময়ের ক্রয়মূল্য snapshot), `expenses` টেবিল, `manage_expenses` পারমিশন |
| `schema_v13.php` | শুধু `view_reports` পারমিশন (+ Super Admin রোলে যুক্ত); নতুন টেবিল/কলাম নেই |
| `schema_v12.php` | `orders.public_token` (গোপন লিংক-কোড, পুরোনো অর্ডারেও backfill), `tracking_attempts` (ট্র্যাকিংয়ে ভুল চেষ্টার লগ) |
| `schema_v9.php` | `delivery_districts` (৬৪ জেলা সিড, `fee` NULL-able), `orders.shipping_district`, `settings.delivery_global`, `manage_delivery` পারমিশন |

চিহ্ন: `settings.schema_v2_at` … `schema_v7_at` (একবার চলে, দ্বিতীয়বার কিছু করে না)।

### অ্যাডমিন লগইন সুরক্ষা নিয়ম
- মেনু **"লগইন সুরক্ষা"** (`admin/login_security.php`, **শুধু Super Admin**, আলাদা পারমিশন নেই); helper `includes/login_security.php` (`ls_*`); লগইন ফর্ম `admin/login.php`-এ যুক্ত। সেটিংস `settings.ls_*` কী-তে
- নিয়ন্ত্রণ: মাস্টার সুইচ; **আইপি-লক** (বার/মিনিট); **ইউজারনেম-লক** (বার/মিনিট, ডিফল্ট বন্ধ — ঝুঁকি: কেউ নাম জানলে আসল অ্যাডমিনকে বাইরে রাখতে পারে); **ধীর করা** (ইউজারনেমে N ভুলের পর প্রতি চেষ্টায় S সেকেন্ড অপেক্ষা, কাউকে আটকায় না); গণনার সময়সীমা; সাদা তালিকা (আইপি); "আর কতবার" বার্তা টগল; লগ রাখার দিন; **আইপি শনাক্তকরণের উৎস** (`auto` ডিফল্ট / `remote_addr` / `xff_last` / `x_real_ip` / `cf_connecting_ip` / `xff_first`)। পেজে "এই মুহূর্তে সার্ভার কী দেখছে" টেবিল ও লক-তালিকা + "খুলে দিন" + সাম্প্রতিক চেষ্টার লগ
- ডিফল্ট: চালু, আইপি-লক ৫ বার/১৫ মিনিট, ইউজারনেম-লক বন্ধ, ধীর করা ২০ বারের পর ৩ সেকেন্ড, গণনা ১৫ মিনিট, উৎস `auto`
- **`auto`**: সরাসরি আইপি ব্যক্তিগত/অভ্যন্তরীণ (প্রক্সি) হলে X-Forwarded-For-এর **শেষ** আইপি, নইলে সরাসরি আইপি। কারণ: লাইভ (InfinityFree) প্রক্সির পেছনে (`Server: openresty`) — সরাসরি আইপি নিলে সবার আইপি এক ধরা পড়ে, একজনের ভুলে সব অ্যাডমিন (Super Admin সহ) আটকাতে পারে; আবার `xff_first` নিলে আক্রমণকারী হেডার জাল করে লক এড়ায়
- **সফল লগইনে শুধু নিজের আইপির ভুল রিসেট** (`result='failx'`, মোছে না); ইউজারনেম ধরে মুছলে আসল অ্যাডমিনের লগইনে আক্রমণকারীর ভুলও মুছে যেত (টেস্টে ধরা পড়া দুর্বলতা)। হাতে লক খোলাও `failx` করে, রেকর্ড মোছে না
- লক চালু থাকলে **সঠিক পাসওয়ার্ডও গ্রহণ হয় না**; আটকানো চেষ্টা `blocked` হিসেবে লগে; সাদা তালিকার আইপি নথিভুক্তও হয় না
- **fail-open**: টেবিল/ডাটাবেস সমস্যায় লগইন আটকায় না (সুরক্ষা সবাইকে বাইরে রাখবে না)
- জরুরি খোলা: অন্য আইপি থেকে ঢুকে পেজে "খুলে দিন"; নইলে phpMyAdmin-এ `DELETE FROM login_locks;`; লোকালে `php tools/unlock_logins.php --list|--all|--ip X|--user NAME`
- **লোকালে** (`127.0.0.1`): ৫ বার ভুল পাসওয়ার্ড দিলে নিজের লোকাল আইপিই ১৫ মিনিট আটকাবে — সাদা তালিকায় `127.0.0.1` ও `::1` দিন বা সুরক্ষা বন্ধ রাখুন
- **লাইভে আপলোডের পর প্রথম কাজ:** পেজ খুলে "আইপি শনাক্তকরণ" টেবিলে নিজের আসল আইপি মেলে কি দেখুন; না মিললে সঠিক উৎস বেছে নিন; নিজের আইপি সাদা তালিকায় দিন

### অর্ডারের তথ্য সুরক্ষা ও ট্র্যাকিং নিয়ম
- অর্ডার আইডি (`?id=`) দিয়ে ক্রেতার নাম/মোবাইল/ঠিকানা **কখনো** খোলে না (আইডি আন্দাজ করা সহজ) — শুধু লগইন করা মালিক (`orders.customer_id` মিললে); গেস্টের জন্য গোপন কোড `?t=<32 hex>` (`orders.public_token`, `oa_*` helper `includes/order_access.php`)। `place_order.php` → `success.php?t=…`; অ্যাডমিন পেজ আইডিই ব্যবহার করে (অ্যাডমিন লগইনের পেছনে)
- `track_orders.php` **সবার জন্য খোলা** (গেস্টও): মোবাইল নম্বর দিলে গত ৭ দিনের অর্ডার, তারপর "আরও দেখুন" বাটনে ১০টি করে (AJAX `?more=1&before=ID`); তালিকায় ক্রেতার নাম নেই (কোড, তারিখ, স্ট্যাটাস, মোট)। অর্ডার কোডও দিলে শুধু ওই অর্ডার; কোড দিয়ে ভুল চেষ্টায় আইপি-ভিত্তিক সীমা (১০ ভুল/১০ মিনিট, `tracking_attempts`)। **"বিস্তারিত দেখুন" বাটনে লগইন লাগে** — `single_order_details.php` `require_customer()` + `oa_load_for_customer()`: শুধু নিজের অর্ডার (`customer_id` মেলে অথবা একই মোবাইল); গোপন কোড (`?t=`) দিয়েও গেস্ট খুলতে পারে না। গোপন কোড এখন শুধু `success.php` (অর্ডারের পরের পেজ)-এর জন্য
- মোবাইল ফরম্যাট `01…`/`+8801…`/`8801…` সব মেলে; কোড `ORD0000034`/`ord34`/`#34` মেলে
- পুরোনো `?id=` বুকমার্ক গেস্টের জন্য আর কাজ করে না (ইচ্ছাকৃত)

### ডেলিভারি চার্জ নিয়ম
- `delivery_districts.fee`: **NULL (ফাঁকা) = গ্লোবাল** (`settings.delivery_global`), **0 = ওই জেলায় ফ্রি**; দুটি আলাদা — `''` কে 0 বানাবেন না
- সব পণ্যে `free` ট্যাগ থাকলে জেলা যাই হোক ০ (সার্ভারেই হিসাব, ক্লায়েন্টের ফ্ল্যাগ/ফি বিশ্বাস করা হয় না)
- `orders.shipping_area` ENUM **অক্ষত**; `shipping_district` (বাংলা নাম) আলাদা কলাম; `shipping_area` নিজে ভরে (ঢাকা জেলা→`dhaka`, বাকি→`nationwide`)
- পুরোনো ক্যাশ-করা চেকআউট (`district_id` নেই) → `shipping_area=dhaka` হলে ঢাকা জেলা, নইলে গ্লোবাল; `district_id` পাঠিয়ে অবৈধ হলে অর্ডার বাতিল
- চেকআউটের জেলা বাছাই **খোঁজা-যোগ্য combobox** (`#cDistrictBtn` + প্যানেল): বাংলা/English/পুরোনো বানান (`dl_aliases()`: chittagong, comilla, barisal, jessore, bogra…); ডেস্কটপে ফিল্ডের নিচে ড্রপডাউন, ≤৬৪০px-এ ফুল-স্ক্রিন শিট (সার্চ বক্স ওপরে, ফন্ট ১৬px)। আসল `<select name=district_id>` লুকানো থাকে (ফর্মে জমা এটাই দেয়); সার্ভারের HTML-এ `required` থাকে, JS চললে সরিয়ে নিজস্ব যাচাই (`districtField.invalid` + লাল হিন্ট)। JS না চললে সাধারণ select-ই কাজ করে। প্যানেল `<body>`-তে বসে (`position:fixed`), যাতে কার্ডের transform/overflow-এ না কাটে
- জেলার টেবিল খালি/অনুপস্থিত হলে চেকআউটে জেলা-ফিল্ড বাদ, অর্ডার গ্লোবাল চার্জে চলে (আটকায় না)
- মাইগ্রেশনে আগের আচরণ হুবহু: ঢাকা জেলা = পুরোনো `delivery_dhaka`, গ্লোবাল = পুরোনো `delivery_nationwide`; পুরোনো দুই settings কী ডাটাবেসে পড়ে আছে, আর ব্যবহৃত নয়
- উপজেলা পরে যোগ হবে: `delivery_districts.id`-কে ভিত্তি করে আলাদা `delivery_upazilas` টেবিল

### বাংলা অঙ্ক ও ঘোষণার নিয়ম
- **অ্যাডমিন যে লেখা দেয় (ঘোষণা ইত্যাদি) তা যেমন দেওয়া হবে তেমনই দেখায়** (বাংলা অঙ্কও); অঙ্ক বদলানো হয় না। পরিষ্কার দেখানোর কাজ ফন্টের
- Hind Siliguri-তে বাংলা অঙ্ক ছোট আকারে গুলিয়ে যায় (`১` একটি ছোট বাঁকা চিহ্ন, `১`/`৬`, `৪`/`৮`)। তাই `partials_header.php`-এ **Noto Sans Bengali-র শুধু অঙ্কের সাবসেট** (`&text=০১২৩৪৫৬৭৮৯`, ~৭KB) লোড হয় এবং `body{font-family:'Poppins','Noto Sans Bengali','Hind Siliguri',…}` — শুধু বাংলা অঙ্কে নতুন ফন্ট, বাকি অক্ষর আগের মতো Hind Siliguri; সাইটজুড়ে (ঘোষণা, ফুটার, তথ্য-বার…)
- সিস্টেম নিজে যে সংখ্যা বানায় (টাকা, পরিমাণ, তারিখ) তা ইংরেজি অঙ্কে (নিচের নিয়ম); এমোজি-ফলব্যাক এড়াতে Font Awesome ব্যবহার করুন
- বারের ফন্ট ১৪.৫px, পপআপের লেখা ১৫.৫px/৫০০
- **টেস্ট কখনো আসল (`[T]`-নয় এমন) ঘোষণা/ডেটার চালু-বন্ধ বা মান বদলাবে না**; দরকারে শুরুতে snapshot, শেষে ফেরানো। (পুরোনো `test_announcements.php` অন্য ঘোষণা নিষ্ক্রিয় করে ফেরাত না — ঠিক করা হয়েছে)

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
- **ডেমো ডাম্প নতুন করে বানানোর নিয়ম** (নতুন মাইগ্রেশন/টেবিল যোগ হলে): আসল লোকাল DB-তে হাত না দিয়ে (১) অস্থায়ী DB `estore_demo_build`-এ `mysqldump estore | mysql` দিয়ে কপি; (২) সেখানে `TRUNCATE` — customers, orders, order_items, contact_messages, product_reviews, page_views, subscribers, wishlists, admin_users, announcements; `settings`-এ `store_phone`/`store_email` মুছুন; ডেলিভারি ডেমো মান (গ্লোবাল ১২০, ঢাকা ৬০, বাকি NULL); (৩) `mysqldump --skip-comments --skip-add-locks --skip-disable-keys --add-drop-table` + আগের হেডার-কমেন্ট; (৪) খালি অস্থায়ী DB-তে ইমপোর্ট, টেবিল সংখ্যা/পারমিশন/মাইগ্রেশন-চিহ্ন যাচাই, `ensure_estore_schema_vN($pdo, true)` আবার চালিয়ে ডেটা অপরিবর্তিত কি দেখুন; (৫) ফাইলে ফোন (`01[3-9]\d{8}`), ইমেইল, লাইভ ডোমেইন, `$2y$` হ্যাশ, আইপি স্ক্যান; (৬) অস্থায়ী DB মুছুন। বর্তমান ডাম্প: ২৭ টেবিল, ১৪টির ডেটা (পণ্য ২৪, জেলা ৬৪ ইত্যাদি)
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
| ব্যাকআপ (ডাটাবেস .sql + কোড/ছবি .zip) | `admin/backup.php`, helper `includes/backup.php` (`bk_*`) — শুধু Super Admin (আলাদা পারমিশন নেই, `gate('settings')`)। PHP দিয়ে স্ট্রিম ডাউনলোড (exec/mysqldump লাগে না), সার্ভারে ফাইল জমে না; "ডেমো" ডাম্পে ১৩টি ব্যক্তিগত টেবিলের শুধু কাঠামো; কোড-জিপে `uploads/logs/database/tools/docs/.git` ও **config ফাইল বাদ**; শেষ ব্যাকআপ `settings.backup_last_db/code/uploads`, ডাটাবেসের ৭ দিনের বেশি পুরোনো হলে সতর্কতা। জিপের জন্য `ZipArchive` লাগে (লোকাল Laragon Apache PHP 8.3-এ `extension=zip` কমেন্ট করা — পেজে বার্তা দেখায়; টেস্ট `php -S` দিয়ে) |
| পণ্য তালিকা (পেজিনেশন/CSV/কপি/প্রিভিউ) | `admin/products.php`: প্রতি পৃষ্ঠায় 10/20/50/100 (`per`, ডিফল্ট ২০, whitelist), পৃষ্ঠা-সীমা শেষ পৃষ্ঠায় ছাঁটা; `?export=csv` বর্তমান ফিল্টারের সব পণ্য (UTF-8 BOM, `= + - @` দিয়ে শুরু ঘরে আগে `'`); নামের নিচে হোম-সেকশন ব্যাজ (সেকশনের ক্রম অনুযায়ী ৮টি নরম রঙ, প্রতিবার একই রঙ); ছবিতে ক্লিকে বড় প্রিভিউ; "কপি" বাটন = নতুন ট্যাবে `product_form.php?copy=ID` (লিংক, `target=_blank`): ফর্ম মূল পণ্যের মান দিয়ে ভরা (নামে " (কপি)", youtube লিংকসহ), **ডাটাবেসে কিছুই লেখা হয় না — Save চাপলে তবেই নতুন পণ্য**; `copy_from` + "ছবি কপি" চেকবক্স থাকলে সার্ভার ছবির **ফাইল আলাদা নামে (`_cpN`) কপি** করে (শেয়ার করলে একটি ডিলিটে অন্যটির ছবি যেত), পরে আপলোড করা ছবি এর পরে বসে; `copy_from` শুধু নতুন পণ্যে (`id=0`) কাজ করে। কোনো নিশ্চিতকরণ পপআপ নেই (ডেটা বদলায় না বলে)। আগের `product_duplicate.php` সরানো হয়েছে |
| বিক্রয় রিপোর্ট + ড্যাশবোর্ডের বিক্রয় অংশ | `admin/report_orders.php`, helper `includes/reports.php` (`rp_*`), পারমিশন `view_reports`। ফিল্টার: আজ/মাসিক/বার্ষিক/কাস্টম (`type,year,month,from,to`) + স্ট্যাটাস; KPI (অর্ডার, রাজস্ব, ডিসকাউন্ট, ডেলিভারি), স্ট্যাটাস-চিপ, ক্যাটেগরি-অনুযায়ী বিক্রি, ২৫/পৃষ্ঠা তালিকা, CSV (সব সারি, BOM, ফর্মুলা-ইনজেকশন গার্ড)। **নিয়ম: বাতিল (cancelled) অর্ডার রাজস্ব/ডিসকাউন্ট/ডেলিভারিতে ধরা হয় না, কিন্তু "মোট অর্ডার" গণনায় আছে**; তারিখ-সীমা `created_at >= from 00:00:00 AND < to+1দিন` (শেষ দিনের 23:59:59 ধরা পড়ে); ভুল তারিখ/অ্যারে ইনপুটে চালু মাসে ফিরে সতর্কতা। ড্যাশবোর্ডে (`view_reports` থাকলে): এই মাসের রাজস্ব/গড়, ৩ দিনের বেশি Pending, ৬ মাসের চার্ট, ক্যাটেগরি-বিক্রি; "আজকের রেভিনিউ" এখন বাতিল বাদে |
| ক্রয়মূল্য ও অর্ডারে snapshot | helper `includes/costs.php` (`cs_*`)। পণ্য ফর্ম ও দ্রুত-যোগ ফর্মে "ক্রয়মূল্য" (ঐচ্ছিক; ফাঁকা = NULL = অজানা; অবৈধ হলে কিছুই সেভ হয় না; পুরোনো ফর্মে ক্ষেত্র না থাকলে ক্রয়মূল্য মোছে না)। **অর্ডার তৈরির পর আলাদা ধাপে snapshot:** `place_order.php` → `cs_snapshot_order()`, `admin/order_edit.php`-এ নতুন আইটেম → `cs_snapshot_items([id])`; বিদ্যমান আইটেম/পরিমাণ বদলালে cost বদলায় না। **মূল INSERT বদলানো হয়নি** — কলাম না থাকলে (config আপলোড বাকি) snapshot চুপচাপ বাদ যায়, অর্ডার আটকায় না। গড় সূত্র `cs_weighted_avg()` (ধাপ ৩-এ ব্যবহার হবে) |
| রিপোর্ট ট্যাব: লাভ-ক্ষতি / ক্রয় / খরচ | helper `includes/finance.php` (`fi_*`), পারমিশন `view_reports` (সব রিপোর্টের একটাই); ট্যাব `fi_tabs()` (অর্ডার · লাভ-ক্ষতি · ক্রয় · খরচ · স্টক — স্টক শুধু `view_stock_report` থাকলে)। **`admin/report_profit.php`:** রাজস্ব = Σ অর্ডারের মোট (ডেলিভারিসহ, ডিসকাউন্টের পর); COGS = Σ(`order_items.cost`×পরিমাণ) **শুধু জানা-ক্রয়মূল্যের আইটেমে** (অজানা NULL আইটেম ০ ধরা হয় না — আলাদা সংখ্যা, বিক্রি ও "লাভ বেশি দেখাচ্ছে" সতর্কতা + জানা-আইটেমে মার্জিন); মোট লাভ = রাজস্ব − COGS; নিট = মোট লাভ − খরচ। বেসিস `basis=all` (বাতিল বাদে সব, ডিফল্ট) / `delivered`; খরচ বেসিসে বদলায় না। মাসভিত্তিক টেবিল (>১ মাস, সর্বোচ্চ ৩৬), ক্যাটেগরি/শীর্ষ-১০ পণ্যের মার্জিন, CSV। ক্রয় (ইনভেন্টরি কেনা) সরাসরি খরচ নয়। **`admin/report_purchases.php`:** ক্রয়ের তারিখে মোট/পরিশোধ/বাকি + "এই সময়ে দেওয়া টাকা" (পরিশোধের তারিখে) + সরবরাহকারী ও পণ্য অনুযায়ী + তালিকা/CSV; বাতিল ক্রয় ও তার পরিশোধ বাদ। **`admin/report_expenses.php`:** মোট, এন্ট্রি, দৈনিক গড়, ক্যাটেগরি অনুযায়ী শতাংশসহ, মাসভিত্তিক, শীর্ষ ৫ খরচ, তালিকা/CSV। **প্রতি অর্ডারের লাভ** = মোট − Σ cost×qty, কোনো আইটেম অজানা হলে `null` ("—"/"অজানা", ০ নয়): অর্ডার-রিপোর্টের "লাভ" কলাম ও CSV, `admin/order_view.php` (শুধু `view_reports` থাকলে)। ড্যাশবোর্ডে "এই মাসের নিট লাভ" কার্ড (অজানা আইটেম থাকলে `*`) |
| সরবরাহকারী ও ক্রয় | `admin/suppliers.php` (যোগ/সম্পাদনা/সক্রিয়-নিষ্ক্রিয়/মোছা শুধু ক্রয় না থাকলে), `admin/supplier_view.php` (ইতিহাস + **একসাথে পরিশোধ**), `admin/purchases.php` (তালিকা, ফিল্টার, KPI, মোট দায়), `admin/purchase_form.php` (নতুন ক্রয়, JS-এ লাইন/মোট/বাকি রিয়েল-টাইম), `admin/purchase_view.php` (আইটেম, কিস্তি, পরিশোধ মোছা, বাতিল); helper `includes/purchases.php` (`pu_*`); পারমিশন `manage_purchases` (মেনু "ক্রয়" ও "সরবরাহকারী")। **নিয়ম:** ক্রয় সংরক্ষণে **একটি ট্রানজাকশনে** স্টক বাড়ে ও পণ্যের ক্রয়মূল্য **গড়ে** (`cs_weighted_avg`) হালনাগাদ হয় — একই পণ্য দুই লাইনে থাকলে ক্রমান্বয়ে; ব্যর্থ হলে কিছুই লেখা হয় না। **বাকি = মোট − পরিশোধ-সারির যোগফল** (আলাদা জমা-সংখ্যা নেই, ড্রিফট হয় না); পরিশোধ কিস্তিতে (বাকির বেশি নয়, ভবিষ্যৎ তারিখ নয়) বা সরবরাহকারীকে **একসাথে** (সবচেয়ে পুরোনো বাকি ক্রয় থেকে বণ্টন, `FOR UPDATE` লক); ভুল পরিশোধ-সারি মোছা যায়; **ক্রয় বাতিল শুধু পরিশোধ ০ থাকলে** — স্টক ফেরে (০-এর নিচে নয়), **গড় ক্রয়মূল্য ফেরানো হয় না** (হাতে ঠিক করতে হবে); বাতিল ক্রয় দায়/KPI-তে ধরা হয় না, ইনভয়েস নম্বর আবার ব্যবহার করা যায়; একই সরবরাহকারীর সক্রিয় ক্রয়ে ইনভয়েস নম্বর ডুপ্লিকেট নয়; পরিমাণ ১–১,০০,০০০ পূর্ণসংখ্যা, লাইন ≤১০০, অঙ্ক সেন্টে তুলনা |
| খরচ (expenses) | `admin/expenses.php`, helper `includes/expenses.php` (`ex_*`), পারমিশন `manage_expenses` (মেনু "খরচ"): যোগ/সম্পাদনা/মোছা (POST+CSRF, মোছায় মোডাল), আজ/মাস/বছর কার্ড, ফিল্টার (সময় + ক্যাটেগরি; `__none` = ক্যাটেগরিহীন), ক্যাটেগরি অনুযায়ী বার, ২৫/পৃষ্ঠা, CSV। নিয়ম: পরিমাণ >০ ও ≤১ কোটি, তারিখ ভবিষ্যতের নয়, শিরোনাম ≤১৬০, ক্যাটেগরি ≤৬০, নোট ≤২৫৫; আউটপুট escape |
| স্টক রিপোর্ট | `admin/stock_report.php`: ফিল্টার-কার্ড (আগের মতো) + **"সব পণ্যের ইনভেন্টরি" (ফিল্টার-নিরপেক্ষ):** ক্রয়মূল্যে স্টকের মূল্য, বিক্রয়মূল্যে মূল্য, সম্ভাব্য লাভ (শুধু ক্রয়মূল্য-জানা পণ্যের), ক্রয়মূল্য অজানা সংখ্যা; তালিকা ও CSV-তে ক্রয়মূল্য/ক্রয়-ভ্যালু |
| অ্যাডমিন প্যানেলের নাম | `admin_store_name()` (`admin/auth_functions.php`): `settings.store_name` → ফাঁকা/অনুপস্থিত হলে `APP_NAME` (`eStore`, config-এ স্থির) → সর্বোচ্চ ৬০ অক্ষর; ব্যবহার: `admin/header.php` (ট্যাব-শিরোনাম + সাইডবার `#adminBrandName`), `admin/footer.php`, `admin/login.php` (শিরোনাম)। আউটপুটে escape। গ্রাহক-সাইটের নামও একই `store_name` — সেটিংসে একবার বদলালে দুই জায়গায় বদলায়; `config.php` ছুঁতে হয় না |
| অ্যাডমিন ড্যাশবোর্ড (ডিজাইন) | `admin/index.php`: **হিরো** (সময় অনুযায়ী শুভেচ্ছা + ইউজারের নাম, আজকের তারিখ/অর্ডার/রেভিনিউ) → **আজকের ৪ কার্ড** (অর্ডার, রেভিনিউ — **গতকালের তুলনা ▲▼%**, পেন্ডিং, লো-স্টক + স্টক-আউট) → (`view_reports` থাকলে `#dashSales`) **এই মাসের ৪ কার্ড** (রাজস্ব ও অর্ডার — **গত মাসের তুলনা**, নিট লাভ, গড় অর্ডার), ৬ মাসের বার-চার্ট (চলতি মাস সবুজ), ক্যাটেগরি **ডোনাট** (`conic-gradient`, শীর্ষ ৫), অর্ডারের অবস্থা-বার → **"মনোযোগ দরকার"** (৩ দিনের বেশি Pending [`manage_orders` বা `view_reports`], স্টক-আউট ও কম-স্টক [`view_stock_report`/`manage_products`], সরবরাহকারীর মোট বাকি [`manage_purchases`]; সারি ধূসর হলে "ঠিক আছে") → **দ্রুত অ্যাকশন** (টাইল-গ্রিড, **প্রতিটি পারমিশন অনুযায়ী লুকানো/দেখানো; "সেটিংস" টাইল সরানো হয়েছে**) → **সাম্প্রতিক অর্ডার** (সর্বশেষ ৮টি, আজের হলে "আজ 4:26 PM"; আগে শুধু আজকেরগুলো ছিল)। তুলনার নিয়ম `$delta`: আগের ০ ও এখনের ০ → "—"; আগের ০, এখন >০ → "নতুন"; ±০.৫% এর ভেতর → "০%"। কোনো ছবি/চার্ট-লাইব্রেরি/CDN নেই — CSS ও inline style; এমোজি নয়, Font Awesome। স্থির id: `kOrdersToday kRevenueToday kPendingToday kLowStock dMonthRevenue dMonthNet dMonthOrders dMonthAvg dOldPending aOutStock aLowStock aSupplierDue dashChart dashCats dashStatus dashQuick dashOrders` (টেস্ট নির্ভর) |
| অ্যাডমিন সাইডবার (গ্রুপ) | `admin/header.php` `$NAV_GROUPS`: **মূল** (ড্যাশবোর্ড, গ্রুপহীন) → **বিক্রয়** (অর্ডার, পেমেন্ট, ডেলিভারি চার্জ, কাস্টমার, রিভিউ, মেসেজ) → **পণ্য ও ক্যাটালগ** → **ক্রয় ও হিসাব** (ক্রয়, সরবরাহকারী, খরচ, স্টক রিপোর্ট) → **রিপোর্ট** (বিক্রয় রিপোর্ট + সব রিপোর্ট-ট্যাবের পেজ, Page Views) → **সাইট সাজানো** (স্লাইডার, হোম সেকশন, ঘোষণা, পেজ) → **সিস্টেম** (সেটিংস, ইউজার ও রোল, লগইন সুরক্ষা, সিকিউরিটি, ব্যাকআপ)। ভাঁজ-করা: বর্তমান পেজের গ্রুপ সবসময় খোলা (`open has-active`, সার্ভারেই), বাকিগুলো বন্ধ; হাতে খোলা গ্রুপ `localStorage.adminNavOpen` (JSON-array, অবৈধ মান উপেক্ষা) এ মনে থাকে। **কোনো গ্রুপে অনুমোদিত মেনু না থাকলে গ্রুপ-হেডারই নেই**। বন্ধ-গ্রুপ লুকানোর CSS শুধু `html.js` থাকলে (`.js .nav-group:not(.open)`) — JS না চললে সব মেনু দেখা যায়। গ্রুপ-হেডার `<button>` (`<a>` নয়) — যাতে `verify_permissions_ui.js`-এর `.sidebar .navy a span` মেনু-নাম তালিকায় না ঢোকে। আগে `settings.php`-এ "সেটিংস" মেনু কখনো সক্রিয় দেখাত না (ফাইলের নাম `'settings'` ছিল) — ঠিক করা হয়েছে |
| কুরিয়ার (Steadfast) — **কাঠামো তৈরি, ডিফল্ট বন্ধ** | `includes/steadfast.php` (`sf_*`), `admin/courier.php` (সেটিংস, সংযোগ পরীক্ষা, সব সিঙ্ক, লগ), `admin/courier_action.php` (একটি অর্ডার পাঠানো/সিঙ্ক, POST+CSRF), `admin/order_view.php`-এর কুরিয়ার কার্ড (শুধু চালু থাকলে বা অর্ডার পাঠানো হয়ে থাকলে), `admin/order_edit.php`-এর তালা; পারমিশন `manage_courier` (মেনু "কুরিয়ার", বিক্রয় গ্রুপ)। **নিয়ম:** (১) বন্ধ/কী-ছাড়া/কলাম-ছাড়া অবস্থায় (`sf_ready()` false) কোড কখনো বাইরে কল করে না; (২) **API-র সব খুঁটিনাটি (base URL `https://portal.packzy.com/api/v1`, হেডার `Api-Key`/`Secret-Key`, `/create_order`, `/status_by_cid/{id}`, `/get_balance`, ফিল্ডের নাম, স্ট্যাটাসের নাম, ট্র্যাকিং URL) এক জায়গায় `sf_profile()` এবং অফিসিয়াল ডকুমেন্ট থেকে যাচাই করা নয়** (Scribd ডকুমেন্ট পড়া যায়নি) — চালু করার আগে "সংযোগ পরীক্ষা" + একটি ছোট আসল অর্ডার দিয়ে মেলান, অমিল হলে শুধু `sf_profile()` বদলান; (৩) Base URL শুধু https (http কেবল 127.0.0.1/localhost, নকল সার্ভারের জন্য); (৪) কী ডাটাবেসে settings-এ, পেজে মাস্ক (`sf_mask`), ফাঁকা রেখে সেভ = আগেরটা থাকে, লগ/বার্তা থেকে কী মোছা হয়; (৫) দুইবার পাঠানো আটকানো: পাঠানোর আগে `courier_status='sending'` "দখল" (৫ মিনিটের পুরোনো দখল ছাড়ানো যায়); (৬) COD অর্ডারে `cod_amount` = মোট, অনলাইন পেমেন্টে ০; (৭) স্ট্যাটাস মেলানো বাটনে (ওয়েবহুক নয়); `steadfast_auto_status=1` হলে শুধু সামনের দিকে (পাঠালে Shipped, delivered → Delivered, cancelled → Cancelled + স্টক ফেরে); (৮) পাঠানো অর্ডারে নাম/মোবাইল/ঠিকানা/ডেলিভারি চার্জ/ডিসকাউন্ট/পরিমাণ/পণ্য যোগ-বাদ বদলানো বন্ধ (`sf_locked_changes`), স্ট্যাটাস/নোট চলে। **এখনো করা হয়নি:** গ্রাহকের অর্ডার-পেজে ট্র্যাকিং কোড, orders তালিকায় কুরিয়ার কলাম |
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
| 8 | ~~অ্যাডমিন লগইনে rate limiting~~ — **সম্পন্ন** (২০২৬-১১-১০, মেনু "লগইন সুরক্ষা") | ✅ |
| — | অ্যাডমিন পাসওয়ার্ড শক্ত করা | **ব্যবহারকারী নিজে করবেন** |

### ব্যবসার হিসাব রোডম্যাপ (সিদ্ধান্ত: ২০২৬-১১-২৫)
- দোকান এখন **সব ধরনের পণ্য** বেচে (শুধু খেলনা নয়) এবং **পণ্য কিনে বিক্রি** করে; কুরিয়ার **Steadfast** (পরে করা হবে)। ভ্যারিয়েন্ট (রঙ/সাইজ) এখন নয় — প্রতি রঙ/সাইজ আলাদা পণ্য
- ধাপ ১ ✅ বিক্রয় রিপোর্ট + ড্যাশবোর্ড কার্ড। ধাপ ২ ✅ (২০২৬-১১-২৫) পণ্যে ক্রয়মূল্য + `order_items.cost` snapshot + খরচ মডিউল + স্টক রিপোর্টে ইনভেন্টরি-মূল্য (পুরোনো অর্ডারের cost NULL — অজানা; ধাপ ৪-এর P&L-এ "ক্রয়মূল্য-অজানা আইটেম" আলাদা দেখাতে হবে, ০ ধরা যাবে না)। ধাপ ৩ ✅ (২০২৬-১১-২৫) সরবরাহকারী + ক্রয় + কিস্তি/একসাথে পরিশোধ + গড় ক্রয়মূল্য। ধাপ ৪ ✅ (২০২৬-১১-২৫) লাভ-ক্ষতি, ক্রয় ও খরচ রিপোর্ট, অর্ডার-লাভ, ড্যাশবোর্ডে নিট লাভ। বাকি: ধাপ ৫ Steadfast। (আগের পরিকল্পনা:) ধাপ ৩: সরবরাহকারী + ক্রয় (ইনভয়েস, পরিশোধ/বাকি, ক্রয়ে স্টক নিজে বাড়া)। ধাপ ৪: Purchase/Expense Report ও Profit & Loss (বিক্রয় − COGS − খরচ)। ধাপ ৫: Steadfast
- **ক্রয়মূল্য = গড় (weighted average):** নতুন ক্রয়ে `নতুন_গড় = (আগের_স্টক×আগের_গড় + ক্রয়ের_পরিমাণ×ক্রয়_দাম) / (আগের_স্টক + ক্রয়ের_পরিমাণ)`
- Steadfast সতর্কতা: লাইভ (InfinityFree)-এ বাইরের বট-চ্যালেঞ্জে ওয়েবহুক আটকাতে পারে → স্ট্যাটাস সিঙ্ক "অ্যাডমিন বাটন" ধরনের; লাইভ থেকে বাইরে cURL চলে কি না আগে যাচাই; API কী/সিক্রেট অ্যাডমিন সেটিংসে (গিটে নয়); আসল API নিয়ম Steadfast-এর বর্তমান ডকুমেন্ট থেকে মেলাতে হবে

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
| `php tools/test_login_security.php` | লগইন সুরক্ষা লজিক ১১৫টি চেক (সেটিংস যাচাই, আইপি শনাক্তকরণ — জাল হেডার সহ, আইপি/ইউজারনেম-লক, সময়সীমা, সাদা তালিকা, ধীর করা, সফল লগইনের প্রভাব, লক খোলা, fail-open; শুধু টেস্ট-আইপি `203.0.113.x` ছোঁয়, ls_* সেটিংস ফেরায়) |
| `node tools/verify_backup.js <url>` | ব্যাকআপ end-to-end ৩৬টি চেক (অ্যাক্সেস/CSRF, আসল ডাউনলোড → অস্থায়ী DB `estore_bk_verify`-এ ইমপোর্ট, সারি সংখ্যা ও চেকসাম মিল, ডেমো ডাম্পে ব্যক্তিগত ডেটা নেই, জিপের বিষয়বস্তু ও config বাদ, সতর্কতা; জিপ-টেস্টে `php -S 127.0.0.1:8123` ও সেই URL দিন; শেষে সব ফেরায়) |
| `node tools/verify_backup_ui.js <url>` | ব্যাকআপ পেজ আসল ব্রাউজারে: ৪টি বাটন দৃশ্যমান (১৩২৬/৩৯০px), overflow নেই, ক্লিকে ৪টি ফাইল নামে, পেজে "শেষ ব্যাকআপ" তারিখ ১২টি চেক (জিপের জন্য `php -S 127.0.0.1:8123 -t .` ও সেই URL)। বড় ডেটা পরীক্ষা: ৬ লাখ সারি/৩২৬MB ডাম্প ২MB মেমোরিতে ৪.৬ সেকেন্ডে, ইমপোর্টে চেকসাম মিলেছে |
| `node tools/verify_products_page.js <url>` | admin/products.php ৫৮টি চেক (পেজিনেশন/অবৈধ per-page/অ্যারে ইনপুট, সেকশন ব্যাজ, CSV হেডার/BOM/ফিল্টার/ফর্মুলা-ইনজেকশন/পারমিশন, ডুপ্লিকেট: CSRF/GET/অবৈধ আইডি/ছবির ফাইল কপি/কপি মুছলে আসলের ছবি থাকে, ব্রাউজারে প্রিভিউ মোডাল ও কপি-ডায়ালগ ২ প্রস্থ; `[T]` পণ্য ও ছবি নিজে মোছে) |
| `node tools/verify_product_images.js <url>` | product_form ছবি আপলোড ১৬টি চেক: default.jpg প্লেসহোল্ডার সারি আসল ছবি এলে নিজে সরে (ফাইল থাকে), ক্রম 0,1,2 বাড়ে, ছবি ছাড়া সেভে default.jpg থাকে, ছবি মোছা (`[T]` পণ্য নিজে মোছে) |
| `php tools/test_reports.php` | রিপোর্ট হিসাব ৬৩টি চেক (v13 মাইগ্রেশন, তারিখ-সীমা/লিপ ইয়ার/অবৈধ-অ্যারে ইনপুট, বাতিল বাদ, ঠিক মাসের সীমানা সেকেন্ড পর্যন্ত, স্ট্যাটাস ফিল্টার, পেজিনেশন, ক্যাটেগরি-বিক্রি, মাসিক সিরিজ, CSV গার্ড; ২০১৯ সালের `[T]` অর্ডার নিজে মোছে) |
| `node tools/verify_reports.js <url>` | রিপোর্ট পেজ ও ড্যাশবোর্ড end-to-end ৫৮টি চেক (অ্যাক্সেস: গেস্ট/পারমিশন ছাড়া/Super Admin, KPI মান, ফিল্টার, অ্যারে ইনপুট, পেজিনেশন, CSV, ড্যাশবোর্ড সংখ্যা ডাটাবেসের সাথে মেলা, ২ প্রস্থ লেআউট ও ফিল্টার ঘর দেখা-লুকানো; `[T]` অর্ডার ও অস্থায়ী সেশন নিজে মোছে) |
| `php tools/test_costs.php` | ক্রয়মূল্য/খরচ লজিক ১০০টি চেক (v14 মাইগ্রেশন idempotent ও NULL-অক্ষত, `cs_parse_money`, গড় ক্রয়মূল্য সূত্র, snapshot অপরিবর্তনীয়তা, `ex_clean` যাচাই, খরচ CRUD/যোগফল/সীমানা-তারিখ/ক্যাটেগরি; `[T]` ডেটা ২০১৯ সালে, নিজে মোছে) |
| `node tools/verify_costs.js <url>` | ক্রয়মূল্য ও খরচ end-to-end ৯৩টি চেক (পণ্য ফর্মে ক্রয়মূল্য সেভ/অবৈধ/অ্যারে/পুরোনো ফর্ম, দ্রুত যোগ, আসল চেকআউটে snapshot, অ্যাডমিন order_edit-এ নতুন আইটেমে snapshot, স্টক রিপোর্টের মূল্য ডাটাবেসের সাথে মেলা ও CSV, খরচ পেজ: পারমিশন/যাচাই/XSS/CSRF/ফিল্টার/CSV/পেজিনেশন/মোডাল, ২ প্রস্থ; `[T]` ডেটা নিজে মোছে, আসল স্টক অপরিবর্তিত যাচাই) |
| `php tools/test_purchases.php` | ক্রয়/সরবরাহকারী লজিক ১৩০টি চেক (v15 মাইগ্রেশন, যাচাইয়ের সব ভুল-ইনপুট, স্টক ও গড় ক্রয়মূল্য, একই পণ্য দুই লাইনে, ট্রানজাকশন rollback, কিস্তি/সেন্ট-হিসাব, একসাথে পরিশোধের বণ্টন, বাতিল, তালিকা/ফিল্টার/মোট; `[T]` ডেটা ২০১৯ সালে, নিজে মোছে) |
| `node tools/verify_purchases.js <url>` | ক্রয় end-to-end ১৫৭টি চেক (৫টি পেজের অ্যাক্সেস/পারমিশন, সরবরাহকারী CRUD/XSS/CSRF, আসল ফর্ম-পোস্টে ক্রয় → স্টক ও গড় ক্রয়মূল্য ডাটাবেসে, ভুল-ইনপুটে ফর্মের মান ফেরত, কিস্তি, একসাথে পরিশোধ, বাতিল, তালিকা/KPI/পেজিনেশন, ব্রাউজারে ফর্মের JS (সারি যোগ/সরানো, লাইন মোট, "পুরোটা পরিশোধ") ও UI থেকে Save, মোডাল, ২ প্রস্থ; `[T]` ডেটা নিজে মোছে, আসল স্টক অপরিবর্তিত যাচাই) |
| `php tools/test_finance.php` | P&L/ক্রয়/খরচ রিপোর্টের হিসাব ৪৯টি চেক (হাতে-মেলানো ২০১৯ ফিক্সচার: বাতিল বাদ, COGS-এ অজানা ০ নয়, রাজস্ব = বিক্রয় + ডেলিভারি, Delivered বেসিস ও ঋণাত্মক নিট, মাস-সিরিজ যোগফল = বার্ষিক, পণ্য/ক্যাটেগরি মার্জিন, অর্ডার-লাভ null নিয়ম, ক্রয় রিপোর্টে বাতিল বাদ, খরচের সীমানা-তারিখ; ইচ্ছাকৃত ভুল ঢুকিয়ে টেস্ট ফেল করে দেখা হয়েছে; `[T]` ডেটা নিজে মোছে) |
| `node tools/verify_finance.js <url>` | লাভ-ক্ষতি/ক্রয়/খরচ রিপোর্ট end-to-end ১১৪টি চেক (অ্যাক্সেস/ট্যাব, প্রতিটি KPI ও বিবরণী মান, বেসিস, অজানা-সতর্কতা, রং, ফিল্টার/অবৈধ-অ্যারে ইনপুট, CSV, অর্ডার-রিপোর্টের লাভ কলাম, অর্ডার-ভিউ লাভ, ড্যাশবোর্ড নিট লাভ ডাটাবেসের সাথে মেলা, ৪ পেজ × ২ প্রস্থ লেআউট ও ফিল্টার-ঘর; `[T]` ডেটা নিজে মোছে) |
| `node tools/verify_admin_brand.js <url>` | অ্যাডমিনের নাম ১৪টি চেক (সাইডবার/ট্যাব-শিরোনাম/ফুটার/লগইন-শিরোনাম `settings.store_name` থেকে; ফাঁকা/স্পেস/সারি-নেই → `eStore`; ৬০ অক্ষর সীমা; HTML escape; গ্রাহক-সাইটের সাথে একই উৎস; `store_name` শুরুতে snapshot করে হুবহু ফেরায়) |
| `node tools/verify_dashboard.js <url>` | ড্যাশবোর্ড ডিজাইন ৪৪টি চেক (হিরো, আজ/এই-মাসের KPI ও গতকাল/গত-মাসের তুলনা ডাটাবেস থেকে আলাদা হিসাব করে মেলানো, অবস্থা-বার ১০০%, ডোনাট, চার্ট ৬ বার, সতর্কতার সংখ্যা, দ্রুত অ্যাকশনে সেটিংস নেই ও পারমিশন-ভেদে টাইল, সাম্প্রতিক ৮ অর্ডার ক্রম হুবহু, ২ প্রস্থ লেআউট/overflow; `[T]` অর্ডার (আজ/গতকাল/গত মাস) নিজে বানায়-মোছে) |
| `node tools/verify_admin_nav.js <url>` | অ্যাডমিন সাইডবারের গ্রুপ ৬১টি চেক (Super Admin: ২৫টি মেনু ঠিক গ্রুপে, ক্রম, ডুপ্লিকেট নেই; ২৯টি পেজে নিজের গ্রুপ খোলা ও ১টি মেনু সক্রিয়; খোলা-বন্ধ ও localStorage মনে রাখা, নষ্ট/অবৈধ মান; `html.js` না থাকলে সব দেখা; সীমিত-পারমিশনে খালি গ্রুপ লুকানো, Super-only মেনু; মোবাইল ড্রয়ার; অস্থায়ী সেশন নিজে বানায়-মোছে, ডাটাবেস বদলায় না) |
| `node tools/verify_full_journey.js <url>` | **পুরো প্রবাহ আসল ফর্ম/এন্ডপয়েন্টে ৭৫টি চেক:** পণ্য এন্ট্রি → সরবরাহকারী → ক্রয় (বাকি/কিস্তি/একসাথে/বাতিল) → গ্রাহকের কার্ট+চেকআউট অর্ডার (স্টক, ক্রয়মূল্য snapshot, ডেলিভারি, স্টকের বেশি আটকানো) → অ্যাডমিন স্ট্যাটাস/ডিসকাউন্ট/পরিমাণ বদল → কুরিয়ার (নকল সার্ভার; delivered/cancelled, স্টক ফেরা) → খরচ → রিপোর্ট/P&L/ড্যাশবোর্ড/CSV/ট্র্যাকিং হাতে-হিসাবে মেলানো; `[T]` ডেটা ও steadfast সেটিংস নিজে ফেরায়, আসল স্টক/সারি-সংখ্যা অপরিবর্তিত যাচাই। **নোট:** লোকালে ঢাকা জেলার ডেলিভারি ফি ০ (ফ্রি) আছে |
| `php tools/test_steadfast.php` | কুরিয়ার লজিক ১৩১টি চেক (বন্ধ অবস্থায় শূন্য কল, sf_ready, Base URL/কী যাচাই ও মাস্ক, payload সীমা, সংযোগ পরীক্ষা, পাঠানো সফল/ব্যর্থ (422/JSON 400/HTML/আইডি-ছাড়া/ভুল কী/সার্ভার-বন্ধ), ডুপ্লিকেট ও দখল, সিঙ্ক ও auto-status (cancel-এ স্টক ফেরা, উল্টো যাত্রা নয়), sync_all, সম্পাদনার তালা, লগে সিক্রেট নেই; `tools/mock_steadfast.php` নিজে চালায় (পোর্ট 8131); steadfast সেটিংস ফেরায়, `[T]` ডেটা মোছে) |
| `node tools/verify_steadfast.js <url>` | কুরিয়ার end-to-end ৪৬টি চেক (বন্ধ অবস্থা, সেটিংস মাস্কিং, সংযোগ পরীক্ষা, পাঠানো/ডুপ্লিকেট/সিঙ্ক, তালা, পারমিশন, CSRF; অস্থায়ী সেশন ও নকল সার্ভার নিজে বানায়-মোছে) |
| `php tools/test_order_access.php` | গোপন কোড, মোবাইল/কোড নরমালাইজ, `oa_find`, আইডি-মালিক নিয়ম, ট্র্যাকিং সীমা, মাইগ্রেশন idempotent ৯০টি চেক (`[T]` অর্ডার নিজে মোছে) |
| `node tools/verify_order_tracking.js <url>` | ট্র্যাকিং end-to-end ৩৩টি চেক (গেস্ট পেজ খোলা, ভিউতে লগইন redirect, ৭ দিন + আরও দেখুন ১২→২২→২৭, অন্যের অর্ডার বন্ধ, ভুল চেষ্টার সীমা, ২ প্রস্থ; `[T]` গ্রাহক/অর্ডার ও অস্থায়ী সেশন নিজে বানায়-মোছে) |
| `node tools/verify_login_security.js <url>` | লগইন সুরক্ষা end-to-end ৬১টি চেক (সেটিংস পেজ, আসল লগইন ফর্মে আটকানো, অন্য আইপি অপ্রভাবিত, লক খোলা, ইউজারনেম-লক, সাদা তালিকা, ধীর করা, সুরক্ষা বন্ধ, CSRF, সার্ভার-যাচাই; নকল আইপি + অস্থায়ী অ্যাডমিন `[t]admin`; শেষে সব ফেরায়) |
| `php tools/test_order_discount.php` | ডিসকাউন্ট লজিক ৪৬টি চেক (সংখ্যা যাচাই, টাকা/শতাংশ, সাবটোটালে ছাঁটা, মোট, কারণ-লেখা, মাইগ্রেশন; ডাটাবেস বদলায় না) |
| `node tools/verify_discount.js <url>` | অ্যাডমিন ডিসকাউন্ট end-to-end ৪৩টি চেক (টেস্ট অর্ডার বানায়: টাকা/শতাংশ, Update/Save, ভুল-জাল মান, CSRF, ক্রেতা/অ্যাডমিন/প্রিন্ট পেজ, তুলে নেওয়া; শেষে মুছে স্টক ফেরায়) |
| `node tools/verify_order_edit_ui.js <url>` | admin/order_edit-এর পরিমাণ-বক্স, সংখ্যা ইংরেজি অঙ্কে, জেলা খোঁজা-বাছাই (খোঁজা, ক্লিকে ফি বসা, জমায় district_id) ৪২টি চেক (অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; Save চাপে না) |
| `node tools/verify_order_font.js <url>` | অর্ডার ডিটেইলস ও ট্র্যাকিং পেজে বাংলা অঙ্ক/এমোজি/ফলব্যাক ফন্ট নেই, তারিখ-পরিমাণ ইংরেজি অঙ্কে, overflow নেই ২০টি চেক (ডাটাবেস বদলায় না) |
| `node tools/verify_cart_qty.js <url>` | কার্ট পরিমাণ স্বয়ংক্রিয় সেভ, ব্যাজ = আলাদা পণ্যের সংখ্যা, "চেকআউট করুন" বাটনে নতুন পরিমাণ, স্টক সীমা, CSRF, চেকআউট সামারির অঙ্ক/ফন্ট (Times/Segoe ফলব্যাক নেই), কার্ট সারির ইউনিট-দাম/লাইভ লাইন-মোট হেডার ব্যাজ ও "সরান" বাটন ৫৩টি চেক (ডাটাবেস বদলায় না) |
| `php tools/test_delivery.php` | ডেলিভারি লজিক ৬৫টি চেক (৬৪ জেলা, ফাঁকা=গ্লোবাল/0=ফ্রি, যাচাই, সব-অথবা-কিছুই-না সেভ; শেষে চার্জ আগের অবস্থায় ফেরায়) |
| `node tools/verify_delivery.js <url>` | চেকআউট জেলা→চার্জ, সত্যিকারের অর্ডার, জাল ফিল্ড/অবৈধ জেলা, পুরোনো ক্যাশ-ফর্ম, অ্যাডমিন ফিল্টার/বাল্ক/সেভ, অর্ডার এডিট, আলাদা গ্লোবাল সেভ, জেলা খোঁজা (combobox) ১০৫টি চেক (mysql CLI+php লাগে; টেস্ট অর্ডার মুছে স্টক ও চার্জ ফেরায়) |
| `php tools/test_announcements.php` | ঘোষণা লজিক ৬০টি চেক (যাচাই, লিংক-গার্ড, সময়সীমা, একটিই দেখানো, XSS escape, লেখা-যেমন-দেওয়া-তেমন; নিজে পরিষ্কার করে এবং আসল ঘোষণার চালু/বন্ধ অবস্থা snapshot করে যাচাই করে যে অপরিবর্তিত) |
| `node tools/verify_announcements.js <url>` | বার/পপআপ ব্রাউজার টেস্ট ৬৬টি চেক (বাংলা অঙ্কে Noto Sans Bengali ব্যবহার সহ) (২ প্রস্থ: delay, ✕/Esc/ব্যাকড্রপ, reshow never/session/days/always, শুধু হোমে পপআপ; mysql CLI লাগে, নিজে পরিষ্কার করে) |
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
| edit-এ default.jpg হাতে ডিলিট করতে হত | নতুন ছবি আপলোডে সেগুলো `sort_order` 0 থেকে বসত আর প্লেসহোল্ডার সারিও (sort 0, ছোট id) থেকে যেত → মূল ছবি default.jpg-ই থাকত; এখন আসল ছবি সেভ হলে `product_images`-এর default.jpg সারি মোছে (ফাইল নয়) এবং নতুন ছবির `sort_order` বিদ্যমান সর্বোচ্চ + 1 থেকে শুরু (`product_form.php`) |
| `can()` খালি `admin_permissions` হলে Super Admin-কেও false দেয় | টেস্ট সেশনে অন্তত একটি পারমিশন রাখুন (`make_admin_session.php` তাই করে) |

---

## ১০. লাইভ ডিপ্লয় চেকলিস্ট

### ⚠️ প্রতিবার আপলোডের আগে (ভুল হলে লাইভে ৫০০/সাদা পেজ)
1. **`includes/live.config.php` আপলোড করে লাইভে নাম দিন `includes/config.php`।** নাম না বদলালে লাইভের পুরোনো `config.php` থেকে যায় — নতুন মাইগ্রেশন (v8, v9, v10…) চলে না, নতুন টেবিল/কলাম (`announcements`, `delivery_districts`, `orders.discount`…) তৈরি হয় না, আর নতুন কোড সেগুলো খুঁজতে গিয়ে ৫০০ দেয় (প্রোডাকশনে বিবরণ লুকানো, শুধু "সাময়িক সমস্যা")। (২০২৬-১১-০৯ এ এটাই ঘটেছিল)
2. **ক্রম:** নতুন ফাইল (`includes/schema_vN.php` সহ) → বদলানো ফাইল → **সবার শেষে** config। config আগে গেলে এবং `schema_vN.php` পৌঁছানোর আগে `require_once` ফ্যাটাল হয়
3. আগে লাইভ DB ব্যাকআপ (phpMyAdmin → Export) ও পুরোনো `config.php`-র কপি
4. লোকালের `includes/config.php` (root, খালি পাসওয়ার্ড) কখনো লাইভে নয়
5. আপলোডের পর: হোমপেজ একবার খুলুন (মাইগ্রেশন চলে) → phpMyAdmin-এ `settings.schema_vN_at` ও নতুন টেবিল দেখুন → অ্যাডমিনে লগআউট/লগইন (পারমিশন সেশনে জমা থাকে) → `Ctrl+Shift+R`
6. **৫০০ হলে:** (ক) config-এর নাম বদলানো হয়েছে কি, (খ) নতুন `includes/*.php` সব পৌঁছেছে কি, (গ) লাইভের `logs/php_error.log`-র শেষ লাইন ("Failed opening required…")
7. লাইভে কার্ল/টুল দিয়ে সাইট দেখা যায় না (InfinityFree-র JS বট-চ্যালেঞ্জ) — headless Chrome/ব্রাউজার ব্যবহার করুন


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
- নতুন admin মেনু যোগ করলে ৪ জায়গা: `admin/header.php`, `permission_labels.php`, `auth_functions.php` (`get_first_allowed_page`), এবং মাইগ্রেশনে পারমিশন insert। **`admin/header.php`-এ এখন মেনু ডেটা-চালিত গ্রুপে** (`$NAV_GROUPS`): নতুন মেনু = সংশ্লিষ্ট গ্রুপের `items`-এ এক সারি (`can` = পারমিশন বা `'super'`, `files` = সক্রিয়-মেনুর সব ফাইল, `href`, `icon`, `label`)। সব ফাইল `files`-এ না দিলে সেই পেজে মেনু সক্রিয় দেখায় না
- নতুন পারমিশন যোগ হলে `tools/verify_permissions_ui.js`-এর সংখ্যা (এখন ২১) বদলাতে হবে; permission-এর বাংলা নাম সাইডবারের কোনো মেনু-নামের সাথে হুবহু মিলতে হয় (টেস্টে ধরা)
- বড় পরিবর্তনের আগে ফাইলের ব্যাকআপ কপি রাখুন (প্রজেক্ট git-এ নেই); এবারেরটি `C:\laragon\wwwstore_backup_pre_homesections\`
