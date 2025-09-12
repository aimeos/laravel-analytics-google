# laravel-analytics-google

Google Analytics driver for Laravel Analytics bridge.

This guide explains how to set up **Google Analytics 4 (GA4)**, retrieve your **Property ID**, and create a **Service Account key** for server-side access (e.g., in PHP using `google/apiclient`).

## 1. Create a GA4 Property
1. Go to [Google Analytics Admin](https://analytics.google.com/analytics/web/).
2. In the **Admin** panel:
   - Under **Account**, select your account (or create a new one).
   - Under **Property**, click **Create Property**.
3. Choose **GA4 (Google Analytics 4)**, enter a name, time zone, and currency.
4. After creation, you’ll see a new **Property ID** (a numeric value like `123456789`).

📌 You can always find the **Property ID**:
- Go to **Admin → Property Settings → Property ID**.

## 2. Create a Service Account in Google Cloud
To query GA4 data programmatically, you need a **Google Cloud service account**.

1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Select (or create) a project.
3. Navigate to **IAM & Admin → Service Accounts**.
4. Click **Create Service Account**:
   - Name it (e.g., `ga4-service-account`).
   - Assign role: **Viewer** or **Editor** (minimum required for GA reporting is **Viewer**).
5. After creation, go to **Keys → Add Key → Create new key**.
6. Select **JSON** → download the key file (`.json`).
   - This file contains your **private key** and credentials.

⚠️ Keep this JSON file safe and do not commit it to version control.

## 3. Grant Access in Google Analytics
Your service account must be added to the GA4 property:

1. Copy the **service account email** from the JSON key (e.g., `my-service-account@my-project.iam.gserviceaccount.com`).
2. Go to **Google Analytics → Admin → Account / Property → Account Access Management**.
3. Click **Add User**, paste the service account email.
4. Assign at least the **Viewer** role.
   - Optionally, give **Analyst** if you want it to create explorations.

## 4. Verify Setup
At this point you should have:
- **Property ID** (numeric).
- **Service Account JSON key file**.
- **Service account email** with access to GA4.

## 5. Configure in Analytics Bridge

The `./config/analytics-bridge.php` file already contains:

```php
return [
    'default' => env('ANALYTICS_DRIVER'),

    'drivers' => [
        'ga4' => [
            'propertyid' => env('GOOGLE_PROPERTYID'),
            'credentials' => json_decode(base64_decode(env('GOOGLE_AUTH', '')), true),
        ],
        /* ... */
    ],
    /* ... */
];
```

Add the required key/value pairs to your `.env` file:

```
ANALYTICS_DRIVER="ga4"
GOOGLE_PROPERTYID="..."
GOOGLE_AUTH="..."
```

The value of `GOOGLE_AUTH` must be the string from the **Service Account JSON
key file** encoded as base64. To do the encoding on the command line, use:

```bash
php -r 'echo base64_encode(file_get_contents("name-xxxxxxxxxxxx.json"));'
```

## 6. Useful links

- [Google Analytics Admin](https://analytics.google.com/analytics/web/)
- [Google Cloud Console](https://console.cloud.google.com/)
- [GA4 Reporting API Reference](https://developers.google.com/analytics/devguides/reporting/data/v1)
- [Service Account Guide](https://cloud.google.com/iam/docs/service-accounts)
