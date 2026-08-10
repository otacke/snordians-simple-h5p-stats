<?php

namespace SNORDIANSSIMPLEH5PSTATS;

use SNORDIANSSIMPLEH5PSTATS\Capability;

// as suggested by the WordPress community
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Display and handle settings page
 *
 * @package SNORDIANSSIMPLEH5PSTATS
  */
class Table_View {
	/** @var string DataTable container ID. */
	private $class_datatable = 'simpleh5pstats-data-table';
	/** @var string DataTable container ID for aggregated view. */
	private $class_datatable_aggregated = 'simpleh5pstats-aggregated-table';

	/** @var string Admin menu icon (base64 SVG). */
	private $menu_icon;

	/**
	 * Register WordPress hooks for stats table admin page.
	 */
	public function __construct() {
		// Only register hooks when on the stats page.
		if ( 'toplevel_page_simpleh5pstats_options' !== ( $_GET['page'] ?? '' ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'add_scripts' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register admin menu item.
	 */
	public function register_menu() {
		add_menu_page( 'Simple H5P Stats', 'Simple H5P Stats', Capability::CAPABILITY_VIEW_RESULTS, 'simpleh5pstats_options', array( $this, 'add_plugin_page' ), $this->menu_icon );
	}

	/**
	 * Enqueue DataTables scripts and styles for stats page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function add_scripts( $hook ) {
		if ( 'toplevel_page_simpleh5pstats_options' !== $hook ) {
			return;
		}

		wp_register_script(
			'SimpleH5PStatsConfirmationDialog',
			plugins_url( '/js/simpleh5pstats-confirmation-dialog.js', SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE ),
			array(),
			SNORDIANSSIMPLEH5PSTATS_VERSION,
			true
		);

		wp_register_script(
			'BuildSimpleH5PStatsTable',
			plugins_url( '/js/simpleh5pstats-table-view.js', SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE ),
			array( 'SimpleH5PStatsConfirmationDialog' ),
			SNORDIANSSIMPLEH5PSTATS_VERSION,
			true
		);

		wp_register_style(
			'SimpleH5PStatsConfirmationDialogStyle',
			plugins_url( '/styles/simpleh5pstats-confirmation-dialog.css', SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE ),
			array(),
			SNORDIANSSIMPLEH5PSTATS_VERSION
		);

		wp_register_style(
			'SimpleH5PStatsTableViewStyle',
			plugins_url( '/styles/simpleh5pstats-table-view.css', SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE ),
			array(),
			SNORDIANSSIMPLEH5PSTATS_VERSION
		);

		wp_enqueue_script( 'BuildSimpleH5PStatsTable' );
		wp_enqueue_style( 'SimpleH5PStatsConfirmationDialogStyle' );
		wp_enqueue_style( 'SimpleH5PStatsTableViewStyle' );

		// Used to allow translations for Datatables from within WordPress translations
		$language_datatables = array(
			'info'           => esc_html__( 'Showing _START_ to _END_ of _TOTAL_ entries', 'snordians-simple-h5p-stats' ),
			'infoEmpty'      => esc_html__( 'Showing 0 entries', 'snordians-simple-h5p-stats' ),
			'infoFiltered'   => esc_html__( 'filtered from _MAX_ total entries', 'snordians-simple-h5p-stats' ),
			'lengthMenu'     => esc_html__( 'Show _MENU_ entries', 'snordians-simple-h5p-stats' ),
			'loadingRecords' => esc_html__( 'Loading...', 'snordians-simple-h5p-stats' ),
			'processing'     => esc_html__( 'Processing...', 'snordians-simple-h5p-stats' ),
			'search'         => esc_html__( 'Search', 'snordians-simple-h5p-stats' ),
			'zeroRecords'    => esc_html__( 'No matching records found', 'snordians-simple-h5p-stats' ),
			'paginate'       => array(
				'first'    => esc_html__( 'First', 'snordians-simple-h5p-stats' ),
				'last'     => esc_html__( 'Last', 'snordians-simple-h5p-stats' ),
				'next'     => esc_html__( 'Next', 'snordians-simple-h5p-stats' ),
				'previous' => esc_html__( 'Previous', 'snordians-simple-h5p-stats' ),
			),
		);

		// pass variables to JavaScript
		wp_localize_script(
			'BuildSimpleH5PStatsTable',
			'simpleh5pstatsByDateDataTable',
			array(
				'classDataTable'              => $this->class_datatable,
				'buttonLabelDownload'         => esc_html__( 'Download', 'snordians-simple-h5p-stats' ),
				'userCanDownloadResults'      => current_user_can( Capability::CAPABILITY_DOWNLOAD_RESULTS ) ? '1' : '0',
				'userCanDeleteResults'        => current_user_can( Capability::CAPABILITY_DELETE_RESULTS ) ? '1' : '0',
				'languageData'                => $language_datatables,
				'buttonLabelDelete'           => esc_html__( 'Delete', 'snordians-simple-h5p-stats' ),
				'dialogTextDelete'            => esc_html__( 'Do you really want to delete all the data?', 'snordians-simple-h5p-stats' ),
				'dialogCancelLabel'           => esc_html__( 'Cancel', 'snordians-simple-h5p-stats' ),
				'dialogConfirmLabel'          => esc_html__( 'OK', 'snordians-simple-h5p-stats' ),
				'errorMessage'                => esc_html__( 'Sorry, something went wrong with deleting the data.', 'snordians-simple-h5p-stats' ),
				'wpAJAXurl'                   => admin_url( 'admin-ajax.php' ),
				'nonce'                       => wp_create_nonce( 'simpleh5pstats_nonce_delete_data' ),
				'nonceGetTableData'           => wp_create_nonce( 'simpleh5pstats_nonce_get_table_data' ),
				'nonceGetColumnOptions'       => wp_create_nonce( 'simpleh5pstats_nonce_get_column_options' ),
				'nonceDownloadTableData'      => wp_create_nonce( 'simpleh5pstats_nonce_download_table_data' ),
				'columnNames'                 => array(
					Database::$column_title_names['content_id']    ?? 'H5P Content ID',
					Database::$column_title_names['content_title'] ?? 'Content Title',
					Database::$column_title_names['date']          ?? 'Date',
					Database::$column_title_names['hits']          ?? 'Hits',
				),
				'defaultOrderColumn'          => DATATABLES_DEFAULT_ORDER_COLUMN,
			)
		);

		// Localization data for the aggregated table.
		wp_localize_script(
			'BuildSimpleH5PStatsTable',
			'simpleh5pstatsAggregatedDataTable',
			array(
				'classDataTable'              => $this->class_datatable_aggregated,
				'buttonLabelDownload'         => esc_html__( 'Download', 'snordians-simple-h5p-stats' ),
				'userCanDownloadResults'      => current_user_can( Capability::CAPABILITY_DOWNLOAD_RESULTS ) ? '1' : '0',
				'languageData'                => $language_datatables,
				'columnNames'                 => array(
					Database::$column_title_names['content_id']    ?? 'Content ID',
					Database::$column_title_names['content_title'] ?? 'Content Title',
					Database::$column_title_names['total_hits']    ?? 'Total Hits',
				),
				'defaultOrderColumn'          => DATATABLES_DEFAULT_ORDER_COLUMN,
				'wpAJAXurl'                   => admin_url( 'admin-ajax.php' ),
				'nonceGetTableData'           => wp_create_nonce( 'simpleh5pstats_nonce_get_aggregated_table_data' ),
				'nonceGetColumnOptions'       => wp_create_nonce( 'simpleh5pstats_nonce_get_aggregated_column_options' ),
				'nonceDownloadTableData'      => wp_create_nonce( 'simpleh5pstats_nonce_download_aggregated_table_data' ),
				'userCanDeleteResults'        => current_user_can( Capability::CAPABILITY_DELETE_RESULTS ) ? '1' : '0',
				'buttonLabelDelete'           => esc_html__( 'Delete', 'snordians-simple-h5p-stats' ),
				'dialogTextDelete'            => esc_html__( 'Do you really want to delete all the data?', 'snordians-simple-h5p-stats' ),
				'dialogCancelLabel'           => esc_html__( 'Cancel', 'snordians-simple-h5p-stats' ),
				'dialogConfirmLabel'          => esc_html__( 'OK', 'snordians-simple-h5p-stats' ),
				'nonce'                       => wp_create_nonce( 'simpleh5pstats_nonce_delete_data' ),
				'actionDownload'              => 'simpleh5pstats_download_aggregated_table_data',
			)
		);
	}

	/**
	 * Render stats table admin page.
	 */
	public function add_plugin_page() {
		if ( ! current_user_can( Capability::CAPABILITY_VIEW_RESULTS ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'snordians-simple-h5p-stats' ) );
		}

		$column_titles = Database::get_column_titles();
		$total_count   = Database::get_table_count();

		echo '<div class="wrap">';
		echo '<h2>' . esc_html__( 'Simple H5P Stats', 'snordians-simple-h5p-stats' ) . '</h2>';
		if ( 0 === $total_count ) {
			echo esc_html__( 'There is no hit data stored.', 'snordians-simple-h5p-stats' );
			wp_die( '', 200 );
		}

		// Use DataTable with server-side processing; tbody is populated via AJAX.

		echo '<h3>' . esc_html__( 'Aggregated', 'snordians-simple-h5p-stats' ) . '</h3>';
		// Aggregated table (rendered first, above the detailed table).
		$aggregated_column_titles = Database::get_aggregated_column_titles();
		echo '<div><table id="' . esc_attr( $this->class_datatable_aggregated ) . '" class="display">';
		echo '<thead><tr>';
		for ( $i = 0; $i < count( $aggregated_column_titles ); $i++ ) {
			echo '<th>' . esc_html(
				isset( Database::$column_title_names[ $aggregated_column_titles[ $i ] ] )
					? Database::$column_title_names[ $aggregated_column_titles[ $i ] ]
					: ''
			) . '</th>';
		}
		echo '</tr></thead>';
		echo '<tfoot><tr>';
		for ( $i = 0; $i < count( $aggregated_column_titles ); $i++ ) {
			echo '<th>' . esc_html(
				isset( Database::$column_title_names[ $aggregated_column_titles[ $i ] ] )
					? Database::$column_title_names[ $aggregated_column_titles[ $i ] ]
					: ''
			) . '</th>';
		}
		echo '</tr></tfoot>';
		echo '<tbody></tbody>';
		echo '</table></div>';

		echo '<hr class="table-separator" />';

		echo '<h3>' . esc_html__( 'Hits by date', 'snordians-simple-h5p-stats' ) . '</h3>';
		echo '<div><table id="' . esc_attr( $this->class_datatable ) . '" class="display">';

		// Table Head and Footer (column headers only — no row data here)
		echo '<thead><tr>';
		for ( $i = 0; $i < count( $column_titles ); $i++ ) {
			echo '<th>' . esc_html(
				isset( Database::$column_title_names[ $column_titles[ $i ] ] )
					? Database::$column_title_names[ $column_titles[ $i ] ]
					: ''
			) . '</th>';
		}
		echo '</tr></thead>';

		echo '<tfoot><tr>';
		for ( $i = 0; $i < count( $column_titles ); $i++ ) {
			echo '<th>' . esc_html(
				isset( Database::$column_title_names[ $column_titles[ $i ] ] )
					? Database::$column_title_names[ $column_titles[ $i ] ]
					: ''
			) . '</th>';
		}
		echo '</tr></tfoot>';

		// Empty tbody — DataTables fills it via server-side AJAX
		echo '<tbody></tbody>';

		echo '</table></div>';
		echo '</div>';
	}
}
