# eStore Changelog

## 2026-11-09 — ডেমো ডাটাবেস ডাম্প হালনাগাদ

- `database/estore_schema_demo.sql`: ২২ → **২৪টি টেবিল** (নতুন `announcements`, `delivery_districts`), `orders`-এ নতুন কলাম (`shipping_district`, `discount`, `discount_note`, `discount_by`), ৬৪ জেলা (ঢাকা ৬০, গ্লোবাল ১২০), নতুন পারমিশন `manage_announcements`/`manage_delivery` (Super Admin-এ যুক্ত), মাইগ্রেশন-চিহ্ন v8–v10
- গ্রাহক, অর্ডার, মেসেজ, রিভিউ, অ্যাডমিন, `page_views`, ঘোষণা, `store_phone`/`store_email` নেই; ফোন/ইমেইল/ডোমেইন/হ্যাশ/আইপি স্ক্যান পরিষ্কার
- যাচাই: খালি DB-তে ইমপোর্ট ত্রুটিহীন; v8–v10 মাইগ্রেশন জোর করে আবার চালালেও ডেটা অপরিবর্তিত

## 2026-11-09 — ইনভয়েস (order_print) প্রিন্ট-লেআউট ঠিক

- **বাগ:** "প্রিন্ট" চাপলে কাস্টমার তথ্য ও অর্ডার তথ্য নিচে নিচে আসত। কারণ কলাম ছিল `col-lg-6` (≥৯৯২px), কিন্তু A4 প্রিন্টের পাতার চওড়া ≈৭৯৪px
- **ঠিক:** `col-12 col-sm-6` + `@media print`-এ সবসময় পাশাপাশি (gutter ০, `gap:12px`, `calc(50% - 6px)`); `@page{size:A4;margin:12mm}`; কার্ড/সারি পাতা-ভাঙায় আলাদা হয় না; রং-ছাপ (`print-color-adjust`)
- তারিখ বাংলা অঙ্কে (`০৯ Nov, ২০২৬`) ছিল → ইংরেজি অঙ্কে `09 Nov 2026, 4:26 PM` (সাইটের বাকি অংশের মতো)
- নতুন টেস্ট `tools/verify_order_print.js` (২৯টি চেক, আসল PDF ১ পাতা সহ)

## 2026-11-09 — অর্ডারে অ্যাডমিন ডিসকাউন্ট, কার্ট-ব্যাজ, অ্যাডমিনে জেলা খোঁজা

### অর্ডারে ডিসকাউন্ট (`schema_v10`)
- `admin/order_edit.php`-এ "ডিসকাউন্ট" ঘর: ৳ টাকা / % শতাংশ + ঐচ্ছিক কারণ; সারাংশে লাইভ "Discount −৳…" ও মোট; শতাংশে "= ৳135 ছাড়" হিন্ট
- মোট = সাবটোটাল − ডিসকাউন্ট + ডেলিভারি; সার্ভারে আবার হিসাব; সাবটোটালের বেশি হলে ছাঁটা; ঋণাত্মক/অক্ষর/অ্যারে/১০০%+ বাতিল; CSRF-সুরক্ষিত
- নতুন কলাম `orders.discount` (ডিফল্ট ০), `discount_note`, `discount_by`; পুরোনো অর্ডার অক্ষত
- দেখায়: order_view, order_print, ক্রেতার `single_order_details.php` (শুধু টাকা, কারণ/দাতা নয়)
- বাগ-ফিক্স: "Update Price" শিপিং ফি সংরক্ষণ করত না → এখন করে
- নতুন: `includes/schema_v10.php`, `includes/order_discount.php`; টেস্ট `tools/test_order_discount.php` (৪৬), `tools/verify_discount.js` (৪৩)

### কার্ট-ব্যাজ
- ব্যাজ এখন **আলাদা পণ্যের সংখ্যা** (আগে পরিমাণের যোগফল); একই পণ্য আবার যোগ করলে বাড়ে না; `verify_cart_qty.js` ৫৮টি চেক

### অ্যাডমিনে জেলা খোঁজা
- চেকআউটের খোঁজা-যোগ্য জেলা বাছাই একটি শেয়ার করা `assets/js/district-picker.js`-এ (CSS সহ); `admin/order_edit.php`-এও একই (বাংলা/English/পুরোনো বানান, কীবোর্ড, মোবাইলে ফুল-স্ক্রিন, প্রতি সারিতে চার্জ); জেলা বাছলে শিপিং ফি বসে
- অ্যাডমিনে প্যানেল কমপক্ষে ৩০০px; ডিসকাউন্টের ধরনের লেবেল "৳ টাকা"/"% শতাংশ" (শুধু "৳" ছোট দেখাত)
- চেকআউট থেকে ইনলাইন কোড সরানো (~১৫০ লাইন কম); `verify_delivery.js` ১০৫টি অপরিবর্তিত পাস; `verify_order_edit_ui.js` ৪২টি

## 2026-11-09 — অ্যাডমিন অর্ডার এডিটের ডিজাইন ঠিক

- **পরিমাণ-বক্স:** `.qtyBox` ছিল `display:grid`, টেবিলের ঘরের পুরো চওড়া নিত → − ১ + এর পাশে লম্বা ফাঁকা সাদা বার। এখন `inline-grid; width:max-content` (১৩৮px), ফোকাস-রিং, স্পিনার-তীর লুকানো, সংখ্যা মাঝখানে গাঢ়
- **সংখ্যা:** JS-এর `bdMoney()` ছিল `bn-BD` → সারাংশ/লাইন-মোট বাংলা অঙ্কে (`৳২,৯৪০`), দামের কলাম ইংরেজি অঙ্কে — অমিল। এখন `en-US`
- নতুন টেস্ট `tools/verify_order_edit_ui.js` (১১টি চেক)

## 2026-11-09 — অর্ডার ডিটেইলস ও ট্র্যাকিং পেজের ফন্ট ঠিক

- **অর্ডার ডিটেইলস (`single_order_details.php`):** টাকা/মোবাইল ইংরেজি অঙ্কে অথচ পরিমাণ ও তারিখ বাংলা অঙ্কে ছিল (`১`-কে `৬`, `৪`-কে `৮`-এর মতো দেখাত; তারিখ `০৯ Nov, ২০২৬ ৪:২৬ PM` মিশ্র)। এখন সব ইংরেজি অঙ্কে, তারিখ `09 Nov 2026, 4:26 PM`; পরিমাণ কলামের ফন্ট ও tabular অঙ্ক ঠিক
- "সব অর্ডার" বাটনের 📦 এমোজি (আলাদা Segoe UI Emoji ফন্ট) → Font Awesome আইকন
- **ট্র্যাকিং (`track_orders.php`):** একই `dateBD()` মিশ্র-অঙ্ক সমস্যা, একই ঠিক
- নতুন টেস্ট `tools/verify_order_font.js` (২০টি চেক)

## 2026-11-09 — কার্টের পরিমাণ সেভ ও চেকআউট সামারির ফন্ট ঠিক

- **বাগ:** কার্টে `+` চেপে পরিমাণ বাড়ালে চেকআউটে ১-ই যেত। কারণ: স্টেপার শুধু পেজের সংখ্যা বদলাত; "চেকআউট করুন" ছিল সাধারণ লিংক, তাই আলাদা "পরিমাণ আপডেট করুন" না চাপলে সার্ভারের কার্ট বদলাত না
- **ঠিক:** (১) পরিমাণ বদলালে ৩৫০ms পর নিজে সার্ভারে সেভ; (২) "চেকআউট করুন" এখন ফর্ম জমা দিয়ে যায়; (৩) "পরিমাণ আপডেট করুন" বাটন আগের মতোই; স্টক সীমা ও CSRF অক্ষত
- **চেকআউট সামারির ফন্ট:** সংখ্যায় আরবি হাজার-বিভাজক `٬` ছিল, যা সাইটের ফন্টে নেই (ব্রাউজার Times New Roman থেকে গ্লিফ আনত) → ভাঙা দেখাত; বাংলা অঙ্ক কার্ট/পণ্য পেজের ইংরেজি অঙ্কের সাথে অমিল ছিল। এখন ইংরেজি অঙ্ক (`৳2,250`), "× ৫" ফ্যাকাশে ১১.৫px থেকে `পরিমাণ: 5 × ৳450` ১২.৮px; নাম/মূল্য/মোটের ফন্ট-ক্রম ঠিক
- **কার্টের সারি:** আগে সারিতে ইউনিট-দামের বদলে লাইন-মোট (যেমন ২৯০ টাকার পণ্য ৫টি → "৳1,450") দেখাত, আর `+` চাপলে বদলাত না (রিলোডে লাফ দিত)। এখন ইউনিট-দাম + "প্রতিটি" ও আলাদা লাইন-মোট (লাইভ); হেডার ও bottom-nav ব্যাজও সঙ্গে সঙ্গে বদলায়
- **"সরান" বাটন:** ছোট লাল লেখা (~১৮px) থেকে নরম-লাল পিল বাটন (ট্র্যাশ আইকন, উচ্চতা ৩৪px, মোবাইলে ৩৮px, হোভার/ফোকাস-রিং/চাপার অবস্থা); চাপলে "সরানো হচ্ছে…" ও নিষ্ক্রিয় (দুবার চাপা যায় না); স্ক্রিন-রিডারের জন্য পণ্যের নামসহ `aria-label`; সরানোর সময় অপেক্ষমাণ স্বয়ংক্রিয় সেভ বাতিল (নইলে সরানো পণ্য আবার ফিরে আসতে পারত)
- বদলেছে: `cart.php`, `checkout.php`; নতুন টেস্ট `tools/verify_cart_qty.js` (৫৩টি চেক); `verify_delivery.js` নতুন অঙ্ক-ফরম্যাটে (১০৫)

