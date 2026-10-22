<?php

/**
 * Record rules for the family save of Do It For The XP: the stores the site keeps, their
 * scopes, the hero a record belongs to, the size caps, who may write a record and who may
 * read a stored one.
 *
 * Pure rules. This class calls no WordPress function and touches no database, so the
 * tests load it alone. The store and the routes (which do use WordPress) ask it.
 *
 * A record on the wire is { store, key, hero_id, deleted, body }. The body is the record's
 * JSON text, as the app wrote it (JSON.stringify): this class never decodes it into PHP
 * arrays and encodes it again, which would turn every empty object inside a profile into an
 * empty list. check() reads the text once to see that it is an object and to find a hero id,
 * and gives it back as it came; the store keeps that text as it is and GET /records sends
 * it back as a string for the app to parse. A removed record (a tombstone) has no body.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Record_Rules {

  /**
   * Store => scope and the rule for the hero id of its records.
   *
   * The stores are those of the app's backup (BACKUP_STORES), less __localSettings, which
   * stays on its device. Scope: family (a Game Master writes it), family-any (a Game Master
   * or any hero key of the family writes it: the chat) or hero (one hero's records: a hero
   * key writes only its own hero's). hero_from: key (the record key is the hero id),
   * key_prefix (the part of the key before the first colon), userId or heroId (that field of
   * the body) or null (a family record belongs to no hero).
   *
   * A family-any record is read by everyone but owned by whoever wrote it: a hero key's record
   * carries that key's hero (so only that hero's key can rewrite or remove it), a Game Master's
   * carries none. Everyone reads every row of such a store; ownership only limits writing.
   */
  const STORES = [
    '__achievementCategories' => [ 'scope' => 'family',     'hero_from' => null ],
    '__beasts'                => [ 'scope' => 'family',     'hero_from' => null ],
    '__achievements'          => [ 'scope' => 'family',     'hero_from' => null ],
    '__abilities'             => [ 'scope' => 'family',     'hero_from' => null ],
    '__accessories'           => [ 'scope' => 'family',     'hero_from' => null ],
    '__shops'                 => [ 'scope' => 'family',     'hero_from' => null ],
    '__temples'               => [ 'scope' => 'family',     'hero_from' => null ],
    '__dosDonts'              => [ 'scope' => 'family',     'hero_from' => null ],
    '__family_settings'       => [ 'scope' => 'family',     'hero_from' => null ],
    '__packs'                 => [ 'scope' => 'family',     'hero_from' => null ],
    '__chat'                  => [ 'scope' => 'family-any', 'hero_from' => null ],
    '__profiles'              => [ 'scope' => 'hero',       'hero_from' => 'key' ],
    '__questLog'              => [ 'scope' => 'hero',       'hero_from' => 'userId' ],
    '__purchaseLog'           => [ 'scope' => 'hero',       'hero_from' => 'userId' ],
    '__castLog'               => [ 'scope' => 'hero',       'hero_from' => 'userId' ],
    '__latePenalties'         => [ 'scope' => 'hero',       'hero_from' => 'heroId' ],
    '__templeProgress'        => [ 'scope' => 'hero',       'hero_from' => 'key_prefix' ],
    '__notifications'         => [ 'scope' => 'hero',       'hero_from' => 'key' ],
  ];

  /** Bytes of a record's body text. */
  const MAX_BODY_BYTES  = 524288;
  /** Records in one request. */
  const MAX_BATCH       = 50;
  /** Bytes of the records in one request. */
  const MAX_BATCH_BYTES = 1048576;
  /** Characters of a record key (the column is varchar(191)); a key has no white space at either end. */
  const MAX_KEY         = 191;

  /** The error codes of a refused record. */
  const STORE_UNKNOWN = 'xp_family_store_unknown';
  const INVALID       = 'xp_family_record_invalid';
  const SCOPE         = 'xp_family_scope';

  /** 1 to 64 letters, digits, dashes and underscores, as the routes read a hero id (the column is varchar(64)). */
  private const HERO_ID_SHAPE = '/\A[A-Za-z0-9_-]{1,64}\z/';

  /**
   * Check one record from the wire and turn it into the record to store, or say why not.
   *
   * The record decides before the caller does: a record that is not valid is refused as
   * invalid whoever sends it. A hero id on the wire has to agree with the one the record
   * gives (the key, or the body); a removed record of a store whose hero is in the body has
   * no body, so it takes the hero id from the wire, and the store compares that with the
   * row it replaces.
   *
   * @param mixed $raw        The wire record: store, key, hero_id, deleted and body (a JSON string).
   * @param array $caller_ctx writer (parent or hero) and key_hero_id (the hero of a hero key, else null).
   * @return array|string The clean record [ store, key, hero_id, deleted, body ] (body: the text as
   *                      sent, or '' for a removal; hero_id: null for a family record, and for a
   *                      family-any record a Game Master writes; the hero of the key for one a hero
   *                      key writes, whatever the wire says), or the error code:
   *                      xp_family_store_unknown, xp_family_record_invalid or xp_family_scope.
   */
  public static function check( $raw, array $caller_ctx ) {
    if ( ! is_array( $raw ) ) {
      return self::INVALID;
    }

    $store = $raw['store'] ?? null;
    if ( ! is_string( $store ) || ! isset( self::STORES[ $store ] ) ) {
      return self::STORE_UNKNOWN;
    }
    $def = self::STORES[ $store ];

    // No white space at either end: MySQL compares a key without its trailing spaces, so
    // 'ql-sib ' would reach the row 'ql-sib' while looking like a different key.
    $key = $raw['key'] ?? null;
    if ( ! is_string( $key ) || '' === $key || $key !== trim( $key ) || ! mb_check_encoding( $key, 'UTF-8' ) || mb_strlen( $key, 'UTF-8' ) > self::MAX_KEY ) {
      return self::INVALID;
    }

    $deleted = $raw['deleted'] ?? false;
    if ( ! is_bool( $deleted ) ) {
      return self::INVALID;
    }

    // A removal has no body; a live record has the JSON text of an object.
    $body    = $raw['body'] ?? null;
    $decoded = null;
    if ( $deleted ) {
      if ( null !== $body && '' !== $body ) {
        return self::INVALID;
      }
      $body = '';
    } else {
      if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
        return self::INVALID;
      }
      $decoded = json_decode( $body, false );
      if ( ! is_object( $decoded ) ) {
        return self::INVALID;
      }
    }

    $hero_id = null;
    if ( 'hero' === $def['scope'] ) {
      $named = $raw['hero_id'] ?? null;
      if ( null !== $named && ( ! is_string( $named ) || 1 !== preg_match( self::HERO_ID_SHAPE, $named ) ) ) {
        return self::INVALID;
      }

      $hero_id = self::hero_id_of( $store, $key, $decoded );
      if ( null === $hero_id && $deleted && in_array( $def['hero_from'], [ 'userId', 'heroId' ], true ) ) {
        $hero_id = $named;
      }
      if ( null === $hero_id ) {
        return self::INVALID;
      }
      if ( null !== $named && $named !== $hero_id ) {
        return self::SCOPE;
      }
    }

    if ( 'parent' !== ( $caller_ctx['writer'] ?? null ) ) {
      $own = $caller_ctx['key_hero_id'] ?? null;
      $own = is_string( $own ) && 1 === preg_match( self::HERO_ID_SHAPE, $own ) ? $own : null;
      if ( 'hero' !== ( $caller_ctx['writer'] ?? null ) || null === $own ) {
        return self::SCOPE;
      }

      if ( 'family-any' === $def['scope'] ) {
        // The chat: any hero key writes it, and the row is then that hero's to rewrite or remove.
        $hero_id = $own;
      } elseif ( 'hero' !== $def['scope'] || $own !== $hero_id ) {
        return self::SCOPE;
      }
    }

    return [
      'store'   => $store,
      'key'     => $key,
      'hero_id' => $hero_id,
      'deleted' => $deleted,
      'body'    => $body,
    ];
  }

  /**
   * The hero a record belongs to, by the rule of its store.
   *
   * @param string $store The store name.
   * @param string $key   The record key.
   * @param mixed  $body  The record's body: its JSON text, or the object or array decoded from it.
   *                      Only the stores whose hero is in the body read it.
   * @return string|null The hero id, or null for a family record, an unknown store, a key or
   *                     body that gives no hero id, or an id that is not 1 to 64 letters,
   *                     digits, dashes and underscores.
   */
  public static function hero_id_of( string $store, string $key, $body ): ?string {
    $from = self::STORES[ $store ]['hero_from'] ?? null;
    if ( null === $from ) {
      return null;
    }

    if ( 'key' === $from ) {
      $id = $key;
    } elseif ( 'key_prefix' === $from ) {
      $colon = strpos( $key, ':' );
      $id    = false === $colon ? null : substr( $key, 0, $colon );
    } else {
      if ( is_string( $body ) ) {
        $body = json_decode( $body, false );
      }
      $fields = is_object( $body ) ? get_object_vars( $body ) : $body;
      $id     = is_array( $fields ) ? ( $fields[ $from ] ?? null ) : null;
    }

    return is_string( $id ) && 1 === preg_match( self::HERO_ID_SHAPE, $id ) ? $id : null;
  }

  /** The stores any hero key may write (scope family-any): the chat. */
  public static function any_writer_stores(): array {
    $names = [];
    foreach ( self::STORES as $name => $def ) {
      if ( 'family-any' === $def['scope'] ) {
        $names[] = $name;
      }
    }

    return $names;
  }

  /**
   * Whether a caller may read a stored record.
   *
   * A parent reads all. A hero key reads the family records (no hero), every row of a
   * family-any store (the chat, whoever wrote it) and the records of its own hero. Anyone else
   * reads nothing. The same filter as the store's query for a hero key.
   *
   * @param array $row        A stored record: store and hero_id (null for a family record).
   * @param array $caller_ctx writer (parent or hero) and key_hero_id.
   */
  public static function may_read( array $row, array $caller_ctx ): bool {
    $writer = $caller_ctx['writer'] ?? null;
    if ( 'parent' === $writer ) {
      return true;
    }
    if ( 'hero' !== $writer ) {
      return false;
    }

    $hero_id = $row['hero_id'] ?? null;
    $own     = $caller_ctx['key_hero_id'] ?? null;
    $store   = $row['store'] ?? null;
    if ( null === $hero_id || ( is_string( $store ) && 'family-any' === ( self::STORES[ $store ]['scope'] ?? null ) ) ) {
      return true;
    }

    return is_string( $own ) && $own === $hero_id;
  }
}
