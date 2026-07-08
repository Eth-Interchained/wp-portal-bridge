<?php
/**
 * WP Portal Bridge — admin screen (WordPress Admin → Portal Bridge).
 *
 * WordPress-native, dependency-light: one options page, nonce-protected
 * admin-post actions, no SPA. The client keeps the admin panel they know.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Admin {

	const REVEAL_TRANSIENT_PREFIX = 'wpb_reveal_';
	const TEST_TRANSIENT_PREFIX   = 'wpb_selftest_';

	/** @var WP_Portal_Bridge_Auth */
	private $auth;

	/** @var WP_Portal_Bridge_Routes */
	private $routes;

	/** @var WP_Portal_Bridge_Snapshot */
	private $snapshot;

	/**
	 * @param WP_Portal_Bridge_Auth     $auth     Auth.
	 * @param WP_Portal_Bridge_Routes   $routes   Routes.
	 * @param WP_Portal_Bridge_Snapshot $snapshot Snapshot.
	 */
	public function __construct( $auth, $routes, $snapshot ) {
		$this->auth     = $auth;
		$this->routes   = $routes;
		$this->snapshot = $snapshot;
	}

	/**
	 * Hook registration.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_wpb_generate_key', array( $this, 'handle_generate_key' ) );
		add_action( 'admin_post_wpb_rotate_key', array( $this, 'handle_rotate_key' ) );
		add_action( 'admin_post_wpb_regenerate_snapshot', array( $this, 'handle_regenerate_snapshot' ) );
		add_action( 'admin_post_wpb_test_signed', array( $this, 'handle_test_signed' ) );
		add_action( 'admin_post_wpb_download_snapshot', array( $this, 'handle_download_snapshot' ) );
		add_action( 'admin_post_wpb_save_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * Top-level menu: Portal Bridge.
	 */
	public function add_menu() {
		add_menu_page(
			'Portal Bridge',
			'Portal Bridge',
			'manage_options',
			'wp-portal-bridge',
			array( $this, 'render' ),
			'dashicons-randomize',
			81
		);
	}

	/**
	 * Assets, scoped to our screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'toplevel_page_wp-portal-bridge' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wpb-admin', WPB_PLUGIN_URL . 'assets/admin.css', array(), WPB_VERSION );
		wp_enqueue_script( 'wpb-admin', WPB_PLUGIN_URL . 'assets/admin.js', array(), WPB_VERSION, true );
	}

	// ── Action handlers ──────────────────────────────────────────────────────

	/**
	 * Generate the first TMK, stage the one-time reveal.
	 */
	public function handle_generate_key() {
		$this->guard( 'wpb_generate_key' );
		$key = $this->auth->generate_key();
		if ( ! is_wp_error( $key ) ) {
			set_transient( self::REVEAL_TRANSIENT_PREFIX . get_current_user_id(), $key['secret'], 5 * MINUTE_IN_SECONDS );
		}
		$this->back( is_wp_error( $key ) ? array( 'wpb_error' => $key->get_error_code() ) : array( 'wpb_notice' => 'key_generated' ) );
	}

	/**
	 * Rotate the TMK (current → previous with grace), stage the reveal.
	 */
	public function handle_rotate_key() {
		$this->guard( 'wpb_rotate_key' );
		$key = $this->auth->rotate_key();
		if ( ! is_wp_error( $key ) ) {
			set_transient( self::REVEAL_TRANSIENT_PREFIX . get_current_user_id(), $key['secret'], 5 * MINUTE_IN_SECONDS );
		}
		$this->back( is_wp_error( $key ) ? array( 'wpb_error' => $key->get_error_code() ) : array( 'wpb_notice' => 'key_rotated' ) );
	}

	/**
	 * Drop the snapshot cache and mark dirty.
	 */
	public function handle_regenerate_snapshot() {
		$this->guard( 'wpb_regenerate_snapshot' );
		$this->snapshot->clear_cache();
		$this->back( array( 'wpb_notice' => 'snapshot_cleared' ) );
	}

	/**
	 * Loop-back self test: sign a request to our own /health with the stored
	 * TMK and verify it round-trips through the real HTTP + auth stack.
	 */
	public function handle_test_signed() {
		$this->guard( 'wpb_test_signed' );

		$keys = $this->auth->keys();
		if ( empty( $keys['current']['secret'] ) ) {
			$this->back( array( 'wpb_error' => 'no_key' ) );
		}

		$url   = rest_url( WPB_REST_NAMESPACE . '/health' );
		$parts = wp_parse_url( $url );
		$path_with_query = $parts['path'] . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );

		$timestamp = (string) time();
		$nonce     = 'selftest-' . bin2hex( random_bytes( 8 ) );
		$body_sha  = WP_Portal_Bridge_Auth::body_sha256( '' );
		$canonical = WP_Portal_Bridge_Auth::canonical_request( 'GET', $path_with_query, $timestamp, $nonce, $body_sha );
		$signature = WP_Portal_Bridge_Auth::sign( $keys['current']['secret'], $canonical );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => apply_filters( 'wpb_selftest_sslverify', true ),
				'headers'   => array(
					'X-Portal-Timestamp'   => $timestamp,
					'X-Portal-Nonce'       => $nonce,
					'X-Portal-Key-Id'      => $keys['current']['id'],
					'X-Portal-Body-SHA256' => $body_sha,
					'X-Portal-Signature'   => $signature,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'ok'     => false,
				'error'  => $response->get_error_message(),
				'time'   => time(),
			);
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			$resp_sig_ok = null;
			$resp_sig    = wp_remote_retrieve_header( $response, 'x-portal-response-signature' );
			$resp_ts     = wp_remote_retrieve_header( $response, 'x-portal-response-timestamp' );
			if ( $resp_sig && $resp_ts ) {
				$resp_canonical = WP_Portal_Bridge_Auth::canonical_response(
					$nonce,
					$code,
					(string) $resp_ts,
					WP_Portal_Bridge_Auth::body_sha256( $body )
				);
				$resp_sig_ok = hash_equals(
					WP_Portal_Bridge_Auth::sign( $keys['current']['secret'], $resp_canonical ),
					strtolower( (string) $resp_sig )
				);
			}

			$result = array(
				'ok'                => 200 === $code,
				'status'            => $code,
				'responseSignature' => $resp_sig_ok,
				'time'              => time(),
			);
		}

		set_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		$this->back( array( 'wpb_notice' => 'selftest_done' ) );
	}

	/**
	 * Download the current snapshot as a JSON file.
	 */
	public function handle_download_snapshot() {
		$this->guard( 'wpb_download_snapshot' );

		$snapshot = $this->snapshot->build( null, 0 );
		if ( is_wp_error( $snapshot ) ) {
			$this->back( array( 'wpb_error' => $snapshot->get_error_code() ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="portal-bridge-snapshot-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $snapshot, JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Persist settings.
	 */
	public function handle_save_settings() {
		$this->guard( 'wpb_save_settings' );

		$this->auth->update_settings(
			array(
				'dev_mode'            => isset( $_POST['dev_mode'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'sign_responses'      => isset( $_POST['sign_responses'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'skew_seconds'        => max( 30, absint( $_POST['skew_seconds'] ?? 300 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'grace_seconds'       => max( 0, absint( $_POST['grace_seconds'] ?? 3600 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'rate_limit_max'      => max( 5, absint( $_POST['rate_limit_max'] ?? 25 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'snapshot_max_routes' => max( 50, absint( $_POST['snapshot_max_routes'] ?? 2000 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		$this->back( array( 'wpb_notice' => 'settings_saved' ) );
	}

	// ── Rendering ────────────────────────────────────────────────────────────

	/**
	 * The Portal Bridge screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->auth->settings();
		$keys     = $this->auth->keys();
		$has_key  = $this->auth->has_key();
		$state    = get_option( WP_Portal_Bridge_Auth::OPTION_STATE, array() );
		$fail_log = get_option( WP_Portal_Bridge_Auth::OPTION_FAIL_LOG, array() );
		$reveal   = get_transient( self::REVEAL_TRANSIENT_PREFIX . get_current_user_id() );
		$selftest = get_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id() );
		$last_meta = $this->snapshot->last_snapshot_meta();

		if ( $reveal ) {
			delete_transient( self::REVEAL_TRANSIENT_PREFIX . get_current_user_id() );
		}
		if ( $selftest ) {
			delete_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id() );
		}

		$routes_preview = $this->routes->list_routes( 1, 10 );
		$route_total    = $routes_preview['meta']['total'];

		$env_base = untrailingslashit( home_url() );
		$env_tmk  = $reveal ? $reveal : ( $has_key ? WP_Portal_Bridge_Auth::mask_secret( $keys['current']['secret'] ) : 'generate a TMK first' );

		echo '<div class="wrap wpb-wrap">';
		echo '<h1>⬡ Portal Bridge</h1>';
		echo '<p class="wpb-tagline">Keep WordPress as your backend. Upgrade the website your customers actually see.</p>';

		$this->render_notices();

		// ── Connection Status ────────────────────────────────────────────────
		echo '<div class="wpb-grid">';

		echo '<div class="wpb-card">';
		echo '<h2>Connection Status</h2>';
		$last = ( is_array( $state ) && ! empty( $state['last_connection'] ) ) ? $state['last_connection'] : null;
		if ( $last ) {
			echo '<p class="wpb-status wpb-ok">● Portal connected</p>';
			echo '<p>Last verified request: <strong>' . esc_html( human_time_diff( (int) $last['time'] ) ) . ' ago</strong>';
			echo ' &middot; key <code>' . esc_html( $last['key_id'] ) . '</code>';
			echo ' &middot; route <code>' . esc_html( $last['route'] ) . '</code></p>';
		} elseif ( $has_key ) {
			echo '<p class="wpb-status wpb-wait">● Waiting for first signed request</p>';
			echo '<p>TMK is minted. Point Portal at this site with the env block below.</p>';
		} else {
			echo '<p class="wpb-status wpb-off">● Not connected</p>';
			echo '<p>Generate a PORTAL_TMK to open the tunnel.</p>';
		}
		echo '</div>';

		// ── Secure Tunnel Status ────────────────────────────────────────────
		echo '<div class="wpb-card">';
		echo '<h2>Secure Tunnel Status</h2>';
		echo '<table class="wpb-kv">';
		echo '<tr><td>Mode</td><td>' . ( empty( $settings['dev_mode'] ) ? '<span class="wpb-ok">signed-only (HMAC-SHA256)</span>' : '<span class="wpb-warn">DEV MODE — unsigned loopback allowed</span>' ) . '</td></tr>';
		echo '<tr><td>Signing version</td><td><code>' . esc_html( WPB_SIGNING_VERSION ) . '</code></td></tr>';
		echo '<tr><td>Timestamp skew</td><td>±' . esc_html( (string) $settings['skew_seconds'] ) . 's</td></tr>';
		echo '<tr><td>Replay window</td><td>' . esc_html( (string) $settings['nonce_ttl'] ) . 's nonce TTL</td></tr>';
		echo '<tr><td>Response signing</td><td>' . ( ! empty( $settings['sign_responses'] ) ? 'on' : 'off' ) . '</td></tr>';
		echo '<tr><td>Active key</td><td>' . ( $has_key ? '<code>' . esc_html( $keys['current']['id'] ) . '</code>' : '—' ) . '</td></tr>';
		if ( ! empty( $keys['previous'] ) && (int) $keys['grace_until'] > time() ) {
			echo '<tr><td>Previous key</td><td><code>' . esc_html( $keys['previous']['id'] ) . '</code> honored ' . esc_html( human_time_diff( time(), (int) $keys['grace_until'] ) ) . ' more</td></tr>';
		}
		echo '</table>';
		echo '</div>';

		// ── PORTAL_TMK management ───────────────────────────────────────────
		echo '<div class="wpb-card wpb-card-wide">';
		echo '<h2>PORTAL_TMK — Tunnel Master Key</h2>';

		if ( $reveal ) {
			echo '<div class="wpb-reveal"><p><strong>Copy this now.</strong> The full key is shown once and stored hashed-equivalent (masked) from here on.</p></div>';
		}

		echo '<p>Portal env config' . ( $reveal ? '' : ( $has_key ? ' (key masked — rotate or regenerate to reveal a fresh one)' : '' ) ) . ':</p>';
		echo '<pre class="wpb-env" id="wpb-env-block">PORTAL_BRIDGE_BASE_URL=' . esc_html( $env_base ) . "\n" . 'PORTAL_TMK=' . esc_html( $env_tmk ) . '</pre>';
		echo '<button type="button" class="button" data-wpb-copy="#wpb-env-block">Copy Portal env config</button> ';

		if ( ! $has_key ) {
			$this->action_button( 'wpb_generate_key', 'Connect Portal Frontend — Generate PORTAL_TMK', 'primary' );
		} else {
			$this->action_button( 'wpb_rotate_key', 'Rotate PORTAL_TMK', 'secondary', 'Rotating mints a new key. The previous key keeps working for the grace window (' . (int) $settings['grace_seconds'] . 's) so deployed Portals do not break mid-rotation. Continue?' );
			$this->action_button( 'wpb_test_signed', 'Test Signed Request', 'secondary' );
		}

		if ( $selftest ) {
			echo '<div class="wpb-selftest ' . ( ! empty( $selftest['ok'] ) ? 'wpb-ok' : 'wpb-err' ) . '">';
			echo '<strong>Self test:</strong> ';
			if ( ! empty( $selftest['ok'] ) ) {
				echo 'signed request verified end-to-end (HTTP 200';
				if ( isset( $selftest['responseSignature'] ) ) {
					echo true === $selftest['responseSignature'] ? ', response signature valid' : ( false === $selftest['responseSignature'] ? ', response signature INVALID' : '' );
				}
				echo ').';
			} else {
				echo 'failed — HTTP ' . esc_html( (string) ( $selftest['status'] ?? '?' ) ) . ( ! empty( $selftest['error'] ) ? ' (' . esc_html( $selftest['error'] ) . ')' : '' ) . '.';
			}
			echo '</div>';
		}
		echo '</div>';

		// ── Site Snapshot ───────────────────────────────────────────────────
		echo '<div class="wpb-card">';
		echo '<h2>Site Snapshot</h2>';
		echo '<table class="wpb-kv">';
		echo '<tr><td>Published routes</td><td><strong>' . esc_html( (string) $route_total ) . '</strong></td></tr>';
		echo '<tr><td>Content version</td><td>v' . esc_html( (string) $this->snapshot->content_version() ) . '</td></tr>';
		echo '<tr><td>Snapshot state</td><td>' . ( $this->snapshot->is_dirty() ? '<span class="wpb-warn">dirty — content changed since last snapshot</span>' : '<span class="wpb-ok">clean</span>' ) . '</td></tr>';
		if ( $last_meta ) {
			echo '<tr><td>Last snapshot</td><td><code>' . esc_html( $last_meta['snapshotId'] ) . '</code> · ' . esc_html( (string) $last_meta['routeCount'] ) . ' routes · ' . esc_html( $last_meta['generatedAt'] ) . '</td></tr>';
			echo '<tr><td>Content hash</td><td><code class="wpb-hash">' . esc_html( substr( $last_meta['contentHash'], 0, 24 ) ) . '…</code></td></tr>';
		}
		echo '</table>';
		$this->action_button( 'wpb_regenerate_snapshot', 'Regenerate Snapshot', 'secondary' );
		$this->action_button( 'wpb_download_snapshot', 'Download Manifest Snapshot', 'secondary' );
		echo '</div>';

		// ── Portal API Status / Contract Preview ───────────────────────────
		echo '<div class="wpb-card">';
		echo '<h2>Portal API Status</h2>';
		echo '<p>Contract endpoints under <code>' . esc_html( untrailingslashit( (string) wp_parse_url( rest_url( WPB_REST_NAMESPACE ), PHP_URL_PATH ) ) ) . '/</code>:</p>';
		echo '<ul class="wpb-endpoints">';
		foreach ( array( 'health', 'site', 'routes', 'route?path=/', 'menus', 'assets', 'taxonomies', 'sitemap', 'snapshot' ) as $endpoint ) {
			echo '<li><code>' . esc_html( $endpoint ) . '</code></li>';
		}
		echo '</ul>';
		echo '<p class="wpb-dim">All endpoints require a valid PORTAL-BRIDGE-V1 signature. Unsigned, stale, replayed, or tampered requests receive a generic 401.</p>';
		echo '</div>';

		// ── Content Contract Preview ────────────────────────────────────────
		echo '<div class="wpb-card wpb-card-wide">';
		echo '<h2>Content Contract Preview — Routes</h2>';
		echo '<table class="widefat striped wpb-routes"><thead><tr><th>Path</th><th>Type</th><th>Title</th><th>Modified</th></tr></thead><tbody>';
		foreach ( $routes_preview['routes'] as $route ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $route['path'] ) . '</code></td>';
			echo '<td>' . esc_html( $route['type'] ) . '</td>';
			echo '<td>' . esc_html( $route['title'] ) . '</td>';
			echo '<td>' . esc_html( $route['dates']['modified'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( $route_total > 10 ) {
			echo '<p class="wpb-dim">Showing 10 of ' . esc_html( (string) $route_total ) . ' routes. The full set ships in the snapshot.</p>';
		}
		echo '</div>';

		// ── Failed attempts + last connection ───────────────────────────────
		echo '<div class="wpb-card">';
		echo '<h2>Failed Signature Attempts</h2>';
		$fail_total = ( is_array( $state ) && isset( $state['failed_attempts'] ) ) ? (int) $state['failed_attempts'] : 0;
		echo '<p><strong>' . esc_html( (string) $fail_total ) . '</strong> rejected requests since activation.</p>';
		if ( is_array( $fail_log ) && ! empty( $fail_log ) ) {
			echo '<table class="wpb-kv wpb-faillog">';
			foreach ( array_reverse( array_slice( $fail_log, -8 ) ) as $entry ) {
				echo '<tr><td>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) ) . 'Z</td><td><code>' . esc_html( $entry['reason'] ) . '</code></td><td>' . esc_html( $entry['ip'] ) . '</td></tr>';
			}
			echo '</table>';
			echo '<p class="wpb-dim">Reasons are internal only — callers always receive a generic 401.</p>';
		}
		echo '</div>';

		// ── Settings ────────────────────────────────────────────────────────
		echo '<div class="wpb-card">';
		echo '<h2>Tunnel Settings</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpb_save_settings' );
		echo '<input type="hidden" name="action" value="wpb_save_settings" />';
		echo '<table class="wpb-kv">';
		echo '<tr><td><label for="wpb-skew">Timestamp skew (s)</label></td><td><input id="wpb-skew" name="skew_seconds" type="number" min="30" value="' . esc_attr( (string) $settings['skew_seconds'] ) . '" /></td></tr>';
		echo '<tr><td><label for="wpb-grace">Rotation grace (s)</label></td><td><input id="wpb-grace" name="grace_seconds" type="number" min="0" value="' . esc_attr( (string) $settings['grace_seconds'] ) . '" /></td></tr>';
		echo '<tr><td><label for="wpb-rate">Rate limit (fails/window)</label></td><td><input id="wpb-rate" name="rate_limit_max" type="number" min="5" value="' . esc_attr( (string) $settings['rate_limit_max'] ) . '" /></td></tr>';
		echo '<tr><td><label for="wpb-maxroutes">Snapshot route ceiling</label></td><td><input id="wpb-maxroutes" name="snapshot_max_routes" type="number" min="50" value="' . esc_attr( (string) $settings['snapshot_max_routes'] ) . '" /></td></tr>';
		echo '<tr><td><label for="wpb-signresp">Sign responses</label></td><td><input id="wpb-signresp" name="sign_responses" type="checkbox" ' . checked( ! empty( $settings['sign_responses'] ), true, false ) . ' /></td></tr>';
		echo '<tr><td><label for="wpb-dev">Dev mode (allow unsigned loopback)</label></td><td><input id="wpb-dev" name="dev_mode" type="checkbox" ' . checked( ! empty( $settings['dev_mode'] ), true, false ) . ' /> <span class="wpb-dim">never enable in production</span></td></tr>';
		echo '</table>';
		submit_button( 'Save Tunnel Settings' );
		echo '</form>';
		echo '</div>';

		echo '</div>'; // .wpb-grid
		echo '</div>'; // .wrap
	}

	/**
	 * Inline admin-post action button.
	 *
	 * @param string $action  Action slug.
	 * @param string $label   Button label.
	 * @param string $style   primary|secondary.
	 * @param string $confirm Optional confirm() message.
	 */
	private function action_button( $action, $label, $style = 'secondary', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpb-inline-form"' . ( $confirm ? ' data-wpb-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<button type="submit" class="button ' . ( 'primary' === $style ? 'button-primary' : '' ) . '">' . esc_html( $label ) . '</button>';
		echo '</form> ';
	}

	/**
	 * Admin notices from redirect query args.
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only notices.
		if ( ! empty( $_GET['wpb_notice'] ) ) {
			$messages = array(
				'key_generated'    => 'PORTAL_TMK generated. Copy the env block below — the full key is shown this once.',
				'key_rotated'      => 'PORTAL_TMK rotated. Previous key stays valid for the grace window. Copy the new env block now.',
				'snapshot_cleared' => 'Snapshot cache cleared. The next snapshot request rebuilds from live content.',
				'selftest_done'    => 'Self test completed — result below.',
				'settings_saved'   => 'Tunnel settings saved.',
			);
			$key = sanitize_key( wp_unslash( $_GET['wpb_notice'] ) );
			if ( isset( $messages[ $key ] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
			}
		}
		if ( ! empty( $_GET['wpb_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>Action failed: <code>' . esc_html( sanitize_key( wp_unslash( $_GET['wpb_error'] ) ) ) . '</code></p></div>';
		}
		// phpcs:enable
	}

	/**
	 * Capability + nonce gate for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the Portal Bridge screen.
	 *
	 * @param array $args Extra query args.
	 */
	private function back( $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=wp-portal-bridge' ) ) );
		exit;
	}
}
