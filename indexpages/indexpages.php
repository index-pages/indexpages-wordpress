<?php
/**
 * Plugin Name:       Index Pages URL Indexing
 * Plugin URI:        https://index-pages.com/api/
 * Description:       Sends the URLs of your published posts and pages to Index Pages, a paid URL indexing service, and shows their status.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Index Pages
 * Author URI:        https://index-pages.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       indexpages
 * Domain Path:       /languages
 *
 * @package IndexPages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INDEXPAGES_VERSION', '0.1.0' );
define( 'INDEXPAGES_PLUGIN_FILE', __FILE__ );
define( 'INDEXPAGES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'INDEXPAGES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-settings.php';
require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-state.php';
require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-api.php';
require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-errors.php';
require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-eligibility.php';
require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-queue.php';
require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-sender.php';
require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-collector.php';
require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-plugin.php';

if ( is_admin() ) {
	require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-admin.php';
	require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-settings-page.php';
	require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-tools-page.php';
	require_once INDEXPAGES_PLUGIN_DIR . 'includes/class-indexpages-posts-list.php';
}

register_activation_hook( __FILE__, array( 'IndexPages_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'IndexPages_Plugin', 'deactivate' ) );

IndexPages_Plugin::init();
