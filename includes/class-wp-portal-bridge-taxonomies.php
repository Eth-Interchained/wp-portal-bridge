<?php
/**
 * WP Portal Bridge — categories, tags, and public custom taxonomies.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Taxonomies {

	/**
	 * All public taxonomies with their terms.
	 *
	 * @return array
	 */
	public function list_taxonomies() {
		$out = array();

		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			// Internal-format taxonomies (nav menus, link categories) are not content.
			if ( in_array( $taxonomy->name, array( 'nav_menu', 'link_category', 'post_format' ), true ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => true,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$term_list = array();
			foreach ( $terms as $term ) {
				$link        = get_term_link( $term );
				$term_list[] = array(
					'id'          => (int) $term->term_id,
					'slug'        => (string) $term->slug,
					'name'        => (string) $term->name,
					'description' => (string) $term->description,
					'parentId'    => (int) $term->parent,
					'count'       => (int) $term->count,
					'path'        => is_wp_error( $link ) ? '' : (string) wp_parse_url( $link, PHP_URL_PATH ),
				);
			}

			$out[] = array(
				'taxonomy'     => (string) $taxonomy->name,
				'label'        => (string) $taxonomy->label,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'postTypes'    => array_values( (array) $taxonomy->object_type ),
				'terms'        => $term_list,
			);
		}

		return $out;
	}
}