## 2026-11-09 — জেলা-ভিত্তিক ডেলিভারি চার্জ (নতুন মেনু "ডেলিভারি চার্জ")

- ৬৪ জেলার প্রত্যেকের আলাদা চার্জ; **ফাঁকা = গ্লোবাল, 0 = ফ্রি**; সব পণ্যে `free` ট্যাগ থাকলে ০ (বিদ্যমান নিয়ম)
- চেকআউটে ঢাকা/সারা-দেশ রেডিওর জায়গায় জেলা ড্রপডাউন (৮ বিভাগ অনুযায়ী গ্রুপ, required); চার্জ ও মোট সঙ্গে সঙ্গে হালনাগাদ
- `place_order.php`-এ আসল হিসাব `dl_quote()` দিয়ে (আগে দুই ফাইলে কপি ছিল); অবৈধ জেলা id বাতিল; ক্লায়েন্টের জাল `shipping_fee`/`free_shipping` উপেক্ষা
- `orders.shipping_district` যোগ; `shipping_area` ENUM অক্ষত (ALTER নেই) — পুরোনো অর্ডার ও ভিউ আগের মতো
- অ্যাডমিন: এক পেজে ৬৪ জেলা, খোঁজা, বিভাগ ফিল্টার, "এই বিভাগের সবাইকে" বাল্ক, কার্যকর চার্জ লাইভ প্রিভিউ; সব-অথবা-কিছুই-না সেভ
- অর্ডার এডিটে জেলা বদলালে শিপিং ফি বসে (হাতে বদলানো যায়); order_view/print/success/single_order_details-এ জেলার নাম; success পেজে "ডেলিভারি চার্জ (জেলা)"
- সেটিংস থেকে পুরোনো ঢাকা/সারা-দেশ ফিল্ড সরানো; নতুন মেনুর লিংক
- **চেকআউটে জেলা খোঁজা:** সাধারণ ড্রপডাউনের বদলে খোঁজা-যোগ্য বাছাই-বক্স — বাংলা, English ও পুরোনো বানানে (Chittagong, Comilla, Barisal, Jessore, Bogra…) খোঁজা যায়, শুরুর মিল আগে; প্রতি সারিতে চার্জ/"ফ্রি"; কীবোর্ড (↑ ↓ Enter Esc Home End); ডেস্কটপে ফিল্ডের নিচে (জায়গা কম হলে ওপরে) ড্রপডাউন, মোবাইলে ফুল-স্ক্রিন শিট (পেছনের স্ক্রল বন্ধ, কীবোর্ড উঠলেও সার্চ বক্স দেখা যায়); জেলা না বেছে সাবমিটে লাল বার্তা; JS না চললে আগের সাধারণ select; নতুন: `dl_aliases()` (`includes/delivery.php`)
- গ্লোবাল চার্জের **আলাদা ছোট ফর্ম ও নিজস্ব সংরক্ষণ বাটন** (৬৪ জেলার ফর্ম জমা দিতে হয় না); জেলার তালিকার আলাদা বাটন "জেলার চার্জ সংরক্ষণ"; দুটি স্বাধীন (`dl_save_global`, `dl_save_fees`)
- নতুন ফাইল: `includes/schema_v9.php`, `includes/delivery.php`, `admin/delivery.php`; বদলেছে: `config.php`/`live.config.php`, `checkout.php`, `place_order.php`, `success.php`, `single_order_details.php`, `admin/order_edit.php`, `order_view.php`, `order_print.php`, `settings.php`, `settings_save.php`, `admin/header.php`, `permission_labels.php`, `auth_functions.php`
- টেস্ট: `tools/test_delivery.php` (৬৫), `tools/verify_delivery.js` (১০৫); `verify_permissions_ui.js` এখন ১৭ পারমিশন
- লোকাল ডাটাবেসে পুরোনো `delivery_dhaka`/`delivery_nationwide` দুটিই ০ ছিল, তাই মাইগ্রেশনে ঢাকা ০ ও গ্লোবাল ০ বসেছে (আচরণ অপরিবর্তিত)। লাইভে মান আলাদা হলে সেটাই কপি হবে

## 2026-11-09 — ঘোষণা মেনু (বার + পপআপ), `track_orders.php` ডিজাইন ঠিক

### নতুন: "ঘোষণা" মেনু (পারমিশন `manage_announcements`, `schema_v8`)
- **বার**: সব ফ্রন্ট পেজের ওপরে সরু রঙিন লাইন (নীল/কমলা/সবুজ), ঐচ্ছিক লিংক ও ✕
- **পপআপ**: শুধু হোমপেজে; শিরোনাম, লেখা, ছবি (ঐচ্ছিক, ৫MB, ৯০০px-এর বেশি চওড়া হলে অনুপাত রেখে ছোট), বাটন+লিংক
- **নিয়ন্ত্রণ**: পপআপ কত সেকেন্ড পর আসবে (০–৬০); দর্শক কেটে দিলে আবার দেখাবে কি না — `never` (লেখা বদলালে আবার আসে) / `session` / `days` (১–৩৬৫ দিন) / `always`; শুরু-শেষের সময়
- প্রতি ধরনে একটিই দেখায়: চালু ও সময়সীমার ভেতরের সবচেয়ে নতুনটি
- "কেটে দেওয়া" ব্রাউজারে (`localStorage`/`sessionStorage`) থাকে; চাবিতে `updated_at` আছে, তাই এডিটে সবাই আবার দেখে
- নিরাপত্তা: লিংকে `javascript:`/`data:`/`//` বাতিল, লেখা `h()` দিয়ে escape, আপলোডে `getimagesize()`, ছবি মোছায় পাথ-গার্ড
- নতুন ফাইল: `includes/schema_v8.php`, `includes/announcements.php`, `admin/announcements.php`, `admin/announcement_form.php`, `assets/js/announcements.js`; বদলেছে: `config.php`/`live.config.php`, `partials_header.php`, `partials_footer.php`, `components.css`, `admin/header.php`, `permission_labels.php`, `auth_functions.php`
- টেস্ট: `tools/test_announcements.php` (৫০), `tools/verify_announcements.js` (৫৬), `verify_permissions_ui.js` এখন ১৬ পারমিশন
- **সেটিংস পরিষ্কার:** `home_heading`/`home_notice` অ্যাডমিন ফর্ম, সেভ-তালিকা ও `index.php` থেকে সরানো (স্লাইডার না থাকলেও পণ্য-ফলব্যাকে হিরো দেখায়, তাই "স্বাগতম" বক্স কখনো আসত না; ডাটাবেসে পুরোনো মান পড়ে আছে)। "স্বাগতম" ফলব্যাক এখন স্থির লেখা + `store_name`
- **COD নোট** (`cod_note`) আগে কোথাও দেখাত না → এখন `checkout.php`-এ পেমেন্ট মেথডের নিচে সবুজ তথ্য-বক্সে দেখায় (খালি হলে আসে না, `h()` escape); সেটিংসে সেকশনের নাম "COD নোট"

### ঠিক: অ্যাডমিনে ফেভিকন
- `admin/header.php` ও `admin/login.php`-এ স্থির `/assets/favicon.ico` ছিল → এখন সেটিংসে আপলোড করা `store_favicon` দেখায়; না থাকলে (বা ফাইল না পেলে) `BASE_URL/assets/favicon.ico`

### ঠিক: `track_orders.php`
- মোবাইলে "খুঁজুন" বাটন কার্ডের বাইরে যেত → grid `minmax(0,1fr)`, ইনপুট `min-width:0`, ≤৭৬৮px-এ এক কলাম
- ডেস্কটপে টেবিল সংকুচিত (পেজে Bootstrap নেই, `.table`-এর `width/padding/border` ছিল না) → নিজস্ব স্টাইল যোগ

## 2026-11-08 — ছবির বক্স অনুপাত ঠিক + "সব দেখুন" বাটন নিচে

### বদলেছে (`assets/css/components.css`, `index.php`)
- **"সব দেখুন" বাটন** সেকশনের গ্রিডের নিচে, মাঝখানে (`.section-see-all-wrap`); মোবাইলে বড় (≤৬০০px: প্রস্থ `min(100%,320px)`)
- **প্রোডাক্ট কার্ডের ছবি**: `.fruit-img` স্থির উচ্চতা (160/120/85px) → `aspect-ratio:1/1`; ৭০০×৭০০ ছবি আর কাটে না (ডেস্কটপে কার্ড ~৮৮px লম্বা)
- **হিরো ও সাইড কার্ড**: স্থির উচ্চতা (420/300/220px) সরিয়ে হিরো `aspect-ratio:16/9`, কলাম `2.3fr 1fr`, সাইড কার্ড `flex:1`; সব প্রস্থে হিরো ১.৭৮, সাইড ১.৬০–১.৬৩, সাইড দুটোর মোট উচ্চতা = হিরো (হিরো ডেস্কটপে ৪২০→৪৫০px, মোবাইলে ২২০→১৯৩px)

