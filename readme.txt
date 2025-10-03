=== Scheduled Content Block ===
Contributors: hancockbuild
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Scheduled Content Block makes creating scheduled content within blocks simple and completely hands-free.

== Description ==

Scheduled Content Block is a WordPress plugin that enables easy scheduling of content on WordPress pages or posts via a Scheduled Content block. Add a Scheduled Container block, fill it with the content you'd like, set the schedule, and publish your post or page. The content you create inside the block will only be visible during its scheduled time.

== Features ==

* Simple container block, allowing you to display content within the block during a specific timeframe.
* Optional integration with the Breeze caching plugin, purging the site's cache when content is scheduled to become active or inactive.
* Change who is able to see scheduled content on your site with role-based controls.

== Changelog ==

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
