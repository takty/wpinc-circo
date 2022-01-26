<?php
/**
 * Custom Search
 *
 * @package Wpinc Ref
 * @author Takuto Yanagida
 * @version 2022-01-25
 */

namespace wpinc\ref;

require_once __DIR__ . '/query-extender.php';

/**
 * Adds post meta keys that are handled as searching target.
 *
 * @param string|string[] $key_s Post meta keys.
 */
function add_meta_key( $key_s ): void {
	$inst = _get_instance();
	$ks   = is_array( $key_s ) ? $key_s : array( $key_s );

	$inst->meta_keys = array_merge( $inst->meta_keys, $ks );
}

/**
 * Adds post type specific pages.
 *
 * @param string          $slug        Search page slug.
 * @param string|string[] $post_type_s Post types.
 */
function add_post_type_specific_page( string $slug, $post_type_s ): void {
	$inst = _get_instance();
	$slug = trim( $slug, '/' );
	$pts  = is_array( $post_type_s ) ? $post_type_s : array( $post_type_s );

	if ( isset( $inst->slug_to_pts[ $slug ] ) ) {
		$inst->slug_to_pts[ $slug ] = array_merge( $inst->slug_to_pts[ $slug ], $pts );
	} else {
		$inst->slug_to_pts[ $slug ] = $pts;
	}
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
	static $activated = 0;
	if ( $activated++ ) {
		return;
	}
	$inst = _get_instance();

	$args += array(
		'home_url_function'     => '\home_url',
		'target_post_types'     => array(),
		'do_allow_slash'        => true,
		'do_target_post_meta'   => true,
		'do_enable_blank_query' => true,
		'do_enable_custom_page' => false,
		'do_extend_query'       => false,
	);

	$inst->home_url_fn           = $args['home_url_function'];
	$inst->target_post_types     = $args['target_post_types'];
	$inst->do_allow_slash        = $args['do_allow_slash'];
	$inst->do_target_post_meta   = $args['do_target_post_meta'];
	$inst->do_enable_blank_query = $args['do_enable_blank_query'];
	$inst->do_enable_custom_page = $args['do_enable_custom_page'];
	$inst->do_extend_query       = $args['do_extend_query'];

	if ( ! empty( $inst->meta_keys ) ) {
		$inst->do_target_post_meta = true;
	}

	if ( $inst->do_target_post_meta || $inst->do_extend_query ) {
		add_filter( 'posts_search', '\wpinc\ref\_cb_posts_search', 10, 2 );
		add_filter( 'posts_groupby', '\wpinc\ref\_cb_posts_groupby', 10, 2 );
	}
	if ( $inst->do_target_post_meta ) {
		add_filter( 'posts_join', '\wpinc\ref\_cb_posts_join', 10, 2 );
	}
	if ( $inst->do_extend_query ) {
		add_filter( 'posts_search_orderby', '\wpinc\ref\_cb_posts_search_orderby', 10, 2 );
		add_filter( 'posts_request', '\wpinc\ref\_cb_posts_request', 10, 2 );
	}

	if ( ! empty( $inst->target_post_types ) ) {
		add_action( 'pre_get_posts', '\wpinc\ref\_cb_pre_get_posts' );
	}
	if ( $inst->do_allow_slash || $inst->do_enable_custom_page ) {
		add_filter( 'request', '\wpinc\ref\_cb_request', 20, 1 );
	}
	if ( $inst->do_enable_blank_query || ! empty( $inst->slug_to_pts ) ) {
		add_filter( 'search_rewrite_rules', '\wpinc\ref\_cb_search_rewrite_rules' );
	}
	if ( $inst->do_enable_blank_query || ! empty( $inst->slug_to_pts ) || $inst->do_enable_custom_page ) {
		add_filter( 'template_redirect', '\wpinc\ref\_cb_template_redirect' );
	}
}


// -----------------------------------------------------------------------------


/**
 * Callback function for 'search_rewrite_rules' filter.
 *
 * @access private
 *
 * @param string[] $rewrite Array of rewrite rules for search queries, keyed by their regex pattern.
 * @return string[] Array of rewrite rules.
 */
function _cb_search_rewrite_rules( array $rewrite ): array {
	$inst = _get_instance();
	global $wp_rewrite;
	if ( ! $wp_rewrite->using_permalinks() ) {
		return $rewrite;
	}
	$search_base = $wp_rewrite->search_base;

	if ( $inst->do_enable_blank_query ) {
		$rewrite[ "$search_base/?$" ] = 'index.php?s=';
	}

	if ( ! empty( $inst->slug_to_pts ) ) {  // For post type specific pages.
		foreach ( $inst->slug_to_pts as $slug => $pts ) {
			$pts_str = implode( ',', $pts );

			$rewrite[ "$slug/$search_base/(.+)/?$" ] = 'index.php?post_type=' . $pts_str . '&s=$matches[1]';
			$rewrite[ "$slug/$search_base/?$" ]      = 'index.php?post_type=' . $pts_str . '&s=';
		}
	}
	return $rewrite;
}

