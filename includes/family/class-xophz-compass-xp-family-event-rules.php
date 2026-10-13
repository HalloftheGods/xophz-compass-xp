<?php

/**
 * Event rules for the family event stream of Do It For The XP: the kinds of event, what
 * each keeps, the checks on one event, who may read it and the hourly limits.
 *
 * Pure rules. This class calls no WordPress function and touches no database, so the
 * tests load it alone. The store and the routes (which do use WordPress) ask it.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Event_Rules {

  /**
   * Kind => scope, writer and fields.
   *
   * Scope: public (the family's own devices in this base) or family (a parent, and the hero
   * it is about). Writer: home (the family's home device), server (PHP code on the site) or
   * hero (a hero phone; none yet). Field types: int, bool, str40, str64, look.
   *
   * A quest's name and category are never fields, because parents type them and they can
   * be private. Gold is never a field, because it buys real rewards.
   */
  const KINDS = [
    'hero.card'      => [
      'scope'  => 'public',
      'writer' => 'home',
      'fields' => [ 'nickname' => 'str40', 'look' => 'look', 'jobClass' => 'str40', 'level' => 'int' ],
    ],
    'hero.leveled'   => [
      'scope'  => 'public',
      'writer' => 'home',
      'fields' => [ 'level' => 'int', 'jobClass' => 'str40' ],
    ],
    'quest.done'     => [
      'scope'  => 'public',
      'writer' => 'home',
      'fields' => [ 'difficulty' => 'int', 'xp' => 'int', 'fight' => 'bool' ],
    ],
    'reward.granted' => [
      'scope'  => 'family',
      'writer' => 'server',
      'fields' => [ 'xp' => 'int', 'ap' => 'int', 'reason' => 'str40', 'sourceId' => 'str64' ],
    ],
  ];

  /** The parts of a hero's voxel look; each value is a string of up to 32 characters. */
  const LOOK_FIELDS = [
    'skin', 'hair', 'hairColor', 'face', 'eyeColor', 'outfit',
    'outfitColor', 'outfitAccent', 'hat', 'hatColor', 'leftHand', 'rightHand',
  ];

  const LOOK_VALUE_MAX  = 32;
  /** Events in one request. */
  const MAX_BATCH       = 50;
  /** Bytes of an event's data as the device sent it (JSON, unicode unescaped). */
  const MAX_DATA_BYTES  = 8192;
  const HERO_PER_HOUR   = 120;
  const FAMILY_PER_HOUR = 600;
  /** Seconds an event's time may be ahead of the server. */
  const FUTURE_SLACK    = 86400;
  /** Seconds a device waits after a 429. */
  const RETRY_AFTER     = 600;
  const INT_MAX         = 100000;

  /** The error code of a refused event. */
  const INVALID = 'xp_family_event_invalid';

  /** Who writes: home, server or hero. */
  private const WRITERS = [ 'home', 'server', 'hero' ];

  /**
   * Keep the listed fields of the right type; drop everything else.
   *
   * Ints are clamped to 0..INT_MAX and a JSON string is not an int. A string longer than its
   * type allows is dropped, not cut. An empty result is an empty array: encode it as an object.
   *
   * @param string $kind  The event kind.
   * @param array  $data  The data the device sent.
   * @param array  $kinds The kinds table, KINDS by default.
   * @return array The kept fields, in the order the kind lists them.
   */
  public static function filter_data( string $kind, array $data, array $kinds = self::KINDS ): array {
    $fields = $kinds[ $kind ]['fields'] ?? null;
    if ( ! is_array( $fields ) ) {
      return [];
    }

    $kept = [];
    foreach ( $fields as $name => $type ) {
      if ( ! array_key_exists( $name, $data ) ) {
        continue;
      }
      list( $ok, $value ) = self::typed( (string) $type, $data[ $name ] );
      if ( $ok ) {
        $kept[ $name ] = $value;
      }
    }

    return $kept;
  }

  /**
   * Check one event from the wire and turn it into the row to store, or say why not.
   *
   * @param array $e     The wire event: id, hero_id, device_id, kind, at, data.
   * @param array $ctx   writer (home, hero or server), hero_ids (the family's live heroes),
   *                     key_hero_id (the hero of a hero key, else null) and now (unix time).
   * @param array $kinds The kinds table, KINDS by default.
   * @return array [ 'ok' => true, 'event' => [ event_id, hero_id, device_id, kind, scope,
   *               occurred_at (unix), data ] ] or [ 'ok' => false, 'id', 'code', 'message' ].
   */
  public static function check_event( array $e, array $ctx, array $kinds = self::KINDS ): array {
    $id = is_string( $e['id'] ?? null ) ? $e['id'] : '';

    if ( 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id ) ) {
      return self::refuse( $id, 'The event id must be a version 4 UUID.' );
    }

    $kind = $e['kind'] ?? null;
    if ( ! is_string( $kind ) || ! isset( $kinds[ $kind ] ) || ! is_array( $kinds[ $kind ] ) ) {
      return self::refuse( $id, 'That kind of event is not known.' );
    }
    $def = $kinds[ $kind ];

    $writer = $ctx['writer'] ?? null;
    if ( ! is_string( $writer ) || ! in_array( $writer, self::WRITERS, true ) || ( $def['writer'] ?? null ) !== $writer ) {
      return self::refuse( $id, 'This caller may not write that kind of event.' );
    }

    $hero_id  = $e['hero_id'] ?? null;
    $hero_ids = is_array( $ctx['hero_ids'] ?? null ) ? $ctx['hero_ids'] : [];
    if ( ! is_string( $hero_id ) || ! in_array( $hero_id, $hero_ids, true ) ) {
      return self::refuse( $id, 'That hero is not in this family.' );
    }

    if ( 'hero' === $writer && ( ! is_string( $ctx['key_hero_id'] ?? null ) || $ctx['key_hero_id'] !== $hero_id ) ) {
      return self::refuse( $id, 'A hero phone can only send events for its own hero.' );
    }

    $device_id = $e['device_id'] ?? null;
    if ( ! is_string( $device_id ) || 1 !== preg_match( '/\A[A-Za-z0-9]{1,16}\z/', $device_id ) ) {
      return self::refuse( $id, 'The device id is not valid.' );
    }

    $now = (int) ( $ctx['now'] ?? time() );
    $at  = is_string( $e['at'] ?? null ) ? self::parse_time( $e['at'] ) : null;
    if ( null === $at ) {
      return self::refuse( $id, 'The time of the event is not an ISO 8601 time.' );
    }
    if ( $at > $now + self::FUTURE_SLACK ) {
      return self::refuse( $id, 'The event is more than a day ahead of the server.' );
    }

    $data = $e['data'] ?? null;
    if ( ! is_array( $data ) || ( [] !== $data && array_is_list( $data ) ) ) {
      return self::refuse( $id, 'The data must be a JSON object.' );
    }
    $json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    if ( false === $json || strlen( $json ) > self::MAX_DATA_BYTES ) {
      return self::refuse( $id, 'The data is larger than ' . self::MAX_DATA_BYTES . ' bytes.' );
    }

    return [
      'ok'    => true,
      'event' => [
        'event_id'    => $id,
        'hero_id'     => $hero_id,
        'device_id'   => $device_id,
        'kind'        => $kind,
        'scope'       => $def['scope'],
        'occurred_at' => $at,
        'data'        => self::filter_data( $kind, $data, $kinds ),
      ],
    ];
  }

  /**
   * Whether a caller may read a stored event.
   *
   * A parent reads all. A hero key reads the public events and the family events of its own
   * hero. Anyone else reads nothing.
   *
   * @param array       $row         A stored event: scope and hero_id.
   * @param string      $caller      parent, hero or none.
   * @param string|null $key_hero_id The hero of the hero key.
   */
  public static function readable_by( array $row, string $caller, ?string $key_hero_id ): bool {
    if ( 'parent' === $caller ) {
      return true;
    }
    if ( 'hero' !== $caller ) {
      return false;
    }

    $scope = $row['scope'] ?? null;
    if ( 'public' === $scope ) {
      return true;
    }

    return 'family' === $scope && null !== $key_hero_id && ( $row['hero_id'] ?? null ) === $key_hero_id;
  }

  /**
   * Whether a batch would take a hero or the family past the hourly limits.
   *
   * @param array $hero_counts       Events in the last hour, hero id => count.
   * @param int   $family_count      Events in the last hour for the whole family.
   * @param array $incoming_by_hero  The batch, hero id => events.
   */
  public static function over_limit( array $hero_counts, int $family_count, array $incoming_by_hero ): bool {
    $total = 0;
    foreach ( $incoming_by_hero as $hero_id => $count ) {
      $count = max( 0, (int) $count );
      if ( $count > 0 && (int) ( $hero_counts[ $hero_id ] ?? 0 ) + $count > self::HERO_PER_HOUR ) {
        return true;
      }
      $total += $count;
    }

    return $total > 0 && $family_count + $total > self::FAMILY_PER_HOUR;
  }

  /** A refused event. */
  private static function refuse( string $id, string $message ): array {
    return [ 'ok' => false, 'id' => $id, 'code' => self::INVALID, 'message' => $message ];
  }

  /**
   * An ISO 8601 date and time as unix seconds. A time with no zone counts as UTC. Words such
   * as "tomorrow", which strtotime would read, are refused.
   */
  private static function parse_time( string $at ): ?int {
    $shape = '/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}(:?\d{2})?)?\z/i';
    if ( 1 !== preg_match( $shape, $at ) ) {
      return null;
    }

    try {
      $time = new DateTimeImmutable( $at, new DateTimeZone( 'UTC' ) );
    } catch ( Exception $e ) {
      return null;
    }

    // 2026-02-31 would roll over into March: refuse it.
    $problems = DateTimeImmutable::getLastErrors();
    if ( is_array( $problems ) && ( $problems['warning_count'] > 0 || $problems['error_count'] > 0 ) ) {
      return null;
    }

    return $time->getTimestamp();
  }

  /**
   * One value against one field type.
   *
   * @param mixed $v
   * @return array [ bool kept, mixed value ]
   */
  private static function typed( string $type, $v ): array {
    if ( 'int' === $type ) {
      return is_int( $v ) ? [ true, max( 0, min( self::INT_MAX, $v ) ) ] : [ false, null ];
    }
    if ( 'bool' === $type ) {
      return is_bool( $v ) ? [ true, $v ] : [ false, null ];
    }
    if ( 1 === preg_match( '/\Astr(\d+)\z/', $type, $m ) ) {
      return self::text_within( $v, (int) $m[1] ) ? [ true, $v ] : [ false, null ];
    }
    if ( 'look' === $type ) {
      $look = self::look( $v );
      return [] === $look ? [ false, null ] : [ true, $look ];
    }

    return [ false, null ];
  }

  /** Whether a value is a UTF-8 string of at most $max characters. @param mixed $v */
  private static function text_within( $v, int $max ): bool {
    return is_string( $v ) && mb_check_encoding( $v, 'UTF-8' ) && mb_strlen( $v, 'UTF-8' ) <= $max;
  }

  /**
   * A look with only its known parts, each a short string. An empty array when there is none.
   *
   * @param mixed $v
   */
  private static function look( $v ): array {
    if ( ! is_array( $v ) ) {
      return [];
    }

    $look = [];
    foreach ( self::LOOK_FIELDS as $part ) {
      if ( array_key_exists( $part, $v ) && self::text_within( $v[ $part ], self::LOOK_VALUE_MAX ) ) {
        $look[ $part ] = $v[ $part ];
      }
    }

    return $look;
  }
}
