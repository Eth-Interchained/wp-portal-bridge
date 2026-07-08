<?php
/**
 * WP Portal Bridge — registered menus and menu items.
 *
 * Menu items pointing at unpublished/private targets are dropped: navigation
 * must never leak content the contract itself would refuse to serve.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Menus {

	/**
	 * All registered menus with items and theme locations.
	 *
	 * @return array
	 */
	public function list_menus() {
		$locations_map = array();
		foreach ( (array) get_nav_menu_locations() as $location => $menu_id ) {
			$locations_map[ (int) $menu_id ][] = $location;
		}

		$home      = home_url();
		$home_host = wp_parse_url( $home, PHP_URL_HOST );

		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$raw_items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
			$items     = array();

			foreach ( (array) $raw_items as $item ) {
				// Drop items whose object is an unpublished post.
				if ( 'post_type' === $item->type ) {
					$target = get_post( (int) $item->object_id );
					if ( ! $target || 'publish' !== $target->post_status || '' !== (string) $target->post_password ) {
						continue;
					}
				}

				$url      = (string) $item->url;
				$host     = wp_parse_url( $url, PHP_URL_HOST );
				$internal = ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) )
					|| ( $host && $home_host && strtolower( $host ) === strtolower( $home_host ) );

				$path = '';
				if ( $internal ) {
					$p    = wp_parse_url( $url, PHP_URL_PATH );
					$path = $p ? $p : '/';
				}

				$items[] = array(
					'id'         => (int) $item->ID,
					'title'      => (string) $item->title,
					'url'        => $url,
					'path'       => $path,
					'type'       => $internal ? 'internal' : 'external',
					'objectType' => (string) $item->object,
					'objectId'   => (int) $item->object_id,
					'parentId'   => (int) $item->menu_item_parent,
					'order'      => (int) $item->menu_order,
					'target'     => (string) $item->target,
					'classes'    => implode( ' ', array_filter( (array) $item->classes ) ),
				);
			}

			$menus[] = array(
				'id'        => (int) $menu->term_id,
				'slug'      => (string) $menu->slug,
				'name'      => (string) $menu->name,
				'locations' => isset( $locations_map[ (int) $menu->term_id ] ) ? $locations_map[ (int) $menu->term_id ] : array(),
				'items'     => $items,
			);
		}

		return $menus;
	}
}
