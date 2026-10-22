/**
 * Family records (MP3, family sync): the record rules (the 17 stores and their scopes, the hero id
 * of a record, the size caps, who may write and read). The rules class is pure PHP, loaded alone,
 * with no WordPress.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLASS_REL = '../includes/family/class-xophz-compass-xp-family-record-rules.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);

const RULES = "require_once '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/family/class-xophz-compass-xp-family-record-rules.php';\n";
const CLS = 'Xophz_Compass_Xp_Family_Record_Rules';

const INVALID = 'xp_family_record_invalid';
const SCOPE = 'xp_family_scope';
const UNKNOWN = 'xp_family_store_unknown';

const FAMILY_STORES = [
  '__achievementCategories', '__beasts', '__achievements', '__abilities', '__accessories',
  '__shops', '__temples', '__dosDonts', '__family_settings', '__packs',
];

function lit(value) {
  return `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;
}

/** Run check once per [record, ctx] row, in one PHP run. */
function checks(rows) {
  return runPhpJson(`${RULES}
    $out = [];
    foreach ( ${lit(rows)} as $row ) { $out[] = ${CLS}::check( $row[0], $row[1] ); }
    echo json_encode( $out );`);
}

/** The result of one check. */
function checkOne(record, ctx = PARENT) {
  return checks([[record, ctx]])[0];
}

/** Run hero_id_of once per [store, key, body] row. */
function heroIds(rows) {
  return runPhpJson(`${RULES}
    $out = [];
    foreach ( ${lit(rows)} as $row ) { $out[] = ${CLS}::hero_id_of( $row[0], $row[1], $row[2] ); }
    echo json_encode( $out );`);
}

/** Run may_read once per [row, ctx] row. */
function reads(rows) {
  return runPhpJson(`${RULES}
    $out = [];
    foreach ( ${lit(rows)} as $row ) { $out[] = ${CLS}::may_read( $row[0], $row[1] ); }
    echo json_encode( $out );`);
}

const PARENT = { writer: 'parent', key_hero_id: null };
const heroKey = (id = 'h-1') => ({ writer: 'hero', key_hero_id: id });

/** A record as the app sends it: the body is the record's JSON text. */
const rec = (store, key, body, over = {}) => ({
  store, key, hero_id: null, deleted: false, body: typeof body === 'string' ? body : JSON.stringify(body), ...over,
});

/** A tombstone: no body. */
const tomb = (store, key, over = {}) => ({ store, key, hero_id: null, deleted: true, ...over });

/** One live record per store, each belonging to hero h-1 where the store is a hero's. */
const SAMPLES = {
  __achievementCategories: ['cat-1', { name: 'Kitchen' }],
  __beasts: ['b-1', { name: 'Socktopus' }],
  __achievements: ['q-1', { name: 'Dishes' }],
  __abilities: ['a-1', { name: 'Fireball' }],
  __accessories: ['acc-1', { name: 'Cape' }],
  __shops: ['s-1', { name: 'Smithy' }],
  __temples: ['t-1', { name: 'Old House' }],
  __dosDonts: ['dd-1', { text: 'Shoes off' }],
  __family_settings: ['settings', { chat: true }],
  __packs: ['p-1', { name: 'Moving House' }],
  __chat: ['m-1', { text: 'hello' }],
  __profiles: ['h-1', { id: 'h-1', name: 'Mia' }],
  __questLog: ['ql-1', { userId: 'h-1', questId: 'q-1' }],
  __purchaseLog: ['pl-1', { userId: 'h-1', item: 'potion' }],
  __castLog: ['cl-1', { userId: 'h-1', power: 'heal' }],
  __latePenalties: ['lp-1', { heroId: 'h-1', gp: 5 }],
  __templeProgress: ['h-1:temple-x', { room: 3 }],
  __notifications: ['h-1', { items: [] }],
};
const sample = (store, over = {}) => rec(store, SAMPLES[store][0], SAMPLES[store][1], over);

/** A JSON object that is exactly `total` bytes. */
const jsonOfBytes = (total) => JSON.stringify({ pad: 'a'.repeat(total - JSON.stringify({ pad: '' }).length) });

