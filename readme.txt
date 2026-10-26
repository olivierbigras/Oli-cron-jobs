=== Oli Cron Jobs ===
Contributors: bigrat95
Donate link: https://olivierbigras.com/
Tags: cron, scheduled-tasks, cron-manager, debugging, tasks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

View, add, edit, run, pause, and export WordPress cron events, including URL events and capability-gated PHP events.

== Description ==

Oli Cron Jobs is a cron event manager for WordPress. It lists every scheduled event and lets administrators add, edit, delete, pause, resume, or run events immediately.

Author: Olivier Bigras
Author URI: https://github.com/olivierbigras
Email: oli@olivierbigras.com

= Key Features =

* **Event list** - Hook, arguments, next run in the site timezone and in UTC, recurrence, action callbacks, and late or missed status
* **Search, filters, and pagination** - Filter by all events, events with no action, core, custom, or PHP events
* **Event management** - Add, edit, delete, run now, and bulk delete
* **Pause and resume** - Temporarily stop a hook from running without deleting it
* **Custom schedules** - Add and delete custom intervals
* **URL cron events** - Schedule an HTTP request to a URL you choose
* **PHP cron events** - Run PHP when an event fires. Requires the `edit_files` capability and is blocked when `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` is set
* **WP-Cron status** - See whether built-in cron is disabled, whether alternate cron is on, and run a spawn test
* **CSV export** - Download the event list as a spreadsheet
* **Site Health** - Checks for disabled cron, spawn failures, and missed events

= PHP cron events =

PHP events require the same access as the plugin and theme editors (`edit_files`). They cannot be added, edited, deleted, or run manually when file editing is disabled.

A warning is shown on the add and edit screens. Stored PHP is protected with an integrity hash so tampered code will not run until the event is re-saved.

To disable PHP events completely, add this to `wp-config.php`:

`define( 'OLI_CJ_DISALLOW_PHP_EVENTS', true );`

= Security =

* Cron management requires the `manage_options` capability
* PHP events also require `edit_files` and respect `DISALLOW_FILE_EDIT`
* Every action uses a nonce
* Input is sanitized and output is escaped
* The plugin does not phone home and does not track users

= Compatibility =

* Works when `DISABLE_WP_CRON` is enabled (use a server cron job)
* Warns when `ALTERNATE_WP_CRON` is enabled
* Compatible with multisite

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/oli-cron-jobs` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Tools → Cron Jobs to manage events.

== Frequently Asked Questions ==

= What is WP-Cron? =

WP-Cron is WordPress's built-in task scheduler. Plugins and core use it to run work at a later time or on an interval.

= Can I delete core cron events? =

Yes, but deleting core events can affect scheduled posts, update checks, and similar features. Prefer pausing a hook if you only need to stop it temporarily.

= What happens if I pause a hook? =

The event stays scheduled, but its callbacks do not run until you resume the hook.

= Who can manage PHP cron events? =

Users who can manage options and edit files. If `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` is set, PHP events cannot be created or changed.

= Does this work with DISABLE_WP_CRON? =

Yes. The plugin still lists and manages events. A notice reminds you to trigger `wp-cron.php` from the server.

= How do I export events? =

Use Export CSV on the Cron Events screen. Custom events and schedules can also be exported as JSON from Cron Settings.

== External services ==

This plugin does not phone home and does not load third-party fonts, maps, or analytics.

= Site Health and spawn test =

When an administrator opens Site Health, or clicks Run spawn test on the plugin screen, the plugin may send an HTTP POST request to this site's own `wp-cron.php` URL. The request stays on the same site. It does not send personal data to a third party.

= URL cron events (opt-in) =

If an administrator creates a URL event, the plugin sends an HTTP GET request to the URL they entered when that event runs. The destination, timing, and query string are chosen by the administrator. The plugin does not add WordPress user data unless the administrator includes it in the URL.

This feature is unused until a URL event is created.

== Screenshots ==

1. Cron Events list with next run times, callbacks, and status
2. Custom schedules
3. Cron execution logs
4. Site Health integration
5. WP-Cron status and spawn test

== Changelog ==

= 0.2.0 =
* Restored PHP cron events with `edit_files` checks, DISALLOW_FILE_EDIT support, integrity hashes, and a clear warning
* Show next run in the site timezone and in UTC
* Added All / no action / core / custom / PHP filters, plus late and missed status
* Added a WP-Cron status panel, spawn test, ALTERNATE_WP_CRON warning, and CSV export
* Admin notices now appear only on this plugin's screens
* Removed load_plugin_textdomain()
* Added PHPUnit tests for event CRUD and run-now

= 0.1.4 =
* Fixed an infinite recursion on the `all` hook that returned HTTP 500 on every wp-admin screen
* Paused hooks are now blocked at execution time with a static option cache and a re-entry guard
* URL cron events now register their handlers immediately so `wp cron event run` actually requests the URL and writes a log row

= 0.1.3 =
* Temporarily removed PHP event creation and execution
* Existing PHP events stayed listed and stored

= 0.1.2 =
* Moved add/edit event-type toggle scripts into the enqueued admin.js file
* Documented Site Health and URL-event HTTP requests under External services

= 0.1.1 =
* Added edit event UI, export/import, advanced filtering, and callback source detection

= 0.1.0 =
* Initial release

== Upgrade Notice ==

= 0.2.0 =
PHP cron events are available again for users who can edit files. Next run times now include UTC, and the events screen can export CSV and test cron spawn.

== Privacy Policy ==

Oli Cron Jobs does not collect, store, or transmit personal data outside of your WordPress installation. All plugin data is stored locally in your WordPress database.

== Support ==

For support, please visit the plugin support forum on WordPress.org.
