<?php
/**
 * Plugin Name: Oli Cron Jobs
 * Plugin URI: https://github.com/olivierbigras/Oli-cron-jobs
 * Description: Manage scheduled cron events, custom intervals, URL events, and PHP events from the WordPress admin.
 * Version: 0.2.8
 * Author: Olivier Bigras
 * Author URI: https://github.com/olivierbigras
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oli-cron-jobs
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OLI_CJ_VERSION', '0.2.8' );
define( 'OLI_CJ_PLUGIN_FILE', __FILE__ );
define( 'OLI_CJ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLI_CJ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-plugin.php';

register_activation_hook( __FILE__, array( 'Oli_CJ_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Oli_CJ_Plugin', 'deactivate' ) );

Oli_CJ_Plugin::get_instance();