describe('Record rules', () => {
  it('holds the exact limits', () => {
    const res = runPhpJson(`${RULES}
      echo json_encode( [ ${CLS}::MAX_BODY_BYTES, ${CLS}::MAX_BATCH, ${CLS}::MAX_BATCH_BYTES, ${CLS}::MAX_KEY ] );`);
    assert.deepStrictEqual(res, [524288, 50, 1048576, 191]);
  });

  it('knows the 18 stores and their scopes', () => {
    const res = runPhpJson(`${RULES} echo json_encode( ${CLS}::STORES );`);
    const expected = {};
    for (const store of FAMILY_STORES) expected[store] = { scope: 'family', hero_from: null };
    expected.__chat = { scope: 'family-any', hero_from: null };
    expected.__profiles = { scope: 'hero', hero_from: 'key' };
    expected.__questLog = { scope: 'hero', hero_from: 'userId' };
    expected.__purchaseLog = { scope: 'hero', hero_from: 'userId' };
    expected.__castLog = { scope: 'hero', hero_from: 'userId' };
    expected.__latePenalties = { scope: 'hero', hero_from: 'heroId' };
    expected.__templeProgress = { scope: 'hero', hero_from: 'key_prefix' };
    expected.__notifications = { scope: 'hero', hero_from: 'key' };
    // The brief says 17; its own list and the spec's table (BACKUP_STORES less __localSettings) hold 18.
    assert.strictEqual(Object.keys(res).length, 18);
    assert.deepStrictEqual(res, expected);
    assert.ok(!('__localSettings' in res), 'device settings stay on the device');
  });

  it('derives the hero id per store', () => {
    const res = heroIds([
      ['__profiles', 'h-1', null],
      ['__profiles', 'h-1', JSON.stringify({ id: 'other' })],
      ['__templeProgress', 'h-1:temple-x', null],
      ['__templeProgress', 'h-1:temple:with:colons', null],
      ['__questLog', 'ql-1', JSON.stringify({ userId: 'h-2' })],
      ['__purchaseLog', 'pl-1', JSON.stringify({ userId: 'h-2' })],
      ['__castLog', 'cl-1', JSON.stringify({ userId: 'h-2' })],
      ['__latePenalties', 'lp-1', JSON.stringify({ heroId: 'h-3' })],
      ['__notifications', 'h-4', null],
      ['__achievements', 'q-1', JSON.stringify({ userId: 'h-1' })],
      ['__chat', 'h-1', null],
      ['__shops', 'h-1', null],
    ]);
    assert.deepStrictEqual(res, ['h-1', 'h-1', 'h-1', 'h-1', 'h-2', 'h-2', 'h-2', 'h-3', 'h-4', null, null, null]);
  });

  it('hero_id_of reads a decoded body too, and answers null when the hero cannot be told', () => {
    const res = heroIds([
      ['__questLog', 'ql-1', { userId: 'h-2' }],
      ['__latePenalties', 'lp-1', { heroId: 'h-3' }],
      ['__latePenalties', 'lp-1', { userId: 'h-3' }],
      ['__questLog', 'ql-1', { heroId: 'h-3' }],
      ['__questLog', 'ql-1', { userId: 5 }],
      ['__questLog', 'ql-1', { userId: '' }],
      ['__questLog', 'ql-1', { userId: 'x'.repeat(65) }],
      ['__questLog', 'ql-1', { userId: 'a b' }],
      ['__questLog', 'ql-1', '{}'],
      ['__questLog', 'ql-1', 'not json'],
      ['__questLog', 'ql-1', '[1,2]'],
      ['__questLog', 'ql-1', null],
      ['__profiles', 'a b', null],
      ['__profiles', 'x'.repeat(65), null],
      ['__profiles', 'x'.repeat(64), null],
      ['__templeProgress', 'no-colon', null],
      ['__templeProgress', ':temple-x', null],
      ['__templeProgress', 'h 1:temple-x', null],
      ['__nope', 'h-1', null],
    ]);
    assert.deepStrictEqual(res, ['h-2', 'h-3', null, null, null, null, null, null, null, null, null, null, null, null, 'x'.repeat(64), null, null, null, null]);
  });

  it('refuses an unknown store, a key over 191 chars, a body over 512 KB, a body that is not an object', () => {
    const res = checks([
      [rec('__nope', 'k', {}), PARENT],
      [rec('__localSettings', 'k', {}), PARENT],
      [rec('', 'k', {}), PARENT],
      [{ key: 'k', deleted: false, body: '{}' }, PARENT],
      [rec(5, 'k', {}), PARENT],
      [rec('__shops', 'k'.repeat(191), {}), PARENT],
      [rec('__shops', 'k'.repeat(192), {}), PARENT],
      [rec('__shops', '\u{1F600}'.repeat(191), {}), PARENT],
      [rec('__shops', '\u{1F600}'.repeat(192), {}), PARENT],
      [rec('__shops', '', {}), PARENT],
      [rec('__shops', 5, {}), PARENT],
      [{ store: '__shops', deleted: false, body: '{}' }, PARENT],
      [rec('__shops', 'k', jsonOfBytes(524288)), PARENT],
      [rec('__shops', 'k', jsonOfBytes(524289)), PARENT],
      [rec('__shops', 'k', '[]'), PARENT],
      [rec('__shops', 'k', '[1,2]'), PARENT],
      [rec('__shops', 'k', '"text"'), PARENT],
      [rec('__shops', 'k', '12'), PARENT],
      [rec('__shops', 'k', 'true'), PARENT],
      [rec('__shops', 'k', 'null'), PARENT],
      [rec('__shops', 'k', 'not json'), PARENT],
      [rec('__shops', 'k', '{"a":'), PARENT],
      [rec('__shops', 'k', ''), PARENT],
      [rec('__shops', 'k', '  '), PARENT],
      [{ store: '__shops', key: 'k', deleted: false }, PARENT],
      [{ store: '__shops', key: 'k', deleted: false, body: null }, PARENT],
      [{ store: '__shops', key: 'k', deleted: false, body: { name: 'decoded object, not text' } }, PARENT],
    ]);
    const codes = res.map((r) => (typeof r === 'string' ? r : 'ok'));
    assert.deepStrictEqual(codes, [
      UNKNOWN, UNKNOWN, UNKNOWN, UNKNOWN, UNKNOWN,
      'ok', INVALID, 'ok', INVALID, INVALID, INVALID, INVALID,
      'ok', INVALID,
      INVALID, INVALID, INVALID, INVALID, INVALID, INVALID, INVALID, INVALID, INVALID, INVALID,
      INVALID, INVALID, INVALID,
    ]);
  });

  it('refuses a record that is not an object, and a deleted flag that is not a boolean', () => {
    const res = checks([
      ['text', PARENT],
      [42, PARENT],
      [null, PARENT],
      [[], PARENT],
      [{ ...rec('__shops', 'k', {}), deleted: 'yes' }, PARENT],
      [{ ...rec('__shops', 'k', {}), deleted: 1 }, PARENT],
      [{ ...rec('__shops', 'k', {}), deleted: 0 }, PARENT],
    ]);
    assert.deepStrictEqual(res, [INVALID, INVALID, INVALID, UNKNOWN, INVALID, INVALID, INVALID]);
  });

  it('answers the clean record: the five fields, the body text kept as sent, nothing else', () => {
    const body = '{ "name" : "Café \u{1F600}", "n": 1.50 }';
    const res = checkOne({ ...rec('__shops', 'k-1', body), extra: 'dropped', device_id: 'sneaky' });
    assert.deepStrictEqual(Object.keys(res), ['store', 'key', 'hero_id', 'deleted', 'body']);
    assert.deepStrictEqual(res, { store: '__shops', key: 'k-1', hero_id: null, deleted: false, body });
  });

  it('a missing deleted flag means a live record, and a family record has no hero whatever the wire says', () => {
    const noFlag = { store: '__shops', key: 'k', hero_id: 'h-1', body: '{}' };
    const res = checks([[noFlag, PARENT], [{ ...noFlag, hero_id: 5 }, PARENT], [{ store: '__shops', key: 'k', body: '{"a":1}' }, PARENT]]);
    assert.deepStrictEqual(res, [
      { store: '__shops', key: 'k', hero_id: null, deleted: false, body: '{}' },
      { store: '__shops', key: 'k', hero_id: null, deleted: false, body: '{}' },
      { store: '__shops', key: 'k', hero_id: null, deleted: false, body: '{"a":1}' },
    ]);
  });

  it('measures the cap on the bytes of the body text, not on its characters', () => {
    // Each euro sign is 3 bytes and 1 character; {"p":""} is 8 bytes.
    const euros = (n) => JSON.stringify({ p: '\u20ac'.repeat(n) });
    const atCap = euros(174760);
    assert.strictEqual(Buffer.byteLength(atCap, 'utf8'), 524288);
    assert.ok(atCap.length < 524288 / 2, 'far fewer characters than bytes');
    const res = checks([[rec('__shops', 'k', euros(174762)), PARENT], [rec('__shops', 'k', euros(174761)), PARENT], [rec('__shops', 'k', atCap), PARENT]]);
    assert.deepStrictEqual(res.map((r) => (typeof r === 'string' ? r : 'ok')), [INVALID, INVALID, 'ok']);
  });

  it('a hero key may write only __chat and its own hero\'s records', () => {
    const own = heroKey('h-1');
    const res = checks([
      [sample('__profiles'), own],
      [sample('__questLog'), own],
      [sample('__purchaseLog'), own],
      [sample('__castLog'), own],
      [sample('__latePenalties'), own],
      [sample('__templeProgress'), own],
      [sample('__notifications'), own],
      [sample('__chat'), own],
      [rec('__profiles', 'h-2', { id: 'h-2' }, { hero_id: 'h-2' }), own],
      [rec('__questLog', 'ql-9', { userId: 'h-2' }), own],
      [rec('__latePenalties', 'lp-9', { heroId: 'h-2' }), own],
      [rec('__templeProgress', 'h-2:temple-x', {}), own],
      [rec('__notifications', 'h-2', {}), own],
      [sample('__achievements'), own],
      [sample('__beasts'), own],
      [sample('__family_settings'), own],
      [sample('__packs'), own],
    ]);
    const codes = res.map((r) => (typeof r === 'string' ? r : 'ok'));
    assert.deepStrictEqual(codes, [
      'ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'ok',
      SCOPE, SCOPE, SCOPE, SCOPE, SCOPE,
      SCOPE, SCOPE, SCOPE, SCOPE,
    ]);
    assert.strictEqual(res[7].hero_id, null);
    assert.strictEqual(res[0].hero_id, 'h-1');
  });

  it('a hero key with no hero, or an unknown kind of caller, writes nothing it does not own', () => {
    const res = checks([
      [sample('__profiles'), { writer: 'hero', key_hero_id: null }],
      [sample('__chat'), { writer: 'hero', key_hero_id: null }],
      [sample('__shops'), { writer: 'none', key_hero_id: null }],
      [sample('__chat'), { writer: 'none', key_hero_id: null }],
      [sample('__chat'), {}],
      [sample('__chat'), { writer: 'server' }],
    ]);
    assert.deepStrictEqual(res.map((r) => (typeof r === 'string' ? r : 'ok')), [SCOPE, 'ok', SCOPE, SCOPE, SCOPE, SCOPE]);
  });

  it('a parent may write every store', () => {
    const stores = Object.keys(SAMPLES);
    const res = checks(stores.map((s) => [sample(s), PARENT]));
    assert.strictEqual(stores.length, 18);
    res.forEach((r, i) => {
      assert.strictEqual(typeof r, 'object', `${stores[i]}: ${JSON.stringify(r)}`);
      assert.strictEqual(r.store, stores[i]);
      assert.strictEqual(r.body, JSON.stringify(SAMPLES[stores[i]][1]));
    });
    const hero = Object.fromEntries(stores.map((s, i) => [s, res[i].hero_id]));
    for (const s of [...FAMILY_STORES, '__chat']) assert.strictEqual(hero[s], null, s);
    for (const s of ['__profiles', '__questLog', '__purchaseLog', '__castLog', '__latePenalties', '__templeProgress', '__notifications']) {
      assert.strictEqual(hero[s], 'h-1', s);
    }
  });

  it('a parent may write any hero\'s records, with or without the hero id on the wire', () => {
    const res = checks([
      [rec('__questLog', 'ql-9', { userId: 'h-7' }), PARENT],
      [rec('__questLog', 'ql-9', { userId: 'h-7' }, { hero_id: 'h-7' }), PARENT],
      [rec('__templeProgress', 'h-7:temple-x', {}, { hero_id: 'h-7' }), PARENT],
    ]);
    assert.deepStrictEqual(res.map((r) => r.hero_id), ['h-7', 'h-7', 'h-7']);
  });

  it('a hero id on the wire that differs from the one the record gives is a scope refusal, whoever sends it', () => {
    const res = checks([
      [rec('__profiles', 'h-1', {}, { hero_id: 'h-2' }), PARENT],
      [rec('__questLog', 'ql-1', { userId: 'h-1' }, { hero_id: 'h-2' }), PARENT],
      [rec('__templeProgress', 'h-1:temple-x', {}, { hero_id: 'h-2' }), PARENT],
      [rec('__profiles', 'h-1', {}, { hero_id: 'h-2' }), heroKey('h-2')],
      [rec('__profiles', 'h-1', {}, { hero_id: 'h-1' }), PARENT],
      [rec('__profiles', 'h-1', {}, { hero_id: null }), PARENT],
      [rec('__profiles', 'h-1', {}, { hero_id: 5 }), PARENT],
      [rec('__profiles', 'h-1', {}, { hero_id: '' }), PARENT],
    ]);
    assert.deepStrictEqual(res.map((r) => (typeof r === 'string' ? r : 'ok')), [SCOPE, SCOPE, SCOPE, SCOPE, 'ok', 'ok', INVALID, INVALID]);
  });

  it('refuses a hero record whose hero cannot be told', () => {
    const res = checks([
      [rec('__profiles', 'a b', {}), PARENT],
      [rec('__profiles', 'x'.repeat(65), {}), PARENT],
      [rec('__templeProgress', 'no-colon', {}), PARENT],
      [rec('__templeProgress', ':temple-x', {}), PARENT],
      [rec('__questLog', 'ql-1', {}), PARENT],
      [rec('__questLog', 'ql-1', { userId: 5 }), PARENT],
      [rec('__questLog', 'ql-1', { userId: 'x'.repeat(65) }), PARENT],
      [rec('__latePenalties', 'lp-1', { userId: 'h-1' }), PARENT],
      [rec('__questLog', 'ql-1', {}, { hero_id: 'h-1' }), PARENT],
      [tomb('__profiles', 'a b', { hero_id: 'h-1' }), PARENT],
      [tomb('__templeProgress', 'no-colon', { hero_id: 'h-1' }), PARENT],
    ]);
    assert.deepStrictEqual(res, Array(11).fill(INVALID));
  });

  it('a deleted record carries no body and keeps the hero: from the key where the key gives it', () => {
    const res = checks([
      [tomb('__profiles', 'h-1'), PARENT],
      [tomb('__notifications', 'h-1'), PARENT],
      [tomb('__templeProgress', 'h-1:temple-x'), PARENT],
      [tomb('__shops', 's-1'), PARENT],
      [tomb('__chat', 'm-1'), PARENT],
      [tomb('__shops', 's-1', { body: '' }), PARENT],
      [tomb('__shops', 's-1', { body: null }), PARENT],
      [tomb('__profiles', 'h-1', { hero_id: 'h-1' }), PARENT],
    ]);
    const tombstone = (store, key, hero) => ({ store, key, hero_id: hero, deleted: true, body: '' });
    assert.deepStrictEqual(res, [
      tombstone('__profiles', 'h-1', 'h-1'),
      tombstone('__notifications', 'h-1', 'h-1'),
      tombstone('__templeProgress', 'h-1:temple-x', 'h-1'),
      tombstone('__shops', 's-1', null),
      tombstone('__chat', 'm-1', null),
      tombstone('__shops', 's-1', null),
      tombstone('__shops', 's-1', null),
      tombstone('__profiles', 'h-1', 'h-1'),
    ]);
  });

  it('a deleted record of a store whose hero is in the body takes the hero from the wire', () => {
    const res = checks([
      [tomb('__questLog', 'ql-1', { hero_id: 'h-2' }), PARENT],
      [tomb('__purchaseLog', 'pl-1', { hero_id: 'h-2' }), PARENT],
      [tomb('__castLog', 'cl-1', { hero_id: 'h-2' }), PARENT],
      [tomb('__latePenalties', 'lp-1', { hero_id: 'h-2' }), PARENT],
      [tomb('__questLog', 'ql-1'), PARENT],
      [tomb('__questLog', 'ql-1', { hero_id: 'a b' }), PARENT],
      [tomb('__questLog', 'ql-1', { hero_id: 'x'.repeat(65) }), PARENT],
      [tomb('__questLog', 'ql-1', { hero_id: 5 }), PARENT],
    ]);
    assert.deepStrictEqual(res.map((r) => (typeof r === 'string' ? r : r.hero_id)), ['h-2', 'h-2', 'h-2', 'h-2', INVALID, INVALID, INVALID, INVALID]);
    assert.strictEqual(res[0].deleted, true);
    assert.strictEqual(res[0].body, '');
  });

  it('refuses a deleted record that still carries a body', () => {
    const res = checks([
      [tomb('__shops', 's-1', { body: '{"name":"Smithy"}' }), PARENT],
      [tomb('__shops', 's-1', { body: '{}' }), PARENT],
      [tomb('__shops', 's-1', { body: { name: 'Smithy' } }), PARENT],
    ]);
    assert.deepStrictEqual(res, [INVALID, INVALID, INVALID]);
  });

  it('a hero key deletes only what it may write', () => {
    const own = heroKey('h-1');
    const res = checks([
      [tomb('__profiles', 'h-1'), own],
      [tomb('__profiles', 'h-2'), own],
      [tomb('__templeProgress', 'h-2:temple-x'), own],
      [tomb('__questLog', 'ql-1', { hero_id: 'h-1' }), own],
      [tomb('__questLog', 'ql-1', { hero_id: 'h-2' }), own],
      [tomb('__chat', 'm-1'), own],
      [tomb('__shops', 's-1'), own],
    ]);
    assert.deepStrictEqual(res.map((r) => (typeof r === 'string' ? r : 'ok')), ['ok', SCOPE, SCOPE, 'ok', SCOPE, 'ok', SCOPE]);
  });

  it('the record decides before the caller: a bad record is invalid whoever sends it, an unknown store is unknown', () => {
    const res = checks([
      [rec('__achievements', 'q-1', '[]'), heroKey('h-1')],
      [rec('__nope', 'k', {}), heroKey('h-1')],
      [rec('__questLog', 'ql-1', 'not json'), heroKey('h-2')],
    ]);
    assert.deepStrictEqual(res, [INVALID, UNKNOWN, INVALID]);
  });

  it('may_read: a hero key reads family stores and its own hero\'s rows only', () => {
    const own = heroKey('h-1');
    const res = reads([
      [{ store: '__shops', hero_id: null }, own],
      [{ store: '__chat', hero_id: null }, own],
      [{ store: '__profiles', hero_id: 'h-1' }, own],
      [{ store: '__questLog', hero_id: 'h-1' }, own],
      [{ store: '__profiles', hero_id: 'h-2' }, own],
      [{ store: '__questLog', hero_id: 'h-2' }, own],
      [{ hero_id: 'h-2' }, own],
      [{ store: '__profiles', hero_id: 'h-1' }, { writer: 'hero', key_hero_id: null }],
      [{ store: '__shops', hero_id: null }, { writer: 'hero', key_hero_id: null }],
    ]);
    assert.deepStrictEqual(res, [true, true, true, true, false, false, false, false, true]);
  });

  it('may_read: a parent reads every row, and anyone else reads nothing', () => {
    const res = reads([
      [{ store: '__shops', hero_id: null }, PARENT],
      [{ store: '__profiles', hero_id: 'h-1' }, PARENT],
      [{ store: '__questLog', hero_id: 'h-9' }, PARENT],
      [{ store: '__shops', hero_id: null }, { writer: 'none', key_hero_id: null }],
      [{ store: '__shops', hero_id: null }, {}],
    ]);
    assert.deepStrictEqual(res, [true, true, true, false, false]);
  });
});

describe('Record rules: the file', () => {
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
    for (const rel of [CLASS_REL, 'family-records.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
