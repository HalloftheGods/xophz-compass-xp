/**
 * Party store (parties and raids): the rows of the party tables, run as real SQL on the SQLite stand-in for wpdb
 * (party-db-fake.mjs). The store decides nothing, so these cases check what it keeps and what it hands back.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';
import { runStore } from './party-db-fake.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLASS_REL = '../includes/party/class-xophz-compass-xp-party-store.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);

const NOW = 1793174400;

/** A store over a fresh database, a party "p1" led by f1|ana, and the helpers the cases share. */
const SETUP = `
$db = new Fake_Party_Db();
$store = new Xophz_Compass_Xp_Party_Store( $db );
$card = function ( $hero, $nick = 'Hero' ) { return [ 'heroId' => $hero, 'nickname' => $nick, 'level' => 3, 'jobClass' => 'knight', 'look' => null ]; };
$store->create_party( [ 'party_id' => 'p1', 'name' => 'Raiders', 'emblem' => 'dragon', 'code' => 'ABCD2345', 'invite_rule' => 'leader', 'leader_family_id' => 'f1', 'leader_hero_id' => 'ana', 'now' => ${NOW} ] );
`;
const run = (body) => runStore(`${SETUP}\n${body}`);

describe('Party store: time', () => {
  it('writes UTC datetimes and reads them back as unix seconds', () => {
    const res = runStore(`echo json_encode( [ Xophz_Compass_Xp_Party_Store::db_time( ${NOW} ), Xophz_Compass_Xp_Party_Store::unix( '2026-10-28 08:00:00' ), Xophz_Compass_Xp_Party_Store::unix( null ), Xophz_Compass_Xp_Party_Store::unix( '' ) ] );`);
    assert.deepStrictEqual(res, ['2026-10-28 08:00:00', NOW, null, null]);
  });
});

describe('Party store: parties', () => {
  it('finds a party by id and by code, and knows a code is taken', () => {
    const res = run(`
      $p = $store->party( 'p1' );
      echo json_encode( [ $p['name'], $p['code'], $p['leader_family_id'], $p['leader_hero_id'], $p['invite_rule'], $store->party_by_code( 'ABCD2345' )['party_id'], $store->code_taken( 'ABCD2345' ), $store->code_taken( 'ZZZZ2345' ), $store->party( 'nope' ), $store->party_by_code( 'ZZZZ2345' ) ] );`);
    assert.deepStrictEqual(res, ['Raiders', 'ABCD2345', 'f1', 'ana', 'leader', 'p1', true, false, null, null]);
  });

  it('changes the code, so the old one finds nothing', () => {
    const res = run(`
      $store->set_code( 'p1', 'WXYZ6789' );
      echo json_encode( [ $store->party_by_code( 'ABCD2345' ), $store->party_by_code( 'WXYZ6789' )['party_id'] ] );`);
    assert.deepStrictEqual(res, [null, 'p1']);
  });

  it('changes the leader', () => {
    const res = run(`
      $store->set_leader( 'p1', 'f2', 'ben' );
      $p = $store->party( 'p1' );
      echo json_encode( [ $p['leader_family_id'], $p['leader_hero_id'] ] );`);
    assert.deepStrictEqual(res, ['f2', 'ben']);
  });

  it('hides a disbanded party from every lookup', () => {
    const res = run(`
      $store->disband( 'p1', ${NOW} );
      echo json_encode( [ $store->party( 'p1' ), $store->party_by_code( 'ABCD2345' ) ] );`);
    assert.deepStrictEqual(res, [null, null]);
  });
});

