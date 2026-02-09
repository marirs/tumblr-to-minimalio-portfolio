<?php
/**
 * Plugin Name: Tumblr to Minimalio Portfolio
 * Plugin URI:  https://wordpress.org/plugins/tumblr-to-minimalio-portfolio
 * Description: Import photos and videos from Tumblr into the Minimalio Portfolio custom post type. Supports AI-powered SEO title, description, and category generation via Google Gemini, Cloudflare Workers AI, and OpenAI.
 * Version:     1.0.0
 * Author:      Sriram Govindan
 * Author URI:  https://github.com/marirs/tumblr-to-minimalio-portfolio
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tumblr-to-minimalio-portfolio
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TTMP_VERSION', '1.0.0' );
define( 'TTMP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TTMP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TTMP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Include files
require_once TTMP_PLUGIN_DIR . 'inc/class-ttmp-ai-service.php';
require_once TTMP_PLUGIN_DIR . 'inc/class-ttmp-gemini.php';
require_once TTMP_PLUGIN_DIR . 'inc/class-ttmp-cloudflare.php';
require_once TTMP_PLUGIN_DIR . 'inc/class-ttmp-openai.php';
require_once TTMP_PLUGIN_DIR . 'inc/class-ttmp-chatgpt-text.php';
require_once TTMP_PLUGIN_DIR . 'inc/class-ttmp-gemini-text.php';
require_once TTMP_PLUGIN_DIR . 'inc/class-ttmp-tag-fallback.php';
require_once TTMP_PLUGIN_DIR . 'inc/class-ttmp-ai-chain.php';
require_once TTMP_PLUGIN_DIR . 'inc/class-ttmp-importer.php';

/**
 * Check if Minimalio Portfolio plugin is active
 */
function ttmp_check_dependency() {
	if ( ! is_admin() ) {
		return;
	}

	if ( ! post_type_exists( 'portfolio' ) ) {
		add_action( 'admin_notices', 'ttmp_dependency_notice' );
	}
}
add_action( 'init', 'ttmp_check_dependency', 20 );

/**
 * Add "Run Importer" link on the Plugins page
 */
function ttmp_plugin_action_links( $links ) {
	$importer_link = '<a href="' . esc_url( admin_url( 'admin.php?import=ttmp-importer' ) ) . '">' . esc_html__( 'Run Importer', 'tumblr-to-minimalio-portfolio' ) . '</a>';
	array_unshift( $links, $importer_link );
	return $links;
}
add_filter( 'plugin_action_links_' . TTMP_PLUGIN_BASENAME, 'ttmp_plugin_action_links' );

/**
 * Show admin notice if Minimalio Portfolio is not active
 */
function ttmp_dependency_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Tumblr to Minimalio Portfolio', 'tumblr-to-minimalio-portfolio' ); ?>:</strong>
			<?php esc_html_e( 'This plugin requires the Minimalio Portfolio plugin to be installed and activated.', 'tumblr-to-minimalio-portfolio' ); ?>
		</p>
	</div>
	<?php
}
