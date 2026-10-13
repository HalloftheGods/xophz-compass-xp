/**
 * Town Hall membership hook (TH8): a parent who links the game to the site becomes a Contributor.
 * The class loads with no WordPress; the hook methods run against small stubs of the WordPress
 * functions they call. The hook tests send a bare PHP script (no harness stubs) because they need
 * to control get_current_user_id() and apply_filters(), which the harness stubs fix in place.
 */

import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
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

/** Run PHP in the u-wordpress container with nothing preloaded and parse its JSON output. */
function runBarePhpJson(code) {
  const res = spawnSync('docker', ['exec', '-i', 'u-wordpress', 'php'], {
    input: `<?php\n${code}`,
    encoding: 'utf8',
    timeout: 15000,
  });
  if (res.status !== 0) {
    throw new Error(`PHP execution failed with exit code ${res.status}: ${res.stderr || res.stdout}`);
  }
  return JSON.parse(res.stdout.trim());
}

const NO_APP_ID = Symbol('no app id');

/**
 * Run a hook method against stubbed WordPress functions and report what was asked and what was joined.
 * `blog` is the site the request is on, `site` its home_url, `member` whether the user already belongs,
 * `allowed` what the xp_town_hall_site_urls filter answers (null keeps the default it was given).
 * For on_admin_init: `pagenow`, `requestAppId` (NO_APP_ID leaves it out of $_REQUEST) and `userId`
 * (0 is a logged-out visitor) describe the request; for the other two, `appId` is the record's app id.
 */
function runHook(method, {
  multisite = true,
  appId = APP_ID,
  requestAppId = APP_ID,
  pagenow = 'authorize-application.php',
  site = DOIT,
  member = false,
  blog = 5,
  userId = 42,
  allowed = null,
} = {}) {
  const call = {
    on_admin_init: '$th->on_admin_init();',
    on_authenticated: '$th->on_authenticated( new WP_User(), $item );',
    on_password_created: `$th->on_password_created( ${userId}, $item );`,
  }[method];
  const request = requestAppId === NO_APP_ID ? '' : `$_REQUEST['app_id'] = ${lit(requestAppId)};`;
  return runBarePhpJson(`${TOWN_HALL}
    $GLOBALS['xp_th'] = [ 'joined' => [], 'member_checks' => [], 'filters' => [] ];
    $GLOBALS['pagenow'] = ${lit(pagenow)};
    ${request}
    function is_multisite() { return ${multisite ? 'true' : 'false'}; }
    function home_url() { return ${lit(site)}; }
    function get_current_blog_id() { return ${blog}; }
    function get_current_user_id() { return ${userId}; }
    function sanitize_text_field( $s ) { return strip_tags( trim( (string) $s ) ); }
    function wp_unslash( $v ) { return $v; }
    function apply_filters( $tag, $value ) {
      $GLOBALS['xp_th']['filters'][] = $tag;
      return ${allowed === null ? '$value' : lit(allowed)};
    }
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
    ${call}
    echo json_encode( $GLOBALS['xp_th'] );`);
}

describe('Town Hall membership: constants and hooks', () => {
  it('names the app id, the default site and the three hooks the loader registers', () => {
    const res = runPhpJson(`${TOWN_HALL}
      $th = new ${CLS}( 'xophz-compass-xp', '1' );
      echo json_encode( [ ${CLS}::APP_ID, ${CLS}::DEFAULT_SITE_URLS, $th->action_hooks ] );`);
    assert.strictEqual(res[0], APP_ID);
    assert.deepStrictEqual(res[1], [DOIT]);
    assert.deepStrictEqual(res[2], {
      admin_init: 'on_admin_init',
      wp_create_application_password: ['on_password_created', 10, 2],
      application_password_did_authenticate: ['on_authenticated', 10, 2],
    });
  });

  it('filters pre_comment_approved after core and other plugins (priority 99, two arguments)', () => {
    const res = runPhpJson(`${TOWN_HALL}
      $th = new ${CLS}( 'xophz-compass-xp', '1' );
      echo json_encode( $th->filter_hooks );`);
    assert.deepStrictEqual(res, { pre_comment_approved: ['on_pre_comment_approved', 99, 2] });
  });
});

/** Call comment_verdict once per row of arguments, in a single PHP run. */
function commentVerdict(rows) {
  return runPhpJson(`${TOWN_HALL}
    $rows = ${lit(rows)};
    $out = [];
    foreach ( $rows as $args ) { $out[] = ${CLS}::comment_verdict( ...$args ); }
    echo json_encode( $out );`);
}

