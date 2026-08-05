<?php

namespace SNORDIANSSIMPLEH5PSTATS;

// as suggested by the WordPress community
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Display and handle settings page
 *
 * @package SNORDIANSSIMPLEH5PSTATS
 */
class Options {

	/** @var string WordPress option name. */
	private static $option_slug = 'simpleh5pstats_option';
	/** @var array Cached option values. */
	private static $options;

	/**
	 * Register WordPress hooks for settings management.
	 */
	public function __construct() {
		add_filter( 'update_option_simpleh5pstats_option', array( $this, 'handle_options_update' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_scripts' ) );
		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'page_init' ) );
	}

	/**
	 * Enqueue admin scripts and styles for settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function add_scripts( $hook ) {
		if ( 'settings_page_simpleh5pstats-admin' !== $hook ) {
			return;
		}

		wp_register_script(
			'ConfirmationDialog',
			plugins_url( '/js/simpleh5pstats-confirmation-dialog.js', SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE ),
			array(),
			SNORDIANSSIMPLEH5PSTATS_VERSION,
			true
		);

		wp_register_script(
			'Options',
			plugins_url( '/js/simpleh5pstats-options.js', SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE ),
			array( 'ConfirmationDialog' ),
			SNORDIANSSIMPLEH5PSTATS_VERSION,
			true
		);

		wp_register_style(
			'ConfirmationDialog',
			plugins_url( '/styles/simpleh5pstats-confirmation-dialog.css', SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE ),
			array(),
			SNORDIANSSIMPLEH5PSTATS_VERSION
		);

		wp_enqueue_script( 'ConfirmationDialog' );
		wp_enqueue_style( 'ConfirmationDialog' );
		wp_enqueue_script( 'Options' );

		// Pass localization data to JavaScript.
		wp_localize_script(
			'Options',
			'simpleh5pstatsOptions',
			array(
				'l10n' => (object) array(
					'embedAllowedWarning' => esc_html__( 'Please note: Enabling this option may lead to unexpected hits (in high numbers) if others embed your content somewhere. But maybe that is what you want.', 'snordians-simple-h5p-stats' ),
					'embedAllowedCancel'  => esc_html__( 'Cancel', 'snordians-simple-h5p-stats' ),
					'embedAllowedConfirm' => esc_html__( 'Enable', 'snordians-simple-h5p-stats' ),
				),
			)
		);
	}

	/**
	 * Set default plugin options and version.
	 * Called on plugin activation.
	 */
	public static function set_defaults() {
		// Set version.
		update_option( 'snordians-simple-h5p-stats_version', SNORDIANSSIMPLEH5PSTATS_VERSION );

		if ( get_option( 'simpleh5pstats_defaults_set' ) ) {
			return; // No need to set defaults
		}

		// Remember that defaults have been set
		update_option( 'simpleh5pstats_defaults_set', true );

		// Store defaults
		update_option(
			self::$option_slug,
			array(
				'embed_supported' => 0,
				'unique_hits'     => 1,
			)
		);

		self::$options = array();
	}

	/**
	 * Delete all plugin options from database.
	 * Called on plugin uninstall.
	 */
	public static function delete_options() {
		delete_option( self::$option_slug );
		delete_site_option( self::$option_slug );
		delete_option( 'simpleh5pstats_defaults_set' );
		delete_option( 'snordians-simple-h5p-stats_version' );
	}

	/**
	 * Register WordPress Settings menu item.
	 */
	public function add_plugin_page() {
		// This page will be under "Settings"
		add_options_page(
			'Simple H5P Stats Settings',
			'Simple H5P Stats',
			'manage_options',
			'simpleh5pstats-admin',
			array( $this, 'create_admin_page' )
		);
	}

