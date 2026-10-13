/**
 * Family rules (MP1): link codes, hero keys, nicknames, ids, binding answers and who may do what.
 * The class is pure PHP: the tests load it alone, with no WordPress.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLASS_REL = '../includes/family/class-xophz-compass-xp-family-rules.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);

const F1 = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const F2 = '9d1b7c40-52aa-4e1f-8c3d-0b6a7e8f9a10';
const D1 = 'devicetest000001';
const D2 = 'devicetest000002';

const RULES = "require_once '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/family/class-xophz-compass-xp-family-rules.php';\n";
const CLS = 'Xophz_Compass_Xp_Family_Rules';

function lit(value) {
  return `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;
}

/** Call one static method once per row of arguments, in a single PHP run. */
function table(method, rows) {
  return runPhpJson(`${RULES}
    $rows = ${lit(rows)};
    $out = [];
    foreach ( $rows as $args ) { $out[] = ${CLS}::${method}( ...$args ); }
    echo json_encode( $out );`);
}

/** Assert that each [...args, expected] row answers expected. */
function expectTable(method, rows) {
  const results = table(method, rows.map((r) => r.slice(0, -1)));
  rows.forEach((row, i) => {
    assert.deepStrictEqual(results[i], row[row.length - 1], `${method}(${JSON.stringify(row.slice(0, -1))})`);
  });
}

describe('Family rules: constants', () => {
  it('holds the exact values of the spec', () => {
    const res = runPhpJson(`${RULES}
      echo json_encode( [
        ${CLS}::CODE_ALPHABET, ${CLS}::CODE_LENGTH, ${CLS}::CODE_TTL, ${CLS}::KEY_PREFIX,
        ${CLS}::AGE_GROUPS, ${CLS}::NICKNAME_MAX, ${CLS}::LABEL_MAX, ${CLS}::CLAIM_FAILS_PER_HOUR,
      ] );`);
    assert.deepStrictEqual(res, [
      'ABCDEFGHJKMNPQRSTUVWXYZ23456789', 8, 600, 'xph_',
      ['child', 'teen', 'adult'], 40, 60, 10,
    ]);
  });
});

describe('Family rules: link codes', () => {
  it('normalize_code accepts typed codes and refuses the rest', () => {
    expectTable('normalize_code', [
      ['abcd-efgh', 'ABCDEFGH'],
      [' ab cd ef gh ', 'ABCDEFGH'],
      ['ABCD-EFGH', 'ABCDEFGH'],
      ['abcd efgh', 'ABCDEFGH'],
      ['abcd–efgh', 'ABCDEFGH'],
      ['abcd efgh', 'ABCDEFGH'],
      ['ABCDEFG', null],
      ['ABCDEFG0', null],
      ['ABCDEFGHJ', null],
      ['ABCD-EFGI', null],
      ['ABCD-EFGL', null],
      ['ABCDEFGO', null],
      ['ABCDEFG1', null],
      ['', null],
      ['----', null],
      ['ABCDEFGH\n', 'ABCDEFGH'],
    ]);
  });

  it('new_code with a random source that always answers 0 is AAAAAAAA, asking for 0..30 eight times', () => {
    const res = runPhpJson(`${RULES}
      $calls = [];
      $zero = ${CLS}::new_code( function ( $min, $max ) use ( &$calls ) { $calls[] = [ $min, $max ]; return 0; } );
      $top  = ${CLS}::new_code( function ( $min, $max ) { return 30; } );
      echo json_encode( [ $zero, $top, $calls ] );`);
    assert.strictEqual(res[0], 'AAAAAAAA');
    assert.strictEqual(res[1], '99999999');
    assert.strictEqual(res[2].length, 8);
    assert.ok(res[2].every((c) => c[0] === 0 && c[1] === 30));
  });

  it('50 real codes all use the 31 character alphabet and are not all the same', () => {
    const res = runPhpJson(`${RULES}
      $codes = [];
      for ( $i = 0; $i < 50; $i++ ) { $codes[] = ${CLS}::new_code(); }
      echo json_encode( $codes );`);
    assert.strictEqual(res.length, 50);
    for (const code of res) {
      assert.ok(/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/.test(code), `bad code ${code}`);
    }
    assert.ok(new Set(res).size > 40, 'codes should differ');
  });

  it('a new code survives normalize_code unchanged', () => {
    const res = runPhpJson(`${RULES}
      $c = ${CLS}::new_code();
      echo json_encode( [ $c, ${CLS}::normalize_code( $c ), ${CLS}::normalize_code( substr( $c, 0, 4 ) . '-' . substr( $c, 4 ) ) ] );`);
    assert.strictEqual(res[1], res[0]);
    assert.strictEqual(res[2], res[0]);
  });
});

