<?php

/**
 * Family rules for Do It For The XP: link codes, hero keys, nicknames, ids, the answers
 * to "bind this family to the account" and who may do what.
 *
 * Pure rules. This class calls no WordPress function and touches no database, so the
 * tests load it alone. The store and the routes (which do use WordPress) ask it.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/family
 */

class Xophz_Compass_Xp_Family_Rules {

  /** No 0, O, 1, I or L: kids read these off a screen and type them on a phone. */
  const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
  const CODE_LENGTH   = 8;
  /** Seconds a link code lives. */
  const CODE_TTL      = 600;
  const KEY_PREFIX    = 'xph_';
  const AGE_GROUPS    = [ 'child', 'teen', 'adult' ];
  const NICKNAME_MAX  = 40;
  const LABEL_MAX     = 60;
  /** Wrong codes allowed per address per hour before the claim route answers 429. */
  const CLAIM_FAILS_PER_HOUR = 10;
  const DEFAULT_LABEL = 'A hero phone';

  /** Bytes in a hero key; 32 bytes are 43 base64url characters without padding. */
  const KEY_BYTES = 32;

  /** Caller => the actions that caller may take. A caller or action not listed may do nothing. */
  private const POLICY = [
    'parent' => [
      'bind', 'move', 'read_family', 'save_hero', 'remove_hero', 'make_code', 'claim',
      'list_devices', 'unlink_device', 'send_events', 'read_events',
      'put_records', 'read_records', 'play', 'rest',
    ],
    'hero'   => [
      'read_family', 'unlink_device', 'send_events', 'read_events',
      'put_records', 'read_records', 'play', 'rest',
    ],
    'none'   => [ 'claim' ],
  ];

