<?php
/**
 * Plugin Name: DiluxOne Offload – Multi-Cloud Media Storage (Azure, AWS, GCP)
 * Description: Move your WordPress media to Azure Blob Storage, Amazon S3, Google Cloud Storage or DiluxOne Cloud and serve it from there. Replaces the /uploads/ directory transparently, via PHP stream wrappers.
 * Version: 1.0.0
 * Author: Pablo Ariel Di Loreto
 * Author URI: https://diluxone.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: diluxone-offload
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'DILUXONE_OFFLOAD_VERSION', '1.0.0' );
define( 'DILUXONE_OFFLOAD_DIR', plugin_dir_path( __FILE__ ) );
define( 'DILUXONE_OFFLOAD_URL', plugin_dir_url( __FILE__ ) );
define( 'DILUXONE_OFFLOAD_FILE', __FILE__ );

// Load enhanced autoloader
require_once DILUXONE_OFFLOAD_DIR . 'includes/enhanced-autoloader.php';

use DiluxOneOffload\Plugin;

global $diluxone_offload_plugin;

/**
 * Initialize the enhanced plugin.
 *
 * Translations for plugins hosted on wordpress.org are loaded automatically
 * by WordPress since 4.6, so we no longer call load_plugin_textdomain().
 *
 * @return void
 */
function diluxone_offload_init() {
	global $diluxone_offload_plugin;

	$diluxone_offload_plugin = Plugin::get_instance();
	$diluxone_offload_plugin->init();
}
add_action( 'plugins_loaded', 'diluxone_offload_init', 10 );

/**
 * Plugin activation hook.
 */
register_activation_hook(
	__FILE__,
	function () {
		\DiluxOneOffload\Logger::log( '[DiluxOne Offload] Activation hook fired.', 'info', true );
		Plugin::activate();
	}
);

/**
 * Plugin deactivation hook.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		\DiluxOneOffload\Logger::log( '[DiluxOne Offload] Deactivation hook fired.', 'info', true );
		Plugin::deactivate();
	}
);
