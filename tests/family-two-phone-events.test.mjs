/**
 * Two-phone play events (MP7): quest.claimed (from the home device or the hero's own phone),
 * quest.approved, quest.rejected, hero.sheet and claim.settled. Pure PHP like
 * family-event-rules.test.mjs, whose helpers it shares. The last suite sends a batch through
 * Family_Events::send with a small in-memory stand-in for wpdb, to see a resent claim come back
 * as a duplicate and a phone's wrong events come back in rejected.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import { RULES, CLS, DEVICE, lit, wireEvent, context, checks, dataOfBytes } from './family-event-rules.test.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PLUGIN = '/var/www/html/wp-content/plugins/xophz-compass-xp';

const uuid = (n) => `5b1d0f3a-2c4e-4a6b-8c9d-${String(n).padStart(12, '0')}`;
const phone = (over = {}) => context({ writer: 'hero', key_hero_id: 'h-1', ...over });

const STATS = { level: 4, xp: 120, nextLevelXp: 300, gp: 55, ap: 9, hp: 30, maxHp: 40, mp: 8, maxMp: 12 };
const quest = (n, over = {}) => ({
  questId: `q-${n}`, name: `Chore ${n}`, difficulty: 2, xp: 40, gp: 5, ap: 1, needsApproval: false, photo: false, overdue: false, ...over,
});

/** Data a kind accepts, for loops over the kinds. */
const SAMPLE = {
  'quest.claimed': { questId: 'q-1', difficulty: 2, requiresApproval: true },
  'quest.approved': { questId: 'q-1', note: '' },
  'quest.rejected': { questId: 'q-1', note: 'Not tidy yet' },
  'hero.sheet': { stats: STATS, quests: [quest(1)] },
  'claim.settled': { claimId: uuid(7), questId: 'q-1', status: 'approved', note: '', xp: 40, gp: 5, ap: 1 },
};
const ev = (kind, data, over = {}) => wireEvent({ kind, data, ...over });
const sample = (kind, over = {}) => ev(kind, SAMPLE[kind], over);
const check = (e, ctx = context()) => checks([[e, ctx]])[0];

/** Run filter_data once per [kind, data] row. */
function filters(rows) {
  return runPhpJson(`${RULES}
    $out = [];
    foreach ( ${lit(rows)} as $row ) {
      $kept  = ${CLS}::filter_data( $row[0], $row[1] );
      $out[] = [] === $kept ? new stdClass() : $kept;
    }
    echo json_encode( $out );`);
}

describe('Two-phone events: the kinds', () => {
  it('quest.claimed names two writers, the other four kinds name one', () => {
    const kinds = runPhpJson(`${RULES} echo json_encode( ${CLS}::KINDS );`);
    assert.deepStrictEqual(kinds['quest.claimed'], {
      scope: 'family',
      writer: ['home', 'hero'],
      fields: { questId: 'str64', difficulty: 'int', requiresApproval: 'bool' },
    });
    assert.deepStrictEqual(kinds['quest.approved'], { scope: 'family', writer: 'home', fields: { questId: 'str64', note: 'str140' } });
    assert.deepStrictEqual(kinds['quest.rejected'], { scope: 'family', writer: 'home', fields: { questId: 'str64', note: 'str140' } });
    assert.deepStrictEqual(kinds['hero.sheet'], {
      scope: 'family', writer: 'home', max_bytes: 16384, fields: { stats: 'stats', quests: 'quests' },
    });
    assert.deepStrictEqual(kinds['claim.settled'], {
      scope: 'family',
      writer: 'home',
      fields: { claimId: 'str64', questId: 'str64', status: 'enum:pending|approved|rejected', note: 'str140', xp: 'int', gp: 'int', ap: 'int' },
    });
  });

  it('only hero.sheet has its own size cap; every other kind uses the 8192 default', () => {
    const kinds = runPhpJson(`${RULES} echo json_encode( ${CLS}::KINDS );`);
    const capped = Object.keys(kinds).filter((k) => 'max_bytes' in kinds[k]);
    assert.deepStrictEqual(capped, ['hero.sheet']);
  });
});