describe('Town Hall comments: comment_verdict holds the game\'s comments for the owner', () => {
  const ALLOWED = [DOIT];

  it('holds a comment the game sent on the doit site, even one WordPress would approve at once', () => {
    // [approved, is_multisite, site_url, allowed, app_id]
    const res = commentVerdict([
      [1, true, DOIT, ALLOWED, APP_ID],
      ['1', true, `${DOIT}/`, ALLOWED, APP_ID],
      [0, true, DOIT, ALLOWED, APP_ID],
    ]);
    assert.deepStrictEqual(res, [0, 0, 0]);
  });

  it('keeps a spam or trash verdict as it is', () => {
    const res = commentVerdict([
      ['spam', true, DOIT, ALLOWED, APP_ID],
      ['trash', true, DOIT, ALLOWED, APP_ID],
    ]);
    assert.deepStrictEqual(res, ['spam', 'trash']);
  });

  it('leaves every other comment to WordPress: another app, no app password, another site, a single site', () => {
    const res = commentVerdict([
      [1, true, DOIT, ALLOWED, 'other-app'],
      [1, true, DOIT, ALLOWED, ''],
      [1, true, 'https://www.forthexp.com', ALLOWED, APP_ID],
      [1, false, DOIT, ALLOWED, APP_ID],
      [1, true, DOIT, [], APP_ID],
    ]);
    assert.deepStrictEqual(res, [1, 1, 1, 1, 1]);
  });
});

/**
 * Run on_pre_comment_approved against stubbed WordPress functions. `uuid` is what
 * rest_get_authenticated_app_password() answers (null: the request did not sign in with an
 * application password); `passwordAppId` is the app id of that password's record (null: no record).
 */
function runCommentHook({
  approved = 1,
  uuid = 'pw-1',
  passwordAppId = APP_ID,
  userId = 42,
  multisite = true,
  site = DOIT,
  allowed = null,
  hasAppPasswordApi = true,
} = {}) {
  const api = hasAppPasswordApi
    ? `function rest_get_authenticated_app_password() { return ${lit(uuid)}; }`
    : '';
  return runBarePhpJson(`${TOWN_HALL}
    $GLOBALS['xp_th'] = [ 'lookups' => [], 'filters' => [] ];
    ${api}
    function get_current_user_id() { return ${userId}; }
    function is_multisite() { return ${multisite ? 'true' : 'false'}; }
    function home_url() { return ${lit(site)}; }
    function apply_filters( $tag, $value ) {
      $GLOBALS['xp_th']['filters'][] = $tag;
      return ${allowed === null ? '$value' : lit(allowed)};
    }
    class WP_Application_Passwords {
      public static function get_user_application_password( $user_id, $uuid ) {
        $GLOBALS['xp_th']['lookups'][] = [ $user_id, $uuid ];
        $app_id = ${lit(passwordAppId)};
        return null === $app_id ? null : [ 'uuid' => $uuid, 'app_id' => $app_id, 'name' => 'Do It For The XP' ];
      }
    }
    $th = new ${CLS}( 'xophz-compass-xp', '1' );
    $verdict = $th->on_pre_comment_approved( ${lit(approved)}, [ 'comment_post_ID' => 7, 'user_id' => ${userId} ] );
    echo json_encode( [ 'verdict' => $verdict ] + $GLOBALS['xp_th'] );`);
}

