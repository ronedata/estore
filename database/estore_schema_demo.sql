-- eStore: কাঠামো (সব ২৪টি টেবিল) + ডেমো ডেটা (পণ্য, ক্যাটেগরি, স্লাইডার, পেজ, হোম সেকশন, সেটিংস, জেলা-ভিত্তিক ডেলিভারি চার্জ, রোল/পারমিশন)।
-- গ্রাহক, অর্ডার, মেসেজ, রিভিউ, অ্যাডমিন ইউজার, page_views, store_phone/store_email বাদ — ব্যক্তিগত তথ্য নেই।
-- ইমপোর্ট: খালি ডাটাবেসে। সতর্কতা: DROP TABLE IF EXISTS আছে, চালু ডাটাবেসে চালাবেন না।
-- অ্যাডমিন ইউজার নেই: admin_users-এ নিজে একটি সারি যোগ করুন (password_hash() দিয়ে), role Super Admin।
-- নতুন মাইগ্রেশন (v8 ঘোষণা, v9 ডেলিভারি, v10 ডিসকাউন্ট) এই ডাম্পেই অন্তর্ভুক্ত; ইমপোর্টের পর এগুলো আবার চলে না।
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `admin_users_role_id_fk` (`role_id`),
  CONSTRAINT `admin_users_role_id_fk` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(10) NOT NULL DEFAULT 'bar',
  `title` varchar(160) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `btn_label` varchar(60) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `color` varchar(10) NOT NULL DEFAULT 'blue',
  `closable` tinyint(1) NOT NULL DEFAULT 1,
  `delay_sec` int(11) NOT NULL DEFAULT 3,
  `reshow` varchar(10) NOT NULL DEFAULT 'days',
  `reshow_days` int(11) NOT NULL DEFAULT 7,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kind_active` (`kind`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `image` varchar(400) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `categories` VALUES (1,'পোশাক','uploads/categories/cat_20261102125716_80f488ac.png',3,1),(2,'ইলেকট্রনিক্স','uploads/categories/cat_20261102125707_e44bd21d.png',2,1),(3,'অ্যাক্সেসরিজ','uploads/categories/cat_20261102125656_e973f18a.png',1,1);
DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_read` (`is_read`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mobile` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `delivery_districts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_districts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `division` varchar(40) NOT NULL,
  `name_bn` varchar(60) NOT NULL,
  `name_en` varchar(60) NOT NULL,
  `fee` decimal(10,2) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name_en` (`name_en`),
  KEY `idx_division` (`division`)
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `delivery_districts` VALUES (1,'ঢাকা','ঢাকা','Dhaka',60.00,1),(2,'ঢাকা','ফরিদপুর','Faridpur',NULL,2),(3,'ঢাকা','গাজীপুর','Gazipur',NULL,3),(4,'ঢাকা','গোপালগঞ্জ','Gopalganj',NULL,4),(5,'ঢাকা','কিশোরগঞ্জ','Kishoreganj',NULL,5),(6,'ঢাকা','মাদারীপুর','Madaripur',NULL,6),(7,'ঢাকা','মানিকগঞ্জ','Manikganj',NULL,7),(8,'ঢাকা','মুন্সিগঞ্জ','Munshiganj',NULL,8),(9,'ঢাকা','নারায়ণগঞ্জ','Narayanganj',NULL,9),(10,'ঢাকা','নরসিংদী','Narsingdi',NULL,10),(11,'ঢাকা','রাজবাড়ী','Rajbari',NULL,11),(12,'ঢাকা','শরীয়তপুর','Shariatpur',NULL,12),(13,'ঢাকা','টাঙ্গাইল','Tangail',NULL,13),(14,'চট্টগ্রাম','বান্দরবান','Bandarban',NULL,14),(15,'চট্টগ্রাম','ব্রাহ্মণবাড়িয়া','Brahmanbaria',NULL,15),(16,'চট্টগ্রাম','চাঁদপুর','Chandpur',NULL,16),(17,'চট্টগ্রাম','চট্টগ্রাম','Chattogram',NULL,17),(18,'চট্টগ্রাম','কুমিল্লা','Cumilla',NULL,18),(19,'চট্টগ্রাম','কক্সবাজার','Cox\'s Bazar',NULL,19),(20,'চট্টগ্রাম','ফেনী','Feni',NULL,20),(21,'চট্টগ্রাম','খাগড়াছড়ি','Khagrachhari',NULL,21),(22,'চট্টগ্রাম','লক্ষ্মীপুর','Lakshmipur',NULL,22),(23,'চট্টগ্রাম','নোয়াখালী','Noakhali',NULL,23),(24,'চট্টগ্রাম','রাঙ্গামাটি','Rangamati',NULL,24),(25,'রাজশাহী','বগুড়া','Bogura',NULL,25),(26,'রাজশাহী','জয়পুরহাট','Joypurhat',NULL,26),(27,'রাজশাহী','নওগাঁ','Naogaon',NULL,27),(28,'রাজশাহী','নাটোর','Natore',NULL,28),(29,'রাজশাহী','চাঁপাইনবাবগঞ্জ','Chapainawabganj',NULL,29),(30,'রাজশাহী','পাবনা','Pabna',NULL,30),(31,'রাজশাহী','রাজশাহী','Rajshahi',NULL,31),(32,'রাজশাহী','সিরাজগঞ্জ','Sirajganj',NULL,32),(33,'খুলনা','বাগেরহাট','Bagerhat',NULL,33),(34,'খুলনা','চুয়াডাঙ্গা','Chuadanga',NULL,34),(35,'খুলনা','যশোর','Jashore',NULL,35),(36,'খুলনা','ঝিনাইদহ','Jhenaidah',NULL,36),(37,'খুলনা','খুলনা','Khulna',NULL,37),(38,'খুলনা','কুষ্টিয়া','Kushtia',NULL,38),(39,'খুলনা','মাগুরা','Magura',NULL,39),(40,'খুলনা','মেহেরপুর','Meherpur',NULL,40),(41,'খুলনা','নড়াইল','Narail',NULL,41),(42,'খুলনা','সাতক্ষীরা','Satkhira',NULL,42),(43,'বরিশাল','বরগুনা','Barguna',NULL,43),(44,'বরিশাল','বরিশাল','Barishal',NULL,44),(45,'বরিশাল','ভোলা','Bhola',NULL,45),(46,'বরিশাল','ঝালকাঠি','Jhalokathi',NULL,46),(47,'বরিশাল','পটুয়াখালী','Patuakhali',NULL,47),(48,'বরিশাল','পিরোজপুর','Pirojpur',NULL,48),(49,'সিলেট','হবিগঞ্জ','Habiganj',NULL,49),(50,'সিলেট','মৌলভীবাজার','Moulvibazar',NULL,50),(51,'সিলেট','সুনামগঞ্জ','Sunamganj',NULL,51),(52,'সিলেট','সিলেট','Sylhet',NULL,52),(53,'রংপুর','দিনাজপুর','Dinajpur',NULL,53),(54,'রংপুর','গাইবান্ধা','Gaibandha',NULL,54),(55,'রংপুর','কুড়িগ্রাম','Kurigram',NULL,55),(56,'রংপুর','লালমনিরহাট','Lalmonirhat',NULL,56),(57,'রংপুর','নীলফামারী','Nilphamari',NULL,57),(58,'রংপুর','পঞ্চগড়','Panchagarh',NULL,58),(59,'রংপুর','রংপুর','Rangpur',NULL,59),(60,'রংপুর','ঠাকুরগাঁও','Thakurgaon',NULL,60),(61,'ময়মনসিংহ','জামালপুর','Jamalpur',NULL,61),(62,'ময়মনসিংহ','ময়মনসিংহ','Mymensingh',NULL,62),(63,'ময়মনসিংহ','নেত্রকোণা','Netrokona',NULL,63),(64,'ময়মনসিংহ','শেরপুর','Sherpur',NULL,64);
DROP TABLE IF EXISTS `home_section_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `home_section_products` (
  `section_id` int(10) unsigned NOT NULL,
  `product_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`section_id`,`product_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `home_section_products` VALUES (1,87,0),(1,88,0),(1,90,0),(1,91,0),(1,92,0),(1,95,0),(1,97,0),(2,1,0),(2,2,0),(2,3,0),(2,4,0),(2,5,0),(2,10,0),(2,11,0),(2,13,0),(2,14,0),(2,22,0),(2,55,0),(2,87,0),(2,88,0),(2,89,0),(2,91,0),(2,92,0),(2,93,0),(2,94,0),(2,95,0),(2,96,0),(2,98,0),(59,10,0),(59,98,0);
DROP TABLE IF EXISTS `home_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `home_sections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'manual',
  `category_id` int(11) DEFAULT NULL,
  `product_limit` int(11) NOT NULL DEFAULT 12,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `show_see_all` tinyint(1) NOT NULL DEFAULT 1,
  `new_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active_sort` (`active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `home_sections` VALUES (1,'Featured Products','আপনার জন্য বাছাই করা সেরা পণ্য','manual',NULL,8,2,1,1,0,'2026-11-05 11:08:05'),(2,'New Arrivals','সদ্য যোগ হওয়া নতুন সংগ্রহ','manual',NULL,8,1,1,1,1,'2026-11-05 11:08:05'),(59,'E.T.C','সাবটাইটেল (ঐচ্ছিক)','manual',NULL,12,3,1,1,0,'2026-11-05 11:17:08');
DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_code` varchar(20) DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `mobile` varchar(30) NOT NULL,
  `address` text NOT NULL,
  `shipping_area` enum('dhaka','nationwide') NOT NULL DEFAULT 'dhaka',
  `shipping_district` varchar(60) DEFAULT NULL,
  `shipping_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_note` varchar(160) DEFAULT NULL,
  `discount_by` varchar(60) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) NOT NULL DEFAULT 'COD',
  `note` text DEFAULT NULL,
  `status` enum('pending','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `customer_id` int(11) DEFAULT NULL,
  `stock_deducted` tinyint(1) NOT NULL DEFAULT 0,
  `stock_returned` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_code` (`order_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `page_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `page_views` (
  `page` varchar(100) NOT NULL,
  `views` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`page`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` longtext DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `pages` VALUES (1,'about','আমাদের সম্পর্কে জানুন','<p class=\"pg-text\"><strong>{STORE}</strong> একটি আধুনিক ও কাস্টমার-বান্ধব ই-কমার্স প্ল্যাটফর্ম। আমাদের মূল লক্ষ্য হলো ডিজিটাল বাংলাদেশে সাধারণ মানুষের কাছে শতভাগ অরিজিনাল ও মানসম্মত পণ্য দ্রুততম সময়ে পৌঁছে দেওয়া।</p>\n<p class=\"pg-text\">আমরা বিশ্বাস করি কেনাকাটা কেবল একটি প্রয়োজন নয়, এটি একটি অভিজ্ঞতা। তাই সহজ পেমেন্ট সুবিধা, দ্রুত ডেলিভারি এবং সার্বক্ষণিক কাস্টমার সাপোর্ট নিশ্চিত করে আমরা আপনাকে দিচ্ছি ঝামেলাহীন শপিংয়ের প্রতিশ্রুতি।</p>\n<hr class=\"pg-divider\">\n<h2 class=\"pg-title\">কেন আমাদের নির্বাচন করবেন?</h2>\n<div class=\"pg-features\">\n  <div class=\"pg-feature\">\n    <i class=\"fa-solid fa-gem\"></i>\n    <h4>১০০% অরিজিনাল পণ্য</h4>\n    <p>আমরা সরাসরি সঠিক উৎস থেকে মানসম্মত ও প্রিমিয়াম প্রোডাক্ট সংগ্রহ করে থাকি।</p>\n  </div>\n  <div class=\"pg-feature\">\n    <i class=\"fa-solid fa-truck-fast\"></i>\n    <h4>দ্রুত ডেলিভারি</h4>\n    <p>আপনার কাঙ্ক্ষিত পণ্য নিরাপদ ও দ্রুততম সময়ে ডেলিভারি দেওয়ার সর্বোচ্চ চেষ্টা করি।</p>\n  </div>\n  <div class=\"pg-feature\">\n    <i class=\"fa-solid fa-shield-halved\"></i>\n    <h4>নিরাপদ পেমেন্ট</h4>\n    <p>ক্যাশ অন ডেলিভারি এবং ১০০% নিরাপদ অনলাইন পেমেন্ট মেথড সুবিধা।</p>\n  </div>\n  <div class=\"pg-feature\">\n    <i class=\"fa-solid fa-headset\"></i>\n    <h4>২৪/৭ সাপোর্ট</h4>\n    <p>যেকোনো জিজ্ঞাসা বা প্রয়োজনে আমাদের টিম সবসময় আপনাকে সহায়তায় প্রস্তুত।</p>\n  </div>\n</div>\n<hr class=\"pg-divider\">\n<h2 class=\"pg-title\">আমাদের লক্ষ্য ও উদ্দেশ্য</h2>\n<p class=\"pg-text\">আমাদের উদ্দেশ্য হলো গ্রাহকদের সঠিক দামে সেরা পণ্য প্রদান করা এবং প্রতিটি কেনাকাটায় সর্বোচ্চ সন্তুষ্টি নিশ্চিত করা। ডিজিটাল প্রযুক্তি ব্যবহার করে কেনাকাটা করার প্রক্রিয়াকে আরও সহজ, নিরাপদ ও দ্রুত করা আমাদের অন্যতম অঙ্গীকার।</p>\n<div class=\"pg-stats\">\n  <div class=\"pg-stat\"><h2>১০০%</h2><p>গ্রাহক সন্তুষ্টি</p></div>\n  <div class=\"pg-stat\"><h2>২৪/৭</h2><p>কাস্টমার কেয়ার</p></div>\n  <div class=\"pg-stat\"><h2>দ্রুত</h2><p>হোম ডেলিভারি</p></div>\n</div>','{STORE} — নির্ভরযোগ্য ও সহজ অনলাইন কেনাকাটার বিশ্বস্ত মাধ্যম',1,'2026-11-02 09:43:36'),(2,'privacy','গোপনীয়তা নীতি (Privacy Policy)','<p class=\"pg-text\"><strong>{STORE}</strong>-এ আপনার গোপনীয়তা রক্ষা করা আমাদের জন্য অত্যন্ত গুরুত্বপূর্ণ। আপনি যখন আমাদের ওয়েবসাইট ব্যবহার করেন এবং কেনাকাটা করেন, তখন আপনার শেয়ার করা ব্যক্তিগত তথ্য আমরা কীভাবে সংগ্রহ, ব্যবহার এবং সুরক্ষিত রাখি—তাহার বিস্তারিত ব্যাখ্যা এই প্রাইভেসি পলিসিতে দেওয়া হয়েছে।</p>\n<hr class=\"pg-divider\">\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-user-check\"></i>১. আমরা কী কী তথ্য সংগ্রহ করি?</h2>\n<p class=\"pg-text\">অর্ডার প্রসেসিং ও সেবা প্রদানের জন্য আমরা গ্রাহকদের কাছ থেকে নিম্নোক্ত তথ্যগুলো সংগ্রহ করে থাকি:</p>\n<ul class=\"pg-list\">\n  <li>আপনার নাম এবং যোগাযোগের তথ্য (মোবাইল নম্বর, ইমেইল এড্রেস)।</li>\n  <li>পণ্য ডেলিভারির সম্পূর্ণ ঠিকানা।</li>\n  <li>অর্ডার ও কেনাকাটার ইতিহাস।</li>\n  <li>পেমেন্ট সংক্রান্ত তথ্য (ক্যাশ অন ডেলিভারি বা মোবাইল ব্যাংকিং ট্রানজেকশন আইডি)।</li>\n</ul>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-gears\"></i>২. তথ্যের ব্যবহার</h2>\n<p class=\"pg-text\">সংগৃহীত তথ্যগুলো আমরা শুধুমাত্র নিম্নলিখিত কাজে ব্যবহার করে থাকি:</p>\n<ul class=\"pg-list\">\n  <li>আপনার কাঙ্ক্ষিত অর্ডার প্রসেসিং ও সময়মতো ডেলিভারি নিশ্চিত করতে।</li>\n  <li>অর্ডার সম্পর্কিত যেকোনো আপডেট বা মেসেজ পাঠাতে।</li>\n  <li>কাস্টমার সাপোর্ট প্রদান ও সমস্যার দ্রুত সমাধান করতে।</li>\n  <li>আমাদের ওয়েবসাইট ও সেবার মান উন্নত করতে।</li>\n</ul>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-shield-halved\"></i>৩. তথ্যের নিরাপত্তা</h2>\n<p class=\"pg-text\">আপনার ব্যক্তিগত তথ্যের সর্বোচ্চ নিরাপত্তা বজায় রাখতে আমরা প্রয়োজনীয় প্রযুক্তিগত ও প্রশাসনিক পদক্ষেপ গ্রহণ করি। আপনার অনুমতি ছাড়া আমরা কোনো অবস্থাতেই কোনো তৃতীয় পক্ষ (Third Party) বা বাণিজ্যিক প্রতিষ্ঠানের কাছে আপনার ব্যক্তিগত তথ্য বিক্রি, ভাড়া বা শেয়ার করি না।</p>\n<p class=\"pg-text\"><em>নোট: কেবল অর্ডার ডেলিভারির জন্য প্রয়োজনীয় তথ্য (যেমন: নাম, ঠিকানা, মোবাইল নম্বর) আমাদের বিশ্বস্ত কুরিয়ার সার্ভিস পার্টনারের সাথে শেয়ার করা হয়ে থাকে।</em></p>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-cookie-bite\"></i>৪. কুকিজ (Cookies) নীতি</h2>\n<p class=\"pg-text\">আমাদের ওয়েবসাইটে আপনার ব্রাউজিং অভিজ্ঞতা আরও সহজ ও দ্রুত করতে আমরা কুকিজ ব্যবহার করতে পারি। এটি আপনার ব্রাউজারে একটি ছোট ফাইল হিসেবে জমা থাকে, যা পরবর্তীতে আপনাকে দ্রুত লগইন করতে বা কার্টে রাখা পণ্যগুলো মনে রাখতে সাহায্য করে। আপনি চাইলে আপনার ব্রাউজার সেটিংস থেকে কুকিজ নিষ্ক্রিয় করে রাখতে পারেন।</p>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-pen-to-square\"></i>৫. নীতিমালার পরিবর্তন</h2>\n<p class=\"pg-text\"><strong>{STORE}</strong> যেকোনো সময় এই প্রাইভেসি পলিসিতে সংশোধন বা পরিবর্তন আনার অধিকার রাখে। যেকোনো পরিবর্তন বা আপডেট এই পেজে প্রকাশ পাওয়ার সাথে সাথে কার্যকর হবে।</p>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-envelope\"></i>৬. যোগাযোগ</h2>\n<p class=\"pg-text\">আমাদের প্রাইভেসি পলিসি বা আপনার তথ্য সম্পর্কিত যেকোনো প্রশ্ন থাকলে আমাদের কন্টাক্ট পেজ থেকে বা সরাসরি কাস্টমার সাপোর্টে যোগাযোগ করতে পারেন।</p>','{STORE} — আপনার তথ্যের সুরক্ষা আমাদের প্রথম অগ্রাধিকার',1,'2026-11-02 09:43:36'),(3,'terms','শর্তাবলী (Terms & Conditions)','<p class=\"pg-text\"><strong>{STORE}</strong> ওয়েবসাইট ব্যবহারের জন্য আপনাকে স্বাগতম। আপনি যদি আমাদের ওয়েবসাইট ব্যবহার করেন বা কোনো পণ্য অর্ডার করেন, তবে ধরে নেওয়া হবে যে আপনি নিম্নে উল্লিখিত নিয়ম ও শর্তাবলী মেনে নিয়েছেন। অনুগ্রহ করে কেনাকাটা করার পূর্বে নিয়মগুলো ভালো করে পড়ে নিন।</p>\n<hr class=\"pg-divider\">\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-cart-shopping\"></i>১. পণ্য ও অর্ডার সংক্রান্ত শর্তাবলী</h2>\n<ul class=\"pg-list\">\n  <li>ওয়েবসাইটে প্রদর্শিত সকল পণ্যের স্টক ও মূল্য যেকোনো সময় পরিবর্তন হতে পারে।</li>\n  <li>অর্ডার করার পর আমাদের প্রতিনিধি ফোন বা মেসেজের মাধ্যমে অর্ডারটি কনফার্ম করতে পারেন।</li>\n  <li>অনিবার্য কারণবশত বা স্টকে পণ্য না থাকলে <strong>{STORE}</strong> যেকোনো অর্ডার বাতিল করার অধিকার রাখে।</li>\n</ul>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-credit-card\"></i>২. মূল্য ও পেমেন্ট নীতি</h2>\n<ul class=\"pg-list\">\n  <li>আমরা ক্যাশ অন ডেলিভারি (COD) এবং অন্যান্য ডিজিটাল পেমেন্ট সুবিধা প্রদান করে থাকি।</li>\n  <li>পণ্য হাতে পাওয়ার পর কুরিয়ার ডেলিভারিম্যানকে নির্ধারিত সম্পূর্ণ অর্থ পরিশোধ করতে হবে।</li>\n  <li>যেকোনো পেমেন্ট সংক্রান্ত বিভ্রান্তি এড়াতে সঠিক ট্রানজেকশন তথ্য প্রদান করা বাধ্যতামূলক।</li>\n</ul>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-truck\"></i>৩. শিপিং ও ডেলিভারি</h2>\n<p class=\"pg-text\">আমরা থার্ড-পার্টি কুরিয়ার সার্ভিসের মাধ্যমে সারা বাংলাদেশে প্রোডাক্ট ডেলিভারি করে থাকি।</p>\n<ul class=\"pg-list\">\n  <li>সাধারণত ঢাকার ভেতরে ২–৩ দিন এবং ঢাকার বাইরে ৩–৫ কার্যদিবসের মধ্যে ডেলিভারি সম্পন্ন করা হয়।</li>\n  <li>প্রাকৃতিক দুর্যোগ, কুরিয়ার জনিত জটিলতা বা কোনো জরুরি পরিস্থিতিতে ডেলিভারি সময় কিছুটা বেশি লাগতে পারে।</li>\n</ul>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-rotate-left\"></i>৪. রিটার্ন ও রিফান্ড পলিসি</h2>\n<ul class=\"pg-list\">\n  <li>ডেলিভারিম্যানের সামনে প্রোডাক্ট চেক করে রিসিভ করার অনুরোধ করা হচ্ছে।</li>\n  <li>যদি ভাঙা, ক্ষতিগ্রস্ত বা ভুল প্রোডাক্ট পেয়ে থাকেন, তবে ডেলিভারিম্যান থাকা অবস্থাতেই আমাদের হেল্পলাইনে কল করে অবহিত করতে হবে।</li>\n  <li>পণ্য গ্রহণের পর কোনো সমস্যা পাওয়া গেলে ২৪ ঘণ্টার মধ্যে কাস্টমার কেয়ারে জানাতে হবে।</li>\n</ul>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-copyright\"></i>৫. বুদ্ধিবৃত্তিক সম্পত্তি (Intellectual Property)</h2>\n<p class=\"pg-text\">এই প্ল্যাটফর্মের সকল কনটেন্ট (যেমন: লেখা, লোগো, গ্রাফিক্স, ডিজাইন) <strong>{STORE}</strong>-এর নিজস্ব সম্পদ। অনুমতি ব্যতীত ওয়েবসাইট থেকে কোনো ছবি বা কনটেন্ট কপি বা বাণিজ্যিক উদ্দেশ্যে ব্যবহার করা সম্পূর্ণ নিষিদ্ধ।</p>\n<h2 class=\"pg-title\"><i class=\"fa-solid fa-pen-nib\"></i>৬. শর্তাবলী পরিবর্তন</h2>\n<p class=\"pg-text\"><strong>{STORE}</strong> যেকোনো সময় কোনো পূর্ব নোটিশ ছাড়াই এই শর্তাবলী পরিবর্তন বা আপডেট করার পূর্ণ অধিকার সংরক্ষণ করে।</p>','{STORE} — কেনাকাটা করার আগে নিয়মাবলী জেনে নিন',1,'2026-11-02 09:43:36'),(4,'contact','যোগাযোগ করুন','আমাদের সাথে যোগাযোগ করতে উপরের তথ্যগুলো ব্যবহার করুন অথবা পাশের ফর্মে মেসেজ পাঠান। আমাদের টিম যত দ্রুত সম্ভব আপনার সাথে যোগাযোগ করবে।','যেকোনো প্রশ্ন, তথ্য বা সহায়তার জন্য আমাদের সাথে সরাসরি যোগাযোগ করুন',1,'2026-11-02 09:48:00');
DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Unique identifier, e.g., COD, BKASH',
  `label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Display name, e.g., Cash on Delivery',
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 for enabled, 0 for disabled',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `logo` varchar(400) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `payment_methods` VALUES (1,'COD','Cash on Delivery','',1,0,'2025-10-25 11:00:08','2025-10-25 11:11:29',NULL),(2,'BKASH','bKash','Pay securely with your bKash account.',0,10,'2025-10-25 11:00:08','2025-10-25 11:18:54',NULL),(3,'NAGAD','Nagad','Pay instantly via Nagad mobile banking.',0,20,'2025-10-25 11:00:08','2025-10-25 11:00:08',NULL),(4,'AAMARPAY','AamarPay','Online payment through AamarPay gateway.',0,30,'2025-10-25 11:00:08','2025-10-25 11:00:08',NULL),(5,'SSLCOMMERZ','SSLCOMMERZ','Online payment through SSLCOMMERZ gateway.',0,40,'2025-10-25 11:00:08','2025-10-25 11:00:08',NULL);
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g., manage_products, view_orders',
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `permissions` VALUES (1,'view_dashboard','Can view the admin dashboard'),(2,'manage_products','Can add, edit, and delete products'),(3,'manage_products_quick','Can use the quick product add page'),(4,'manage_categories','Can manage product categories'),(5,'manage_orders','Can view and manage orders'),(6,'manage_admin_users','Can manage admin users and roles'),(7,'view_stock_report','Can view the stock report'),(8,'settings','Can change store settings'),(10,'payments','Can manage payments'),(11,'manage_sliders','Can manage hero sliders and side banners'),(12,'manage_reviews','Can moderate product reviews'),(13,'manage_customers','Can view and manage customers'),(14,'manage_pages','Can edit static pages (about, privacy, terms, contact)'),(15,'view_page_views','Can view page views report'),(19,'manage_home_sections','Can manage homepage sections (add, remove, reorder, product count)'),(22,'manage_announcements','Can manage announcement bar and popup'),(27,'manage_delivery','Can manage district-wise delivery charges');
DROP TABLE IF EXISTS `product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `image` varchar(400) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=168 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `product_images` VALUES (6,14,'/uploads/product_14_1755693827_0.jpg',0),(24,22,'/uploads/product_22_1756020718_0.jpg',0),(91,54,'/uploads/product_54_1756207179_img0.jpg',0),(92,55,'/uploads/product_55_1759994173_img0.png',0),(93,55,'/uploads/product_55_1760156564_img0.jpg',0),(149,13,'/uploads/products_image/product_13_1793614024_img0.jpg',0),(153,11,'/uploads/products_image/product_11_1793614099_img0.jpg',0),(154,10,'/uploads/products_image/product_10_1793614766_img0.png',0),(155,1,'/uploads/products_image/product_1_1793614801_img0.jpg',0),(156,2,'/uploads/products_image/product_2_1793614813_img0.jpg',0),(157,3,'/uploads/products_image/product_3_1793614825_img0.jpg',0),(167,98,'/uploads/products_image/default.jpg',0);
DROP TABLE IF EXISTS `product_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `rating` tinyint(4) NOT NULL DEFAULT 5,
  `comment` text NOT NULL,
  `image` varchar(400) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_status` (`product_id`,`status`),
  CONSTRAINT `fk_review_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `product_videos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_videos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `type` enum('file','youtube') NOT NULL,
  `url` varchar(500) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_product_type` (`product_id`,`type`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `product_videos` VALUES (18,55,'file','/uploads/product_55_1760157843_video.mp4',0,'2025-10-11 04:44:03'),(23,13,'youtube','https://www.youtube.com/watch?v=AV8Le5FnbMA',0,'2026-11-03 05:59:49');
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `sku` varchar(40) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `compare_at_price` decimal(10,2) DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `tags` varchar(255) DEFAULT NULL,
  `specifications` text DEFAULT NULL,
  `image` varchar(400) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sold_count` int(11) NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `badge_color` varchar(20) DEFAULT NULL,
  `is_new_arrival` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `idx_products_stock` (`stock`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `products` VALUES (1,1,'ক্লাসিক টি-শার্ট',NULL,'১০০% কটন, আরামদায়ক।',450.00,520.00,120,'','','/uploads/products_image/product_1_1793614801_img0.jpg',1,'2025-08-19 04:22:16',0,0,'#ff5252',1),(2,2,'ওয়্যারলেস ইয়ারবাড',NULL,'ব্লুটুথ ৫.০, ২০ ঘন্টা ব্যাটারি।',1650.00,NULL,45,'','','/uploads/products_image/product_2_1793614813_img0.jpg',1,'2025-08-19 04:22:16',0,0,'#ff5252',1),(3,3,'স্মার্টফোন কেস',NULL,'শকপ্রুফ প্রটেকশন।',250.00,300.00,200,'','','/uploads/products_image/product_3_1793614825_img0.jpg',1,'2025-08-19 04:22:16',0,0,'#ff5252',1),(4,1,'কটন হুডি',NULL,'শীতের জন্য উষ্ণ, নরম।',890.00,NULL,75,NULL,NULL,'uploads/hoodie.jpg',1,'2025-08-19 04:22:16',0,0,NULL,1),(5,3,'Kelsie Flynn',NULL,'Quisquam cupiditate',5000.00,NULL,1,NULL,NULL,'uploads/p_1755597731_3944.jpg',1,'2025-08-19 10:02:11',0,0,NULL,1),(10,2,'Justin Hansen',NULL,'Eum nihil rerum aliq',508.00,526.00,66,'','','/uploads/products_image/product_10_1793614766_img0.png',1,'2025-08-19 10:07:01',0,0,'#ff5252',1),(11,1,'sagar',NULL,'প্রিলিমিনারি, লিখিত ও মৌখিক পরীক্ষা শেষে চাকরিতে নিয়োগের জন্য প্রাথমিকভাবে নির্বাচিত হন আশরাফুল ইসলাম। তালিকার শুরুর রোল নম্বরটিই তাঁর। তবে শেষ পর্যন্ত তিনি নিয়োগপত্র পাননি।\r\n\r\nসাধারণ বীমা করপোরেশনে জুনিয়র অফিসার (গ্রেড-১০) পদে নিয়োগের জন্য প্রাথমিকভাবে নির্বাচিত মোট ৬৭ প্রার্থীর এই তালিকা গত ১৫ জানুয়ারি প্রকাশিত হয়।',2500.00,NULL,0,'','রাষ্ট্রদূত জানান, ব্রাজিল কম মূল্যে বাংলাদেশে প্রাণীজ আমিষ বিশেষ করে গরুর মাংস রফতানি করতে প্রস্তুত। তার দাবি, ব্রাজিল থেকে গরুর মাংস আমদানি করা গেলে কেজি প্রতি দাম মাত্র এক ডলার অর্থাৎ ১২০ থেকে ১২৫ টাকার মধ্যে পাওয়া যাবে। তবে এ প্রক্রিয়া এগিয়ে নিতে বাংলাদেশের পক্ষ থেকে দ্রুত হালাল সার্টিফিকেট প্রদান জরুরি। এতে শুধু রফতানি বাড়বে না, বরং ব্রাজিলের বেসরকারি উদ্যোক্তারা বাংলাদেশের উদ্যোক্তাদের সাথে অংশীদারিত্বে কাজ করতে আরও আগ্রহী হবে।','/uploads/products_image/product_11_1793614099_img0.jpg',1,'2025-08-20 06:26:54',0,0,'#ff5252',1),(13,3,'Inez Brewer',NULL,'শ্রমিকদের বিক্ষোভের কারণে অপ্রীতিকর ঘটনা এড়াতে জেলার বিভিন্ন এলাকার ৫০টি তৈরি পোশাক কারখানায় আজ ছুটি ঘোষণা করেছে কর্তৃপক্ষ। এতে এসব কারখানার শ্রমিকেরা কাজে যোগ দিতে পারেননি।\r\n\r\nশিল্প পুলিশ ও স্থানীয় সূত্রে জানা যায়, সকাল সাড়ে ৯টার দিকে মহানগরের নাওজোড় এলাকায় প্যানিসিয়া লিমিটেডের শ্রমিকেরা কারখানা থেকে বের হয়ে মহাসড়কের উড়ালসড়কের পশ্চিম পাশে অবস্থান নেন। পরে বাঁশ দিয়ে প্রতিবন্ধকতা তৈরি করে মহাসড়ক অবরোধ করেন তাঁরা। এতে মহাসড়কের উভয় পাশে যানবাহন চলাচল বন্ধ হয়ে যায়। একই সময়ে কোনাবাড়ী, মৌচাক ও তেলিচালা এলাকার কয়েকটি কারখানার শ্রমিকেরাও কাজ বন্ধ করে সড়কে বেরিয়ে আসেন।',347.00,721.00,39,'Earum, saepe, volupt','ন্যূনতম মজুরি ৩০ হাজার টাকা করার দাবিতে গাজীপুরে আজ মঙ্গলবার সকাল সাড়ে নয়টার দিকে ঢাকা–টাঙ্গাইল মহাসড়ক অবরোধ করে বিক্ষোভ করছেন পোশাকশ্রমিকেরা। এ ছাড়া জেলার বিভিন্ন এলাকায় শ্রমিকদের বিক্ষোভ করার খবর পাওয়া গেছে।\r\n\r\nসকাল সাড়ে ৯টার দিকে গাজীপুর মহানগরের নাওজোড় এলাকায় ঢাকা-টাঙ্গাইল মহাসড়কে অবস্থান নেন শ্রমিকেরা। তাঁদের অনেকের হাতে লাঠিসোঁটা দেখা গেছে। এতে মহাসড়কের ওই অংশে যান চলাচল বন্ধ হয়ে যায়। ভোগান্তিতে পড়েন দূরপাল্লার বাসসহ বিভিন্ন যানবাহনের যাত্রীরা।','/uploads/products_image/product_13_1793614024_img0.jpg',1,'2025-08-20 12:37:30',0,0,'#ff5252',1),(14,3,'Seth Frost',NULL,'Sed quidem voluptate',873.00,326.00,65,'Adipisci explicabo','Rerum sit velit est','/uploads/product_14_1755693827_0.jpg',1,'2025-08-20 12:43:47',0,0,NULL,1),(22,1,'Julian Pennington qw',NULL,'Velit ex cupiditate',750.00,192.00,0,'Consequatur At eius','Repudiandae tempore 11','/uploads/product_22_1756020718_0.jpg',1,'2025-08-24 07:31:58',0,0,NULL,1),(54,1,'Hedda Michael',NULL,'',82.00,0.00,9,'','','/uploads/product_54_1756207179_img0.jpg',1,'2025-08-26 11:19:39',0,0,NULL,0),(55,1,'Remote Control Car with LED Light | 360° রোটেটিং আরসি রিমোট কন্ট্রোল কার উইথ এলইডি লাইট (র‍্যান্ডম কালার))',NULL,'Tempore aperiam sin',629.00,990.00,83,'In optio voluptas e, Free Shipping, Hot','Veniam ipsum vel v','/uploads/product_55_1759994173_img0.png',1,'2025-10-09 07:16:13',0,0,'#ff5252',1),(87,1,'বাচ্চাদের কটন টি-শার্ট','SEED-KTSHIRT','নরম ১০০% কটন, বাচ্চাদের ত্বকের জন্য নিরাপদ। ২–১০ বছরের জন্য উপলব্ধ।',450.00,620.00,60,'Cotton, Kids, Summer',NULL,'/uploads/products_image/seed_ktshirt_20261105102641_d267be.jpg',1,'2026-11-05 04:26:41',0,1,'#ff5252',1),(88,1,'কিডস ডেনিম জ্যাকেট','SEED-KDENIM','স্ট্রেচেবল ডেনিম, ভেতরে নরম লাইনিং। শীতের জন্য আরামদায়ক।',1250.00,1650.00,35,'Denim, Winter, Free Shipping',NULL,'/uploads/products_image/seed_kdenim_20261105102641_541560.jpg',1,'2026-11-05 04:26:42',0,1,'#2196f3',1),(89,1,'বেবি কটন প্যান্ট','SEED-KPANT','ইলাস্টিক কোমর, আরামদায়ক ফিট। ১–৫ বছরের বাচ্চাদের জন্য।',380.00,NULL,80,'Cotton, Baby, Daily',NULL,'/uploads/products_image/seed_kpant_20261105102642_acb6e2.jpg',1,'2026-11-05 04:26:42',0,0,'#4caf50',1),(90,1,'বাচ্চাদের হুডি','SEED-KHOODIE','মোটা ফ্লিস ভেতরের হুডি, ঠান্ডায় উষ্ণ থাকার জন্য।',890.00,1150.00,45,'Hoodie, Winter, Kids',NULL,'/uploads/products_image/seed_khoodie_20261105102642_a7111c.jpg',1,'2026-11-05 04:26:43',0,1,'#ff9800',0),(91,2,'রিমোট কন্ট্রোল কার','SEED-RCCAR','রিচার্জেবল ব্যাটারি, ২.৪GHz রিমোট, ২০ মিটার রেঞ্জ। ৩+ বয়সের জন্য।',1490.00,1990.00,24,'RC, Toy Car, Free Shipping, Hot',NULL,'/uploads/products_image/seed_rccar_20261105102643_b9fe1c.jpg',1,'2026-11-05 04:26:43',0,1,'#e91e63',1),(92,2,'মিনি রোবট টয়','SEED-ROBOT','LED চোখ, সাউন্ড ইফেক্ট, হাত-পা নড়ে। ব্যাটারি Included নয়।',2100.00,2600.00,18,'Robot, LED, Toy',NULL,'/uploads/products_image/seed_robot_20261105102643_8aea98.jpg',1,'2026-11-05 04:26:43',0,1,'#9c27b0',1),(93,2,'টয় ইলেকট্রনিক পিয়ানো','SEED-PIANO','৩৭ কি, ডেমো মিউজিক, রেকর্ড ও প্লে। শিশুর সংগীত শেখার জন্য।',950.00,1250.00,40,'Music, Piano, Kids',NULL,'/uploads/products_image/seed_piano_20261105102643_0cb8e2.jpg',1,'2026-11-05 04:26:44',0,0,'#00bcd4',1),(94,2,'কিডস টর্চ লাইট','SEED-TORCH','ছোট হাতের জন্য উপযোগী, উজ্জ্বল LED, ব্যাটারি সহ।',320.00,NULL,55,'LED, Torch, Utility',NULL,'/uploads/products_image/seed_torch_20261105102644_c4e869.jpg',1,'2026-11-05 04:26:44',0,0,'#ff5722',1),(95,3,'কিডস স্মার্ট ওয়াচ','SEED-WATCH','ডিজিটাল ডিসপ্লে, জলরোধী, স্টেপ কাউন্টার ও অ্যালার্ম।',1800.00,2250.00,22,'Watch, Digital, Free Shipping',NULL,'/uploads/products_image/seed_watch_20261105102644_b46943.jpg',1,'2026-11-05 04:26:45',0,1,'#0284c7',1),(96,3,'বাচ্চাদের সানগ্লাস','SEED-SUNGAN','UV400 প্রোটেকশন, ফ্লেক্সিবল ফ্রেম — সহজে ভাঙে না।',290.00,380.00,65,'Sunglasses, UV, Kids',NULL,'/uploads/products_image/seed_sungan_20261105102645_01b5c4.jpg',1,'2026-11-05 04:26:45',0,0,'#f59e0b',1),(97,3,'মিনি স্কুল ব্যাকপ্যাক','SEED-BAG','হালকা ওয়াটার-রেজিস্ট্যান্ট ফ্যাব্রিক, দুই পাশে বোতল পকেট।',750.00,950.00,50,'Backpack, School, Kids',NULL,'/uploads/products_image/seed_bag_20261105102645_582041.jpg',1,'2026-11-05 04:26:45',0,1,'#16a34a',0),(98,3,'কিডস ওয়াটার বোতল','SEED-BOTTLE','BPA-মুক্ত প্লাস্টিক, লিক-প্রুফ ঢাকনা, ৫০০ মিলি।',420.00,NULL,60,'Bottle, BPA Free, Kids','','/uploads/products_image/default.jpg',1,'2026-11-05 04:26:46',0,0,'#8b5cf6',1);
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `role_permissions` VALUES (1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),(1,8),(1,11),(1,12),(1,13),(1,14),(1,15),(1,19),(1,22),(1,27);
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `roles` VALUES (1,'Super Admin','Has all permissions'),(2,'Editor','Editor role');
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `settings` VALUES ('animations_enabled','0'),('cod_note','সারা দেশে ক্যাশ অন ডেলিভারি প্রযোজ্য।'),('delivery_dhaka','60'),('delivery_global','120'),('delivery_nationwide','120'),('discount_show_product','0'),('featured_subtitle','আপনার জন্য বাছাই করা সেরা পণ্য'),('featured_title','Featured Products'),('hero_autoplay_ms','2500'),('home_heading','হোম পেজ শিরোনাম'),('home_layout',''),('home_notice','বাংলাদেশের সেরা অনলাইন শপিং প্ল্যাটফর্ম। ক্যাশ অন ডেলিভারি সুবিধা সহ।'),('info1_sub','৩–৫ দিনের মধ্যে'),('info1_title','দ্রুত ডেলিভারি'),('info2_sub','পণ্য হাতে পেয়ে পেমেন্ট'),('info2_title','ক্যাশ অন ডেলিভারি'),('info3_sub','কোয়ালিটি গ্যারান্টি'),('info3_title','১০০% অরিজিনাল'),('maintenance_enabled','0'),('newest_subtitle','সদ্য যোগ হওয়া নতুন সংগ্রহ'),('newest_title','New Arrivals'),('schema_v10_at','2026-11-09 16:46:49'),('schema_v2_at','2026-11-02 10:21:48'),('schema_v3_at','2026-11-02 12:17:46'),('schema_v4_at','2026-11-02 14:28:59'),('schema_v5_at','2026-11-02 15:10:52'),('schema_v6_at','2026-11-05 11:39:40'),('schema_v7_at','2026-11-09 15:19:13'),('schema_v8_at','2026-11-09 15:19:12'),('schema_v9_at','2026-11-09 16:48:00'),('social_facebook',''),('social_instagram',''),('social_whatsapp',''),('sold_count_label','Delivered'),('store_about','বাংলাদেশের সেরা অনলাইন শপিং প্ল্যাটফর্ম। ক্যাশ অন ডেলিভারি সুবিধা সহ।'),('store_address',''),('store_favicon','/uploads/site_logo/store_favicon_20261102132651_beb56a.png'),('store_logo','/uploads/site_logo/store_logo_1760000969.png'),('store_name','Toy BD'),('theme_primary','#6c5ce7'),('theme_secondary','#ff7675');
DROP TABLE IF EXISTS `sliders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sliders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `placement` enum('hero','side') NOT NULL DEFAULT 'hero',
  `image` varchar(400) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `btn_label` varchar(60) DEFAULT 'Shop Now',
  `btn_bg` varchar(20) DEFAULT '#ff5252',
  `link_type` enum('product','category','url') DEFAULT 'product',
  `link_value` varchar(255) DEFAULT NULL,
  `theme` varchar(30) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `btn_size` varchar(10) NOT NULL DEFAULT 'md',
  `btn_pos_x` varchar(10) NOT NULL DEFAULT 'center',
  `btn_pos_y` varchar(10) NOT NULL DEFAULT 'bottom',
  `show_text` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_placement_active` (`placement`,`active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sliders` VALUES (2,'hero','uploads/sliders/slider_20261103115807_20fa3a.jpg','','','Shop Now','#ff5252','product','3','purple',3,0,'2026-11-02 06:33:14','sm','left','bottom',0),(3,'hero','uploads/sliders/slider_20261102123341_740f57.jpg','','','Shop Now','#ff5252','product','1','purple',2,1,'2026-11-02 06:33:41','sm','left','bottom',0),(4,'hero','uploads/sliders/slider_20261102123358_c8da0b.jpg','','','Shop Now','#ff5252','product','4','purple',1,1,'2026-11-02 06:33:58','sm','left','bottom',0),(5,'side','uploads/sliders/slider_20261102123509_ae2701.jpg','','','Shop Now','#ff5252','product','2','purple',0,0,'2026-11-02 06:35:09','md','left','bottom',0),(6,'side','uploads/sliders/slider_20261102123531_0d99f0.jpg','','','Shop Now','#ff5252','product','2','purple',0,0,'2026-11-02 06:35:31','md','left','bottom',0),(9,'hero','uploads/sliders/slider_20261105111428_d9faf6.jpg','','','Shop Now','#0284c7','category','2','purple',1,0,'2026-11-05 05:14:28','md','left','bottom',0),(10,'hero','uploads/sliders/slider_20261105111428_b5dfa7.jpg','','','Shop Now','#ff7043','category','1','orange',2,0,'2026-11-05 05:14:29','md','left','bottom',0),(11,'hero','uploads/sliders/slider_20261105111429_06a3b2.jpg','','','শপিং শুরু করুন','#2e7d32','url','/shop.php','green',3,0,'2026-11-05 05:14:29','md','left','bottom',0),(12,'side','uploads/sliders/slider_20261105111429_3bc810.jpg','রিমোট কন্ট্রোল কার','নতুন কালেকশন','কিনুন','#7b1fa2','product','91','purple',1,1,'2026-11-05 05:14:30','md','left','bottom',0),(13,'side','uploads/sliders/slider_20261105111430_3c14ff.jpg','কিডস স্মার্ট ওয়াচ','বেস্ট সেলার','কিনুন','#0288d1','product','95','blue',2,1,'2026-11-05 05:14:30','md','left','bottom',0);
DROP TABLE IF EXISTS `subscribers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `wishlists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wishlists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_product` (`customer_id`,`product_id`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

SET FOREIGN_KEY_CHECKS=1;
