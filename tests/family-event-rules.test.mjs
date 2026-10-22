/**
 * Family event rules (MP2): the kinds of event, field filtering, the checks on one event,
 * who may read an event and the hourly limits. Pure PHP, loaded alone, no WordPress.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLASS_REL = '../includes/family/class-xophz-compass-xp-family-event-rules.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);

export const RULES = "require_once '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/family/class-xophz-compass-xp-family-event-rules.php';\n";
export const CLS = 'Xophz_Compass_Xp_Family_Event_Rules';

export const NOW = Date.UTC(2026, 9, 13, 10, 0, 0) / 1000;
export const iso = (secs) => new Date(secs * 1000).toISOString();
export const ID1 = '5b1d0f3a-2c4e-4a6b-8c9d-0e1f2a3b4c5d';
export const DEVICE = 'devicetest000001';

const LOOK = {
  skin: 'tan', hair: 'short', hairColor: 'brown', face: 'smile', eyeColor: 'green', outfit: 'tunic',
  outfitColor: 'blue', outfitAccent: 'gold', hat: 'none', hatColor: 'red', leftHand: 'shield', rightHand: 'sword',
};

export function lit(value) {
  return `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;
}

export const wireEvent = (over = {}) => ({
  id: ID1,
  hero_id: 'h-1',
  device_id: DEVICE,
  kind: 'hero.card',
  at: iso(NOW - 60),
  data: { nickname: 'Mia', look: LOOK, jobClass: 'Knight', level: 3 },
  ...over,
});

export const context = (over = {}) => ({ writer: 'home', hero_ids: ['h-1', 'h-2'], key_hero_id: null, now: NOW, ...over });

/** Run check_event once per [event, ctx, kinds?] row, in one PHP run. */
export function checks(rows) {
  return runPhpJson(`${RULES}
    $rows = ${lit(rows)};
    $out = [];
    foreach ( $rows as $row ) {
      $out[] = isset( $row[2] ) ? ${CLS}::check_event( $row[0], $row[1], $row[2] ) : ${CLS}::check_event( $row[0], $row[1] );
    }
    echo json_encode( $out );`);
}

const check = (e, ctx = context(), kinds) => checks([kinds ? [e, ctx, kinds] : [e, ctx]])[0];

/** Run filter_data once per [kind, data, kinds?] row. */
function filters(rows) {
  return runPhpJson(`${RULES}
    $rows = ${lit(rows)};
    $out = [];
    foreach ( $rows as $row ) {
      $kept  = isset( $row[2] ) ? ${CLS}::filter_data( $row[0], $row[1], $row[2] ) : ${CLS}::filter_data( $row[0], $row[1] );
      $out[] = [] === $kept ? new stdClass() : $kept;
    }
    echo json_encode( $out );`);
}

/** Body of a data object that is exactly `total` bytes as JSON (ASCII only). */
export function dataOfBytes(total) {
  const empty = JSON.stringify({ pad: '' }).length;
  return { pad: 'a'.repeat(total - empty) };
}

describe('Family event rules: the kinds', () => {
  it('lists the nine kinds with their scope, writer and fields', () => {
    const res = runPhpJson(`${RULES} echo json_encode( ${CLS}::KINDS );`);
    assert.deepStrictEqual(Object.keys(res), [
      'hero.card', 'hero.leveled', 'quest.done', 'quest.claimed', 'quest.approved', 'quest.rejected',
      'reward.granted', 'hero.sheet', 'claim.settled',
    ]);
    assert.deepStrictEqual(res['hero.card'], { scope: 'public', writer: 'home', fields: { nickname: 'str40', look: 'look', jobClass: 'str40', level: 'int' } });
    assert.deepStrictEqual(res['hero.leveled'], { scope: 'public', writer: 'home', fields: { level: 'int', jobClass: 'str40' } });
    assert.deepStrictEqual(res['quest.done'], { scope: 'public', writer: 'home', fields: { difficulty: 'int', xp: 'int', fight: 'bool' } });
    assert.deepStrictEqual(res['reward.granted'], { scope: 'family', writer: 'server', fields: { xp: 'int', ap: 'int', reason: 'str40', sourceId: 'str64' } });
    // The five two-phone kinds are pinned in family-two-phone-events.test.mjs.
  });

  it('never lets a quest name, category or gold into a public kind', () => {
    const res = runPhpJson(`${RULES} echo json_encode( ${CLS}::KINDS );`);
    for (const kind of ['hero.card', 'hero.leveled', 'quest.done']) {
      const fields = Object.keys(res[kind].fields);
      for (const forbidden of ['name', 'title', 'category', 'gold', 'birthday', 'email']) {
        assert.ok(!fields.includes(forbidden), `${kind} must not carry ${forbidden}`);
      }
    }
  });

  it('holds the exact limits and look fields', () => {
    const res = runPhpJson(`${RULES}
      echo json_encode( [
        ${CLS}::LOOK_FIELDS, ${CLS}::MAX_BATCH, ${CLS}::MAX_DATA_BYTES, ${CLS}::HERO_PER_HOUR,
        ${CLS}::FAMILY_PER_HOUR, ${CLS}::FUTURE_SLACK, ${CLS}::RETRY_AFTER, ${CLS}::INT_MAX,
      ] );`);
    assert.deepStrictEqual(res[0], Object.keys(LOOK));
    assert.deepStrictEqual(res.slice(1), [50, 8192, 120, 600, 86400, 600, 100000]);
  });
});