describe('Party store: members', () => {
  it('adds members and lists the active ones earliest joined first, cards decoded', () => {
    const res = run(`
      $store->add_member( 'p1', 'f1', 'ana', $card( 'ana', 'Ana' ), 'leader', ${NOW} );
      $store->add_member( 'p1', 'f2', 'ben', $card( 'ben', 'Ben' ), 'member', ${NOW + 60} );
      $list = $store->members( 'p1' );
      echo json_encode( [ array_map( function ( $m ) { return [ $m['family_id'], $m['hero_id'], $m['role'], $m['card']['nickname'], $m['joined_at'] ]; }, $list ), $store->member( 'p1', 'f2', 'ben' )['card']['level'], $store->member( 'p1', 'f2', 'nobody' ) ] );`);
    assert.deepStrictEqual(res, [[['f1', 'ana', 'leader', 'Ana', NOW], ['f2', 'ben', 'member', 'Ben', NOW + 60]], 3, null]);
  });

  it('keeps one row per hero: leaving and joining again brings the same row back', () => {
    const res = run(`
      $store->add_member( 'p1', 'f2', 'ben', $card( 'ben', 'Ben' ), 'member', ${NOW} );
      $left = $store->remove_member( 'p1', 'f2', 'ben', ${NOW + 10} );
      $again = $store->remove_member( 'p1', 'f2', 'ben', ${NOW + 20} );
      $gone = count( $store->members( 'p1' ) );
      $store->add_member( 'p1', 'f2', 'ben', $card( 'ben', 'Benny' ), 'member', ${NOW + 30} );
      $m = $store->members( 'p1' );
      echo json_encode( [ $left, $again, $gone, count( $m ), $m[0]['card']['nickname'], $m[0]['joined_at'] ] );`);
    assert.deepStrictEqual(res, [true, false, 0, 1, 'Benny', NOW + 30]);
  });

  it('changes the role of one member without touching when they joined', () => {
    const res = run(`
      $store->add_member( 'p1', 'f1', 'ana', $card( 'ana' ), 'leader', ${NOW} );
      $store->add_member( 'p1', 'f2', 'ben', $card( 'ben' ), 'member', ${NOW + 60} );
      $store->set_role( 'p1', 'f2', 'ben', 'leader' );
      $store->set_role( 'p1', 'f1', 'ana', 'member' );
      $m = $store->members( 'p1' );
      echo json_encode( array_map( function ( $x ) { return [ $x['hero_id'], $x['role'], $x['joined_at'] ]; }, $m ) );`);
    assert.deepStrictEqual(res, [['ana', 'member', NOW], ['ben', 'leader', NOW + 60]]);
  });

  it('lists the parties a family, or one hero of it, is in', () => {
    const res = run(`
      $store->create_party( [ 'party_id' => 'p2', 'name' => 'Other', 'emblem' => 'star', 'code' => 'QRST2345', 'invite_rule' => 'leader', 'leader_family_id' => 'f3', 'leader_hero_id' => 'cam', 'now' => ${NOW} ] );
      $store->add_member( 'p1', 'f2', 'ben', $card( 'ben' ), 'member', ${NOW} );
      $store->add_member( 'p2', 'f2', 'dot', $card( 'dot' ), 'member', ${NOW} );
      $store->add_member( 'p2', 'f3', 'cam', $card( 'cam' ), 'leader', ${NOW} );
      $family = $store->party_ids_of( 'f2' ); sort( $family );
      echo json_encode( [ $family, $store->party_ids_of( 'f2', 'ben' ), $store->party_ids_of( 'f2', 'nobody' ), $store->party_ids_of( 'f9' ) ] );`);
    assert.deepStrictEqual(res, [['p1', 'p2'], ['p1'], [], []]);
  });
});

describe('Party store: join requests', () => {
  const MAKE = `$store->create_request( [ 'request_id' => 'r1', 'party_id' => 'p1', 'family_id' => 'f2', 'hero_id' => 'ben', 'card' => $card( 'ben', 'Ben' ), 'now' => ${NOW} ] );`;

  it('keeps an open request and finds it by id, by hero and by party and family', () => {
    const res = run(`${MAKE}
      $r = $store->request( 'r1' );
      echo json_encode( [ $r['party_id'], $r['family_id'], $r['hero_id'], $r['card']['nickname'], $r['created_at'], $r['resolved_at'], $store->open_request_for( 'p1', 'f2', 'ben' )['request_id'], $store->open_request_for( 'p1', 'f2', 'dot' ), count( $store->open_requests_of_party( 'p1' ) ), count( $store->open_requests_of_family( 'f2' ) ), count( $store->open_requests_of_family( 'f1' ) ), $store->request( 'nope' ) ] );`);
    assert.deepStrictEqual(res, ['p1', 'f2', 'ben', 'Ben', NOW, null, 'r1', null, 1, 1, 0, null]);
  });

  it('settles a request once: a second settle changes nothing and the request leaves the open lists', () => {
    const res = run(`${MAKE}
      $first = $store->resolve_request( 'r1', 'accepted', ${NOW + 5} );
      $second = $store->resolve_request( 'r1', 'declined', ${NOW + 9} );
      $r = $store->request( 'r1' );
      echo json_encode( [ $first, $second, $r['resolution'], $r['resolved_at'], count( $store->open_requests_of_party( 'p1' ) ), $store->open_request_for( 'p1', 'f2', 'ben' ) ] );`);
    assert.deepStrictEqual(res, [true, false, 'accepted', NOW + 5, 0, null]);
  });
});

