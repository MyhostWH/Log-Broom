=== Log Broom ===
Contributors: lolfust11
Tags: database, cleanup, logs, revisions, transients
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely clean up log tables and clutter that plugins leave behind in your WordPress database, after you confirm a backup.

== Description ==

Many plugins keep logs, history and cached data in your database. Log Broom lists what is there, shows how many entries each source holds, and lets you remove the log entries you no longer need. It only deletes log, history and cache entries. It never deletes your published posts, pages or settings.

Every deletion, whether you run it manually or turn on automatic cleaning, requires you to confirm that you have a database backup. Nothing is turned on by default.

Find it under Tools > Log Broom.

**Supported data sources and what is kept**

Sources from other plugins appear only when that plugin's tables exist on your site.

* Post revisions: deletes old saved versions (revisions) of posts, pages, products and other content, including revisions from page builders such as Elementor. Posts, pages, autosaves and the Site Editor's history (styles, templates, navigation, patterns, custom CSS) are kept.
* Expired transients: deletes temporary cached values that have already expired. Unexpired transients are kept.
* WooCommerce database log: deletes log entries stored in its database table. Orders, products and settings are kept.
* Redirection: deletes the redirect hit log and the 404 error log. Your redirects are kept.
* Rank Math 404 monitor: deletes the recorded 404 errors. Your redirections and SEO settings are kept.
* WP Mail SMTP debug events: deletes the recorded debug events. Mailer settings and queued emails are kept.
* WP Activity Log: deletes the recorded events and their details. Activity log settings are kept.
* Wordfence: deletes live traffic older than 24 hours, failed login attempts and the scan activity log. Wordfence settings, blocked IPs, 2FA, successful logins (Wordfence uses them to recognise trusted IPs), the last 24 hours of traffic, scan results and scan working data are kept.
* Action Scheduler (used by WooCommerce and other plugins): deletes completed and canceled actions, failed actions older than 7 days, and their log entries. Pending and in-progress tasks and failed tasks from the last 7 days are kept.
* Solid Security: deletes the whole event log, attempt counters older than one day, and lockouts that expired longer ago than the ban period ("Days to Remember Lockouts") plus one day (8 days by default). Attempt counters and lockouts follow the same retention as Solid Security's own cleanup. Active lockouts, recent lockouts used for repeat-offender bans, attempt counters from the last day and bans are kept. Because the whole event log is deleted, this also clears Solid Security's unread security alerts and its last site-scan result.

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

No. Only completed and canceled Action Scheduler actions, failed actions older than 7 days, and their logs are removed. Pending and in-progress actions, and actions that failed in the last 7 days, are kept.

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

Log Broom does not collect, store or send any personal data, makes no external requests, and loads no assets from other servers. It stores only its own settings, the time and number of entries of the last cleaning run, and small internal values used while an automatic cleaning run is in progress (the names of the database tables waiting to be optimized and the running total of entries removed), and, if the last automatic run hit database errors, the error messages, the sources they came from and the number of retries so far, and the names of the tables you optimized in the last 10 minutes with the time of each, in your site's options table (on multisite, the optimize times are kept in the network's options). None of this is personal data, and all of it is removed when you delete the plugin.

== Changelog ==

= 1.0.2 =
* Automatic cleaning retries database errors with growing wait times and keeps cleaning the other sources.
* More accurate size figures after cleaning; clearer message when Optimize is off.
* Fixed a progress number that could be out of date for one batch.
* Safer Optimize: limits for large and shared tables, and a once-per-10-minutes guard.

= 1.0.1 =
* Safer Solid Security cleaning: keeps the data Solid Security uses for repeat-offender bans and attempt limits (recent lockouts and the last day of attempt counters). Thanks to Yossi Gueta.
* Safer Wordfence cleaning: keeps successful logins, scan results and scan working data, and the last 24 hours of traffic. Thanks to Yossi Gueta.
* Fix: automatic cleaning no longer forgets sources whose plugin is temporarily inactive when you save settings.
* Fix: saving settings no longer postpones the next automatic cleaning.
* Fix: post revisions that another plugin protects no longer block cleaning of the rest. Thanks to Yossi Gueta for reporting and fixing these.
* Fix: post revisions keep the Site Editor's history (styles, templates, template parts, navigation, synced patterns and custom CSS); revisions of other content, including products, are still cleaned. Thanks to Yossi Gueta.
* Fix: a database error during cleaning (for example a lock wait timeout) is now reported instead of counted as a finished run. Automatic cleaning retries 5 minutes later and the admin page shows the error. Thanks to Yossi Gueta.
* Fix: "Optimize tables" now rebuilds only the tables entries were removed from. Thanks to Yossi Gueta.
* Fix: on multisite, network-wide logs are cleaned only from the main site of the main network, and only network administrators can optimize the network options table. Thanks to Yossi Gueta.
* Fix: Action Scheduler failed tasks from the last 7 days are kept, so Action Scheduler can still stop frequently recurring tasks that keep failing.
* New: Details notes about each plugin's own cleanup, a note that cleaning Solid Security also clears its unread alerts and last site-scan result, and a confirmation line when security or activity history is selected.
* Fix: "Last run" now counts every part of a long automatic cleaning run.
* Fix: an expired login session during cleaning now asks you to reload the page; the fixed batch size is saved only when you finish typing, and choosing Automatic right after typing works; the summary wording, focus after cancelling, and the page without JavaScript are corrected.
* Much faster on sites with millions of WooCommerce scheduled tasks: the page opens instantly and cleaning is faster.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.2 =
Automatic cleaning keeps cleaning the other sources when one hits a database error and retries it with growing wait times. Also more accurate size figures after cleaning.

= 1.0.1 =
Safety update: Solid Security and Wordfence cleaning now keep the data their brute-force protection, breached-password check and scans rely on. Also fixes settings saves, "Last run" totals and several admin page issues.

= 1.0.0 =
Initial release.
