# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.7] - 2026-10-27

### Security
- PHP events require the dedicated `oli_cj_manage_php_events` capability (granted to administrators by default; filter `oli_cj_php_events_capability`)
- PHP events can be disabled with `OLI_CJ_DISABLE_PHP_EVENTS` (alias `OLI_CJ_DISALLOW_PHP_EVENTS`) or the `oli_cj_php_events_disabled` filter; the form is hidden and already-scheduled events will not execute
- On a multisite network only a super administrator may manage PHP events

### Added
- FAQ note that leftover standard events created before 0.2.6 may remain after uninstall
- PHPUnit coverage for the PHP-event capability, nonce checks, and the disable switch

## [0.2.6] - 2026-10-27

### Fixed
- Uninstall unschedules only events this plugin created (new hooks, PHP events, and URL events)
- Existing cron events that were edited, imported, or paused are left in place; pausing is cleared so they run again
- URL events use `wp_safe_remote_get()` so private and local destinations are rejected

## [0.2.5] - 2026-10-26

### Added
- Plugin header now declares `Domain Path: /languages`
- Stored log error messages stay in English; known plugin messages are translated when the logs screen displays them
- Regenerated `languages/oli-cron-jobs.pot` for all current translatable strings

## [0.2.4] - 2026-10-26

### Fixed
- Admin notices use the `oli_cj_message` query arg so WordPress no longer strips the import confirmation
- Late, missed, and paused row tints use higher specificity so WP striped rows do not override them
- Log timestamps are formatted with `wp_date()` and the site `date_format` / `time_format`

## [0.2.3] - 2026-10-26

### Fixed
- Status badges meet WCAG AA contrast (≥ 4.5:1) on admin backgrounds and include Dashicons so status is not color-only
- Event list filters, row checkboxes, and the import file field have accessible labels
- Alternate WP-Cron "Off" uses a neutral color instead of success green
- Empty logs explain retention and disable Clear All Logs; empty search results name the query
- Hook and callback columns wrap on `_` / `->` and use wider widths
- DISABLE_WP_CRON and overdue admin notices are merged into one dismissible notice

## [0.2.2] - 2026-10-26

### Fixed
- The hidden Edit Cron Event screen now sets `$title` (and `admin_title`) on `load-{hook}` so `admin-header.php` never calls `strip_tags(null)` on PHP 8.4

### Changed
- `CHANGELOG.md` is included in the plugin ZIP again

## [0.2.1] - 2026-10-26

### Security
- PHP events refuse to execute, including from WP-Cron, when `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` is defined. The constants are checked independently of `can_execute()`
- PHP events run through `eval()` after an HMAC-SHA256 integrity check and require `edit_files` to manage. Temporary-file includes were removed
- CSV export prefixes cells that start with `=`, `+`, `-`, `@`, a tab, or a carriage return

### Fixed
- PHP 8.4: `fputcsv()` is called with delimiter, enclosure, and escape; the hidden edit screen no longer passes `null` to `add_submenu_page()`
- Saving an edited event redirects on `admin_init` before output, so headers are not already sent and the success notice appears
- A missing or invalid nonce returns HTTP 403 instead of 500
- A PHP event that throws reports failure instead of success
- A URL event without a hook name shows a validation message
- The custom-event filter label is now "Custom events" instead of "Custom (0)"
- Uninstall deletes the `oli_cj_spawn_test` transient and unschedules the plugin's own events

### Added
- PHPUnit coverage for file-edit execution blocks, CSV formula escaping, and eval/HMAC execution

## [0.2.0] - 2026-10-26

### Added
- PHP cron events with `edit_files` capability checks and respect for `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS`
- HMAC integrity hashes for stored PHP (and URL) events
- Next run display in the site timezone and in UTC
- List filters for all events, events with no action, core, custom, and PHP
- Late and missed status (10-minute overdue window)
- WP-Cron status panel, same-site spawn test, and `ALTERNATE_WP_CRON` warning
- CSV export of the event list
- PHPUnit tests for event CRUD, run-now, pause, and PHP integrity

### Changed
- Author URI is now https://github.com/olivierbigras
- Admin notices appear only on this plugin's screens
- Add/edit datetime fields use the site timezone
- PHPCS standard is WordPress-Extra

### Removed
- `load_plugin_textdomain()` (WordPress.org loads translations automatically)
- The incomplete `all` action logger that could recurse in admin

### Fixed
- Admin action handler no longer intercepts unrelated `action` query arguments

## 2026-10-23

### Changed
- composer.json: author email set to oli@olivierbigras.com
- composer.json: package renamed from bigrat95/ to olivierbigras/

## [0.1.4] - 2026-10-21

### Fixed
- Removed the `all` action used to block paused hooks. `get_option()` fired `pre_option_*` then `all` again, which recursed until every wp-admin screen returned HTTP 500
- Paused hooks are blocked at execution time: each paused hook gets a `PHP_INT_MIN` callback that removes remaining actions
- Plugin options are cached in a static array, with a re-entry guard around `get_option()`
- URL event handlers are registered immediately instead of on `init` priority 1 from inside `init` priority 10

### Added
- PHPUnit / WP-CLI test that `wp cron event run` on a URL event performs an HTTP request and writes a log row
- `.distignore` and `.gitattributes` `export-ignore` rules so development files stay out of the WordPress.org ZIP

## [0.1.3] - 2026-10-21

### Removed
- PHP event creation, editing, import, and execution
- All `eval()` / `create_function()` usage

### Changed
- Only standard hook events and URL events can be created
- Stored PHP events from earlier versions stay listed and keep their option data

## [0.1.2] - 2026-10-21

### Changed
- Extracted the add/edit event-type scripts into `assets/js/admin.js`
- Replaced inline confirm dialogs and color styles with classes and CSS
- Documented Site Health spawn checks and opt-in URL events in `readme.txt`

### Added
- Versioned upgrade routine and uninstall cleanup

### Fixed
- Sanitized imported schedules, paused hooks, event hooks, and URLs

## [0.1.1] - 2026-10-19

### Added
- Edit event UI, export/import, advanced filters, and callback source detection

## [0.1.0] - 2026-10-19

### Added
- Initial release of Oli Cron Jobs

[0.2.7]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.7
[0.2.6]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.6
[0.2.5]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.5
[0.2.4]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.4
[0.2.3]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.3
[0.2.2]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.2
[0.2.1]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.1
[0.2.0]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.0
[0.1.4]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.4
[0.1.3]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.3
[0.1.2]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.2
[0.1.1]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.1
[0.1.0]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.0