describe('Family event rules: filter_data', () => {
  it('keeps the listed fields of the right type and drops the rest', () => {
    const [res] = filters([[
      'quest.done',
      { difficulty: 2, xp: 40, fight: true, name: 'Take your medicine', category: 'health', gold: 5 },
    ]]);
    assert.deepStrictEqual(res, { difficulty: 2, xp: 40, fight: true });
  });

  it('drops an int sent as a string, clamps ints to 0..100000', () => {
    const res = filters([
      ['quest.done', { xp: '40' }],
      ['quest.done', { xp: -5 }],
      ['quest.done', { xp: 999999 }],
      ['quest.done', { xp: 100000 }],
      ['quest.done', { xp: 0 }],
      ['quest.done', { xp: 40.5 }],
      ['quest.done', { xp: true }],
      ['quest.done', { xp: null }],
    ]);
    assert.deepStrictEqual(res, [{}, { xp: 0 }, { xp: 100000 }, { xp: 100000 }, { xp: 0 }, {}, {}, {}]);
  });

  it('keeps a bool only as a bool', () => {
    const res = filters([
      ['quest.done', { fight: false }],
      ['quest.done', { fight: 'true' }],
      ['quest.done', { fight: 1 }],
    ]);
    assert.deepStrictEqual(res, [{ fight: false }, {}, {}]);
  });

  it('counts string length in characters: 40 emoji fit a str40, 41 do not', () => {
    const res = filters([
      ['hero.leveled', { jobClass: '\u{1F600}'.repeat(40) }],
      ['hero.leveled', { jobClass: '\u{1F600}'.repeat(41) }],
      ['reward.granted', { sourceId: 'x'.repeat(64) }],
      ['reward.granted', { sourceId: 'x'.repeat(65) }],
      ['reward.granted', { reason: 7, sourceId: ['x'] }],
    ]);
    assert.deepStrictEqual(res, [
      { jobClass: '\u{1F600}'.repeat(40) },
      {},
      { sourceId: 'x'.repeat(64) },
      {},
      {},
    ]);
  });

  it('keeps only the look fields, as strings of up to 32 characters', () => {
    const res = filters([
      ['hero.card', { look: { ...LOOK, secret: 'x' } }],
      ['hero.card', { look: { skin: 'a'.repeat(32), hair: 'a'.repeat(33), hat: 5, face: null, hatColor: ['red'] } }],
      ['hero.card', { look: { secret: 'x' } }],
      ['hero.card', { look: ['tan', 'short'] }],
      ['hero.card', { look: 'tan' }],
      ['hero.card', { look: {} }],
    ]);
    assert.deepStrictEqual(res[0].look, LOOK);
    assert.deepStrictEqual(res[1], { look: { skin: 'a'.repeat(32) } });
    assert.deepStrictEqual(res.slice(2), [{}, {}, {}, {}]);
  });

  it('answers nothing for a kind it does not know, and follows a kinds table it is given', () => {
    const kinds = { 'party.ping': { scope: 'family', writer: 'hero', fields: { note: 'str40', n: 'int', odd: 'mystery' } } };
    const res = filters([
      ['hero.secret', { nickname: 'Mia' }],
      ['party.ping', { note: 'hi', n: 3, odd: 'x', extra: 1 }, kinds],
      ['hero.card', { nickname: 'Mia' }, kinds],
    ]);
    assert.deepStrictEqual(res, [{}, { note: 'hi', n: 3 }, {}]);
  });

  it('quest.claimed keeps synced as a bool', () => {
    const claim = { questId: 'q-1', difficulty: 2, requiresApproval: true };
    const res = filters([
      ['quest.claimed', { ...claim, synced: true }],
      ['quest.claimed', { ...claim, synced: false }],
      ['quest.claimed', { ...claim, synced: 'yes' }],
      ['quest.claimed', { ...claim, synced: 1 }],
      ['quest.claimed', claim],
    ]);
    assert.deepStrictEqual(res, [
      { ...claim, synced: true }, { ...claim, synced: false }, claim, claim, claim,
    ]);
    const ok = check(wireEvent({ kind: 'quest.claimed', data: { ...claim, synced: true, name: 'Dishes' } }));
    assert.strictEqual(ok.ok, true, JSON.stringify(ok));
    assert.deepStrictEqual(ok.event.data, { ...claim, synced: true });
  });
});