describe('Family rules: hashes and hero keys', () => {
  it('hash_secret is SHA-256 hex', () => {
    expectTable('hash_secret', [
      ['abc', 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'],
      ['', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
    ]);
  });

  it('new_key is xph_ plus 43 base64url characters, 47 in all', () => {
    const res = runPhpJson(`${RULES}
      $k = ${CLS}::new_key();
      echo json_encode( [ $k, ${CLS}::is_key_shaped( $k ), ${CLS}::new_key() !== $k ] );`);
    assert.ok(/^xph_[A-Za-z0-9_-]{43}$/.test(res[0]), `bad key ${res[0]}`);
    assert.strictEqual(res[0].length, 47);
    assert.strictEqual(res[1], true);
    assert.strictEqual(res[2], true);
  });

  it('new_key asks for 32 bytes and writes base64url without padding', () => {
    const res = runPhpJson(`${RULES}
      $asked = [];
      $zero = ${CLS}::new_key( function ( $n ) use ( &$asked ) { $asked[] = $n; return str_repeat( chr( 0 ), $n ); } );
      $full = ${CLS}::new_key( function ( $n ) { return str_repeat( chr( 255 ), $n ); } );
      echo json_encode( [ $zero, $full, $asked ] );`);
    assert.strictEqual(res[0], 'xph_' + 'A'.repeat(43));
    assert.strictEqual(res[1], 'xph_' + '_'.repeat(42) + '8');
    assert.deepStrictEqual(res[2], [32]);
  });

  it('is_key_shaped refuses anything else', () => {
    expectTable('is_key_shaped', [
      ['xph_' + 'A'.repeat(43), true],
      ['xph_' + 'a-_Z9'.repeat(8) + 'abc', true],
      ['xph_short', false],
      ['XP-PRO-AAAA-BBBB-CCCC', false],
      ['', false],
      ['xph_' + 'A'.repeat(42), false],
      ['xph_' + 'A'.repeat(44), false],
      ['xph_' + 'A'.repeat(42) + '+', false],
      ['XPH_' + 'A'.repeat(43), false],
      ['xph_' + 'A'.repeat(43) + '\n', false],
      [' xph_' + 'A'.repeat(43), false],
    ]);
  });
});

describe('Family rules: nicknames', () => {
  it('clean_nickname trims, strips tags, collapses spaces and counts characters', () => {
    expectTable('clean_nickname', [
      ['  Mia  ', 'Mia'],
      ['<b>Mia</b>', 'Mia'],
      ['A    B', 'A B'],
      ['A\t\nB', 'A B'],
      ['Mi\u0000a', 'Mia'],
      ['', null],
      ['   ', null],
      ['<b></b>', null],
      ['\u{1F600}'.repeat(40), '\u{1F600}'.repeat(40)],
      ['\u{1F600}'.repeat(41), null],
      ['a'.repeat(40), 'a'.repeat(40)],
      ['a'.repeat(41), null],
      ['é'.repeat(40), 'é'.repeat(40)],
      [5, null],
      [null, null],
      [['Mia'], null],
    ]);
  });

  it('clean_nickname keeps a joined emoji whole and refuses bytes that are not UTF-8', () => {
    const res = runPhpJson(`${RULES}
      $family = "\\u{1F469}\\u{200D}\\u{1F467}";
      echo json_encode( [
        ${CLS}::clean_nickname( $family ) === $family,
        ${CLS}::clean_nickname( "bad\\xC3\\x28" ),
      ] );`);
    assert.deepStrictEqual(res, [true, null]);
  });

  it('device_label trims to 60 characters and falls back to A hero phone', () => {
    expectTable('device_label', [
      ['', 'A hero phone'],
      ['   ', 'A hero phone'],
      [null, 'A hero phone'],
      [12, 'A hero phone'],
      ['a'.repeat(70), 'a'.repeat(60)],
      ['\u{1F600}'.repeat(70), '\u{1F600}'.repeat(60)],
      ["Mia's iPhone", "Mia's iPhone"],
      ['  <i>Mia</i>   phone ', 'Mia phone'],
      ['<b></b>', 'A hero phone'],
    ]);
  });
});

describe('Family rules: ids', () => {
  it('is_age_group knows child, teen and adult', () => {
    expectTable('is_age_group', [
      ['child', true], ['teen', true], ['adult', true],
      ['Child', false], ['', false], ['senior', false], [null, false], [13, false],
    ]);
  });

  it('is_family_id is a lowercase v4 UUID', () => {
    expectTable('is_family_id', [
      [F1, true],
      [F2, true],
      [F1.toUpperCase(), false],
      ['3f6c2a1e-8b4d-1c7a-9e2f-1a2b3c4d5e6f', false],
      ['3f6c2a1e-8b4d-4c7a-7e2f-1a2b3c4d5e6f', false],
      ['3f6c2a1e8b4d4c7a9e2f1a2b3c4d5e6f', false],
      ['', false],
      [F1 + '\n', false],
      [F1 + 'a', false],
    ]);
  });

  it('is_device_id is 16 letters and digits', () => {
    expectTable('is_device_id', [
      [D1, true],
      ['ABCDEFGHIJKLMNOP', true],
      ['abcdefghij12345', false],
      ['abcdefghij1234567', false],
      ['abcdefghij-12345', false],
      ['abcdefghij_12345', false],
      ['', false],
      [D1 + '\n', false],
    ]);
  });

  it('is_hero_id is 1 to 64 letters, digits, dash and underscore', () => {
    expectTable('is_hero_id', [
      ['h-1', true],
      ['a', true],
      ['Hero_01-b', true],
      ['a'.repeat(64), true],
      ['a'.repeat(65), false],
      ['', false],
      ['has space', false],
      ['a/b', false],
      ['h-1\n', false],
      ['héro', false],
    ]);
  });
});

describe('Family rules: binding answers', () => {
  const mine = (home) => ({ family_id: F1, owner_user_id: 7, home_device_id: home });

  it('bind_answer covers created, home, home-elsewhere, other-family and taken', () => {
    expectTable('bind_answer', [
      [null, null, 7, F1, D1, 'created'],
      [mine(D1), mine(D1), 7, F1, D1, 'home'],
      [mine(D1), mine(D1), 7, F1, D2, 'home-elsewhere'],
      [mine(D1), null, 7, F2, D2, 'other-family'],
      [null, { family_id: F1, owner_user_id: 8, home_device_id: D1 }, 7, F1, D2, 'taken'],
    ]);
  });

  it('a family row owned by this user counts as theirs even when only the id lookup found it', () => {
    expectTable('bind_answer', [
      [null, mine(D1), 7, F1, D1, 'home'],
      [null, mine(D1), 7, F1, D2, 'home-elsewhere'],
    ]);
  });

  it('a family with no home device recorded answers home-elsewhere', () => {
    expectTable('bind_answer', [
      [mine(null), mine(null), 7, F1, D1, 'home-elsewhere'],
      [mine(''), mine(''), 7, F1, D1, 'home-elsewhere'],
    ]);
  });

  it('an account with another family answers other-family even when the id belongs to someone else', () => {
    expectTable('bind_answer', [
      [mine(D1), { family_id: F2, owner_user_id: 8, home_device_id: D2 }, 7, F2, D2, 'other-family'],
    ]);
  });
});

describe('Family rules: sites and callers', () => {
  it('is_family_site compares without trailing slashes', () => {
    expectTable('is_family_site', [
      ['https://doit.forthexp.com/', ['https://doit.forthexp.com'], true],
      ['https://doit.forthexp.com', ['https://doit.forthexp.com/'], true],
      ['https://doit.forthexp.com///', ['https://doit.forthexp.com'], true],
      ['https://www.forthexp.com', ['https://doit.forthexp.com'], false],
      ['https://doit.forthexp.com/sub', ['https://doit.forthexp.com'], false],
      ['https://doit.forthexp.com', [], false],
      ['', [''], false],
      ['https://doit.forthexp.com', [null, 5, 'https://doit.forthexp.com'], true],
      ['https://rehearsal.test', ['https://doit.forthexp.com', 'https://rehearsal.test/'], true],
    ]);
  });

  const ACTIONS = [
    'bind', 'move', 'read_family', 'save_hero', 'remove_hero', 'make_code', 'claim',
    'list_devices', 'unlink_device', 'send_events', 'read_events',
  ];
  const HERO_MAY = ['read_family', 'unlink_device', 'send_events', 'read_events'];

  it('a parent may every action', () => {
    const res = table('may', ACTIONS.map((a) => ['parent', a]));
    assert.deepStrictEqual(res, ACTIONS.map(() => true));
  });

  it('a hero key may read the family, unlink, send and read events, and nothing else', () => {
    const res = table('may', ACTIONS.map((a) => ['hero', a]));
    assert.deepStrictEqual(res, ACTIONS.map((a) => HERO_MAY.includes(a)));
  });

  it('nobody may only claim', () => {
    const res = table('may', ACTIONS.map((a) => ['none', a]));
    assert.deepStrictEqual(res, ACTIONS.map((a) => a === 'claim'));
  });

  it('an unknown caller or action is refused', () => {
    const res = table('may', [['admin', 'bind'], ['parent', 'delete_everything'], ['', ''], ['hero', 'reward_self']]);
    assert.deepStrictEqual(res, [false, false, false, false]);
  });
});

describe('Family rules: the file', () => {
  it('lints', () => {
    const lint = lintPhpFile(CLASS_FILE);
    assert.strictEqual(lint.isValid, true, lint.output);
  });

  it('calls no WordPress function', () => {
    const src = fs.readFileSync(CLASS_FILE, 'utf8');
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*(\/\/|#).*$/gm, '');
    const wp = code.match(/\b(wp_[a-z_]+|esc_[a-z_]+|sanitize_[a-z_]+|get_option|update_option|add_option|apply_filters|do_action|add_action|add_filter|home_url|site_url|get_current_user_id|is_wp_error|__|_e)\s*\(/g);
    assert.strictEqual(wp, null, `WordPress calls found: ${JSON.stringify(wp)}`);
    assert.ok(!/\$wpdb/.test(code), 'no $wpdb in the rule class');
  });

  it('files carry no em dashes', () => {
    for (const rel of [CLASS_REL, 'family-rules.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
