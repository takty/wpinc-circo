<?php
/**
 * Custom Search
 *
 * @package Sample
 * @author Takuto Yanagida
 * @version 2022-01-26
 */

namespace sample;

require_once __DIR__ . '/ref/custom-search.php';

/**
 * Adds post meta keys that are handled as searching target.
 *
 * @param string|string[] $key_s Post meta keys.
 */
function add_meta_key( $key_s ): void {
	\wpinc\ref\add_meta_key( $key_s );
}

/**
 * Adds post type specific pages.
 *
 * @param string          $slug        Search page slug.
 * @param string|string[] $post_type_s Post types.
 */
function add_post_type_specific_page( string $slug, $post_type_s ): void {
	\wpinc\ref\add_post_type_specific_page( $slug, $post_type_s );
}

/**
 * Activates the search.
 *
 * @param array $args {
 *     Arguments.
 *
 *     @type 'home_url_function'     Callable for getting home URL link. Default '\home_url'.
 *     @type 'target_post_types'     Target post types. Default empty (no limitation).
 *     @type 'do_allow_slash'        Whether do allow slash in query. Default true.
 *     @type 'do_target_post_meta'   Whether do search for post meta values. Default true.
 *     @type 'do_enable_blank_query' Whether do enable blank query. Default true.
 *     @type 'do_enable_custom_page' Whether do enable custom search page. Default false.
 *     @type 'do_extend_query'       Whether do extend query. Default false.
 * }
 */
function activate( array $args = array() ): void {
	\wpinc\ref\activate( $args );
}
