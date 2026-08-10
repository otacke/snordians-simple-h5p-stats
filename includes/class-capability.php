<?php
/**
 * Capability management for SNORDIAN's Simple H5P Stats.
 *
 * @package SNORDIANSSIMPLEH5PSTATS
 */

namespace SNORDIANSSIMPLEH5PSTATS;

// as suggested by the WordPress community.
defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

/**
 * Class for managing capabilities.
 */
class Capability {

	/**
	 * Capability for managing plugin options.
	 */
	const CAPABILITY_MANAGE_OPTIONS = 'manage_simpleh5pstats_options';

	/**
	 * Capability for viewing plugin results.
	 */
	const CAPABILITY_VIEW_RESULTS = 'view_simpleh5pstats_results';

	/**
	 * Capability for downloading plugin results.
	 */
	const CAPABILITY_DOWNLOAD_RESULTS = 'download_simpleh5pstats_results';

	/**
	 * Capability for deleting plugin results.
	 */
	const CAPABILITY_DELETE_RESULTS = 'delete_simpleh5pstats_results';

	/**
	 * WordPress capability that new capabilities are mapped to.
	 */
	const MAPPED_CAPABILITY = 'manage_options';

	/**
	 * List of all plugin-specific capabilities.
	 */
	private static array $capabilities = array(
		self::CAPABILITY_MANAGE_OPTIONS,
		self::CAPABILITY_VIEW_RESULTS,
		self::CAPABILITY_DOWNLOAD_RESULTS,
		self::CAPABILITY_DELETE_RESULTS,
	);

	/**
	 * Add default capabilities.
	 */
	public static function add_capabilities() {
		global $wp_roles;

		$all_roles = $wp_roles->roles;
		foreach ( $all_roles as $role_name => $role_info ) {
			$role = get_role( $role_name );

			foreach ( self::$capabilities as $new_cap ) {
				self::map_capability( $role, $role_info, self::MAPPED_CAPABILITY, $new_cap );
			}
		}
	}

	/**
	 * Remove default capabilities.
	 */
	public static function remove_capabilities() {
		global $wp_roles;

		$all_roles = $wp_roles->roles;
		foreach ( $all_roles as $role_name => $role_info ) {
			$role = get_role( $role_name );

			foreach ( self::$capabilities as $new_cap ) {
				if ( isset( $role_info['capabilities'][ $new_cap ] ) ) {
					$role->remove_cap( $new_cap );
				}
			}
		}
	}

	/**
	 * Make sure that a role has or hasn't the provided capability depending on existing roles.
	 *
	 * @param object $role      Role object.
	 * @param array  $role_info Role information.
	 * @param string $existing_cap Existing capability.
	 * @param string $new_cap    New capability.
	 */
	private static function map_capability( $role, $role_info, $existing_cap, $new_cap ) {
		if ( $role->has_cap( $new_cap ) ) {
			// Already has new cap…

			if ( ! $role->has_cap( $existing_cap ) ) {
				// But shouldn't have it!
				$role->remove_cap( $new_cap );
			}
		} else {
			// Doesn't have new cap…
			if ( $role->has_cap( $existing_cap ) ) {
				// But should have it!
				$role->add_cap( $new_cap );
			}
		}
	}
}
