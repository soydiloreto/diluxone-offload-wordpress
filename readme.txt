=== DiluxOne Offload – Media Storage ===
Contributors: pablodiloreto
Tags: media, offload, azure, cloud storage, uploads
Requires at least: 5.1
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
* **Sync with resumable state machine** — start, cancel, resume after an interruption, retry failed files, resync from scratch.
* **Offloading mode** — after a successful sync you can delete the local copies to free disk space; the stream wrapper keeps everything working.
* **Connection health monitoring** — when the cloud is unreachable, new uploads are refused with a clear error instead of landing somewhere else, and a banner on the plugin's admin pages says why until it recovers.
* **No files written by the plugin** — the plugin keeps no data on disk. The one time it writes to the uploads directory is when you disconnect, to copy your own media back to where WordPress expects it.
* **Custom domain / CDN support** — serve media from your own domain or CDN edge.
* **Multisite aware** — network activation supported; each site keeps its own configuration and file tracking.
* **Debug logging toggle** — errors always go to the PHP error log; info and debug lines only when you turn on the Settings toggle.

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

* WordPress 5.1 or higher.
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
* A connection-health check — a small GET request for your container's properties — when you open one of the plugin's admin pages, at most once every 5 minutes. Nothing runs on the front end or via cron.

This is **your own Azure account**. DiluxOne Offload is not involved and has no access to your data. The plugin sends no telemetry or usage data to the author or anyone else.

* Service: [Azure Blob Storage](https://azure.microsoft.com/services/storage/blobs/)
* Terms of Service: [Microsoft Online Services Terms](https://www.microsoft.com/licensing/terms/productoffering/MicrosoftAzure)
* Privacy Policy: [Microsoft Privacy Statement](https://privacy.microsoft.com/privacystatement)

== Frequently Asked Questions ==

= Does this plugin modify my existing media URLs in the database? =

No. The stream wrapper intercepts filesystem calls transparently — your post content, the `wp_posts` table, and the `wp_postmeta` table are never rewritten.

= What happens if the cloud is temporarily unreachable? =

The plugin monitors connection health. While the cloud is unreachable, a new upload fails with WordPress's own "could not be moved" error and nothing is saved anywhere, so you never end up with a file that looks uploaded but isn't. A banner on the plugin's admin pages explains the failure. Files already in the cloud keep being served from your custom domain or the storage account URL. The plugin re-checks the connection at most every five minutes and uploads resume on their own once it is back.

= Does the plugin write any files to my server? =

Not for itself: it has no cache, log or data files on disk; everything it needs lives in the WordPress options table and its own database table. Your media is written by WordPress core through the plugin's stream wrapper straight to the cloud.

The one operation that writes to the server is **Sync & Offloading → Disconnect from Cloud**. It copies your media back from the container to the exact uploads-directory paths WordPress has on record (resolved at runtime with `wp_upload_dir()`), so the Media Library works again without the plugin. It only restores files the plugin itself tracked from your uploads directory, never a script or executable file name (PHP, JavaScript, HTML, shell or Windows executables) whatever put it in the container, and it runs only when you click it.

= Can I switch providers later? =

Yes. You can remove the current provider configuration from the admin, configure a new one, run a full resync, and the plugin will start serving from the new location. No URL rewriting required.

= Will this work with WooCommerce / Elementor / image editors? =

Yes. Because the stream wrapper operates at the filesystem layer, any plugin that reads or writes files under `/uploads/` using standard PHP functions works unchanged.

= Does the plugin delete my local files automatically? =

Only if you explicitly opt in. After a successful sync you can click **Delete Local Files** in the Sync & Offloading tab. Until you do that, files are kept in both locations.

= If I delete a file from the Media Library, is it deleted from the cloud too? =

Yes, while offloading is active: the stream wrapper turns the deletion into a delete on your container, thumbnails included. If you have synced but not yet enabled offloading, WordPress deletes only the local copy; the copy already in your container is not removed automatically.

= What happens when I uninstall the plugin? =

Deleting the plugin from the Plugins screen removes everything it created in your database: its options (all prefixed `diluxone_offload_`), its transients and its file-tracking table (`diluxone_offload_files`, with your table prefix) — on every site of a network. Deactivating alone keeps all of that, so you can deactivate and reactivate without losing your configuration.

Your media files are never touched by uninstalling: whatever is in `/wp-content/uploads/` stays there, and whatever is in your container stays in your container. If offloading was active and local copies had been deleted, download them first with **Sync & Offloading → Disconnect from Cloud**, otherwise WordPress will be pointing at files that are no longer on the server.

= How do I enable verbose debug logging? =

Go to **DiluxOne Offload → Settings → Enable detailed debug logging**. Logs are written to the standard PHP `error_log` destination. Disable it in production unless you are actively troubleshooting — it will impact performance.

= Is the plugin multisite compatible? =

Yes. It can be network-activated; each site then has its own Cloud Provider configuration and its own file-tracking table, so different sites can use different containers or accounts.

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
9. Plugin status.

== Changelog ==

= 1.0.0 =
First public release.

* Azure Blob Storage provider — bring-your-own credentials, files served from `https://<your-account-name>.blob.core.windows.net`.
* Transparent stream wrapper with read/write interception — no URL rewriting, no regex on post content, no database migration required for URLs.
* Sync state machine with cancel, resume after an interruption and retry of failed files.
* Offloading mode with optional local file deletion after a successful sync.
* Connection health monitoring: while the cloud is unreachable new uploads are refused with an explicit error rather than written anywhere else, and an admin banner tailored to each failure mode (unreadable credentials, `401`/`403`, `404`, network exception) explains it with its own call to action.
* Every tab agrees on the same state: when the connection is paused, the Overview, Sync & Offloading and Status cards all say so with the same wording and the same reason, instead of some staying green while others report the failure.
* "Force HTTPS for cloud storage URLs" option to keep media working on installs served over plain HTTP.
* Custom domain / CDN support.
* Multisite support — network activation with per-site configuration and file tracking.
* Verbose debug logging toggle in Settings (errors always log; info and debug respect the toggle).
* Provider credentials encrypted at rest with AES-256-GCM using a key derived from the site's WordPress salts.
* Uninstalling removes the plugin's options, transients and tracking table on every site; media files are never touched.
* Translations included for es_AR, es_ES, es_MX, pt_BR, pt_PT, fr_FR, de_DE and it_IT.

== Upgrade Notice ==

= 1.0.0 =
First public release.
