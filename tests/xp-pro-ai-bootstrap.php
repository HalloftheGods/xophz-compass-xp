<?php
/**
 * Bootstrap for the XP Pro AI specs (tests/xp-pro-ai.test.mjs): the few WordPress functions and
 * classes the proxy touches, in memory, and a stand-in for the three Gatekeeper helper functions
 * it calls (gatekeeper_license_for_key, gatekeeper_counter_bump, gatekeeper_rate_limited).
 *
 * A spec sets $GLOBALS['xp_omit'] to a list of helper names before requiring this file to see a
 * site where Gatekeeper does not define them. Everything the stand-ins record lives in
 * $GLOBALS['gk']: licenses (key => record), calls to each helper, counters and rate buckets.
 */

$GLOBALS['options']    = array();
$GLOBALS['http']       = array();
$GLOBALS['http_reply'] = null;
$GLOBALS['env_type']   = 'production';
$GLOBALS['routes']     = array();
$GLOBALS['gk']         = array(
	'licenses'   => array(),
	'for_key'    => array(),
	'bumps'      => array(),
	'counters'   => array(),
	'rate_calls' => array(),
	'rates'      => array(),
	'force'      => null,
);

class WP_Error {
	protected $code;
	protected $message;
	protected $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function get_option( $name, $default = false ) {
	return $GLOBALS['options'][ $name ] ?? $default;
}
function update_option( $name, $value ) {
	$GLOBALS['options'][ $name ] = $value;
	return true;
}
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}
function wp_get_environment_type() {
	return $GLOBALS['env_type'];
}
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['http'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['http_reply'] ?? new WP_Error( 'offline', 'No reply stubbed' );
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
}
function register_rest_route( $namespace, $route, $args = array() ) {
	$GLOBALS['routes'][] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
	return true;
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
}
class WP_REST_Request {
	protected $params = array();
	public function __construct( $method = 'GET', $route = '' ) {}
	public function set_param( $key, $value ) {
		$this->params[ $key ] = $value;
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}
class WP_REST_Response {
	protected $data;
	protected $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_status() {
		return $this->status;
	}
}

/*
 * The Gatekeeper helpers, as small stand-ins that follow the real contract (board epic #1552):
 *  gatekeeper_license_for_key: invalid_input 400 (bad key or device shape), not_found 404, pro_required
 *  402 (checked BEFORE the device is attached), device_limit 409, busy 503; a new device takes a free
 *  slot; it does not judge the sandbox env. Entitlement: status active and either no end or now short
 *  of the end plus the 3 day grace.
 *  gatekeeper_counter_bump: a named counter, never below zero, delta -1 takes one back.
 *  gatekeeper_rate_limited: true once a bucket (and scope) has been asked for more than $max times.
 */
$omit = $GLOBALS['xp_omit'] ?? array();

if ( ! in_array( 'gatekeeper_license_for_key', $omit, true ) ) {
	function gatekeeper_license_for_key( string $product, string $key, string $device ) {
		$GLOBALS['gk']['for_key'][] = array( $product, $key, $device );
		if ( null !== $GLOBALS['gk']['force'] ) {
			list( $code, $status ) = $GLOBALS['gk']['force'];
			return new WP_Error( $code, 'forced', array( 'status' => $status ) );
		}
		if ( 'xp-pro' !== $product ) {
			return new WP_Error( 'invalid_input', 'Unknown license product.', array( 'status' => 400 ) );
		}
		$key        = strtoupper( trim( $key ) );
		$key_ok     = 1 === preg_match( '/\AXP-PRO-(?:[A-HJ-NP-Z2-9]{4}-){2}[A-HJ-NP-Z2-9]{4}\z/', $key );
		$device_ok  = 1 === preg_match( '/\A[A-Za-z0-9_-]{8,64}\z/', $device );
		if ( ! $key_ok || ! $device_ok ) {
			return new WP_Error( 'invalid_input', 'Invalid license key or device id.', array( 'status' => 400 ) );
		}
		$record = $GLOBALS['gk']['licenses'][ $key ] ?? null;
		if ( null === $record ) {
			return new WP_Error( 'not_found', 'No license with this key.', array( 'status' => 404 ) );
		}
		$ends      = $record['expires_at'] ?? null;
		$is_active = 'active' === ( $record['status'] ?? '' ) && ( null === $ends || time() < $ends + 259200 );
		if ( ! $is_active ) {
			return new WP_Error( 'pro_required', 'This license is not active.', array( 'status' => 402 ) );
		}
		if ( ! in_array( $device, $record['devices'], true ) ) {
			if ( count( $record['devices'] ) >= 10 ) {
				return new WP_Error( 'device_limit', 'This key is on 10 devices.', array( 'status' => 409 ) );
			}
			$record['devices'][]                      = $device;
			$GLOBALS['gk']['licenses'][ $key ]        = $record;
		}
		return $record;
	}
}

if ( ! in_array( 'gatekeeper_counter_bump', $omit, true ) ) {
	function gatekeeper_counter_bump( string $name, int $ttl, int $delta = 1 ): int {
		$GLOBALS['gk']['bumps'][]           = array( $name, $ttl, $delta );
		$value                              = max( 0, ( $GLOBALS['gk']['counters'][ $name ] ?? 0 ) + $delta );
		$GLOBALS['gk']['counters'][ $name ] = $value;
		return $value;
	}
}

if ( ! in_array( 'gatekeeper_rate_limited', $omit, true ) ) {
	function gatekeeper_rate_limited( string $bucket, int $max, int $window, $request = null, string $scope = '' ): bool {
		$GLOBALS['gk']['rate_calls'][] = array( $bucket, $max, $window, $scope );
		$bucket_key                    = $bucket . '|' . $scope;
		$count                         = ( $GLOBALS['gk']['rates'][ $bucket_key ] ?? 0 ) + 1;
		$GLOBALS['gk']['rates'][ $bucket_key ] = $count;
		return $count > $max;
	}
}

// The core Gemini wrapper: the proxy asks it for the key.
class Xophz_Compass_HTTP {
	public static function get_gemini_api_key(): ?string {
		return $GLOBALS['gemini_key'] ?? 'gemini-key-unit-only';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-xophz-compass-xp-pro-ai.php';
