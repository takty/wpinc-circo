<?php
/**
 * Search Function for Custom Fields
 *
 * @author Takuto Yanagida
 * @version 2021-03-24
 */

namespace st;

class Search {

	private static $_instance = null;
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new Search();
		}
		return self::$_instance;
	}


	// -------------------------------------------------------------------------


	private $_is_slash_in_query_enabled  = false;
	private $_is_extended_search_enabled = false;

	private $_home_url = 'home_url';

	private $_meta_keys   = array();
	private $_post_types  = array();
	private $_slug_to_pts = array();
	private $_stop_words;

	private $_search_rewrite_rules_func = null;
	private $_request_func = null;
	private $_template_redirect_func = null;
	private $_pre_get_posts_func = null;

	private $_posts_search_filter_added = false;

	private function __construct() {}

	public function set_home_url( $home_url ) {
		$this->_home_url = $home_url;
	}

	public function set_slash_in_query_enabled( $enabled ) {
		$this->_is_slash_in_query_enabled = $enabled;
		$this->ensure_request_filter_added();
	}

	public function set_extended_search_enabled( $enabled ) {
		$this->_is_extended_search_enabled = $enabled;
	}

	public function set_blank_search_page_enabled( $enabled ) {
		if ( $enabled ) {
			$this->ensure_search_rewrite_rules_filter_added();
			$this->ensure_template_redirect_filter_added();
		} else {
			$this->ensure_search_rewrite_rules_filter_removed();
			$this->ensure_template_redirect_filter_removed();
		}
	}

	public function set_custom_search_page_enabled( $enabled ) {
		if ( is_admin() ) {
			return;
		}
		if ( $enabled ) {
			$this->ensure_request_filter_added();
			$this->ensure_template_redirect_filter_added();
		} else {
			$this->ensure_request_filter_removed();
			$this->ensure_template_redirect_filter_removed();
		}
	}

	public function add_post_type( $str_or_array ) {
		if ( is_admin() ) {
			return;
		}
		$this->ensure_pre_get_posts_filter();

		if ( ! is_array( $str_or_array ) ) {
			$str_or_array = array( $str_or_array );
		}
		$this->_post_types = array_merge( $this->_post_types, $str_or_array );
	}

	public function add_post_type_specific_search_page( $slug, $post_type_s ) {
		$this->ensure_search_rewrite_rules_filter_added();
		$this->ensure_template_redirect_filter_added();

		if ( ! is_array( $post_type_s ) ) {
			$post_type_s = array( $post_type_s );
		}
		$this->_slug_to_pts[ trim( $slug, '/' ) ] = $post_type_s;
	}

	public function set_post_meta_search_enabled( $enabled ) {
		$this->ensure_posts_search_filter();
	}

	public function add_meta_key( $str_or_array ) {
		$this->ensure_posts_search_filter();

		if ( ! is_array( $str_or_array ) ) {
			$str_or_array = array( $str_or_array );
		}
		$this->_meta_keys = array_merge( $this->_meta_keys, $str_or_array );
	}


	// Private Functions -------------------------------------------------------


	private function ensure_search_rewrite_rules_filter_added() {
		if ( $this->_search_rewrite_rules_func ) {
			return;
		}
		$this->_search_rewrite_rules_func = array( $this, '_cb_add_rewrite_rules' );
		add_filter( 'search_rewrite_rules', $this->_search_rewrite_rules_func );
	}

	private function ensure_search_rewrite_rules_filter_removed() {
		if ( ! $this->_search_rewrite_rules_func ) {
			return;
		}
		remove_filter( 'search_rewrite_rules', $this->_search_rewrite_rules_func );
		$this->_search_rewrite_rules_func = null;
	}

	private function ensure_request_filter_added() {
		if ( $this->_request_func ) {
			return;
		}
		$this->_request_func = array( $this, '_cb_request' );
		add_filter( 'request', $this->_request_func, 20, 1 );
	}

	private function ensure_request_filter_removed() {
		if ( ! $this->_request_func ) {
			return;
		}
		remove_filter( 'request', $this->_request_func, 20 );
		$this->_request_func = null;
	}

	private function ensure_template_redirect_filter_added() {
		if ( $this->_template_redirect_func ) {
			return;
		}
		$this->_template_redirect_func = array( $this, '_cb_template_redirect' );
		add_filter( 'template_redirect', $this->_template_redirect_func );
	}

	private function ensure_template_redirect_filter_removed() {
		if ( ! $this->_template_redirect_func ) {
			return;
		}
		remove_filter( 'template_redirect', $this->_template_redirect_func );
		$this->_template_redirect_func = null;
	}

	private function ensure_pre_get_posts_filter() {
		if ( $this->_pre_get_posts_func ) {
			return;
		}
		$this->_pre_get_posts_func = array( $this, '_cb_pre_get_posts' );
		add_action( 'pre_get_posts', $this->_pre_get_posts_func );
	}

	private function ensure_posts_search_filter() {
		if ( $this->_posts_search_filter_added ) {
			return;
		}
		add_filter( 'posts_search', array( $this, '_cb_posts_search' ), 10, 2 );
		add_filter( 'posts_join', array( $this, '_cb_posts_join' ), 10, 2 );
		add_filter( 'posts_groupby', array( $this, '_cb_posts_groupby' ), 10, 2 );
		add_filter( 'posts_search_orderby', array( $this, '_cb_posts_search_orderby' ), 10, 2 );
		add_filter( 'posts_request', array( $this, '_cb_posts_request' ), 10, 2 );
		$this->_posts_search_filter_added = true;
	}


	// Callback Functions ------------------------------------------------------


	public function _cb_add_rewrite_rules( $rewrite_rules ) {
		global $wp_rewrite;
		if ( ! $wp_rewrite->using_permalinks() ) {
			return;
		}
		$search_base = $wp_rewrite->search_base;

		$rewrite_rules[ "$search_base/?$" ] = 'index.php?s=';

		foreach ( $this->_slug_to_pts as $slug => $pts ) {
			$pts_str = implode( ',', $pts );

			$rewrite_rules[ "$slug/$search_base/(.+)/?$" ] = 'index.php?post_type=' . $pts_str . '&s=$matches[1]';
			$rewrite_rules[ "$slug/$search_base/?$" ]      = 'index.php?post_type=' . $pts_str . '&s=';
		}
		return $rewrite_rules;
	}

	public function _cb_template_redirect() {
		global $wp_rewrite;
		if ( ! $wp_rewrite->using_permalinks() ) {
			return;
		}
		$search_base = $wp_rewrite->search_base;
		if ( is_search() && ! is_admin() && isset( $_GET['s'] ) ) {
			$home_url = $this->home_url( "/$search_base/" );
			if ( ! empty( $_GET['post_type'] ) ) {
				$pts  = explode( ',', $_GET['post_type'] );
				$slug = $this->get_matched_slug( $pts );
				if ( $slug !== false ) {
					$home_url = $this->home_url( "/$slug/$search_base/" );
				}
			}
			wp_safe_redirect( $home_url . $this->urlencode( get_query_var( 's' ) ) );
			exit;
		}
	}

	private function get_matched_slug( $post_types ) {
		foreach ( $this->_slug_to_pts as $slug => $pts ) {
			foreach ( $post_types as $t ) {
				if ( in_array( $t, $pts, true ) ) {
					return $slug;
				}
			}
		}
		return false;
	}

	private function home_url( $slug ) {
		return call_user_func( $this->_home_url, $slug );
	}

	public function _cb_request( $query_vars ) {
		if ( isset( $query_vars['s'] ) && ! empty( $query_vars['pagename'] ) ) {
			$query_vars['pagename'] = '';
		}
		if ( isset( $query_vars['s'] ) && $this->_is_slash_in_query_enabled ) {
			$query_vars['s'] = str_replace( [ '%1f', '%1F' ], [ '%2f', '%2F' ], $query_vars['s'] );
		}
		return $query_vars;
	}

	public function _cb_pre_get_posts( $query ) {
		if ( $query->is_search ) {
			$val = $query->get( 'post_type' );
			if ( empty( $val ) && ! empty( $this->_post_types ) ) {
				$query->set( 'post_type', $this->_post_types );
			}
		}
	}

	public function _cb_posts_search( $search, $query ) {
		if ( ! $query->is_search() || ! $query->is_main_query() || empty( $search ) ) {
			return $search;
		}
		$q = $query->query_vars;
		global $wpdb;
		$search = '';

		$n = ! empty( $q['exact'] ) ? '' : '%';

		$searchand        = '';
		$exclusion_prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );

		if ( $this->_is_extended_search_enabled ) {
			$search_terms = $this->extend_search_terms( $q['search_terms'], $exclusion_prefix );
		} else {
			$search_terms = $q['search_terms'];
		}
		foreach ( $search_terms as $term ) {
			if ( $this->_is_extended_search_enabled && is_array( $term ) ) {
				$search   .= "$searchand(" . $this->create_extended_query( $term ) . ')';
				$searchand = ' AND ';
				continue;
			}
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
			// Add post_meta.
			$search   .= $wpdb->prepare( "{$searchand}(($wpdb->posts.post_title $like_op %s) $andor_op ({$wpdb->posts}.post_excerpt $like_op %s) $andor_op ($wpdb->posts.post_content $like_op %s) $andor_op (stinc_search.meta_value $like_op %s))", $like, $like, $like, $like );
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

	public function _cb_posts_join( $join, $query ) {
		if ( ! $query->is_search() || ! $query->is_main_query() ) {
			return $join;
		}
		$sql_mks = '';
		if ( ! empty( $this->_meta_keys ) ) {
			$_mks = array();
			foreach ( $this->_meta_keys as $mk ) {
				$_mks[] = "'" . esc_sql( $mk ) . "'";
			}
			$sql_mks = implode( ', ', $_mks );
		}
		global $wpdb;
		$join .= " INNER JOIN ( SELECT post_id, meta_value FROM $wpdb->postmeta";
		if ( ! empty( $sql_mks ) ) {
			$join .= " WHERE meta_key IN ( $sql_mks )";
		}
		$join .= " ) AS stinc_search ON ($wpdb->posts.ID = stinc_search.post_id) ";
		return $join;
	}

	public function _cb_posts_groupby( $groupby, $query ) {
		global $wpdb;
		if ( $query->is_search() && $query->is_main_query() ) {
			$groupby = "{$wpdb->posts}.ID";
		}
		return $groupby;
	}

	public function _cb_posts_search_orderby( $orderby, $query ) {
		global $wpdb;
		if ( $this->_is_extended_search_enabled ) {
			$orderby .= ( $orderby ? ', ' : '' ) . "count({$wpdb->posts}.ID) DESC";
		}
		return $orderby;
	}

	public function _cb_posts_request( $request, $query ) {
		global $wpdb;
		if ( $this->_is_extended_search_enabled && $query->is_search() && $query->is_main_query() ) {
			$request = str_replace( '.* FROM ', ".*, count({$wpdb->posts}.ID) FROM ", $request );
		}
		return $request;
	}


	// Private Functions -------------------------------------------------------


	private function create_extended_query( $likes ) {
		global $wpdb;
		$search = '';
		$sh     = '';
		foreach ( $likes as $like ) {
			$search .= $wpdb->prepare( "{$sh}(($wpdb->posts.post_title LIKE %s) OR ({$wpdb->posts}.post_excerpt LIKE %s) OR ($wpdb->posts.post_content LIKE %s) OR (stinc_search.meta_value LIKE %s))", $like, $like, $like, $like );
			$sh      = ' OR ';
		}
		return $search;
	}

	private function extend_search_terms( $terms, $exclusion_prefix ) {
		$ret = array();
		foreach ( $terms as $term ) {
			$exclude = $exclusion_prefix && ( substr( $term, 0, 1 ) === $exclusion_prefix );
			if ( $exclude ) {
				$ret[] = $term;
				continue;
			}
			$sts = array_map( '\st\mb_trim', mb_split( "[「『（［｛〈《【〔〖〘〚＜」』）］｝〉》】〕〗〙〛＞、，。．？！：・]+", $term ) );
			foreach ( $sts as $t ) {
				if ( empty( $t ) ) {
					continue;
				}
				$len = mb_strlen( $t );
				if ( 4 <= $len && $len <= 10 ) {
					$ret[] = $this->split_term( $t );
				} else {
					$ret[] = $t;
				}
			}
		}
		return $ret;
	}

	public function split_term( $term ) {
		global $wpdb;
		$bis = array();
		$chs = preg_split( "//u", $term, -1, PREG_SPLIT_NO_EMPTY );
		$sws = array_map(
			function ( $ch ) {
				return mb_strwidth( $ch );
			},
			$chs
		);

		$temp = '';
		foreach ( $chs as $i => $ch ) {
			if ( $sws[ $i ] === 2 ) {
				if ( $temp !== '' ) {
					$bis[] = $temp;
					$temp  = '';
				}
				if ( isset( $chs[ $i + 1 ] ) && $sws[ $i + 1 ] === 2 ) {
					$bis[] = $ch . $chs[ $i + 1 ];
				}
			} else {
				$temp .= $ch;
			}
		}
		if ( $temp !== '' ) {
			$bis[] = $temp;
		}
		$ret  = array( '%' . $wpdb->esc_like( $term ) . '%' );
		$size = count( $bis );
		for ( $j = 0; $j < $size; ++$j ) {
			$str = '%';
			for ( $i = 0; $i < $size; ++$i ) {
				if ( $j !== $i || 2 < mb_strlen( $bis[ $i ] ) ) {
					$str .= $wpdb->esc_like( $bis[ $i ] ) . '%';
				}
			}
			$ret[] = $str;
		}
		return $ret;
	}

	private function urlencode( $str ) {
		if ( $this->_is_slash_in_query_enabled ) {
			$ret = rawurlencode( $str );
			return str_replace( array( '%2f', '%2F' ), array( '%1f', '%1F' ), $ret );
		} else {
			return rawurlencode( $str );
		}
	}

	private function urldecode( $str ) {
		if ( $this->_is_slash_in_query_enabled ) {
			$ret = str_replace( array( '%1f', '%1F' ), array( '%2f', '%2F' ), $str );
			return rawurldecode( $ret );
		} else {
			return rawurldecode( $str );
		}
	}

}