/**
 * Callback function for 'template_redirect' action.
 *
 * @access private
 */
function _cb_template_redirect(): void {
	global $wp_rewrite;
	if ( ! $wp_rewrite->using_permalinks() ) {
		return;
	}
	if ( is_search() && ! is_admin() && isset( $_GET['s'] ) ) {  // phpcs:ignore
		$search_base = $wp_rewrite->search_base;
		$home_url    = _home_url( "/$search_base/" );
		$post_type_s = $_GET['post_type'] ?? '';  // phpcs:ignore
		if ( ! empty( $post_type_s ) ) {
			$pts  = explode( ',', $post_type_s );
			$slug = _get_matching_slug( $pts );
			if ( ! empty( $slug ) ) {
				$home_url = _home_url( "/$slug/$search_base/" );
			}
		}
		wp_safe_redirect( $home_url . _urlencode( get_query_var( 's' ) ) );
		exit;
	}
}

/**
 * Retrieves matching slug.
 *
 * @access private
 *
 * @param string[] $post_types Post types.
 * @return string Matched post type.
 */
function _get_matching_slug( array $post_types ): string {
	$inst = _get_instance();
	foreach ( $inst->slug_to_pts as $slug => $pts ) {
		foreach ( $post_types as $t ) {
			if ( in_array( $t, $pts, true ) ) {
				return $slug;
			}
		}
	}
	return '';
}

/**
 * Returns home URL link with optional path appended.
 *
 * @access private
 *
 * @param string $path Path relative to the home URL.
 * @return string Home URL link with optional path appended.
 */
function _home_url( string $path ): string {
	$inst = _get_instance();
	return call_user_func( $inst->home_url_fn, $path );
}

/**
 * Callback function for 'request' filter.
 *
 * @access private
 *
 * @param array $query_vars The array of requested query variables.
 * @return array Variables.
 */
function _cb_request( array $query_vars ): array {
	$inst = _get_instance();
	if ( $inst->do_enable_custom_page ) {
		if ( ! is_admin() && isset( $query_vars['s'] ) ) {
			$query_vars['pagename'] = '';
		}
	}
	if ( $inst->do_allow_slash ) {
		if ( ! is_admin() && isset( $query_vars['s'] ) ) {
			$query_vars['s'] = str_replace(
				array( '%1f', '%1F' ),
				array( '%2f', '%2F' ),
				$query_vars['s']
			);
		}
	}
	return $query_vars;
}

/**
 * Callback function for 'pre_get_posts' action.
 *
 * @access private
 *
 * @param \WP_Query $query The WP_Query instance (passed by reference).
 */
function _cb_pre_get_posts( \WP_Query $query ): void {
	$inst = _get_instance();
	if ( $query->is_search ) {
		$val = $query->get( 'post_type' );
		if ( empty( $val ) ) {
			$query->set( 'post_type', $inst->target_post_types );
		}
	}
}

/**
 * Callback function for 'posts_search' filter.
 *
 * @access private
 *
 * @param string    $search Search SQL for WHERE clause.
 * @param \WP_Query $query  The WP_Query instance (passed by reference).
 * @return string WHERE clause.
 */
function _cb_posts_search( string $search, \WP_Query $query ): string {
	$inst = _get_instance();
	if ( ! $query->is_search() || ! $query->is_main_query() || empty( $search ) ) {
		return $search;
	}
	$q = $query->query_vars;

	global $wpdb;
	$search           = '';
	$searchand        = '';
	$exclusion_prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );

	if ( $inst->do_extend_query ) {
		$search_terms = _extend_search_terms( $q['search_terms'], $exclusion_prefix );
	} else {
		$search_terms = $q['search_terms'];
	}
	foreach ( $search_terms as $term ) {
		if ( $inst->do_extend_query && is_array( $term ) ) {
			$search .= "$searchand(" . _create_extended_query( $term ) . ')';
		} else {
			$search .= "$searchand(" . _create_query( $term, $exclusion_prefix, $q['exact'] ) . ')';
		}
		$searchand = ' AND ';
	}
	if ( ! empty( $search ) ) {
		$search = " AND ({$search}) ";
		if ( ! is_user_logged_in() ) {
			$search .= " AND ($wpdb->posts.post_password = '') ";
		}
	}
	return $search;
}

/**
 * Makes query.
 *
 * @access private
 *
 * @param string      $term             Term.
 * @param string      $exclusion_prefix Exclusion prefix.
 * @param string|null $exact            Query 'exact'.
 * @return string Query.
 */
