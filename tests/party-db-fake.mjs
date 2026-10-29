/**
 * A stand-in for wpdb shared by the party store and route suites. It runs the store's real SQL on an in-memory SQLite
 * database (pdo_sqlite in the u-wordpress container), so a wrong column, a wrong WHERE or a missing row shows up as a
 * failing test, not as an unseen statement. Like MySQL it hands every value back as a string and counts only changed
 * rows for an UPDATE. The named lock is always free here (the lock itself is tested apart, with a flag).
 *
 * The DDL below mirrors Xophz_Compass_Xp_Party_Schema::tables() column for column, in SQLite's types.
 */

import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';

const PLUGIN = '/var/www/html/wp-content/plugins/xophz-compass-xp';

export const STORE_PRELUDE = `
require_once '${PLUGIN}/includes/family/class-xophz-compass-xp-family-pro.php';
require_once '${PLUGIN}/includes/party/class-xophz-compass-xp-party-rules.php';
require_once '${PLUGIN}/includes/party/class-xophz-compass-xp-party-store.php';
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

class Fake_Party_Db {
  public $prefix = 'wp_5_';
  public $insert_id = 0;
  public $log = [];
  public $lock_free = true;
  private $pdo;

  public function __construct() {
    $this->pdo = new PDO( 'sqlite::memory:' );
    $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $p = $this->prefix;
    foreach ( [
      "CREATE TABLE {$p}xp_parties (party_id TEXT PRIMARY KEY, name TEXT NOT NULL, emblem TEXT NOT NULL, code TEXT NOT NULL UNIQUE, invite_rule TEXT NOT NULL DEFAULT 'leader', leader_family_id TEXT NOT NULL, leader_hero_id TEXT NOT NULL, events_seq INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, disbanded_at TEXT)",
      "CREATE TABLE {$p}xp_party_members (party_id TEXT NOT NULL, family_id TEXT NOT NULL, hero_id TEXT NOT NULL, card TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'member', joined_at TEXT NOT NULL, left_at TEXT, PRIMARY KEY (party_id, family_id, hero_id))",
      "CREATE TABLE {$p}xp_party_requests (request_id TEXT PRIMARY KEY, party_id TEXT NOT NULL, family_id TEXT NOT NULL, hero_id TEXT NOT NULL, card TEXT NOT NULL, created_at TEXT NOT NULL, resolved_at TEXT, resolution TEXT)",
      "CREATE TABLE {$p}xp_party_events (party_id TEXT NOT NULL, seq INTEGER NOT NULL, kind TEXT NOT NULL, family_id TEXT, hero_id TEXT, data TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (party_id, seq))",
      "CREATE TABLE {$p}xp_party_raids (raid_id TEXT PRIMARY KEY, party_id TEXT NOT NULL, beast_id TEXT NOT NULL, raid_window TEXT NOT NULL, max_hp INTEGER NOT NULL, member_count INTEGER NOT NULL, started_at TEXT NOT NULL, ends_at TEXT NOT NULL)",
      "CREATE TABLE {$p}xp_party_strikes (raid_id TEXT NOT NULL, strike_key TEXT NOT NULL, family_id TEXT NOT NULL, hero_id TEXT NOT NULL, damage INTEGER NOT NULL, day TEXT NOT NULL, struck_at TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (raid_id, strike_key))",
      "CREATE TABLE {$p}xp_party_blocks (family_id TEXT NOT NULL, party_id TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (family_id, party_id))",
    ] as $sql ) {
      $this->pdo->exec( $sql );
    }
  }

  public function prepare( $sql, ...$args ) {
    $args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? array_values( $args[0] ) : $args;
    $i    = 0;

    return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
      $v = $args[ $i++ ] ?? '';
      return '%d' === $m[0] ? (string) (int) $v : $this->pdo->quote( (string) $v );
    }, $sql );
  }

  private function strings( array $row ): array {
    return array_map( function ( $v ) { return null === $v ? null : (string) $v; }, $row );
  }

  public function get_var( $sql ) {
    if ( preg_match( '/^SELECT GET_LOCK/', $sql ) ) { $this->log[] = 'GET_LOCK'; return $this->lock_free ? '1' : '0'; }
    if ( preg_match( '/^SELECT RELEASE_LOCK/', $sql ) ) { $this->log[] = 'RELEASE_LOCK'; return '1'; }
    $row = $this->pdo->query( $sql )->fetch( PDO::FETCH_NUM );

    return $row && null !== $row[0] ? (string) $row[0] : null;
  }

  public function get_row( $sql, $output = 'ARRAY_A' ) {
    $row = $this->pdo->query( $sql )->fetch( PDO::FETCH_ASSOC );

    return $row ? $this->strings( $row ) : null;
  }

  public function get_results( $sql, $output = 'ARRAY_A' ) {
    return array_map( [ $this, 'strings' ], $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ) );
  }

  public function get_col( $sql ) {
    return array_map( function ( $v ) { return (string) $v; }, $this->pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN, 0 ) );
  }

  public function query( $sql ) {
    $this->log[] = $sql;

    return $this->pdo->exec( $sql );
  }

  public function insert( $table, $data, $format = null ) {
    $names = implode( ', ', array_keys( $data ) );
    $marks = implode( ', ', array_map( function ( $v ) { return null === $v ? 'NULL' : $this->pdo->quote( (string) $v ); }, array_values( $data ) ) );
    $this->log[] = "INSERT INTO {$table}";
    $this->pdo->exec( "INSERT INTO {$table} ({$names}) VALUES ({$marks})" );
    $this->insert_id = (int) $this->pdo->lastInsertId();

    return 1;
  }

  private function assignments( array $data, string $glue ): string {
    $parts = [];
    foreach ( $data as $column => $value ) {
      $parts[] = null === $value ? "{$column} IS NULL" : "{$column} = " . $this->pdo->quote( (string) $value );
    }

    return implode( $glue, $parts );
  }

  public function update( $table, $data, $where, $format = null, $where_format = null ) {
    $set = [];
    foreach ( $data as $column => $value ) {
      $set[] = null === $value ? "{$column} = NULL" : "{$column} = " . $this->pdo->quote( (string) $value );
    }
    $this->log[] = "UPDATE {$table}";

    return $this->pdo->exec( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . $this->assignments( $where, ' AND ' ) );
  }

  public function delete( $table, $where, $where_format = null ) {
    $this->log[] = "DELETE {$table}";

    return $this->pdo->exec( "DELETE FROM {$table} WHERE " . $this->assignments( $where, ' AND ' ) );
  }
}

function wp_generate_uuid4() {
  static $n = 0;
  $n++;

  return sprintf( '00000000-0000-4000-8000-%012d', $n );
}
`;

