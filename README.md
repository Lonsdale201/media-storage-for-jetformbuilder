# Media Storage for JetFormBuilder

Sync JetFormBuilder file uploads to external cloud storage providers (Dropbox, Google Drive, Cloudflare R2). Supports automatic token refresh, per-form overrides, file-type filtering, size limits, and a flexible folder-template system.

---

## Installation

Install this plugin like any other WordPress plugin: upload it to `wp-content/plugins`, then activate it from **Plugins -> Installed Plugins**.

**Requirements:** JetFormBuilder must be installed and active. PHP 7.4+, WordPress 6.0+.

---

## Global Settings

All settings are managed from **JetFormBuilder -> Settings -> Media Storage**.

### General section

| Setting | Description |
|---------|-------------|
| **Delete original file** | Remove the local WordPress copy after a successful sync to all enabled providers. Default: off. |
| **Default folder structure** | Template that determines the remote folder path. See [Folder Templates](#folder-templates) below. Default: `JetFormBuilder/%formname%/%currentdate%`. |
| **Max file size (MB)** | Files exceeding this limit are silently skipped (submission still succeeds). Set `0` or `-1` for unlimited. Supports decimals like `1.5` or `0,5`. Default: no limit. |
| **Allowed file types** | Multi-select checkbox panel grouped by category (Images, Audio, Video, Documents, Text). Only checked types are synced to providers. **Leave empty to allow all types.** The plugin also performs a double-extension check (e.g. `evil.php.jpg`) to prevent bypasses. |
| **Enable debug logs** | Write every upload attempt (success/failure) to the PHP error log. Never includes credential data. Works independently of `WP_DEBUG`. |

### Per-form overrides

Inside the WordPress block editor, each JetFormBuilder form has a **Media Storage** sidebar panel where you can override:

- **Delete original** — Inherit global / Yes / No
- **Max file size** — Leave empty to inherit global, or set a form-specific limit
- **Allowed file types** — Leave empty to inherit global, or pick form-specific types
- **Provider rules** — Enable/disable individual providers per form, and optionally set a custom folder template per provider

When set to "inherit" (or left empty), the form uses the global setting.

---

## Folder Templates

The folder template determines the directory structure inside your cloud provider. Both the global default and per-form/per-provider overrides accept the same macros:

| Macro | Output | Example |
|-------|--------|---------|
| `%formid%` | `form-{id}` | `form-42` |
| `%formname%` | Form post title | `Contact Form` |
| `%currentdate%` | `Y/m/d` | `2026/04/10` |
| `%currentyear%` | 4-digit year | `2026` |
| `%currentmonth%` | 2-digit month | `04` |
| `%currentday%` | 2-digit day | `10` |
| `%fieldslug%` | Sanitized field name | `upload_photo` |

**Default template:** `JetFormBuilder/%formname%/%currentdate%`

Per-form provider folders set to `default` fall back to the global template.

### Developer filter

Each provider exposes a filter to modify the final remote path:

```php
// Dropbox
add_filter( 'media_storage_for_jetformbuilder/dropbox/path', function( $path, $entry, $handler, $context ) {
    return $path;
}, 10, 4 );

// Google Drive
add_filter( 'media_storage_for_jetformbuilder/gdrive/path', $callback, 10, 4 );

// Cloudflare R2
add_filter( 'media_storage_for_jetformbuilder/cloudflare_r2/path', $callback, 10, 4 );
```

---

## Provider Setup

### Dropbox

1. **Create a Dropbox app**
   - Visit https://www.dropbox.com/developers and click **Create App** -> choose **Scoped access** and **Full Dropbox** (or **App folder** if preferred).
   - Name the app and create it.
2. **Grant permissions**
   - Open the app's **Permissions** tab and enable at least `files.content.write` and `files.content.read`.
   - Save the changes.
3. **Collect app credentials and set redirect URI**
   - On the **Settings** tab copy the **App key** and **App secret**.
   - Add the following redirect URI: `https://<your-domain>/wp-json/msjfb/v1/dropbox/callback`
4. **Enter credentials in WordPress**
   - Go to **JetFormBuilder -> Settings -> Media Storage**.
   - Enable **Dropbox** and fill in the **App Key** and **App Secret**.
   - **Save the settings** (important — the credentials must be saved before generating tokens).
5. **Generate a Refresh Token**
   - Click **Generate Refresh Token** inside the Dropbox card. A popup opens the Dropbox consent screen.
   - Approve access. The plugin automatically fills the token fields via the popup.
   - If the fields don't auto-fill, the popup displays a copyable refresh token — paste it into the **Refresh Token** field manually.
6. **Save the settings** again. The plugin will automatically refresh the access token when it expires.

**Manual fallback (only if the popup flow is unavailable):**

Build an authorization URL in the browser:
```
https://www.dropbox.com/oauth2/authorize?client_id=APP_KEY&response_type=code&redirect_uri=REDIRECT_URI&token_access_type=offline
```
Log in, approve the app, and note the `code` parameter in the redirected URL. Exchange the code:
```
POST https://api.dropboxapi.com/oauth2/token
Content-Type: application/x-www-form-urlencoded

code=AUTH_CODE&grant_type=authorization_code&client_id=APP_KEY&client_secret=APP_SECRET&redirect_uri=REDIRECT_URI
```
The response contains `access_token` and `refresh_token`. Copy them into the settings.

---

### Google Drive

#### Step 1 — Create a Google Cloud project

1. Go to https://console.cloud.google.com/ and create a new project (or select an existing one).
2. Navigate to **APIs & Services -> Library**, search for **Google Drive API**, and click **Enable**.

#### Step 2 — Configure the OAuth consent screen

1. Go to **APIs & Services -> OAuth consent screen**.
2. Select **External** user type (unless you have a Google Workspace org and want Internal).
3. Fill in the required fields:
   - **App name** — any name (e.g. your site's name)
   - **User support email** — your email
   - **Developer contact email** — your email
4. On the **Scopes** step, click **Add or remove scopes** and add:
   - `https://www.googleapis.com/auth/drive.file` (allows access only to files created by the app)
5. Save and continue.

> **Important:** By default the app is in **Testing** mode. In testing mode only users explicitly added as **test users** can authorize. If you want to use this in production:
> - Either add yourself (and any admin who needs to generate tokens) as a test user under **OAuth consent screen -> Test users**
> - **Or publish the app** by clicking **Publish App** on the consent screen page. Publishing removes the test-user restriction. For internal/personal use this is fine — Google may request verification only if you use sensitive scopes or have many users, but `drive.file` is not a sensitive scope.

#### Step 3 — Create OAuth credentials

1. Go to **APIs & Services -> Credentials**.
2. Click **Create Credentials -> OAuth client ID**.
3. Choose **Web application** as the application type.
4. Under **Authorized redirect URIs** add:
   ```
   https://<your-domain>/wp-json/msjfb/v1/gdrive/callback
   ```
5. Click **Create**. Copy the **Client ID** and **Client Secret**.

#### Step 4 — Enter credentials in WordPress

1. Go to **JetFormBuilder -> Settings -> Media Storage**.
2. Enable **Google Drive** and fill in:
   - **Client ID** — from step 3
   - **Client Secret** — from step 3
   - **Root folder** — a folder name (e.g. `JFB Uploads`) or a Google Drive folder ID. Leave empty to use the Drive root. If you enter a name, the plugin will find or create the folder automatically.
3. **Save the settings** (credentials must be saved before generating tokens).

#### Step 5 — Generate a Refresh Token

1. Click **Generate Refresh Token** inside the Google Drive card.
2. A popup opens the Google consent screen. Sign in and grant access.
3. The plugin automatically fills the token fields via the popup.
4. If the fields don't auto-fill, the popup displays a copyable refresh token — paste it into the **Refresh Token** field manually.
5. **Save the settings** again.

The plugin automatically refreshes the access token (valid for ~1 hour) using the refresh token. No manual intervention needed after the initial setup.

> **Troubleshooting:** If you see `Error 403: access_denied` during authorization, your Google Cloud app is still in Testing mode and your Google account is not added as a test user. Either add yourself under **OAuth consent screen -> Test users**, or publish the app.

---

### Cloudflare R2

1. **Create an R2 bucket**
   - Log in to the Cloudflare dashboard and navigate to **R2 -> Buckets -> Create bucket**.
   - Give it a name (e.g. `jetformbuilder-uploads`) and create it.
2. **Generate an API token**
   - Go to **R2 -> Account Details -> Manage R2 API Tokens -> Create API token**.
   - Choose **Edit** permissions (read/write) for the specific bucket.
   - Copy the **Access Key ID** and **Secret Access Key** immediately — the secret cannot be viewed again.
3. **Find your Account ID**
   - In the dashboard sidebar, look for the **Account ID** (long alphanumeric string).
4. **Enter credentials in WordPress**
   - Go to **JetFormBuilder -> Settings -> Media Storage**.
   - Enable **Cloudflare R2** and fill in:
     - **Account ID** — from step 3
     - **Access Key ID** — from step 2
     - **Secret Access Key** — from step 2
     - **Bucket** — the name from step 1
     - **Region** — usually `auto`
5. Save the settings.

> **Note:** If you regenerate API tokens or change the bucket name, update the values here; otherwise uploads will fail with an authentication error.

---

## How It Works

1. A user submits a JetFormBuilder form with file uploads.
2. JetFormBuilder processes the form and saves files to WordPress as usual.
3. **After** the form is processed, this plugin collects the uploaded files.
4. Files are filtered by size limit and allowed file types (global, then per-form overrides).
5. Each enabled provider receives the filtered files, uploaded to the resolved folder path.
6. If **Delete original** is on, local copies are removed after all providers succeed.

The plugin hooks into `jet-form-builder/form-handler/after-send` — it never interferes with the core JetFormBuilder upload process. If a file is filtered out (wrong type, too large), the form submission still succeeds normally; the file simply isn't synced to the provider.
