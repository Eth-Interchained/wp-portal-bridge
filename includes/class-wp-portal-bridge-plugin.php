<?php
/**
 * WP Portal Bridge — plugin orchestrator.
 *
 * Wires the modules together. Boring on purpose: no framework, no container,
 * no magic — instantiation order is the dependency graph.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Plugin {

	/** @var WP_Portal_Bridge_Plugin|null */
	private static $instance = null;

	/** @var WP_Portal_Bridge_Auth */
	public $auth;

	/** @var WP_Portal_Bridge_Seo */
	public $seo;

	/** @var WP_Portal_Bridge_Site */
	public $site;

	/** @var WP_Portal_Bridge_Media */
	public $media;

	/** @var WP_Portal_Bridge_Routes */
	public $routes;

	/** @var WP_Portal_Bridge_Menus */
	public $menus;

	/** @var WP_Portal_Bridge_Taxonomies */
	public $taxonomies;

	/** @var WP_Portal_Bridge_Sitemap */
	public $sitemap;

	/** @var WP_Portal_Bridge_Snapshot */
	public $snapshot;

	/** @var WP_Portal_Bridge_Rest */
	public $rest;

	/** @var WP_Portal_Bridge_Admin */
	public $admin;

	/**
	 * Singleton accessor (bound to plugins_loaded).
	 *
	 * @return WP_Portal_Bridge_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build the module graph and register hooks.
	 */
	private function __construct() {
		$this->auth       = new WP_Portal_Bridge_Auth();
		$this->seo        = new WP_Portal_Bridge_Seo();
		$this->media      = new WP_Portal_Bridge_Media();
		$this->routes     = new WP_Portal_Bridge_Routes( $this->seo, $this->media );
		$this->menus      = new WP_Portal_Bridge_Menus();
		$this->taxonomies = new WP_Portal_Bridge_Taxonomies();
		$this->site       = new WP_Portal_Bridge_Site( $this->seo );
		$this->sitemap    = new WP_Portal_Bridge_Sitemap( $this->routes );
		$this->snapshot   = new WP_Portal_Bridge_Snapshot(
			$this->routes,
			$this->menus,
			$this->taxonomies,
			$this->media,
			$this->site,
			$this->auth
		);
		$this->rest       = new WP_Portal_Bridge_Rest(
			$this->auth,
			$this->site,
			$this->routes,
			$this->menus,
			$this->media,
			$this->taxonomies,
			$this->sitemap,
			$this->snapshot
		);

		$this->snapshot->register_invalidation_hooks();
		$this->rest->register();

		if ( is_admin() ) {
			$this->admin = new WP_Portal_Bridge_Admin( $this->auth, $this->routes, $this->snapshot );
			$this->admin->register();
		}
	}

	/**
	 * Activation: seed default settings (never keys — the admin mints those
	 * deliberately) and stamp the content state.
	 */
	public static function activate() {
		if ( false === get_option( WP_Portal_Bridge_Auth::OPTION_SETTINGS, false ) ) {
			add_option( WP_Portal_Bridge_Auth::OPTION_SETTINGS, array(), '', 'no' );
		}
		if ( false === get_option( WP_Portal_Bridge_Snapshot::OPTION_CONTENT, false ) ) {
			add_option(
				WP_Portal_Bridge_Snapshot::OPTION_CONTENT,
				array(
					'version'         => 1,
					'dirty'           => true,
					'last_changed_at' => time(),
					'last_snapshot'   => null,
				),
				'',
				'no'
			);
		}
	}
}
