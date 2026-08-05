<?php

namespace SNORDIANSSIMPLEH5PSTATS;

// as suggested by the WordPress community
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Sanitize request data used by insert_data().
 *
 * @param array $post Already unslashed $_POST data.
 * @return array {
 *     @type int    $content_id H5P content id, 0 if missing/invalid.
 *     @type string $user_uuid  Anonymous visitor identifier.
 * }
 */
function sanitize_insert_data_request( array $post ) {
	$post_content_id = $post['content_id'] ?? '';
	$post_user_uuid  = $post['user_uuid'] ?? '';

	return array(
		'content_id' => '' !== $post_content_id ? intval( $post_content_id ) : 0,
		'user_uuid'  => '' !== $post_user_uuid ? sanitize_text_field( $post_user_uuid ) : '',
	);
}

/**
 * Insert hit for given content.
 * /!\ No access control here, because hits of visitors not logged in should also be
 * stored.
 *
 * @param string text Text to be added.
 */
function insert_data() {
	global $wpdb;

	if ( ! check_ajax_referer( 'simpleh5pstats_nonce_insert_data', 'nonce', false ) ) {
		exit( json_encode( 'error' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$request = sanitize_insert_data_request( wp_unslash( $_POST ) );
	if ( $request['content_id'] < 1 ) {
		wp_die();
	}

	// Ignore calls from logged in users who can edit H5P content
	$wp_user_id = get_current_user_id();
	if ( $wp_user_id > 0 && current_user_can( 'edit_h5p_contents' ) ) {
		wp_die();
	}

	Database::insert_hit( $request['content_id'], $request['user_uuid'] );

	wp_die();
}

/**
 * Delete all data.
 */
function delete_data() {
	if ( ! check_ajax_referer( 'simpleh5pstats_nonce_delete_data', 'nonce', false ) ) {
		exit( json_encode( 'error' ) );
	}

	if ( ! current_user_can( 'delete_simpleh5pstats_results' ) ) {
		exit( json_encode( 'error' ) );
	}

	// Add hook 'simpleh5pstats_delete_data'
	do_action( 'simpleh5pstats_delete_data' );

	$response = Database::delete_data();
	exit( json_encode( $response ) );
}

/**
 * Return page of rows for DataTables server-side processing.
 */
function get_table_data() {
	serve_table_data( 'simpleh5pstats_nonce_get_table_data', 'get_table_count', 'get_filtered_count', 'get_table_page' );
}

/**
 * Return page of aggregated rows for DataTables server-side processing.
 */
function get_aggregated_table_data() {
	serve_table_data( NONCE_GET_AGGREGATED_TABLE_DATA, 'get_aggregated_table_count', 'get_aggregated_filtered_count', 'get_aggregated_table_page' );
}

/**
 * Sanitize DataTables request data for server-side processing.
 *
 * @param array $post Already unslashed $_POST data.
 * @return array {
 *     @type int    $draw         Draw counter, echoed back as-is.
 *     @type int    $start        Pagination start offset.
 *     @type int    $length       Page length, clamped to configured bounds.
 *     @type int    $order_col    Column index to sort by.
 *     @type string $order_dir    Sort direction.
 *     @type string $search       Global search term.
 *     @type array  $col_searches Per-column search terms keyed by column index.
 * }
 */
function sanitize_table_data_request( array $post ) {
	$post_draw    = $post['draw'] ?? '';
	$post_start   = $post['start'] ?? '';
	$post_length  = $post['length'] ?? '';
	$post_order   = $post['order'] ?? array();
	$post_search  = $post['search'] ?? array();
	$post_columns = $post['columns'] ?? array();

	$length = '' !== $post_length ? (int) $post_length : DATATABLES_DEFAULT_PAGE_LENGTH;
	$length = max( DATATABLES_MIN_PAGE_LENGTH, min( $length, DATATABLES_MAX_PAGE_LENGTH ) );

	$order_col = isset( $post_order[0]['column'] )
		? (int) $post_order[0]['column']
		: DATATABLES_DEFAULT_ORDER_COLUMN;

	$order_dir = isset( $post_order[0]['dir'] )
		? sanitize_text_field( $post_order[0]['dir'] )
		: 'desc';

	$search = isset( $post_search['value'] )
		? sanitize_text_field( $post_search['value'] )
		: '';

	$col_searches = array();
	foreach ( $post_columns as $idx => $col ) {
		$val = isset( $col['search']['value'] )
			? sanitize_text_field( $col['search']['value'] )
			: '';
		if ( '' !== $val ) {
			$col_searches[ (int) $idx ] = $val;
		}
	}

	return array(
		'draw'         => '' !== $post_draw ? (int) $post_draw : 1,
		'start'        => '' !== $post_start ? (int) $post_start : 0,
		'length'       => $length,
		'order_col'    => $order_col,
		'order_dir'    => $order_dir,
		'search'       => $search,
		'col_searches' => $col_searches,
	);
}

/**
 * Shared DataTables server-side processing handler.
 *
 * @param string $nonce             AJAX nonce constant.
 * @param string $count_method      Database method for total count.
 * @param string $filtered_count_method Database method for filtered count.
 * @param string $page_method       Database method for page rows.
 */
function serve_table_data( $nonce, $count_method, $filtered_count_method, $page_method ) {
	if ( ! check_ajax_referer( $nonce, 'nonce', false ) ) {
		wp_send_json( array( 'error' => 'bad_nonce' ), HTTP_FORBIDDEN );
	}

	if ( ! current_user_can( 'view_simpleh5pstats_results' ) ) {
		wp_send_json( array( 'error' => 'forbidden' ), HTTP_FORBIDDEN );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$request = sanitize_table_data_request( wp_unslash( $_POST ) );

	$total    = Database::$count_method();
	$filtered = Database::$filtered_count_method( $request['search'], $request['col_searches'] );
	$rows     = Database::$page_method(
		$request['start'],
		$request['length'],
		$request['order_col'],
		$request['order_dir'],
		$request['search'],
		$request['col_searches']
	);

	$data = array();
	if ( $rows ) {
		foreach ( $rows as $row ) {
			$data[] = (array) $row;
		}
	}

	wp_send_json( array(
		'draw'            => $request['draw'],
		'recordsTotal'    => $total,
		'recordsFiltered' => $filtered,
		'data'            => $data,
	) );
}

/**
 * Return distinct column values for filter dropdowns.
 */
function get_column_options_data() {
	if ( ! check_ajax_referer( 'simpleh5pstats_nonce_get_table_data', 'nonce', false ) ) {
		wp_send_json_error( 'bad_nonce', HTTP_FORBIDDEN );
	}

	if ( ! current_user_can( 'view_simpleh5pstats_results' ) ) {
		wp_send_json_error( 'forbidden', HTTP_FORBIDDEN );
	}

	wp_send_json_success( Database::get_column_options() );
}

/**
 * Stream dataset as CSV download.
 */
function download_table_data() {
	if ( ! check_ajax_referer( 'simpleh5pstats_nonce_download_table_data', 'nonce', false ) ) {
		wp_die( esc_html__( 'Security check failed.', 'snordians-simple-h5p-stats' ) );
	}

	if ( ! current_user_can( 'download_simpleh5pstats_results' ) ) {
		wp_die( esc_html__( 'You do not have permission to download data.', 'snordians-simple-h5p-stats' ) );
	}

	$rows = Database::get_complete_table();

	$filename = 'simpleh5pstats-' . gmdate( 'Y-m-d' ) . '.csv';
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Cache-Control: no-cache, must-revalidate' );
	header( 'Pragma: public' );

	$output        = fopen( 'php://output', 'w' );
	$column_titles = Database::get_column_titles();
	$header_row    = array();
	foreach ( $column_titles as $title ) {
		$header_row[] = isset( Database::$column_title_names[ $title ] )
			? Database::$column_title_names[ $title ]
			: $title;
	}
	fputcsv( $output, $header_row );

	if ( $rows ) {
		foreach ( $rows as $row ) {
			fputcsv( $output, array_values( (array) $row ) );
		}
	}

	exit;
}

/**
 * Return distinct column values for aggregated filter dropdowns.
 */
function get_aggregated_column_options_data() {
	if ( ! check_ajax_referer( NONCE_GET_AGGREGATED_COLUMN_OPTIONS, 'nonce', false ) ) {
		wp_send_json_error( 'bad_nonce', HTTP_FORBIDDEN );
	}

	if ( ! current_user_can( 'view_simpleh5pstats_results' ) ) {
		wp_send_json_error( 'forbidden', HTTP_FORBIDDEN );
	}

	wp_send_json_success( Database::get_aggregated_column_options() );
}

/**
 * Stream aggregated dataset as CSV download.
 */
function download_aggregated_table_data() {
	if ( ! check_ajax_referer( NONCE_DOWNLOAD_AGGREGATED_TABLE_DATA, 'nonce', false ) ) {
		wp_die( esc_html__( 'Security check failed.', 'snordians-simple-h5p-stats' ) );
	}

	if ( ! current_user_can( 'download_simpleh5pstats_results' ) ) {
		wp_die( esc_html__( 'You do not have permission to download data.', 'snordians-simple-h5p-stats' ) );
	}

	$rows = Database::get_aggregated_complete_table();

	$filename = 'simpleh5pstats-aggregated-' . gmdate( 'Y-m-d' ) . '.csv';
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Cache-Control: no-cache, must-revalidate' );
	header( 'Pragma: public' );

	$output        = fopen( 'php://output', 'w' );
	$column_titles = Database::get_aggregated_column_titles();
	$header_row    = array();
	foreach ( $column_titles as $title ) {
		$header_row[] = isset( Database::$column_title_names[ $title ] )
			? Database::$column_title_names[ $title ]
			: $title;
	}
	fputcsv( $output, $header_row );

	if ( $rows ) {
		foreach ( $rows as $row ) {
			fputcsv( $output, array_values( (array) $row ) );
		}
	}

	exit;
}
