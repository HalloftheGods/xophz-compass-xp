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
import { storeRun } from './family-store-fake.mjs';

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

describe('Hero turn: the files', () => {
  it('files carry no em dashes', () => {
    for (const rel of ['family-turn.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
