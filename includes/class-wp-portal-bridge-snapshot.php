<?php
/**
 * WP Portal Bridge — full signed snapshot of the content contract, plus the
 * dirty-tracking that keeps Portal's snapshot-first mode honest.
 *
 * Snapshot envelope (schemaVersion portal.wp.source.v1):
 *   snapshotId, contentHash, routeCount, generatedAt, generator, site,
 *   source, routes[], menus[], taxonomies[], assets[], redirects[]
 *
 * Size strategy: when routeCount exceeds the configured ceiling and no page
 * was requested, the endpoint answers with an explicit snapshot_too_large
 * error carrying chunk metadata — never a silent timeout. Chunked pages slice
 * routes and assets; site/menus/taxonomies ride on every chunk (small).
 *
 * Dirty tracking: content-change hooks bump a monotonically increasing
 * contentVersion and flip the dirty flag; the cached snapshot transient is
 * keyed to the version it was built from, so a stale cache can never be
 * served as fresh.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Snapshot {

	const OPTION_CONTENT = 'wp_portal_bridge_content_state';
	const CACHE_TRANSIENT = 'wpb_snapshot_cache';

	/** @var WP_Portal_Bridge_Routes */
	private $routes;

	/** @var WP_Portal_Bridge_Menus */
	private $menus;

	/** @var WP_Portal_Bridge_Taxonomies */
	private $taxonomies;

	/** @var WP_Portal_Bridge_Media */
	private $media;

	/** @var WP_Portal_Bridge_Site */
	private $site;

	/** @var WP_Portal_Bridge_Auth */
	private $auth;

	/**
	 * @param WP_Portal_Bridge_Routes     $routes     Routes.
	 * @param WP_Portal_Bridge_Menus      $menus      Menus.
	 * @param WP_Portal_Bridge_Taxonomies $taxonomies Taxonomies.
	 * @param WP_Portal_Bridge_Media      $media      Media.
	 * @param WP_Portal_Bridge_Site       $site       Site.
	 * @param WP_Portal_Bridge_Auth       $auth       Auth (settings).
	 */
	public function __construct( $routes, $menus, $taxonomies, $media, $site, $auth ) {
		$this->routes     = $routes;
		$this->menus      = $menus;
		$this->taxonomies = $taxonomies;
		$this->media      = $media;
		$this->site       = $site;
		$this->auth       = $auth;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Dirty tracking / content version
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Register every content-change hook that must mark the snapshot dirty.
	 */
	public function register_invalidation_hooks() {
		add_action( 'save_post', array( $this, 'on_post_change' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'mark_dirty' ) );
		add_action( 'transition_post_status', array( $this, 'on_status_transition' ), 10, 3 );
		add_action( 'wp_update_nav_menu', array( $this, 'mark_dirty' ) );
		add_action( 'created_term', array( $this, 'mark_dirty' ) );
		add_action( 'edited_term', array( $this, 'mark_dirty' ) );
		add_action( 'delete_term', array( $this, 'mark_dirty' ) );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );

		foreach ( array( 'permalink_structure', 'blogname', 'blogdescription', 'page_on_front', 'page_for_posts', 'show_on_front', 'home', 'siteurl' ) as $option ) {
			add_action( "update_option_{$option}", array( $this, 'mark_dirty' ) );
		}
	}

	/**
	 * Post saved — ignore revisions/autosaves and non-public types.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function on_post_change( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( $post && ! in_array( $post->post_type, $this->routes->public_post_types(), true ) && 'attachment' !== $post->post_type ) {
			return;
		}
		$this->mark_dirty();
	}

	/**
	 * Publish/unpublish transitions always dirty the snapshot.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_status_transition( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			$this->mark_dirty();
		}
	}

	/**
	 * SEO metadata edits dirty the snapshot (Yoast / Rank Math / AIOSEO keys).
	 *
	 * @param int    $meta_id  Meta row id.
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 */
	public function on_meta_change( $meta_id, $post_id, $meta_key ) {
		$prefixes = array( '_yoast_wpseo_', 'rank_math_', '_aioseo_', '_thumbnail_id' );
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( (string) $meta_key, $prefix ) ) {
				$this->mark_dirty();
				return;
			}
		}
	}

	/**
	 * Bump contentVersion, set dirty, stamp lastChangedAt.
	 */
	public function mark_dirty() {
		$state                    = $this->content_state();
		$state['version']         = (int) $state['version'] + 1;
		$state['dirty']           = true;
		$state['last_changed_at'] = time();
		update_option( self::OPTION_CONTENT, $state, false );
	}

	/**
	 * Content state with defaults.
	 *
	 * @return array{version:int, dirty:bool, last_changed_at:int, last_snapshot:array|null}
	 */
	private function content_state() {
		$state = get_option( self::OPTION_CONTENT, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return array_merge(
			array(
				'version'         => 1,
				'dirty'           => true,
				'last_changed_at' => 0,
				'last_snapshot'   => null,
			),
			$state
		);
	}

	/** @return int */
	public function content_version() {
		return (int) $this->content_state()['version'];
	}

	/** @return bool */
	public function is_dirty() {
		return (bool) $this->content_state()['dirty'];
	}

	/** @return string ISO 8601 or empty. */
	public function last_changed_iso() {
		$ts = (int) $this->content_state()['last_changed_at'];
		return $ts > 0 ? gmdate( 'c', $ts ) : '';
	}

	/** @return array|null Metadata of the last generated snapshot. */
	public function last_snapshot_meta() {
		return $this->content_state()['last_snapshot'];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Snapshot generation
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Build (or serve from cache) the snapshot envelope.
	 *
	 * @param int|null $page     Requested chunk page (null = unchunked request).
	 * @param int      $per_page Chunk size when paging.
	 * @return array|WP_Error
	 */
	public function build( $page = null, $per_page = 0 ) {
		$settings   = $this->auth->settings();
		$max_routes = (int) $settings['snapshot_max_routes'];
		$per_page   = $per_page > 0 ? min( 1000, (int) $per_page ) : (int) $settings['per_page_default'];

		$posts       = $this->routes->published_posts();
		$route_count = count( $posts );

		// Size guard: explicit error with chunk metadata, never a silent timeout.
		if ( null === $page && $route_count > $max_routes ) {
			return new WP_Error(
				'wpb_snapshot_too_large',
				'Snapshot exceeds the configured route ceiling. Request it in chunks.',
				array(
					'status'     => 413,
					'routeCount' => $route_count,
					'maxRoutes'  => $max_routes,
					'chunking'   => array(
						'param'      => 'page',
						'perPage'    => $per_page,
						'totalPages' => (int) ceil( $route_count / $per_page ),
					),
				)
			);
		}

		// Unchunked cache: valid only for the exact content version it was built from.
		$state = $this->content_state();
		if ( null === $page ) {
			$cached = get_transient( self::CACHE_TRANSIENT );
			if ( is_array( $cached )
				&& isset( $cached['builtFromVersion'], $cached['snapshot'] )
				&& (int) $cached['builtFromVersion'] === (int) $state['version'] ) {
				return $cached['snapshot'];
			}
		}

		$effective_page = null === $page ? 1 : max( 1, (int) $page );
		$slice          = null === $page ? $posts : array_slice( $posts, ( $effective_page - 1 ) * $per_page, $per_page );

		$routes = array();
		foreach ( $slice as $post ) {
			$routes[] = $this->routes->build_route( $post );
		}

		$menus      = $this->menus->list_menus();
		$taxonomies = $this->taxonomies->list_taxonomies();
		$assets     = $this->media->list_assets( $posts, $effective_page, null === $page ? 100000 : $per_page );

		// Content hash: cheap, deterministic, covers the FULL logical snapshot
		// (every route id + modified stamp) regardless of chunking.
		$hash_input = WPB_SCHEMA_VERSION;
		foreach ( $posts as $post ) {
			$hash_input .= '|' . $post->post_type . ':' . $post->ID . ':' . $post->post_modified_gmt;
		}
		$hash_input .= '|menus:' . count( $menus ) . '|tax:' . count( $taxonomies );
		$content_hash = hash( 'sha256', $hash_input );

		$generated_at = gmdate( 'c' );
		$snapshot_id  = 'snap_' . substr( $content_hash, 0, 16 );

		$snapshot = array(
			'schemaVersion' => WPB_SCHEMA_VERSION,
			'snapshotId'    => $snapshot_id,
			'contentHash'   => $content_hash,
			'routeCount'    => $route_count,
			'generatedAt'   => $generated_at,
			'generator'     => array(
				'name'    => 'wp-portal-bridge',
				'version' => WPB_VERSION,
			),
			'site'          => $this->site->payload( $this ),
			'source'        => array(
				'type'       => 'wordpress',
				'homeUrl'    => home_url( '/' ),
				'wpVersion'  => $GLOBALS['wp_version'],
				'seoPlugins' => wp_list_pluck( $this->site->payload( $this )['source']['seoPlugins'], 'id' ),
			),
			'routes'        => $routes,
			'menus'         => $menus,
			'taxonomies'    => $taxonomies,
			'assets'        => $assets['assets'],
			'redirects'     => array(),
		);

		if ( null !== $page ) {
			$snapshot['chunk'] = array(
				'page'       => $effective_page,
				'perPage'    => $per_page,
				'totalPages' => (int) ceil( $route_count / $per_page ),
			);
		}

		// Record + cache (unchunked only), and clear the dirty flag: the
		// snapshot now reflects the current content version.
		$state['dirty']         = false;
		$state['last_snapshot'] = array(
			'snapshotId'  => $snapshot_id,
			'contentHash' => $content_hash,
			'routeCount'  => $route_count,
			'generatedAt' => $generated_at,
			'version'     => (int) $state['version'],
		);
		update_option( self::OPTION_CONTENT, $state, false );

		if ( null === $page ) {
			set_transient(
				self::CACHE_TRANSIENT,
				array(
					'builtFromVersion' => (int) $state['version'],
					'snapshot'         => $snapshot,
				),
				HOUR_IN_SECONDS
			);
		}

		return $snapshot;
	}

	/**
	 * Drop the cached snapshot (admin "Regenerate Snapshot").
	 */
	public function clear_cache() {
		delete_transient( self::CACHE_TRANSIENT );
		$this->mark_dirty();
	}
}
