<?php

/**
 * The rules of parties and raids for Do It For The XP: what a party may know of a hero, the preset words that
 * travel between families, the health of a raid beast, and whether one strike counts.
 *
 * Pure PHP: no WordPress, no database, so the rules can be tested alone. Every constant and every case has a
 * twin in the app (src/lib/services/party/raidRules.ts, partyRules.ts and presets.ts): the site and the app must
 * work out the same health, the same caps and the same reward gate from the same strikes. Change them together.
 *
 * A member of a party is named "family_id|hero_id" here, because hero ids are only unique inside one family.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes/party
 */

class Xophz_Compass_Xp_Party_Rules {

  /** Days each raid window runs. */
  const RAID_DAYS = [ 'quick' => 3, 'standard' => 7, 'epic' => 14 ];

  /** Damage a chore deals per Fibonacci step of difficulty (the app works the damage out; the site only caps it). */
  const DAMAGE_PER_STEP = 10;

  /** The most damage one hero can deal to one raid on one day. */
  const DAILY_STRIKE_CAP = 60;

  /** The pace a raid's health expects: one middling chore a day from each hero. */
  const HP_PER_HERO_DAY = 30;

  const DAY_SECONDS = 86400;

  /** The only words that travel between families: ids of the app's fixed list (presets.ts), cheer and poke included. */
  const PRESETS = [ 'gg', 'nice', 'onit', 'ready', 'help', 'thanks', 'wait', 'brb', 'heart', 'star', 'party', 'cheer', 'poke' ];

  /** Party code letters and digits: no I, L, O, 0 or 1, which are easy to misread. */
  const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
  const CODE_LENGTH   = 8;

  const NICKNAME_MAX   = 24;
  const CLASS_MAX      = 32;
  const LEVEL_MAX      = 999;
  const LOOK_VALUE_MAX = 40;
  const HERO_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

  /** The five fields of a hero card: nothing else leaves a family. */
  const CARD_KEYS = [ 'heroId', 'nickname', 'level', 'jobClass', 'look' ];

  /** The fields of a hero's look. */
  const LOOK_KEYS = [ 'skin', 'hair', 'hairColor', 'face', 'eyeColor', 'outfit', 'outfitColor', 'outfitAccent', 'hat', 'hatColor', 'leftHand', 'rightHand' ];

  // --- words and cards -------------------------------------------------------------------------

  /** Whether this is one of the preset ids. Strict: "toString" and numbers are not. */
  public static function is_preset( $id ): bool {
    return is_string( $id ) && in_array( $id, self::PRESETS, true );
  }

  /**
   * A hero card, checked field by field. Exactly the five public fields are allowed: a card with a PIN, an email, a
   * real name or any other field is refused whole, so a client bug can never leak one.
   *
   * @return array [ 'ok' => true, 'card' => array ] or [ 'ok' => false, 'code' => 'xp_party_card_invalid' ].
   */
  public static function card( $hero ): array {
    $refuse = [ 'ok' => false, 'code' => 'xp_party_card_invalid' ];

    if ( ! is_array( $hero ) ) {
      return $refuse;
    }

    $keys = array_keys( $hero );
    sort( $keys );
    $want = self::CARD_KEYS;
    sort( $want );
    if ( $keys !== $want ) {
      return $refuse;
    }

    $look = $hero['look'];

    $ok = is_string( $hero['heroId'] ) && 1 === preg_match( self::HERO_ID_PATTERN, $hero['heroId'] )
      && self::is_nickname( $hero['nickname'] )
      && is_int( $hero['level'] ) && $hero['level'] >= 1 && $hero['level'] <= self::LEVEL_MAX
      && is_string( $hero['jobClass'] ) && mb_strlen( $hero['jobClass'] ) <= self::CLASS_MAX
      && ( null === $look || self::is_look( $look ) );

    return $ok ? [ 'ok' => true, 'card' => $hero ] : $refuse;
  }

  /** A nickname: 1 to 24 characters, some of them visible, none of them control characters. */
  private static function is_nickname( $nickname ): bool {
    if ( ! is_string( $nickname ) ) {
      return false;
    }

    $length  = mb_strlen( $nickname );
    $visible = '' !== trim( $nickname );

    return $length >= 1 && $length <= self::NICKNAME_MAX && $visible && 1 !== preg_match( '/[\x00-\x1F\x7F]/u', $nickname );
  }

  /** A look: known fields only, each a short non-empty text. */
  private static function is_look( $look ): bool {
    if ( ! is_array( $look ) || array() === $look ) {
      return false;
    }

    foreach ( $look as $key => $value ) {
      $known = is_string( $key ) && in_array( $key, self::LOOK_KEYS, true );
      $text  = is_string( $value ) && '' !== $value && mb_strlen( $value ) <= self::LOOK_VALUE_MAX;
      if ( ! $known || ! $text ) {
        return false;
      }
    }

    return true;
  }

  // --- raids -----------------------------------------------------------------------------------

  /** Whether this is one of the raid windows. */
  public static function is_window( $window ): bool {
    return is_string( $window ) && isset( self::RAID_DAYS[ $window ] );
  }

  /** The beast's health: the window's days times the party's size times the pace; 0 for an unknown window. */
  public static function raid_max_hp( string $window, $members ): int {
    $heroes = is_numeric( $members ) && $members >= 1 ? (int) floor( $members ) : 1;
    $days   = self::RAID_DAYS[ $window ] ?? 0;

    return $days * $heroes * self::HP_PER_HERO_DAY;
  }

  /** When a raid's window closes (unix time): a blow from this moment on is too late. */
  public static function raid_ends_at( int $started_at, string $window ): int {
    return $started_at + ( self::RAID_DAYS[ $window ] ?? 0 ) * self::DAY_SECONDS;
  }

  /**
   * Whether a hero's day ("2026-10-28", the calendar day on the hero's device) is believable for the moment of the
   * strike: a real date within a day of the site's own date, which covers every time zone.
   */
  public static function plausible_day( $day, int $at ): bool {
    if ( ! is_string( $day ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
      return false;
    }

    $zone   = new DateTimeZone( 'UTC' );
    $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $day, $zone );
    if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $day ) {
      return false;
    }

    $today = new DateTimeImmutable( '@' . $at );
    $today = $today->setTimezone( $zone )->setTime( 0, 0 );

    return abs( (int) $today->diff( $parsed )->format( '%r%a' ) ) <= 1;
  }

  /**
   * Whether one strike counts, and for how much.
   *
   * @param array $raid    [ 'max_hp' => int, 'window' => string, 'started_at' => int ] (unix time).
   * @param array $strikes The strikes already counted: [ 'strike_key', 'member', 'damage', 'day', 'at' ] each.
   * @param array $members The member keys ("family_id|hero_id") who may strike now.
   * @param array $blow    [ 'strike_key', 'member', 'damage', 'day', 'at' ] the strike to judge.
   * @return array [ 'accepted' => bool, 'damage' => int, 'reason' => string ]; the reason is '' when it counts, else
   *               duplicate, not_member, closed, bad_day or no_damage. The damage is trimmed to the hero's cap for the
   *               day and to what is left of the beast.
   */
  public static function strike( array $raid, array $strikes, array $members, array $blow ): array {
    $reject = static function ( string $reason ): array {
      return [ 'accepted' => false, 'damage' => 0, 'reason' => $reason ];
    };

    foreach ( $strikes as $counted ) {
      if ( $counted['strike_key'] === $blow['strike_key'] ) {
        return $reject( 'duplicate' );
      }
    }

    if ( ! in_array( $blow['member'], $members, true ) ) {
      return $reject( 'not_member' );
    }

    $at        = (int) $blow['at'];
    $started   = (int) $raid['started_at'];
    $remaining = (int) $raid['max_hp'] - (int) array_sum( array_column( $strikes, 'damage' ) );
    $in_window = $at >= $started && $at < self::raid_ends_at( $started, $raid['window'] );
    if ( ! $in_window || $remaining <= 0 ) {
      return $reject( 'closed' );
    }

    if ( ! self::plausible_day( $blow['day'], $at ) ) {
      return $reject( 'bad_day' );
    }

    $dealt = 0;
    foreach ( $strikes as $counted ) {
      if ( $counted['member'] === $blow['member'] && $counted['day'] === $blow['day'] ) {
        $dealt += (int) $counted['damage'];
      }
    }

    $room   = self::DAILY_STRIKE_CAP - $dealt;
    $damage = min( max( 0, min( (int) $blow['damage'], $room ) ), $remaining );
    if ( $damage <= 0 ) {
      return $reject( 'no_damage' );
    }

    return [ 'accepted' => true, 'damage' => $damage, 'reason' => '' ];
  }

  /**
   * Where a raid stands at `$now`: the beast's health, whether it is down or the window closed on it, the member with
   * the most damage (a tie goes to whoever struck first) and, once it is down, the member whose blow felled it.
   *
   * @return array [ 'hp' => int, 'status' => active|defeated|retreated, 'top' => ?string, 'last' => ?string ].
   */
  public static function raid_state( array $raid, array $strikes, int $now ): array {
    $max_hp   = (int) $raid['max_hp'];
    $hp       = max( 0, $max_hp - (int) array_sum( array_column( $strikes, 'damage' ) ) );
    $defeated = count( $strikes ) > 0 && 0 === $hp;
    $closed   = $now >= self::raid_ends_at( (int) $raid['started_at'], $raid['window'] );
    $missed   = $closed ? 'retreated' : 'active';

    return [
      'hp'     => $hp,
      'status' => $defeated ? 'defeated' : $missed,
      'top'    => self::top_striker( $strikes ),
      'last'   => $defeated ? self::last_hit( $strikes, $max_hp ) : null,
    ];
  }

  /** The member with the most damage; a tie goes to the earlier first strike, then to the earlier row. */
  private static function top_striker( array $strikes ): ?string {
    $tally = [];
    foreach ( array_values( $strikes ) as $index => $strike ) {
      $member = $strike['member'];
      if ( ! isset( $tally[ $member ] ) ) {
        $tally[ $member ] = [ 'total' => 0, 'first_at' => (int) $strike['at'], 'first_index' => $index ];
      }
      $tally[ $member ]['total']   += (int) $strike['damage'];
      $tally[ $member ]['first_at'] = min( $tally[ $member ]['first_at'], (int) $strike['at'] );
    }

    $members = array_keys( $tally );
    usort(
      $members,
      static function ( $a, $b ) use ( $tally ): int {
        return [ $tally[ $b ]['total'], $tally[ $a ]['first_at'], $tally[ $a ]['first_index'] ]
          <=> [ $tally[ $a ]['total'], $tally[ $b ]['first_at'], $tally[ $b ]['first_index'] ];
      }
    );

    return $members[0] ?? null;
  }

  /** The member whose blow took the beast to zero. */
  private static function last_hit( array $strikes, int $max_hp ): ?string {
    $dealt = 0;
    foreach ( $strikes as $strike ) {
      $dealt += (int) $strike['damage'];
      if ( $dealt >= $max_hp ) {
        return $strike['member'];
      }
    }

    return null;
  }

  // --- party codes -----------------------------------------------------------------------------

  /** A fresh party code: eight characters from the alphabet that cannot be misread. */
  public static function new_code(): string {
    $top  = strlen( self::CODE_ALPHABET ) - 1;
    $code = '';
    for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
      $code .= self::CODE_ALPHABET[ random_int( 0, $top ) ];
    }

    return $code;
  }

  /** A code as typed: spaces and dashes dropped, letters upper-cased. */
  public static function normalize_code( $typed ): string {
    return strtoupper( (string) preg_replace( '/[\s-]+/', '', (string) $typed ) );
  }

  /** Whether this is shaped like a party code (already normalized). */
  public static function is_code_shaped( $code ): bool {
    return is_string( $code ) && 1 === preg_match( '/^[' . self::CODE_ALPHABET . ']{' . self::CODE_LENGTH . '}$/', $code );
  }
}
