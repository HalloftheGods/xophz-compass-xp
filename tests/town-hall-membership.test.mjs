/**
 * Town Hall membership hook (TH8): a parent who links the game to the site becomes a Contributor.
 * The class loads with no WordPress; the hook methods run against small stubs of the WordPress
 * functions they call.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLASS_REL = '../includes/class-xophz-compass-xp-town-hall.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);

const APP_ID = '7606ce60-29c7-43b6-9533-2063ee726df4';
const DOIT = 'https://doit.forthexp.com';

const TOWN_HALL = "require_once '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/class-xophz-compass-xp-town-hall.php';\n";
const CLS = 'Xophz_Compass_Xp_Town_Hall';

function lit(value) {
  return `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;
}

/** Call should_join once per row of arguments, in a single PHP run. */
function shouldJoin(rows) {
  return runPhpJson(`${TOWN_HALL}
    $rows = ${lit(rows)};
    $out = [];
    foreach ( $rows as $args ) { $out[] = ${CLS}::should_join( ...$args ); }
    echo json_encode( $out );`);
}

/**
 * Run a hook method against stubbed WordPress functions and report what was asked and what was joined.
 * `blog` is the site the request is on, `site` its home_url, `member` whether the user already belongs.
 */
function runHook(method, { multisite = true, appId = APP_ID, site = DOIT, member = false, blog = 5, userId = 42 } = {}) {
  return runPhpJson(`${TOWN_HALL}
    $GLOBALS['xp_th'] = [ 'joined' => [], 'member_checks' => [], 'filter' => null ];
    function is_multisite() { return ${multisite ? 'true' : 'false'}; }
    function home_url() { return ${lit(site)}; }
    function get_current_blog_id() { return ${blog}; }
    function is_user_member_of_blog( $user_id, $blog_id ) {
      $GLOBALS['xp_th']['member_checks'][] = [ $user_id, $blog_id ];
      return ${member ? 'true' : 'false'};
    }
    function add_user_to_blog( $blog_id, $user_id, $role ) {
      $GLOBALS['xp_th']['joined'][] = [ $blog_id, $user_id, $role ];
      return true;
    }
    class WP_User { public $ID = ${userId}; }
    $th   = new ${CLS}( 'xophz-compass-xp', '1' );
    $item = [ 'app_id' => ${lit(appId)}, 'name' => 'Do It For The XP' ];
    ${method === 'on_authenticated' ? '$th->on_authenticated( new WP_User(), $item );' : `$th->on_password_created( ${userId}, $item );`}
    echo json_encode( $GLOBALS['xp_th'] );`);
}

describe('Town Hall membership: constants and hooks', () => {
  it('names the app id, the default site and the two hooks the loader registers', () => {
    const res = runPhpJson(`${TOWN_HALL}
      $th = new ${CLS}( 'xophz-compass-xp', '1' );
      echo json_encode( [ ${CLS}::APP_ID, ${CLS}::DEFAULT_SITE_URLS, $th->action_hooks ] );`);
    assert.strictEqual(res[0], APP_ID);
    assert.deepStrictEqual(res[1], [DOIT]);
    assert.deepStrictEqual(res[2], {
      wp_create_application_password: ['on_password_created', 10, 2],
      application_password_did_authenticate: ['on_authenticated', 10, 2],
    });
  });
});

describe('Town Hall membership: should_join', () => {
  const ALLOWED = [DOIT];

  it('joins a non-member who linked this app on the doit site', () => {
    // [is_multisite, app_id, site_url, allowed, is_member]
    const res = shouldJoin([
      [true, APP_ID, DOIT, ALLOWED, false],
      [true, APP_ID, `${DOIT}/`, ALLOWED, false],
    ]);
    assert.deepStrictEqual(res, [true, true]);
  });

  it('does nothing off a multisite network', () => {
    assert.deepStrictEqual(shouldJoin([[false, APP_ID, DOIT, ALLOWED, false]]), [false]);
  });

  it('does nothing for another app or no app id', () => {
    const res = shouldJoin([
      [true, 'other-app', DOIT, ALLOWED, false],
      [true, '', DOIT, ALLOWED, false],
    ]);
    assert.deepStrictEqual(res, [false, false]);
  });

  it('does nothing on a site that is not in the allowed list', () => {
    assert.deepStrictEqual(shouldJoin([[true, APP_ID, 'https://www.forthexp.com', ALLOWED, false]]), [false]);
  });

  it('does nothing for a user who is already a member', () => {
    assert.deepStrictEqual(shouldJoin([[true, APP_ID, DOIT, ALLOWED, true]]), [false]);
  });

  it('compares addresses without trailing slashes on either side', () => {
    const res = shouldJoin([
      [true, APP_ID, DOIT, [`${DOIT}/`], false],
      [true, APP_ID, `${DOIT}/`, [`${DOIT}/`], false],
      [true, APP_ID, DOIT, [], false],
    ]);
    assert.deepStrictEqual(res, [true, true, false]);
  });
});

describe('Town Hall membership: the hooks', () => {
  it('a new application password makes the user a Contributor on this site', () => {
    const res = runHook('on_password_created');
    assert.deepStrictEqual(res.member_checks, [[42, 5]]);
    assert.deepStrictEqual(res.joined, [[5, 42, 'contributor']]);
  });

  it('signing in with the application password does the same', () => {
    const res = runHook('on_authenticated');
    assert.deepStrictEqual(res.joined, [[5, 42, 'contributor']]);
  });

  it('an existing member is left alone', () => {
    assert.deepStrictEqual(runHook('on_password_created', { member: true }).joined, []);
    assert.deepStrictEqual(runHook('on_authenticated', { member: true }).joined, []);
  });

  it('another app, another site and a single site are left alone', () => {
    assert.deepStrictEqual(runHook('on_password_created', { appId: 'other-app' }).joined, []);
    assert.deepStrictEqual(runHook('on_password_created', { site: 'https://www.forthexp.com' }).joined, []);
    assert.deepStrictEqual(runHook('on_password_created', { multisite: false }).joined, []);
  });
});

describe('Town Hall membership: the file', () => {
  it('lints', () => {
    const lint = lintPhpFile(CLASS_FILE);
    assert.strictEqual(lint.isValid, true, lint.output);
  });

  it('files carry no em dashes', () => {
    for (const rel of [CLASS_REL, 'town-hall-membership.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
