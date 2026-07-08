<?php
/**
 * Plugin Name:       WP Portal Bridge
 * Plugin URI:        https://github.com/interchained/wp-portal-bridge
 * Description:       Keep WordPress as your backend. Upgrade the website your customers actually see. WP Portal Bridge exposes your WordPress content as a signed Portal content contract so the Portal framework can render your public site.
 * Version:           0.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Interchained
 * Author URI:        https://interchained.org
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-portal-bridge
 *
 * WordPress owns content editing. Portal owns rendering, routing, layout,
 * performance, deployment, caching, and public UX. This plugin is the secure
 * contract layer between them: an HMAC-signed, server-to-server tunnel.
 *
 * © Interchained LLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPB_VERSION', '0.1.1' );
define( 'WPB_SCHEMA_VERSION', 'portal.wp.source.v1' );
define( 'WPB_SIGNING_VERSION', 'PORTAL-BRIDGE-V1' );
define( 'WPB_RESPONSE_SIGNING_VERSION', 'PORTAL-BRIDGE-RESPONSE-V1' );
define( 'WPB_REST_NAMESPACE', 'wp-portal-bridge/v1' );
define( 'WPB_PLUGIN_FILE', __FILE__ );
define( 'WPB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-auth.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-seo.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-site.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-media.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-routes.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-menus.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-taxonomies.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-sitemap.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-snapshot.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-rest.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-admin.php';
require_once WPB_PLUGIN_DIR . 'includes/class-wp-portal-bridge-plugin.php';

register_activation_hook( __FILE__, array( 'WP_Portal_Bridge_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'WP_Portal_Bridge_Plugin', 'instance' ) );
