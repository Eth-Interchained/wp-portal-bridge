<?php
/**
 * WP Portal Bridge — site identity and settings payloads.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Site {

	/** @var WP_Portal_Bridge_Seo */
	private $seo;

	/**
	 * @param WP_Portal_Bridge_Seo $seo SEO extractor (plugin detection).
	 */
	public function __construct( $seo ) {
		$this->seo = $seo;
	}

	/**
	 * Site identity payload for /site.
	 *
	 * @param WP_Portal_Bridge_Snapshot $snapshot Snapshot state (content version).
	 * @return array
	 */
	public function payload( $snapshot ) {
		global $wp_version;

		$front_id = (int) get_option( 'page_on_front' );
		$blog_id  = (int) get_option( 'page_for_posts' );

		return array(
			'schemaVersion' => WPB_SCHEMA_VERSION,
			'name'          => get_bloginfo( 'name' ),
			'description'   => get_bloginfo( 'description' ),
			'homeUrl'       => home_url( '/' ),
			'siteUrl'       => site_url( '/' ),
			'restUrl'       => get_rest_url(),
			'language'      => get_bloginfo( 'language' ),
			'timezone'      => wp_timezone_string(),
			'permalinks'    => array(
				'structure' => (string) get_option( 'permalink_structure' ),
				'pretty'    => '' !== (string) get_option( 'permalink_structure' ),
			),
			'frontPage'     => array(
				'mode'   => 'page' === get_option( 'show_on_front' ) ? 'static_page' : 'latest_posts',
				'pageId' => $front_id > 0 ? $front_id : null,
				'blogId' => $blog_id > 0 ? $blog_id : null,
			),
			'source'        => array(
				'type'       => 'wordpress',
				'wpVersion'  => $wp_version,
				'phpVersion' => PHP_VERSION,
				'seoPlugins' => $this->seo->detect_plugins(),
			),
			'generator'     => array(
				'name'    => 'wp-portal-bridge',
				'version' => WPB_VERSION,
			),
			'content'       => array(
				'version'       => $snapshot->content_version(),
				'lastChangedAt' => $snapshot->last_changed_iso(),
				'dirty'         => $snapshot->is_dirty(),
			),
		);
	}

	/**
	 * Health payload for /health — status only, no content, no secrets.
	 *
	 * @param WP_Portal_Bridge_Auth     $auth     Auth (key ids only).
	 * @param WP_Portal_Bridge_Snapshot $snapshot Snapshot state.
	 * @return array
	 */
	public function health( $auth, $snapshot ) {
		global $wp_version;

		$keys     = $auth->keys();
		$settings = $auth->settings();
		$state    = get_option( WP_Portal_Bridge_Auth::OPTION_STATE, array() );
		$last     = ( is_array( $state ) && isset( $state['last_connection'] ) ) ? $state['last_connection'] : null;

		return array(
			'ok'             => true,
			'plugin'         => 'wp-portal-bridge',
			'version'        => WPB_VERSION,
			'schemaVersion'  => WPB_SCHEMA_VERSION,
			'signingVersion' => WPB_SIGNING_VERSION,
			'wpVersion'      => $wp_version,
			'phpVersion'     => PHP_VERSION,
			'time'           => time(),
			'security'       => array(
				'signedOnly'    => empty( $settings['dev_mode'] ),
				'devMode'       => (bool) $settings['dev_mode'],
				'signResponses' => (bool) $settings['sign_responses'],
				'skewSeconds'   => (int) $settings['skew_seconds'],
				'keys'          => array(
					'currentId'  => ! empty( $keys['current'] ) ? $keys['current']['id'] : null,
					'previousId' => ( ! empty( $keys['previous'] ) && (int) $keys['grace_until'] > time() ) ? $keys['previous']['id'] : null,
					'graceUntil' => (int) $keys['grace_until'] > time() ? (int) $keys['grace_until'] : null,
				),
			),
			'content'        => array(
				'version'       => $snapshot->content_version(),
				'lastChangedAt' => $snapshot->last_changed_iso(),
				'dirty'         => $snapshot->is_dirty(),
			),
			'lastConnection' => $last ? array(
				'time'  => (int) $last['time'],
				'keyId' => (string) $last['key_id'],
			) : null,
		);
	}
}