  /**
   * A new link code.
   *
   * @param callable|null $rand Called as $rand( 0, 30 ) once per character; random_int when null
   *                            (PHP cannot default a callable parameter to a function name).
   * @return string 8 characters of CODE_ALPHABET.
   */
  public static function new_code( ?callable $rand = null ): string {
    $rand = $rand ?? 'random_int';
    $last = strlen( self::CODE_ALPHABET ) - 1;
    $code = '';
    for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
      $code .= self::CODE_ALPHABET[ $rand( 0, $last ) ];
    }
    return $code;
  }

  /**
   * What a person typed or scanned, as the code it stands for: uppercase, with spaces and
   * dashes dropped. "abcd efgh" and "ABCD-EFGH" are the same code.
   *
   * @return string|null The 8 characters, or null when it cannot be a code.
   */
  public static function normalize_code( string $typed ): ?string {
    // Whitespace (also the no-break space of a phone keyboard), hyphen-minus and the Unicode dashes.
    $clean = preg_replace( '/[\s\-\x{2010}-\x{2015}]+/u', '', $typed );
    if ( ! is_string( $clean ) ) {
      return null;
    }

    $clean = strtoupper( $clean );
    $shape = '/\A[' . self::CODE_ALPHABET . ']{' . self::CODE_LENGTH . '}\z/';

    return 1 === preg_match( $shape, $clean ) ? $clean : null;
  }

  /** SHA-256 hex of a code or a key; the server stores only this. */
  public static function hash_secret( string $s ): string {
    return hash( 'sha256', $s );
  }

  /**
   * A new hero key: "xph_" and 32 random bytes in base64url without padding (47 characters).
   *
   * @param callable|null $bytes Called as $bytes( 32 ); random_bytes when null.
   */
  public static function new_key( ?callable $bytes = null ): string {
    $bytes = $bytes ?? 'random_bytes';

    return self::KEY_PREFIX . rtrim( strtr( base64_encode( $bytes( self::KEY_BYTES ) ), '+/', '-_' ), '=' );
  }

  /** Whether a string looks like a hero key (it says nothing about whether the key is valid). */
  public static function is_key_shaped( string $s ): bool {
    return 1 === preg_match( '/\A' . preg_quote( self::KEY_PREFIX, '/' ) . '[A-Za-z0-9_-]{43}\z/', $s );
  }

  /**
   * A nickname fit to store: tags stripped, spaces collapsed and trimmed, 1 to 40 characters
   * (characters, not bytes, so 40 emoji fit and 41 do not).
   *
   * @param mixed $raw Whatever the request carried.
   * @return string|null The clean nickname, or null when there is none or it is too long.
   */
  public static function clean_nickname( $raw ): ?string {
    $clean = self::squish( $raw );
    if ( null === $clean || '' === $clean ) {
      return null;
    }

    return mb_strlen( $clean, 'UTF-8' ) <= self::NICKNAME_MAX ? $clean : null;
  }

  /**
   * A label for a device, cut to 60 characters; one with no text becomes $default ("A hero
   * phone" unless the caller names another).
   *
   * @param mixed       $raw     Whatever the request carried.
   * @param string|null $default The label for a device that has none.
   */
  public static function device_label( $raw, ?string $default = null ): string {
    $default = $default ?? self::DEFAULT_LABEL;
    $clean   = self::squish( $raw );
    if ( null === $clean || '' === $clean ) {
      return $default;
    }

    $clean = trim( mb_substr( $clean, 0, self::LABEL_MAX, 'UTF-8' ) );

    return '' === $clean ? $default : $clean;
  }

  /** @param mixed $v */
  public static function is_age_group( $v ): bool {
    return is_string( $v ) && in_array( $v, self::AGE_GROUPS, true );
  }

  /** A lowercase version 4 UUID. */
  public static function is_family_id( string $s ): bool {
    return 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $s );
  }

  /** 16 letters and digits, as the app's family identity makes them. */
  public static function is_device_id( string $s ): bool {
    return 1 === preg_match( '/\A[A-Za-z0-9]{16}\z/', $s );
  }

  /** 1 to 64 letters, digits, dashes and underscores. */
  public static function is_hero_id( string $s ): bool {
    return 1 === preg_match( '/\A[A-Za-z0-9_-]{1,64}\z/', $s );
  }

  /**
   * What binding a family to an account comes to.
   *
   * @param array|null $account_family The family row this user owns, if any.
   * @param array|null $id_owner       The family row that already holds $family_id, if any.
   * @param int        $user_id        The signed-in user.
   * @param string     $family_id      The family the device asks to bind.
   * @param string     $device_id      The asking device.
   * @return string created | home | home-elsewhere | other-family | taken
   */
  public static function bind_answer( ?array $account_family, ?array $id_owner, int $user_id, string $family_id, string $device_id ): string {
    $mine = $account_family;
    if ( null === $mine && null !== $id_owner && $user_id > 0 && (int) ( $id_owner['owner_user_id'] ?? 0 ) === $user_id ) {
      $mine = $id_owner;
    }

    if ( null !== $mine ) {
      if ( ( $mine['family_id'] ?? '' ) !== $family_id ) {
        return 'other-family';
      }
      $home = (string) ( $mine['home_device_id'] ?? '' );
      return '' !== $device_id && $home === $device_id ? 'home' : 'home-elsewhere';
    }

    return null !== $id_owner ? 'taken' : 'created';
  }

  /**
   * Whether this site is one of the family sites, comparing without trailing slashes.
   *
   * @param string $home_url The site's own address.
   * @param array  $allowed  The Town Hall's list of site addresses.
   */
  public static function is_family_site( string $home_url, array $allowed ): bool {
    $home = rtrim( $home_url, '/' );
    if ( '' === $home ) {
      return false;
    }

    foreach ( $allowed as $url ) {
      if ( is_string( $url ) && rtrim( $url, '/' ) === $home ) {
        return true;
      }
    }

    return false;
  }

  /**
   * Whether a caller may take an action. Callers: parent, hero (a hero key) and none (no
   * credentials). An unknown caller or action is refused.
   */
  public static function may( string $caller, string $action ): bool {
    return in_array( $action, self::POLICY[ $caller ] ?? [], true );
  }

  /**
   * Text from a request, made safe to store: valid UTF-8, no tags, controls and runs of
   * whitespace become one space, trimmed.
   *
   * @param mixed $raw
   * @return string|null Null when it is not a string or not UTF-8.
   */
  private static function squish( $raw ): ?string {
    if ( ! is_string( $raw ) ) {
      return null;
    }

    $raw = str_replace( "\0", '', $raw );
    if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
      return null;
    }

    // Controls only (\p{Cc}): the zero width joiner inside a family emoji is a format character and stays.
    $text = preg_replace( '/[\s\p{Cc}]+/u', ' ', strip_tags( $raw ) );

    return is_string( $text ) ? trim( $text ) : null;
  }
}
