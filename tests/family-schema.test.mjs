/**
 * Family schema (MP3, schema 2): the six tables, installing them on a family site only, and the
 * daily cleanup. WordPress is stubbed inside each snippet; the owner's database is not touched.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLASS_REL = '../includes/family/class-xophz-compass-xp-family-schema.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);
const MAIN_FILE = path.join(HERE, '../includes/class-xophz-compass-xp.php');

const PLUGIN = '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/family/';
const SCHEMA = `require_once '${PLUGIN}class-xophz-compass-xp-family-rules.php';
require_once '${PLUGIN}class-xophz-compass-xp-family-event-rules.php';
require_once '${PLUGIN}class-xophz-compass-xp-family-schema.php';
`;
const CLS = 'Xophz_Compass_Xp_Family_Schema';

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

const NAMES = ['wp_5_xp_families', 'wp_5_xp_family_heroes', 'wp_5_xp_family_devices', 'wp_5_xp_family_link_codes', 'wp_5_xp_family_events', 'wp_5_xp_family_records'];

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

describe('Family schema: constants and hooks', () => {
  it('holds the version, option and cleanup hook of the spec', () => {
    const res = runPhpJson(`${SCHEMA} echo json_encode( [ ${CLS}::VERSION, ${CLS}::OPTION, ${CLS}::CLEANUP_HOOK ] );`);
    assert.deepStrictEqual(res, ['2', 'xp_family_db_version', 'xp_family_cleanup']);
  });

  it('installs on init at priority 5 and cleans up on the daily hook', () => {
    const res = runPhpJson(`${SCHEMA}
      $schema = new ${CLS}( 'xophz-compass-xp', '1' );
      echo json_encode( [ $schema->action_hooks, $schema->filter_hooks ?? null ] );`);
    assert.deepStrictEqual(res[0], { init: ['maybe_install', 5], xp_family_cleanup: 'cleanup' });
  });
});

describe('Family schema: tables', () => {
  it('has the family tables, named with the prefix', () => {
    assert.deepStrictEqual(Object.keys(tables()), NAMES);
  });

  it('every statement is a dbDelta style CREATE TABLE with the charset', () => {
    const sql = tables();
    for (const name of NAMES) {
      assert.ok(sql[name].startsWith(`CREATE TABLE ${name} (\n`), `${name} header`);
      assert.ok(sql[name].endsWith(') DEFAULT CHARSET=utf8mb4;'), `${name} footer`);
      assert.ok(!sql[name].includes('0000-00-00'), `${name} must not default to a zero date`);
      for (const line of sql[name].split('\n').filter((l) => l.includes('PRIMARY KEY'))) {
        assert.ok(/PRIMARY KEY {2}\(/.test(line), `${name}: two spaces after PRIMARY KEY (dbDelta): ${line}`);
      }
      const keyLines = sql[name].split('\n').filter((l) => /^\s*(PRIMARY KEY|UNIQUE KEY|KEY)/.test(l));
      assert.ok(keyLines.length >= 1, `${name} has a key`);
    }
  });

  it('xp_families: family id primary key, one family per owner', () => {
    const sql = tables()['wp_5_xp_families'];
    assert.deepStrictEqual(columns(sql), ['family_id', 'owner_user_id', 'home_device_id', 'created_at', 'updated_at', 'records_seq']);
    assert.ok(column(sql, 'family_id').startsWith('family_id char(36) NOT NULL'));
    assert.ok(column(sql, 'owner_user_id').startsWith('owner_user_id bigint(20) unsigned NOT NULL'));
    assert.ok(column(sql, 'home_device_id').startsWith('home_device_id varchar(16) NOT NULL'));
    assert.ok(sql.includes('PRIMARY KEY  (family_id)'));
    assert.ok(sql.includes('UNIQUE KEY owner_user_id (owner_user_id)'));
  });

  it('xp_family_heroes: primary key on family and hero', () => {
    const sql = tables()['wp_5_xp_family_heroes'];
    assert.deepStrictEqual(columns(sql), ['family_id', 'hero_id', 'nickname', 'age_group', 'card', 'card_at', 'created_at', 'removed_at', 'turn_device_id', 'turn_label', 'turn_taken_at', 'turn_seen_at']);
    assert.ok(sql.includes('PRIMARY KEY  (family_id,hero_id)'));
    assert.ok(column(sql, 'hero_id').startsWith('hero_id varchar(64) NOT NULL'));
    assert.ok(column(sql, 'nickname').startsWith('nickname varchar(40) NOT NULL'));
    assert.ok(column(sql, 'age_group').startsWith('age_group varchar(8) NOT NULL'));
    assert.ok(column(sql, 'card').startsWith('card longtext'));
    assert.ok(/removed_at datetime DEFAULT NULL/.test(sql));
    assert.ok(/card_at datetime DEFAULT NULL/.test(sql));
  });

  it('xp_family_devices: one row per phone, a unique key hash, nullable revoked time', () => {
    const sql = tables()['wp_5_xp_family_devices'];
    assert.deepStrictEqual(columns(sql), ['id', 'family_id', 'hero_id', 'device_id', 'label', 'key_hash', 'created_at', 'last_seen_at', 'revoked_at']);
    assert.ok(column(sql, 'id').startsWith('id bigint(20) unsigned NOT NULL AUTO_INCREMENT'));
    assert.ok(column(sql, 'device_id').startsWith('device_id varchar(16) NOT NULL'));
    assert.ok(column(sql, 'label').startsWith('label varchar(60) NOT NULL'));
    assert.ok(column(sql, 'key_hash').startsWith('key_hash char(64) NOT NULL'));
    assert.ok(/revoked_at datetime DEFAULT NULL/.test(sql));
    assert.ok(sql.includes('PRIMARY KEY  (id)'));
    assert.ok(sql.includes('UNIQUE KEY key_hash (key_hash)'));
    assert.ok(sql.includes('KEY family_hero (family_id,hero_id)'));
  });

  it('xp_family_link_codes: stores only the code hash', () => {
    const sql = tables()['wp_5_xp_family_link_codes'];
    assert.deepStrictEqual(columns(sql), ['code_hash', 'family_id', 'hero_id', 'created_by', 'expires_at', 'used_at']);
    assert.ok(column(sql, 'code_hash').startsWith('code_hash char(64) NOT NULL'));
    assert.ok(column(sql, 'created_by').startsWith('created_by bigint(20) unsigned NOT NULL'));
    assert.ok(/used_at datetime DEFAULT NULL/.test(sql));
    assert.ok(sql.includes('PRIMARY KEY  (code_hash)'));
    assert.ok(sql.includes('KEY family_hero (family_id,hero_id)'));
    assert.ok(sql.includes('KEY expires_at (expires_at)'));
  });

  it('xp_family_events: sequence number, unique event id, family sequence index', () => {
    const sql = tables()['wp_5_xp_family_events'];
    assert.deepStrictEqual(columns(sql), ['seq', 'event_id', 'family_id', 'hero_id', 'device_id', 'kind', 'scope', 'occurred_at', 'received_at', 'data']);
    assert.ok(column(sql, 'seq').startsWith('seq bigint(20) unsigned NOT NULL AUTO_INCREMENT'));
    assert.ok(column(sql, 'event_id').startsWith('event_id char(36) NOT NULL'));
    assert.ok(column(sql, 'kind').startsWith('kind varchar(40) NOT NULL'));
    assert.ok(column(sql, 'scope').startsWith('scope varchar(8) NOT NULL'));
    assert.ok(column(sql, 'data').startsWith('data longtext'));
    assert.ok(sql.includes('PRIMARY KEY  (seq)'));
    assert.ok(sql.includes('UNIQUE KEY event_id (event_id)'));
    assert.ok(sql.includes('KEY family_seq (family_id,seq)'));
    assert.ok(sql.includes('KEY received_at (received_at)'));
  });

  it('version 2 declares six tables, records with its three keys', () => {
    const [version, sql] = runPhpJson(`${SCHEMA} echo json_encode( [ ${CLS}::VERSION, ${CLS}::tables( 'wp_5_', 'DEFAULT CHARSET=utf8mb4' ) ] );`);
    assert.strictEqual(version, '2');
    assert.strictEqual(Object.values(sql).filter((statement) => statement.startsWith('CREATE TABLE ')).length, 6);

    const records = sql['wp_5_xp_family_records'];
    assert.ok(records, 'the records table is declared');
    assert.deepStrictEqual(columns(records), ['seq', 'family_id', 'store', 'record_key', 'hero_id', 'device_id', 'saved_at', 'deleted', 'body']);
    assert.ok(column(records, 'seq').startsWith('seq bigint(20) unsigned NOT NULL'));
    assert.ok(!column(records, 'seq').includes('AUTO_INCREMENT'), 'the store numbers the rows, not MySQL');
    assert.ok(column(records, 'family_id').startsWith('family_id char(36) NOT NULL'));
    assert.ok(column(records, 'store').startsWith('store varchar(40) NOT NULL'));
    assert.ok(column(records, 'record_key').startsWith('record_key varchar(191) NOT NULL'));
    assert.ok(column(records, 'hero_id').startsWith('hero_id varchar(64) DEFAULT NULL'));
    assert.ok(column(records, 'device_id').startsWith('device_id varchar(16) NOT NULL'));
    assert.ok(column(records, 'saved_at').startsWith('saved_at datetime NOT NULL'));
    assert.ok(column(records, 'deleted').startsWith('deleted tinyint(1) NOT NULL DEFAULT 0'));
    assert.ok(column(records, 'body').startsWith('body mediumtext'));
    assert.ok(records.includes('PRIMARY KEY  (seq)'));
    assert.ok(records.includes('UNIQUE KEY family_store_key (family_id,store,record_key)'));
    assert.ok(records.includes('KEY family_seq (family_id,seq)'));
    assert.ok(records.includes('KEY family_hero_seq (family_id,hero_id,seq)'));
  });

  it('families carry records_seq and heroes the four turn columns', () => {
    const sql = tables();
    const families = sql['wp_5_xp_families'];
    assert.ok(column(families, 'records_seq').startsWith('records_seq bigint(20) unsigned NOT NULL DEFAULT 0'));

    const heroes = sql['wp_5_xp_family_heroes'];
    assert.ok(column(heroes, 'turn_device_id').startsWith('turn_device_id varchar(16) DEFAULT NULL'));
    assert.ok(column(heroes, 'turn_label').startsWith("turn_label varchar(60) NOT NULL DEFAULT ''"));
    assert.ok(column(heroes, 'turn_taken_at').startsWith('turn_taken_at datetime DEFAULT NULL'));
    assert.ok(column(heroes, 'turn_seen_at').startsWith('turn_seen_at datetime DEFAULT NULL'));
  });

  it('follows the prefix and charset it is given', () => {
    const res = runPhpJson(`${SCHEMA} echo json_encode( ${CLS}::tables( 'x_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' ) );`);
    assert.deepStrictEqual(Object.keys(res), ['x_xp_families', 'x_xp_family_heroes', 'x_xp_family_devices', 'x_xp_family_link_codes', 'x_xp_family_events', 'x_xp_family_records']);
    for (const sql of Object.values(res)) {
      assert.ok(sql.endsWith(') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;'));
    }
  });
});

describe('Family schema: install', () => {
  function install({ home = 'https://doit.forthexp.com/', scheduled = false, before = '' } = {}) {
    return runPhpJson(`${STUBS}
      ${before}
      $GLOBALS['t']['home'] = ${JSON.stringify(home)};
      $GLOBALS['t']['scheduled'] = ${JSON.stringify(scheduled)};
      ${SCHEMA}
      $schema = new ${CLS}( 'xophz-compass-xp', '1' );
      $schema->maybe_install();
      echo json_encode( [ 'delta' => $GLOBALS['t']['delta'], 'cron' => $GLOBALS['t']['cron'] ] );`);
  }

  it('on a family site it creates the six tables and schedules the daily cleanup', () => {
    const res = install();
    assert.strictEqual(res.delta.length, 6);
    assert.deepStrictEqual(res.delta.map((sql) => sql.match(/^CREATE TABLE (\S+)/)[1]), NAMES);
    assert.deepStrictEqual(res.cron, [['daily', 'xp_family_cleanup', true]]);
  });

  it('on any other site it does nothing', () => {
    for (const home of ['https://www.forthexp.com', 'http://localhost', 'https://doit.forthexp.com.evil.test', '']) {
      const res = install({ home });
      assert.deepStrictEqual(res, { delta: [], cron: [] }, home);
    }
  });

  it('does not schedule the cleanup twice', () => {
    const res = install({ scheduled: 1800000000 });
    assert.strictEqual(res.delta.length, 6);
    assert.deepStrictEqual(res.cron, []);
  });

  it('asks the Town Hall class for its list of sites when it exists', () => {
    const before = "class Xophz_Compass_Xp_Town_Hall { const DEFAULT_SITE_URLS = [ 'https://rehearsal.test' ]; }";
    assert.strictEqual(install({ home: 'https://rehearsal.test/', before }).delta.length, 6);
    assert.deepStrictEqual(install({ home: 'https://doit.forthexp.com/', before }), { delta: [], cron: [] });
  });
});

describe('Family schema: cleanup', () => {
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

  it('deletes events older than 90 days, expired codes and devices revoked over 30 days ago', () => {
    const res = cleanup();
    assert.strictEqual(res.queries.length, 4);
    const day = 86400;

    const events = res.queries[0].match(/^DELETE FROM wp_5_xp_family_events WHERE received_at < '([\d: -]{19})'$/);
    assert.ok(events, res.queries[0]);
    assert.ok(seconds(events[1]) >= res.before - 90 * day && seconds(events[1]) <= res.after - 90 * day);

    const codes = res.queries[1].match(/^DELETE FROM wp_5_xp_family_link_codes WHERE expires_at < '([\d: -]{19})'$/);
    assert.ok(codes, res.queries[1]);
    assert.ok(seconds(codes[1]) >= res.before && seconds(codes[1]) <= res.after);

    const devices = res.queries[2].match(/^DELETE FROM wp_5_xp_family_devices WHERE revoked_at IS NOT NULL AND revoked_at < '([\d: -]{19})'$/);
    assert.ok(devices, res.queries[2]);
    assert.ok(seconds(devices[1]) >= res.before - 30 * day && seconds(devices[1]) <= res.after - 30 * day);
  });

  it('cleanup deletes tombstones older than 90 days', () => {
    const res = cleanup();
    const day = 86400;
    assert.strictEqual(runPhpJson(`${SCHEMA} echo json_encode( ${CLS}::TOMBSTONE_DAYS );`), 90);

    const tombstones = res.queries.filter((sql) => sql.startsWith('DELETE FROM wp_5_xp_family_records '));
    assert.strictEqual(tombstones.length, 1, 'one delete on the records table');
    const match = tombstones[0].match(/^DELETE FROM wp_5_xp_family_records WHERE deleted = 1 AND saved_at < '([\d: -]{19})'$/);
    assert.ok(match, tombstones[0]);
    assert.ok(seconds(match[1]) >= res.before - 90 * day && seconds(match[1]) <= res.after - 90 * day);
  });

  it('touches nothing on a site that is not a family site', () => {
    assert.deepStrictEqual(cleanup('https://www.forthexp.com').queries, []);
  });
});

describe('Family schema: loading', () => {
  it('the plugin requires the two rule classes before the schema and hooks the schema', () => {
    const main = fs.readFileSync(MAIN_FILE, 'utf8');
    const rules = main.indexOf("includes/family/class-xophz-compass-xp-family-rules.php'");
    const events = main.indexOf("includes/family/class-xophz-compass-xp-family-event-rules.php'");
    const schema = main.indexOf("includes/family/class-xophz-compass-xp-family-schema.php'");
    assert.ok(rules > 0 && events > 0 && schema > 0, 'all three require_once lines');
    assert.ok(rules < schema && events < schema, 'rules before schema');
    assert.ok(main.includes("$this->define_class_hooks( 'Xophz_Compass_Xp_Family_Schema' );"));
  });

  it('the plugin class lints', () => {
    const lint = lintPhpFile(MAIN_FILE);
    assert.strictEqual(lint.isValid, true, lint.output);
  });
});

describe('Family schema: the file', () => {
  it('lints', () => {
    const lint = lintPhpFile(CLASS_FILE);
    assert.strictEqual(lint.isValid, true, lint.output);
  });

  it('files carry no em dashes', () => {
    for (const rel of [CLASS_REL, 'family-schema.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
