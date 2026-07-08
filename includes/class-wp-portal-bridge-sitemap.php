<?php
/**
 * WP Portal Bridge — Portal-ready sitemap data.
 *
 * Returns data, not XML: Portal owns rendering, including the sitemap.
 * lastmod comes from real modification timestamps — honest signals only.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Sitemap {

	/** @var WP_Portal_Bridge_Routes */
	private $routes;

	/**
	 * @param WP_Portal_Bridge_Routes $routes Route enumerator.
	 */
	public function __construct( $routes ) {
		$this->routes = $routes;
	}

	/**
	 * Sitemap entries for every published route.
	 *
	 * @return array{entries: array, meta: array}
	 */
	public function entries() {
		$posts   = $this->routes->published_posts();
		$entries = array();

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			$modified  = $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt
				? gmdate( 'c', strtotime( $post->post_modified_gmt . ' UTC' ) )
				: '';

			$entries[] = array(
				'loc'     => $permalink,
				'path'    => $this->routes->path_from_url( $permalink ),
				'lastmod' => $modified,
				'type'    => $post->post_type,
			);
		}

		return array(
			'entries' => $entries,
			'meta'    => array(
				'total'       => count( $entries ),
				'generatedAt' => gmdate( 'c' ),
			),
		);
	}
}
