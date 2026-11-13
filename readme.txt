=== Scheduled Content Block ===
Contributors: hancockbuild
Tags: blocks, gutenberg, editor, gutenberg blocks, dynamic content
Requires at least: 6.0
Tested up to: 7.1.4
Requires PHP: 8.2
Stable tag: 2.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Scheduled Content Block makes creating scheduled content within blocks simple and completely hands-free.

== Description ==

Scheduled Content Block is a WordPress plugin that enables easy scheduling of content on WordPress pages or posts via a Scheduled Content block. Add a Scheduled Container block, fill it with the content you'd like, set the schedule, and publish your post or page. The content you create inside the block will only be visible during its scheduled time.

== Features ==

* Simple container block, allowing you to display content within the block during a specific timeframe.
* Optional daily, weekly, monthly, or yearly schedules, including custom intervals such as every two weeks or every three months.
* Optional integration with the Breeze caching plugin, purging the site's cache when content is scheduled to become active or inactive.
* Change who is able to see scheduled content on your site with role-based controls, including custom roles.
* Clear start/end times displayed in the editor.
* Preview any scheduled content in the WordPress editor.
* Option to automatically delete any scheduled blocks once the date/time has expired.

== Recurring schedules ==

In the block editor, open Display Schedule, choose the start and end of the first visible window, then choose Repeat. Select Daily, Weekly, Monthly, or Yearly and optionally change Repeat every (1–365). Leave Repeat set to Does not repeat to keep a one-time schedule, including open-ended start/end dates.

Repeating schedules require both dates and an end later than the start. They repeat indefinitely from the first start date; the block is visible during each window and hidden between occurrences. For example, set Monday 9:00–17:00 and choose Weekly to show the block every Monday during those hours, or choose an interval of 2 for alternate Mondays. Existing role bypass and hidden placeholder settings still apply.

Repeats follow the WordPress site timezone and preserve the first window's number of calendar days and start/end wall times, including overnight windows. Monthly and yearly repeats use the last available day when the original start day does not exist (January 31 becomes February 28 or 29, then returns to March 31; February 29 becomes February 28 in non-leap years). Daylight saving gaps move nonexistent times forward by the clock change; repeated hours use the earlier occurrence. If a clock change moves the start past the end, that occurrence stays hidden.

Visitor Preview can check future occurrences at any site date/time. Automatic deletion is disabled for repeating schedules so the block survives for future occurrences. To enable deletion, first switch back to a one-time schedule and review its end date. If Breeze integration is enabled, cache purges continue at future recurring boundaries. As with other scheduled purges, these depend on WordPress cron running on time.

== Screenshots ==

1. Search for or locate the Scheduled Container Block in the WordPress editor to get started.
2. Place any content you'd like within your scheduled container and set the start/end time.
3. The Scheduled Container block will match the width of your other content but can also be set to Wide or Full-Width.
4. Use the plugin settings to change who can view the block outside of its set schedule and integrate with the Breeze cache plugin.

== Changelog ==

= 2.1.0 =
* Add optional daily, weekly, monthly, and yearly recurring visibility windows to the block editor, with custom repeat intervals from 1 to 365.
* Keep start/end date and time controls for the first window, then repeat in the site timezone with support for overnight windows, month ends, leap years, and daylight saving changes.
* Show recurring schedule summaries and a hidden-between-occurrences status in the editor and Visitor Preview.
* Protect recurring blocks from automatic deletion, including previously scheduled deletion jobs.
* Re-arm Breeze cache purges for later occurrences and clear obsolete events when schedules change.
* Preserve existing one-time schedules, placeholders, and role-based visibility overrides.

= 2.0.0 =
* Used the WordPress date API with an explicit dependency for consistent site timezone handling, including fixed offsets and daylight saving transitions.
* Plugin now displays whether the scheduled date/time is upcoming, active, or expired.
* Keep new unscheduled blocks and cleared boundaries empty.
* Preserve parent serialization and surviving siblings when deleting expired nested containers.
* Request a pre-deletion WordPress revision and preserve literal backslashes when saving remaining content.
* Added editor-only status and visitor visibility preview using the current or a chosen site time.
* Include scheduling helpers in the editor script to avoid a missing helper request crashing new or existing blocks; include the release version in its cache key.
* Plugin now keeps content editing available with a clear message if the visitor preview configuration cannot load.
* Fixed role sanitization so built-in and custom roles can be saved; allow clearing all bypass roles.

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
