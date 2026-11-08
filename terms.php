<?php
// terms.php — কনটেন্ট pages টেবিল থেকে আসে (admin/pages.php এডিটযোগ্য)
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/render_page.php';

increment_page_view('terms');

render_static_page(
  'terms',
  'শর্তাবলী (Terms & Conditions)',
  '<p class="pg-text">কনটেন্ট এখনো যোগ করা হয়নি।</p>'
);
