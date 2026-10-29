/**
 * Party schema (parties and raids): the seven tables, installing them on a family site only, and the daily cleanup.
 * WordPress is stubbed inside each snippet; the owner's database is not touched.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLASS_REL = '../includes/party/class-xophz-compass-xp-party-schema.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);
const MAIN_FILE = path.join(HERE, '../includes/class-xophz-compass-xp.php');

const PLUGIN = '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/';
const SCHEMA = `require_once '${PLUGIN}family/class-xophz-compass-xp-family-rules.php';
require_once '${PLUGIN}family/class-xophz-compass-xp-family-event-rules.php';
require_once '${PLUGIN}family/class-xophz-compass-xp-family-schema.php';
require_once '${PLUGIN}party/class-xophz-compass-xp-party-schema.php';
`;
const CLS = 'Xophz_Compass_Xp_Party_Schema';

/** The few WordPress functions the schema calls, as recording stubs. */
const STUBS = `
$GLOBALS['t'] = [ 'home' => 'https://doit.forthexp.com/', 'scheduled' => false, 'delta' => [], 'cron' => [] ];
function home_url() { return $GLOBALS['t']['home']; }
function dbDelta( $sql ) { $GLOBALS['t']['delta'][] = $sql; return []; }
function wp_next_scheduled( $hook ) { return $GLOBALS['t']['scheduled']; }
function wp_schedule_event( $ts, $recurrence, $hook ) { $GLOBALS['t']['cron'][] = [ $recurrence, $hook, is_int( $ts ) ]; return true; }
class Fake_Wpdb {
  public $prefix = 'wp_5_';
  public $queries = [];
  public function prepare( $sql, ...$args ) {
    foreach ( $args as $a ) { $sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 ); }
    return $sql;
  }
  public function query( $sql ) { $this->queries[] = $sql; return 0; }
  public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
}
$wpdb = new Fake_Wpdb();
`;

const NAMES = [
  'wp_5_xp_parties',
  'wp_5_xp_party_members',
  'wp_5_xp_party_requests',
  'wp_5_xp_party_events',
  'wp_5_xp_party_raids',
  'wp_5_xp_party_strikes',
  'wp_5_xp_party_blocks',
];

function tables() {
  return runPhpJson(`${SCHEMA} echo json_encode( ${CLS}::tables( 'wp_5_', 'DEFAULT CHARSET=utf8mb4' ) );`);
}

/** The column names of a CREATE TABLE statement, in order. */
function columns(sql) {
  return sql
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => /^[a-z_]+ [a-z]+/.test(line) && !/^(PRIMARY|UNIQUE|KEY|CREATE) /.test(line))
    .map((line) => line.split(' ')[0]);
}

/** The definition of one column. */
function column(sql, name) {
  const line = sql.split('\n').map((l) => l.trim()).find((l) => l.startsWith(`${name} `));
  assert.ok(line, `no column ${name}`);
  return line;
}

describe('Party schema: constants and hooks', () => {
  it('holds the version, option and cleanup hook', () => {
    const res = runPhpJson(`${SCHEMA} echo json_encode( [ ${CLS}::VERSION, ${CLS}::OPTION, ${CLS}::CLEANUP_HOOK ] );`);
    assert.deepStrictEqual(res, ['1', 'xp_party_db_version', 'xp_party_cleanup']);
  });

  it('installs on init at priority 5 and cleans up on the daily hook', () => {
    const res = runPhpJson(`${SCHEMA}
      $schema = new ${CLS}( 'xophz-compass-xp', '1' );
      echo json_encode( $schema->action_hooks );`);
    assert.deepStrictEqual(res, { init: ['maybe_install', 5], xp_party_cleanup: 'cleanup' });
  });

  it('keeps how long things are kept', () => {
    const res = runPhpJson(`${SCHEMA} echo json_encode( [ ${CLS}::EVENT_DAYS, ${CLS}::REQUEST_DAYS, ${CLS}::RAID_DAYS, ${CLS}::MEMBER_DAYS ] );`);
    assert.deepStrictEqual(res, [90, 30, 90, 30]);
  });
});

