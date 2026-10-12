<?php
/**
 * Settings → Do It For The XP: where to mount the uploaded build.
 *
 * Rendered by Xophz_Compass_Xp_Doit::render_settings_page(), which sets $available,
 * $has_build, $upload_path, $slug, $load_mode, $page_id and $app_url.
 *
 * @package    Xophz_Compass_Xp
 * @subpackage Xophz_Compass_Xp/admin/partials
 */
?>
<div class="wrap">
  <h1><?php esc_html_e( 'Do It For The XP', 'xophz-compass-xp' ); ?></h1>

  <?php if ( ! $available ) : ?>
    <div class="notice notice-info inline">
      <p>
        <?php esc_html_e( 'The Do It For The XP app is not installed on this site.', 'xophz-compass-xp' ); ?>
        <?php esc_html_e( 'Upload the contents of its build folder (dist/ from pnpm build) to:', 'xophz-compass-xp' ); ?>
        <code><?php echo esc_html( $upload_path ); ?></code>
      </p>
      <p><?php esc_html_e( 'The mount options appear here once index.html is in that folder.', 'xophz-compass-xp' ); ?></p>
    </div>
  <?php else : ?>
    <?php if ( ! $has_build ) : ?>
      <div class="notice notice-warning inline">
        <p><?php esc_html_e( 'No build uploaded. Dev mode is on, so the app is served from the dev server on port 8100.', 'xophz-compass-xp' ); ?></p>
      </div>
    <?php endif; ?>

    <p>
      <a href="<?php echo esc_url( $app_url ); ?>" target="_blank" class="button">
        <?php esc_html_e( 'Open the app', 'xophz-compass-xp' ); ?> &rarr;
      </a>
    </p>

    <form method="post" action="options.php">
      <?php settings_fields( Xophz_Compass_Xp_Doit::OPTION_GROUP ); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">
            <label for="xophz_compass_doit_custom_slug"><?php esc_html_e( 'Permalink', 'xophz-compass-xp' ); ?></label>
          </th>
          <td>
            <code><?php echo esc_html( home_url( '/' ) ); ?></code>
            <input type="text" id="xophz_compass_doit_custom_slug" name="<?php echo esc_attr( Xophz_Compass_Xp_Doit::OPT_SLUG ); ?>" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" />
            <p class="description"><?php esc_html_e( 'Where the app lives, e.g. app or play/doit. The app is always reachable here.', 'xophz-compass-xp' ); ?></p>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e( 'Also load it', 'xophz-compass-xp' ); ?></th>
          <td>
            <fieldset>
              <label>
                <input type="radio" name="<?php echo esc_attr( Xophz_Compass_Xp_Doit::OPT_MODE ); ?>" value="routes_only" <?php checked( $load_mode, 'routes_only' ); ?> />
                <?php esc_html_e( 'Nowhere else (permalink only)', 'xophz-compass-xp' ); ?>
              </label><br />
              <label>
                <input type="radio" name="<?php echo esc_attr( Xophz_Compass_Xp_Doit::OPT_MODE ); ?>" value="homepage" <?php checked( $load_mode, 'homepage' ); ?> />
                <?php esc_html_e( 'As the site homepage', 'xophz-compass-xp' ); ?>
              </label><br />
              <label>
                <input type="radio" name="<?php echo esc_attr( Xophz_Compass_Xp_Doit::OPT_MODE ); ?>" value="specific_page" <?php checked( $load_mode, 'specific_page' ); ?> />
                <?php esc_html_e( 'On the page below', 'xophz-compass-xp' ); ?>
              </label>
            </fieldset>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="xophz_compass_doit_load_page_id"><?php esc_html_e( 'Page', 'xophz-compass-xp' ); ?></label>
          </th>
          <td>
            <select id="xophz_compass_doit_load_page_id" name="<?php echo esc_attr( Xophz_Compass_Xp_Doit::OPT_PAGE ); ?>">
              <option value="0"><?php esc_html_e( 'None', 'xophz-compass-xp' ); ?></option>
              <?php foreach ( get_pages() as $page ) : ?>
                <option value="<?php echo (int) $page->ID; ?>" <?php selected( $page_id, $page->ID ); ?>>
                  <?php echo esc_html( $page->post_title ); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e( 'Used when "On the page below" is selected.', 'xophz-compass-xp' ); ?></p>
          </td>
        </tr>
      </table>
      <?php submit_button(); ?>
    </form>
  <?php endif; ?>
</div>
