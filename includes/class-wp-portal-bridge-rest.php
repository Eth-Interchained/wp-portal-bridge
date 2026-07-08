<?php
/**
 * WP Portal Bridge — protected REST endpoints (the contract surface).
 *
 *   GET /wp-json/wp-portal-bridge/v1/health
 *   GET /wp-json/wp-portal-bridge/v1/site
 *   GET /wp-json/wp-portal-bridge/v1/routes?page=&perPage=
 *   GET /wp-json/wp-portal-bridge/v1/route?path=/some/path
 *   GET /wp-json/wp-portal-bridge/v1/menus
 *   GET /wp-json/wp-portal-bridge/v1/assets?page=&perPage=
 *   GET /wp-json/wp-portal-bridge/v1/taxonomies
 *   GET /wp-json/wp-portal-bridge/v1/sitemap
 *   GET /wp-json/wp-portal-bridge/v1/snapshot?page=&perPage=
 *
 * Every endpoint is signed-only by default (see WP_Portal_Bridge_Auth), and
 * every response from this namespace is response-signed so Portal can verify
 * integrity end to end.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Rest {

	/** @var WP_Portal_Bridge_Auth */
	private $auth;

	/** @var WP_Portal_Bridge_Site */
	private $site;

	/** @var WP_Portal_Bridge_Routes */
	private $routes;

	/** @var WP_Portal_Bridge_Menus */
	private $menus;

	/** @var WP_Portal_Bridge_Media */
	private $media;

	/** @var WP_Portal_Bridge_Taxonomies */
	private $taxonomies;

	/** @var WP_Portal_Bridge_Sitemap */
	private $sitemap;

	/** @var WP_Portal_Bridge_Snapshot */
	private $snapshot;

	/**
	 * Wire the modules.
	 *
	 * @param WP_Portal_Bridge_Auth       $auth       Auth.
	 * @param WP_Portal_Bridge_Site       $site       Site.
	 * @param WP_Portal_Bridge_Routes     $routes     Routes.
	 * @param WP_Portal_Bridge_Menus      $menus      Menus.
	 * @param WP_Portal_Bridge_Media      $media      Media.
	 * @param WP_Portal_Bridge_Taxonomies $taxonomies Taxonomies.
	 * @param WP_Portal_Bridge_Sitemap    $sitemap    Sitemap.
	 * @param WP_Portal_Bridge_Snapshot   $snapshot   Snapshot.
	 */
	public function __construct( $auth, $site, $routes, $menus, $media, $taxonomies, $sitemap, $snapshot ) {
		$this->auth       = $auth;
		$this->site       = $site;
		$this->routes     = $routes;
		$this->menus      = $menus;
		$this->media      = $media;
		$this->taxonomies = $taxonomies;
		$this->sitemap    = $sitemap;
		$this->snapshot   = $snapshot;
	}

	/**
	 * Hook registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_signed_response' ), 10, 4 );
	}

	/**
	 * Register every endpoint under wp-portal-bridge/v1.
	 */
	public function register_routes() {
		$permission = array( $this->auth, 'permit' );

		$paged_args = array(
			'page'    => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'perPage' => array(
				'type'    => 'integer',
				'default' => 0, // 0 → settings default.
				'minimum' => 0,
				'maximum' => 1000,
			),
		);

		register_rest_route(
			WPB_REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( $this, 'get_health' ),
			)
		);

		register_rest_route(
			WPB_REST_NAMESPACE,
			'/site',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( $this, 'get_site' ),
			)
		);

		register_rest_route(
			WPB_REST_NAMESPACE,
			'/routes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( $this, 'get_routes' ),
				'args'                => $paged_args,
			)
		);

		register_rest_route(
			WPB_REST_NAMESPACE,
			'/route',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( $this, 'get_route' ),
				'args'                => array(
					'path' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			WPB_REST_NAMESPACE,
			'/menus',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( $this, 'get_menus' ),
			)
		);

		register_rest_route(
			WPB_REST_NAMESPACE,
			'/assets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( $this, 'get_assets' ),
				'args'                => $paged_args,
			)
		);

		register_rest_route(
			WPB_REST_NAMESPACE,
			'/taxonomies',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( $this, 'get_taxonomies' ),
			)
		);

		register_rest_route(
			WPB_REST_NAMESPACE,
			'/sitemap',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( $this, 'get_sitemap' ),
			)
		);

		register_rest_route(
			WPB_REST_NAMESPACE,
			'/snapshot',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $permission,
				'callback'            => array( $this, 'get_snapshot' ),
				'args'                => $paged_args,
			)
		);
	}

	// ── Callbacks ────────────────────────────────────────────────────────────

	/** @return array */
	public function get_health() {
		return $this->site->health( $this->auth, $this->snapshot );
	}

	/** @return array */
	public function get_site() {
		return $this->site->payload( $this->snapshot );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function get_routes( $request ) {
		$settings = $this->auth->settings();
		$per_page = (int) $request->get_param( 'perPage' );
		if ( $per_page <= 0 ) {
			$per_page = (int) $settings['per_page_default'];
		}
		return $this->routes->list_routes( (int) $request->get_param( 'page' ), $per_page );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function get_route( $request ) {
		$path  = (string) $request->get_param( 'path' );
		$route = $this->routes->route_by_path( $path );
		if ( null === $route ) {
			return new WP_Error(
				'wpb_route_not_found',
				'No published route at that path.',
				array( 'status' => 404 )
			);
		}
		return $route;
	}

	/** @return array */
	public function get_menus() {
		return array( 'menus' => $this->menus->list_menus() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function get_assets( $request ) {
		$settings = $this->auth->settings();
		$per_page = (int) $request->get_param( 'perPage' );
		if ( $per_page <= 0 ) {
			$per_page = (int) $settings['per_page_default'];
		}
		return $this->media->list_assets(
			$this->routes->published_posts(),
			(int) $request->get_param( 'page' ),
			$per_page
		);
	}

	/** @return array */
	public function get_taxonomies() {
		return array( 'taxonomies' => $this->taxonomies->list_taxonomies() );
	}

	/** @return array */
	public function get_sitemap() {
		return $this->sitemap->entries();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function get_snapshot( $request ) {
		// Chunked only when the caller explicitly paged; a bare GET /snapshot
		// asks for the full envelope (and may get the 413 size guard).
		$explicit_page = array_key_exists( 'page', $request->get_query_params() );

		return $this->snapshot->build(
			$explicit_page ? (int) $request->get_param( 'page' ) : null,
			(int) $request->get_param( 'perPage' )
		);
	}

	// ── Response signing ─────────────────────────────────────────────────────

	/**
	 * Serve wp-portal-bridge responses ourselves so the exact body bytes can be
	 * hashed and signed (PORTAL-BRIDGE-RESPONSE-V1). Other namespaces untouched.
	 *
	 * @param bool             $served  Whether the request has been served.
	 * @param WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public function serve_signed_response( $served, $result, $request, $server ) {
		if ( $served ) {
			return $served;
		}
		if ( 0 !== strpos( (string) $request->get_route(), '/' . WPB_REST_NAMESPACE ) ) {
			return $served;
		}

		$data = $server->response_to_data( $result, false );
		$body = wp_json_encode( $data );
		if ( false === $body ) {
			return $served; // Fall back to core serving on encode failure.
		}

		$status = $result->get_status();

		foreach ( $result->get_headers() as $name => $value ) {
			$server->send_header( $name, $value );
		}
		$server->send_header( 'Content-Type', 'application/json; charset=' . get_option( 'blog_charset' ) );

		foreach ( $this->auth->response_headers( $status, $body ) as $name => $value ) {
			$server->send_header( $name, $value );
		}

		status_header( $status );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- exact signed JSON bytes.

		return true;
	}
}
