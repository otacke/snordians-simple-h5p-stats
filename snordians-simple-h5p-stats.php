<?php

/**
 * Plugin Name: SNORDIAN's Simple H5P Stats
 * Plugin URI: https://snordian.de
 * Text Domain: snordians-simple-h5p-stats
 * Domain Path: /languages
 * Description: Track hits on H5P content
 * Version: 1.0.1
 * Author: Oliver Tacke
 * Author URI: https://www.olivertacke.de
 * License: MIT
 */

namespace SNORDIANSSIMPLEH5PSTATS;

// as suggested by the WordPress community
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

if ( ! defined( 'SNORDIANSSIMPLEH5PSTATS_VERSION' ) ) {
	define( 'SNORDIANSSIMPLEH5PSTATS_VERSION', '1.0.1' );
}

if ( ! defined( 'SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE' ) ) {
	define( 'SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE', __FILE__ );
}

/** @var int HTTP status code for "Forbidden". */
const HTTP_FORBIDDEN = 403;

/** @var int Default number of rows per page in DataTables. */
const DATATABLES_DEFAULT_PAGE_LENGTH = 10;

/** @var int Minimum number of rows per page in DataTables. */
const DATATABLES_MIN_PAGE_LENGTH = 1;

/** @var int Maximum number of rows per page in DataTables. */
const DATATABLES_MAX_PAGE_LENGTH = 500;

/**
 * @var int Default column index to sort by in DataTables. Column 1 = Content title.
 */
const DATATABLES_DEFAULT_ORDER_COLUMN = 1;

/** @var string Nonce action for fetching aggregated table data. */
const NONCE_GET_AGGREGATED_TABLE_DATA = 'simpleh5pstats_nonce_get_aggregated_table_data';

/** @var string Nonce action for fetching aggregated column options. */
const NONCE_GET_AGGREGATED_COLUMN_OPTIONS = 'simpleh5pstats_nonce_get_aggregated_column_options';

/** @var string Nonce action for downloading aggregated table data. */
const NONCE_DOWNLOAD_AGGREGATED_TABLE_DATA = 'simpleh5pstats_nonce_download_aggregated_table_data';

require_once( __DIR__ . '/includes/class-ajax-handler.php' );
require_once( __DIR__ . '/includes/class-capability.php' );
require_once( __DIR__ . '/includes/class-database.php' );
require_once( __DIR__ . '/includes/class-h5p-script-handler.php' );
require_once( __DIR__ . '/includes/class-options.php' );
require_once( __DIR__ . '/includes/class-table-view.php' );

/**
 * Initialize plugin: load options, create config file, instantiate admin table view.
 */
function init() {
	$simpleh5pstats_options = new Options;

	// Ensure that configuration is set
	$upload_dir = wp_upload_dir();
	$path       = $upload_dir['basedir'] . '/snordians-simple-h5p-stats/simpleh5pstats-config.js';
	if ( ! file_exists( $path ) ) {
		$config_data = get_option( 'simpleh5pstats_option' );
		Options::update_config_file( $config_data );
	}

	if ( is_admin() ) {
		$simpleh5pstats_table_view = new Table_View;
	}
}

/**
 * Enqueue admin-wide styles.
 */
function enqueue_admin_styles() {
	wp_enqueue_style(
		'simpleh5pstats-admin-style',
		plugins_url( '/styles/simpleh5pstats.css', SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE ),
		array(),
		SNORDIANSSIMPLEH5PSTATS_VERSION
	);
}

/**
 * Activate plugin.
 */
function on_activation() {
	Database::build_tables();
	Options::set_defaults();
	update_config_file();

	Capability::add_capabilities();
	register_daily_cleanup_cron();
}

/**
 * Register daily cleanup cron event.
 */
function register_daily_cleanup_cron() {
	if ( ! wp_next_scheduled( 'simpleh5pstats_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'simpleh5pstats_daily_cleanup' );
	}
}

/**
 * Run daily cleanup: remove visitor records older than today.
 */
function run_daily_cleanup() {
	Database::clean_old_visitors();
}
add_action( 'simpleh5pstats_daily_cleanup', 'SNORDIANSSIMPLEH5PSTATS\run_daily_cleanup' );

/**
 * Deactivate plugin.
 */
function on_deactivation() {
	$timestamp = wp_next_scheduled( 'simpleh5pstats_daily_cleanup' );
	if ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, 'simpleh5pstats_daily_cleanup' );
	}
}

/**
 * Uninstall plugin.
 */
function on_uninstall() {
	Database::delete_tables();
	Options::delete_options();

	Capability::remove_capabilities();
}

/**
 * Update plugin.
 */
