/**
 * Family turn (MP3, family sync): a hero's play turn in the store. take_turn grants the turn when
 * it is free, when the same device asks again or when the asker forces it, and otherwise answers
 * busy with the holder's label and last-seen time; rest_turn and touch_turn act only for the
 * device that holds it; revoking a phone or removing a hero clears a turn they held. The store
 * runs against the in-memory stand-in for wpdb (family-store-fake.mjs), so the answers come from
 * the statements the store sends.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { storeRun, routeRun } from './family-store-fake.mjs';
import { gatekeeper } from './family-pro.test.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));

const NOW = 1792000000;
const stamp = (unix) => new Date(unix * 1000).toISOString().slice(0, 19).replace('T', ' ');
const iso = (unix) => new Date(unix * 1000).toISOString().slice(0, 19) + 'Z';

const MIA = 'miaphone00000001';
const TABLET = 'familytablet0001';
const DAD = 'dadphone00000001';

/** Build the fake database with `setup`, run `call` (sets $result), and answer the result and the heroes. */
function run(setup, call) {
  return storeRun(`
    $db = new FakeFamilyDb();
    $db->add_family( 'fam-1', 0 );
    $db->add_hero( 'fam-1', 'h-1' );
    $db->add_hero( 'fam-1', 'h-2' );
    ${setup}
    $store = new Xophz_Compass_Xp_Family_Store( $db );
    ${call}
    echo json_encode( [
      'result' => is_wp_error( $result ) ? [ 'error' => $result->get_error_code(), 'status' => $result->get_error_data()['status'] ] : $result,
      'heroes' => $db->heroes,
      'log'    => array_column( $db->log, 'sql' ),
      'args'   => array_column( $db->log, 'args' ),
      'locks'  => array_keys( $db->locks ),
      'outside' => $db->outside_lock,
    ] );`);
}

/** The four turn columns of a hero row. */
const turnOf = (res, hero) => {
  const h = res.heroes[`fam-1|${hero}`];
  return { device: h.turn_device_id, label: h.turn_label, taken: h.turn_taken_at, seen: h.turn_seen_at };
};

const FREE = { device: null, label: '', taken: null, seen: null };

