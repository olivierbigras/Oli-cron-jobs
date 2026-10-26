# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.2.1]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.1
[0.2.0]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.2.0
[0.1.4]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.4
[0.1.3]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.3
[0.1.2]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.2
[0.1.1]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.1
[0.1.0]: https://github.com/olivierbigras/Oli-cron-jobs/releases/tag/0.1.0