function update() {
	if ( SNORDIANSSIMPLEH5PSTATS_VERSION === get_option( 'snordians-simple-h5p-stats_version' ) ) {
		return;
	}

	// Update database
	Database::build_tables();

	update_option( 'snordians-simple-h5p-stats_version', SNORDIANSSIMPLEH5PSTATS_VERSION );
}

/**
 * Load text domain for internationalization.
 */
function simpleh5pstats_load_plugin_textdomain() {
  // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- preferred for offline use
	load_plugin_textdomain( 'snordians-simple-h5p-stats', false, basename( dirname( __FILE__ ) ) . '/languages/' );
}

/**
 * Add listener to H5P content if feasible.
 *
 * @param object &$scripts List of JavaScripts that will be loaded.
 * @param array $libraries The libraries which the scripts belong to.
 * @param string $embed_type Possible values are: div, iframe, external, editor.
 */
function alter_h5p_scripts( &$scripts, $libraries, $embed_type ) {
	$server_request_uri         = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$server_http_referrer       = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
	$server_http_sec_fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) : '';

	$is_embed             = H5P_Script_Handler::is_embedded( $server_request_uri );
	$is_admin_h5p_view    = H5P_Script_Handler::is_admin_h5p_view( $server_request_uri );
	$is_admin_post_iframe = H5P_Script_Handler::is_admin_editing_post( $server_http_referrer );
	$is_same_origin       = H5P_Script_Handler::is_same_origin( $server_http_sec_fetch_site );

	if ( H5P_Script_Handler::should_skip_admin_access( $is_admin_h5p_view, $is_admin_post_iframe ) ) {
		return;
	}

	if ( H5P_Script_Handler::should_skip_external_embeds( $is_embed, $is_same_origin ) ) {
		return;
	}

	$content_id = H5P_Script_Handler::find_content_id( $server_request_uri, $server_http_referrer, $is_embed );

	if ( H5P_Script_Handler::is_content_author( $content_id ) ) {
		return;
	}

	H5P_Script_Handler::add_scripts( $scripts );
}

/**
 * Update configuration file.
 */
function update_config_file() {
	Options::update_config_file();
}

// Start setup
register_activation_hook( __FILE__, 'SNORDIANSSIMPLEH5PSTATS\on_activation' );
register_deactivation_hook( __FILE__, 'SNORDIANSSIMPLEH5PSTATS\on_deactivation' );
register_uninstall_hook( __FILE__, 'SNORDIANSSIMPLEH5PSTATS\on_uninstall' );

add_action( 'h5p_alter_library_scripts', 'SNORDIANSSIMPLEH5PSTATS\alter_h5p_scripts', 10, 3 );
add_action( 'wp_ajax_nopriv_simpleh5pstats_insert_data', 'SNORDIANSSIMPLEH5PSTATS\insert_data' );
add_action( 'wp_ajax_simpleh5pstats_insert_data', 'SNORDIANSSIMPLEH5PSTATS\insert_data' );
add_action( 'wp_ajax_simpleh5pstats_get_table_data', 'SNORDIANSSIMPLEH5PSTATS\get_table_data' );
add_action( 'wp_ajax_simpleh5pstats_get_column_options', 'SNORDIANSSIMPLEH5PSTATS\get_column_options_data' );
add_action( 'wp_ajax_simpleh5pstats_download_table_data', 'SNORDIANSSIMPLEH5PSTATS\download_table_data' );
add_action( 'wp_ajax_simpleh5pstats_get_aggregated_table_data', 'SNORDIANSSIMPLEH5PSTATS\get_aggregated_table_data' );
add_action( 'wp_ajax_simpleh5pstats_get_aggregated_column_options', 'SNORDIANSSIMPLEH5PSTATS\get_aggregated_column_options_data' );
add_action( 'wp_ajax_simpleh5pstats_download_aggregated_table_data', 'SNORDIANSSIMPLEH5PSTATS\download_aggregated_table_data' );
add_action( 'wp_ajax_simpleh5pstats_delete_data', 'SNORDIANSSIMPLEH5PSTATS\delete_data' );
add_action( 'plugins_loaded', 'SNORDIANSSIMPLEH5PSTATS\simpleh5pstats_load_plugin_textdomain' );
add_action( 'plugins_loaded', 'SNORDIANSSIMPLEH5PSTATS\update' );
add_action( 'update_option_siteurl', 'SNORDIANSSIMPLEH5PSTATS\update_config_file', 10, 3 );
add_action( 'admin_enqueue_scripts', 'SNORDIANSSIMPLEH5PSTATS\enqueue_admin_styles' );

// Initialize plugin
add_action( 'init', 'SNORDIANSSIMPLEH5PSTATS\init' );