describe('Two-phone events: quest.claimed and the writer rule', () => {
  it('is accepted from the home device, filtered, with family scope', () => {
    const res = check(sample('quest.claimed', { data: { ...SAMPLE['quest.claimed'], name: 'Take your medicine', gold: 5 } }));
    assert.strictEqual(res.ok, true, JSON.stringify(res));
    assert.strictEqual(res.event.scope, 'family');
    assert.strictEqual(res.event.kind, 'quest.claimed');
    assert.deepStrictEqual(res.event.data, SAMPLE['quest.claimed']);
  });

  it('is accepted from the hero key for its own hero', () => {
    const res = check(sample('quest.claimed'), phone());
    assert.strictEqual(res.ok, true, JSON.stringify(res));
    assert.strictEqual(res.event.hero_id, 'h-1');
    assert.strictEqual(res.event.scope, 'family');
    assert.deepStrictEqual(res.event.data, SAMPLE['quest.claimed']);
  });

  it('a hero key cannot claim for another hero, a stranger or without a key hero', () => {
    const res = checks([
      [sample('quest.claimed', { hero_id: 'h-2' }), phone()],
      [sample('quest.claimed', { hero_id: 'h-9' }), phone()],
      [sample('quest.claimed', { hero_id: 'h-9' }), phone({ key_hero_id: 'h-9' })],
      [sample('quest.claimed'), phone({ key_hero_id: null })],
      [sample('quest.claimed', { hero_id: 'h-1' }), phone({ key_hero_id: 'h-2' })],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, false, false, false, false]);
    for (const r of res) assert.strictEqual(r.code, 'xp_family_event_invalid');
    // The key check is what stops a sibling's claim, not some other rule.
    assert.deepStrictEqual(res.map((r) => r.message), [
      'A hero phone can only send events for its own hero.',
      'That hero is not in this family.',
      'That hero is not in this family.',
      'A hero phone can only send events for its own hero.',
      'A hero phone can only send events for its own hero.',
    ]);
  });

  it('the home device claims for any hero of the family', () => {
    const res = checks([
      [sample('quest.claimed', { hero_id: 'h-1' }), context()],
      [sample('quest.claimed', { hero_id: 'h-2' }), context()],
      [sample('quest.claimed', { hero_id: 'h-9' }), context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [true, true, false]);
  });

  it('the server may not write quest.claimed', () => {
    const [res] = checks([[sample('quest.claimed', { device_id: 'server' }), context({ writer: 'server' })]]);
    assert.strictEqual(res.ok, false);
  });

  it('a hero key cannot send any other kind, for itself', () => {
    const kinds = Object.keys(runPhpJson(`${RULES} echo json_encode( ${CLS}::KINDS );`)).filter((k) => k !== 'quest.claimed');
    assert.ok(kinds.length >= 8, `kinds: ${kinds}`);
    const other = { nickname: 'Mia', jobClass: 'Knight', level: 3, difficulty: 2, xp: 5, ap: 1, fight: false, reason: 'x', sourceId: 's' };
    const res = checks(kinds.map((k) => [ev(k, SAMPLE[k] ?? other, { hero_id: 'h-1' }), phone()]));
    assert.deepStrictEqual(res.map((r) => r.ok), kinds.map(() => false), kinds.join(','));
    // The writer rule is what stops them, not a bad field or a missing hero.
    assert.deepStrictEqual(res.map((r) => r.message), kinds.map(() => 'This caller may not write that kind of event.'));
  });

  it('the home device writes each of the five kinds', () => {
    const res = checks(Object.keys(SAMPLE).map((k) => [sample(k), context()]));
    assert.deepStrictEqual(res.map((r) => r.ok), [true, true, true, true, true], JSON.stringify(res));
    assert.deepStrictEqual(res.map((r) => r.event.scope), ['family', 'family', 'family', 'family', 'family']);
  });

  it('requiresApproval and difficulty keep their types; a long quest id is dropped, not cut', () => {
    const res = filters([
      ['quest.claimed', { questId: 'q'.repeat(64), difficulty: 3, requiresApproval: false }],
      ['quest.claimed', { questId: 'q'.repeat(65), difficulty: '3', requiresApproval: 'yes' }],
      ['quest.claimed', { difficulty: 99999999 }],
    ]);
    assert.deepStrictEqual(res, [{ questId: 'q'.repeat(64), difficulty: 3, requiresApproval: false }, {}, { difficulty: 100000 }]);
  });
});

describe('Two-phone events: quest.approved and quest.rejected', () => {
  it('keep the quest id and a note of up to 140 characters; 141 is dropped, not cut', () => {
    for (const kind of ['quest.approved', 'quest.rejected']) {
      const res = filters([
        [kind, { questId: 'q-1', note: 'n'.repeat(140) }],
        [kind, { questId: 'q-1', note: 'n'.repeat(141) }],
        [kind, { questId: 'q-1', note: '\u{1F600}'.repeat(140) }],
        [kind, { questId: 'q-1', note: '' }],
        [kind, { questId: 'q-1', note: 5 }],
        [kind, { questId: 'q'.repeat(65), note: 'ok', reviewer: 'Dad' }],
      ]);
      assert.deepStrictEqual(res, [
        { questId: 'q-1', note: 'n'.repeat(140) },
        { questId: 'q-1' },
        { questId: 'q-1', note: '\u{1F600}'.repeat(140) },
        { questId: 'q-1', note: '' },
        { questId: 'q-1' },
        { note: 'ok' },
      ], kind);
    }
  });

  it('are accepted from the home device and refused from a hero key', () => {
    const res = checks([
      [sample('quest.approved'), context()],
      [sample('quest.rejected'), context()],
      [sample('quest.approved'), phone()],
      [sample('quest.rejected'), phone()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [true, true, false, false]);
    assert.strictEqual(res[1].event.data.note, 'Not tidy yet');
  });
});

describe('Two-phone events: hero.sheet', () => {
  it('keeps the stats and the quests it lists, in order, and drops unknown keys', () => {
    const res = check(ev('hero.sheet', {
      stats: { ...STATS, secret: 'x', birthday: '2014-05-05' },
      quests: [{ ...quest(1), category: 'health', parentNote: 'private' }, quest(2, { needsApproval: true, photo: true, overdue: true })],
      extra: 1,
    }));
    assert.strictEqual(res.ok, true, JSON.stringify(res));
    assert.deepStrictEqual(Object.keys(res.event.data), ['stats', 'quests']);
    assert.deepStrictEqual(res.event.data.stats, STATS);
    assert.deepStrictEqual(Object.keys(res.event.data.stats), Object.keys(STATS));
    assert.deepStrictEqual(res.event.data.quests, [quest(1), quest(2, { needsApproval: true, photo: true, overdue: true })]);
    assert.deepStrictEqual(Object.keys(res.event.data.quests[0]), Object.keys(quest(1)));
    assert.strictEqual(res.event.scope, 'family');
  });

  it('drops a stat or a quest field of the wrong type and a name of 81 characters', () => {
    const [res] = filters([[
      'hero.sheet',
      {
        stats: { ...STATS, level: '4', xp: -3, gp: 999999, hp: true },
        quests: [quest(1, { name: 'n'.repeat(80) }), quest(2, { name: 'n'.repeat(81), xp: '40', needsApproval: 'yes', ap: 3 })],
      },
    ]]);
    assert.deepStrictEqual(res.stats, { xp: 0, nextLevelXp: 300, gp: 100000, ap: 9, maxHp: 40, mp: 8, maxMp: 12 });
    assert.strictEqual(res.quests[0].name.length, 80);
    assert.deepStrictEqual(res.quests[1], { questId: 'q-2', difficulty: 2, gp: 5, ap: 3, photo: false, overdue: false });
  });

  it('drops a quest with no usable id, and anything that is not a list of objects', () => {
    const res = filters([
      ['hero.sheet', { quests: [quest(1), { name: 'no id' }, 'q-3', 7, null, [], quest(5, { questId: 'q'.repeat(65) }), quest(6, { questId: '' })] }],
      ['hero.sheet', { quests: { a: quest(1) } }],
      ['hero.sheet', { quests: 'q-1' }],
      ['hero.sheet', { quests: [] }],
      ['hero.sheet', { stats: [1, 2, 3] }],
      ['hero.sheet', { stats: { secret: 1 } }],
      ['hero.sheet', { stats: 'strong' }],
      ['hero.sheet', { stats: {}, quests: [] }],
    ]);
    assert.deepStrictEqual(res, [{ quests: [quest(1)] }, {}, {}, { quests: [] }, {}, {}, {}, { quests: [] }]);
  });

  it('tells a list from an object as array_is_list does, without needing PHP 8.1', () => {
    // A JSON object with the keys "0" and "1" decodes to a list; one with a gap or other keys does not.
    const shapes = [{}, [], [1, 2], { 0: 'a', 1: 'b' }, { 1: 'a', 2: 'b' }, { 0: 'a', 2: 'b' }, { 2: 'a', 1: 'b' }, { a: 1 }, { 0: 'a', x: 'b' }];
    const res = runPhpJson(`${RULES}
      $m   = new ReflectionMethod( '${CLS}', 'is_list' );
      $out = [];
      foreach ( ${lit(shapes)} as $v ) { $out[] = [ $m->invoke( null, $v ), array_is_list( $v ) ]; }
      echo json_encode( $out );`);
    for (const [mine, theirs] of res) assert.strictEqual(mine, theirs);
    assert.deepStrictEqual(res.map(([mine]) => mine), [true, true, true, true, false, false, false, false, false]);
    assert.ok(!fs.readFileSync(path.join(HERE, '../includes/family/class-xophz-compass-xp-family-event-rules.php'), 'utf8').includes('array_is_list('), 'the rules call no PHP 8.1 only function');
  });

  it('reads keys "0" and "1" as a list in the data, the stats and the quests', () => {
    const [asData] = checks([[ev('quest.done', { 0: 1, 1: 2 }), context()]]);
    assert.strictEqual(asData.ok, false);
    assert.strictEqual(asData.message, 'The data must be a JSON object.');
    const res = filters([
      ['hero.sheet', { stats: { 0: 4, 1: 5 } }],
      ['hero.sheet', { quests: { 0: quest(1), 1: quest(2) } }],
      ['hero.sheet', { quests: { 1: quest(1), 2: quest(2) } }],
    ]);
    assert.deepStrictEqual(res, [{}, { quests: [quest(1), quest(2)] }, {}]);
  });

  it('accepts 60 quests and refuses 61, the whole event', () => {
    const sheet = (n) => ev('hero.sheet', { stats: STATS, quests: Array.from({ length: n }, (_, i) => quest(i, { name: 'n' })) });
    const [sixty, over, many] = checks([[sheet(60), context()], [sheet(61), context()], [sheet(200), context()]]);
    assert.strictEqual(sixty.ok, true, JSON.stringify(sixty));
    assert.strictEqual(sixty.event.data.quests.length, 60);
    assert.strictEqual(over.ok, false);
    assert.strictEqual(over.code, 'xp_family_event_invalid');
    assert.match(over.message, /60 quests/);
    assert.strictEqual(many.ok, false);
  });

  it('counts the list as the device sent it: 60 quests and one stray entry is over the limit', () => {
    const sixty = Array.from({ length: 60 }, (_, i) => ({ questId: `q${i}` }));
    const res = checks([
      [ev('hero.sheet', { quests: [...sixty, 'junk'] }), context()],
      [ev('hero.sheet', { quests: [...sixty, { name: 'no id' }] }), context()],
      [ev('hero.sheet', { quests: sixty }), context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, false, true]);
  });

  it('carries about 16 KB: a full sheet of 60 long names is over 8 KB and is accepted whole', () => {
    const quests = Array.from({ length: 60 }, (_, i) => quest(i, { name: `${i}`.padEnd(80, 'x') }));
    const data = { stats: STATS, quests };
    const bytes = JSON.stringify(data).length;
    assert.ok(bytes > 8192 && bytes <= 16384, `sheet is ${bytes} bytes`);
    const [res, asDone] = checks([[ev('hero.sheet', data), context()], [ev('quest.approved', data), context()]]);
    assert.strictEqual(res.ok, true, JSON.stringify(res).slice(0, 300));
    assert.strictEqual(res.event.data.quests.length, 60);
    assert.strictEqual(asDone.ok, false, 'another kind keeps the 8192 cap');
  });

  it('refuses data over 16384 bytes and accepts exactly 16384', () => {
    const res = checks([
      [ev('hero.sheet', dataOfBytes(16385)), context()],
      [ev('hero.sheet', dataOfBytes(20000)), context()],
      [ev('hero.sheet', dataOfBytes(16384)), context()],
      [ev('hero.sheet', dataOfBytes(8193)), context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), [false, false, true, true]);
    assert.match(res[0].message, /16384 bytes/);
  });

  it('every other kind is still refused over 8192 bytes, and says 8192', () => {
    const kinds = ['quest.claimed', 'quest.approved', 'quest.rejected'];
    const res = checks(kinds.flatMap((k) => [[ev(k, dataOfBytes(8193)), context()], [ev(k, dataOfBytes(8192)), context()]]));
    assert.deepStrictEqual(res.map((r) => r.ok), [false, true, false, true, false, true]);
    assert.match(res[0].message, /8192 bytes/);
  });

  it('is refused from a hero key', () => {
    const [res] = checks([[sample('hero.sheet'), phone()]]);
    assert.strictEqual(res.ok, false);
  });
});

describe('Two-phone events: claim.settled', () => {
  const settled = (over) => ({ ...SAMPLE['claim.settled'], ...over });

  it('keeps its fields for each of the three statuses', () => {
    for (const status of ['pending', 'approved', 'rejected']) {
      const res = check(ev('claim.settled', settled({ status, note: 'Window used up', gp: 0, extra: 'x' })));
      assert.strictEqual(res.ok, true, `${status}: ${JSON.stringify(res)}`);
      assert.strictEqual(res.event.scope, 'family');
      assert.deepStrictEqual(res.event.data, settled({ status, note: 'Window used up', gp: 0 }));
    }
  });

  it('refuses a status outside pending, approved and rejected, or none at all', () => {
    const res = checks([
      [ev('claim.settled', settled({ status: 'paid' })), context()],
      [ev('claim.settled', settled({ status: 'APPROVED' })), context()],
      [ev('claim.settled', settled({ status: ' approved' })), context()],
      [ev('claim.settled', settled({ status: '' })), context()],
      [ev('claim.settled', settled({ status: 1 })), context()],
      [ev('claim.settled', settled({ status: ['approved'] })), context()],
      [ev('claim.settled', settled({ status: null })), context()],
      [ev('claim.settled', (({ status, ...rest }) => rest)(settled())), context()],
    ]);
    assert.deepStrictEqual(res.map((r) => r.ok), res.map(() => false));
    for (const r of res) assert.strictEqual(r.code, 'xp_family_event_invalid');
    assert.match(res[0].message, /status/);
  });

  it('drops the status field itself when it is outside the enum', () => {
    const res = filters([
      ['claim.settled', { claimId: 'c', status: 'paid', xp: 5 }],
      ['claim.settled', { status: 'pending' }],
    ]);
    assert.deepStrictEqual(res, [{ claimId: 'c', xp: 5 }, { status: 'pending' }]);
  });

  it('keeps a note of 140 characters and drops one of 141; drops long ids; clamps the pay', () => {
    const res = filters([
      ['claim.settled', settled({ note: 'n'.repeat(140) })],
      ['claim.settled', settled({ note: 'n'.repeat(141) })],
      ['claim.settled', settled({ claimId: 'c'.repeat(64), questId: 'q'.repeat(65) })],
      ['claim.settled', settled({ xp: -1, gp: 1e9, ap: '3' })],
    ]);
    assert.strictEqual(res[0].note, 'n'.repeat(140));
    assert.ok(!('note' in res[1]));
    assert.strictEqual(res[2].claimId, 'c'.repeat(64));
    assert.ok(!('questId' in res[2]));
    assert.strictEqual(res[3].xp, 0);
    assert.strictEqual(res[3].gp, 100000);
    assert.ok(!('ap' in res[3]));
  });

  it('is refused from a hero key', () => {
    const [res] = checks([[sample('claim.settled'), phone()]]);
    assert.strictEqual(res.ok, false);
  });
});

describe('Two-phone events: who reads them', () => {
  /** Check each kind as the home device would send it for h-1 or h-2, then ask readable_by. */
  function readers(kinds) {
    return runPhpJson(`${RULES}
      $samples = ${lit(kinds.map((k) => [sample(k, { hero_id: 'h-1' }), sample(k, { hero_id: 'h-2' })]))};
      $ctx = ${lit(context())};
      $out = [];
      foreach ( $samples as $pair ) {
        $row = [];
        foreach ( $pair as $e ) {
          $r = ${CLS}::check_event( $e, $ctx );
          $row[] = [
            'ok'     => $r['ok'],
            'parent' => ${CLS}::readable_by( $r['event'], 'parent', null ),
            'ownKey' => ${CLS}::readable_by( $r['event'], 'hero', 'h-1' ),
            'other'  => ${CLS}::readable_by( $r['event'], 'hero', 'h-2' ),
            'none'   => ${CLS}::readable_by( $r['event'], 'none', null ),
          ];
        }
        $out[] = $row;
      }
      echo json_encode( $out );`);
  }

  it('a phone reads its own hero.sheet, claim.settled and verdicts, not another hero\'s; the parent reads all', () => {
    const kinds = Object.keys(SAMPLE);
    const res = readers(kinds);
    res.forEach(([mine, theirs], i) => {
      assert.strictEqual(mine.ok && theirs.ok, true, kinds[i]);
      assert.deepStrictEqual([mine.parent, mine.ownKey, mine.other, mine.none], [true, true, false, false], `${kinds[i]} for h-1`);
      assert.deepStrictEqual([theirs.parent, theirs.ownKey, theirs.other, theirs.none], [true, false, true, false], `${kinds[i]} for h-2`);
    });
  });
});

describe('Two-phone events: a batch through send', () => {
  /** A small in-memory stand-in for wpdb: the few calls the event stream makes. */
  const STAND_IN = `
    require_once '${PLUGIN}/includes/family/class-xophz-compass-xp-family-store.php';
    require_once '${PLUGIN}/includes/family/class-xophz-compass-xp-family-events.php';
    if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
    class StandInDb {
      public $prefix = 'wp_';
      public $rows   = [];
      public function prepare( $sql, ...$args ) {
        return [ 'sql' => $sql, 'args' => ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args ];
      }
      public function get_var( $q ) { return false !== strpos( $q['sql'], 'GET_LOCK' ) ? '1' : null; }
      public function get_col( $q ) { return array_values( array_filter( $q['args'], fn( $id ) => isset( $this->rows[ $id ] ) ) ); }
      public function get_results( $q, $out = null ) {
        return false !== strpos( $q['sql'], 'xp_family_heroes' ) ? [ [ 'hero_id' => 'h-1' ], [ 'hero_id' => 'h-2' ] ] : [];
      }
      public function insert( $table, $data, $format ) { $this->rows[ $data['event_id'] ] = $data; return 1; }
    }
  `;

  /** Each call is [ 'hero' | 'home', events ]; one store across the calls. */
  function sends(calls) {
    return runPhpJson(`${RULES} ${STAND_IN}
      $db     = new StandInDb();
      $store  = new Xophz_Compass_Xp_Family_Store( $db );
      $family = [ 'family_id' => 'fam-1', 'home_device_id' => 'homedevice000001' ];
      $phone  = [ 'kind' => 'hero', 'device' => [ 'hero_id' => 'h-1', 'device_id' => '${DEVICE}' ] ];
      $home   = [ 'kind' => 'parent' ];
      $out    = [];
      foreach ( ${lit(calls)} as $call ) {
        $r = Xophz_Compass_Xp_Family_Events::send( $store, $family, 'hero' === $call[0] ? $phone : $home, $call[1] );
        $out[] = is_wp_error( $r ) ? [ 'error' => $r->get_error_code() ] : $r;
      }
      echo json_encode( [ 'results' => $out, 'stored' => array_values( $db->rows ) ] );`);
  }

  const claim = (n, over = {}) => sample('quest.claimed', { id: uuid(n), ...over });

  it('a resent quest.claimed comes back as a duplicate and is stored once', () => {
    const { results, stored } = sends([['hero', [claim(1)]], ['hero', [claim(1)]], ['hero', [claim(1), claim(2)]]]);
    assert.deepStrictEqual(results[0], { accepted: [uuid(1)], duplicate: [], rejected: [] });
    assert.deepStrictEqual(results[1], { accepted: [], duplicate: [uuid(1)], rejected: [] });
    assert.deepStrictEqual(results[2], { accepted: [uuid(2)], duplicate: [uuid(1)], rejected: [] });
    assert.strictEqual(stored.length, 2);
    assert.strictEqual(stored[0].scope, 'family');
    assert.strictEqual(stored[0].device_id, DEVICE);
    assert.strictEqual(stored[0].hero_id, 'h-1');
  });

  it('a phone\'s wrong events are rejected one by one, with no 4xx for the batch', () => {
    const { results, stored } = sends([['hero', [
      claim(3),
      sample('hero.sheet', { id: uuid(4), device_id: DEVICE }),
      claim(5, { hero_id: 'h-2' }),
      sample('claim.settled', { id: uuid(6), device_id: DEVICE }),
      claim(8, { device_id: 'anotherphone0001' }),
    ]]]);
    const [res] = results;
    assert.deepStrictEqual(res.accepted, [uuid(3)]);
    assert.deepStrictEqual(res.duplicate, []);
    assert.deepStrictEqual(res.rejected.map((r) => r.id), [uuid(4), uuid(5), uuid(6), uuid(8)]);
    for (const r of res.rejected) assert.strictEqual(r.code, 'xp_family_event_invalid');
    assert.strictEqual(stored.length, 1);
  });

  it('the home device sends all five kinds in one batch', () => {
    const home = { device_id: 'homedevice000001' };
    const { results } = sends([['home', Object.keys(SAMPLE).map((k, i) => sample(k, { id: uuid(20 + i), ...home }))]]);
    assert.strictEqual(results[0].accepted.length, 5, JSON.stringify(results[0]));
    assert.deepStrictEqual(results[0].rejected, []);
  });
});

describe('Two-phone events: the files', () => {
  it('files carry no em dashes', () => {
    for (const rel of ['family-two-phone-events.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