describe('Party schema: tables', () => {
  it('declares the seven tables in order, each in the form dbDelta reads', () => {
    const sql = tables();
    assert.deepStrictEqual(Object.keys(sql), NAMES);
    for (const [name, statement] of Object.entries(sql)) {
      assert.ok(statement.startsWith(`CREATE TABLE ${name} (`), name);
      assert.ok(statement.endsWith(') DEFAULT CHARSET=utf8mb4;'), name);
      assert.ok(/PRIMARY KEY {2}\(/.test(statement), `${name}: two spaces after PRIMARY KEY`);
    }
  });

  it('parties carry a unique code and their own event counter', () => {
    const parties = tables()['wp_5_xp_parties'];
    assert.deepStrictEqual(columns(parties), ['party_id', 'name', 'emblem', 'code', 'invite_rule', 'leader_family_id', 'leader_hero_id', 'events_seq', 'created_at', 'disbanded_at']);
    assert.ok(column(parties, 'code').startsWith('code char(8) NOT NULL'));
    assert.ok(column(parties, 'events_seq').startsWith('events_seq bigint(20) unsigned NOT NULL DEFAULT 0'));
    assert.ok(parties.includes('PRIMARY KEY  (party_id)'));
    assert.ok(parties.includes('UNIQUE KEY code (code)'));
  });

  it('members are named by party, family and hero, and hold only the public card', () => {
    const members = tables()['wp_5_xp_party_members'];
    assert.deepStrictEqual(columns(members), ['party_id', 'family_id', 'hero_id', 'card', 'role', 'joined_at', 'left_at']);
    assert.ok(members.includes('PRIMARY KEY  (party_id,family_id,hero_id)'));
    assert.ok(members.includes('KEY family_hero (family_id,hero_id)'));
  });

  it('requests find the open ones of a party and of a family by index', () => {
    const requests = tables()['wp_5_xp_party_requests'];
    assert.deepStrictEqual(columns(requests), ['request_id', 'party_id', 'family_id', 'hero_id', 'card', 'created_at', 'resolved_at', 'resolution']);
    assert.ok(requests.includes('KEY party_open (party_id,resolved_at)'));
    assert.ok(requests.includes('KEY family_open (family_id,resolved_at)'));
  });

  it('events number per party, and the store numbers them, not MySQL', () => {
    const events = tables()['wp_5_xp_party_events'];
    assert.deepStrictEqual(columns(events), ['party_id', 'seq', 'kind', 'family_id', 'hero_id', 'data', 'created_at']);
    assert.ok(column(events, 'seq').startsWith('seq bigint(20) unsigned NOT NULL'));
    assert.ok(!column(events, 'seq').includes('AUTO_INCREMENT'));
    assert.ok(events.includes('PRIMARY KEY  (party_id,seq)'));
    assert.ok(events.includes('KEY created_at (created_at)'));
  });

  it('raids keep their window in raid_window, a name MySQL does not reserve', () => {
    const raids = tables()['wp_5_xp_party_raids'];
    assert.deepStrictEqual(columns(raids), ['raid_id', 'party_id', 'beast_id', 'raid_window', 'max_hp', 'member_count', 'started_at', 'ends_at']);
    assert.ok(!columns(raids).includes('window'));
    assert.ok(raids.includes('PRIMARY KEY  (raid_id)'));
    assert.ok(raids.includes('KEY party_started (party_id,started_at)'));
    assert.ok(raids.includes('KEY ends_at (ends_at)'), 'the cleanup finds old raids by index');
  });

  it('strikes are unique per raid and key, with keys that differ only by case kept apart', () => {
    const strikes = tables()['wp_5_xp_party_strikes'];
    assert.deepStrictEqual(columns(strikes), ['raid_id', 'strike_key', 'family_id', 'hero_id', 'damage', 'day', 'struck_at', 'created_at']);
    assert.ok(column(strikes, 'strike_key').startsWith('strike_key varchar(80) COLLATE utf8mb4_bin NOT NULL'));
    assert.ok(column(strikes, 'day').startsWith('day char(10) NOT NULL'));
    assert.ok(strikes.includes('PRIMARY KEY  (raid_id,strike_key)'));
    assert.ok(strikes.includes('KEY member_day (raid_id,family_id,hero_id,day)'), 'the daily cap sums by index');
  });

  it('blocks are one row per family and party', () => {
    const blocks = tables()['wp_5_xp_party_blocks'];
    assert.deepStrictEqual(columns(blocks), ['family_id', 'party_id', 'created_at']);
    assert.ok(blocks.includes('PRIMARY KEY  (family_id,party_id)'));
  });

  it('follows the prefix and charset it is given', () => {
    const res = runPhpJson(`${SCHEMA} echo json_encode( ${CLS}::tables( 'x_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' ) );`);
    assert.deepStrictEqual(Object.keys(res), NAMES.map((name) => name.replace('wp_5_', 'x_')));
    for (const sql of Object.values(res)) assert.ok(sql.endsWith(') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;'));
  });
});

describe('Party schema: install', () => {
  function install({ home = 'https://doit.forthexp.com/', scheduled = false } = {}) {
    return runPhpJson(`${STUBS}
      $GLOBALS['t']['home'] = ${JSON.stringify(home)};
      $GLOBALS['t']['scheduled'] = ${JSON.stringify(scheduled)};
      ${SCHEMA}
      $schema = new ${CLS}( 'xophz-compass-xp', '1' );
      $schema->maybe_install();
      echo json_encode( [ 'delta' => $GLOBALS['t']['delta'], 'cron' => $GLOBALS['t']['cron'] ] );`);
  }

  it('on a family site it creates the seven tables and schedules the daily cleanup', () => {
    const res = install();
    assert.deepStrictEqual(res.delta.map((sql) => sql.match(/^CREATE TABLE (\S+)/)[1]), NAMES);
    assert.deepStrictEqual(res.cron, [['daily', 'xp_party_cleanup', true]]);
  });

  it('on any other site it does nothing', () => {
    for (const home of ['https://www.forthexp.com', 'http://localhost', 'https://doit.forthexp.com.evil.test', '']) {
      assert.deepStrictEqual(install({ home }), { delta: [], cron: [] }, home);
    }
  });

  it('does not schedule the cleanup twice', () => {
    const res = install({ scheduled: 1800000000 });
    assert.strictEqual(res.delta.length, 7);
    assert.deepStrictEqual(res.cron, []);
  });
});

describe('Party schema: cleanup', () => {
  function cleanup(home = 'https://doit.forthexp.com/') {
    return runPhpJson(`${STUBS}
      $GLOBALS['t']['home'] = ${JSON.stringify(home)};
      ${SCHEMA}
      $schema = new ${CLS}( 'xophz-compass-xp', '1' );
      $before = time();
      $schema->cleanup();
      $after = time();
      echo json_encode( [ 'queries' => $wpdb->queries, 'before' => $before, 'after' => $after ] );`);
  }

  const seconds = (stamp) => Date.parse(`${stamp.replace(' ', 'T')}Z`) / 1000;
  const DAY = 86400;
  const STAMP = "'([\\d: -]{19})'";

  it('deletes old events, resolved requests, then the strikes and raids that ended long ago, and members who left', () => {
    const res = cleanup();
    assert.strictEqual(res.queries.length, 5);

    const cases = [
      [new RegExp(`^DELETE FROM wp_5_xp_party_events WHERE created_at < ${STAMP}$`), 90],
      [new RegExp(`^DELETE FROM wp_5_xp_party_requests WHERE resolved_at IS NOT NULL AND resolved_at < ${STAMP}$`), 30],
      [new RegExp(`^DELETE FROM wp_5_xp_party_strikes WHERE raid_id IN \\(SELECT raid_id FROM wp_5_xp_party_raids WHERE ends_at < ${STAMP}\\)$`), 90],
      [new RegExp(`^DELETE FROM wp_5_xp_party_raids WHERE ends_at < ${STAMP}$`), 90],
      [new RegExp(`^DELETE FROM wp_5_xp_party_members WHERE left_at IS NOT NULL AND left_at < ${STAMP}$`), 30],
    ];
    cases.forEach(([pattern, days], index) => {
      const match = res.queries[index].match(pattern);
      assert.ok(match, res.queries[index]);
      assert.ok(seconds(match[1]) >= res.before - days * DAY && seconds(match[1]) <= res.after - days * DAY, `${days} days: ${res.queries[index]}`);
    });
  });

  it('removes the strikes of a raid before the raid itself', () => {
    const { queries } = cleanup();
    const strikes = queries.findIndex((sql) => sql.includes('xp_party_strikes'));
    const raids = queries.findIndex((sql) => sql.startsWith('DELETE FROM wp_5_xp_party_raids '));
    assert.ok(strikes >= 0 && strikes < raids);
  });

  it('touches nothing on a site that is not a family site', () => {
    assert.deepStrictEqual(cleanup('https://www.forthexp.com').queries, []);
  });
});

describe('Party schema: loading', () => {
  it('the plugin requires the party rules before the schema and hooks the schema', () => {
    const main = fs.readFileSync(MAIN_FILE, 'utf8');
    const rules = main.indexOf("includes/party/class-xophz-compass-xp-party-rules.php'");
    const schema = main.indexOf("includes/party/class-xophz-compass-xp-party-schema.php'");
    assert.ok(rules > 0 && schema > 0, 'both require_once lines');
    assert.ok(rules < schema, 'rules before schema');
    assert.ok(main.includes("$this->define_class_hooks( 'Xophz_Compass_Xp_Party_Schema' );"));
  });

  it('the plugin class lints', () => {
    const lint = lintPhpFile(MAIN_FILE);
    assert.strictEqual(lint.isValid, true, lint.output);
  });
});

describe('Party schema: the file', () => {
  it('lints', () => {
    const lint = lintPhpFile(CLASS_FILE);
    assert.strictEqual(lint.isValid, true, lint.output);
  });

  it('files carry no em dashes', () => {
    for (const rel of [CLASS_REL, 'party-schema.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
