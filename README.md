# Oli Cron Jobs

[![Code Quality](https://github.com/olivierbigras/Oli-cron-jobs/actions/workflows/code-quality.yml/badge.svg)](https://github.com/olivierbigras/Oli-cron-jobs/actions/workflows/code-quality.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)

A cron event manager for WordPress. View, add, edit, delete, pause, resume, and run scheduled events, including URL events and capability-gated PHP events.

Author: Olivier Bigras
Author URI: https://github.com/olivierbigras
Email: oli@olivierbigras.com

## Features

- List every cron event: hook, arguments, next run (site timezone and UTC), recurrence, callbacks, late or missed status
- Search, pagination, and filters: all, events with no action, core, custom, PHP
- Add, edit, delete, run now, and bulk delete
- Pause and resume a hook
- Add and delete custom schedules
- URL cron events (HTTP GET to a URL you choose)
- PHP cron events via `eval()` after HMAC integrity checks (`manage_options`, `oli_cj_manage_php_events`, and `edit_files`; super admin on multisite; execution is refused from cron when `OLI_CJ_DISABLE_PHP_EVENTS`, `DISALLOW_FILE_EDIT`, or `DISALLOW_FILE_MODS` is set)
- WP-Cron status, spawn test, and warnings for `DISABLE_WP_CRON` / `ALTERNATE_WP_CRON`
- CSV export of the event list

## Installation

1. Upload the plugin to `/wp-content/plugins/oli-cron-jobs/`
2. Activate it from the Plugins screen
3. Open **Tools → Cron Jobs**

## Requirements

- WordPress 6.5 or higher
- PHP 7.4 or higher
- Tested up to WordPress 7.1

## PHP cron events

Managing PHP events requires `manage_options`, the dedicated `oli_cj_manage_php_events` capability (granted to administrators by default), and `edit_files`. On a multisite network only a super administrator may manage them. Stored code is executed with `eval()` after an HMAC integrity check. That is equivalent in risk to the plugin editor: only trusted administrators should create PHP events. The disable switch and file-editing constants block add, edit, delete, run-now, and cron execution. Stored PHP is hashed so tampered code will not run.

Disable the feature entirely with:

```php
define( 'OLI_CJ_DISABLE_PHP_EVENTS', true );
```

The older `OLI_CJ_DISALLOW_PHP_EVENTS` constant is still honored.

## Development

```bash
composer install
composer run lint
composer run phpcs
WP_ROOT=/path/to/wordpress composer test
```

With wp-env:

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli wp plugin activate oli-cron-jobs
```

## License

GPL v2 or later. Copyright (C) 2026 Olivier Bigras.

See [LICENSE](LICENSE) and [CHANGELOG.md](CHANGELOG.md).
