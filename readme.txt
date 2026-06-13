=== Media Management Platform ===
Contributors: brainstudioz
Tags: media, folders, media library, file management, media organizer
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Full media lifecycle management for WordPress — organize, optimize, track, and deliver media assets at scale.

== Description ==

**Media Management Platform** is a comprehensive media management solution for WordPress. It extends the native media library with a full folder system, version control, usage tracking, analytics, AI-powered tagging, and much more.

Source code, issues, and contributions: https://github.com/derwaish05/media-management-platform

= Key Features =

* **Folder System** — hierarchical folders with drag-and-drop, per-user or global modes, and bulk assignment
* **Version Control** — keep a full history of replaced files and roll back with one click
* **Usage Tracker** — see exactly which posts, pages, products, and page-builder widgets use each media file, and flag unused files for cleanup
* **Analytics** — storage totals, storage by file type and by folder, upload activity over time, and insert/download counts per attachment, with CSV export
* **AI Tagging** — auto-tag images on upload via Google Vision or AWS Rekognition (opt-in)
* **Duplicate Detection** — find exact (MD5) and visually-similar (perceptual hash) duplicates, with a cancellable background scan
* **Smart Search** — full-text search across the media library
* **Document Library** — public-facing searchable file library via shortcode
* **Client Portal** — password-protected share links for external clients
* **Gallery Blocks** — Gutenberg block, Elementor widget, Divi module, Beaver Builder module, and WPBakery element
* **CSV Import/Export** — migrate folder structures and file assignments
* **WP-CLI Support** — manage folders, export data, and run optimization from the command line
* **Developer Hooks** — extensive `do_action` and `apply_filters` hooks for customization

= Page Builder Integrations =

* Gutenberg (block editor)
* Elementor
* Divi Builder
* Beaver Builder
* WPBakery Page Builder
* Bricks Builder

= Third-Party Integrations =

* WooCommerce — product gallery sync
* ACF — folder picker field
* FileBird, Real Media Library, Wicked Folders, HappyFiles — one-click import
* WPML, Polylang — multilingual support

== External Services ==

This plugin connects to third-party AI services **only if you enable AI tagging and select a provider** in the plugin settings. By default no external service is used and no data leaves your site.

= Google Cloud Vision API =
When AI tagging is set to **Google Vision**, image data (the image bytes of newly uploaded attachments) and your API key are sent to `https://vision.googleapis.com/v1/images:annotate` at upload time (and during OCR processing if enabled) to generate tags and extract text. This service is provided by Google LLC.
[Terms of Service](https://cloud.google.com/terms) · [Privacy Policy](https://policies.google.com/privacy)

= AWS Rekognition =
When AI tagging is set to **AWS Rekognition**, image data (the image bytes of newly uploaded attachments) and your AWS credentials are sent to the Amazon Rekognition API in your configured region at upload time to generate tags. This service is provided by Amazon Web Services, Inc.
[Terms of Service](https://aws.amazon.com/service-terms/) · [Privacy Policy](https://aws.amazon.com/privacy/)

= Client Portal download logging =
When the Client Portal feature is used, the plugin logs downloads (file, timestamp, and visitor IP address) in your own site's database for audit purposes. This data is never sent to any external service.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins > Installed Plugins**.
3. Navigate to **Media > MMP Settings** to configure the plugin.

== Frequently Asked Questions ==

= Does this plugin replace the default media library? =

No. It enhances the native WordPress media library with folders, version control, analytics, and more. All standard WordPress media functionality continues to work.

= Is there a limit on the number of folders? =

No. You can create as many folders as your site requires.

= Can I import my existing folders from FileBird or Real Media Library? =

Yes. Go to **Media > MMP Settings > Migration** and choose your source plugin to import all folders and file assignments.

= Why does the "Unused Media" view show everything right after install? =

Usage data is only recorded as content is saved while the plugin is active, so a fresh install starts with an empty usage index. Go to **Media > Analytics** and click **Rebuild Usage Index** once — it scans all posts, pages, and products in batches and populates the index. After it finishes, "Unused Media" shows only files that are genuinely not referenced anywhere.

= Why is Total Storage 0 right after install? =

Storage figures are read from a per-file size index that is written on upload. For media added before the plugin was active, open **Media > Analytics** — the page backfills the missing sizes automatically in batches and refreshes when done.

= How do duplicate scans work, and can I stop one? =

Go to **Media > Duplicates** and click **Scan for Duplicates**. The scan runs in the background in small batches and shows live progress; click **Cancel scan** at any time to stop it. Exact duplicates are matched by file hash; visually-similar images are matched by perceptual hash.

= Does this work with multisite? =

The plugin supports standard WordPress installations. Multisite compatibility has not been fully tested.

== Screenshots ==

1. Folder sidebar in the media library
2. File detail panel with version history
3. Usage tracker showing where a file is used
4. Analytics dashboard
5. Settings page

== Changelog ==

= 1.0.0 =
* Initial release.
* Hierarchical folder system for the media library with drag-and-drop and bulk assignment.
* Usage tracker across posts, pages, products, page builders, and widgets, with a one-click "Rebuild Usage Index" and an "Unused Media" view.
* Analytics: storage totals, storage by file type and folder, upload activity, insert/download counts, and CSV export. Storage sizes are backfilled automatically for existing media.
* Duplicate detection (exact + visually similar) with a cancellable, batched background scan.
* AI tagging via Google Vision or AWS Rekognition (opt-in; disclosed under External Services).
* Document Library shortcode and password-protected Client Portal share links.
* Gallery blocks/widgets for Gutenberg, Elementor, Divi, Beaver Builder, and WPBakery.
* Chart.js is bundled locally; no third-party CDNs are used.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