describe('Family event rules: check_event', () => {
  it('accepts a hero.card from the home device, filtered, with the stored shape', () => {
    const e = wireEvent({
      data: { nickname: 'Mia', look: { ...LOOK, secret: 'x' }, jobClass: 'Knight', level: 3, birthday: '2014-05-05' },
    });
    const res = check(e);
    assert.strictEqual(res.ok, true, JSON.stringify(res));
    assert.deepStrictEqual(Object.keys(res.event).sort(), ['data', 'device_id', 'event_id', 'hero_id', 'kind', 'occurred_at', 'scope']);
    assert.strictEqual(res.event.event_id, ID1);
    assert.strictEqual(res.event.hero_id, 'h-1');
    assert.strictEqual(res.event.device_id, DEVICE);
    assert.strictEqual(res.event.kind, 'hero.card');
    assert.strictEqual(res.event.scope, 'public');
    assert.strictEqual(res.event.occurred_at, NOW - 60);
    assert.deepStrictEqual(res.event.data, { nickname: 'Mia', look: LOOK, jobClass: 'Knight', level: 3 });
    assert.ok(!('birthday' in res.event.data));
    assert.ok(!('secret' in res.event.data.look));
  });

  it('refuses an event id that is not a v4 UUID, naming the id and the code', () => {
    const res = checks([
      [wireEvent({ id: 'abc' }), context()],
      [wireEvent({ id: ID1.toUpperCase() }), context()],
      [wireEvent({ id: '5b1d0f3a-2c4e-1a6b-8c9d-0e1f2a3b4c5d' }), context()],
      [wireEvent({ id: 5 }), context()],
      [(({ id, ...rest }) => rest)(wireEvent()), context()],
    ]);
    for (const r of res) {
      assert.strictEqual(r.ok, false);
      assert.strictEqual(r.code, 'xp_family_event_invalid');
      assert.ok(typeof r.message === 'string' && r.message.length > 0);
    }
    assert.strictEqual(res[0].id, 'abc');
  });

  it('refuses a kind it does not know', () => {
    const res = check(wireEvent({ kind: 'hero.secret' }));
    assert.strictEqual(res.ok, false);
    assert.strictEqual(res.id, ID1);
    assert.strictEqual(res.code, 'xp_family_event_invalid');
  });

  it('reward.granted is for the server only', () => {
    const reward = wireEvent({ kind: 'reward.granted', device_id: 'server', data: { xp: 25, ap: 2, reason: 'party-bonus', sourceId: 'smoke-1' } });
    const [fromHome, fromServer, fromHero] = checks([
      [reward, context({ writer: 'home' })],
      [reward, context({ writer: 'server' })],
      [reward, context({ writer: 'hero', key_hero_id: 'h-1' })],
    ]);
    assert.strictEqual(fromHome.ok, false);
    assert.strictEqual(fromHero.ok, false);
    assert.strictEqual(fromServer.ok, true, JSON.stringify(fromServer));
    assert.strictEqual(fromServer.event.scope, 'family');
    assert.strictEqual(fromServer.event.device_id, 'server');
    assert.deepStrictEqual(fromServer.event.data, { xp: 25, ap: 2, reason: 'party-bonus', sourceId: 'smoke-1' });
  });

  it('a hero key cannot send the home device kinds, for itself or a sibling', () => {
    const res = checks([
      [wireEvent({ hero_id: 'h-1' }), context({ writer: 'hero', key_hero_id: 'h-1' })],
      [wireEvent({ hero_id: 'h-2' }), context({ writer: 'hero', key_hero_id: 'h-1' })],
      [wireEvent({ kind: 'quest.done', data: { xp: 5 } }), context({ writer: 'hero', key_hero_id: 'h-1' })],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, false, false]);
  });

  it('a hero writer kind needs the key hero to be the event hero', () => {
    const kinds = { 'party.ping': { scope: 'family', writer: 'hero', fields: { note: 'str40' } } };
    const ping = (heroId) => wireEvent({ kind: 'party.ping', hero_id: heroId, data: { note: 'hi' } });
    const res = checks([
      [ping('h-2'), context({ writer: 'hero', key_hero_id: 'h-1' }), kinds],
      [ping('h-1'), context({ writer: 'hero', key_hero_id: 'h-1' }), kinds],
      [ping('h-1'), context({ writer: 'hero', key_hero_id: null }), kinds],
      [ping('h-1'), context({ writer: 'home' }), kinds],
      [ping('h-1'), context({ writer: 'server' }), kinds],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, true, false, false, false]);
    assert.strictEqual(res[1].event.scope, 'family');
  });

  it('refuses a hero the family does not have, or an unknown writer', () => {
    const res = checks([
      [wireEvent({ hero_id: 'h-9' }), context()],
      [wireEvent({ hero_id: 7 }), context()],
      [wireEvent(), context({ hero_ids: [] })],
      [wireEvent(), context({ writer: 'admin' })],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, false, false, false]);
  });

  it('refuses a device id that cannot be stored', () => {
    const res = checks([
      [wireEvent({ device_id: 'server' }), context()],
      [wireEvent({ device_id: 'a'.repeat(16) }), context()],
      [wireEvent({ device_id: 'a'.repeat(17) }), context()],
      [wireEvent({ device_id: 'dev-1' }), context()],
      [wireEvent({ device_id: '' }), context()],
      [wireEvent({ device_id: 3 }), context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [true, true, false, false, false, false]);
  });

  it('accepts the past, up to a day ahead, and not a second more', () => {
    const res = checks([
      [wireEvent({ at: iso(NOW + 86401) }), context()],
      [wireEvent({ at: iso(NOW + 86400) }), context()],
      [wireEvent({ at: iso(NOW - 30 * 86400) }), context()],
      [wireEvent({ at: iso(NOW) }), context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, true, true, true]);
    assert.strictEqual(res[2].event.occurred_at, NOW - 30 * 86400);
  });

  it('reads ISO 8601 with zones and fractions, and refuses what is not a time', () => {
    const res = checks([
      [wireEvent({ at: '2026-10-13T09:00:00.123Z' }), context()],
      [wireEvent({ at: '2026-10-13T09:00:00+00:00' }), context()],
      [wireEvent({ at: '2026-10-13T11:00:00+02:00' }), context()],
      [wireEvent({ at: '2026-10-13T09:00:00' }), context()],
      [wireEvent({ at: 'tomorrow' }), context()],
      [wireEvent({ at: 'now' }), context()],
      [wireEvent({ at: '' }), context()],
      [wireEvent({ at: 1791885600 }), context()],
      [wireEvent({ at: '2026-02-31T10:00:00Z' }), context()],
      [wireEvent({ at: 'garbage' }), context()],
      [(({ at, ...rest }) => rest)(wireEvent()), context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [true, true, true, true, false, false, false, false, false, false, false]);
    assert.strictEqual(res[0].event.occurred_at, NOW - 3600);
    assert.strictEqual(res[1].event.occurred_at, NOW - 3600);
    assert.strictEqual(res[2].event.occurred_at, NOW - 3600);
    assert.strictEqual(res[3].event.occurred_at, NOW - 3600);
  });

  it('refuses data over 8192 bytes as JSON, and accepts exactly 8192', () => {
    const base = wireEvent({ kind: 'quest.done' });
    const res = checks([
      [{ ...base, data: dataOfBytes(9000) }, context()],
      [{ ...base, data: dataOfBytes(8193) }, context()],
      [{ ...base, data: dataOfBytes(8192) }, context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, false, true]);
    assert.deepStrictEqual(res[2].event.data, []);
  });

  it('measures the size of the data as the device sent it, not with unicode escapes', () => {
    // 2000 emoji are 8000 bytes in UTF-8 but 24000 characters as \\u escapes.
    const res = check(wireEvent({ kind: 'quest.done', data: { pad: '\u{1F600}'.repeat(2000) } }));
    assert.strictEqual(res.ok, true, JSON.stringify(res));
  });

  it('refuses data that is not a JSON object', () => {
    const base = wireEvent({ kind: 'quest.done' });
    const res = checks([
      [{ ...base, data: [1, 2, 3] }, context()],
      [{ ...base, data: 'xp' }, context()],
      [{ ...base, data: 7 }, context()],
      [{ ...base, data: null }, context()],
      [(({ data, ...rest }) => rest)(base), context()],
      [{ ...base, data: {} }, context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, false, false, false, false, true]);
  });

  it('filters the types of a quest.done: a string xp is dropped, a negative one is 0, a huge one is 100000', () => {
    const base = wireEvent({ kind: 'quest.done' });
    const res = checks([
      [{ ...base, data: { difficulty: 2, xp: '40', fight: true } }, context()],
      [{ ...base, data: { xp: -5 } }, context()],
      [{ ...base, data: { xp: 999999, name: 'Take your medicine' } }, context()],
    ]);
    assert.deepStrictEqual(res[0].event.data, { difficulty: 2, fight: true });
    assert.deepStrictEqual(res[1].event.data, { xp: 0 });
    assert.deepStrictEqual(res[2].event.data, { xp: 100000 });
  });

  it('one bad event does not spoil the next call', () => {
    const res = checks([
      [wireEvent({ id: 'abc' }), context()],
      [wireEvent(), context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, true]);
  });
});

describe('Family event rules: readable_by', () => {
  const row = (scope, heroId) => ({ scope, hero_id: heroId });

  function readable(rows) {
    return runPhpJson(`${RULES}
      $rows = ${lit(rows)};
      $out = [];
      foreach ( $rows as $r ) { $out[] = ${CLS}::readable_by( $r[0], $r[1], $r[2] ); }
      echo json_encode( $out );`);
  }

  it('a parent reads every row, a sibling family row included', () => {
    const res = readable([
      [row('public', 'h-1'), 'parent', null],
      [row('family', 'h-2'), 'parent', null],
      [row('family', 'h-1'), 'parent', 'h-1'],
    ]);
    assert.deepStrictEqual(res, [true, true, true]);
  });

  it('a hero reads public rows and the family rows of its own hero, not a sibling\'s', () => {
    const res = readable([
      [row('public', 'h-2'), 'hero', 'h-1'],
      [row('family', 'h-1'), 'hero', 'h-1'],
      [row('family', 'h-2'), 'hero', 'h-1'],
      [row('family', 'h-1'), 'hero', null],
    ]);
    assert.deepStrictEqual(res, [true, true, false, false]);
  });

  it('no credentials, an unknown caller or an unknown scope read nothing', () => {
    const res = readable([
      [row('public', 'h-1'), 'none', null],
      [row('public', 'h-1'), 'admin', null],
      [row('secret', 'h-1'), 'hero', 'h-1'],
      [{ hero_id: 'h-1' }, 'hero', 'h-1'],
    ]);
    assert.deepStrictEqual(res, [false, false, false, false]);
  });
});

describe('Family event rules: over_limit', () => {
  function limits(rows) {
    return runPhpJson(`${RULES}
      $rows = ${lit(rows)};
      $out = [];
      foreach ( $rows as $r ) { $out[] = ${CLS}::over_limit( $r[0], $r[1], $r[2] ); }
      echo json_encode( $out );`);
  }

  it('120 per hero and 600 per family per hour, the last allowed event included', () => {
    const res = limits([
      [{ 'h-1': 119 }, 0, { 'h-1': 1 }],
      [{ 'h-1': 120 }, 0, { 'h-1': 1 }],
      [[], 599, { 'h-1': 1 }],
      [[], 600, { 'h-1': 1 }],
    ]);
    assert.deepStrictEqual(res, [false, true, false, true]);
  });

  it('counts a whole batch against both limits', () => {
    const res = limits([
      [{ 'h-3': 0 }, 0, { 'h-3': 50 }],
      [{ 'h-3': 100 }, 100, { 'h-3': 20 }],
      [{ 'h-3': 100 }, 100, { 'h-3': 21 }],
      [{ 'h-1': 100, 'h-2': 100 }, 580, { 'h-1': 10, 'h-2': 11 }],
      [{ 'h-1': 100, 'h-2': 100 }, 579, { 'h-1': 10, 'h-2': 11 }],
      [[], 0, { 'h-1': 50, 'h-2': 50, 'h-3': 50 }],
    ]);
    assert.deepStrictEqual(res, [false, false, true, true, false, false]);
  });

  it('a hero over the limit does not hide behind a quiet one in the same batch', () => {
    const res = limits([[{ 'h-1': 120, 'h-2': 0 }, 120, { 'h-1': 1, 'h-2': 1 }]]);
    assert.deepStrictEqual(res, [true]);
  });

  it('an empty batch is never over the limit', () => {
    const res = limits([[{ 'h-1': 500 }, 900, []]]);
    assert.deepStrictEqual(res, [false]);
  });
});

describe('Family event rules: the file', () => {
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
    for (const rel of [CLASS_REL, 'family-event-rules.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