### কারণ
আপলোডে ছবি ঠিক অনুপাতে (৭০০×৭০০, ১২০০×৬৭৫, ৬০০×৩৭৫) কাটা হয়, কিন্তু দেখানোর বক্স স্থির উচ্চতার ছিল এবং অনুপাত স্ক্রিনভেদে বদলাত (সাইড কার্ড ১.৫২–১.৮৯)। `object-fit:cover` তাই ছবির ওপর-নিচ/দুই পাশ কেটে দিত।

### সিদ্ধান্ত
`contain`/fit বা আপলোডে সাদা-প্যাডিং fit করা হয়নি: ছবি আগে থেকেই বক্সের অনুপাতে প্রসেস হয়, `contain` দিলে কোনো লাভ নেই, শুধু ফাঁকা অংশ আসে।

### টেস্ট
- `verify_see_all.js` এখন বাটন গ্রিডের নিচে ও মাঝখানে কি না মাপে; বাটন সংখ্যা ডাইনামিক (অন্তত ১টি); ৮৪/৮৪ পাস
- `verify_hero.js` ৬৫/৬৫, `verify_bottom_nav.js` ২০/২০, `test_wishlist_ui.php` ৭/৭
- `audit_gutters.js` চালিয়ে শেষ করা যায়নি (অনেক সময় নেয়); চওড়া বদলায়নি, শুধু উচ্চতা

### লাইভ আপলোড
শুধু `assets/css/components.css` (+ "সব দেখুন" বাটনের জন্য `index.php`, আগে আপলোড না হয়ে থাকলে)। তারপর `Ctrl+Shift+R`।

### ব্যাকআপ
`C:\laragon\www\estore_backup_pre_homesections\components.css.bak2` (কার্ড ছবির আগে), `components.css.bak3` (হিরো/সাইড কার্ডের আগে)

### জানা দরকার
- `product_form.php` আপলোডে jpg/png/webp অটো ৭০০×৭০০ ক্রপ/প্যাড হয়; HEIC/HEIF হুবহু সেভ হয় (GD পড়তে পারে না)
- আগে কাটা অবস্থায় আপলোড হওয়া ছবি CSS দিয়ে ফেরে না, আবার আপলোড করতে হবে

## 2026-11-05 — ডাইনামিক হোম সেকশন ম্যানেজার

### নতুন
- `includes/schema_v7.php`, `includes/home_sections.php`
- `admin/home_sections.php` (তালিকা, ↑↓ ক্রম, On/Off, মুছুন), `admin/home_section_form.php`
- পারমিশন `manage_home_sections`, সাইডবার মেনু "হোম সেকশন"
- টুল: `test_home_sections.php`, `verify_home_sections.js`, `make_admin_session.php`

### বদলেছে
- `index.php`: হার্ডকোড Featured/New Arrivals বদলে `home_sections` লুপ (শিরোনাম, ক্রম, পণ্য সংখ্যা ডাইনামিক)
- `admin/product_form.php`, `admin/product_add_for_mobile.php`: হার্ডকোড দুই টগল বদলে সেকশন-টগল তালিকা (`section_ids[]`)
- `admin/product_delete.php`: সংযোগ সারি মোছে
- `shop.php`: `?section=ID`; পুরোনো `?filter=featured` → "Featured…" সেকশন
- `admin/settings.php`/`settings_save.php`: পুরোনো চার শিরোনাম ফিল্ড সরানো
- `tools/seed_products.php`: সেকশনে সংযোগও দেয়; `tools/verify_permissions_ui.js`: ১৪→১৫

### সীমা
সর্বোচ্চ ৮, ন্যূনতম ২ সেকশন (ন্যূনতম ২টি **চালু** থাকতেই হবে); পণ্য সংখ্যা ১–৪৮।

### সেকশনের ধরন
`manual` (প্রোডাক্ট ফর্ম থেকে বাছাই), `category`, `discount`, `latest`, `bestseller`।
`new_default=1` সেকশনে নতুন পণ্যে ডিফল্ট টিক (সিডে New Arrivals)।

