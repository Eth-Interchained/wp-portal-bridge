<?php
/**
 * WP Portal Bridge — golden vector conformance test (PHP side).
 *
 * Runs WITHOUT WordPress: the four pure signing primitives on
 * WP_Portal_Bridge_Auth have no WP dependencies by design. The same vectors
 * are asserted by the TypeScript adapter test in the Portal framework — if
 * either implementation drifts, its suite fails.
 *
 * Usage: php tests/test-auth-vectors.php
 * Exit code 0 = all vectors pass.
 *
 * @package wp-portal-bridge
 */

// Minimal shims so the class file loads outside WordPress.
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'WPB_SIGNING_VERSION', 'PORTAL-BRIDGE-V1' );
define( 'WPB_RESPONSE_SIGNING_VERSION', 'PORTAL-BRIDGE-RESPONSE-V1' );

require __DIR__ . '/../includes/class-wp-portal-bridge-auth.php';

$vectors_path = __DIR__ . '/vectors/hmac-vectors.json';
if ( ! file_exists( $vectors_path ) ) {
	fwrite( STDERR, "FAIL: vectors file missing: {$vectors_path}\n" );
	fwrite( STDERR, "Run: node tools/generate-vectors.mjs\n" );
	exit( 1 );
}

$vectors = json_decode( file_get_contents( $vectors_path ), true );
if ( ! is_array( $vectors ) || empty( $vectors['requests'] ) ) {
	fwrite( STDERR, "FAIL: vectors file unreadable or empty\n" );
	exit( 1 );
}

$tmk    = $vectors['tmk'];
$passed = 0;
$failed = 0;

function assert_equal( $name, $field, $expected, $actual, &$passed, &$failed ) {
	if ( $expected === $actual ) {
		$passed++;
		return;
	}
	$failed++;
	fwrite( STDERR, "FAIL [{$name}] {$field}\n  expected: {$expected}\n  actual:   {$actual}\n" );
}

// Empty-body sha256 constant.
assert_equal(
	'constants',
	'emptyBodySha256',
	$vectors['emptyBodySha256'],
	WP_Portal_Bridge_Auth::body_sha256( '' ),
	$passed,
	$failed
);

// Request vectors.
foreach ( $vectors['requests'] as $vector ) {
	$name = $vector['name'];

	$body_sha = null === $vector['body']
		? WP_Portal_Bridge_Auth::body_sha256( '' )
		: WP_Portal_Bridge_Auth::body_sha256( $vector['body'] );

	assert_equal( $name, 'bodySha256', $vector['bodySha256'], $body_sha, $passed, $failed );

	$canonical = WP_Portal_Bridge_Auth::canonical_request(
		$vector['method'],
		$vector['pathWithQuery'],
		(string) $vector['timestamp'],
		$vector['nonce'],
		$body_sha
	);
	assert_equal( $name, 'canonical', $vector['canonical'], $canonical, $passed, $failed );

	$signature = WP_Portal_Bridge_Auth::sign( $tmk, $canonical );
	assert_equal( $name, 'signature', $vector['signature'], $signature, $passed, $failed );
}

// Response vectors.
foreach ( $vectors['responses'] as $vector ) {
	$name = $vector['name'];

	$body_sha = WP_Portal_Bridge_Auth::body_sha256( $vector['responseBody'] );
	assert_equal( $name, 'responseBodySha256', $vector['responseBodySha256'], $body_sha, $passed, $failed );

	$canonical = WP_Portal_Bridge_Auth::canonical_response(
		$vector['requestNonce'],
		(int) $vector['statusCode'],
		(string) $vector['responseTimestamp'],
		$body_sha
	);
	assert_equal( $name, 'canonical', $vector['canonical'], $canonical, $passed, $failed );

	$signature = WP_Portal_Bridge_Auth::sign( $tmk, $canonical );
	assert_equal( $name, 'signature', $vector['signature'], $signature, $passed, $failed );
}

// Key id derivation (fingerprint) matches the canonical generator.
assert_equal(
	'derive_key_id',
	'derivedKeyId',
	$vectors['derivedKeyId'],
	WP_Portal_Bridge_Auth::derive_key_id( $tmk ),
	$passed,
	$failed
);

// Cross-check: mask never leaks more than prefix + last 4.
$mask = WP_Portal_Bridge_Auth::mask_secret( $tmk );
if ( false !== strpos( $mask, substr( $tmk, 20, 12 ) ) ) {
	$failed++;
	fwrite( STDERR, "FAIL [mask_secret] mask leaks key middle\n" );
} else {
	$passed++;
}

$total = $passed + $failed;
echo "wp-portal-bridge vector conformance: {$passed}/{$total} assertions passed\n";
exit( $failed > 0 ? 1 : 0 );
