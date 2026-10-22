/**
 * A small in-memory stand-in for wpdb, shared by the store suites (family-records.test.mjs and
 * family-turn.test.mjs). It holds the rows of the family tables and answers the exact statements
 * the store sends: prepare() keeps the SQL (white space folded) with its arguments, and an
 * unknown statement throws, so a changed query fails a test instead of passing unseen. Like MySQL
 * it hands every value back as a string, counts only changed rows for an UPDATE, and gives the
 * records table a primary key on (family_id, seq) beside the unique (family_id, store, record_key).
 * Record keys compare the way MySQL compares a utf8mb4_bin column (PAD SPACE): 'k' and 'k ' are the
 * same key, and an upsert keeps the key the row was first written with. The named lock is a flag on
 * the stand-in, and any write to the records, the counter or a hero's turn by take_turn while the
 * family's lock is not held is noted in $outside_lock.
 */

import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';

const PLUGIN = '/var/www/html/wp-content/plugins/xophz-compass-xp';

export const FAKE_DB = `
require_once '${PLUGIN}/includes/family/class-xophz-compass-xp-family-rules.php';
require_once '${PLUGIN}/includes/family/class-xophz-compass-xp-family-record-rules.php';
require_once '${PLUGIN}/includes/family/class-xophz-compass-xp-family-store.php';
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

class FakeFamilyDb {
  public $prefix = 'wp_';
  public $log = [];
  public $locks = [];
  public $outside_lock = [];
  public $lock_free = true;
  public $explode_on = null;
  public $fail_key = null;
  public $fail_turn_update = false;
  public $families = [];
  public $records = [];
  public $heroes = [];
  public $devices = [];

  public function add_family( $id, $seq = 0 ) {
    $this->families[ $id ] = [ 'records_seq' => $seq ];
  }

  public function add_hero( $family, $hero, $over = [] ) {
    $this->heroes[ $family . '|' . $hero ] = array_merge( [
      'family_id' => $family, 'hero_id' => $hero, 'nickname' => $hero, 'age_group' => 'kid', 'card' => null,
      'card_at' => null, 'created_at' => '2026-10-01 00:00:00', 'removed_at' => null, 'turn_device_id' => null,
      'turn_label' => '', 'turn_taken_at' => null, 'turn_seen_at' => null,
    ], $over );
  }

  public function add_device( $id, $family, $hero, $device_id ) {
    $this->devices[ $id ] = [ 'id' => $id, 'family_id' => $family, 'hero_id' => $hero, 'device_id' => $device_id, 'revoked_at' => null ];
  }

  public function add_record( $family, $store, $key, $seq, $hero = null, $body = '{}', $deleted = 0 ) {
    $this->records[ $family . '|' . $store . '|' . rtrim( $key, ' ' ) ] = [
      'seq' => $seq, 'family_id' => $family, 'store' => $store, 'record_key' => $key, 'hero_id' => $hero,
      'device_id' => 'olddevice0000001', 'saved_at' => '2026-10-01 00:00:00', 'deleted' => $deleted, 'body' => $body,
    ];
  }

  /** Like wpdb: the SQL stays, the arguments are kept (a NULL becomes an empty string, as wpdb makes it). */
  public function prepare( $sql, ...$args ) {
    $args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? array_values( $args[0] ) : $args;
    $sql  = trim( preg_replace( '/\\s+/', ' ', $sql ) );
    if ( preg_match_all( '/%[sd]/', $sql ) !== count( $args ) ) {
      throw new RuntimeException( 'placeholders and arguments differ: ' . $sql );
    }

    return [ 'sql' => $sql, 'args' => array_map( function ( $v ) { return null === $v ? '' : $v; }, $args ) ];
  }

  public function get_var( $q )                  { return $this->run( $q ); }
  public function get_row( $q, $out = null )     { return $this->run( $q ); }
  public function get_results( $q, $out = null ) { return $this->run( $q ); }
  public function query( $q )                    { return $this->run( $q ); }

  private function text( $row ) {
    return array_map( function ( $v ) { return null === $v ? null : (string) $v; }, $row );
  }

  /** Note a write made while the family's lock is not held. */
  private function guard( $family, $sql ) {
    if ( ! isset( $this->locks[ 'xp_family_' . $family ] ) ) { $this->outside_lock[] = $sql; }
  }

  private function run( $q ) {
    if ( ! is_array( $q ) ) { throw new RuntimeException( 'unprepared SQL: ' . $q ); }
    $this->log[] = $q;
    $sql = $q['sql'];
    $a   = $q['args'];
    if ( null !== $this->explode_on && false !== strpos( $sql, $this->explode_on ) ) {
      throw new RuntimeException( 'the database went away' );
    }

    // The named lock.
    if ( 0 === strpos( $sql, 'SELECT GET_LOCK(%s, %d)' ) ) {
      if ( ! $this->lock_free ) { return '0'; }
      $this->locks[ $a[0] ] = true;
      return '1';
    }
    if ( 0 === strpos( $sql, 'SELECT RELEASE_LOCK(%s)' ) ) {
      unset( $this->locks[ $a[0] ] );
      return '1';
    }

    // The family counter.
    if ( 'SELECT records_seq FROM wp_xp_families WHERE family_id = %s FOR UPDATE' === $sql ) {
      $this->guard( $a[0], $sql );
      return isset( $this->families[ $a[0] ] ) ? (string) $this->families[ $a[0] ]['records_seq'] : null;
    }
    if ( 'UPDATE wp_xp_families SET records_seq = %d WHERE family_id = %s' === $sql ) {
      $this->guard( $a[1], $sql );
      $this->families[ $a[1] ]['records_seq'] = (int) $a[0];
      return 1;
    }

    // The records.
    if ( preg_match( '/^SELECT store, record_key, hero_id FROM wp_xp_family_records WHERE family_id = %s AND \\( \\( store = %s AND record_key = %s \\)( OR \\( store = %s AND record_key = %s \\))* \\)$/', $sql ) ) {
      $found = [];
      for ( $i = 1; $i < count( $a ); $i += 2 ) {
        $row = $this->records[ $a[0] . '|' . $a[ $i ] . '|' . rtrim( $a[ $i + 1 ], ' ' ) ] ?? null;
        if ( null !== $row ) {
          $found[] = $this->text( [ 'store' => $row['store'], 'record_key' => $row['record_key'], 'hero_id' => $row['hero_id'] ] );
        }
      }
      return $found;
    }
    if ( 0 === strpos( $sql, 'INSERT INTO wp_xp_family_records (seq, family_id, store, record_key, hero_id, device_id, saved_at, deleted, body) VALUES (' ) ) {
      $literal = false !== strpos( $sql, 'VALUES (%d, %s, %s, %s, NULL, %s, %s, %d, %s) ON DUPLICATE KEY UPDATE seq = VALUES(seq), hero_id = VALUES(hero_id), device_id = VALUES(device_id), saved_at = VALUES(saved_at), deleted = VALUES(deleted), body = VALUES(body)' );
      if ( ! $literal && false === strpos( $sql, 'VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %s) ON DUPLICATE KEY UPDATE seq = VALUES(seq), hero_id = VALUES(hero_id), device_id = VALUES(device_id), saved_at = VALUES(saved_at), deleted = VALUES(deleted), body = VALUES(body)' ) ) {
        throw new RuntimeException( 'unexpected upsert: ' . $sql );
      }
      $this->guard( $a[1], $sql );
      list( $seq, $family, $store, $key ) = $a;
      $hero = $literal ? null : $a[4];
      list( $device, $saved, $deleted, $body ) = array_slice( $a, $literal ? 4 : 5 );

      if ( (string) $key === (string) $this->fail_key ) { return false; }

      $own = $family . '|' . $store . '|' . rtrim( $key, ' ' );
      foreach ( $this->records as $name => $row ) {
        if ( $row['family_id'] === $family && (int) $row['seq'] === (int) $seq && $name !== $own ) {
          throw new RuntimeException( 'PRIMARY KEY collision on (' . $family . ', ' . $seq . ')' );
        }
      }

      $new    = [ 'seq' => (int) $seq, 'family_id' => $family, 'store' => $store, 'record_key' => $key, 'hero_id' => $hero,
        'device_id' => $device, 'saved_at' => $saved, 'deleted' => (int) $deleted, 'body' => $body ];
      $exists = isset( $this->records[ $own ] );
      if ( $exists ) { $new['record_key'] = $this->records[ $own ]['record_key']; }
      $this->records[ $own ] = $new;
      return $exists ? 2 : 1;
    }
    if ( preg_match( '/^SELECT \\* FROM wp_xp_family_records WHERE family_id = %s AND seq > %d( AND \\( hero_id IS NULL OR hero_id = %s \\))? ORDER BY seq ASC LIMIT %d$/', $sql, $m ) ) {
      $hero = empty( $m[1] ) ? null : $a[2];
      $out  = [];
      foreach ( $this->records as $row ) {
        $seen = null === $hero || null === $row['hero_id'] || $hero === $row['hero_id'];
        if ( $row['family_id'] === $a[0] && (int) $row['seq'] > (int) $a[1] && $seen ) { $out[] = $row; }
      }
      usort( $out, function ( $x, $y ) { return $x['seq'] <=> $y['seq']; } );
      return array_map( [ $this, 'text' ], array_slice( $out, 0, (int) end( $a ) ) );
    }

    // The hero's turn.
    if ( 'SELECT turn_device_id FROM wp_xp_family_heroes WHERE family_id = %s AND hero_id = %s AND removed_at IS NULL' === $sql ) {
      $h = $this->heroes[ $a[0] . '|' . $a[1] ] ?? null;
      return ( null === $h || null !== $h['removed_at'] ) ? null : $h['turn_device_id'];
    }
    if ( preg_match( '/^SELECT hero_id, turn_device_id FROM wp_xp_family_heroes WHERE family_id = %s AND removed_at IS NULL AND hero_id IN \\(%s(, %s)*\\)$/', $sql ) ) {
      $this->guard( $a[0], $sql );
      $found = [];
      foreach ( array_slice( $a, 1 ) as $hero ) {
        $h = $this->heroes[ $a[0] . '|' . $hero ] ?? null;
        if ( null !== $h && null === $h['removed_at'] ) { $found[] = $this->text( [ 'hero_id' => $h['hero_id'], 'turn_device_id' => $h['turn_device_id'] ] ); }
      }
      return $found;
    }
    if ( 'SELECT * FROM wp_xp_family_heroes WHERE family_id = %s AND hero_id = %s AND removed_at IS NULL' === $sql ) {
      $h = $this->heroes[ $a[0] . '|' . $a[1] ] ?? null;
      return ( null === $h || null !== $h['removed_at'] ) ? null : $this->text( $h );
    }
    if ( 'UPDATE wp_xp_family_heroes SET turn_device_id = %s, turn_label = %s, turn_taken_at = %s, turn_seen_at = %s WHERE family_id = %s AND hero_id = %s AND removed_at IS NULL AND ( %d = 1 OR turn_device_id IS NULL OR turn_device_id = %s )' === $sql ) {
      $this->guard( $a[4], $sql );
      if ( $this->fail_turn_update ) { return false; }
      $k = $a[4] . '|' . $a[5];
      $h = $this->heroes[ $k ] ?? null;
      if ( null === $h || null !== $h['removed_at'] ) { return 0; }
      if ( ! ( 1 === (int) $a[6] || null === $h['turn_device_id'] || $a[7] === $h['turn_device_id'] ) ) { return 0; }
      $new     = [ 'turn_device_id' => $a[0], 'turn_label' => $a[1], 'turn_taken_at' => $a[2], 'turn_seen_at' => $a[3] ];
      $changed = false;
      foreach ( $new as $col => $value ) { $changed = $changed || $h[ $col ] !== $value; }
      $this->heroes[ $k ] = array_merge( $h, $new );
      return $changed ? 1 : 0;
    }
    if ( "UPDATE wp_xp_family_heroes SET turn_device_id = NULL, turn_label = '', turn_taken_at = NULL, turn_seen_at = NULL WHERE family_id = %s AND hero_id = %s AND turn_device_id = %s" === $sql ) {
      $n = 0;
      if ( isset( $this->heroes[ $a[0] . '|' . $a[1] ] ) && $this->heroes[ $a[0] . '|' . $a[1] ]['turn_device_id'] === $a[2] ) {
        $this->heroes[ $a[0] . '|' . $a[1] ] = array_merge( $this->heroes[ $a[0] . '|' . $a[1] ], [ 'turn_device_id' => null, 'turn_label' => '', 'turn_taken_at' => null, 'turn_seen_at' => null ] );
        $n = 1;
      }
      return $n;
    }
    if ( 'UPDATE wp_xp_family_heroes SET turn_seen_at = %s WHERE family_id = %s AND hero_id = %s AND turn_device_id = %s' === $sql ) {
      $k = $a[1] . '|' . $a[2];
      $h = $this->heroes[ $k ] ?? null;
      if ( null === $h || $h['turn_device_id'] !== $a[3] || $h['turn_seen_at'] === $a[0] ) { return 0; }
      $this->heroes[ $k ]['turn_seen_at'] = $a[0];
      return 1;
    }

    // Removing a hero and revoking a phone.
    if ( "UPDATE wp_xp_family_heroes SET removed_at = %s, card = NULL, card_at = NULL, turn_device_id = NULL, turn_label = '', turn_taken_at = NULL, turn_seen_at = NULL WHERE family_id = %s AND hero_id = %s AND removed_at IS NULL" === $sql ) {
      $k = $a[1] . '|' . $a[2];
      $h = $this->heroes[ $k ] ?? null;
      if ( null === $h || null !== $h['removed_at'] ) { return 0; }
      $this->heroes[ $k ] = array_merge( $h, [ 'removed_at' => $a[0], 'card' => null, 'card_at' => null, 'turn_device_id' => null, 'turn_label' => '', 'turn_taken_at' => null, 'turn_seen_at' => null ] );
      return 1;
    }
    if ( 'UPDATE wp_xp_family_devices SET revoked_at = %s WHERE family_id = %s AND hero_id = %s AND revoked_at IS NULL' === $sql ) {
      $n = 0;
      foreach ( $this->devices as $id => $d ) {
        if ( $d['family_id'] === $a[1] && $d['hero_id'] === $a[2] && null === $d['revoked_at'] ) {
          $this->devices[ $id ]['revoked_at'] = $a[0];
          $n++;
        }
      }
      return $n;
    }
    if ( 'DELETE FROM wp_xp_family_link_codes WHERE family_id = %s AND hero_id = %s AND used_at IS NULL' === $sql ) {
      return 0;
    }
    if ( 'UPDATE wp_xp_family_devices SET revoked_at = %s WHERE id = %d AND family_id = %s AND revoked_at IS NULL' === $sql ) {
      $d = $this->devices[ (int) $a[1] ] ?? null;
      if ( null === $d || $d['family_id'] !== $a[2] || null !== $d['revoked_at'] ) { return 0; }
      $this->devices[ (int) $a[1] ]['revoked_at'] = $a[0];
      return 1;
    }
    if ( 'SELECT hero_id, device_id FROM wp_xp_family_devices WHERE id = %d AND family_id = %s' === $sql ) {
      $d = $this->devices[ (int) $a[0] ] ?? null;
      return ( null === $d || $d['family_id'] !== $a[1] ) ? null : $this->text( [ 'hero_id' => $d['hero_id'], 'device_id' => $d['device_id'] ] );
    }

    throw new RuntimeException( 'unexpected SQL: ' . $sql );
  }
}
`;

/** Run PHP after the stand-in and the store are loaded; the snippet echoes the JSON it wants back. */
export function storeRun(code) {
  return runPhpJson(`${FAKE_DB}\n${code}`);
}