### ডেটা
- মাইগ্রেশন (v7): `is_featured=1` → Featured সেকশনে (৭টি), `is_new_arrival=1` → New Arrivals-এ (২১টি) কপি; `products`-এর পুরোনো কলাম ও `settings`-এর পুরোনো শিরোনাম key অক্ষত
- ব্যাকআপ: `database/database_estore_26-11-05.sql` (লোকাল, ২২ টেবিল); ফাইলের ব্যাকআপ `C:\laragon\www\estore_backup_pre_homesections\`

### লাইভ আপলোড
- নতুন: `includes/schema_v7.php`, `includes/home_sections.php`, `admin/home_sections.php`, `admin/home_section_form.php`
- বদলানো: `includes/live.config.php` (লাইভে `config.php` নামে), `index.php`, `shop.php`, `admin/header.php`, `admin/permission_labels.php`, `admin/auth_functions.php`, `admin/product_form.php`, `admin/product_add_for_mobile.php`, `admin/product_delete.php`, `admin/settings.php`, `admin/settings_save.php`
- আগে লাইভ DB ব্যাকআপ; হোমপেজ একবার খুললে মাইগ্রেশন চলে; অ্যাডমিনে লগআউট→লগইন করে "হোম সেকশন" মেনু দেখুন

### জেনে রাখুন
- সেকশনে পণ্য না থাকলে সেটি হোম থেকে লুকায়। আগে Featured ফাঁকা হলে সর্বোচ্চ ছাড়ের পণ্য দেখাত, এখন আর নয়
- `products.is_featured`/`is_new_arrival` আর লেখা হয় না; সত্য-উৎস `home_section_products`
- লাইভে `shop.php?filter=featured` পুরোনো লিংক "Featured…" নামের সেকশনে যায়, সেকশনের নাম বদলালে ফলব্যাক হিসেবে পুরোনো কলাম পড়ে

### যাচাই
`test_home_sections.php` 40/40, `verify_home_sections.js` 58/58, `verify_see_all.js` 54/54, `verify_hero.js` 83/83, `verify_permissions_ui.js` 14/14, `test_csrf.js` 18/18, `test_stock_flow.php` 10/10

## 2026-11-05 — পারমিশনের নাম সাইডবার মেনুর সাথে মিলিয়ে দেওয়া

### নতুন ফাইল
`admin/permission_labels.php` — permission identifier → বাংলা মেনু-নাম, আইকন ও গ্রুপের একক সোর্স:

| Identifier | মেনু নাম | গ্রুপ |
|---|---|---|
| `view_dashboard` | ড্যাশবোর্ড | সাধারণ |
| `manage_products` | পণ্য ব্যবস্থাপনা | ক্যাটালগ |
| `manage_products_quick` | দ্রুত পণ্য যোগ | ক্যাটালগ |
| `manage_categories` | ক্যাটেগরি | ক্যাটালগ |
| `manage_sliders` | স্লাইডার | ক্যাটালগ |
| `manage_reviews` | রিভিউ | কনটেন্ট |
| `manage_customers` | কাস্টমার | কনটেন্ট |
| `manage_pages` | পেজ | কনটেন্ট |
| `manage_orders` | অর্ডার ব্যবস্থাপনা | বিক্রয় |
| `payments` | পেমেন্ট | বিক্রয় |
| `view_stock_report` | স্টক রিপোর্ট | রিপোর্ট |
| `view_page_views` | Page Views | রিপোর্ট |
| `manage_admin_users` | ইউজার ও রোল | সিস্টেম |
| `settings` | সেটিংস | সিস্টেম |

ফাংশন: `permission_label()`, `permission_icon()`, `permission_group()`, `permission_grouped()`, `permission_unmapped()`

### পরিবর্তন

**`admin/permissions.php`** — এক টেবিলের বদলে গ্রুপ-ভিত্তিক কার্ড:
- প্রতিটি সারিতে আইকন + বাংলা মেনু-নাম + `identifier` + description
- গ্রুপ শিরোনামে সংখ্যা ব্যাজ
- ম্যাপে না থাকা পারমিশন থাকলে উপরে সতর্কবার্তা

**`admin/role_form.php`** — চেকবক্স এখন গ্রুপ অনুযায়ী সাজানো:
- প্রতিটি গ্রুপ আলাদা কার্ড, রঙিন হেডার
- লেবেলে আইকন + বাংলা নাম + ছোট করে identifier
- "সব নির্বাচন" / "সব বাদ দিন" বাল্ক বাটন

**`admin/permission_form.php`**:
- identifier টাইপ করার সাথে সাথে লাইভ প্রিভিউ — "মেনু নাম: স্লাইডার · গ্রুপ: ক্যাটালগ"
- ম্যাপে না থাকলে সতর্কবার্তা
- **mojibake ফিক্স**: duplicate-নামের বার্তায় ভুল স্ক্রিপ্টের অক্ষর ঢুকে গিয়েছিল → সঠিক বাংলা "ইতিমধ্যে"

### নিরাপত্তা
- `permission_labels.php`-এ সরাসরি অ্যাক্সেস → **403** (`BASE_URL` guard)
- `tools/security_audit.js` — helper ফাইল এক্সক্লুড লিস্টে যোগ (false positive বন্ধ)

### নতুন টেস্ট
`tools/verify_permissions_ui.js` — **১৪ চেক**: ১৪টি পারমিশন, ৬টি গ্রুপ, unmapped নেই, **সব লেবেল সাইডবারের মেনুর সাথে মিলেছে**, আইকন, role_form চেকবক্স/গ্রুপ/বাল্ক বাটন (কাজ করে), permission_form প্রিভিউ।

### যাচাই
- `verify_permissions_ui` **14/14**
- `security_audit` **0**, `test_csrf` 18/18, `verify_search_suggest` 41/41
- `php -l` 0 error; সব পেজ clean
- রোল সেভ রিগ্রেশন: Editor-এ ৩টি পারমিশন দিয়ে সেভ → DB-তে `1,2,5` (তারপর আগের অবস্থায় ফেরত)

## 2026-11-05 — রিভিউ সিস্টেম অডিট ও তিনটি বাগ ফিক্স

### সমীক্ষার ফল — কী কাজ করে
| ধাপ | অবস্থা |
|---|---|
| লগইন কাস্টমার, delivered অর্ডার থাকলে ফর্ম দেখা | ✅ |
| ফর্ম ফিল্ড (৫ স্টার, নাম, কমেন্ট, ছবি) | ✅ |
| CSRF সুরক্ষা | ✅ |
| ক্রয়-শর্ত (`delivered/shipped/processing`) | ✅ |
| ডুপ্লিকেট ব্লক | ✅ |
| `pending` স্ট্যাটাসে সংরক্ষণ | ✅ |
| pending রিভিউ পাবলিকভাবে অদৃশ্য | ✅ |
| Admin approve/reject/delete | ✅ |
| approve-এর পর রেটিং গড় + স্টার | ✅ |

### বাগ ১ — flash মেসেজে বাড়তি কোলন (HIGH, দৃশ্যমান)
`ok:` ৩ অক্ষর কিন্তু `err:` ৪ অক্ষর — কোড উভয় ক্ষেত্রে `substr($flash, 3)` করত, তাই ত্রুটি বার্তার আগে বাড়তি `:` দেখাত (`":আপনি ইতিমধ্যে..."`)।
- `includes/config.php` + `live.config.php` — নতুন helper `flash_is_ok()`, `flash_message()` (prefix অনুযায়ী সঠিক অফসেট)
- `product_details.php`, `account_dashboard.php` — helper ব্যবহার

### বাগ ২ — রিভিউ দেওয়ার পরেও ফর্ম দেখাত (MEDIUM, UX)
`$canReview` শুধু ক্রয় আছে কিনা দেখত, ডুপ্লিকেট আছে কিনা নয়। ফলে যিনি ইতিমধ্যে রিভিউ দিয়েছেন তিনি ফর্ম দেখতেন, সাবমিট করলে সার্ভার ব্লক করত — বিভ্রান্তিকর।
- এখন `product_details.php`-এ `alreadyReviewed` চেক: রিভিউ থাকলে ফর্ম লুকিয়ে বার্তা —
  - approved → "আপনার রিভিউ প্রকাশিত হয়েছে। ধন্যবাদ!"
  - pending → "আপনার রিভিউ পর্যালোচনার জন্য জমা আছে। অনুমোদনের পর প্রকাশিত হবে।"

### বাগ ৩ — রিভিউ ফ্লো-এর বাকি অংশ যাচাই
`review_submit.php`-এর সব বার্তা UTF-8 সঠিক পাওয়া গেছে (mojibake নেই)। মূল সমস্যা ছিল শুধু flash rendering (বাগ ১)।

### নতুন টেস্ট
- `tools/verify_review_flow.js` — **১৪ চেক**: ফর্ম/ফিল্ড/CSRF/product_id, সাবমিট, flash ক্লাস, ডুপ্লিকেট ব্লক, auto-tab-switch, গেস্টে ফর্ম নেই, pending পাবলিক নয়
- `tools/verify_review_moderation.js` — **১৩ চেক**: admin পেজ, ৩ ফিল্টার ট্যাব, কার্ড, approve/reject/delete বাটন, approve POST, flash, কাউন্ট আপডেট, CSRF ছাড়া ব্লক

### যাচাই
- `verify_review_flow` 14/14, `verify_review_moderation` 13/13
- `security_audit` 0, `test_csrf` 18/18, `verify_search_suggest` 41/41, `verify_hero` 83/83
- `php -l` 0 error; ৬টি পেজ clean
- টেস্ট ডেটা সম্পূর্ণ পরিষ্কার (reviews=0, orders=32, শুধু আপনার নিজের কাস্টমার অ্যাকাউন্ট অটুট)

## 2026-11-05 — Search suggestion দেখা যাচ্ছে না (ফিক্স)

### মূল কারণ
`.main-header { overflow:hidden }` — এই নিয়মটি মোবাইলে horizontal overflow ঠেকাতে জরুরি (AGENTS.md-এ সতর্কতা আছে, `overflow:visible` করলে bottom-nav ভাঙে)। কিন্তু `#suggestBox` ওই header-এর **ভেতরে** `position:absolute` ছিল, তাই বক্সটি header-এর সীমানায় **ক্লিপ** হয়ে যেত — রেন্ডার হচ্ছিল (`items=5`, `display:block`) কিন্তু চোখে দেখা যেত না।

**পরিমাপ:** বক্সের bottom 395px, header-এর bottom 91px — পুরোটাই কাটা পড়ছিল। `elementFromPoint` হিট করছিল `section.hero-grid-container`-এ, বক্সে নয়।

### ফিক্স
- `includes/partials_header.php` — `#suggestBox` header-এর বাইরে সরানো (ঠিক `</header>`-এর পরে), `aria-label` যোগ
- `assets/css/theme.css` — `.suggest-box` এখন `position:fixed` (header-এর overflow আর প্রভাব ফেলে না), `max-height:min(380px,60vh)`, `-webkit-overflow-scrolling:touch`
- `assets/js/home.js` — নতুন `positionSuggest()`:
  - ইনপুটের rect থেকে হিসাব করে fixed অবস্থান, ইনপুটের সাথে কেন্দ্র, viewport-এ clamp
  - নিচে ১৬০px-এর কম জায়গা থাকলে বক্স **উপরে** খোলে
  - `scroll` (passive) / `resize` / `orientationchange`-এ পজিশন আপডেট

### নতুন টেস্ট
`tools/verify_search_suggest.js` — **৪১ চেক**:
- ৩ প্রস্থ (1440/768/390) × ১০: বক্স খোলে, item রেন্ডার, বাস্তব দৃশ্যমানতা (`elementFromPoint`), header-এর বাইরে, ইনপুটের নিচে, প্রস্থ মিল, viewport-এ, overflow নেই
- ইন্টারঅ্যাকশন ১১: টাইপ, ফোকাসেবল আইটেম, ArrowUp/Down, Escape, ১ অক্ষরে না আসা, বাইরে ক্লিকে বন্ধ, স্ক্রলে রিপজিশন

### যাচাই
- `verify_search_suggest` → **41/41** (আগে box bottom 395 > header bottom 91 = অদৃশ্য)
- `audit_gutters` 42/42, `bottom_nav` 20/20, `verify_see_all` 54/54, `verify_hero` 83/83
- `security_audit` 0, `test_csrf` 18/18, `php -l` 0 error
- সব ফ্রন্টএন্ড পেজে `#suggestBox` রেন্ডার হচ্ছে

## 2026-11-05 — Page Views: কাউন্ট রিসেট, প্রোডাক্ট ভিউ ট্যাব, 1k ফরম্যাট

### ১. কাউন্ট রিসেট
`admin/page_views.php` — "কাউন্ট রিসেট" dropdown (Bootstrap):
- **শুধু প্রোডাক্ট ভিউ** → `DELETE FROM page_views WHERE page LIKE 'product[_]%'`
- **সব কাউন্ট** → `DELETE FROM page_views`
- উভয়ই `confirm()` ডায়ালগ, CSRF-protected POST, flash message সহ
- **CSRF ছাড়া POST → ডেটা অটুট** (যাচাইকৃত), **GET দিয়ে clear অসম্ভব** (শুধু POST হ্যান্ডেল করে)

### ২. প্রোডাক্ট অনুযায়ী ভিউ
- `product_details.php` ও `product.php` — এখন `increment_page_view('product_'.$id)`-ও ডাকে (আগে সব প্রোডাক্ট একসাথে `'product'` কী-তে জমা হতো, কোন পণ্য কতবার দেখা হয়েছে জানা যেত না)
- নতুন **প্রোডাক্ট ট্যাব** — `page_views.php?tab=products`:
  - থাম্বনেইল, প্রোডাক্ট নাম (নতুন ট্যাবে খোলা লিংক), `#id`, নিষ্ক্রিয় ব্যাজ
  - মুছে ফেলা প্রোডাক্টের ভিউ `LEFT JOIN`-এ "মুছে ফেলা প্রোডাক্ট" হিসেবে থাকে
  - ভিউ ডিসেন্ডিং + শেয়ার বার