/** Runs a PHP body after the store prelude and answers the JSON it echoes. */
export const runStore = (body) => runPhpJson(`${STORE_PRELUDE}\n${body}`);

/**
 * The route layer on top of the store prelude: a stub identity (the caller is passed in the request), a Gatekeeper stub
 * (f1, f2 and f3 hold XP Pro, f9 does not), a clock the case controls and helpers that call a handler and answer
 * either [ status, data ] or [ error code, status ].
 */
export const ROUTES_PRELUDE = `${STORE_PRELUDE}
require_once '${PLUGIN}/includes/party/class-xophz-compass-xp-party-rest.php';

class Gatekeeper_Xp_Pro_Licenses {
  public static $licensed = [ 'f1', 'f2', 'f3' ];
  public static function get( $id ) { return in_array( $id, self::$licensed, true ) ? [ 'active' => true ] : null; }
  public static function is_entitled( $record, $now ) { return ! empty( $record['active'] ); }
}

class Req extends WP_REST_Request {
  public function __construct( $params = [], $headers = [] ) { $this->params = $params; $this->headers = $headers; }
}

class Stub_Identity {
  public function caller( $r ) { return $r->get_param( '__caller' ); }
}

$GLOBALS['now'] = 1793174400;
$db    = new Fake_Party_Db();
$store = new Xophz_Compass_Xp_Party_Store( $db );
$rest  = new Xophz_Compass_Xp_Party_Rest( 'xophz-compass-xp', '1', $store, new Stub_Identity(), function () { return $GLOBALS['now']; } );

function parent_of( $family ) { return [ 'kind' => 'parent', 'user_id' => 1, 'family_id' => $family ]; }
function hero_of( $family, $hero ) { return [ 'kind' => 'hero', 'family_id' => $family, 'hero_id' => $hero ]; }
function nobody() { return [ 'kind' => 'none' ]; }
function card( $hero, $nick = 'Hero' ) { return [ 'heroId' => $hero, 'nickname' => $nick, 'level' => 4, 'jobClass' => 'knight', 'look' => null ]; }
function iso( $unix ) { return gmdate( 'Y-m-d\\\\TH:i:s\\\\Z', $unix ); }

/** Calls a handler; answers [ 'status', 'data' ] or [ 'error', 'status' ]. */
function pcall( $handler, $params, $caller ) {
  global $rest;
  $res = $rest->$handler( new Req( $params + [ '__caller' => $caller ] ) );
  if ( is_wp_error( $res ) ) {
    $d = $res->get_error_data();
    return [ 'error' => $res->get_error_code(), 'status' => is_array( $d ) ? ( $d['status'] ?? null ) : null ];
  }

  return [ 'status' => $res->get_status(), 'data' => $res->get_data() ];
}

/** f1 forms the party "Raiders" led by its hero ana; answers [ party id, code ]. */
function make_party( $rule = 'leader' ) {
  $res = pcall( 'form', [ 'name' => 'Raiders', 'emblem' => 'dragon', 'leader' => card( 'ana', 'Ana' ), 'invite_rule' => $rule ], parent_of( 'f1' ) );

  return [ $res['data']['party']['id'], $res['data']['code'] ];
}

/** A hero of another family joins by code and the leader family accepts. */
function join_party( $code, $family, $hero, $nick = 'Hero' ) {
  $join = pcall( 'join', [ 'code' => $code, 'card' => card( $hero, $nick ) ], parent_of( $family ) );
  $pid  = $join['data']['request']['partyId'];
  $rid  = $join['data']['request']['id'];

  return pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'approve' ], parent_of( 'f1' ) );
}

/** The usual party: f1 leads with ana and f2 brings ben. Answers [ party id, code ]. */
function two_party( $rule = 'leader' ) {
  [ $pid, $code ] = make_party( $rule );
  join_party( $code, 'f2', 'ben', 'Ben' );

  return [ $pid, $code ];
}

/** A strike of this hero on today's day (by the controlled clock), sent by this caller. */
function hit( $raid, $key, $family, $hero, $damage, $caller = null ) {
  return pcall( 'strike', [ 'id' => $raid, 'key' => $key, 'hero_id' => $hero, 'damage' => $damage, 'day' => gmdate( 'Y-m-d', $GLOBALS['now'] ), 'at' => iso( $GLOBALS['now'] ) ], $caller ?? parent_of( $family ) );
}
`;

/** Runs a PHP body after the route prelude and answers the JSON it echoes. */
export const runRoutes = (body) => runPhpJson(`${ROUTES_PRELUDE}\n${body}`);
