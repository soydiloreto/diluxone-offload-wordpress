=== DiluxOne Offload – Media Storage ===
Contributors: pablodiloreto
Tags: media, offload, azure, cloud storage, uploads
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move your media to cloud object storage and serve it from there. Replaces /uploads/ transparently, with no URL rewriting.

== Description ==

DiluxOne Offload moves your WordPress media library to cloud object storage and serves files directly from the cloud — without breaking the Media Library UI, plugins, or existing content.

The plugin uses a custom PHP stream wrapper to intercept every read and write to `/wp-content/uploads/`, so WordPress, WooCommerce, page builders, image editors, and any plugin that calls standard filesystem functions (`fopen`, `file_get_contents`, `unlink`, etc.) keep working unchanged.

= Key features =

* **Azure Blob Storage** — bring your own storage account; nothing is shared with anyone else.
* **Transparent stream wrapper** — no URL rewriting, no regex on post content, no database migration required for URLs.
* **Sync with resumable state machine** — start, pause, resume, cancel, retry failed files, resync from scratch.
* **Offloading mode** — after a successful sync you can delete the local copies to free disk space; the stream wrapper keeps everything working.
* **Connection health monitoring** — automatic fallback to local storage when cloud is unreachable, with a persistent banner in the admin.
* **Custom domain / CDN support** — serve media from your own domain or CDN edge.
* **Import / export of configuration** — JSON-based for environment mirroring.
* **Multisite aware** — per-site or network-level configuration.
* **Debug logging toggle** — built-in verbose logging that respects WP_DEBUG and the admin Settings toggle.

= Why a stream wrapper instead of URL rewriting =

Most offload plugins rewrite media URLs in post content, which breaks when you switch providers, move domains, or restore from a backup. DiluxOne Offload leaves URLs alone and rewrites reads/writes at the filesystem layer, so your content stays portable.

== Installation ==

1. Upload the `diluxone-offload` folder to `/wp-content/plugins/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Open the new **DiluxOne Offload** menu in the admin sidebar.
4. Go to **Cloud Provider**, pick your provider, enter credentials, and click **Test Connection**.
5. Save the configuration.
6. Go to **Sync & Offloading**, run the initial sync, and enable offloading when sync is complete.

= Requirements =

* WordPress 5.0 or higher.
* PHP 7.4 or higher.
* `ext-curl` and `ext-openssl` enabled.
* Writable `wp-content/uploads/` directory during sync (needed for temporary files).
* An Azure Blob Storage account and its access key.

== External Services ==

This plugin integrates with a third-party cloud storage service. **Nothing is sent to it until you explicitly configure it** in the *Cloud Provider* tab, with credentials you supply.

= Azure Blob Storage =

When Azure is selected as the active provider, the plugin sends your media files and their metadata (file path, file size, MIME type, content) to your own Azure Blob Storage account at `https://<your-account-name>.blob.core.windows.net` using the Azure Blob REST API. Authentication is performed with the storage account key you provide. The plugin issues these requests:

* During the initial sync — to upload existing files from `/wp-content/uploads/` to your container.
* On every new media upload — to write the file to the cloud transparently via the stream wrapper.
* On read or delete — when WordPress (or any plugin using filesystem APIs against `/uploads/`) reads or deletes a file.
* Periodic connection-health checks (lightweight HEAD requests).

This is **your own Azure account**. DiluxOne Offload is not involved and has no access to your data.

* Service: [Azure Blob Storage](https://azure.microsoft.com/services/storage/blobs/)
* Terms of Service: [Microsoft Online Services Terms](https://www.microsoft.com/licensing/terms/productoffering/MicrosoftAzure)
* Privacy Policy: [Microsoft Privacy Statement](https://privacy.microsoft.com/privacystatement)

== Frequently Asked Questions ==

= Does this plugin modify my existing media URLs in the database? =

No. The stream wrapper intercepts filesystem calls transparently — your post content, the `wp_posts` table, and the `wp_postmeta` table are never rewritten.

= What happens if the cloud is temporarily unreachable? =

The plugin monitors connection health. When the cloud is marked unhealthy, new uploads automatically fall back to local storage and an admin banner warns you. When the connection recovers, you can run a sync to push the fallback files up.

= Can I switch providers later? =

Yes. You can remove the current provider configuration from the admin, configure a new one, run a full resync, and the plugin will start serving from the new location. No URL rewriting required.

= Will this work with WooCommerce / Elementor / image editors? =

Yes. Because the stream wrapper operates at the filesystem layer, any plugin that reads or writes files under `/uploads/` using standard PHP functions works unchanged.

= Does the plugin delete my local files automatically? =

Only if you explicitly opt in. After a successful sync you can click **Delete local files** in the Sync & Offloading tab. Until you do that, files are kept in both locations.

= How do I enable verbose debug logging? =

Go to **DiluxOne Offload → Settings → Enable detailed debug logging**. Logs are written to the standard PHP `error_log` destination. Disable it in production unless you are actively troubleshooting — it will impact performance.

= Is the plugin multisite compatible? =

Yes. You can configure per-site, or at the network level.

= How are my Azure credentials stored? =

The Azure access key is encrypted with AES-256-GCM before they are written to the WordPress options table. The encryption key is derived from your site's WordPress salts (`AUTH_KEY` / `SECURE_AUTH_KEY` and the corresponding salts in `wp-config.php`), so a database dump on its own is not enough to recover the credentials — the attacker also needs filesystem access to `wp-config.php`.

If you ever rotate the WordPress salts, the existing encrypted credentials become unreadable; the plugin will surface the provider as "not configured" and you simply re-enter the credentials in the *Cloud Provider* tab. There is intentionally no plaintext fallback.

Requirements: PHP `ext-openssl` (enabled by default on virtually every host).

== Screenshots ==

1. Overview with offloading configured in Microsoft Azure Storage (more options available).
2. Cloud Provider selection.
3. Cloud Provider configuration in Azure Storage (more options available).
4. Cloud Provider configured with sync option in Azure Storage (more options available).
5. Syncing in real time (first time).
6. Syncing finished and ready to enable offloading.
7. Offloading enabled.
8. Plugin settings.
9. Plugin status & tools.

== Changelog ==

= 1.0.0 =
First public release.

* Azure Blob Storage provider — bring-your-own credentials, files served from `https://<your-account-name>.blob.core.windows.net`.
* Transparent stream wrapper with read/write interception — no URL rewriting, no regex on post content, no database migration required for URLs.
* Sync state machine with pause, resume, cancel and retry of failed files.
* Offloading mode with optional local file deletion after a successful sync.
* Connection health monitoring with automatic local fallback when the cloud is unreachable, and an admin banner tailored to each failure mode (unreadable credentials, `401`/`403`, `404`, network exception) with its own explanation and call to action.
* Every tab agrees on the same state: when the connection is paused, the Overview, Sync & Offloading and Status cards all say so with the same wording and the same reason, instead of some staying green while others report the failure.
* "Force HTTPS for cloud storage URLs" option to keep media working on installs served over plain HTTP.
* Custom domain / CDN support.
* Import / export of plugin configuration as JSON for environment mirroring.
* Multisite support — per-site or network-level configuration.
* Verbose debug logging toggle in Settings (errors always log; info and debug respect the toggle).
* Provider credentials encrypted at rest with AES-256-GCM using a key derived from the site's WordPress salts.
* Translations included for es_AR, es_ES, es_MX, pt_BR, pt_PT, fr_FR, de_DE and it_IT.

== Upgrade Notice ==

= 1.0.0 =
First public release.
