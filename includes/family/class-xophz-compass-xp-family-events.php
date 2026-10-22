<?php

/**
 * The family event stream of Do It For The XP: a batch sent by a device, reading by number,
 * and rewards from the server.
 *
 * The routes (Xophz_Compass_Xp_Family_Rest) work out the caller and the family, then hand the
 * batch or the read here. Server code calls grant_reward (Quest Together's rooms first; the
 * rehearsal through wp eval), the only way a reward.granted reaches a stream: a device that
 * sends one is refused, because the kind's writer is the server. The family's home device pays
 * each reward once, by its event id.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Events {

  /** The device id of events the server writes. */
  const SERVER_DEVICE = 'server';

  /**
   * A batch from a device.
   *
   * Each event is checked on its own: a refused one (a sibling's event from a hero key, a
   * reward.granted from a device, an unknown kind...) is listed in rejected and the rest of
   * the batch carries on. The whole batch is refused only when it is not a list or has more
   * than 50 events (400 xp_family_bad_request), when a parent of a family that is not entitled
   * to XP Pro sends from a device that is not the family's home (409 xp_family_moved; an entitled
   * family's parents may send from any of their devices), when it would take a hero or the family
   * past the hourly limits (429 xp_family_rate_limited) or when the family's lock is busy (503).
   *
   * @param array $family The caller's family row.
   * @param array $caller The caller: parent (writes as the home device) or hero (its phone).
   * @param mixed $events The events param of the request.
   * @return array|WP_Error [ 'accepted' => ids, 'duplicate' => ids, 'rejected' => [ { id, code, message } ] ]
   */
  public static function send( Xophz_Compass_Xp_Family_Store $store, array $family, array $caller, $events ) {
    if ( ! is_array( $events ) || ! array_is_list( $events ) ) {
      return self::error( 'xp_family_bad_request', 'The body needs a list of events.', 400 );
    }
    if ( count( $events ) > Xophz_Compass_Xp_Family_Event_Rules::MAX_BATCH ) {
      return self::error( 'xp_family_bad_request', 'At most ' . Xophz_Compass_Xp_Family_Event_Rules::MAX_BATCH . ' events per request.', 400 );
    }

    $family_id = (string) $family['family_id'];
    $is_hero   = 'hero' === ( $caller['kind'] ?? null );
    if ( ! $is_hero && 'parent' !== ( $caller['kind'] ?? null ) ) {
      return self::error( 'xp_family_forbidden', 'This caller may not do that.', 403 );
    }

    $now = time();

    if ( ! $is_hero ) {
      $home = (string) $family['home_device_id'];
      foreach ( $events as $e ) {
        if ( is_array( $e ) && is_string( $e['device_id'] ?? null ) && $e['device_id'] !== $home ) {
          // An entitled family has no home device to keep: every parent device is a family device.
          // The licence is read only when a batch comes from another device.
          if ( Xophz_Compass_Xp_Family_Pro::entitled( $family_id, $now ) ) {
            break;
          }
          return self::error( 'xp_family_moved', "This family's home is another device now.", 409 );
        }
      }
    }

    $ctx = [
      'writer'      => $is_hero ? 'hero' : 'home',
      'hero_ids'    => array_map( 'strval', array_column( $store->heroes( $family_id ), 'hero_id' ) ),
      'key_hero_id' => $is_hero ? (string) $caller['device']['hero_id'] : null,
      'now'         => $now,
    ];
    $phone_id = $is_hero ? (string) $caller['device']['device_id'] : '';

    $rows     = [];
    $rejected = [];
    foreach ( $events as $e ) {
      $check = is_array( $e )
        ? Xophz_Compass_Xp_Family_Event_Rules::check_event( $e, $ctx )
        : self::refused( '', 'An event must be an object.' );

      if ( $check['ok'] && $is_hero && $check['event']['device_id'] !== $phone_id ) {
        $check = self::refused( $check['event']['event_id'], 'A hero phone can only send its own events.' );
      }

      if ( $check['ok'] ) {
        $rows[] = $check['event'];
      } else {
        $rejected[] = [ 'id' => (string) $check['id'], 'code' => (string) $check['code'], 'message' => (string) $check['message'] ];
      }
    }

    // Ids already stored come back as duplicates and add nothing, so they do not count.
    $known    = array_flip( $store->known_event_ids( array_column( $rows, 'event_id' ) ) );
    $incoming = [];
    foreach ( $rows as $row ) {
      if ( ! isset( $known[ $row['event_id'] ] ) ) {
        $incoming[ $row['hero_id'] ] = ( $incoming[ $row['hero_id'] ] ?? 0 ) + 1;
      }
    }
    $counts = $store->counts_last_hour( $family_id, (int) $ctx['now'] );
    if ( Xophz_Compass_Xp_Family_Event_Rules::over_limit( $counts['by_hero'], $counts['family'], $incoming ) ) {
      return self::error( 'xp_family_rate_limited', 'Too many events this hour. Wait, then send again.', 429 );
    }

    $result = [] === $rows ? [ 'accepted' => [], 'duplicate' => [] ] : $store->append_events( $family_id, $rows );
    if ( is_wp_error( $result ) ) {
      return $result;
    }

    return [
      'accepted'  => $result['accepted'],
      'duplicate' => $result['duplicate'],
      'rejected'  => $rejected,
    ];
  }

  /**
   * The family's events after the number since, oldest first, at most limit, only those the
   * caller may read: a parent reads all; a hero key reads the public events and the family
   * events of its own hero (never a sibling's).
   *
   * @param array $family The caller's family row.
   * @param array $caller The caller: parent or hero.
   * @return array The events as the wire carries them (event_wire).
   */
  public static function read( Xophz_Compass_Xp_Family_Store $store, array $family, array $caller, int $since, int $limit ): array {
    $kind    = (string) ( $caller['kind'] ?? 'none' );
    $hero_id = 'hero' === $kind ? (string) $caller['device']['hero_id'] : null;
    if ( 'hero' !== $kind && 'parent' !== $kind ) {
      return [];
    }

    $out = [];
    foreach ( $store->events_after( (string) $family['family_id'], $since, $limit, $hero_id ) as $row ) {
      if ( Xophz_Compass_Xp_Family_Event_Rules::readable_by( $row, $kind, $hero_id ) ) {
        $out[] = self::event_wire( $row );
      }
    }

    return $out;
  }

  /** A stored event as the wire carries it; data is always a JSON object. */
  public static function event_wire( array $row ): array {
    $data = is_string( $row['data'] ?? null ) ? json_decode( $row['data'] ) : null;

    return [
      'seq'         => (int) $row['seq'],
      'id'          => (string) $row['event_id'],
      'hero_id'     => (string) $row['hero_id'],
      'device_id'   => (string) $row['device_id'],
      'kind'        => (string) $row['kind'],
      'scope'       => (string) $row['scope'],
      'at'          => Xophz_Compass_Xp_Family_Store::iso( $row['occurred_at'] ?? null ),
      'received_at' => Xophz_Compass_Xp_Family_Store::iso( $row['received_at'] ?? null ),
      'data'        => is_object( $data ) ? $data : new stdClass(),
    ];
  }

  /**
   * Grant a hero XP and AP: append a reward.granted to the family's stream (scope family, so
   * only the parent and that hero read it). Rewards are XP and AP only, never gold.
   *
   * @param string                             $family_id The family.
   * @param string                             $hero_id   A live hero of that family.
   * @param int                                $xp        XP to pay (0 to 100000).
   * @param int                                $ap        AP to pay (0 to 100000).
   * @param string                             $reason    Up to 40 characters, e.g. party-bonus (longer is dropped).
   * @param string                             $source_id Up to 64 characters: what earned it (longer is dropped).
   * @param Xophz_Compass_Xp_Family_Store|null $store     The store; a new one when null.
   * @return array|WP_Error [ 'id' => event id, 'family_id', 'hero_id', 'data' ] or why not.
   */
  public static function grant_reward( string $family_id, string $hero_id, int $xp, int $ap, string $reason, string $source_id, $store = null ) {
    if ( ! Xophz_Compass_Xp_Family_Schema::is_family_site() ) {
      return new WP_Error( 'xp_family_forbidden', 'Rewards are granted only on a family site.', [ 'status' => 403 ] );
    }

    $store = $store instanceof Xophz_Compass_Xp_Family_Store ? $store : new Xophz_Compass_Xp_Family_Store();
    if ( null === $store->family( $family_id ) ) {
      return new WP_Error( 'xp_family_no_family', 'There is no such family.', [ 'status' => 404 ] );
    }

    $now   = time();
    $id    = wp_generate_uuid4();
    $check = Xophz_Compass_Xp_Family_Event_Rules::check_event(
      [
        'id'        => $id,
        'hero_id'   => $hero_id,
        'device_id' => self::SERVER_DEVICE,
        'kind'      => 'reward.granted',
        'at'        => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
        'data'      => [ 'xp' => $xp, 'ap' => $ap, 'reason' => $reason, 'sourceId' => $source_id ],
      ],
      [
        'writer'      => 'server',
        'hero_ids'    => array_map( 'strval', array_column( $store->heroes( $family_id ), 'hero_id' ) ),
        'key_hero_id' => null,
        'now'         => $now,
      ]
    );
    if ( ! $check['ok'] ) {
      return new WP_Error( $check['code'], $check['message'], [ 'status' => 400 ] );
    }

    $result = $store->append_events( $family_id, [ $check['event'] ] );
    if ( is_wp_error( $result ) ) {
      return $result;
    }
    if ( ! in_array( $id, $result['accepted'], true ) ) {
      return new WP_Error( 'xp_family_server', 'The reward could not be stored.', [ 'status' => 500 ] );
    }

    return [
      'id'        => $id,
      'family_id' => $family_id,
      'hero_id'   => $hero_id,
      'data'      => $check['event']['data'],
    ];
  }

  /** A refused event, in the shape of Event_Rules::check_event's refusal. */
  private static function refused( string $id, string $message ): array {
    return [ 'ok' => false, 'id' => $id, 'code' => Xophz_Compass_Xp_Family_Event_Rules::INVALID, 'message' => $message ];
  }

  private static function error( string $code, string $message, int $status ): WP_Error {
    return new WP_Error( $code, $message, [ 'status' => $status ] );
  }
}