/** A PHP single-quoted string. */
const php = (text) => `'${text.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

/** A hero whose turn is held by `device`, taken at `taken` and last seen at `seen` (unix). */
const held = (hero, device, label, taken, seen) =>
  `$db->add_hero( 'fam-1', '${hero}', [ 'turn_device_id' => '${device}', 'turn_label' => ${php(label)}, 'turn_taken_at' => '${stamp(taken)}', 'turn_seen_at' => '${stamp(seen)}' ] );`;

const take = (hero, device, label, force = false, now = NOW) =>
  `$result = $store->take_turn( 'fam-1', '${hero}', '${device}', ${php(label)}, ${force}, ${now} );`;

describe('Hero turn: taking it', () => {
  it('is granted when nobody holds it, and the turn is set to this device and now', () => {
    const res = run('', take('h-1', MIA, "Mia's iPhone"));
    assert.deepStrictEqual(res.result, { answer: 'granted' });
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: MIA, label: "Mia's iPhone", taken: stamp(NOW), seen: stamp(NOW) });
    assert.deepStrictEqual(turnOf(res, 'h-2'), FREE, 'another hero is not touched');
  });

  it('is busy with the holder\'s label and last seen (ISO) when another device holds it, and changes nothing', () => {
    const seen = NOW - 150;
    const res = run(held('h-1', MIA, "Mia's iPhone", NOW - 3600, seen), take('h-1', TABLET, 'the family tablet'));
    assert.deepStrictEqual(res.result, { answer: 'busy', device: { label: "Mia's iPhone", last_seen_at: iso(seen) } });
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: MIA, label: "Mia's iPhone", taken: stamp(NOW - 3600), seen: stamp(seen) });
  });

  it('is granted with force, and the turn moves to the asker', () => {
    const res = run(held('h-1', MIA, "Mia's iPhone", NOW - 3600, NOW - 60), take('h-1', TABLET, 'the family tablet', true));
    assert.deepStrictEqual(res.result, { answer: 'granted' });
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: TABLET, label: 'the family tablet', taken: stamp(NOW), seen: stamp(NOW) });
  });

  it('lets the same device take it again silently, with the new label and time', () => {
    const res = run(held('h-1', MIA, 'Mia phone', NOW - 3600, NOW - 60), take('h-1', MIA, "Mia's iPhone"));
    assert.deepStrictEqual(res.result, { answer: 'granted' });
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: MIA, label: "Mia's iPhone", taken: stamp(NOW), seen: stamp(NOW) });
  });

  it('is granted to the holder asking again within the same second, when no column changes', () => {
    const res = run(held('h-1', MIA, "Mia's iPhone", NOW, NOW), take('h-1', MIA, "Mia's iPhone"));
    assert.deepStrictEqual(res.result, { answer: 'granted' });
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: MIA, label: "Mia's iPhone", taken: stamp(NOW), seen: stamp(NOW) });
  });

  it('decides in one conditional UPDATE, so two devices asking at once cannot both be granted', () => {
    const res = run('', take('h-1', MIA, 'Mia'));
    const updates = res.log.filter((sql) => sql.startsWith('UPDATE wp_xp_family_heroes SET turn_device_id = %s'));
    assert.strictEqual(updates.length, 1);
    assert.ok(updates[0].includes('AND ( %d = 1 OR turn_device_id IS NULL OR turn_device_id = %s )'), updates[0]);
    assert.ok(updates[0].includes('removed_at IS NULL'), updates[0]);
  });

  it('takes the turn under the family lock, the one a records write holds, and lets go of it', () => {
    // A forced take must not land between a records batch checking the turn and writing its rows.
    const res = run('', take('h-1', MIA, 'Mia'));
    assert.ok(res.log[0].startsWith('SELECT GET_LOCK('), res.log[0]);
    assert.deepStrictEqual(res.args[0], ['xp_family_fam-1', 5]);
    assert.ok(res.log[res.log.length - 1].startsWith('SELECT RELEASE_LOCK('));
    assert.strictEqual(res.log.filter((sql) => sql.startsWith('UPDATE wp_xp_family_heroes SET turn_device_id = %s')).length, 1);
    assert.deepStrictEqual(res.outside, [], 'the write happens inside the lock');
    assert.deepStrictEqual(res.locks, []);
  });

  it('lets go of the lock on every answer: granted, busy, missing and the failed write', () => {
    const answers = run(`${held('h-1', MIA, 'Mia', NOW - 60, NOW - 60)} $db->add_hero( 'fam-1', 'h-3', [ 'removed_at' => '2026-10-02 00:00:00' ] ); $db->add_hero( 'fam-1', 'h-4' );`, `
      $result = [
        $store->take_turn( 'fam-1', 'h-1', '${TABLET}', 'tablet', false, ${NOW} ),
        $store->take_turn( 'fam-1', 'h-3', '${TABLET}', 'tablet', false, ${NOW} ),
        $store->take_turn( 'fam-1', 'h-2', '${TABLET}', 'tablet', false, ${NOW} ),
      ];
      $db->fail_turn_update = true;
      $result[] = $store->take_turn( 'fam-1', 'h-4', '${DAD}', 'dad', false, ${NOW} ) instanceof WP_Error;`);
    assert.deepStrictEqual(answers.result.map((r) => r.answer ?? r), ['busy', 'missing', 'granted', true]);
    assert.deepStrictEqual(answers.locks, []);
  });

  it('answers 503 xp_family_busy and changes nothing when the family lock is not had', () => {
    const res = run(held('h-1', MIA, 'Mia', NOW - 60, NOW - 60), `$db->lock_free = false; ${take('h-1', TABLET, 'the family tablet', true)}`);
    assert.deepStrictEqual(res.result, { error: 'xp_family_busy', status: 503 });
    assert.strictEqual(turnOf(res, 'h-1').device, MIA);
    assert.strictEqual(res.log.length, 1, 'only the lock was asked for');
  });

  it('never grants on a write that failed', () => {
    const res = run('$db->fail_turn_update = true;', take('h-1', MIA, 'Mia'));
    assert.deepStrictEqual(res.result, { error: 'xp_family_busy', status: 503 });
    assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
  });

  it('answers missing for a hero that is not there or was removed, and grants nothing', () => {
    const res = run(`$db->add_hero( 'fam-1', 'h-3', [ 'removed_at' => '2026-10-02 00:00:00' ] );`, `
      $result = [ $store->take_turn( 'fam-1', 'h-9', '${MIA}', 'Mia', false, ${NOW} ), $store->take_turn( 'fam-1', 'h-3', '${MIA}', 'Mia', true, ${NOW} ) ];`);
    assert.deepStrictEqual(res.result, [{ answer: 'missing' }, { answer: 'missing' }]);
    assert.deepStrictEqual(turnOf(res, 'h-3'), FREE);
  });

  it('cuts a label past the 60 characters of the column', () => {
    const res = run('', take('h-1', MIA, 'é'.repeat(70)));
    assert.deepStrictEqual(res.result, { answer: 'granted' });
    assert.strictEqual([...turnOf(res, 'h-1').label].length, 60);
  });
});

describe('Hero turn: resting, keeping it fresh and asking who holds it', () => {
  it('rest from a non-holder changes nothing', () => {
    const res = run(held('h-1', MIA, "Mia's iPhone", NOW - 3600, NOW - 60), `$store->rest_turn( 'fam-1', 'h-1', '${TABLET}' ); $result = null;`);
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: MIA, label: "Mia's iPhone", taken: stamp(NOW - 3600), seen: stamp(NOW - 60) });
  });

  it('rest from the holder clears the whole turn', () => {
    const res = run(held('h-1', MIA, "Mia's iPhone", NOW - 3600, NOW - 60), `$store->rest_turn( 'fam-1', 'h-1', '${MIA}' ); $result = null;`);
    assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
  });

  it('a late rest from a device that lost the turn cannot clear the new holder', () => {
    const res = run(held('h-1', MIA, "Mia's iPhone", NOW - 3600, NOW - 60), `
      $store->take_turn( 'fam-1', 'h-1', '${TABLET}', 'the family tablet', true, ${NOW} );
      $store->rest_turn( 'fam-1', 'h-1', '${MIA}' );
      $result = $store->turn_holder( 'fam-1', 'h-1' );`);
    assert.strictEqual(res.result, TABLET);
    assert.strictEqual(turnOf(res, 'h-1').label, 'the family tablet');
  });

  it('rest only reaches the hero it names, in its own family', () => {
    const res = run(`${held('h-1', MIA, 'Mia', NOW - 60, NOW - 60)} ${held('h-2', MIA, 'Mia', NOW - 60, NOW - 60)}`, `$store->rest_turn( 'fam-1', 'h-1', '${MIA}' ); $store->rest_turn( 'fam-2', 'h-2', '${MIA}' ); $result = null;`);
    assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
    assert.strictEqual(turnOf(res, 'h-2').device, MIA);
  });

  it('touch_turn refreshes last seen for the holder only', () => {
    const res = run(held('h-1', MIA, "Mia's iPhone", NOW - 3600, NOW - 600), `
      $store->touch_turn( 'fam-1', 'h-1', '${TABLET}', ${NOW - 100} );
      $before = $db->heroes['fam-1|h-1']['turn_seen_at'];
      $store->touch_turn( 'fam-1', 'h-1', '${MIA}', ${NOW} );
      $result = $before;`);
    assert.strictEqual(res.result, stamp(NOW - 600), 'a non-holder\'s touch changes nothing');
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: MIA, label: "Mia's iPhone", taken: stamp(NOW - 3600), seen: stamp(NOW) });
  });

  it('touch_turn on a free hero holds nothing for the asker', () => {
    const res = run('', `$store->touch_turn( 'fam-1', 'h-1', '${MIA}', ${NOW} ); $result = $store->turn_holder( 'fam-1', 'h-1' );`);
    assert.strictEqual(res.result, null);
    assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
  });

  it('turn_holder answers the device id, or null when free, removed or unknown', () => {
    const res = run(`${held('h-1', MIA, 'Mia', NOW, NOW)} $db->add_hero( 'fam-1', 'h-3', [ 'turn_device_id' => '${TABLET}', 'removed_at' => '2026-10-02 00:00:00' ] );`, `
      $result = [ $store->turn_holder( 'fam-1', 'h-1' ), $store->turn_holder( 'fam-1', 'h-2' ), $store->turn_holder( 'fam-1', 'h-3' ), $store->turn_holder( 'fam-1', 'h-9' ), $store->turn_holder( 'fam-2', 'h-1' ) ];`);
    assert.deepStrictEqual(res.result, [MIA, null, null, null, null]);
  });

  it('a holder that touches keeps the busy answer honest to the minute', () => {
    const res = run(held('h-1', MIA, "Mia's iPhone", NOW - 3600, NOW - 3600), `
      $store->touch_turn( 'fam-1', 'h-1', '${MIA}', ${NOW - 30} );
      $result = $store->take_turn( 'fam-1', 'h-1', '${TABLET}', 'the family tablet', false, ${NOW} );`);
    assert.deepStrictEqual(res.result, { answer: 'busy', device: { label: "Mia's iPhone", last_seen_at: iso(NOW - 30) } });
  });
});

describe('Hero turn: a revoked phone and a removed hero', () => {
  const PHONES = `$db->add_device( 31, 'fam-1', 'h-1', '${MIA}' ); $db->add_device( 32, 'fam-1', 'h-2', '${DAD}' );`;

  it('revoking the holding device clears the turn', () => {
    const res = run(`${PHONES} ${held('h-1', MIA, "Mia's iPhone", NOW - 3600, NOW - 60)} ${held('h-2', DAD, 'Dad', NOW - 60, NOW - 60)}`, `$result = $store->revoke_device( 'fam-1', 31 );`);
    assert.strictEqual(res.result, true);
    assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
    assert.strictEqual(turnOf(res, 'h-2').device, DAD, 'another phone\'s turn stays');
  });

  it('revoking a phone clears only the turn of its own hero, not another hero the same device id holds', () => {
    const res = run(`${PHONES} ${held('h-1', MIA, 'Mia', NOW - 60, NOW - 60)} ${held('h-2', MIA, 'Mia too', NOW - 60, NOW - 60)}`, `$result = $store->revoke_device( 'fam-1', 31 );`);
    assert.strictEqual(res.result, true);
    assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
    assert.deepStrictEqual(turnOf(res, 'h-2'), { device: MIA, label: 'Mia too', taken: stamp(NOW - 60), seen: stamp(NOW - 60) });
  });

  it('revoking a device that holds nothing leaves the turns as they are', () => {
    const res = run(`${PHONES} ${held('h-1', TABLET, 'the family tablet', NOW - 60, NOW - 60)}`, `$result = $store->revoke_device( 'fam-1', 31 );`);
    assert.strictEqual(res.result, true);
    assert.strictEqual(turnOf(res, 'h-1').device, TABLET, 'the family tablet is not a linked phone');
  });

  it('revoking a phone of another family, or one already revoked, clears nothing', () => {
    const res = run(`${PHONES} ${held('h-1', MIA, 'Mia', NOW - 60, NOW - 60)}`, `
      $other = $store->revoke_device( 'fam-2', 31 );
      $first = $store->revoke_device( 'fam-1', 31 );
      $db->add_hero( 'fam-1', 'h-1', [ 'turn_device_id' => '${MIA}', 'turn_label' => 'Mia again', 'turn_taken_at' => '${stamp(NOW)}', 'turn_seen_at' => '${stamp(NOW)}' ] );
      $again = $store->revoke_device( 'fam-1', 31 );
      $result = [ $other, $first, $again ];`);
    assert.deepStrictEqual(res.result, [false, true, false]);
    assert.strictEqual(turnOf(res, 'h-1').label, 'Mia again', 'the second revoke does not clear a turn taken since');
  });

  it('removing a hero clears the turn it held and keeps the others', () => {
    const res = run(`${held('h-1', TABLET, 'the family tablet', NOW - 60, NOW - 60)} ${held('h-2', TABLET, 'the family tablet', NOW - 60, NOW - 60)}`, `$store->remove_hero( 'fam-1', 'h-1' ); $result = $store->turn_holder( 'fam-1', 'h-1' );`);
    assert.strictEqual(res.result, null);
    assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
    assert.strictEqual(turnOf(res, 'h-2').device, TABLET);
    assert.notStrictEqual(res.heroes['fam-1|h-1'].removed_at, null);
  });

  it('a removed hero has no turn to take', () => {
    const res = run(held('h-1', MIA, 'Mia', NOW - 60, NOW - 60), `
      $store->remove_hero( 'fam-1', 'h-1' );
      $result = $store->take_turn( 'fam-1', 'h-1', '${TABLET}', 'the family tablet', false, ${NOW} );`);
    assert.deepStrictEqual(res.result, { answer: 'missing' });
  });
});

describe('Hero turn: the play and rest routes', () => {
  const PAID = { 'fam-1': { status: 'active', expires_at: null, plan: 'lifetime' } };
  const PARENT_CALLER = { kind: 'parent', user_id: 7 };
  const kid = (heroId = 'h-1', device = MIA, label = "Mia's iPhone") => ({ kind: 'hero', device: { id: 31, family_id: 'fam-1', hero_id: heroId, device_id: device, label } });
  const lit = (value) => `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;

  const DUMP = `echo json_encode( [
    'result' => wire( $result ),
    'heroes' => $db->heroes,
    'log'    => array_column( $db->log, 'sql' ),
    'locks'  => array_keys( $db->locks ),
  ] );`;

  /** Build the fake family (heroes h-1 and h-2 free), seed it, then run `call` (which sets $result). */
  function route(call, { setup = '', licences = PAID, who = null, request = '' } = {}) {
    return routeRun(`
      ${licences === null ? '' : gatekeeper(licences)}
      $db = new FakeFamilyDb();
      $db->add_family( 'fam-1', 0 );
      $db->add_hero( 'fam-1', 'h-1' );
      $db->add_hero( 'fam-1', 'h-2' );
      ${setup}
      $store  = new Xophz_Compass_Xp_Family_Store( $db );
      $family = [ 'family_id' => 'fam-1', 'home_device_id' => 'homedevice000001' ];
      ${who === null ? '' : `$rest = new StubRest( 'xp', '1', new StubFamilyStore( $db ) ); $rest->who = ${lit(who)}; $req = new StubRequest(); ${request}`}
      ${call}
      ${DUMP}`);
  }

  /** Records::play for the caller; `device` is the one the request names. */
  function play(hero, device, label, { force = false, caller = PARENT_CALLER, ...rest } = {}) {
    return route(`$result = Xophz_Compass_Xp_Family_Records::play( $store, $family, ${lit(caller)}, '${hero}', '${device}', ${php(label)}, ${force}, ${NOW} );`, rest);
  }

  function rest(hero, device, { caller = PARENT_CALLER, ...others } = {}) {
    return route(`$result = Xophz_Compass_Xp_Family_Records::rest( $store, $family, ${lit(caller)}, '${hero}', '${device}', ${NOW} );`, others);
  }

  const proRequired = { error: 'xp_family_pro_required', status: 403 };
  const MIAS = (extra = '') => `${held('h-1', MIA, "Mia's iPhone", NOW - 3600, NOW - 150)} ${extra}`;

  it('play is granted when the hero is free or the asker already holds it, and sets the turn to that device and now', () => {
    const res = play('h-1', TABLET, 'the family tablet');
    assert.deepStrictEqual(res.result, { answer: 'granted' });
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: TABLET, label: 'the family tablet', taken: stamp(NOW), seen: stamp(NOW) });
    assert.deepStrictEqual(turnOf(res, 'h-2'), FREE);

    const again = play('h-1', MIA, "Mia's iPhone", { setup: MIAS() });
    assert.deepStrictEqual(again.result, { answer: 'granted' });
    assert.strictEqual(turnOf(again, 'h-1').device, MIA);
  });

  it('play is busy with the holder\'s label and last seen when another device holds it, as a plain 200 answer that changes nothing', () => {
    const res = play('h-1', TABLET, 'the family tablet', { setup: MIAS() });
    assert.deepStrictEqual(res.result, { answer: 'busy', device: { label: "Mia's iPhone", last_seen_at: iso(NOW - 150) } });
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: MIA, label: "Mia's iPhone", taken: stamp(NOW - 3600), seen: stamp(NOW - 150) });
  });

  it('play with force takes the turn from the holder', () => {
    const res = play('h-1', TABLET, 'the family tablet', { force: true, setup: MIAS() });
    assert.deepStrictEqual(res.result, { answer: 'granted' });
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: TABLET, label: 'the family tablet', taken: stamp(NOW), seen: stamp(NOW) });
  });

  it('play for a hero that is not there, or was removed, is a 404 xp_family_no_hero', () => {
    const unknown = play('h-9', TABLET, 'the family tablet');
    assert.deepStrictEqual(unknown.result, { error: 'xp_family_no_hero', status: 404 });
    const removed = play('h-3', TABLET, 'the family tablet', { setup: "$db->add_hero( 'fam-1', 'h-3', [ 'removed_at' => '2026-10-02 00:00:00' ] );" });
    assert.deepStrictEqual(removed.result, { error: 'xp_family_no_hero', status: 404 });
  });

  it('play passes the store\'s 503 xp_family_busy through when the family is busy', () => {
    const res = play('h-1', TABLET, 'the family tablet', { setup: '$db->lock_free = false;' });
    assert.deepStrictEqual(res.result, { error: 'xp_family_busy', status: 503 });
    assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
  });

  it('a hero key plays its own hero as its own phone, whatever device the request names, and cannot play another hero', () => {
    const own = play('h-1', TABLET, 'the family tablet', { caller: kid('h-1', MIA), setup: MIAS() });
    assert.deepStrictEqual(own.result, { answer: 'granted' }, 'the key\'s device is Mia\'s and Mia holds the turn');

    const borrow = play('h-1', MIA, 'sneaky', { caller: kid('h-1', DAD), setup: MIAS() });
    assert.deepStrictEqual(borrow.result.answer, 'busy', 'naming the holder\'s device does not make a phone the holder');

    const other = play('h-2', MIA, "Mia's iPhone", { caller: kid('h-1', MIA) });
    assert.deepStrictEqual(other.result, { error: 'xp_family_scope', status: 403 });
    assert.deepStrictEqual(turnOf(other, 'h-2'), FREE);
    assert.deepStrictEqual(other.log, [], 'refused before the database is asked');
  });

  it('play cleans a parent device\'s label: tags go, it is cut to 60 characters, and an empty one is "the family device"', () => {
    const tagged = play('h-1', TABLET, '<b>Dad\'s</b>   <script>alert(1)</script>tablet');
    assert.strictEqual(turnOf(tagged, 'h-1').label, "Dad's alert(1)tablet");
    const long = play('h-1', TABLET, 'x'.repeat(80));
    assert.strictEqual(turnOf(long, 'h-1').label, 'x'.repeat(60));
    for (const empty of ['   ', '', '<b></b>']) {
      assert.strictEqual(turnOf(play('h-1', TABLET, empty), 'h-1').label, 'the family device', JSON.stringify(empty));
    }
  });

  it('a hero key\'s busy-line label is the one its phone was linked with, never the one in the request', () => {
    const own = play('h-1', DAD, 'the family tablet', { caller: kid('h-1', MIA, "Mia's iPhone") });
    assert.deepStrictEqual(own.result, { answer: 'granted' });
    assert.strictEqual(turnOf(own, 'h-1').label, "Mia's iPhone", 'a kid phone cannot pass itself off as the family tablet');

    const forced = play('h-1', DAD, 'the family tablet', { caller: kid('h-1', MIA, 'Mia <b>phone</b>'), force: true, setup: held('h-1', TABLET, 'the family tablet', NOW - 60, NOW - 60) });
    assert.strictEqual(turnOf(forced, 'h-1').label, 'Mia phone', 'the linked label is cleaned like any other');

    const after = route(`
      Xophz_Compass_Xp_Family_Records::play( $store, $family, ${lit(kid('h-1', MIA, "Mia's iPhone"))}, 'h-1', '${DAD}', 'the family tablet', false, ${NOW} );
      $result = Xophz_Compass_Xp_Family_Records::play( $store, $family, ${lit(PARENT_CALLER)}, 'h-1', '${TABLET}', 'the family tablet', false, ${NOW} );`);
    assert.deepStrictEqual(after.result, { answer: 'busy', device: { label: "Mia's iPhone", last_seen_at: iso(NOW) } }, 'what the parent is told');

    const unnamed = play('h-1', MIA, 'whatever', { caller: kid('h-1', MIA, '') });
    assert.strictEqual(turnOf(unnamed, 'h-1').label, 'A hero phone', 'a phone linked without a label gets the phone default');
  });

  it('play and rest refuse a device id or a hero id of the wrong shape with 400', () => {
    for (const device of ['', 'short', 'sixteen-chars-xx']) {
      assert.deepStrictEqual(play('h-1', device, 'tablet').result, { error: 'xp_family_bad_request', status: 400 }, device);
      assert.deepStrictEqual(rest('h-1', device).result, { error: 'xp_family_bad_request', status: 400 }, device);
    }
    assert.deepStrictEqual(play('h 1', TABLET, 'tablet').result, { error: 'xp_family_bad_request', status: 400 });
    assert.deepStrictEqual(rest('', TABLET).result, { error: 'xp_family_bad_request', status: 400 });
  });

  it('play and rest answer an unentitled family 403 xp_family_pro_required before the database is touched', () => {
    for (const licences of [null, {}, { 'fam-1': { status: 'revoked', expires_at: null, plan: 'yearly' } }]) {
      for (const res of [play('h-1', TABLET, 'tablet', { licences }), rest('h-1', TABLET, { licences }), play('h-1', MIA, 'phone', { licences, caller: kid() })]) {
        assert.deepStrictEqual(res.result, proRequired, JSON.stringify(licences));
        assert.deepStrictEqual(res.log, []);
        assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
      }
    }
  });

  it('rest gives the turn back when the asker holds it, and answers rested', () => {
    const res = rest('h-1', MIA, { setup: MIAS() });
    assert.deepStrictEqual(res.result, { answer: 'rested' });
    assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
  });

  it('a late rest from a device that lost the turn answers rested and leaves the new holder alone', () => {
    const res = rest('h-1', MIA, { setup: held('h-1', TABLET, 'the family tablet', NOW - 60, NOW - 30) });
    assert.deepStrictEqual(res.result, { answer: 'rested' });
    assert.deepStrictEqual(turnOf(res, 'h-1'), { device: TABLET, label: 'the family tablet', taken: stamp(NOW - 60), seen: stamp(NOW - 30) });
    const nobody = rest('h-9', MIA);
    assert.deepStrictEqual(nobody.result, { answer: 'rested' }, 'a hero that is not there has nothing to give back');
  });

  it('a hero key rests its own hero as its own phone, and cannot rest another hero', () => {
    const own = rest('h-1', TABLET, { caller: kid('h-1', MIA), setup: MIAS() });
    assert.deepStrictEqual(own.result, { answer: 'rested' });
    assert.deepStrictEqual(turnOf(own, 'h-1'), FREE, 'the key\'s device held it, and the device in the request did not matter');

    const other = rest('h-2', MIA, { caller: kid('h-1', MIA), setup: held('h-2', MIA, 'Mia too', NOW - 60, NOW - 60) });
    assert.deepStrictEqual(other.result, { error: 'xp_family_scope', status: 403 });
    assert.strictEqual(turnOf(other, 'h-2').device, MIA);
  });

  describe('Hero turn: the handlers', () => {
    const PLAY = '$result = $rest->play( $req );';
    const REST = '$result = $rest->rest( $req );';
    const asks = (body, url = { hero_id: 'h-1' }, query = {}) =>
      `$req->url = ${lit(url)}; $req->query = ${lit(query)}; $req->body = ${lit(body)};`;

    it('POST /heroes/{id}/play takes the hero from the path, never from the body or the query', () => {
      const res = route(PLAY, { who: PARENT_CALLER, request: asks({ device_id: TABLET, label: 'the family tablet', hero_id: 'h-2' }, { hero_id: 'h-1' }, { hero_id: 'h-2' }) });
      assert.deepStrictEqual(res.result, { answer: 'granted' });
      assert.strictEqual(turnOf(res, 'h-1').device, TABLET);
      assert.deepStrictEqual(turnOf(res, 'h-2'), FREE, 'the hero_id in the body is ignored');
    });

    it('POST /heroes/{id}/rest takes the hero from the path, never from the body or the query', () => {
      const setup = `${held('h-1', TABLET, 'the family tablet', NOW - 60, NOW - 60)} ${held('h-2', TABLET, 'the family tablet', NOW - 60, NOW - 60)}`;
      const res = route(REST, { who: PARENT_CALLER, setup, request: asks({ device_id: TABLET, hero_id: 'h-2' }, { hero_id: 'h-1' }, { hero_id: 'h-2' }) });
      assert.deepStrictEqual(res.result, { answer: 'rested' });
      assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
      assert.strictEqual(turnOf(res, 'h-2').device, TABLET, 'the other hero keeps its turn');
    });

    it('POST /heroes/{id}/play from a hero key uses the linked label, not the body\'s', () => {
      const res = route(PLAY, { who: kid('h-1', MIA, "Mia's iPhone"), request: asks({ device_id: TABLET, label: 'the family tablet' }) });
      assert.deepStrictEqual(res.result, { answer: 'granted' });
      const turn = turnOf(res, 'h-1');
      assert.deepStrictEqual([turn.device, turn.label], [MIA, "Mia's iPhone"], 'the device and label are the key\'s; the handler stamps the real clock');
    });

    it('a hero key is held to the hero of the path, not to a hero_id in the body', () => {
      const res = route(PLAY, { who: kid('h-1', MIA), request: asks({ device_id: MIA, label: "Mia's iPhone", hero_id: 'h-1' }, { hero_id: 'h-2' }) });
      assert.deepStrictEqual(res.result, { error: 'xp_family_scope', status: 403 });
      assert.deepStrictEqual(turnOf(res, 'h-2'), FREE);
    });

    it('force counts only when it is true, "true" or 1', () => {
      const values = [true, 'true', 1, '1', false, 'false', 0, '0', 'no', '', null, [], 'maybe'];
      const res = route(`
        $result = [];
        foreach ( ${lit(values)} as $force ) {
          ${MIAS()}
          $req = new StubRequest();
          $req->url  = [ 'hero_id' => 'h-1' ];
          $req->body = [ 'device_id' => '${TABLET}', 'label' => 'the family tablet', 'force' => $force ];
          $result[] = $rest->play( $req )['answer'];
        }`, { who: PARENT_CALLER });
      assert.deepStrictEqual(res.result, ['granted', 'granted', 'granted', 'granted', ...Array(9).fill('busy')]);
    });

    it('a parent names its device in the body, and a missing one is a 400', () => {
      const res = route(PLAY, { who: PARENT_CALLER, request: asks({ label: 'the family tablet' }) });
      assert.deepStrictEqual(res.result, { error: 'xp_family_bad_request', status: 400 });
      assert.deepStrictEqual(turnOf(res, 'h-1'), FREE);
    });
  });
});

describe('Hero turn: the files', () => {
  it('files carry no em dashes', () => {
    for (const rel of ['family-turn.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
