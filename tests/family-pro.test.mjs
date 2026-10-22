/**
 * The Pro gate of family sync (MP3, family sync): Family_Pro::entitled asks the Gatekeeper
 * plugin's XP Pro licence store whether a family is entitled, lets the xp_family_pro_entitled
 * filter have the last word, and caches the answer for the request. A rehearsal override
 * (the option xp_family_pro_override) counts only while WP_DEBUG is on. The tests send bare PHP
 * scripts (no harness stubs) because they need to define apply_filters() and get_option()
 * themselves; Gatekeeper is a small stand-in that follows the real class's rule.
 */

import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLASS_REL = '../includes/family/class-xophz-compass-xp-family-pro.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);

const PLUGIN = '/var/www/html/wp-content/plugins/xophz-compass-xp';
export const PRO = `require_once '${PLUGIN}/includes/family/class-xophz-compass-xp-family-pro.php';\n`;
const CLS = 'Xophz_Compass_Xp_Family_Pro';

export const NOW = 1792000000;
const DAY = 86400;
const when = (unix) => new Date(unix * 1000).toISOString().slice(0, 19) + 'Z';

function lit(value) {
  return `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;
}

/**
 * A stand-in for Gatekeeper_Xp_Pro_Licenses with the records of the families given (family id to
 * record). get() and is_entitled() answer as the real class does (status active, and either no
 * end or now short of the end plus the 3 day grace); $gets counts the lookups.
 */
export function gatekeeper(records) {
  return `
    class Gatekeeper_Xp_Pro_Licenses {
      public static $records = [];
      public static $gets    = 0;
      public static function get( string $family_id ): ?array {
        self::$gets++;
        return self::$records[ $family_id ] ?? null;
      }
      public static function is_entitled( array $record, int $now ): bool {
        if ( ( $record['status'] ?? '' ) !== 'active' ) {
          return false;
        }
        $expires = $record['expires_at'] ?? null;
        if ( null === $expires ) {
          return true;
        }
        $ts = is_string( $expires ) ? strtotime( $expires ) : false;
        return false !== $ts && $now < $ts + 259200;
      }
    }
    Gatekeeper_Xp_Pro_Licenses::$records = ${lit(records)};
  `;
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

/**
 * The WordPress side of the class as small stubs: add_filter() keeps callbacks, apply_filters()
 * runs them in turn and get_option() answers from $GLOBALS['options'].
 */
const WORDPRESS = `
  $GLOBALS['filters'] = [];
  $GLOBALS['options'] = [];
  function add_filter( $tag, $cb ) { $GLOBALS['filters'][ $tag ][] = $cb; }
  function apply_filters( $tag, $value, ...$args ) {
    foreach ( $GLOBALS['filters'][ $tag ] ?? [] as $cb ) { $value = $cb( $value, ...$args ); }
    return $value;
  }
  function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
`;

/**
 * Run `body` against the class and the stubs. `records` is the Gatekeeper stand-in's table, or
 * null for a site without the Gatekeeper plugin; `debug` is WP_DEBUG (undefined when null).
 */
function run(body, { records = null, debug = null } = {}) {
  const wpDebug = debug === null ? '' : `define( 'WP_DEBUG', ${debug ? 'true' : 'false'} );`;
  return runBarePhpJson(`${WORDPRESS} ${wpDebug} ${records === null ? '' : gatekeeper(records)} ${PRO} ${body}`);
}

const ACTIVE = { status: 'active', expires_at: null, plan: 'lifetime' };
const ended = (secondsAgo, status = 'active') => ({ status, expires_at: when(NOW - secondsAgo), plan: 'monthly' });

describe('Family Pro: the Gatekeeper licence', () => {
  it('is false without Gatekeeper, true with an active record, false past grace', () => {
    const without = run(`echo json_encode( ${CLS}::entitled( 'fam-1', ${NOW} ) );`);
    assert.strictEqual(without, false, 'no Gatekeeper plugin means a free family');

    const records = {
      'fam-life': ACTIVE,
      'fam-paid': { status: 'active', expires_at: when(NOW + 20 * DAY), plan: 'monthly' },
      'fam-grace': ended(2 * DAY),
      'fam-lapsed': ended(4 * DAY),
      'fam-expired': ended(0, 'expired'),
      'fam-revoked': { status: 'revoked', expires_at: null, plan: 'yearly' },
      'fam-bad-date': { status: 'active', expires_at: 'not a date', plan: 'monthly' },
    };
    const res = run(`
      $out = [];
      foreach ( [ 'fam-life', 'fam-paid', 'fam-grace', 'fam-lapsed', 'fam-expired', 'fam-revoked', 'fam-bad-date', 'fam-none' ] as $id ) {
        ${CLS}::reset();
        $out[ $id ] = ${CLS}::entitled( $id, ${NOW} );
      }
      echo json_encode( $out );`, { records });
    assert.deepStrictEqual(res, {
      'fam-life': true,
      'fam-paid': true,
      'fam-grace': true,
      'fam-lapsed': false,
      'fam-expired': false,
      'fam-revoked': false,
      'fam-bad-date': false,
      'fam-none': false,
    });
  });

  it('judges the record at the time it is given', () => {
    const records = { 'fam-1': { status: 'active', expires_at: when(NOW + DAY), plan: 'monthly' } };
    const res = run(`
      $out = [];
      foreach ( [ ${NOW}, ${NOW + 3 * DAY}, ${NOW + 4 * DAY - 1}, ${NOW + 4 * DAY} ] as $at ) {
        ${CLS}::reset();
        $out[] = ${CLS}::entitled( 'fam-1', $at );
      }
      echo json_encode( $out );`, { records });
    assert.deepStrictEqual(res, [true, true, true, false], 'the end plus the 3 day grace is the edge');
  });

  it('answers a real bool', () => {
    const res = run(`
      $a = ${CLS}::entitled( 'fam-1', ${NOW} );
      ${CLS}::reset();
      $b = ${CLS}::entitled( 'fam-2', ${NOW} );
      echo json_encode( [ is_bool( $a ), is_bool( $b ) ] );`, { records: { 'fam-1': ACTIVE } });
    assert.deepStrictEqual(res, [true, true]);
  });
});

describe('Family Pro: the filter', () => {
  it('the filter can force it on and off', () => {
    const res = run(`
      $out = [];
      add_filter( 'xp_family_pro_entitled', function ( $default, $family_id ) { return 'fam-on' === $family_id; } );
      foreach ( [ 'fam-on', 'fam-life', 'fam-none' ] as $id ) {
        ${CLS}::reset();
        $out[ $id ] = ${CLS}::entitled( $id, ${NOW} );
      }
      echo json_encode( $out );`, { records: { 'fam-life': ACTIVE } });
    // fam-on has no record and is forced on; fam-life has an active record and is forced off.
    assert.deepStrictEqual(res, { 'fam-on': true, 'fam-life': false, 'fam-none': false });
  });

  it('hands the filter the Gatekeeper answer and the family id, and casts what comes back to a bool', () => {
    const res = run(`
      $seen = [];
      add_filter( 'xp_family_pro_entitled', function ( $default, $family_id ) use ( &$seen ) {
        $seen[] = [ $default, $family_id ];
        return 'fam-life' === $family_id ? 1 : '';
      } );
      $out = [];
      foreach ( [ 'fam-life', 'fam-none' ] as $id ) {
        ${CLS}::reset();
        $out[] = ${CLS}::entitled( $id, ${NOW} );
      }
      echo json_encode( [ $seen, $out ] );`, { records: { 'fam-life': ACTIVE } });
    assert.deepStrictEqual(res, [[[true, 'fam-life'], [false, 'fam-none']], [true, false]]);
  });

  it('asks Gatekeeper and the filter once per family for the request, and reset() starts over', () => {
    const res = run(`
      $calls = 0;
      add_filter( 'xp_family_pro_entitled', function ( $default ) use ( &$calls ) { $calls++; return $default; } );
      $first  = [ ${CLS}::entitled( 'fam-life', ${NOW} ), ${CLS}::entitled( 'fam-life', ${NOW} ), ${CLS}::entitled( 'fam-life', ${NOW + 99} ) ];
      $other  = ${CLS}::entitled( 'fam-none', ${NOW} );
      $again  = ${CLS}::entitled( 'fam-none', ${NOW} );
      $before = [ Gatekeeper_Xp_Pro_Licenses::$gets, $calls ];
      ${CLS}::reset();
      ${CLS}::entitled( 'fam-life', ${NOW} );
      echo json_encode( [ $first, $other, $again, $before, [ Gatekeeper_Xp_Pro_Licenses::$gets, $calls ] ] );`, { records: { 'fam-life': ACTIVE } });
    assert.deepStrictEqual(res, [[true, true, true], false, false, [2, 2], [3, 3]]);
  });

  it('caches a no as well as a yes', () => {
    // A licence that appears in the table after the first answer is not seen until the next request.
    const res = run(`
      $first = ${CLS}::entitled( 'fam-1', ${NOW} );
      Gatekeeper_Xp_Pro_Licenses::$records['fam-1'] = ${lit(ACTIVE)};
      $second = ${CLS}::entitled( 'fam-1', ${NOW} );
      ${CLS}::reset();
      $third = ${CLS}::entitled( 'fam-1', ${NOW} );
      echo json_encode( [ $first, $second, $third ] );`, { records: {} });
    assert.deepStrictEqual(res, [false, false, true]);
  });
});

describe('Family Pro: the rehearsal override', () => {
  const entitledWith = (options, debug) => run(`
    $GLOBALS['options'] = ${lit(options)};
    echo json_encode( ${CLS}::entitled( 'fam-1', ${NOW} ) );`, { debug });

  it('turns the gate on under WP_DEBUG when xp_family_pro_override is set, with no Gatekeeper at all', () => {
    assert.strictEqual(entitledWith({ xp_family_pro_override: '1' }, true), true);
    assert.strictEqual(entitledWith({ xp_family_pro_override: true }, true), true);
  });

  it('does nothing under WP_DEBUG when the option is unset or falsy', () => {
    assert.strictEqual(entitledWith({}, true), false);
    assert.strictEqual(entitledWith({ xp_family_pro_override: '' }, true), false);
    assert.strictEqual(entitledWith({ xp_family_pro_override: '0' }, true), false);
  });

  it('is ignored when WP_DEBUG is false, or not defined: a production site never reads it', () => {
    assert.strictEqual(entitledWith({ xp_family_pro_override: '1' }, false), false);
    assert.strictEqual(entitledWith({ xp_family_pro_override: '1' }, null), false);
  });

  it('is only the default: the filter still has the last word', () => {
    const res = run(`
      $GLOBALS['options'] = [ 'xp_family_pro_override' => '1' ];
      add_filter( 'xp_family_pro_entitled', function ( $default ) { return ! $default; } );
      echo json_encode( ${CLS}::entitled( 'fam-1', ${NOW} ) );`, { debug: true });
    assert.strictEqual(res, false);
  });
});

describe('Family Pro: the file', () => {
  it('lints', () => {
    const lint = lintPhpFile(CLASS_FILE);
    assert.strictEqual(lint.isValid, true, lint.output);
  });

  it('is loaded by the plugin after the record rules and before the store and the events', () => {
    const loader = fs.readFileSync(path.join(HERE, '../includes/class-xophz-compass-xp.php'), 'utf8');
    const order = ['family-record-rules', 'family-pro', 'family-store', 'family-events'].map((name) => loader.indexOf(`includes/family/class-xophz-compass-xp-${name}.php`));
    assert.ok(order.every((at) => at >= 0), `each file is required: ${order}`);
    assert.deepStrictEqual([...order].sort((a, b) => a - b), order);
  });

  it('files carry no em dashes', () => {
    for (const rel of [CLASS_REL, '../includes/family/class-xophz-compass-xp-family-events.php', 'family-pro.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
