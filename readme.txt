=== Scheduled Content Block ===
Contributors: hancockbuild
Tags: blocks, gutenberg, editor, gutenberg blocks, dynamic content
Requires at least: 6.0
Tested up to: 7.1.4
Requires PHP: 8.2
Stable tag: 2.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Scheduled Content Block makes creating scheduled content within blocks simple and completely hands-free.

== Description ==

Scheduled Content Block is a WordPress plugin that enables easy scheduling of content on WordPress pages or posts via a Scheduled Content block. Add a Scheduled Container block, fill it with the content you'd like, set the schedule, and publish your post or page. The content you create inside the block will only be visible during its scheduled time.

== Features ==

* Simple container block, allowing you to display content within the block during a specific timeframe.
* Optional integration with the Breeze caching plugin, purging the site's cache when content is scheduled to become active or inactive.
* Change who is able to see scheduled content on your site with role-based controls, including custom roles.
* Clear start/end boundaries independently and see the site timezone beside the date controls.
* Editor-only schedule status and visitor visibility preview at the current or a chosen site time.
* Optional automatic deletion of an expired container, with nested sibling preservation.

== Scheduling and preview ==

Dates and times use the WordPress site timezone, regardless of your browser timezone. Stored timestamps are absolute UTC instants. Changing the site timezone changes their displayed local time, not the scheduled instant. Leave start or end empty for an open boundary. Clearing a date clears the entire boundary; an empty time on a selected date uses midnight.

Invalid ranges are shown as errors without changing your dates. Visitors see the hidden state for invalid schedules unless they have been allowed to bypass schedules in settings. A nonexistent local time during the spring daylight saving jump is rejected. During the repeated hour in autumn, the earlier occurrence is used. Use a named city timezone in WordPress settings to follow daylight saving rules; fixed UTC offsets stay fixed.

Select a container to see its status: No schedule, Upcoming, Active, Expired, or Invalid schedule. Expand Visitor Preview (editor only), enable Preview visitor visibility, and optionally select a date/time in the site timezone. Use current time returns to a live preview. The preview respects the site setting that lets logged-out visitors bypass schedules. Each preview applies to the selected container; nested containers have their own schedules. It is a visibility simulation, not a full frontend theme rendering.

Preview controls, status labels, and preview times are editor-only. They are not saved in the block or displayed on the frontend. Turn preview off to edit content hidden by the simulation.

== Automatic deletion and recovery ==

Automatic deletion is off by default and requires an end date. When enabled, it removes the container and all its contents from the saved post after expiry. Nested siblings are retained. An already expired container with deletion enabled is also removed when the post is saved. Deletion uses WP-Cron, so execution may be delayed on sites with little traffic.

Before deletion, the plugin requests a WordPress revision. Recovery depends on revisions being enabled and retained for that post type. In the revision history, locate the content from before deletion, copy it back into the editor, and turn automatic deletion off or set a future end date before saving it. Restoring an expired revision unchanged can delete the block again. Keep backups for content you need to retain permanently.

== Upgrade notice ==

= 2.0.0 =
Role settings now accept every registered role. Date controls no longer silently adjust invalid ranges or assign a start date to unscheduled blocks. Existing block names, attributes, settings, and UTC timestamps remain compatible. Check invalid schedules in existing content; visitors continue to see the hidden state until corrected. Automatic deletion remains opt-in.

== Screenshots ==

1. Search for or locate the Scheduled Container Block in the WordPress editor to get started.
2. Place any content you'd like within your scheduled container and set the start/end time.
3. The Scheduled Container block will match the width of your other content but can also be set to Wide or Full-Width.
4. Use the plugin settings to change who can view the block outside of its set schedule and integrate with the Breeze cache plugin.

== Changelog ==

= 2.0.0 =
* Fix role sanitization so built-in and custom roles can be saved; allow clearing all bypass roles.
* Use the WordPress date API with an explicit dependency for consistent site timezone handling, including fixed offsets and daylight saving transitions.
* Display the site timezone, add Clear start / Clear end, and report invalid ranges without changing the entered boundaries.
* Keep new unscheduled blocks and cleared boundaries empty.
* Preserve parent serialization and surviving siblings when deleting expired nested containers.
* Request a pre-deletion WordPress revision and preserve literal backslashes when saving remaining content.
* Add editor-only status and visitor visibility preview using the current or a chosen site time; preview state is never serialized.
* Document scheduling behavior, upgrade compatibility, deletion recovery, and test procedures.
* Tested with WordPress 7.1.4 and PHP 8.2.

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
