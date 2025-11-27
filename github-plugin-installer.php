<?php
/**
 * Plugin Name: GitHub Plugin Installer
 * Description: Adds an admin interface for installing WordPress plugins directly from GitHub repositories.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPLv2 or later
 * Text Domain: github-plugin-installer
 *
 * @package GitHubPluginInstaller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GPI_PLUGIN_DIR . 'includes/class-gpi-installer.php';

/**
 * Bootstrap the plugin.
 *
 * @return void
 */
function gpi_bootstrap() {
	load_plugin_textdomain( 'github-plugin-installer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	$installer = new GPI_Installer();
	$installer->init();
}

add_action( 'plugins_loaded', 'gpi_bootstrap' );

/**
 * Inject stored GitHub token into request headers.
 *
 * @param array  $args  HTTP args passed to wp_remote_get.
 * @param string $owner Repository owner.
 * @param string $repo  Repository slug.
 * @param string $ref   Reference (branch/tag/commit).
 *
 * @return array
 */
function gpi_maybe_attach_token( $args, $owner, $repo, $ref ) {
	$token = get_option( 'gpi_github_token', '' );

	if ( ! empty( $token ) ) {
		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'token ' . $token;
	}

	return $args;
}

add_filter( 'gpi_github_request_args', 'gpi_maybe_attach_token', 10, 4 );

