<?php
/**
 * Query Extender for Search
 *
 * @package Wpinc Ref
 * @author Takuto Yanagida
 * @version 2023-09-01
 */

namespace wpinc\ref;

/**
 * Callback function for 'posts_search_orderby' filter.
 *
 * @access private
 *
 * @param string    $orderby The ORDER BY clause of the query.
 * @param \WP_Query $query   The WP_Query instance (passed by reference).
 * @return string ORDER BY clause.
 */
function _cb_posts_search_orderby( string $orderby, \WP_Query $query ): string {
	global $wpdb;
	if ( $query->is_search() && $query->is_main_query() ) {
		$orderby .= ( $orderby ? ', ' : '' ) . "count({$wpdb->posts}.ID) DESC";
	}
	return $orderby;
}

/**
 * Callback function for 'posts_request' filter.
 *
 * @access private
 *
 * @param string    $request The complete SQL query.
 * @param \WP_Query $query   The WP_Query instance (passed by reference).
 * @return string Query.
 */
function _cb_posts_request( string $request, \WP_Query $query ): string {
	global $wpdb;
	if ( $query->is_search() && $query->is_main_query() ) {
		$request = str_replace( '.* FROM ', ".*, count({$wpdb->posts}.ID) FROM ", $request );
	}
	return $request;
}


// -----------------------------------------------------------------------------


/**
 * Makes extended query.
 *
 * @access private
 *
 * @param string[] $likes               Terms.
 * @param bool     $do_target_post_meta Whether do search for post meta values.
 * @return string Query.
 */
function _create_extended_query( array $likes, bool $do_target_post_meta ): string {
	global $wpdb;
	$search = '';
	$sh     = '';
	foreach ( $likes as $like ) {
		if ( $do_target_post_meta ) {
			$t       = "{$sh}(($wpdb->posts.post_title LIKE %s) OR ({$wpdb->posts}.post_excerpt LIKE %s) OR ($wpdb->posts.post_content LIKE %s) OR (postmeta_wpinc_ref.meta_value LIKE %s))";
			$search .= $wpdb->prepare( $t, $like, $like, $like, $like );  // phpcs:ignore
		} else {
			$t       = "{$sh}(($wpdb->posts.post_title LIKE %s) OR ({$wpdb->posts}.post_excerpt LIKE %s) OR ($wpdb->posts.post_content LIKE %s))";
			$search .= $wpdb->prepare( $t, $like, $like, $like );  // phpcs:ignore
		}
		$sh = ' OR ';
	}
	return $search;
}

/**
 * Extends search terms.
 *
 * @access private
 *
 * @param string[] $terms            Search terms.
 * @param string   $exclusion_prefix Exclusion prefix.
 * @return array<string|string[]> Terms.
 */
function _extend_search_terms( array $terms, string $exclusion_prefix ): array {
	$ret = array();
	foreach ( $terms as $term ) {
		$exclude = $exclusion_prefix && ( substr( $term, 0, 1 ) === $exclusion_prefix );
		if ( $exclude ) {
			$ret[] = $term;
			continue;
		}
		$sts = mb_split( '[「『（［｛〈《【〔〖〘〚＜」』）］｝〉》】〕〗〙〛＞、，。．？！：・]+', $term );
		if ( ! $sts ) {
			$sts = array();
		}
		$sts = array_map( '\wpinc\ref\_mb_trim', $sts );
		foreach ( $sts as $t ) {
			if ( empty( $t ) ) {
				continue;
			}
			$len = mb_strlen( $t );
			if ( 4 <= $len && $len <= 10 ) {
				$ret[] = _split_term( $t );
			} else {
				$ret[] = $t;
			}
		}
	}
	return $ret;
}

/**
 * Trims string.
 *
 * @access private
 *
 * @param string $str String.
 * @return string Trimmed string.
 */
function _mb_trim( string $str ): string {
	return preg_replace( '/\A[\p{C}\p{Z}]++|[\p{C}\p{Z}]++\z/u', '', $str ) ?? $str;
}

/**
 * Splits a term.
 *
 * @access private
 *
 * @param string $term Term string.
 * @return string[] Terms.
 */
function _split_term( string $term ): array {
	global $wpdb;
	$bis = array();
	$chs = preg_split( '//u', $term, -1, PREG_SPLIT_NO_EMPTY );
	if ( $chs ) {
		$sws = array_map(
			function ( $ch ) {
				return mb_strwidth( $ch );
			},
			$chs
		);

		$temp = '';
		foreach ( $chs as $i => $ch ) {
			if ( 2 === $sws[ $i ] ) {
				if ( '' !== $temp ) {
					$bis[] = $temp;
					$temp  = '';
				}
				if ( isset( $chs[ $i + 1 ] ) && 2 === $sws[ $i + 1 ] ) {
					$bis[] = $ch . $chs[ $i + 1 ];
				}
			} else {
				$temp .= $ch;
			}
		}
		if ( '' !== $temp ) {
			$bis[] = $temp;
		}
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
