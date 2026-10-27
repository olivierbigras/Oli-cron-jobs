=== Oli Cron Jobs ===
Contributors: bigrat95
Donate link: https://olivierbigras.com/
Tags: cron, scheduled-tasks, cron-manager, debugging, tasks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.9
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
* **PHP cron events** - Run stored PHP with `eval()` when an event fires. Requires `manage_options` and the dedicated `oli_cj_manage_php_events` capability (administrators have it by default), plus `edit_files`. On multisite only a super administrator may manage PHP events. Execution is refused, including from WP-Cron, when `OLI_CJ_DISABLE_PHP_EVENTS`, `DISALLOW_FILE_EDIT`, or `DISALLOW_FILE_MODS` is set
* **WP-Cron status** - See whether built-in cron is disabled, whether alternate cron is on, and run a spawn test
* **CSV export** - Download the event list as a spreadsheet
* **Site Health** - Checks for disabled cron, spawn failures, and missed events

= PHP cron events =

PHP events execute stored code with PHP's `eval()` function after an HMAC-SHA256 integrity check. This is powerful and dangerous: the code runs with the same privileges as WordPress itself. Only trusted administrators who have `manage_options`, `oli_cj_manage_php_events` (granted to administrators by default), and `edit_files` should create PHP events. On a multisite network only a super administrator may manage them. A warning is shown on the add and edit screens.

Stored PHP is hashed with `wp_salt( 'auth' )`. Tampered code will not run until the event is re-saved. The plugin does not write a temporary file and include it.

Execution is refused even when WP-Cron fires the hook if `OLI_CJ_DISABLE_PHP_EVENTS`, `DISALLOW_FILE_EDIT`, or `DISALLOW_FILE_MODS` is defined. PHP events cannot be added, edited, deleted, or run manually in those cases either. The PHP event form is hidden when the feature is disabled.

To disable PHP events completely, add this to `wp-config.php`:

`define( 'OLI_CJ_DISABLE_PHP_EVENTS', true );`

The older `OLI_CJ_DISALLOW_PHP_EVENTS` constant is still honored.

= Security =

* Cron management requires the `manage_options` capability
* PHP events also require the dedicated `oli_cj_manage_php_events` capability (filterable) and `edit_files`
* On multisite, PHP events are restricted to super administrators
* Every create, edit, run, and delete action uses a nonce
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

Users who have `manage_options`, the dedicated `oli_cj_manage_php_events` capability (administrators have it by default), and `edit_files`. On a multisite network only a super administrator may manage PHP events. If `OLI_CJ_DISABLE_PHP_EVENTS`, `DISALLOW_FILE_EDIT`, or `DISALLOW_FILE_MODS` is set, PHP events cannot be created, changed, or executed (including from WP-Cron).

= Why does this plugin use eval()? =

PHP cron events need to run administrator-authored code when the event fires. The plugin uses `eval()` only for that feature, after an HMAC integrity check, and only when file editing is allowed. The risk is the same as pasting that code into the plugin editor: a compromised administrator account, or tampered stored code that still has a valid hash, can run arbitrary PHP. Disable the feature with `OLI_CJ_DISABLE_PHP_EVENTS` or by setting `DISALLOW_FILE_EDIT`.

= What happens to leftover cron events after I uninstall? =

Standard WP-Cron events created by versions before 0.2.6 are not tracked as plugin-owned, so they may remain after uninstall. This is harmless. You can delete those events from the Cron Jobs screen before uninstalling.

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

= 0.2.9 =
* Import is processed before the page renders: a missing or invalid nonce, or a user without manage_options, gets HTTP 403; a valid import redirects without PHP "headers already sent" warnings

= 0.2.8 =
* Security: administrators without oli_cj_manage_php_events can no longer edit, pause, resume, bulk-delete, import over, export, or view PHP events; PHP rows are hidden from them
* Security: the edit-form nonce is bound to the edited event
* Bulk delete is processed before output: an invalid nonce returns HTTP 403
* The eval() warning is shown only when PHP events can actually be used
* Uninstall removes the oli_cj_manage_php_events capability from every role
* Removed the undocumented oli_cj_user_can_manage_php_events filter (use oli_cj_php_events_capability)

= 0.2.7 =
* PHP events now require the dedicated oli_cj_manage_php_events capability (administrators have it by default; filterable)
* PHP events can be disabled with OLI_CJ_DISABLE_PHP_EVENTS (or the older OLI_CJ_DISALLOW_PHP_EVENTS alias) or the oli_cj_php_events_disabled filter; the UI is hidden and stored events will not run
* On multisite, only a super administrator may manage PHP events
* Documented that leftover pre-0.2.6 standard events may remain after uninstall

= 0.2.6 =
* Uninstall removes only events created by this plugin (new hooks, PHP events, URL events)
* Events that were only edited, imported, or paused are left scheduled; paused foreign hooks are resumed
* URL events now request destinations with wp_safe_remote_get

= 0.2.5 =
* Added Domain Path: /languages and regenerated the translation catalog
* Known log error messages are translated on the logs screen; stored rows are unchanged

= 0.2.4 =
* Import and other admin notices use oli_cj_message so WordPress no longer strips the confirmation
* Late, missed, and paused row tints override WP striped-table styles
* Log dates use the site date and time formats

= 0.2.3 =
* Status text now meets WCAG AA contrast and includes an icon so status is not color-only
* Added accessible labels for event filters, row checkboxes, and the import file field
* Neutral color for Alternate WP-Cron "Off", clearer empty logs and search states, and wrap-friendly hook names

= 0.2.2 =
* Hidden Edit Cron Event screen now sets a page title so PHP 8.4 no longer deprecates strip_tags(null) on that page

= 0.2.1 =
* PHP events refuse to execute (including from cron) when DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS is set
* PHP events now run through eval() after an HMAC integrity check; documented the risk
* CSV export prefixes formula-like cells to prevent spreadsheet injection
* Fixed PHP 8.4 deprecations in fputcsv() and add_submenu_page()
* Edit-event saves redirect before output so the success notice can show
* Invalid or missing nonces now return HTTP 403
* Failed PHP events report failure; URL events require a hook name
* Uninstall deletes the spawn-test transient and unschedules plugin events

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

= 0.2.9 =
Import now answers 403 on a bad nonce and no longer logs "headers already sent" warnings.

= 0.2.8 =
Security fix: administrators without the PHP events capability can no longer change, pause, or view PHP events. Recommended for all users.

= 0.2.7 =
PHP events now use a dedicated capability and can be disabled with OLI_CJ_DISABLE_PHP_EVENTS. On multisite only super admins may manage them.

= 0.2.6 =
Uninstall no longer clears core or third-party cron events that were only edited or paused.

= 0.2.5 =
Translation catalog updated. Known log messages are translated when displayed.

= 0.2.4 =
Import now shows a confirmation, status row colors stay visible on striped tables, and log dates follow the site format.

= 0.2.3 =
Accessibility: status contrast, labels on filters and checkboxes, and clearer empty states.

= 0.2.2 =
Fixes a PHP 8.4 deprecation on the hidden Edit Cron Event screen.

= 0.2.1 =
PHP events now use eval() with HMAC checks and will not run when file editing is disabled. CSV export is hardened against formula injection.

= 0.2.0 =
PHP cron events are available again for users who can edit files. Next run times now include UTC, and the events screen can export CSV and test cron spawn.

== Privacy Policy ==

Oli Cron Jobs does not collect, store, or transmit personal data outside of your WordPress installation. All plugin data is stored locally in your WordPress database.

== Support ==

For support, please visit the plugin support forum on WordPress.org.
