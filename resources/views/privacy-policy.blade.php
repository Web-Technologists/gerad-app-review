<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - UPI Generation App</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: #111827;
            --text-color: #9ca3af;
            --title-color: #f3f4f6;
            --primary-color: #10b981;
            --primary-hover: #34d399;
            --border-color: #1f2937;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.7;
            margin: 0;
            padding: 0;
        }

        .header {
            text-align: center;
            padding: 4rem 1rem 2rem 1rem;
            background: linear-gradient(180deg, rgba(16, 185, 129, 0.08) 0%, rgba(11, 15, 25, 0) 100%);
        }

        .header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.5rem;
            color: var(--title-color);
            margin: 0;
            font-weight: 700;
            letter-spacing: -0.025em;
        }

        .header p {
            font-size: 1.1rem;
            color: var(--primary-color);
            margin: 0.5rem 0 0 0;
            font-weight: 500;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1.5rem 6rem 1.5rem;
        }

        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 2.5rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.3);
        }

        h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            color: var(--title-color);
            margin-top: 2rem;
            margin-bottom: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            letter-spacing: -0.015em;
        }

        h2::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 1.25rem;
            background-color: var(--primary-color);
            border-radius: 2px;
        }

        h2:first-of-type {
            margin-top: 0;
        }

        p {
            margin-top: 0;
            margin-bottom: 1.25rem;
        }

        ul {
            margin: 0 0 1.5rem 0;
            padding-left: 1.5rem;
        }

        li {
            margin-bottom: 0.5rem;
        }

        .footer {
            text-align: center;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.875rem;
        }

        .footer a {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .footer a:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        @media (max-width: 640px) {
            .header h1 {
                font-size: 2rem;
            }
            .card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>Privacy Policy</h1>
        <p>UPI Generation App</p>
    </div>

    <div class="container">
        <div class="card">
            <h2>Introduction</h2>
            <p>Welcome to <strong>UPI Generation App</strong>. We are committed to protecting your privacy and security. This Privacy Policy describes how we collect, use, and share information when you install and use our application in connection with your Shopify store.</p>

            <h2>Information We Collect</h2>
            <p>When you install the App, we automatically collect certain information from your Shopify account required to provide app services. This includes:</p>
            <ul>
                <li><strong>Shop details:</strong> Your store domain, shop ID, country, currency, and contact email.</li>
                <li><strong>OAuth tokens:</strong> Permanent offline access tokens generated during installation to communicate with Shopify API.</li>
                <li><strong>Product catalog metadata:</strong> Product details (IDs, titles, handles, types, images, and metafields) to generate UPI codes and perform syncing operations.</li>
            </ul>
            <p><strong>Note:</strong> We do NOT collect, store, or process any protected customer data (such as customer names, emails, physical addresses, or phone numbers).</p>

            <h2>How We Use Your Information</h2>
            <p>We use the information we collect to provide and improve the App's core services, including:</p>
            <ul>
                <li>Generating unique, alphanumeric UPI codes for your product inventory.</li>
                <li>Synchronizing product metadata, categories, and image URLs across your connected stores.</li>
                <li>Processing direct CSV file imports and downloads for catalog management.</li>
            </ul>

            <h2>Data Retention and Erasure (GDPR Compliance)</h2>
            <p>We retain your store and product metadata for as long as your Shopify store remains active with our application. If you uninstall the app:</p>
            <ul>
                <li>We automatically mark the store status as inactive.</li>
                <li>We comply with Shopify's mandatory GDPR endpoints (`shop/redact`, `customers/redact`, and `customers/data_request`) to erase or return data within 48 hours of notification.</li>
            </ul>

            <h2>Security</h2>
            <p>We implement industry-standard security measures to safeguard your access credentials and metadata. All communication between our server and Shopify's API is encrypted using SSL/TLS, and webhooks are verified using HMAC signatures.</p>

            <h2>Changes to This Policy</h2>
            <p>We may update this Privacy Policy from time to time to reflect changes in our practices or operational requirements. We will notify you of any major changes by updating the policy page.</p>

            <h2>Contact Us</h2>
            <p>If you have any questions or requests regarding your data, please contact us at: <a href="mailto:developer@synscript.com" style="color: var(--primary-color); text-decoration: none;">developer@synscript.com</a>.</p>

            <div class="footer">
                &copy; {{ date('Y') }} Synscript Technologies. All rights reserved.
            </div>
        </div>
    </div>

</body>
</html>
