<?php

/**
 * The Town Hall membership hook for Do It For The XP.
 *
 * A parent links the game to the family site from the Town Hall: the game asks the site for an
 * application password under a fixed app id. On the Do It For The XP site of a multisite network
 * the parent becomes a Contributor there, so the forum posts the game sends are theirs. Nobody is
 * ever promoted: an existing member keeps whatever role they have.
 *
 * Membership has to come BEFORE the password: WordPress refuses to create an application password
 * for a network user who belongs to no site of the network ("Invalid user ID."). So the parent joins
 * when they open the approval screen (admin_init on authorize-application.php); the two hooks on the
 * password itself stay as a safety net for a parent who reached the password some other way.
 *
 * Comments the game sends are held for the owner. WordPress approves a comment at once when its
 * author wrote the post ("the author and the admins get respect"), so once the owner publishes a
 * family's topic, the family's replies would go public with no approval. A comment made with the
 * game's application password on the Do It For The XP site waits in moderation instead.
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
    'admin_init'                            => 'on_admin_init',
    'wp_create_application_password'        => [ 'on_password_created', 10, 2 ],
    'application_password_did_authenticate' => [ 'on_authenticated', 10, 2 ],
  ];

  /** After core and other plugins (Akismet's spam verdict comes first and is kept). */
  public $filter_hooks = [
    'pre_comment_approved' => [ 'on_pre_comment_approved', 99, 2 ],
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

    return self::is_allowed_site( $site_url, $allowed );
  }

  /**
   * The moderation verdict for a comment: 0 (held for the owner) when it was sent with the game's
   * application password on an allowed site of a network, else the verdict WordPress reached.
   * A 'spam' or 'trash' verdict, or an error, is never softened.
   *
   * Pure: every fact comes in as an argument.
   *
   * @param mixed  $approved     The verdict so far: 1, 0, 'spam', 'trash' or a WP_Error.
   * @param bool   $is_multisite Whether WordPress runs as a network.
   * @param string $site_url     The address of this site.
   * @param array  $allowed      The addresses of the Do It For The XP sites.
   * @param string $app_id       The app id of the application password the request signed in with ('' for none).
   * @return mixed
   */
  public static function comment_verdict( $approved, bool $is_multisite, string $site_url, array $allowed, string $app_id ) {
    $keeps_verdict = 'spam' === $approved || 'trash' === $approved || is_object( $approved );
    if ( $keeps_verdict || ! $is_multisite || self::APP_ID !== $app_id ) {
      return $approved;
    }

    return self::is_allowed_site( $site_url, $allowed ) ? 0 : $approved;
  }

  /**
   * pre_comment_approved: hold a comment the game sent (see comment_verdict).
   *
   * @param mixed $approved    The verdict WordPress reached.
   * @param array $commentdata The comment (unused: the request's sign-in decides).
   * @return mixed
   */
  public function on_pre_comment_approved( $approved, $commentdata = [] ) {
    $app_id = $this->request_app_id();
    // Cheap exit before the allowlist filter; comment_verdict still makes the full decision.
    if ( self::APP_ID !== $app_id ) {
      return $approved;
    }

    $allowed = apply_filters( 'xp_town_hall_site_urls', self::DEFAULT_SITE_URLS );

    return self::comment_verdict(
      $approved,
      is_multisite(),
      home_url(),
      is_array( $allowed ) ? $allowed : self::DEFAULT_SITE_URLS,
      $app_id
    );
  }

  /**
   * The parent opened the approval screen (wp-admin/authorize-application.php): join the site now,
   * because WordPress will not create an application password for a user who is not a member.
   * Acts only on that screen, for a signed-in user, and only for this app's id.
   */
  public function on_admin_init(): void {
    if ( 'authorize-application.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
      return;
    }

    $user_id = (int) get_current_user_id();
    if ( $user_id < 1 ) {
      return;
    }

    $app_id = isset( $_REQUEST['app_id'] ) && is_string( $_REQUEST['app_id'] )
      ? sanitize_text_field( wp_unslash( $_REQUEST['app_id'] ) )
      : '';

    $this->maybe_join( $user_id, $app_id );
  }

  /**
   * An application password was just created.
   *
   * @param int   $user_id The user the password belongs to.
   * @param array $item    The new password's record (holds app_id).
   */
  public function on_password_created( int $user_id, array $item ): void {
    $this->maybe_join( $user_id, self::item_app_id( $item ) );
  }

  /**
   * A request just signed in with an application password.
   *
   * @param WP_User $user The user who signed in.
   * @param array   $item The password's record (holds app_id).
   */
  public function on_authenticated( WP_User $user, array $item ): void {
    $this->maybe_join( (int) $user->ID, self::item_app_id( $item ) );
  }

  /** A site address without its trailing slashes. */
  private static function address( string $url ): string {
    return rtrim( $url, '/' );
  }

  /** Whether a site address is on the list, both compared without trailing slashes; an empty address never is. */
  private static function is_allowed_site( string $site_url, array $allowed ): bool {
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
   * The app id of the application password this request signed in with, or '' when it signed in
   * some other way (a cookie, nothing) or the password has no record.
   */
  private function request_app_id(): string {
    if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
      return '';
    }

    $uuid = rest_get_authenticated_app_password();
    if ( ! is_string( $uuid ) || '' === $uuid ) {
      return '';
    }

    $user_id = (int) get_current_user_id();
    if ( $user_id < 1 ) {
      return '';
    }

    $item = WP_Application_Passwords::get_user_application_password( $user_id, $uuid );

    return is_array( $item ) ? self::item_app_id( $item ) : '';
  }

  /** The app id of an application password's record, or an empty string. */
  private static function item_app_id( array $item ): string {
    return is_string( $item['app_id'] ?? null ) ? $item['app_id'] : '';
  }

  private function maybe_join( int $user_id, string $app_id ): void {
    // Cheap exit before any site or membership lookup; should_join still makes the full decision.
    if ( self::APP_ID !== $app_id ) {
      return;
    }

    $blog_id = get_current_blog_id();
    $allowed = apply_filters( 'xp_town_hall_site_urls', self::DEFAULT_SITE_URLS );

    $join = self::should_join(
      is_multisite(),
      $app_id,
      home_url(),
      is_array( $allowed ) ? $allowed : self::DEFAULT_SITE_URLS,
      is_user_member_of_blog( $user_id, $blog_id )
    );

    if ( $join ) {
      add_user_to_blog( $blog_id, $user_id, self::ROLE );
    }
  }
}