- ৪টি সারাংশ কার্ড এখন: মোট ভিউ / পেজ ভিউ / প্রোডাক্ট ভিউ / সর্বোচ্চ

### ৩. 1k ফরম্যাট
`pv_short()` — ১০০০-এর বেশি হলে সংক্ষিপ্ত:

| মান | দেখায় |
|---|---|
| 999 | `999` |
| 1,000 | `1k` |
| 1,200 | `1.2k` |
| 1,702 | `1.7k` |
| 12,500 | `12.5k` |
| 999,949 | `999.9k` |
| 999,950 | `1M` |
| 1,250,000 | `1.3M` |

পুরো সংখ্যা `title` attribute-এ থাকে (hover-এ দেখা যায়)। বাগ ধরা পড়েছিল: ৯৯৯,৯৯৯ → `1000k`; থ্রেশহোল্ড `999950` করে ঠিক করা হয়েছে।

### যাচাই
- `tools/verify_page_views.js` পুনর্লিখিত → **29/29**: মেনু, ড্যাশবোর্ড পরিষ্কার, ৪ কার্ড, দুই ট্যাব, active state, রিসেট বাটন, শেয়ার বার, k ফরম্যাট (1,786 → 1.8k), প্রোডাক্ট thumbnail/লিংক/নাম/ক্রম, মোবাইল
- clear টেস্ট: প্রোডাক্ট-only → ৩ রো মুছে, পেজ ভিউ অটুট; সব → ০ রো; তারপর মূল ডেটা পুনরুদ্ধার
- `security_audit` 0, `php -l` 0 error
- ডেটা: page_views ১৮ রো (৭ প্রোডাক্ট), মোট ১,৭৫৮ ভিউ — অটুট

## 2026-11-05 — Admin সাইডবার স্ক্রল + Page Views আলাদা মেনু

### ১. Admin সাইডবার স্ক্রল হয় না (ফিক্স)
**সমস্যা:** `.sidebar { height:100vh; padding:16px }` — কোনো `overflow` ছিল না। মেনু ১৬টি লিংকে বেড়ে যাওয়ায় কম উচ্চতার স্ক্রিনে নিচের আইটেম (স্টক রিপোর্ট, পেমেন্ট, সেটিংস, লগআউট) সম্পূর্ণ কাটা পড়ছিল।

পরিমাপ (ফিক্সের আগে):

| viewport | sidebar clientH | scrollH | কাটা পড়ছে |
|---|---|---|---|
| 1440×600 | 600 | 875 | **275px** |
| 1280×520 | 520 | 875 | **355px** |
| 390×720 | 664 | 875 | **211px** |

**ফিক্স (`admin/header.php`):**
- `.sidebar` → `overflow-y:auto`, `display:flex; flex-direction:column`, custom thin scrollbar
- `.sidebar .navy` → `flex:1 1 auto; min-height:0; overflow-y:auto` — ব্র্যান্ড উপরে স্থির, মেনু অংশটাই স্ক্রল হয়
- `overscroll-behavior:contain` — পেজ স্ক্রল লিক করে না

