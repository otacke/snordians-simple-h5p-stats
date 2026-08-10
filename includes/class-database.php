<?php

namespace SNORDIANSSIMPLEH5PSTATS;

// as suggested by the WordPress community
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Database stuff
 *
 * @package SNORDIANSSIMPLEH5PSTATS
 */
class Database {
	/** @var string Hits table name (wp_simpleh5pstats_hits). */
	private static $table_hits;
	/** @var string Visitors table name (wp_simpleh5pstats_visitors). */
	private static $table_visitors;
	/** @var string H5P contents table name (wp_h5p_contents). */
	private static $table_h5p_content_types;

	/**
	 * Column title translations keyed by column name.
	 * Populated on admin_init for translation support.
	 * @var array
	 */
	public static $column_title_names;

	/**
	 * Build plugin's database tables (hits + visitors).
	 * Called on plugin activation and update.
	 */
	public static function build_tables() {
		global $wpdb;

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$charset_collate = $wpdb->get_charset_collate();

		$sql = 'CREATE TABLE ' . self::$table_hits . " (
			id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
			content_id INT(10) NOT NULL,
			date DATE NOT NULL,
			hits MEDIUMINT(9) NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			UNIQUE KEY content_date (content_id, date)
		) $charset_collate;";

		dbDelta( $sql );