	/**
	 * Render settings page HTML.
	 */
	public function create_admin_page() {
		// Set class property
		?>
		<div class="wrap">
			<h2>Simple H5P Stats</h2>
			<form method="post" action="options.php">
				<?php
				// This prints out all hidden setting fields
				settings_fields( 'simpleh5pstats_option_group' );
				do_settings_sections( 'simpleh5pstats-admin' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register settings fields and sections for WordPress settings API.
	 */
	public function page_init() {
		register_setting(
			'simpleh5pstats_option_group',
			'simpleh5pstats_option',
			array( $this, 'sanitize' )
		);

		add_settings_section(
			'general_settings',
			'',
			array(),
			'simpleh5pstats-admin'
		);

		add_settings_field(
			'embed_supported',
			__( 'Embed support', 'snordians-simple-h5p-stats' ),
			array( $this, 'embed_supported_callback' ),
			'simpleh5pstats-admin',
			'general_settings'
		);

		add_settings_field(
			'unique_hits',
			__( 'Ignore repeated hits', 'snordians-simple-h5p-stats' ),
			array( $this, 'unique_hits_callback' ),
			'simpleh5pstats-admin',
			'general_settings'
		);

	}

	/**
	 * Sanitize each setting field as needed
	 *
	 * @param array $input Contains all settings fields as array keys
	 * @return array Output
	 */
	public function sanitize( $input ) {
		$new_input = array();
		if ( isset( $input['embed_supported'] ) ) {
			$new_input['embed_supported'] = absint( $input['embed_supported'] );
		}
		if ( isset( $input['unique_hits'] ) ) {
			$new_input['unique_hits'] = absint( $input['unique_hits'] );
		}

		return $new_input;
	}


	/**
	 * Render embed support checkbox field.
	 */
	public function embed_supported_callback() {
		?>
		<label for="embed_supported">
		<input
			type="checkbox"
			name="simpleh5pstats_option[embed_supported]"
			id="embed_supported"
			value="1"
			<?php
				echo isset( self::$options['embed_supported'] ) ?
					checked( '1', self::$options['embed_supported'], false ) :
					''
			?>
		/>
		<?php echo esc_html__( 'Count hits to your content that is embedded on other websites', 'snordians-simple-h5p-stats' ); ?>
		</label>
		<?php
	}

	/**
	 * Render unique hits tracking checkbox field.
	 */
	public function unique_hits_callback() {
		?>
		<label for="unique_hits">
		<input
			type="checkbox"
			name="simpleh5pstats_option[unique_hits]"
			id="unique_hits"
			value="1"
			<?php
				echo isset( self::$options['unique_hits'] ) ?
					checked( '1', self::$options['unique_hits'], false ) :
					''
			?>
		/>
		<?php echo esc_html__( 'Count only unique visitors per day', 'snordians-simple-h5p-stats' ); ?>
		</label>
		<?php
	}
	/**
	 * Update configuration file when settings change.
	 *
	 * @param array $old_values Previous settings values.
	 * @param array $new_values New settings values.
	 */
	function handle_options_update( $old_values, $new_values ) {
		self::update_config_file( $new_values );
	}

	/**
	 * Update dynamic config file
	 *
	 * @param array $new_values Contains all set settings fields as array keys
	 */
	public static function update_config_file( $new_values = null ) {
		if ( ! isset( $new_values ) ) {
			$new_values = self::$options;
		}

		$upload_dir = wp_upload_dir();
		$config_dir = $upload_dir['basedir'] . '/snordians-simple-h5p-stats';

		if ( ! is_dir( $config_dir ) ) {
			wp_mkdir_p( $config_dir );
		}

		$config_file = $config_dir . '/simpleh5pstats-config.js';

		$config_data  = '// Set environment variables' . "\n";
		$config_data .= 'window.SimpleH5PStats = {' . "\n";
		$config_data .= '  wpAJAXurl: \'' . admin_url( 'admin-ajax.php' ) . '\',' . "\n";
		$config_data .= '  verbAttempted: \'http://adlnet.gov/xapi/verb/attempted\',' . "\n";
		$config_data .= '};' . "\n";

		file_put_contents( $config_file, $config_data );
	}

	/**
	 * Get flag for embed supported.
	 * @return boolean True, if flag set.
	 */
	public static function is_embed_supported() {
		return isset( self::$options['embed_supported'] );
	}

	/**
	 * Get flag for unique hits tracking.
	 * @return boolean True, if flag set.
	 */
	public static function is_unique_hits_enabled() {
		return isset( self::$options['unique_hits'] ) && 1 === self::$options['unique_hits'];
	}

	/**
	 * Load plugin options from WordPress storage.
	 * Called once at plugin load time.
	 */
	static function init() {
		self::$options = get_option( self::$option_slug, false );
	}
}
Options::init();
