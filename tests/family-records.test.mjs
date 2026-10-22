/**
 * Family records (MP3, family sync): the record rules (the 18 stores of the spec's table and their
 * scopes, the hero id of a record, the size caps, who may write and read). The rules class is pure
 * PHP, loaded alone, with no WordPress.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';
import { storeRun } from './family-store-fake.mjs';

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
    // The spec's table (section 2: BACKUP_STORES less __localSettings) holds 18 stores.
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

describe('Record store', () => {
  const NOW = 1792000000;
  const STAMP = new Date(NOW * 1000).toISOString().slice(0, 19).replace('T', ' ');
  const PHONE = 'phone-a-0000001';
  const STORE_REL = '../includes/family/class-xophz-compass-xp-family-store.php';

  /** A clean record as Record_Rules::check hands it over: the body is the JSON text. */
  const clean = (store, key, body, over = {}) => ({ store, key, hero_id: null, deleted: false, body, ...over });
  const gone = (store, key, over = {}) => ({ store, key, hero_id: null, deleted: true, body: '', ...over });
  const hero = (store, key, heroId, body = '{"userId":"' + heroId + '"}') => clean(store, key, body, { hero_id: heroId });

  /** Build a fake database with `setup`, run `call` (which sets $result), and answer what changed. */
  function run(setup, call) {
    return storeRun(`
      $db = new FakeFamilyDb();
      $db->add_family( 'fam-1', 40 );
      ${setup}
      $store = new Xophz_Compass_Xp_Family_Store( $db );
      ${call}
      echo json_encode( [
        'result'   => is_wp_error( $result ) ? [ 'error' => $result->get_error_code(), 'status' => $result->get_error_data()['status'] ] : $result,
        'rows'     => array_values( $db->records ),
        'counters' => array_map( function ( $f ) { return $f['records_seq']; }, $db->families ),
        'log'      => array_column( $db->log, 'sql' ),
        'args'     => array_column( $db->log, 'args' ),
        'locks'    => array_keys( $db->locks ),
        'outside'  => $db->outside_lock,
      ] );`);
  }

  /** One upsert_records call; `caller` is the hero key's hero id, or null for a parent. */
  function upsert(records, { setup = '', caller = null, family = 'fam-1' } = {}) {
    return run(setup, `$result = $store->upsert_records( '${family}', '${PHONE}', ${lit(records)}, ${NOW}, ${caller === null ? 'null' : `'${caller}'`} );`);
  }

  const bySeq = (rows) => [...rows].sort((a, b) => a.seq - b.seq);
  const view = (row) => ({ family: row.family_id, store: row.store, key: row.record_key, seq: row.seq, hero: row.hero_id, deleted: row.deleted, body: row.body });
  const touched = (res, pattern) => res.log.filter((sql) => pattern.test(sql));

  it('numbers a batch from the family counter and writes it back', () => {
    const odd = '{ "name" : "Smithy 100% \\u00e9" , "n" : 1 }';
    const res = upsert([clean('__shops', 's-1', odd), hero('__questLog', 'ql-1', 'h-1'), gone('__shops', 's-2')]);

    assert.deepStrictEqual(res.result, {
      accepted: [{ store: '__shops', key: 's-1', seq: 41 }, { store: '__questLog', key: 'ql-1', seq: 42 }, { store: '__shops', key: 's-2', seq: 43 }],
      refused: [],
    });
    assert.strictEqual(res.counters['fam-1'], 43);
    assert.deepStrictEqual(bySeq(res.rows).map(view), [
      { family: 'fam-1', store: '__shops', key: 's-1', seq: 41, hero: null, deleted: 0, body: odd },
      { family: 'fam-1', store: '__questLog', key: 'ql-1', seq: 42, hero: 'h-1', deleted: 0, body: '{"userId":"h-1"}' },
      { family: 'fam-1', store: '__shops', key: 's-2', seq: 43, hero: null, deleted: 1, body: '' },
    ]);
    for (const row of res.rows) {
      assert.strictEqual(row.device_id, PHONE);
      assert.strictEqual(row.saved_at, STAMP);
    }
  });

  it('keeps counting from the written counter on the next batch', () => {
    const res = run('', `
      $first  = $store->upsert_records( 'fam-1', '${PHONE}', ${lit([clean('__shops', 'a', '{}'), clean('__shops', 'b', '{}')])}, ${NOW} );
      $second = $store->upsert_records( 'fam-1', '${PHONE}', ${lit([clean('__shops', 'c', '{}')])}, ${NOW} );
      $result = [ $first, $second ];`);
    assert.deepStrictEqual(res.result[0].accepted.map((r) => r.seq), [41, 42]);
    assert.deepStrictEqual(res.result[1].accepted.map((r) => r.seq), [43]);
    assert.strictEqual(res.counters['fam-1'], 43);
  });

  it('runs under the family lock: lock, counter row, then writes, then release', () => {
    const res = upsert([clean('__shops', 's-1', '{}')]);
    assert.deepStrictEqual(res.args[0], ['xp_family_fam-1', 5]);
    assert.ok(res.log[0].startsWith('SELECT GET_LOCK('), res.log[0]);
    assert.strictEqual(res.log[1], 'SELECT records_seq FROM wp_xp_families WHERE family_id = %s FOR UPDATE');
    assert.ok(res.log[res.log.length - 1].startsWith('SELECT RELEASE_LOCK('), res.log[res.log.length - 1]);
    assert.deepStrictEqual(res.args[res.args.length - 1], ['xp_family_fam-1']);
    assert.deepStrictEqual(res.locks, [], 'the lock is released');
    assert.deepStrictEqual(res.outside, [], 'nothing is written outside the lock');
  });

  it('reserves the numbers before it writes, so a crash leaves a gap and never a reused number', () => {
    const res = upsert([clean('__shops', 'a', '{}'), clean('__shops', 'b', '{}')]);
    const reserve = res.log.findIndex((sql) => sql.startsWith('UPDATE wp_xp_families SET records_seq'));
    const firstWrite = res.log.findIndex((sql) => sql.startsWith('INSERT INTO wp_xp_family_records'));
    assert.ok(reserve > 0 && firstWrite > reserve, `reserve ${reserve}, first write ${firstWrite}`);
    assert.strictEqual(res.args[reserve][0], 42);
  });

  it('writes the upsert the brief names, one statement per accepted record', () => {
    const res = upsert([clean('__shops', 'a', '{}'), gone('__shops', 'b'), hero('__questLog', 'c', 'h-1')]);
    const writes = touched(res, /^INSERT INTO /);
    assert.strictEqual(writes.length, 3);
    const tail = 'ON DUPLICATE KEY UPDATE seq = VALUES(seq), hero_id = VALUES(hero_id), device_id = VALUES(device_id), saved_at = VALUES(saved_at), deleted = VALUES(deleted), body = VALUES(body)';
    for (const sql of writes) {
      assert.ok(sql.startsWith('INSERT INTO wp_xp_family_records (seq, family_id, store, record_key, hero_id, device_id, saved_at, deleted, body) VALUES ('), sql);
      assert.ok(sql.endsWith(tail), sql);
    }
    // A family record's hero id is a real NULL, never the empty string a NULL argument would become.
    assert.ok(writes[0].includes('%s, NULL, %s'), writes[0]);
    assert.ok(writes[2].includes('%s, %s, %s, %d, %s)'), writes[2]);
  });

  it('an existing key is overwritten and gets the new number', () => {
    const res = upsert([clean('__shops', 's-1', '{"name":"new"}')], {
      setup: `
        $db->add_family( 'fam-2', 5 );
        $db->add_record( 'fam-1', '__shops', 's-1', 5, null, '{"name":"old"}' );
        $db->add_record( 'fam-1', '__shops', 's-2', 6, null, '{"name":"keep"}' );
        $db->add_record( 'fam-2', '__shops', 's-1', 5, null, '{"name":"other family"}' );`,
    });
    assert.deepStrictEqual(res.result, { accepted: [{ store: '__shops', key: 's-1', seq: 41 }], refused: [] });
    const rows = bySeq(res.rows).map(view);
    assert.strictEqual(rows.length, 3, 'one row per key');
    assert.deepStrictEqual(rows, [
      { family: 'fam-2', store: '__shops', key: 's-1', seq: 5, hero: null, deleted: 0, body: '{"name":"other family"}' },
      { family: 'fam-1', store: '__shops', key: 's-2', seq: 6, hero: null, deleted: 0, body: '{"name":"keep"}' },
      { family: 'fam-1', store: '__shops', key: 's-1', seq: 41, hero: null, deleted: 0, body: '{"name":"new"}' },
    ]);
    assert.strictEqual(res.rows.find((row) => row.record_key === 's-1' && row.family_id === 'fam-1').device_id, PHONE);
    assert.strictEqual(res.counters['fam-2'], 5, 'another family is not touched');
  });

  it('a tombstone stores an empty body, even if one is handed over, and a later live record brings the key back', () => {
    const res = run(`$db->add_record( 'fam-1', '__shops', 's-1', 5, null, '{"name":"old"}' );`, `
      $first  = $store->upsert_records( 'fam-1', '${PHONE}', ${lit([{ ...gone('__shops', 's-1'), body: '{"stray":true}' }])}, ${NOW} );
      $after  = array_values( $db->records );
      $second = $store->upsert_records( 'fam-1', '${PHONE}', ${lit([clean('__shops', 's-1', '{"name":"back"}')])}, ${NOW} );
      $result = [ $first, $second, $after ];`);
    assert.deepStrictEqual(res.result[0].accepted, [{ store: '__shops', key: 's-1', seq: 41 }]);
    assert.deepStrictEqual([res.result[2][0].deleted, res.result[2][0].body, res.result[2][0].seq], [1, '', 41]);
    assert.deepStrictEqual(res.result[1].accepted, [{ store: '__shops', key: 's-1', seq: 42 }]);
    assert.deepStrictEqual(res.rows.map(view), [{ family: 'fam-1', store: '__shops', key: 's-1', seq: 42, hero: null, deleted: 0, body: '{"name":"back"}' }]);
  });

  it('a key twice in one batch ends as the later one, with the later number', () => {
    const res = upsert([clean('__shops', 's-1', '{"v":1}'), clean('__shops', 's-1', '{"v":2}')]);
    assert.deepStrictEqual(res.result.accepted.map((r) => r.seq), [41, 42]);
    assert.deepStrictEqual(res.rows.map(view), [{ family: 'fam-1', store: '__shops', key: 's-1', seq: 42, hero: null, deleted: 0, body: '{"v":2}' }]);
  });

  it('a record that cannot be written is in neither list, and the batch carries on', () => {
    const res = run('$db->fail_key = "bad";', `$result = $store->upsert_records( 'fam-1', '${PHONE}', ${lit([clean('__shops', 'a', '{}'), clean('__shops', 'bad', '{}'), clean('__shops', 'c', '{}')])}, ${NOW} );`);
    assert.deepStrictEqual(res.result, { accepted: [{ store: '__shops', key: 'a', seq: 41 }, { store: '__shops', key: 'c', seq: 43 }], refused: [] });
    assert.strictEqual(res.counters['fam-1'], 43, 'the number of the lost write is spent, never reused');
  });

  it('answers 503 xp_family_busy and writes nothing when the lock is not had', () => {
    const res = run('$db->lock_free = false;', `$result = $store->upsert_records( 'fam-1', '${PHONE}', ${lit([clean('__shops', 'a', '{}')])}, ${NOW} );`);
    assert.deepStrictEqual(res.result, { error: 'xp_family_busy', status: 503 });
    assert.deepStrictEqual(res.rows, []);
    assert.strictEqual(res.counters['fam-1'], 40);
    assert.strictEqual(res.log.length, 1, 'only the lock was asked for');
  });

  it('an empty batch answers empty lists without taking the lock', () => {
    const res = upsert([]);
    assert.deepStrictEqual(res.result, { accepted: [], refused: [] });
    assert.deepStrictEqual(res.log, []);
  });

  it('a family that is not there is a 404, nothing is written and the lock is released', () => {
    const res = upsert([clean('__shops', 'a', '{}')], { family: 'fam-9' });
    assert.deepStrictEqual(res.result, { error: 'xp_family_no_family', status: 404 });
    assert.deepStrictEqual(res.rows, []);
    assert.deepStrictEqual(res.locks, []);
  });

  it('releases the lock when a statement fails', () => {
    const out = storeRun(`
      $db = new FakeFamilyDb();
      $db->add_family( 'fam-1', 40 );
      $db->explode_on = 'INSERT INTO';
      $store = new Xophz_Compass_Xp_Family_Store( $db );
      $caught = '';
      try { $store->upsert_records( 'fam-1', '${PHONE}', ${lit([clean('__shops', 'a', '{}')])}, ${NOW} ); } catch ( RuntimeException $e ) { $caught = $e->getMessage(); }
      echo json_encode( [ 'caught' => $caught, 'locks' => array_keys( $db->locks ) ] );`);
    assert.strictEqual(out.caught, 'the database went away');
    assert.deepStrictEqual(out.locks, []);
  });

  describe('Record store: a hero key and the rows that already exist', () => {
    const SEEDS = `
      $db->add_record( 'fam-1', '__questLog', 'ql-mine', 7, 'h-1', '{"userId":"h-1","v":"mine"}' );
      $db->add_record( 'fam-1', '__questLog', 'ql-sib', 8, 'h-2', '{"userId":"h-2","v":"sibling"}' );
      $db->add_record( 'fam-1', '__chat', 'c-1', 9, null, '{"text":"hi"}' );
      $db->add_record( 'fam-1', '__shops', 's-1', 10, null, '{"name":"Smithy"}' );`;
    const asKid = (records) => upsert(records, { setup: SEEDS, caller: 'h-1' });

    it('refuses a live write over another hero\'s row, unnumbered, and accepts its own and a new key', () => {
      const res = asKid([
        hero('__questLog', 'ql-mine', 'h-1', '{"userId":"h-1","v":"edited"}'),
        hero('__questLog', 'ql-sib', 'h-1', '{"userId":"h-1","v":"hijack"}'),
        hero('__questLog', 'ql-new', 'h-1'),
      ]);
      assert.deepStrictEqual(res.result.accepted, [{ store: '__questLog', key: 'ql-mine', seq: 41 }, { store: '__questLog', key: 'ql-new', seq: 42 }]);
      assert.strictEqual(res.result.refused.length, 1);
      assert.deepStrictEqual([res.result.refused[0].store, res.result.refused[0].key, res.result.refused[0].code], ['__questLog', 'ql-sib', 'xp_family_scope']);
      assert.ok(res.result.refused[0].message.length > 0);
      assert.deepStrictEqual(Object.keys(res.result.refused[0]), ['store', 'key', 'code', 'message']);
      const sibling = res.rows.find((row) => row.record_key === 'ql-sib');
      assert.deepStrictEqual([sibling.seq, sibling.hero_id, sibling.body], [8, 'h-2', '{"userId":"h-2","v":"sibling"}'], 'the sibling\'s row is untouched');
      assert.strictEqual(res.rows.find((row) => row.record_key === 'ql-mine').body, '{"userId":"h-1","v":"edited"}');
      assert.strictEqual(res.counters['fam-1'], 42, 'a refused record takes no number');
    });

    it('refuses a tombstone over another hero\'s row and lets its own go', () => {
      const res = asKid([gone('__questLog', 'ql-sib', { hero_id: 'h-1' }), gone('__questLog', 'ql-mine', { hero_id: 'h-1' })]);
      assert.deepStrictEqual(res.result.accepted, [{ store: '__questLog', key: 'ql-mine', seq: 41 }]);
      assert.deepStrictEqual(res.result.refused.map((r) => [r.key, r.code]), [['ql-sib', 'xp_family_scope']]);
      const sibling = res.rows.find((row) => row.record_key === 'ql-sib');
      assert.deepStrictEqual([sibling.deleted, sibling.seq], [0, 8], 'the sibling\'s row is still live');
      assert.strictEqual(res.rows.find((row) => row.record_key === 'ql-mine').deleted, 1);
    });

    it('keeps both lists in the order the records came in', () => {
      const res = asKid([
        hero('__questLog', 'ql-sib', 'h-1'), hero('__questLog', 'a', 'h-1'), gone('__questLog', 'ql-sib', { hero_id: 'h-1' }), hero('__questLog', 'b', 'h-1'),
      ]);
      assert.deepStrictEqual(res.result.accepted.map((r) => [r.key, r.seq]), [['a', 41], ['b', 42]]);
      assert.deepStrictEqual(res.result.refused.map((r) => r.key), ['ql-sib', 'ql-sib']);
    });

    it('a row nobody owns (chat) stays writable by any hero key', () => {
      const res = asKid([clean('__chat', 'c-1', '{"text":"edited"}'), gone('__chat', 'c-1')]);
      assert.deepStrictEqual(res.result.accepted.map((r) => r.key), ['c-1', 'c-1']);
      assert.deepStrictEqual(res.result.refused, []);
    });

    it('looks the batch\'s keys up once, under the lock and before it numbers anything', () => {
      const res = asKid([hero('__questLog', 'ql-mine', 'h-1'), hero('__questLog', 'ql-new', 'h-1'), clean('__chat', 'c-1', '{}')]);
      const looks = res.log.map((sql, i) => [sql, i]).filter(([sql]) => sql.startsWith('SELECT store, record_key, hero_id FROM wp_xp_family_records WHERE family_id = %s AND ('));
      assert.strictEqual(looks.length, 1, 'one existence query for the batch');
      const reserve = res.log.findIndex((sql) => sql.startsWith('UPDATE wp_xp_families SET records_seq'));
      const lock = res.log.findIndex((sql) => sql.startsWith('SELECT GET_LOCK('));
      assert.ok(lock < looks[0][1] && looks[0][1] < reserve, `lock ${lock}, look ${looks[0][1]}, reserve ${reserve}`);
      assert.deepStrictEqual(res.args[looks[0][1]], ['fam-1', '__questLog', 'ql-mine', '__questLog', 'ql-new', '__chat', 'c-1']);
      assert.deepStrictEqual(res.outside, []);
    });

    it('never restricts a parent: no lookup, and any row can be overwritten', () => {
      const res = upsert([hero('__questLog', 'ql-sib', 'h-2', '{"userId":"h-2","v":"fixed by a parent"}'), gone('__shops', 's-1')], { setup: SEEDS, caller: null });
      assert.deepStrictEqual(res.result.accepted.map((r) => r.key), ['ql-sib', 's-1']);
      assert.deepStrictEqual(res.result.refused, []);
      assert.strictEqual(touched(res, /^SELECT store, record_key/).length, 0);
    });
  });

  describe('Record store: records_after', () => {
    const rows = `
      $db->add_record( 'fam-1', '__questLog', 'q-1', 11, 'h-1', '{"userId":"h-1"}' );
      $db->add_record( 'fam-1', '__shops', 's-1', 3, null, '{"name":"Smithy"}' );
      $db->add_record( 'fam-1', '__chat', 'c-1', 9, null, '{"text":"hi"}', 0 );
      $db->add_record( 'fam-1', '__profiles', 'h-1', 5, 'h-1', '{"id":"h-1"}' );
      $db->add_record( 'fam-1', '__profiles', 'h-2', 7, 'h-2', '{"id":"h-2"}' );
      $db->add_record( 'fam-1', '__shops', 's-gone', 13, null, '', 1 );
      $db->add_record( 'fam-2', '__shops', 's-1', 4, null, '{"name":"another family"}' );`;

    function after(since, limit, heroId = null) {
      return run(rows, `$result = $store->records_after( 'fam-1', ${since}, ${limit}, ${heroId === null ? 'null' : `'${heroId}'`} );`);
    }
    const seqs = (res) => res.result.map((row) => Number(row.seq));

    it('returns the family\'s rows after since, oldest first', () => {
      assert.deepStrictEqual(seqs(after(4, 100)), [5, 7, 9, 11, 13]);
      assert.deepStrictEqual(seqs(after(0, 100)), [3, 5, 7, 9, 11, 13]);
      assert.deepStrictEqual(seqs(after(13, 100)), []);
    });

    it('stops at the limit and keeps the oldest', () => {
      assert.deepStrictEqual(seqs(after(4, 2)), [5, 7]);
      assert.deepStrictEqual(seqs(after(0, 0)), [3], 'a limit under 1 is 1');
      assert.deepStrictEqual(seqs(after(-9, 100)), [3, 5, 7, 9, 11, 13], 'a negative since is 0');
    });

    it('with a hero, returns the family\'s rows and that hero\'s own, never another hero\'s', () => {
      assert.deepStrictEqual(seqs(after(0, 100, 'h-1')), [3, 5, 9, 11, 13]);
      assert.deepStrictEqual(seqs(after(0, 100, 'h-2')), [3, 7, 9, 13]);
      assert.deepStrictEqual(seqs(after(4, 100, 'h-3')), [9, 13]);
      const sql = after(0, 10, 'h-1').log[0];
      assert.ok(sql.includes('( hero_id IS NULL OR hero_id = %s )') && sql.includes('ORDER BY seq ASC LIMIT %d'), sql);
    });

    it('hands back the columns of the row with the body as the stored text, a tombstone empty', () => {
      const res = after(12, 100);
      assert.deepStrictEqual(res.result, [{
        seq: '13', family_id: 'fam-1', store: '__shops', record_key: 's-gone', hero_id: null,
        device_id: 'olddevice0000001', saved_at: '2026-10-01 00:00:00', deleted: '1', body: '',
      }]);
      assert.strictEqual(after(10, 100).result[0].body, '{"userId":"h-1"}');
    });
  });

  it('the store class lints', () => {
    const lint = lintPhpFile(path.join(HERE, STORE_REL));
    assert.strictEqual(lint.isValid, true, lint.output);
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
    for (const rel of [CLASS_REL, '../includes/family/class-xophz-compass-xp-family-store.php', 'family-records.test.mjs', 'family-store-fake.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
