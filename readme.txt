=== Scheduled Content Block ===
Contributors: hancockbuild
Tags: blocks, gutenberg, editor, gutenberg blocks, dynamic content
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Scheduled Content Block makes creating scheduled content within blocks simple and completely hands-free.

== Description ==

Scheduled Content Block is a WordPress plugin that enables easy scheduling of content on WordPress pages or posts via a Scheduled Content block. Add a Scheduled Container block, fill it with the content you'd like, set the schedule, and publish your post or page. The content you create inside the block will only be visible during its scheduled time.

== Features ==

* Simple container block, allowing you to display content within the block during a specific timeframe.
* Optional integration with the Breeze caching plugin, purging the site's cache when content is scheduled to become active or inactive.
* Change who is able to see scheduled content on your site with role-based controls.
* Optional AI creation: describe your content and display schedule, review the generated draft, and apply it to your Scheduled Container.

== AI block creation ==

Enable AI block creation under Settings > Scheduled Content. This feature follows the AI Building Blocks for WordPress initiative, using the provider-independent WordPress AI Client and exposing a `scheduled-content-block/generate-draft` ability when the Abilities API is available.

Use WordPress 7.0 or newer with its built-in AI Client, or install the WordPress AI Client plugin on earlier supported WordPress versions. Configure a provider with a model supporting structured text generation using your site's AI settings. Your account must have the `prompt_ai` permission and permission to edit the post. The plugin does not store provider keys or select a specific provider.

Insert a Scheduled Container, open Create with AI, and describe the content and schedule. Generated content uses heading and paragraph blocks. Dates are interpreted in the site's timezone. Review the proposed content, start date, and end date before applying. Applying replaces any existing content inside that container and updates the schedule; save or update the post to persist changes. AI never publishes the post or enables automatic deletion.

AI is optional and disabled by default. Only an explicit Generate draft action sends your prompt, site timezone, and current site time to the site's configured AI provider. Existing post content is not sent. Provider usage may incur charges and is subject to that provider's terms and privacy policy. Manual scheduling remains available without AI. If the AI Client or a suitable provider is unavailable, setup guidance or an error is shown instead.

Initiative: https://make.wordpress.org/ai/2025/07/17/ai-building-blocks/
AI Client: https://github.com/WordPress/wp-ai-client
Abilities API: https://github.com/WordPress/abilities-api

== Screenshots ==

1. Search for or locate the Scheduled Container Block in the WordPress editor to get started.
2. Place any content you'd like within your scheduled container and set the start/end time.
3. The Scheduled Container block will match the width of your other content but can also be set to Wide or Full-Width.
4. Use the plugin settings to change who can view the block outside of its set schedule and integrate with the Breeze cache plugin.

== Changelog ==

= 1.2.0 =
* Add optional prompt-based creation of content and display schedules using the WordPress AI Client.
* Add draft review before applying generated headings, paragraphs, and dates to the container.
* Expose permission-checked draft generation through the WordPress Abilities API when available.

= 1.1.0 =
* Add an optional per-block setting to delete scheduled blocks after the end date passes.
* Default scheduled-content visibility to administrators only.
* Refresh the settings screen layout with cleaner styling.

= 1.0.3 =
* Fix Cover blocks inside scheduled containers so wide alignment options are available.
* Tested up to WordPress 6.9.

= 1.0.2 =
* Prevent scheduling a stop time earlier than the start time and treat invalid ranges as hidden on the frontend.

= 1.0.1 =
* Replace the legacy `scb` prefix across the plugin with the unique `scblk` namespace.
* Fix block editor loading by avoiding unescaped inline file contents during script registration.
* Remove unused legacy prefixes and migration helpers now that the plugin ships with the `scblk` namespace.

= 1.0.0 =
* First stable release.

= 0.1.2 =
* Adjusted the required version to be a major release.
* Added short description.
* Modified features and long description.

= 0.1.1 =
* Fix undefined admin check in block editor.

= 0.1.0 =
* Beta release.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/scheduled-content-block` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress
