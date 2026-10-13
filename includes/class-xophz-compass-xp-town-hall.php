<?php

/**
 * The Town Hall membership hook for Do It For The XP.
 *
 * A parent links the game to the family site from the Town Hall: the game asks the site for an
 * application password under a fixed app id. When that password is created (or used) on the
 * Do It For The XP site of a multisite network, the parent becomes a Contributor there, so the
 * forum posts the game sends are theirs. Nobody is ever promoted: an existing member keeps
 * whatever role they have.
 *
 * The class loads without WordPress; the WordPress calls live inside the hook methods.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/includes
 */

class Xophz_Compass_Xp_Town_Hall {

  /** The app id the game sends when it asks for an application password. */
  const APP_ID = '7606ce60-29c7-43b6-9533-2063ee726df4';

  /** The sites that give a linked parent a seat. Filter: xp_town_hall_site_urls. */
  const DEFAULT_SITE_URLS = [ 'https://doit.forthexp.com' ];

  /** The role a linked parent gets on the site. */
  const ROLE = 'contributor';

  private $plugin_name;
  private $version;

  public $action_hooks = [
    'wp_create_application_password'        => [ 'on_password_created', 10, 2 ],
    'application_password_did_authenticate' => [ 'on_authenticated', 10, 2 ],
  ];

  public function __construct( $plugin_name, $version ) {
    $this->plugin_name = $plugin_name;
    $this->version     = $version;
  }

  /**
   * Whether this user should be added to this site as a Contributor.
   *
   * Pure: every fact comes in as an argument. Site addresses are compared without their
   * trailing slashes, and an empty address never matches.
   *
   * @param bool   $is_multisite Whether WordPress runs as a network.
   * @param string $app_id       The app id of the application password.
   * @param string $site_url     The address of this site.
   * @param array  $allowed      The addresses that give a linked parent a seat.
   * @param bool   $is_member    Whether the user already belongs to this site.
   * @return bool
   */
  public static function should_join( bool $is_multisite, string $app_id, string $site_url, array $allowed, bool $is_member ): bool {
    if ( ! $is_multisite || $is_member || self::APP_ID !== $app_id ) {
      return false;
    }

    $site = self::address( $site_url );
    if ( '' === $site ) {
      return false;
    }

    foreach ( $allowed as $url ) {
      if ( is_string( $url ) && $site === self::address( $url ) ) {
        return true;
      }
    }

    return false;
  }

  /**
   * An application password was just created.
   *
   * @param int   $user_id The user the password belongs to.
   * @param array $item    The new password's record (holds app_id).
   */
  public function on_password_created( int $user_id, array $item ): void {
    $this->maybe_join( $user_id, $item );
  }

  /**
   * A request just signed in with an application password.
   *
   * @param WP_User $user The user who signed in.
   * @param array   $item The password's record (holds app_id).
   */
  public function on_authenticated( WP_User $user, array $item ): void {
    $this->maybe_join( (int) $user->ID, $item );
  }

  /** A site address without its trailing slashes. */
  private static function address( string $url ): string {
    return rtrim( $url, '/' );
  }

  private function maybe_join( int $user_id, array $item ): void {
    $blog_id = get_current_blog_id();
    $allowed = apply_filters( 'xp_town_hall_site_urls', self::DEFAULT_SITE_URLS );

    $join = self::should_join(
      is_multisite(),
      (string) ( $item['app_id'] ?? '' ),
      home_url(),
      is_array( $allowed ) ? $allowed : self::DEFAULT_SITE_URLS,
      is_user_member_of_blog( $user_id, $blog_id )
    );

    if ( $join ) {
      add_user_to_blog( $blog_id, $user_id, self::ROLE );
    }
  }
}