describe('Town Hall comments: the pre_comment_approved hook', () => {
  it('holds a comment sent with the game\'s application password, though its author wrote the post', () => {
    const res = runCommentHook();
    assert.strictEqual(res.verdict, 0);
    assert.deepStrictEqual(res.lookups, [[42, 'pw-1']]);
    assert.deepStrictEqual(res.filters, ['xp_town_hall_site_urls']);
  });

  it('keeps spam and trash', () => {
    assert.strictEqual(runCommentHook({ approved: 'spam' }).verdict, 'spam');
    assert.strictEqual(runCommentHook({ approved: 'trash' }).verdict, 'trash');
  });

  it('leaves a comment made without an application password alone, before any lookup', () => {
    const res = runCommentHook({ uuid: null });
    assert.strictEqual(res.verdict, 1);
    assert.deepStrictEqual(res.lookups, []);
    assert.deepStrictEqual(res.filters, []);
    assert.strictEqual(runCommentHook({ uuid: '' }).verdict, 1);
  });

  it('leaves a comment sent with another app\'s password, or a password with no record, alone', () => {
    const other = runCommentHook({ passwordAppId: 'other-app' });
    assert.strictEqual(other.verdict, 1);
    assert.deepStrictEqual(other.filters, []);
    assert.strictEqual(runCommentHook({ passwordAppId: null }).verdict, 1);
  });

  it('leaves comments alone on another site, a single site, or for a visitor who is not signed in', () => {
    assert.strictEqual(runCommentHook({ site: 'https://www.forthexp.com' }).verdict, 1);
    assert.strictEqual(runCommentHook({ multisite: false }).verdict, 1);
    const visitor = runCommentHook({ userId: 0 });
    assert.strictEqual(visitor.verdict, 1);
    assert.deepStrictEqual(visitor.lookups, []);
  });

  it('uses the same site allowlist filter as the membership hooks', () => {
    const rehearsal = 'http://localhost:8090/doit';
    assert.strictEqual(runCommentHook({ site: rehearsal }).verdict, 1);
    assert.strictEqual(runCommentHook({ site: rehearsal, allowed: [rehearsal] }).verdict, 0);
  });

  it('does nothing on a WordPress without the application password API', () => {
    assert.strictEqual(runCommentHook({ hasAppPasswordApi: false }).verdict, 1);
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

describe('Town Hall membership: the password hooks', () => {
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

  it('another app is turned away before any filter or membership lookup', () => {
    const res = runHook('on_password_created', { appId: 'other-app' });
    assert.deepStrictEqual(res.filters, []);
    assert.deepStrictEqual(res.member_checks, []);
  });

  it('the site allowlist comes from the xp_town_hall_site_urls filter', () => {
    const rehearsal = 'http://localhost:8088/doit';
    assert.deepStrictEqual(runHook('on_password_created', { site: rehearsal }).joined, []);
    const res = runHook('on_password_created', { site: rehearsal, allowed: [rehearsal] });
    assert.deepStrictEqual(res.filters, ['xp_town_hall_site_urls']);
    assert.deepStrictEqual(res.joined, [[5, 42, 'contributor']]);
  });
});

describe('Town Hall membership: before Approve (admin_init)', () => {
  it('adds a non-member as a Contributor when they open the approval screen for this app', () => {
    const res = runHook('on_admin_init');
    assert.deepStrictEqual(res.member_checks, [[42, 5]]);
    assert.deepStrictEqual(res.joined, [[5, 42, 'contributor']]);
  });

  it('uses the same allowlist filter as the other hooks', () => {
    const rehearsal = 'http://localhost:8088/doit';
    assert.deepStrictEqual(runHook('on_admin_init', { site: rehearsal }).joined, []);
    assert.deepStrictEqual(runHook('on_admin_init', { site: rehearsal, allowed: [rehearsal] }).joined, [[5, 42, 'contributor']]);
  });

  it('does nothing on any other admin page', () => {
    for (const pagenow of ['index.php', 'users.php', 'profile.php', '']) {
      assert.deepStrictEqual(runHook('on_admin_init', { pagenow }).joined, [], pagenow);
    }
  });

  it('does nothing for another app id, a missing app id or a malformed one', () => {
    assert.deepStrictEqual(runHook('on_admin_init', { requestAppId: 'other-app' }).joined, []);
    assert.deepStrictEqual(runHook('on_admin_init', { requestAppId: '' }).joined, []);
    assert.deepStrictEqual(runHook('on_admin_init', { requestAppId: NO_APP_ID }).joined, []);
    assert.deepStrictEqual(runHook('on_admin_init', { requestAppId: [APP_ID] }).joined, []);
  });

  it('does nothing for a visitor who is not signed in', () => {
    const res = runHook('on_admin_init', { userId: 0 });
    assert.deepStrictEqual(res.joined, []);
    assert.deepStrictEqual(res.member_checks, []);
  });

  it('leaves a member, a single site and a site off the allowlist alone', () => {
    assert.deepStrictEqual(runHook('on_admin_init', { member: true }).joined, []);
    assert.deepStrictEqual(runHook('on_admin_init', { multisite: false }).joined, []);
    assert.deepStrictEqual(runHook('on_admin_init', { site: 'https://www.forthexp.com' }).joined, []);
  });

  it('turns another app away before any filter or membership lookup', () => {
    const res = runHook('on_admin_init', { requestAppId: 'other-app' });
    assert.deepStrictEqual(res.filters, []);
    assert.deepStrictEqual(res.member_checks, []);
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