describe('Party store: events', () => {
  it('numbers a party events one by one with no gaps, each party apart', () => {
    const res = run(`
      $store->create_party( [ 'party_id' => 'p2', 'name' => 'Other', 'emblem' => 'star', 'code' => 'QRST2345', 'invite_rule' => 'leader', 'leader_family_id' => 'f3', 'leader_hero_id' => 'cam', 'now' => ${NOW} ] );
      $a = $store->append_event( 'p1', 'join', 'f1', 'ana', [ 'x' => 1 ], ${NOW} );
      $b = $store->append_event( 'p1', 'strike', 'f1', 'ana', [ 'damage' => 30 ], ${NOW + 1} );
      $c = $store->append_event( 'p2', 'join', 'f3', 'cam', [], ${NOW} );
      $d = $store->append_event( 'p1', 'preset', null, null, [ 'preset' => 'gg' ], ${NOW + 2} );
      echo json_encode( [ $a, $b, $c, $d, (int) $db->get_var( "SELECT events_seq FROM wp_5_xp_parties WHERE party_id = 'p1'" ) ] );`);
    assert.deepStrictEqual(res, [1, 2, 1, 3, 3]);
  });

  it('reads the events after a number, oldest first, at most a limit, data decoded', () => {
    const res = run(`
      for ( $i = 1; $i <= 5; $i++ ) { $store->append_event( 'p1', 'strike', 'f1', 'ana', [ 'n' => $i ], ${NOW} + $i ); }
      $after = $store->events_since( 'p1', 2, 10 );
      $capped = $store->events_since( 'p1', 0, 2 );
      echo json_encode( [ array_column( $after, 'seq' ), $after[0]['data'], $after[0]['kind'], $after[0]['family_id'], $after[0]['at'], array_column( $capped, 'seq' ), $store->events_since( 'p1', 5, 10 ) ] );`);
    assert.deepStrictEqual(res, [[3, 4, 5], { n: 3 }, 'strike', 'f1', NOW + 3, [1, 2], []]);
  });

  it('keeps an event with no hero as null', () => {
    const res = run(`
      $store->append_event( 'p1', 'raid', null, null, [], ${NOW} );
      $e = $store->events_since( 'p1', 0, 5 )[0];
      echo json_encode( [ $e['family_id'], $e['hero_id'] ] );`);
    assert.deepStrictEqual(res, [null, null]);
  });
});

describe('Party store: blocks', () => {
  it('blocks a party for one family, and blocking twice is harmless', () => {
    const res = run(`
      $store->block( 'f2', 'p1', ${NOW} );
      $store->block( 'f2', 'p1', ${NOW + 5} );
      echo json_encode( [ $store->is_blocked( 'f2', 'p1' ), $store->is_blocked( 'f3', 'p1' ), $store->is_blocked( 'f2', 'p2' ) ] );`);
    assert.deepStrictEqual(res, [true, false, false]);
  });
});