### ২. Page Views এখন আলাদা Admin মেনু
- **নতুন পেজ** `admin/page_views.php` — ড্যাশবোর্ডের ছোট "Top 10" টেবিলের বদলে পূর্ণ রিপোর্ট:
  - ৪টি সারাংশ কার্ড: মোট ভিউ, ট্র্যাক করা পেজ, সর্বোচ্চ ভিউ (পেজ নামসহ), গড় ভিউ/পেজ
  - সম্পূর্ণ তালিকা (Top-10 সীমা নেই), ভিউ-ডিসেন্ডিং, top-3 র‍্যাংক ব্যাজ
  - প্রতিটি সারিতে শতকরা শেয়ার বার
  - বাংলা লেবেল ম্যাপিং (`index` → হোম, `category_1` → ক্যাটাগরি #1 …) এবং মূল কী `<code>`-এ
  - মোবাইলে ৪ → ১ কলাম, শেয়ার কলাম লুকানো, horizontal overflow নেই
- **নতুন পারমিশন** `view_page_views` (id 15) — `permissions` টেবিলে যোগ, Super Admin (role 1)-এ লিংক, `schema_v2.php`-তেও যোগ যাতে নতুন ইনস্টলে আসে
- **সাইডবার মেনু** — সিকিউরিটির পরে "Page Views" (আইকন `fa-chart-simple`), active state সহ
- `get_first_allowed_page()` ম্যাপে `view_page_views` যোগ
- `admin/index.php` থেকে Page Views কার্ড, এর কোয়েরি, এবং এর জন্য থাকা ~৬০ লাইনের CSS সরানো হয়েছে; সাধারণ `.table-responsive` নিয়ম রাখা হয়েছে

### নতুন টেস্ট
- `tools/verify_admin_sidebar.js` — ৪ প্রস্থ × ৪ চেক = **১৬ চেক**: overflow-y, clientH/scrollH, স্ক্রল করা যায় কিনা, লগআউট বাটনে পৌঁছানো যায় কিনা
- `tools/verify_page_views.js` — **১৪ চেক**: মেনু উপস্থিতি/আইকন/active state, ড্যাশবোর্ডে কার্ড নেই, পেজ নেভিগেশন, ৪ সারাংশ কার্ড, টেবিল সারি, শেয়ার বার, ডিসেন্ডিং অর্ডার, মোবাইল লেআউট

### যাচাই
- `verify_admin_sidebar` → **16/16** (সব উচ্চতায় স্ক্রল কাজ করে, লগআউটে পৌঁছায়)
- `verify_page_views` → **14/14**
- `security_audit` → 0, `php -l` সব PHP → 0 error
- `admin/page_views.php` লগইন ছাড়া → **302** (গার্ড ঠিক আছে)
- `admin/index.php` 200, কোনো Page Views কার্ড নেই; সাইডবারে মেনু আছে

## 2026-11-05 — slider_form: show_text select → checkbox switch

### পরিবর্তন
- `admin/slider_form.php` — "টেক্সট দেখাবে?" এখন **Bootstrap switch checkbox** (আগে দুই-অপশনের select):
  ```html
  <input type="checkbox" role="switch" name="show_text" value="1" id="showTextChk">
  <label for="showTextChk">ছবির ওপর টাইটেল ও সাবটাইটেল দেখাও</label>
  ```
  - ডিফল্ট **unchecked** (= 0, বন্ধ) — কিছু না করলে আগের আচরণ
  - লেবেলে ক্লিক করলেও টগল হয় (টাচ টার্গেট বড়)
  - লেবেল/হিন্ট টেক্সট "চালু/বন্ধ" ভাষায়
- Backend: `$show_text = !empty($_POST['show_text']) ? 1 : 0;` — unchecked checkbox POST-এ আসে না, তাই `empty()` দিয়ে নিরাপদে 0 ধরা হয়
- JS (`showTextSelect` → `showTextChk`) — `chk.checked` পড়ে ইনপুট enable/disable ও প্রিভিউ স্ক্রিম/টেক্সট আপডেট

### যাচাই
- **13/13** ব্রাউজার টেস্ট (`tools/verify_slider_form.js`): checkbox type, `role=switch`, দৃশ্যমানতা, লেবেল, পুরোনো select অনুপস্থিত, checked/unchecked অনুযায়ী state, ইনপুট disabled, হিন্ট, ক্লিক, লেবেল-ক্লিক, প্রিভিউ
- POST: unchecked → `show_text=0`, checked → `show_text=1` (দুই দিকই পরীক্ষিত)
- `verify_hero` 83/83, `verify_see_all` 54/54, `security_audit` 0, `php -l` 0 error

## 2026-11-05 — স্লাইডারে "টেক্সট দেখাবে?" select (default Disable)

### ডেটাবেস (Schema v6)
- নতুন মাইগ্রেটর `includes/schema_v6.php` — `sliders.show_text TINYINT(1) NOT NULL DEFAULT 0`
- চিহ্ন `settings.schema_v6_at`; `config.php` ও `live.config.php` দুটোতেই যোগ (additive, পুরোনো ডেটা অক্ষত)

### Admin ফর্ম
- `admin/slider_form.php` — টাইটেল/সাবটাইটেলের নিচে **"টেক্সট দেখাবে?"** select:
  - `0` → **ডিসেবল (শুধু ছবি ও বাটন)** ← ডিফল্ট
  - `1` → এনাবল (টাইটেল + সাবটাইটেল)
- নির্বাচন অনুযায়ী টাইটেল/সাবটাইটেল ইনপুট `disabled` হয়/worse হয়, পাশে বাংলা হিন্ট আপডেট হয়
- প্রিভিউতে স্ক্রিম + টাইটেল/সাবটাইটেল লাইভ দেখা যায় (বাটন প্রিভিউর সাথে একসাথে)
- INSERT ও UPDATE দুটোতেই `show_text` লেখা হয়

### ফ্রন্টএন্ড
- `index.php` — হিরো ও সাইড উভয়ে `show_text=1` **এবং** টাইটেল/সাবটাইটেল খালি নয় — এই দুটো শর্তে টেক্সট ওভারলে; নাহলে আগের মতো শুধু ছবি + বাটন (স্ক্রিমও থাকবে না)।
- `title` শুধু `alt` অ্যাট্রিবিউটে থাকে (SEO/accessibility), দৃশ্যমান হয় না।

### যাচাই
- select-এ `value="0" selected` default হিসেবে রেন্ডার হয়
- POST সেভ → `show_text=1` DB-তে যায়, হোমপেজে টাইটেল+সাবটাইটেল+স্ক্রিম দেখা যায়
- `show_text=0` স্লাইডে কোনো `hero-copy` বা `hero-scrim` রেন্ডার হয় না
- `verify_hero.js` আপডেট (per-slide) → **83/83**, `verify_see_all` 54/54, `security_audit` 0, `test_csrf` 18/18
- `php -l` সব PHP → 0 error

### বর্তমান ডেটা
| id | placement | show_text |
|---|---|---|
| 9 | hero | এনাবল |
| 10 | hero | এনাবল |
| 11 | hero | ডিসেবল |
| 12 | side | ডিসেবল |
| 13 | side | ডিসেবল |

## 2026-11-05 — নতুন হিরো স্লাইডার ও সাইড কার্ড + টেক্সট ওভারলে

### নতুন কনটেন্ট (৫টি)
পুরোনো ৫টি স্লাইডার `active=0` করে (ডেটা অটুট রাখা হয়েছে) নতুন ৫টি যোগ:

| id | placement | টাইটেল | লিংক |
|---|---|---|---|
| 9 | hero | নতুন খেলনার সম্ভার | category 2 (ইলেকট্রনিক্স) |
| 10 | hero | বাচ্চাদের জন্য বিশেষ ছাড় | category 1 (পোশাক) |
| 11 | hero | ফ্রি ডেলিভারি অফার | /shop.php |
| 12 | side | রিমোট কন্ট্রোল কার | product 91 |
| 13 | side | কিডস স্মার্ট ওয়াচ | product 95 |

ছবি picsum.photos থেকে এনে `estore_process_image()` দিয়ে **hero 1200×675 (16:9)** ও **side 600×375 (16:10)** বানানো।

### টেক্সট ওভারলে (আগে ছিল না)
DB-তে `title`/`subtitle` থাকলেও হিরোতে কখনো রেন্ডার হতো না — শুধু ছবি + বাটন।
- `index.php` — `.hero-scrim` (নিচমুখী গ্রেডিয়েন্ট), `.hero-copy` (title + subtitle), `.hero-body` (টেক্সট উপরে, বাটন নিচে)। title/subtitle খালি হলে আগের আচরণেই থাকে।
- সাইড কার্ডেও একই টেক্সট ওভারলে।
- `components.css` — `.hero-copy-title` `clamp(1.05rem, 2.1vw, 1.65rem)`, সাদা রঙ + text-shadow, `:has(.hero-copy)` দিয়ে flex layout, মোবাইলে ছোট ফন্ট ও গাঢ় স্ক্রিম।

### টুল
- `tools/seed_sliders.php [--dry-run]` — পুরোনো deactivate + নতুন slider সিড (idempotent, ছবি ৪০% হলে default-এ ফ্যালব্যাক)।
- `tools/verify_hero.js` — ৩ প্রস্থ × ১৫ চেক = **৪৪ চেক**: স্লাইড সংখ্যা, টাইটেল/সাবটাইটেল/বাটন দৃশ্যমানতা, স্ক্রিম, সাদা টেক্সট, stacking ক্রম, ছবি cover, অনুপাত, সাইড কার্ড, overflow।

### যাচাই
- `verify_hero` **44/44**, `verify_see_all` 54/54, `audit_gutters` 42/42, `bottom_nav` 20/20, `check_hero` অনুপাত 1.78/1.60 সঠিক
- `security_audit` 0, `test_csrf` 18/18
- `index.php`, `shop.php`, `cart.php`, `product_details.php` → clean
- CSS braces 489/489

## 2026-11-05 — "সব দেখুন" বাটন রিডিজাইন + Section padding ফিক্স

### "সব দেখুন" বাটন
- `.section-see-all` — গ্রেডিয়েন্ট পিল বাটন (brand-blue → sky), hover-এ solid gradient + elevation, arrow `translateX(4px)` স্লাইড, `:focus-visible` আউটলাইন, `prefers-reduced-motion` respect।
- `index.php` — 아이কনে `aria-hidden`, স্ক্রিন-রিডারের জন্য `sr-only` কনটেক্সট টেক্সট।
- উচ্চতা ৩৬–৩৮px (টাচ টার্গেট), ১৪৪০/১০২৪/৩৯০ — তিনটিতেই viewport-এ।

### Payment / Info section padding ফিক্স
**সমস্যা:** `main.wrap .payment-service-section, .info-bar-section { padding-left/right: 0 }` — বাইরের gutter ঠিক করতে গিয়ে **ভেতরের padding-ও মুছে গিয়েছিল**, তাই আইকন ও পেমেন্ট লোগো সেকশনের কিনারায় মাত্র **১px** দূরে লেগে ছিল। ডেস্কটপে সংকুচিত দেখাচ্ছিল।

**ফিক্স:**
- `info-bar-section` ও `payment-service-section`-কে আলাদা রুলে সরিয়ে ভেতরে `padding-left/right: 24px` দেওয়া (কার্ডের নিজের ভেতরের ফাঁকা)।
- Vertical padding `20px → 22px`, margin `20px → 26px` (ডেস্কটপে শ্বাস নেওয়ার জায়গা)।
- মোবাইল override `padding-top/bottom: 14px` → `padding: 16px 16px`।

**পরিমাপ (1440px):** ভেতরের বাম/ডান ফাঁকা **১px → ২৫px**, উপরে/নিচে **22px**। ১০২৪ ও ৩৯০-এও একই।

### নতুন টেস্ট
- `tools/verify_see_all.js` — ৩ প্রস্থ × ১৮ চেক = **৫৪ চেক**: বাটন উপস্থিতি, icon, pill radius, উচ্চতা, viewport, ও দুই সেকশনের ভেতরের/বাইরের padding + horizontal overflow।
- `tools/measure_payment_section.js` — যেকোনো সেকশনের বাস্তব box/padding/ancestor chain মাপার ডায়াগনস্টিক (reveal animation settle হওয়া পর্যন্ত অপেক্ষা করে)।

### যাচাই
- `verify_see_all.js` → 54/54, `audit_gutters` → 42/42, `bottom_nav` → 20/20, `check_info_align` → 28/28
- `security_audit` → 0, `test_csrf` → 18/18
- CSS braces 472/472

## 2026-11-05 — হোমপেজে "সব দেখুন" লিংক + Featured ফিল্টার

### সমস্যা
Featured Products ও New Arrivals সেকশনে `LIMIT 18` — ১৮-এর বেশি প্রোডাক্ট থাকলে বাকিগুলো হোমপেজে অদৃশ্য, আর "আরও দেখুন" লিংকও ছিল না। `new_arrival` ইতিমধ্যে ২১টি ছিল, তাই ৩টি প্রোডাক্ট (id 1, 2, 3) কোথাও দেখা যাচ্ছিল না।

### সমাধান
- `index.php` — দুই সেকশনের শিরোনামের নিচে `.section-see-all` বাটন:
  - Featured → `shop.php?filter=featured`
  - New Arrivals → `shop.php?sort=new`
- `shop.php` — নতুন `filter=featured` কুয়েরি প্যারামিটার (`WHERE p.is_featured = 1`), শিরোনাম "Featured Products", ফর্মে hidden input ও pagination লিংকে `filter` সংরক্ষিত।
- `assets/css/components.css` — `.section-see-all` পিল-স্টাইল বাটন (brand-blue, hover elevation)।

### যাচাই
- `filter=featured` → ৭টি (featureded=৭), শিরোনাম ও কাউন্ট সঠিক
- Featured ২৪টি বানিয়ে পরীক্ষা → page 1-এ ২০টি + pagination, page 2-এ ৪টি; তারপর ডেটা ফেরত
- `filter+sort+cat` একসাথে → ফিল্টার state ধরে রাখে (hidden input)
- `security_audit` 0, `test_csrf` 18/18, `test_cart_wishlist` ALL PASS, `audit_gutters` 42/42, `bottom_nav` 20/20
- CSS braces 464/464

## 2026-11-05 — P2 বাগ ও লজিক ফিক্স

### HIGH — কার্ট থেকে পণ্য মুছত না
- `cart.php`-এর `cartRemove()` JS-এ CSRF টোকেন পাঠানো হতো না → `cart_remove.php` সবসময় `403` দিত, পেজ রিলোড হতো কিন্তু আইটেম থেকে যেত। এখন `<meta name="csrf-token">` থেকে টোকেন পাঠায় এবং 403 হলে বাংলা টোস্ট দেখায়।
- `product.php`-এর পুরোনো ইনলাইন `addToCart()`-ও CSRF ছাড়া `cart_add.php` কল করত → সবসময় ব্যর্থ। টোকেন যোগ করা হয়েছে।

### HIGH — `next[]=x` দিলে ফ্যাটাল এরর + full path leak
- `cart_update.php:58` — `parse_url(array)` → `TypeError` + server path।
- `cart_update.php:7`, `cart_remove.php:30`, `account.php:13`, `admin/login.php:54` — `(string)$array` → `Array to string conversion` warning।
- `includes/config.php` / `live.config.php` — `h()` এখন non-scalar ইনপুটে খালি স্ট্রিং দেয় (আগে `htmlspecialchars(array)` ফ্যাটাল করত)। `admin/login.php?next[]=x` এই ফাঁক দিয়ে path leak করছিল।

### MEDIUM — Wishlist থেকে সরানোর কোনো UI ছিল না
- `wishlist.php`-এর AJAX remove handler বানানো ছিল কিন্তু পেজে কোনো বাটন/JS ছিল না → ইউজার কিছু সরাতে পারত না।
- `wishlist.php`-এ `removeFromWishlist()` JS + `partials_product_card.php`-এ `.wl-remove-btn` বাটন (`$cardRemoveWishlist` ফ্ল্যাগ দিয়ে শুধু wishlist পেজে), `components.css`-এ স্টাইল।
- AJAX remove এখন proper JSON দেয় (`401` login ছাড়া, `403` ভুল টোকেন, `ok` + নতুন count সফল হলে), আগে সব ক্ষেত্রেই redirect করত।

### MEDIUM — অর্ডারে `customer_id` সেভ হতো না
- `place_order.php` — লগইন করা কাস্টমারের অর্ডারেও `customer_id` NULL যেত, ফলে dashboard match হতো শুধু মোবাইল দিয়ে। এখন সেশন থেকে যাচাই করে `customer_id` লেখা হয়।

### MEDIUM — প্রতি নতুন ভিজিটরে ২টি PHPSESSID কুকি
- `includes/config.php` + `live.config.php` — খালি সেশনে `session_regenerate_id()` ডাকা হতো, তাই প্রথম রেসপন্সে দুইটি `Set-Cookie` যেত। এখন fresh session-এ শুধু টাইমস্ট্যাম্প সেট হয়, rotation হয় ৩০ মিনিট পর।

### LOW
- `cart_update.php:12` — ভুল/বিভ্রান্তিকর কমেন্ট সরানো।
- AJAX ডিটেকশন: `cart_remove.php`/`wishlist.php` এখন `X-Requested-With: fetch`-ও চেনে (আগে শুধু `XMLHttpRequest`)।

### নতুন টেস্ট
- `tools/test_stock_flow.php` — ১০ চেক: order → cancel → re-activate → delivered ট্রানজিশনে স্টক ও sold হিসাব, over-sell ব্লক, rollback যাচাই।
- `tools/test_cart_wishlist.js` — ৯ চেক: কার্টে যোগ/মুছে ফেলা (CSRF সহ ও ছাড়া), wishlist 401, `next[]` ফ্যাটাল।
- `tools/test_wishlist_ui.php` — ৭ চেক: wishlist remove মার্কআপ, JS, CSS; টেস্ট ডেটা নিজে পরিষ্কার করে।

### যাচাই
- `security_audit.js` → Total 0 (C:0 H:0 M:0 L:0)
- `test_csrf.js` → 18/18, `test_cart_wishlist.js` → 9/9, `test_stock_flow.php` → 10/10, `test_wishlist_ui.php` → 7/7
- `verify_bottom_nav.js` → 20/20, `audit_gutters.js` → 42/42
- `php -l` সব PHP → 0 error; CSS braces 461/461
- ১৭টি রুট → কোনো warning/fatal নেই

### মনোযোগ
- **শিপিং ফি এখনো `delivery_dhaka=0`, `delivery_nationwide=0`** — এটি DB সেটিং, কোড বাগ নয়। বাস্তব চার্জ বসাতে হবে Admin → Settings → ডেলিভারি চার্জ থেকে।

## 2026-11-04 — নিরাপত্তা হার্ডেনিং (P0: ১, ৩, ৫)

### ১. CSRF array-safe (HIGH ফিক্স)
- `csrf_verify()` এখন `is_string()` গার্ড দিয়ে array ইনপুট reject করে — আর `csrf[]=1` পাঠালে TypeError ফ্যাটাল + full server path leak হয় না।
- raw `hash_equals` CSRF চেক ৭ জায়গায় `csrf_verify()`-এ রূপান্তর: `admin/login.php`, `account.php`, `account_dashboard.php` (২), `review_submit.php`, `wishlist.php`, `admin/order_edit.php`, `admin/payments.php`।
- ৪টি admin guard-এর manual `isset/empty/hash_equals` ব্লক `csrf_verify()`-এ সরল করা: `settings_save.php`, `product_toggle.php`, `product_add_for_mobile.php`, `product_form.php` (৩)।
- `tools/test_csrf.js` — ১৩ → **১৮ চেক** (নতুন ৫টি array-CSRF/raw-endpoint গার্ড কভার করে)।

### ৩. Production error handling
- `APP_DEBUG` auto-detect (host + SAPI ভিত্তিক; `.test`/`.local`/loopback/private IP/CLI = debug)।
- লাইভে `display_errors=0`, `display_startup_errors=0`, `log_errors=1` → `logs/php_error.log`।
- ফ্যাটাল/পার্স এররে `register_shutdown_function` দিয়ে generic বাংলা 500 পেজ (পাথ/ট্রেস leak নেই)।
- `includes/config.php` ও `includes/live.config.php` দুইটাতেই একই ব্লক (সিঙ্ক রাখা হয়েছে)।
- `.htaccess` — `logs/` ডাইরেক্টরি ওয়েব থেকে ব্লক (`403`)।

### ৫. Dead code সরানো
- `admin/auth_functions.php` থেকে ডুপ্লিকেট `csrf_token()`, `csrf_field()`, `csrf_verify()` সরানো — `includes/config.php` আগেই লোড হয়, তাই ওই ফাংশনগুলো কখনো ব্যবহার হতো না।
- ওই ফাইলের insecure `csrf_verify()` (array-safe গার্ড ছাড়া) এখন আর কোডবেসে নেই।

### টুল
- `tools/security_audit.js` — DEBUG রুল নিখুঁত করা হয়েছে (conditional `APP_DEBUG ? ...` guard আর false positive দেয় না)।

### যাচাই
- `tools/security_audit.js` → **Total 0 (C:0 H:0 M:0 L:0)**
- `tools/test_csrf.js` → **ALL PASS (18)**
- `tools/verify_bottom_nav.js` → ALL PASS (20)
- `tools/audit_gutters.js` → 42/42 OK
- `php -l` সব PHP ফাইল → 0 error
- ১৭টি ফ্রন্টএন্ড/অ্যাডমিন রুট → কোনো warning/fatal নেই

## 2026-11-02 — প্রোজেক্ট শুরু (Phase 1-4)

### নতুন প্রোজেক্ট
- `C:\laragon\www\estore` — `toyShop` থেকে কোর কপি করে নতুন ই-কমার্স।
- আলাদা ডেটাবেস: **`estore`** (MariaDB, `127.0.0.1:3306`)। পুরনো `toysbd` অক্ষত।
- `PROJECT_PLAN.md` — বিস্তারিত রোডম্যাপ (mfeex.shop অ্যানালাইসিস সহ)।

### ডেটাবেস
- `toysbd` থেকে ১৩টি টেবিল + ডেটা কপি (products ১২, orders ৩২, categories, settings, payment_methods, admin_users, roles, permissions)।
- নতুন `includes/schema_v2.php` — স্বয়ংক্রিয় (idempotent) মাইগ্রেশন, `settings.schema_v2_at` দিয়ে একবারই চলে।
- **নতুন ৬টি টেবিল:**
  - `sliders` — হিরো/সাইড ব্যানার (`placement`, `link_type`, `theme`, `sort_order`, `active`)
  - `customers` — মোবাইল+পাসওয়ার্ড ভিত্তিক কাস্টমার অ্যাকাউন্ট
  - `product_reviews` — রেটিং + কমেন্ট + ছবি + মডারেশন স্ট্যাটাস
  - `wishlists` — কাস্টমার-প্রোডাক্ট ইউনিক পেয়ার
  - `pages` — About/Privacy/Terms/Contact (slug ভিত্তিক)
  - `subscribers` — নিউজলেটার
- **নতুন কলাম:** `products` (sku, sold_count, is_featured, badge_color), `categories` (image, sort_order, active), `payment_methods` (logo), `orders` (customer_id)।
- **নতুন পারমিশন:** `manage_sliders`, `manage_reviews`, `manage_customers`, `manage_pages` (Super Admin-কে দেওয়া হয়েছে)।
- ডিফল্ট `pages` (৪টি) ও নতুন settings কী (থিম কালার, ইনফো বার, সোশ্যাল) স্বয়ংক্রিয়ভাবে যোগ হয়।

### নিরাপত্তা ফিক্স (toyShop-এ যেগুলো বাকি ছিল)
- `admin/product_delete.php` — `gate('manage_products')` যোগ; CSRF চেক `isset()` বাইপাস থেকে `csrf_verify()`-এ পরিবর্তিত।
- `admin/product_delete.php` — অর্ডারে ব্যবহৃত প্রোডাক্ট hard-delete না করে **archive** (`active=0`), ফলে অর্ডার ইতিহাস অক্ষত।
- `admin/settings_save.php` — `gate('settings')`, `admin/product_toggle.php` — `gate('manage_products')`।
- **পারমিশন মিসম্যাচ ঠিক:** `manage_settings` → `settings` (settings.php, index.php), → `payments` (payments.php)। মেনুতেও সেটিংস আলাদা `can('settings')` হয়েছে।
- `admin/login.php` — CSRF টোকেন, `session_regenerate_id(true)`, `next` প্যারামিটারে open-redirect ব্লক।
- `admin/logout.php` — পুরো অ্যাডমিন সেশন ধ্বংস + কুকি ক্লিয়ার (কাস্টমার সেশন থাকলে অটুট)।
- `uploads/.htaccess` — PHP/CGI এক্সিকিউশন বন্ধ, polyglot ফাইল attachment হিসেবে সার্ভ, directory listing বন্ধ।
- `cart_remove.php` — POST প্রাধিকার, লোকাল `next` ভ্যালিডেশন, AJAX JSON রেসপন্স।

### অ্যাডমিন প্যানেল
- **`admin/sliders.php`** (নতুন) — লিস্ট, placement ফিল্টার, active টগল, সর্ট এডিট, ডিলিট (ইমেজ ফাইলসহ)।
- **`admin/slider_form.php`** (নতুন) — ইমেজ আপলোড (MIME + সাইজ যাচাই), placement, লিংক পিকার (product/category/url), বাটন লেবেল/রঙ, থিম।
- **`admin/reviews.php`** (নতুন) — স্ট্যাটাস ফিল্টার, অনুমোদন/বাতিল/ডিলিট, স্টার ভিজুয়াল।
- **`admin/customers.php`** (নতুন) — সার্চ, অর্ডার কাউন্ট, মোট খরচ, active/block টগল, ডিলিট (অর্ডার ইতিহাস অটুট)।
- **`admin/pages.php`** (নতুন) — স্ট্যাটিক পেজ এডিটর (৪টি), প্রিভিউ লিংক।
- `admin/header.php` — সাইডবারে ৪টি নতুন মেনু (স্লাইডার/রিভিউ/কাস্টমার/পেজ), সেটিংস আলাদা।
- `admin/auth_functions.php` — `$menu_map`-এ নতুন ৪টি পারমিশন।

### ফ্রন্টএন্ড — নতুন ডিজাইন (mfeex.shop ধাঁচে)
- **`assets/css/theme.css`** (নতুন) — ডিজাইন টোকেন (`--primary-purple #6c5ce7`, `--accent-orange #ff7675`), গ্লাস হেডার, সাইড ড্রয়ার, বটম নেভ, কার্ট পপআপ, টোস্ট, scroll reveal।
- **`assets/css/components.css`** (নতুন) — হিরো, ক্যাটেগরি রো, প্রোডাক্ট কার্ড, ইনফো বার, পেমেন্ট বার, প্রোডাক্ট ডিটেইল, রিভিউ, কার্ট, ফর্ম, ফুটার, পেজিনেশন।
- **`assets/js/home.js`** (নতুন) — Swiper init, ড্রয়ার, XSS-নিরাপদ লাইভ সার্চ (`textContent`), কার্ট পপআপ, IntersectionObserver (কিউ-ভিত্তিক ১১০ms স্ট্যাগার), `estoreToast()`।
- **`includes/partials_header.php`** — সম্পূর্ণ নতুন: গ্লাস হেডার (☰ + লোগো + সার্চ + কার্ট ব্যাজ), সাইড ড্রয়ার (মেনু + ক্যাটেগরি), কার্ট পপআপ, টোস্ট স্ট্যাক। settings এখন **এক কুয়েরিতে**।
- **`includes/partials_footer.php`** — গ্লাস ফুটার (ব্র্যান্ড/লিংক/যোগাযোগ), মোবাইল বটম নেভ। ৭টি আলাদা কুয়েরি → **এক কুয়েরি**।
- **`includes/partials_product_card.php`** (নতুন) — একটাই কার্ড, ৫ জায়গায় ব্যবহৃত; ৮ রঙের ডিসকাউন্ট ব্যাজ, stock-out ব্যাজ, dynamic Delivered কাউন্ট।
- **`index.php`** — সম্পূর্ণ নতুন: Swiper হিরো + ২ সাইড কার্ড (মোবাইলে hidden), ক্যাটেগরি রো (৩/৮ কলাম), Featured + New Arrivals সেকশন, ইনফো বার, পেমেন্ট বার। স্লাইডার না থাকলে সুন্দর fallback।
- **`shop.php`** (নতুন) — সার্চ, ক্যাটেগরি, সর্ট (নতুন/দাম/ছাড়), পেজিনেশন।
- **`category_products.php`** (নতুন) — ক্যাটেগরি প্রোডাক্ট + Pagination।
- **`deals.php`** (নতুন) — সর্বোচ্চ ছাড়ের পণ্য, ডিসকাউন্ট % অনুযায়ী সর্ট।
- **`product_details.php`** (নতুন) — গ্যালারি + থাম্বনেইল, ভিডিও (file/YouTube), SKU, স্টক ব্যাজ, রেটিং, Sold, ট্যাব (বিবরণ/স্পেক/রিভিউ), রিভিউ ফর্ম (স্টার ইনপুট + ছবি), উইশলিস্ট বাটন, রিলেটেড।
- **`account.php`** (নতুন) — কাস্টমার লগইন/রেজিস্ট্রেশন (মোবাইল+পাসওয়ার্ড), ট্যাব সুইচ UI।
- **`account_dashboard.php`** (নতুন) — অর্ডার হিস্টোরি (স্ট্যাটাস ব্যাজ), উইশলিস্ট কাউন্ট, প্রোফাইল এডিট, লগআউট।
- **`wishlist.php`** (নতুন) + **`wishlist_toggle.php`** — উইশলিস্ট লিস্ট ও AJAX টগল (লগইন ছাড়া `login_required`)।
- **`review_submit.php`** (নতুন) — শুধু ক্রেতা রিভিউ দিতে পারবে, একই পণ্যে একবার, ছবি আপলোড (2MB, MIME যাচাই), মডারেশনে `pending`।
- **`about.php` / `privacy_policy.php` / `terms.php` / `contact.php`** (নতুন) — `pages` টেবিল থেকে কনটেন্ট, `includes/render_page.php` দিয়ে HTML স্যানিটাইজ (script/on* বাদ)।
- **`includes/customer_auth.php`** (নতুন) — `current_customer()`, `require_customer()`, `normalize_mobile()`।
- **`cart.php`** — নতুন থিম (কার্ট কার্ড, ক্লায়েন্ট-সাইড রিক্যাল্ক), **স্টক ক্ল্যাম্প ও হিন্ট অপরিবর্তিত**।
- **`checkout.php`** — নতুন থিম (সেগমেন্টেড শিপিং/পেমেন্ট কার্ড), মোবাইল নরমালাইজেশন, Free Shipping, স্টক ক্ল্যাম্প — **লজিক হুবহু অপরিবর্তিত**।
- **`success.php`** — নতুন থিম, পূর্ণ স্ট্যাটাস ব্যাজ সেট (green/red/blue/indigo/orange/gray), কনফেটি অটুট।

### মোবাইল-ফ্রেন্ডলি
- ব্রেকপয়েন্ট: প্রোডাক্ট গ্রিড ২/৩/৪/৫ · ক্যাটেগরি ৩/৮ · হিরো ১ কলাম → `2fr 1fr`।
- মোবাইলে সাইড কার্ড ও ডেস্কটপ-ফুটার গ্লো বন্ধ; বটম নেভ শুধু <768px।
- `100dvh` ড্রয়ার, `env(safe-area-inset-*)`, ইনপুট `font-size:16px` (iOS zoom রোধ), টাচ টার্গেট ≥৪০px।
- `prefers-reduced-motion` — সব অ্যানিমেশন বন্ধ।
- `loading="lazy"` + `decoding="async"` + `width`/`height` (CLS কমাতে)।

### যাচাই
- সব নতুন/পরিবর্তিত PHP ফাইল `php -l` — কোনো syntax error নেই।
- ১২টি ফ্রন্টএন্ড পেজ ও ৯টি অ্যাডমিন পেজ HTTP 200, কোনো `Fatal error`/`Parse error`/`Warning` নেই।
- লগইন CSRF কাজ করছে (টোকেন ছাড়া লগইন হয় না), নতুন অ্যাডমিন পেজ লগইন ছাড়া রিডাইরেক্ট করে।
- Stock/inventory লজিক (৩৯ + ২২ + ১১ = ৭২ টেস্ট) স্পর্শ করা হয়নি — মূল কোড হুবহু কপি।

### বাকি (পরবর্তী ধাপ)
- স্লাইডার/ক্যাটেগরি ইমেজ/পেমেন্ট লোগো আপলোড করে কনটেন্ট দেওয়া (অ্যাডমিনে ফিচার তৈরি)।
- `admin/settings.php`-এ থিম কালার, ইনফো বার, হিরো অটোপ্লে, সোশ্যাল লিংকের UI সেকশন।
- পুরনো ৭২টি টেস্ট estore ডেটাবেসে চালিয়ে রিগ্রেশন যাচাই।
- রেসপন্সিভ টেস্ট (320/375/414/768/1024/1440)।
- `products_more.php` অব্যবহৃত — অপসারণ বা `shop.php`-এ মিলিয়ে দেওয়া।
