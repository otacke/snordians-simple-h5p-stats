<?php

namespace SNORDIANSSIMPLEH5PSTATS;

// as suggested by the WordPress community
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Handles decisions about injecting H5P listener scripts.
 *
 * @package SNORDIANSSIMPLEH5PSTATS
 */
class H5P_Script_Handler {

	/**
	 * Check if the request is for embedded H5P content.
	 *
	 * @param string $request_uri The request URI.
	 * @return bool True if embedded.
	 */
	public static function is_embedded( $request_uri ) {
		return false !== strpos( $request_uri, 'action=h5p_embed' );
	}

	/**
	 * Check if admin is viewing H5P content in the backend.
	 *
	 * @param string $request_uri The request URI.
	 * @return bool True if admin H5P view.
	 */
	public static function is_admin_h5p_view( $request_uri ) {
		return false !== strpos( $request_uri, 'page=h5p' )
			&& false !== strpos( $request_uri, 'task=show' );
	}

	/**
	 * Check if admin is editing a post/page with embedded H5P content.
	 *
	 * @param string|null $http_referrer The HTTP referrer.
	 * @return bool True if admin editing post.
	 */
	public static function is_admin_editing_post( $http_referrer ) {
		return isset( $http_referrer )
			&& false !== strpos( $http_referrer, 'action=edit' );
	}

	/**
	 * Check if iframe call is from the same origin.
	 *
	 * @param string|null $sec_fetch_site The SEC-Fetch-Site header value.
	 * @return bool True if same origin.
	 */
	public static function is_same_origin( $sec_fetch_site ) {
		return isset( $sec_fetch_site ) && 'same-origin' === $sec_fetch_site;
	}

	/**
	 * Determine whether to skip adding the listener due to admin access.
	 *
	 * @param bool $is_admin_h5p_view Whether admin is viewing H5P in backend.
	 * @param bool $is_admin_post_iframe Whether admin is editing a post.
	 * @return bool True if should skip.
	 */
	public static function should_skip_admin_access( $is_admin_h5p_view, $is_admin_post_iframe ) {
		return $is_admin_h5p_view || $is_admin_post_iframe;
	}

	/**
	 * Determine whether to skip embedding from external sources.
	 *
	 * @param bool $is_embed Whether content is embedded.
	 * @param bool $is_same_origin Whether iframe is from same origin.
	 * @return bool True if should skip.
	 */
	public static function should_skip_external_embeds( $is_embed, $is_same_origin ) {
		return ! Options::is_embed_supported() && ! $is_same_origin && $is_embed;
	}

	/**
	 * Find the H5P content ID from the request URI or referrer.
	 *
	 * @param string $request_uri The request URI.
	 * @param string|null $http_referrer The HTTP referrer.
	 * @param bool $is_embed Whether the request is for embedded content.
	 * @return int|null Content ID, or null if not found.
	 */
	public static function find_content_id( $request_uri, $http_referrer, $is_embed ) {
		$components = null;

		if ( isset( $http_referrer ) && false !== strpos( $http_referrer, 'task=show' ) ) {
			$components = wp_parse_url( $http_referrer );
		} elseif ( $is_embed ) {
			$components = wp_parse_url( $request_uri );
		}

		if ( ! isset( $components ) || ! isset( $components['query'] ) ) {
			return null;
		}

		return array_reduce(
			explode( '&', $components['query'] ),
			function ( $id, $query ) {
				if ( '' !== $id ) {
					return $id;
				}

				$split = explode( '=', $query );
				if ( 'id' === $split[0] && isset( $split[1] ) ) {
					return intval( $split[1] );
				}

				if ( 'slug' === $split[0] && isset( $split[1] ) ) {
					$found = Database::get_content_id_by_slug( $split[1] );
					if ( false !== $found ) {
						return $found;
					}
				}

				return '';
			},
			''
		) ?: null;
	}

	/**
	 * Check whether the current user is the author of the given content.
	 *
	 * @param int|null $content_id The content ID.
	 * @return bool True if user is the author.
	 */
	public static function is_content_author( $content_id ) {
		if ( null === $content_id ) {
			return false;
		}

		return Database::get_content_author_id( $content_id ) === get_current_user_id();
	}

	/**
	 * Add JavaScript listener scripts to H5P.
	 *
	 * @param object &$scripts List of JavaScripts to modify.
	 */
	public static function add_scripts( &$scripts ) {
		$upload_dir = wp_upload_dir();
		$path = $upload_dir['basedir'] . '/snordians-simple-h5p-stats/simpleh5pstats-config.js';

		if ( file_exists( $path ) ) {
			$scripts[] = (object) array(
				'path'    => $upload_dir['baseurl'] . '/snordians-simple-h5p-stats/simpleh5pstats-config.js',
				'version' => '?buster=' . uniqid(),
			);
		}

		// /!\ Adding nonce here is workaround, because wp_localize_script cannot be used here. Will appear in server log!
		$scripts[] = (object) array(
			'path'    => plugins_url( 'js/simpleh5pstats-listener.js', SNORDIANSSIMPLEH5PSTATS_PLUGIN_FILE ),
			'version' => '?ver=' . SNORDIANSSIMPLEH5PSTATS_VERSION . '&nonce=' . wp_create_nonce( 'simpleh5pstats_nonce_insert_data' ),
		);
	}
}
