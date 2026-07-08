<?php
/**
 * WP Portal Bridge — route enumeration and the route contract.
 *
 * A "route" is one public, renderable URL backed by published content:
 * pages, posts, and public custom post type singles (plus the front page).
 * Paths are preserved exactly as WordPress generates them — authority
 * preservation beats pretty route redesign.
 *
 * Exclusion rules (v0, hard):
 *   - only post_status = publish
 *   - never password-protected content
 *   - never drafts, private posts, revisions, or admin-only metadata
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Routes {

	/** @var WP_Portal_Bridge_Seo */
	private $seo;

	/** @var WP_Portal_Bridge_Media */
	private $media;

	/**
	 * @param WP_Portal_Bridge_Seo   $seo   SEO extractor.
	 * @param WP_Portal_Bridge_Media $media Media mapper.
	 */
	public function __construct( $seo, $media ) {
		$this->seo   = $seo;
		$this->media = $media;
	}

	/**
	 * Public, renderable post types (built-ins page/post + public CPTs).
	 *
	 * @return array<int,string>
	 */
	public function public_post_types() {
		$types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * All published, non-password-protected posts, stable order.
	 *
	 * @return array<int,WP_Post>
	 */
	public function published_posts() {
		return get_posts(
			array(
				'post_type'        => $this->public_post_types(),
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Route list with pagination.
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Page size.
	 * @return array{routes: array, meta: array}
	 */
	public function list_routes( $page = 1, $per_page = 500 ) {
		$posts = $this->published_posts();
		$total = count( $posts );

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 1000, (int) $per_page ) );
		$slice    = array_slice( $posts, ( $page - 1 ) * $per_page, $per_page );

		$routes = array();
		foreach ( $slice as $post ) {
			$routes[] = $this->build_route( $post );
		}

		return array(
			'routes' => $routes,
			'meta'   => array(
				'total'      => $total,
				'page'       => $page,
				'perPage'    => $per_page,
				'totalPages' => (int) ceil( $total / $per_page ),
			),
		);
	}

	/**
	 * Resolve one route by its public path. Deterministic normalization:
	 * exact match first, then the trailing-slash toggle.
	 *
	 * @param string $path Public path, e.g. "/about/".
	 * @return array|null Route contract or null.
	 */
	public function route_by_path( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );

		$post = $this->resolve_post_by_path( $path );
		if ( null === $post ) {
			$toggled = ( '/' !== $path && substr( $path, -1 ) === '/' )
				? rtrim( $path, '/' )
				: $path . '/';
			$post    = $this->resolve_post_by_path( $toggled );
		}

		if ( null === $post ) {
			return null;
		}
		return $this->build_route( $post );
	}

	/**
	 * Path → published WP_Post, or null.
	 *
	 * @param string $path Public path.
	 * @return WP_Post|null
	 */
	private function resolve_post_by_path( $path ) {
		// Front page.
		if ( '/' === $path || '' === $path ) {
			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id > 0 ) {
				$front = get_post( $front_id );
				if ( $front && $this->is_exposable( $front ) ) {
					return $front;
				}
			}
			return null;
		}

		$url = home_url( $path );

		// Core resolver handles most permalink structures.
		$post_id = url_to_postid( $url );
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post && $this->is_exposable( $post ) ) {
				return $post;
			}
			return null;
		}

		// Hierarchical page fallback (url_to_postid misses some setups).
		$trimmed = trim( $path, '/' );
		if ( '' !== $trimmed ) {
			$page = get_page_by_path( $trimmed, OBJECT, $this->public_post_types() );
			if ( $page && $this->is_exposable( $page ) ) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * Publish + no password + public type gate. The single exposure predicate.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function is_exposable( $post ) {
		return 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& in_array( $post->post_type, $this->public_post_types(), true );
	}

	/**
	 * Build the full route contract for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public function build_route( $post ) {
		$permalink = get_permalink( $post );
		$path      = $this->path_from_url( $permalink );

		$html = $this->render_content( $post );
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );

		$links = $this->extract_links( $html );

		$author = $this->author_block( $post );

		return array(
			'id'         => sprintf( 'wp:%s:%d', $post->post_type, $post->ID ),
			'source'     => 'wordpress',
			'type'       => $post->post_type,
			'path'       => $path,
			'status'     => $post->post_status,
			'title'      => get_the_title( $post ),
			'slug'       => $post->post_name,
			'content'    => array(
				'html'   => $html,
				'text'   => $text,
				'blocks' => array(), // v0: HTML preserved verbatim; block normalization is a future slice.
			),
			'seo'        => $this->seo->extract( $post ),
			'media'      => $this->media->for_post( $post, $html ),
			'taxonomies' => $this->taxonomy_block( $post ),
			'author'     => $author,
			'dates'      => array(
				'published' => $this->iso8601( $post->post_date_gmt ),
				'modified'  => $this->iso8601( $post->post_modified_gmt ),
			),
			'links'      => $links,
			'authority'  => array(
				'preservePath' => true,
				'score'        => 0,
				'notes'        => array(),
			),
		);
	}

	/**
	 * Rendered post HTML through the_content — shortcodes and blocks resolve to
	 * the same markup WordPress would serve, preserved verbatim for Portal.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function render_content( $post ) {
		global $wp_query;

		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );

		$html = apply_filters( 'the_content', $post->post_content );

		wp_reset_postdata();
		if ( null !== $previous ) {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}

		return (string) $html;
	}

	/**
	 * Internal/external link split from rendered HTML.
	 *
	 * @param string $html Rendered HTML.
	 * @return array{internal: array, external: array}
	 */
	private function extract_links( $html ) {
		$internal = array();
		$external = array();

		if ( '' === $html ) {
			return array(
				'internal' => $internal,
				'external' => $external,
			);
		}

		$home      = home_url();
		$home_host = wp_parse_url( $home, PHP_URL_HOST );

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\'#][^"\']*)["\']/i', $html, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $href ) {
				if ( 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) || 0 === strpos( $href, 'javascript:' ) ) {
					continue;
				}
				if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
					$internal[] = $href;
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( $host && $home_host && strtolower( $host ) === strtolower( $home_host ) ) {
					$path       = wp_parse_url( $href, PHP_URL_PATH );
					$internal[] = $path ? $path : '/';
				} elseif ( $host ) {
					$external[] = $href;
				}
			}
		}

		return array(
			'internal' => array_values( array_unique( $internal ) ),
			'external' => array_values( array_unique( $external ) ),
		);
	}

	/**
	 * Public author block — never includes email or login.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function author_block( $post ) {
		$author_id = (int) $post->post_author;
		if ( $author_id <= 0 ) {
			return array();
		}
		$name = get_the_author_meta( 'display_name', $author_id );
		$slug = get_the_author_meta( 'user_nicename', $author_id );
		return array(
			'id'     => $author_id,
			'name'   => (string) $name,
			'slug'   => (string) $slug,
			'path'   => $this->path_from_url( get_author_posts_url( $author_id ) ),
			'avatar' => get_avatar_url( $author_id, array( 'size' => 96 ) ),
		);
	}

	/**
	 * Taxonomy assignments for a post (public taxonomies only).
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function taxonomy_block( $post ) {
		$out        = array();
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link  = get_term_link( $term );
				$out[] = array(
					'taxonomy' => $taxonomy->name,
					'id'       => (int) $term->term_id,
					'slug'     => $term->slug,
					'name'     => $term->name,
					'path'     => is_wp_error( $link ) ? '' : $this->path_from_url( $link ),
				);
			}
		}
		return $out;
	}

	/**
	 * URL → path with query preserved off (paths only in the contract).
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	public function path_from_url( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( ! $path ) {
			return '/';
		}
		return $path;
	}

	/**
	 * MySQL GMT datetime → ISO 8601 Z.
	 *
	 * @param string $mysql_gmt "Y-m-d H:i:s" in GMT.
	 * @return string
	 */
	private function iso8601( $mysql_gmt ) {
		if ( ! $mysql_gmt || '0000-00-00 00:00:00' === $mysql_gmt ) {
			return '';
		}
		return gmdate( 'c', strtotime( $mysql_gmt . ' UTC' ) );
	}
}