function _create_query( string $term, string $exclusion_prefix, ?string $exact ): string {
	global $wpdb;
	$search = '';
	$n      = ! empty( $exact ) ? '' : '%';

	$exclude = $exclusion_prefix && ( substr( $term, 0, 1 ) === $exclusion_prefix );
	if ( $exclude ) {
		$like_op  = 'NOT LIKE';
		$andor_op = 'AND';
		$term     = substr( $term, 1 );
	} else {
		$like_op  = 'LIKE';
		$andor_op = 'OR';
	}
	if ( $n && ! $exclude ) {
		$like                        = '%' . $wpdb->esc_like( $term ) . '%';
		$q['search_orderby_title'][] = $wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", $like );
	}
	$like = $n . $wpdb->esc_like( $term ) . $n;
	if ( $inst->do_target_post_meta ) {
		$t       = "($wpdb->posts.post_title $like_op %s) $andor_op ({$wpdb->posts}.post_excerpt $like_op %s) $andor_op ($wpdb->posts.post_content $like_op %s) $andor_op (postmeta_wpinc_ref.meta_value $like_op %s)";
		$search .= $wpdb->prepare( $t, $like, $like, $like, $like );  // phpcs:ignore
	} else {
		$t       = "($wpdb->posts.post_title $like_op %s) $andor_op ({$wpdb->posts}.post_excerpt $like_op %s) $andor_op ($wpdb->posts.post_content $like_op %s)";
		$search .= $wpdb->prepare( $t, $like, $like, $like );  // phpcs:ignore
	}
	return $search;
}

/**
 * Callback function for 'posts_join' filter.
 *
 * @access private
 *
 * @param string    $join  The JOIN clause of the query.
 * @param \WP_Query $query The WP_Query instance (passed by reference).
 * @return string JOIN clause.
 */
function _cb_posts_join( string $join, \WP_Query $query ): string {
	$inst = _get_instance();
	if ( ! $query->is_search() || ! $query->is_main_query() ) {
		return $join;
	}
	$sql_mks = '';
	if ( ! empty( $inst->meta_keys ) ) {
		$_mks = array();
		foreach ( $inst->meta_keys as $mk ) {
			$_mks[] = "'" . esc_sql( $mk ) . "'";
		}
		$sql_mks = implode( ', ', $_mks );
	}
	global $wpdb;
	if ( $inst->do_target_post_meta ) {
		$join .= " INNER JOIN ( SELECT post_id, meta_value FROM $wpdb->postmeta";
		if ( ! empty( $sql_mks ) ) {
			$join .= " WHERE meta_key IN ( $sql_mks )";
		}
		$join .= " ) AS postmeta_wpinc_ref ON ($wpdb->posts.ID = postmeta_wpinc_ref.post_id) ";
	}
	return $join;
}

/**
 * Callback function for 'posts_groupby' filter.
 *
 * @access private
 *
 * @param string    $groupby The GROUP BY clause of the query.
 * @param \WP_Query $query   The WP_Query instance (passed by reference).
 * @return string GROUP BY clause.
 */
function _cb_posts_groupby( string $groupby, \WP_Query $query ): string {
	global $wpdb;
	if ( $query->is_search() && $query->is_main_query() ) {
		$groupby = "{$wpdb->posts}.ID";
	}
	return $groupby;
}


// -----------------------------------------------------------------------------


/**
 * Encodes URL.
 *
 * @access private
 *
 * @param string $str String.
 * @return string Encoded string.
 */
function _urlencode( string $str ): string {
	$inst = _get_instance();
	if ( $inst->is_slash_in_query_enabled ) {
		$ret = rawurlencode( $str );
		return str_replace( array( '%2f', '%2F' ), array( '%1f', '%1F' ), $ret );
	} else {
		return rawurlencode( $str );
	}
}


// -------------------------------------------------------------------------


/**
 * Gets instance.
 *
 * @access private
 *
 * @return object Instance.
 */
function _get_instance(): object {
	static $values = null;
	if ( $values ) {
		return $values;
	}
	$values = new class() {
		/**
		 * Callable for getting home URL link.
		 *
		 * @var callable
		 */
		public $home_url_fn = 'home_url';

		/**
		 * Target post types.
		 *
		 * @var string[]
		 */
		public $target_post_types = array();

		/**
		 * Whether do allow slash in query.
		 *
		 * @var bool
		 */
		public $do_allow_slash = true;

		/**
		 * Whether do search for post meta values.
		 *
		 * @var bool
		 */
		public $do_target_post_meta = true;

		/**
		 * Whether do enable blank query.
		 *
		 * @var bool
		 */
		public $do_enable_blank_query = true;

		/**
		 * Whether do enable custom search page.
		 *
		 * @var bool
		 */
		public $do_enable_custom_page = false;

		/**
		 * Whether do extend query.
		 *
		 * @var bool
		 */
		public $do_extend_query = false;

		/**
		 * Array of meta keys that are handled as searching target.
		 *
		 * @var string[]
		 */
		public $meta_keys = array();

		/**
		 * Array of slug to post types.
		 *
		 * @var array
		 */
		public $slug_to_pts = array();
	};
	return $values;
}
