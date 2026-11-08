<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>সাইট মেইনটেন্যান্স চলছে</title>
    <link rel="icon" type="image/x-icon" href="/assets/favicon.ico">
    <style>
        /* CSS Variables for quick theming */
        :root {
            --bg-color: #f8fafc; /* Light gray background */
            --primary-color: #0ea5e9; /* Blue */
            --text-color: #1e293b; /* Dark text */
            --font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        /* Basic Reset and Body Styles */
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: var(--font-family);
            background-color: var(--bg-color);
            color: var(--text-color);
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
        }

        .container {
            width: 90%;
            max-width: 400px; /* Max width for mobile-first focus */
            padding: 24px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            animation: fadeIn 0.6s ease-out;
        }

        /* Icon and Heading */
        .icon-box {
            color: var(--primary-color);
            font-size: 48px;
            margin-bottom: 15px;
        }

        .title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        /* Content and Paragraph */
        .message {
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 20px;
            color: #475569;
        }

        .contact-info {
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
            font-size: 0.9rem;
            color: #64748b;
        }

        .contact-info a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }
        
        /* Mobile-Specific Tweaks (Ensuring responsiveness) */
        @media (max-width: 480px) {
            .container {
                padding: 20px;
                width: 95%;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <?php
        // Fetching settings if available, else using default values
        // NOTE: Ensure your config.php and get_pdo() are accessible here.
        // For a true maintenance page, you might hardcode these values to avoid DB load.
        $store_name = 'Toy BD'; // Default
        $store_email = 'info@starshop.com'; // Default
        // If DB is accessible:
        /*
        try {
            $pdo_m = get_pdo();
            $settings_m = $pdo_m->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('store_name', 'store_email')")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $store_name = $settings_m['store_name'] ?? $store_name;
            $store_email = $settings_m['store_email'] ?? $store_email;
        } catch (\Throwable $th) {
            // DB connection failed, use default/hardcoded values
        }
        */
    ?>
    <div class="container">
        <div class="icon-box">
            <i class="fa-solid fa-screwdriver-wrench"></i> 
        </div>

        <h1 class="title"><?php echo h($store_name); ?> মেইনটেন্যান্স চলছে</h1>

        <p class="message">
            সাইটটি বর্তমানে জরুরি **রক্ষণাবেক্ষণ মোডে (Maintenance Mode)** আছে। আমরা নতুন ফিচার্স যোগ এবং কিছু সমস্যার সমাধান করছি।
        </p>
        <p class="message">
            আপনার অসুবিধার জন্য আন্তরিকভাবে দুঃখিত। **খুব দ্রুতই** আমরা ফিরে আসব।
        </p>

        <div class="contact-info">
            জরুরি প্রয়োজনে যোগাযোগ করুন: <br>
            <a href="mailto:<?php echo h($store_email); ?>"><?php echo h($store_email); ?></a>
        </div>
    </div>
</body>
</html>