<?php
/**
 * Plugin Name:       Linkyar – Internal Link Assistant
 * Plugin URI:        https://github.com/kamranhajhossein/linkyar
 * Description:       Finds internal-link opportunities while you write, inserts them in one click and reports orphan pages. Persian-aware: matches across ي/ی, ZWNJ and diacritics.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Kamran Hajhossein
 * Author URI:        https://kamranh.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linkyar
 * Domain Path:       /languages
 *
 * @package Linkyar
 */

defined( 'ABSPATH' ) || exit;

define( 'LINKYAR_VERSION', '1.0.0' );
define( 'LINKYAR_FILE', __FILE__ );
define( 'LINKYAR_DIR', plugin_dir_path( __FILE__ ) );

require_once LINKYAR_DIR . 'includes/class-linkyar-text.php';
require_once LINKYAR_DIR . 'includes/class-linkyar-index.php';
require_once LINKYAR_DIR . 'includes/class-linkyar-links.php';
require_once LINKYAR_DIR . 'includes/class-linkyar-suggest.php';
require_once LINKYAR_DIR . 'includes/class-linkyar-plugin.php';

register_activation_hook( __FILE__, array( 'Linkyar_Links', 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'linkyar', false, dirname( plugin_basename( LINKYAR_FILE ) ) . '/languages' );
		Linkyar_Plugin::init();
		if ( is_admin() ) {
			require_once LINKYAR_DIR . 'includes/class-linkyar-admin.php';
			Linkyar_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once LINKYAR_DIR . 'includes/class-linkyar-cli.php';
			WP_CLI::add_command( 'linkyar', 'Linkyar_CLI' );
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LINKYAR_FILE, true );
		}
	}
);