describe('Party store: raids and strikes', () => {
  const RAID = (id, at) => `$store->create_raid( [ 'raid_id' => '${id}', 'party_id' => 'p1', 'beast_id' => 'beast-fluffalo', 'window' => 'quick', 'max_hp' => 180, 'member_count' => 2, 'started_at' => ${at}, 'ends_at' => ${at} + 3 * 86400 ] );`;

  it('keeps a raid and answers the newest of a party', () => {
    const res = run(`${RAID('old', NOW)} ${RAID('new', NOW + 86400)}
      $r = $store->raid( 'new' );
      echo json_encode( [ $r['beast_id'], $r['window'], $r['max_hp'], $r['member_count'], $r['started_at'], $r['ends_at'], $store->latest_raid( 'p1' )['raid_id'], $store->latest_raid( 'p2' ), $store->raid( 'nope' ) ] );`);
    assert.deepStrictEqual(res, ['beast-fluffalo', 'quick', 180, 2, NOW + 86400, NOW + 4 * 86400, 'new', null, null]);
  });

  it('counts strikes in the shape the rules read, and takes one back', () => {
    const res = run(`${RAID('r1', NOW)}
      $store->add_strike( [ 'raid_id' => 'r1', 'strike_key' => 'k1', 'family_id' => 'f1', 'hero_id' => 'ana', 'damage' => 30, 'day' => '2026-10-28', 'at' => ${NOW + 60}, 'now' => ${NOW + 61} ] );
      $store->add_strike( [ 'raid_id' => 'r1', 'strike_key' => 'k2', 'family_id' => 'f2', 'hero_id' => 'ben', 'damage' => 20, 'day' => '2026-10-28', 'at' => ${NOW + 120}, 'now' => ${NOW + 121} ] );
      $rows = $store->strikes_of( 'r1' );
      $gone = $store->remove_strike( 'r1', 'k1' );
      $again = $store->remove_strike( 'r1', 'k1' );
      echo json_encode( [ $rows, $gone, $again, count( $store->strikes_of( 'r1' ) ), $store->strikes_of( 'other' ) ] );`);
    assert.deepStrictEqual(res[0][0], { strike_key: 'k1', family_id: 'f1', hero_id: 'ana', member: 'f1|ana', damage: 30, day: '2026-10-28', at: NOW + 60 });
    assert.strictEqual(res[0][1].member, 'f2|ben');
    assert.deepStrictEqual(res.slice(1), [true, false, 1, []]);
  });

  it('keeps keys that differ only by case apart', () => {
    const res = run(`${RAID('r1', NOW)}
      foreach ( [ 'Key', 'key' ] as $k ) { $store->add_strike( [ 'raid_id' => 'r1', 'strike_key' => $k, 'family_id' => 'f1', 'hero_id' => 'ana', 'damage' => 10, 'day' => '2026-10-28', 'at' => ${NOW}, 'now' => ${NOW} ] ); }
      echo json_encode( [ count( $store->strikes_of( 'r1' ) ) ] );`);
    assert.deepStrictEqual(res, [2]);
  });
});

describe('Party store: the party lock', () => {
  it('runs the work under the lock, releases it and answers what the work answers', () => {
    const res = run(`
      $answer = $store->locked( 'p1', function () { return [ 'done' => true ]; } );
      echo json_encode( [ $answer, array_values( array_filter( $db->log, function ( $l ) { return false !== strpos( $l, '_LOCK' ); } ) ) ] );`);
    assert.deepStrictEqual(res, [{ done: true }, ['GET_LOCK', 'RELEASE_LOCK']]);
  });

  it('answers 503 and runs nothing when the lock cannot be had', () => {
    const res = run(`
      $db->lock_free = false;
      $ran = false;
      $answer = $store->locked( 'p1', function () use ( &$ran ) { $ran = true; return 1; } );
      echo json_encode( [ is_wp_error( $answer ), $answer->get_error_code(), $answer->get_error_data()['status'], $ran ] );`);
    assert.deepStrictEqual(res, [true, 'xp_party_busy', 503, false]);
  });

  it('releases the lock even when the work throws', () => {
    const res = run(`
      try { $store->locked( 'p1', function () { throw new RuntimeException( 'boom' ); } ); } catch ( RuntimeException $e ) { $caught = $e->getMessage(); }
      echo json_encode( [ $caught, end( $db->log ) ] );`);
    assert.deepStrictEqual(res, ['boom', 'RELEASE_LOCK']);
  });
});

describe('Party store: the file', () => {
  it('is valid PHP', () => {
    const res = lintPhpFile(CLASS_FILE);
    assert.ok(res.isValid, res.output);
  });

  it('has no em dashes', () => {
    for (const rel of [CLASS_REL, 'party-store.test.mjs', 'party-db-fake.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
