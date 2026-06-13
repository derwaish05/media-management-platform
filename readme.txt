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

= Key Features =

* **Folder System** — hierarchical folders with drag-and-drop, per-user or global modes, and bulk assignment
* **Version Control** — keep a full history of replaced files and roll back with one click
* **Usage Tracker** — see exactly which posts, pages, and page-builder widgets use each media file
* **Analytics** — track views, inserts, and downloads per attachment
* **AI Tagging** — auto-tag images on upload via Google Vision or AWS Rekognition
* **Smart Search** — full-text and perceptual-hash duplicate detection
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

== Upgrade Notice ==

= 1.0.0 =
Initial release.