		$sql = 'CREATE TABLE ' . self::$table_visitors . " (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			content_id INT(10) NOT NULL,
			date DATE NOT NULL,
			visitor_id VARCHAR(255) NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY content_date_visitor (content_id, date, visitor_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Delete all plugin database tables.
	 * Called on plugin uninstall.
	 */
	public static function delete_tables() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::$table_hits ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::$table_visitors ) );
	}

	/**
	 * Get column titles of all tables + additional columns.
	 * This function seems weird, but we possibly want to make data
	 * structure and retrieval process more flexible in future.
	 * @return array Database column titles.
	 */
	public static function get_column_titles() {
		return array(
			'content_id',
			'content_title',
			'date',
			'hits',
		);
	}

	/**
	 * Get WordPress user id for
	 * @param int $content_id H5P content id.
	 * @return (int|false) Number of rows affected/selected or false on error
	 */
	public static function get_content_author_id( $content_id ) {
		global $wpdb;

		$ok = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT con.user_id AS id FROM %i AS con WHERE con.id = %d',
				self::$table_h5p_content_types,
				$content_id
			)
		);

		if ( isset( $ok ) ) {
			return intval( $ok[0]->id );
		}

		return false;
	}

	/**
	 * Insert or increment hit for given content_id and date.
	 * If unique tracking enabled, also records visitor_id.
	 *
	 * @param int    $content_id  H5P content ID.
	 * @param string $visitor_id  Visitor identifier (null for anonymous/no dedup).
	 * @return int|false Inserted/updated row ID, or false on error.
	 */
	public static function insert_hit( $content_id, $visitor_id = '' ) {
		global $wpdb;

		$date = current_time( 'Y-m-d' );

		if ( '' !== $visitor_id && Options::is_unique_hits_enabled() ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM %i
				 WHERE content_id = %d AND date = %s AND visitor_id = %s
				 LIMIT 1',
				self::$table_visitors,
				$content_id,
				$date,
				$visitor_id
			) );

			if ( $exists ) {
				return;
			}
		}

		$wpdb->query( $wpdb->prepare(
			'INSERT INTO %i (content_id, date, hits)
			 VALUES (%d, %s, 1)
			 ON DUPLICATE KEY UPDATE hits = hits + 1',
			self::$table_hits,
			$content_id,
			$date
		) );

		if ( '' !== $visitor_id ) {
			$wpdb->query( $wpdb->prepare(
				'INSERT IGNORE INTO %i (content_id, date, visitor_id)
				 VALUES (%d, %s, %s)',
				self::$table_visitors,
				$content_id,
				$date,
				$visitor_id
			) );
		}

		return $wpdb->insert_id;
	}

	/**
	 * Clean visitor records older than current day.
	 * Called daily via WordPress cron.
	 */
	public static function clean_old_visitors() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare(
			'DELETE FROM %i WHERE date != %s',
			self::$table_visitors,
			current_time( 'Y-m-d' )
		) );
	}

	/**
	 * Get page of rows for DataTables server-side processing.
	 *
	 * @param int    $start         Row offset.
	 * @param int    $length        Page size.
	 * @param int    $order_col_idx Column index (0-based).
	 * @param string $order_dir     'asc' or 'desc'.
	 * @param string $search        Global search string.
	 * @param array  $col_searches  Map of column_index => search_value.
	 * @return array
	 */
	public static function get_table_page( $start, $length, $order_col_idx, $order_dir, $search, $col_searches ) {
		global $wpdb;

		$column_map = self::get_column_sql_map();
		$order_col  = isset( $column_map[ (int) $order_col_idx ] )
			? $column_map[ (int) $order_col_idx ]
			: 'mst.date';
		$order_dir  = ( 'asc' === strtolower( $order_dir ) ) ? 'ASC' : 'DESC';

		$extra  = self::build_search_where( $search, $col_searches );
		$params = array_merge(
			array( self::$table_hits, self::$table_h5p_content_types ),
			$extra['params'],
			array( (int) $start, (int) $length )
		);

		// $extra['where'] only ever contains whitelisted column names and %s/%d placeholders (never raw values);
		// build_search_where() supplies the matching values via $extra['params'], and $params length is derived from it.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT
				     mst.content_id, cnt.title AS content_title, mst.date, mst.hits
				   FROM
				     %i AS mst
				   LEFT JOIN
				     %i AS cnt ON mst.content_id = cnt.id'
			. $extra['where'] .
			' ORDER BY ' . $order_col . ' ' . $order_dir .
			' LIMIT %d, %d',
			...$params
		) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Total row count (no search filters).
	 * Used as DataTables recordsTotal.
	 *
	 * @return int
	 */
	public static function get_table_count() {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::$table_hits ) );
	}

	/**
	 * Row count after applying search filters.
	 * Used as DataTables recordsFiltered.
	 *
	 * @param string $search       Global search string.
	 * @param array  $col_searches Map of column_index => search_value.
	 * @return int
	 */
	public static function get_filtered_count( $search, $col_searches ) {
		global $wpdb;

		$extra  = self::build_search_where( $search, $col_searches );
		$params = array_merge( array( self::$table_hits, self::$table_h5p_content_types ), $extra['params'] );

		// $extra['where'] only ever contains whitelisted column names and %s/%d placeholders (never raw values);
		// build_search_where() supplies the matching values via $extra['params'], and $params length is derived from it.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*)
				   FROM
				     %i AS mst
				   LEFT JOIN
				     %i AS cnt ON mst.content_id = cnt.id'
			. $extra['where'],
			...$params
		) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Whitelist mapping column index (0-based) to SQL alias used in
	 * two-table JOIN. Only strings from this array can reach ORDER BY / WHERE.
	 */
	private static function get_column_sql_map() {
		return array(
			0 => 'mst.content_id',
			1 => 'cnt.title',
			2 => 'mst.date',
			3 => 'mst.hits',
		);
	}

	/**
	 * Build extra WHERE clauses and matching param array for global
	 * search term and optional per-column exact-match filters.
	 *
	 * @param string $search        Global search string (empty = skip).
	 * @param array  $col_searches  Map of column_index => search_value.
	 * @return array { where: string, params: array }
	 */
	private static function build_search_where( $search, $col_searches ) {
		global $wpdb;

		$column_map = self::get_column_sql_map();
		$conditions = array();
		$params     = array();

		if ( '' !== (string) $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses = array();
			foreach ( $column_map as $col ) {
				$clauses[] = $col . ' LIKE %s';
				$params[]  = $like;
			}
			$conditions[] = '(' . implode( ' OR ', $clauses ) . ')';
		}

		foreach ( $col_searches as $col_index => $col_search ) {
			if ( '' !== (string) $col_search && isset( $column_map[ (int) $col_index ] ) ) {
				$conditions[] = $column_map[ (int) $col_index ] . ' = %s';
				$params[]     = $col_search;
			}
		}

		$where = empty( $conditions )
			? ''
			: ' WHERE ' . implode( ' AND ', $conditions );

		return array(
			'where'  => $where,
			'params' => $params,
		);
	}

	/**
	 * Distinct values for filterable columns (content_title, date).
	 * Used to populate filter dropdowns.
	 *
	 * @return array  Indexed by column index (1, 2), values are string arrays.
	 */
	public static function get_column_options() {
		global $wpdb;

		$filterable_indices = array( 1, 2 ); // content_title, date.
		$column_map         = array_intersect_key(
			self::get_column_sql_map(),
			array_flip( $filterable_indices )
		);
		$options            = array();

		foreach ( $column_map as $index => $col ) {
			$results = $wpdb->get_results( $wpdb->prepare(
				'SELECT DISTINCT %i AS val
					 FROM
					   %i AS mst
					LEFT JOIN
					  %i AS cnt ON mst.content_id = cnt.id
					ORDER BY val
					LIMIT 500',
				$col,
				self::$table_hits,
				self::$table_h5p_content_types
			) );

			$options[ $index ] = array_values(
				array_map(
					function( $row ) {
						return (string) $row->val; },
					$results ? $results : array()
				)
			);
		}

		return $options;
	}

	/**
	 * Get column titles for aggregated (grouped) view.
	 * @return array Column titles.
	 */
	public static function get_aggregated_column_titles() {
		return array(
			'content_id',
			'content_title',
			'total_hits',
		);
	}

	/**
	 * Whitelist mapping column index (0-based) to SQL alias used in
	 * aggregated query. Only strings from this array can reach ORDER BY / WHERE.
	 */
	private static function get_aggregated_column_sql_map() {
		return array(
			0 => 'mst.content_id',
			1 => 'cnt.title',
			2 => 'SUM(mst.hits)',
		);
	}

	/**
	 * Get page of aggregated rows for DataTables server-side processing.
	 * Rows are grouped by content_id with hits summed across all dates.
	 *
	 * @param int    $start         Row offset.
	 * @param int    $length        Page size.
	 * @param int    $order_col_idx Column index (0-based).
	 * @param string $order_dir     'asc' or 'desc'.
	 * @param string $search        Global search string.
	 * @param array  $col_searches  Map of column_index => search_value.
	 * @return array
	 */
	public static function get_aggregated_table_page( $start, $length, $order_col_idx, $order_dir, $search, $col_searches ) {
		global $wpdb;

		$column_map = self::get_aggregated_column_sql_map();
		$order_col  = isset( $column_map[ (int) $order_col_idx ] )
			? $column_map[ (int) $order_col_idx ]
			: 'cnt.title';
		$order_dir  = ( 'asc' === strtolower( $order_dir ) ) ? 'ASC' : 'DESC';

		$extra  = self::build_aggregated_search_where( $search, $col_searches );
		$params = array_merge(
			array( self::$table_hits, self::$table_h5p_content_types ),
			$extra['params'],
			$extra['having_params'],
			array( (int) $start, (int) $length )
		);

		// $extra['where']/$extra['having'] only ever contain whitelisted column names and %s/%d placeholders (never raw values);
		// build_aggregated_search_where() supplies the matching values via $extra['params']/$extra['having_params'], and $params length is derived from them.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT mst.content_id, cnt.title AS content_title, SUM(mst.hits) AS total_hits
				 FROM %i AS mst
				LEFT JOIN %i AS cnt ON mst.content_id = cnt.id'
			 . $extra['where'] .
			 ' GROUP BY mst.content_id, cnt.title' .
			 ( '' !== $extra['having'] ? $extra['having'] : '' ) .
			 ' ORDER BY ' . $order_col . ' ' . $order_dir .
			 ' LIMIT %d, %d',
			...$params
		) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Total row count (no search filters) for aggregated view.
	 * Used as DataTables recordsTotal.
	 *
	 * @return int
	 */
	public static function get_aggregated_table_count() {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT mst.content_id) FROM %i AS mst', self::$table_hits ) );
	}

	/**
	 * Row count after applying search filters for aggregated view.
	 * Used as DataTables recordsFiltered.
	 *
	 * @param string $search       Global search string.
	 * @param array  $col_searches Map of column_index => search_value.
	 * @return int
	 */
	public static function get_aggregated_filtered_count( $search, $col_searches ) {
		global $wpdb;

		$extra  = self::build_aggregated_search_where( $search, $col_searches );
		$params = array_merge(
			array( self::$table_hits, self::$table_h5p_content_types ),
			$extra['params'],
			$extra['having_params']
		);

		// $extra['where']/$extra['having'] only ever contain whitelisted column names and %s/%d placeholders (never raw values);
		// build_aggregated_search_where() supplies the matching values via $extra['params']/$extra['having_params'], and $params length is derived from them.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(DISTINCT mst.content_id)
			   FROM
			     %i AS mst
			   LEFT JOIN
			     %i AS cnt ON mst.content_id = cnt.id'
			. $extra['where'] .
			( '' !== $extra['having'] ? $extra['having'] : '' ),
			...$params
		) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Build extra WHERE/HAVING clauses and matching param array for
	 * aggregated query. Global search matches content_id and content_title only.
	 * Column 2 (total_hits) filter uses HAVING since aggregate.
	 *
	 * @param string $search        Global search string (empty = skip).
	 * @param array  $col_searches  Map of column_index => search_value.
	 * @return array { where: string, params: array, having: string, having_params: array }
	 */
	private static function build_aggregated_search_where( $search, $col_searches ) {
		global $wpdb;

		$column_map = self::get_aggregated_column_sql_map();
		$conditions = array();
		$params     = array();
		$having     = array();
		$havingParams = array();

		if ( '' !== (string) $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			// Only search content_id and content_title (indices 0, 1), NOT the SUM(hits) aggregate.
			$clauses = array(
				'mst.content_id LIKE %s',
				'cnt.title LIKE %s',
			);
			$params  = array( $like, $like );
			$conditions[] = '(' . implode( ' OR ', $clauses ) . ')';
		}

		foreach ( $col_searches as $col_index => $col_search ) {
			if ( '' !== (string) $col_search && isset( $column_map[ (int) $col_index ] ) ) {
				// For column index 2 (total_hits), use HAVING since it is an aggregate.
				if ( 2 === (int) $col_index ) {
					$having[] = 'SUM(mst.hits) = %d';
					$havingParams[] = $col_search;
				} else {
					$conditions[] = $column_map[ (int) $col_index ] . ' = %s';
					$params[] = $col_search;
				}
			}
		}

		$where  = empty( $conditions )
			? ''
			: ' WHERE ' . implode( ' AND ', $conditions );
		$having = empty( $having )
			? ''
			: ' HAVING ' . implode( ' AND ', $having );

		return array(
			'where'          => $where,
			'params'         => $params,
			'having'         => $having,
			'having_params'  => $havingParams,
		);
	}

	/**
	 * Distinct values for filterable column in aggregated view (content_title).
	 * Used to populate filter dropdowns.
	 *
	 * @return array  Indexed by column index (1), values are string arrays.
	 */
	public static function get_aggregated_column_options() {
		global $wpdb;

		$options = array();

		// Column 1: content_title (distinct titles from joined table).
			$results = $wpdb->get_results( $wpdb->prepare(
				'SELECT DISTINCT cnt.title AS val
					 FROM
					   %i AS mst
					LEFT JOIN
					  %i AS cnt ON mst.content_id = cnt.id
					ORDER BY val
					LIMIT 500',
				self::$table_hits,
				self::$table_h5p_content_types
			) );
		$options[1] = array_values(
			array_map(
				function( $row ) {
					return (string) $row->val;
				},
				$results ? $results : array()
			)
		);

		return $options;
	}


	/**
	 * Get complete overview of all aggregated data for CSV download.
	 * @return object Database results.
	 */
	public static function get_aggregated_complete_table() {
		global $wpdb;

			return $wpdb->get_results( $wpdb->prepare(
				'SELECT mst.content_id, cnt.title AS content_title, SUM(mst.hits) AS total_hits
					FROM
					  %i AS mst
					LEFT JOIN
					  %i AS cnt ON mst.content_id = cnt.id
					GROUP BY mst.content_id, cnt.title
					ORDER BY cnt.title',
				self::$table_hits,
				self::$table_h5p_content_types
			) );
	}

	/**
	 * Get complete overview of all stored data.
	 * @return object Database results.
	 */
	public static function get_complete_table() {
		global $wpdb;

			return $wpdb->get_results( $wpdb->prepare(
				'SELECT mst.content_id, cnt.title AS content_title, mst.date, mst.hits
					FROM
					  %i AS mst
					LEFT JOIN
					  %i AS cnt ON mst.content_id = cnt.id
					ORDER BY cnt.title',
				self::$table_hits,
				self::$table_h5p_content_types
			) );
	}

	/**
	 * Get list of all H5P content types in database.
	 * @return array Database results.
	 */
	public static function get_h5p_content_types() {
		global $wpdb;

		// Stop if H5P doesn't seem to be installed, checked via two database tables.
		$ok = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				self::$table_h5p_content_types
			)
		);
		if ( 0 === sizeof( $ok ) ) {
			return array();
		}
		$ok = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				self::$table_h5p_libraries
			)
		);
		if ( 0 === sizeof( $ok ) ) {
			return array();
		}

		// Get ID, title and library name
			$content_types = $wpdb->get_results( $wpdb->prepare(
				'SELECT CT.id AS ct_id, CT.title AS ct_title, LIB.title AS lib_title
					FROM
					  %i AS CT,
					  %i AS LIB
					WHERE CT.library_id = LIB.id',
				self::$table_h5p_content_types,
				self::$table_h5p_libraries
			) );

		return json_decode( json_encode( $content_types ), true );
	}

	/**
	 * Set names for columns including translations.
	 */
	static function set_column_names() {
		// Those might become handy if we make make the SELECTs flexible.
		self::$column_title_names = array(
			'content_id'    => esc_html__( 'Content ID', 'snordians-simple-h5p-stats' ),
			'content_title' => esc_html__( 'Content Title', 'snordians-simple-h5p-stats' ),
			'date'          => esc_html__( 'Date', 'snordians-simple-h5p-stats' ),
			'hits'          => esc_html__( 'Hits', 'snordians-simple-h5p-stats' ),
			'total_hits'    => esc_html__( 'Total Hits', 'snordians-simple-h5p-stats' ),
		);
	}

	/**
	 * Initialize class variables with actual table names.
	 * Called once at plugin load time.
	 */
	static function init() {
		global $wpdb;
		self::$table_hits            = $wpdb->prefix . 'simpleh5pstats_hits';
		self::$table_visitors        = $wpdb->prefix . 'simpleh5pstats_visitors';
		self::$table_h5p_content_types = $wpdb->prefix . 'h5p_contents';
	}

	/**
	 * Get content ID by slug from H5P contents table.
	 *
	 * @param string $slug Content slug.
	 * @return int|false Content ID, or false if not found.
	 */
	public static function get_content_id_by_slug( $slug ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE slug = %s',
				self::$table_h5p_content_types,
				$slug
			)
		);

		if ( null === $result ) {
			return false;
		}

		return intval( $result );
	}
}

Database::init();

// This is neccessary for the translation to work from within an array.
add_action( 'admin_init', 'SNORDIANSSIMPLEH5PSTATS\Database::set_column_names' );
