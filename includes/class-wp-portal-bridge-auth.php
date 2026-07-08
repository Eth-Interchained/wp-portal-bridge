<?php
/**
 * WP Portal Bridge — secure tunnel authentication.
 *
 * Server-to-server HMAC (PORTAL-BRIDGE-V1). Portal signs every request with
 * PORTAL_TMK (Tunnel Master Key); this class verifies the signature before a
 * single byte of contract data is returned.
 *
 * Verification pipeline (all failures answer with the SAME generic 401 —
 * the precise reason is only recorded in the sanitized internal fail log):
 *
 *   0. Rate limit gate per client IP (429 when tripped).
 *   1. All five X-Portal-* headers present.
 *   2. Timestamp within the allowed skew window.
 *   3. Key id resolves to the current key — or the previous key inside the
 *      rotation grace window.
 *   4. Body hash matches the raw request body.
 *   5. HMAC signature matches the canonical string (constant-time compare).
 *   6. Nonce unseen inside the replay window (stored as a transient AFTER a
 *      fully successful verification, so failed probes cannot poison nonces).
 *
 * Canonical request string:
 *
 *   PORTAL-BRIDGE-V1 \n METHOD \n PATH_WITH_QUERY \n TIMESTAMP \n NONCE \n BODY_SHA256
 *
 * PATH_WITH_QUERY is the raw request target exactly as sent on the wire —
 * verified against $_SERVER['REQUEST_URI'] with NO re-encoding, re-ordering,
 * or normalization on either side.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Auth {

	const OPTION_KEYS     = 'wp_portal_bridge_keys';
	const OPTION_SETTINGS = 'wp_portal_bridge_settings';
	const OPTION_STATE    = 'wp_portal_bridge_state';
	const OPTION_FAIL_LOG = 'wp_portal_bridge_fail_log';

	const NONCE_TRANSIENT_PREFIX = 'wpb_nonce_';
	const RATE_TRANSIENT_PREFIX  = 'wpb_rate_';

	/** @var array|null Key record that verified the current request (for response signing). */
	private $verified_key = null;

	/** @var string Nonce of the current verified request (for response signing). */
	private $verified_nonce = '';

	// ─────────────────────────────────────────────────────────────────────────
	// Pure signing primitives — no WordPress dependencies. These four methods
	// are the entire algorithm and are asserted against the shared golden
	// vectors (tests/vectors/hmac-vectors.json) by tests/test-auth-vectors.php.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Build the canonical request string.
	 *
	 * @param string $method          HTTP method.
	 * @param string $path_with_query Raw request target (path + '?' + raw query), exactly as sent.
	 * @param string $timestamp       Unix seconds, base-10 string.
	 * @param string $nonce           Request nonce.
	 * @param string $body_sha256     Lowercase hex sha256 of the raw body ('' body → sha256 of empty string).
	 * @return string
	 */
	public static function canonical_request( $method, $path_with_query, $timestamp, $nonce, $body_sha256 ) {
		return implode(
			"\n",
			array(
				WPB_SIGNING_VERSION,
				strtoupper( $method ),
				$path_with_query,
				(string) $timestamp,
				$nonce,
				$body_sha256,
			)
		);
	}

	/**
	 * Build the canonical response string.
	 *
	 * @param string $request_nonce      Nonce of the request being answered.
	 * @param int    $status_code        HTTP status code.
	 * @param string $response_timestamp Unix seconds, base-10 string.
	 * @param string $body_sha256        Lowercase hex sha256 of the exact response body bytes.
	 * @return string
	 */
	public static function canonical_response( $request_nonce, $status_code, $response_timestamp, $body_sha256 ) {
		return implode(
			"\n",
			array(
				WPB_RESPONSE_SIGNING_VERSION,
				$request_nonce,
				(string) $status_code,
				(string) $response_timestamp,
				$body_sha256,
			)
		);
	}

	/**
	 * HMAC-SHA256 over a canonical string, lowercase hex.
	 *
	 * @param string $secret    TMK secret.
	 * @param string $canonical Canonical string.
	 * @return string
	 */
	public static function sign( $secret, $canonical ) {
		return hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * Lowercase hex sha256 of a raw byte string.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	public static function body_sha256( $raw ) {
		return hash( 'sha256', (string) $raw );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Settings & key management
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Effective settings with defaults.
	 *
	 * @return array
	 */
	public function settings() {
		$defaults = array(
			'skew_seconds'        => 300,   // ±5 min timestamp window.
			'nonce_ttl'           => 600,   // 10 min replay window.
			'grace_seconds'       => 3600,  // previous key honored for 1h after rotation.
			'rate_limit_max'      => 25,    // failed attempts per window per IP.
			'rate_limit_window'   => 600,
			'dev_mode'            => false, // explicit opt-in: allow unsigned requests.
			'snapshot_max_routes' => 2000,
			'per_page_default'    => 500,
			'sign_responses'      => true,
		);
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Persist settings (merged over current).
	 *
	 * @param array $patch Settings to change.
	 */
	public function update_settings( $patch ) {
		$next = array_merge( $this->settings(), $patch );
		update_option( self::OPTION_SETTINGS, $next, false );
	}

	/**
	 * Current key set: array{current: ?array, previous: ?array, grace_until: int}.
	 *
	 * @return array
	 */
	public function keys() {
		$keys = get_option( self::OPTION_KEYS, array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		return array_merge(
			array(
				'current'     => null,
				'previous'    => null,
				'grace_until' => 0,
			),
			$keys
		);
	}

	/**
	 * Whether a TMK has been generated.
	 *
	 * @return bool
	 */
	public function has_key() {
		$keys = $this->keys();
		return ! empty( $keys['current']['secret'] );
	}

	/**
	 * Generate the first TMK. Fails if one already exists (use rotate).
	 *
	 * @return array|WP_Error The new key record.
	 */
	public function generate_key() {
		if ( $this->has_key() ) {
			return new WP_Error( 'wpb_key_exists', 'A TMK already exists. Rotate it instead.' );
		}
		$key  = $this->mint_key();
		$keys = array(
			'current'     => $key,
			'previous'    => null,
			'grace_until' => 0,
		);
		// Autoload disabled: secrets never ride along on every page load.
		add_option( self::OPTION_KEYS, $keys, '', 'no' );
		update_option( self::OPTION_KEYS, $keys, false );
		return $key;
	}

	/**
	 * Rotate the TMK: current → previous (honored for grace window), new current minted.
	 * New signatures must always use the current key.
	 *
	 * @return array|WP_Error The new current key record.
	 */
	public function rotate_key() {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'wpb_no_key', 'No TMK exists yet. Generate one first.' );
		}
		$settings = $this->settings();
		$keys     = $this->keys();

		$previous            = $keys['current'];
		$previous['retired'] = time();

		$next = array(
			'current'     => $this->mint_key(),
			'previous'    => $previous,
			'grace_until' => time() + (int) $settings['grace_seconds'],
		);
		update_option( self::OPTION_KEYS, $next, false );
		return $next['current'];
	}

	/**
	 * Mint a fresh key record.
	 *
	 * @return array{id: string, secret: string, created: int}
	 */
	private function mint_key() {
		return array(
			'id'      => 'wpb_' . bin2hex( random_bytes( 6 ) ),
			'secret'  => 'portal_tmk_live_' . bin2hex( random_bytes( 24 ) ),
			'created' => time(),
		);
	}

	/**
	 * Masked secret for admin display: portal_tmk_live_ab12………cd34.
	 *
	 * @param string $secret Full secret.
	 * @return string
	 */
	public static function mask_secret( $secret ) {
		if ( strlen( $secret ) < 24 ) {
			return '••••••••';
		}
		return substr( $secret, 0, 20 ) . str_repeat( '•', 12 ) . substr( $secret, -4 );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Request verification
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * REST permission callback for every protected endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function permit( $request ) {
		$settings = $this->settings();

		$ip = $this->client_ip();

		// 0. Rate limit gate — tripped IPs get a generic 429 before any work.
		if ( $this->is_rate_limited( $ip, $settings ) ) {
			return new WP_Error(
				'wpb_rate_limited',
				'Too many requests.',
				array( 'status' => 429 )
			);
		}

		// Explicit, opt-in local development escape hatch. Default is signed-only.
		if ( ! empty( $settings['dev_mode'] ) && $this->is_local_request( $ip ) ) {
			return true;
		}

		if ( ! $this->has_key() ) {
			return $this->deny( $ip, 'no_key_configured', null );
		}

		$timestamp = $request->get_header( 'X-Portal-Timestamp' );
		$nonce     = $request->get_header( 'X-Portal-Nonce' );
		$key_id    = $request->get_header( 'X-Portal-Key-Id' );
		$body_sha  = $request->get_header( 'X-Portal-Body-SHA256' );
		$signature = $request->get_header( 'X-Portal-Signature' );

		// 1. Header presence.
		if ( ! $timestamp || ! $nonce || ! $key_id || ! $body_sha || ! $signature ) {
			return $this->deny( $ip, 'missing_headers', $key_id );
		}

		// 2. Timestamp skew.
		$now = time();
		$ts  = (int) $timestamp;
		if ( ! preg_match( '/^\d+$/', (string) $timestamp ) || abs( $now - $ts ) > (int) $settings['skew_seconds'] ) {
			return $this->deny( $ip, 'timestamp_out_of_skew', $key_id );
		}

		// 3. Key resolution: current, or previous inside the grace window.
		$key = $this->resolve_key( $key_id );
		if ( null === $key ) {
			return $this->deny( $ip, 'unknown_or_expired_key', $key_id );
		}

		// 4. Body hash must match the raw request body.
		$raw_body = $request->get_body();
		$expected_body_sha = self::body_sha256( $raw_body );
		if ( ! hash_equals( $expected_body_sha, strtolower( (string) $body_sha ) ) ) {
			return $this->deny( $ip, 'body_hash_mismatch', $key_id );
		}

		// 5. Signature over the RAW request target — no rebuilding, no re-encoding.
		$path_with_query = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$canonical       = self::canonical_request(
			$request->get_method(),
			$path_with_query,
			(string) $timestamp,
			(string) $nonce,
			$expected_body_sha
		);
		$expected_sig = self::sign( $key['secret'], $canonical );
		if ( ! hash_equals( $expected_sig, strtolower( (string) $signature ) ) ) {
			return $this->deny( $ip, 'signature_mismatch', $key_id );
		}

		// 6. Replay protection — nonce must be unseen; recorded only on success.
		$nonce_key = self::NONCE_TRANSIENT_PREFIX . sha1( (string) $nonce );
		if ( false !== get_transient( $nonce_key ) ) {
			return $this->deny( $ip, 'nonce_replayed', $key_id );
		}
		set_transient( $nonce_key, 1, (int) $settings['nonce_ttl'] );

		// Success — remember which key verified (response signing) and record the connection.
		$this->verified_key   = $key;
		$this->verified_nonce = (string) $nonce;

		$state = get_option( self::OPTION_STATE, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$state['last_connection'] = array(
			'time'   => $now,
			'key_id' => $key['id'],
			'ip'     => $ip,
			'route'  => $request->get_route(),
		);
		update_option( self::OPTION_STATE, $state, false );

		return true;
	}

	/**
	 * Resolve a key id to a usable key record.
	 *
	 * @param string $key_id Presented key id.
	 * @return array|null
	 */
	private function resolve_key( $key_id ) {
		$keys = $this->keys();
		if ( ! empty( $keys['current'] ) && hash_equals( $keys['current']['id'], (string) $key_id ) ) {
			return $keys['current'];
		}
		if (
			! empty( $keys['previous'] )
			&& (int) $keys['grace_until'] > time()
			&& hash_equals( $keys['previous']['id'], (string) $key_id )
		) {
			return $keys['previous'];
		}
		return null;
	}

	/**
	 * Deny helper: increments the rate counter, appends a sanitized entry to the
	 * internal fail log, and returns the one generic 401 every failure shares.
	 *
	 * @param string      $ip     Client IP.
	 * @param string      $reason Internal reason code (never sent to the client).
	 * @param string|null $key_id Presented key id, if any.
	 * @return WP_Error
	 */
	private function deny( $ip, $reason, $key_id ) {
		$settings = $this->settings();

		// Rate counter.
		$rate_key = self::RATE_TRANSIENT_PREFIX . md5( $ip );
		$count    = (int) get_transient( $rate_key );
		set_transient( $rate_key, $count + 1, (int) $settings['rate_limit_window'] );

		// Sanitized internal ring buffer (admin-only visibility).
		$log = get_option( self::OPTION_FAIL_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'   => time(),
			'ip'     => $ip,
			'reason' => $reason,
			'key_id' => $key_id ? substr( (string) $key_id, 0, 24 ) : null,
		);
		if ( count( $log ) > 20 ) {
			$log = array_slice( $log, -20 );
		}
		update_option( self::OPTION_FAIL_LOG, $log, false );

		$state = get_option( self::OPTION_STATE, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$state['failed_attempts'] = isset( $state['failed_attempts'] ) ? (int) $state['failed_attempts'] + 1 : 1;
		update_option( self::OPTION_STATE, $state, false );

		// One generic answer for every failure mode — no oracle for attackers.
		return new WP_Error( 'wpb_unauthorized', 'Unauthorized.', array( 'status' => 401 ) );
	}

	/**
	 * Whether an IP has tripped the failed-signature rate limit.
	 *
	 * @param string $ip       Client IP.
	 * @param array  $settings Effective settings.
	 * @return bool
	 */
	private function is_rate_limited( $ip, $settings ) {
		$count = (int) get_transient( self::RATE_TRANSIENT_PREFIX . md5( $ip ) );
		return $count >= (int) $settings['rate_limit_max'];
	}

	/**
	 * Best-effort client IP (REMOTE_ADDR only — proxy headers are spoofable and
	 * this value only feeds rate limiting and the sanitized fail log).
	 *
	 * @return string
	 */
	private function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
	}

	/**
	 * Loopback check for the dev-mode escape hatch.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function is_local_request( $ip ) {
		return in_array( $ip, array( '127.0.0.1', '::1' ), true );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Response signing
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Key record that verified the current request (null when dev-mode/unsigned).
	 *
	 * @return array|null
	 */
	public function verified_key() {
		return $this->verified_key;
	}

	/**
	 * Nonce of the current verified request.
	 *
	 * @return string
	 */
	public function verified_nonce() {
		return $this->verified_nonce;
	}

	/**
	 * Sign a response body. Returns headers to attach.
	 *
	 * @param int    $status_code HTTP status about to be sent.
	 * @param string $raw_body    Exact body bytes about to be sent.
	 * @return array<string,string> Headers (empty when signing unavailable).
	 */
	public function response_headers( $status_code, $raw_body ) {
		$settings = $this->settings();
		if ( empty( $settings['sign_responses'] ) || null === $this->verified_key ) {
			return array();
		}
		$ts        = (string) time();
		$body_sha  = self::body_sha256( $raw_body );
		$canonical = self::canonical_response( $this->verified_nonce, (int) $status_code, $ts, $body_sha );
		return array(
			'X-Portal-Response-Timestamp'   => $ts,
			'X-Portal-Response-Body-SHA256' => $body_sha,
			'X-Portal-Response-Signature'   => self::sign( $this->verified_key['secret'], $canonical ),
		);
	}
}
