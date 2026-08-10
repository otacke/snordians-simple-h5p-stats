<?php

/**
 * Plugin Name: SNORDIAN's Simple H5P Stats
 * Plugin URI: https://snordian.de
 * Text Domain: snordians-simple-h5p-stats
 * Domain Path: /languages
 * Description: Track hits on H5P content
 * Version: 1.0.0
 * Author: Oliver Tacke
 * Author URI: https://www.olivertacke.de
 * License: MIT
 */

namespace SNORDIANSSIMPLEH5PSTATS;

// as suggested by the WordPress community
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

if ( ! defined( 'SNORDIANSSIMPLEH5PSTATS_VERSION' ) ) {
	define( 'SNORDIANSSIMPLEH5PSTATS_VERSION', '1.0.0' );
}

if ( ! defined( 'SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE' ) ) {
	define( 'SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE', __FILE__ );
}

/** @var int HTTP status code for “Forbidden”. */
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
require_once( __DIR__ . '/includes/class-options.php' );
require_once( __DIR__ . '/includes/class-table-view.php' );

/**
 * Initialize plugin: load options, create config file, instantiate admin table view.
 */
function init() {
	$simpleh5pstats_options = new Options;

	// Ensure that configuration is set
	$upload_dir  = wp_upload_dir();
	$path        = $upload_dir['basedir'] . '/snordians-simple-h5p-stats/simpleh5pstats-config.js';
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
  $server_request_uri         = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$server_http_referrer       = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	$server_http_sec_fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';

	// Is content embedded?
	$is_embed = ( false !== strpos( $server_request_uri, 'action=h5p_embed' ) );

	// Is admin viewing H5P content in backend?
	$is_admin_h5p_view = (
		false !== strpos( $server_request_uri, 'page=h5p' ) &&
		false !== strpos( $server_request_uri, 'task=show' )
	);

	// Is admin editing post/page with embedded content?
	$is_admin_post_iframe = (
		isset( $server_http_referrer ) &&
		false !== strpos( $server_http_referrer, 'action=edit' )
	);

	// Is iframe call from same origin?
	$is_same_origin = ( isset( $server_http_sec_fetch_site ) && 'same-origin' === $server_http_sec_fetch_site );

	if ( $is_admin_h5p_view || $is_admin_post_iframe ) {
		return; // Viewing H5P content in backend or editing post with embedded content
	}

	if ( ! Options::is_embed_supported() && ! $is_same_origin && $is_embed ) {
		return; // Embedding via link or iframe from external
	}

	// Try to determine H5P content id
	if ( isset( $server_http_referrer ) && false !== strpos( $server_http_referrer, 'task=show' ) ) {
		$components = wp_parse_url( $server_http_referrer );
	} elseif ( isset( $server_request_uri ) && false !== strpos( $server_request_uri, 'action=h5p_embed' ) ) {
		$components = wp_parse_url( $server_request_uri );
	}

	// Check whether current user is author of current content
	if ( isset( $components ) ) {
		// ID of content being displayed
		$content_id = array_reduce(
			explode( '&', $components['query'] ),
			function ( $id, $query ) {
	  		if ( '' !== $id ) {
					return $id;
				}

				$split = explode( '=', $query );
				if ( 'id' === $split[0] ) {
					return intval( $split[1] );
				}

				if ( 'slug' === $split[0] ) {
					$found = Database::get_content_id_by_slug( $split[1] );
					if ( false !== $found ) {
						return $found;
					}
				}

				return '';
			},
			''
		);

		if ( Database::get_content_author_id( $content_id ) === get_current_user_id() ) {
			return; // User is author of the content
		}
	}

	/*
	 * Add JavaScript listener to H5P content.
	 * Configuration is created via dynamically created H5P file, because passing config via wp_localize_script cannot run
	 * if WordPress is bypassed by using embed code or direct link.
	 */
	$upload_dir = wp_upload_dir();
	$path       = $upload_dir['basedir'] . '/snordians-simple-h5p-stats/simpleh5pstats-config.js';
	if ( file_exists( $path ) ) {
		$scripts[] = (object) array(
			'path'    => $upload_dir['baseurl'] . '/snordians-simple-h5p-stats/simpleh5pstats-config.js',
			'version' => '?buster=' . uniqid(),
		);
	}

	// /!\ Adding the nonce here is a workaround, because wp_localize_script cannot be used here.
	$scripts[] = (object) array(
		'path'    => plugins_url( 'js/simpleh5pstats-listener.js', __FILE__ ),
		'version' => '?ver=' . SNORDIANSSIMPLEH5PSTATS_VERSION . '&nonce=' . wp_create_nonce( 'simpleh5pstats_nonce_insert_data' ),
	);
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
add_action( 'wp_ajax_nopriv_simpleh5pstats_delete_data', 'SNORDIANSSIMPLEH5PSTATS\delete_data' );
add_action( 'wp_ajax_simpleh5pstats_delete_data', 'SNORDIANSSIMPLEH5PSTATS\delete_data' );
add_action( 'plugins_loaded', 'SNORDIANSSIMPLEH5PSTATS\simpleh5pstats_load_plugin_textdomain' );
add_action( 'plugins_loaded', 'SNORDIANSSIMPLEH5PSTATS\update' );
add_action( 'update_option_siteurl', 'SNORDIANSSIMPLEH5PSTATS\update_config_file', 10, 3 );
add_action( 'admin_enqueue_scripts', 'SNORDIANSSIMPLEH5PSTATS\enqueue_admin_styles' );

// Initialize plugin
add_action( 'init', 'SNORDIANSSIMPLEH5PSTATS\init' );
