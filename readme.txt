=== Log Broom ===
Contributors: lolfust11
Tags: database, cleanup, logs, revisions, transients
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely clean up log tables and clutter that plugins leave behind in your WordPress database, after you confirm a backup.

== Description ==

Many plugins keep logs, history and cached data in your database. Log Broom lists what is there, shows how many entries each source holds, and lets you remove the log entries you no longer need. It only deletes log, history and cache entries. It never deletes your published posts, pages or settings.

Every deletion, whether you run it manually or turn on automatic cleaning, requires you to confirm that you have a database backup. Nothing is turned on by default.

Find it under Tools > Log Broom.

**Supported data sources and what is kept**

Sources from other plugins appear only when that plugin's tables exist on your site.

* Post revisions: deletes old saved versions of posts and pages. Posts, pages and autosaves are kept.
* Expired transients: deletes temporary cached values that have already expired. Unexpired transients are kept.
* WooCommerce database log: deletes log entries stored in its database table. Orders, products and settings are kept.
* Redirection: deletes the redirect hit log and the 404 error log. Your redirects are kept.
* Rank Math 404 monitor: deletes the recorded 404 errors. Your redirections and SEO settings are kept.
* WP Mail SMTP debug events: deletes the recorded debug events. Mailer settings and queued emails are kept.
* WP Activity Log: deletes the recorded events and their details. Activity log settings are kept.
* Wordfence: deletes the live traffic, login attempt, scan activity and scan scratch data tables. Wordfence settings, blocked IPs and 2FA are kept.
* Action Scheduler (used by WooCommerce and other plugins): deletes completed, canceled and failed actions and their log entries. Pending and in-progress tasks are kept.
* Solid Security: deletes the event log, temporary attempt records and lockouts that have already expired. Active lockouts are kept.

Plugin names are used only to describe what each source cleans. This plugin is not affiliated with or endorsed by them.

**Features**

* Shows the entry count and, where the database can report it, the size of each source.
* Cleans in small batches, with a progress display.
* Optional automatic cleaning on a schedule you choose, which also requires the backup confirmation.
* Works in the admin only. Nothing is added to the front end of your site.

Results depend on how much data your site has accumulated. Removing old log entries may reduce the size of your database.

== Installation ==

1. Upload the `log-broom` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Open Tools > Log Broom.
4. Back up your database, tick the confirmation in the dialog, and clean the sources you choose.

== Frequently Asked Questions ==

= Is it reversible? =

No. Deleted log entries cannot be restored by this plugin. Back up your database first. The plugin asks you to confirm a backup before every deletion.

= Will it delete pending WooCommerce tasks? =

No. Only completed, canceled and failed Action Scheduler actions and their logs are removed. Pending and in-progress actions are kept.

= Why is the size shown as "—" for revisions? =

Revisions live in the shared posts table, so the database cannot report a size for them alone. The entry count is shown instead. The same applies to expired transients.

= How does it handle very large logs? =

It cleans in automatic batches that grow on fast servers and shrink on slow ones, so it never sends one huge query. Each batch starts at 5,000 entries, doubles while batches finish in under a second (up to 50,000), and halves when a batch takes longer than three seconds. Post revisions and expired transients always use their own safe sizes.

There's also an Advanced manual override: under Advanced, "Entries per batch" can be set to a fixed number from 1,000 to 100,000. Larger batches clean faster but can time out on slower hosting.

= Does it work on multisite? =

Yes. It cleans the current site. The network-wide tables of Wordfence, Solid Security and WP Activity Log are only offered on the main site.

= Does it send any data anywhere? =

No. See the Privacy section.

== Screenshots ==

1. The Log Broom screen with the list of sources, entry counts and the summary.
2. The backup confirmation dialog shown before any deletion.
3. Cleaning in progress.
4. The success message after cleaning.

== Privacy ==

Log Broom does not collect, store or send any personal data, makes no external requests, and loads no assets from other servers. It stores only its own settings and the time of the last cleaning run in your site's options table. Both are removed when you delete the plugin.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
