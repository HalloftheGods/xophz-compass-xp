<?php

/**
 * Party smoke on the real MySQL of the local Docker WordPress ("My Compass").
 *
 * The unit tests run the store's SQL on a SQLite stand-in; this runs the same store and the same route handlers on
 * MySQL, so a statement MySQL reads differently (a reserved word, a lock, a column type) fails here. It creates its
 * own scratch tables under the prefix xpsmoke_ in the site's database, drops them before and after, and touches
 * nothing else. Run it with:
 *
 *   docker exec -i u-wordpress php < tests/rehearsal/party-smoke.php
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
require_once '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$plugin = WP_PLUGIN_DIR . '/xophz-compass-xp/includes/';
foreach ( [ 'family/class-xophz-compass-xp-family-pro.php', 'party/class-xophz-compass-xp-party-rules.php', 'party/class-xophz-compass-xp-party-schema.php', 'party/class-xophz-compass-xp-party-store.php', 'party/class-xophz-compass-xp-party-identity.php', 'party/class-xophz-compass-xp-party-rest.php' ] as $file ) {
  require_once $plugin . $file;
}

$failures = 0;
function check( $condition, $label ) {
  global $failures;
  echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
  if ( ! $condition ) {
    $failures++;
  }
}

// The scratch database handle: the site's own connection with a prefix of its own.
$smoke = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$smoke->set_prefix( 'xpsmoke_' );
$names = array_keys( Xophz_Compass_Xp_Party_Schema::tables( 'xpsmoke_', '' ) );

$drop = function () use ( $smoke, $names ) {
  foreach ( $names as $name ) {
    $smoke->query( "DROP TABLE IF EXISTS {$name}" );
  }
};
$drop();

foreach ( Xophz_Compass_Xp_Party_Schema::tables( 'xpsmoke_', $smoke->get_charset_collate() ) as $sql ) {
  dbDelta( $sql );
}
$present = $smoke->get_col( "SHOW TABLES LIKE 'xpsmoke\\_%'" );
check( 7 === count( $present ), 'the schema creates all seven tables on MySQL (' . count( $present ) . ')' );

add_filter( 'xp_family_pro_entitled', '__return_true' );

class Smoke_Identity {
  public function caller( $r ) { return $r->get_param( '__caller' ); }
}

$GLOBALS['now'] = 1793174400;
$store = new Xophz_Compass_Xp_Party_Store( $smoke );
$rest  = new Xophz_Compass_Xp_Party_Rest( 'xophz-compass-xp', '1', $store, new Smoke_Identity(), function () { return $GLOBALS['now']; } );

function ask( $handler, $params, $caller ) {
  global $rest;
  $r = new WP_REST_Request( 'POST', '/xp-party/v1/x' );
  foreach ( $params + [ '__caller' => $caller ] as $key => $value ) {
    $r->set_param( $key, $value );
  }
  $res = $rest->$handler( $r );

  return is_wp_error( $res ) ? [ 'error' => $res->get_error_code() ] : [ 'status' => $res->get_status(), 'data' => json_decode( wp_json_encode( $res->get_data() ), true ) ];
}
function parent_of( $f ) { return [ 'kind' => 'parent', 'user_id' => 1, 'family_id' => $f ]; }
function card( $h, $n ) { return [ 'heroId' => $h, 'nickname' => $n, 'level' => 4, 'jobClass' => 'knight', 'look' => null ]; }

$made = ask( 'form', [ 'name' => 'Raiders', 'emblem' => 'dragon', 'leader' => card( 'ana', 'Ana' ) ], parent_of( 'f1' ) );
check( 201 === ( $made['status'] ?? 0 ), 'forms a party' );
$pid  = $made['data']['party']['id'] ?? '';
$code = $made['data']['code'] ?? '';
check( 1 === preg_match( '/^[A-Z2-9]{8}$/', $code ), 'the code is eight safe characters' );

$join = ask( 'join', [ 'code' => strtolower( substr( $code, 0, 4 ) . '-' . substr( $code, 4 ) ), 'card' => card( 'ben', 'Ben' ) ], parent_of( 'f2' ) );
check( 201 === ( $join['status'] ?? 0 ), 'asks to join with a code typed in lower case with a dash' );
$resolved = ask( 'resolve_request', [ 'id' => $pid, 'rid' => $join['data']['request']['id'] ?? '', 'decision' => 'approve' ], parent_of( 'f1' ) );
check( 200 === ( $resolved['status'] ?? 0 ), 'the leader accepts' );

$raid = ask( 'start_raid', [ 'id' => $pid, 'beast_id' => 'beast-fluffalo', 'window' => 'quick' ], parent_of( 'f1' ) );
check( 180 === ( $raid['data']['raid']['maxHp'] ?? 0 ), 'starts a raid sized to two heroes' );
$rid = $raid['data']['raid']['id'] ?? '';

function strike_as( $rid, $key, $family, $hero, $damage ) {
  return ask( 'strike', [ 'id' => $rid, 'key' => $key, 'hero_id' => $hero, 'damage' => $damage, 'day' => gmdate( 'Y-m-d', $GLOBALS['now'] ), 'at' => gmdate( 'Y-m-d\TH:i:s\Z', $GLOBALS['now'] ) ], parent_of( $family ) );
}

$a1 = strike_as( $rid, 'a1', 'f1', 'ana', 100 );
check( true === ( $a1['data']['accepted'] ?? false ) && 60 === $a1['data']['damage'], 'a strike is trimmed to the daily cap (60)' );
$again = strike_as( $rid, 'a1', 'f1', 'ana', 100 );
check( false === ( $again['data']['accepted'] ?? true ) && 'duplicate' === $again['data']['reason'], 'a resend of the same key counts once' );
strike_as( $rid, 'b1', 'f2', 'ben', 60 );
$GLOBALS['now'] += 86400;
$a2 = strike_as( $rid, 'a2', 'f1', 'ana', 60 );
check( 'defeated' === ( $a2['data']['raid']['status'] ?? '' ) && 0 === $a2['data']['raid']['hp'], 'the beast falls on the third strike' );

$late = strike_as( $rid, 'b2', 'f2', 'ben', 60 );
check( 'closed' === ( $late['data']['reason'] ?? '' ), 'a strike after the beast fell is closed' );

$feed  = ask( 'feed', [ 'id' => $pid, 'since' => 0 ], parent_of( 'f2' ) );
$kinds = array_count_values( array_column( $feed['data']['events'] ?? [], 'kind' ) );
check( 1 === ( $kinds['defeat'] ?? 0 ) && 3 === ( $kinds['strike'] ?? 0 ) && 2 === ( $kinds['join'] ?? 0 ), 'the feed tells joins, three strikes and one defeat' );

$word = ask( 'preset', [ 'id' => $pid, 'hero_id' => 'ben', 'preset' => 'cheer', 'to_family' => 'f1', 'to_hero' => 'ana' ], parent_of( 'f2' ) );
check( 201 === ( $word['status'] ?? 0 ), 'a member sends a cheer to another member' );

$new = ask( 'rotate_code', [ 'id' => $pid ], parent_of( 'f1' ) );
$old = ask( 'join', [ 'code' => $code, 'card' => card( 'dot', 'Dot' ) ], parent_of( 'f3' ) );
check( ( $new['data']['code'] ?? '' ) !== $code && 'xp_party_code_unknown' === ( $old['error'] ?? '' ), 'a new code kills the old one' );

$left = ask( 'remove_member', [ 'id' => $pid, 'family_id' => 'f1', 'hero_id' => 'ana' ], parent_of( 'f1' ) );
$mine = ask( 'mine', [], parent_of( 'f2' ) );
check( false === ( $left['data']['disbanded'] ?? true ) && 'ben' === ( $mine['data']['parties'][0]['leader']['heroId'] ?? '' ), 'the lead passes to the next member when the leader leaves' );

$locks = $smoke->get_var( "SELECT IS_FREE_LOCK('xp_party_{$pid}')" );
check( '1' === (string) $locks, 'the party lock is released after the calls' );

$drop();
check( array() === $smoke->get_col( "SHOW TABLES LIKE 'xpsmoke\\_%'" ), 'the scratch tables are dropped' );

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASSED\n";
exit( $failures ? 1 : 0 );
