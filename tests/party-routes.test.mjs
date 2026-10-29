/**
 * Party routes (parties and raids, xp-party/v1): who may do what, on the real store over the SQLite stand-in for wpdb.
 * Callers are passed in the request (a parent of a family, or a hero's own key), the clock is a variable, and the
 * XP Pro licence is a stub: f1, f2 and f3 hold it, f9 does not.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';
import { runRoutes } from './party-db-fake.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const MAIN_FILE = path.join(HERE, '../includes/class-xophz-compass-xp.php');
const CLASS_REL = '../includes/party/class-xophz-compass-xp-party-rest.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);

const NOW = 1793174400;
const DAY = 86400;
const iso = (unix) => new Date(unix * 1000).toISOString().replace('.000Z', 'Z');

const ALL_HANDLERS = ['form', 'join', 'resolve_request', 'remove_member', 'rotate_code', 'block', 'mine', 'feed', 'start_raid', 'strike', 'withdraw_strike', 'preset'];

/** The error code and status of a failed call, or the status of a good one. */
const failed = (code, status) => ({ error: code, status });

describe('Party routes: who is calling and the Pro gate', () => {
  it('turns away a family without XP Pro on every route', () => {
    const res = runRoutes(`
      $out = [];
      foreach ( ${JSON.stringify(ALL_HANDLERS)} as $h ) { $out[ $h ] = pcall( $h, [], parent_of( 'f9' ) ); }
      echo json_encode( $out );`);
    for (const handler of ALL_HANDLERS) assert.deepStrictEqual(res[handler], failed('xp_party_pro_required', 403), handler);
  });

  it('turns away a hero key whose family lacks XP Pro', () => {
    const res = runRoutes(`echo json_encode( pcall( 'mine', [], hero_of( 'f9', 'zed' ) ) );`);
    assert.deepStrictEqual(res, failed('xp_party_pro_required', 403));
  });

  it('asks nobody who is not signed in to sign in', () => {
    const res = runRoutes(`
      $out = [];
      foreach ( ${JSON.stringify(ALL_HANDLERS)} as $h ) { $out[ $h ] = pcall( $h, [], nobody() ); }
      echo json_encode( $out );`);
    for (const handler of ALL_HANDLERS) assert.deepStrictEqual(res[handler], failed('rest_not_logged_in', 401), handler);
  });

  it('passes on the identity error when the caller cannot be told', () => {
    const res = runRoutes(`
      class Bad_Identity { public function caller( $r ) { return new WP_Error( 'xp_family_key_revoked', 'Unlinked.', [ 'status' => 401 ] ); } }
      $rest = new Xophz_Compass_Xp_Party_Rest( 'x', '1', $store, new Bad_Identity(), function () { return 1; } );
      $res = $rest->mine( new Req( [] ) );
      echo json_encode( [ $res->get_error_code(), $res->get_error_data()['status'] ] );`);
    assert.deepStrictEqual(res, ['xp_family_key_revoked', 401]);
  });
});

describe('Party routes: forming a party', () => {
  it('forms a party led by a hero of the family, and answers the party and its code', () => {
    const res = runRoutes(`
      $r = pcall( 'form', [ 'name' => 'Raiders', 'emblem' => 'dragon', 'leader' => card( 'ana', 'Ana' ) ], parent_of( 'f1' ) );
      echo json_encode( $r );`);
    assert.strictEqual(res.status, 201);
    const { party, code } = res.data;
    assert.match(code, /^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/);
    assert.strictEqual(party.name, 'Raiders');
    assert.strictEqual(party.emblem, 'dragon');
    assert.strictEqual(party.inviteRule, 'leader');
    assert.deepStrictEqual(party.leader, { familyId: 'f1', heroId: 'ana' });
    assert.strictEqual(party.raid, null);
    assert.strictEqual(party.seq, 1);
    assert.deepStrictEqual(party.members.map((m) => [m.familyId, m.heroId, m.role, m.card.nickname, m.joinedAt]), [['f1', 'ana', 'leader', 'Ana', iso(NOW)]]);
    assert.strictEqual(party.code, code);
  });

  it('refuses a card that carries anything beyond the five public fields', () => {
    const res = runRoutes(`echo json_encode( pcall( 'form', [ 'name' => 'Raiders', 'emblem' => 'dragon', 'leader' => card( 'ana' ) + [ 'pin' => '1234' ] ], parent_of( 'f1' ) ) );`);
    assert.deepStrictEqual(res, failed('xp_party_card_invalid', 400));
  });

  it('refuses a bad name, a bad emblem and a bad invite rule', () => {
    const res = runRoutes(`
      $base = [ 'name' => 'Raiders', 'emblem' => 'dragon', 'leader' => card( 'ana' ) ];
      echo json_encode( [
        pcall( 'form', [ 'name' => '' ] + $base, parent_of( 'f1' ) ),
        pcall( 'form', [ 'name' => str_repeat( 'x', 41 ) ] + $base, parent_of( 'f1' ) ),
        pcall( 'form', [ 'name' => "bad\\x07name" ] + $base, parent_of( 'f1' ) ),
        pcall( 'form', [ 'emblem' => 'Big Dragon' ] + $base, parent_of( 'f1' ) ),
        pcall( 'form', [ 'invite_rule' => 'anyone' ] + $base, parent_of( 'f1' ) ),
      ] );`);
    assert.deepStrictEqual(res, [
      failed('xp_party_bad_name', 400),
      failed('xp_party_bad_name', 400),
      failed('xp_party_bad_name', 400),
      failed('xp_party_bad_emblem', 400),
      failed('xp_party_bad_rule', 400),
    ]);
  });

  it('lets only a parent form a party', () => {
    const res = runRoutes(`echo json_encode( pcall( 'form', [ 'name' => 'Raiders', 'emblem' => 'dragon', 'leader' => card( 'ana' ) ], hero_of( 'f1', 'ana' ) ) );`);
    assert.deepStrictEqual(res, failed('xp_party_forbidden', 403));
  });

  it('gives every party its own code', () => {
    const res = runRoutes(`
      [ , $a ] = make_party();
      [ , $b ] = make_party();
      echo json_encode( [ $a !== $b ] );`);
    assert.deepStrictEqual(res, [true]);
  });
});

describe('Party routes: joining', () => {
  it('opens a request from a code typed with lower case and dashes, naming the party', () => {
    const res = runRoutes(`
      [ $pid, $code ] = make_party();
      $typed = strtolower( substr( $code, 0, 4 ) . '-' . substr( $code, 4 ) );
      echo json_encode( [ $pid, pcall( 'join', [ 'code' => $typed, 'card' => card( 'ben', 'Ben' ) ], parent_of( 'f2' ) ) ] );`);
    const [pid, joined] = res;
    assert.strictEqual(joined.status, 201);
    assert.strictEqual(joined.data.request.partyId, pid);
    assert.strictEqual(joined.data.request.partyName, 'Raiders');
    assert.strictEqual(joined.data.request.card.nickname, 'Ben');
    assert.strictEqual(joined.data.request.createdAt, iso(NOW));
  });

  it('answers the same for a code that is wrong and one that is not even shaped like a code', () => {
    const res = runRoutes(`
      make_party();
      echo json_encode( [ pcall( 'join', [ 'code' => 'ZZZZ2345', 'card' => card( 'ben' ) ], parent_of( 'f2' ) ), pcall( 'join', [ 'code' => 'nope', 'card' => card( 'ben' ) ], parent_of( 'f2' ) ) ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_code_unknown', 404), failed('xp_party_code_unknown', 404)]);
  });

  it('refuses a card with an extra field, and a hero key asking for itself', () => {
    const res = runRoutes(`
      [ , $code ] = make_party();
      echo json_encode( [ pcall( 'join', [ 'code' => $code, 'card' => card( 'ben' ) + [ 'email' => 'x' ] ], parent_of( 'f2' ) ), pcall( 'join', [ 'code' => $code, 'card' => card( 'ben' ) ], hero_of( 'f2', 'ben' ) ) ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_card_invalid', 400), failed('xp_party_forbidden', 403)]);
  });

  it('keeps a hero from asking twice, and a member from asking at all', () => {
    const res = runRoutes(`
      [ , $code ] = make_party();
      pcall( 'join', [ 'code' => $code, 'card' => card( 'ben' ) ], parent_of( 'f2' ) );
      $again = pcall( 'join', [ 'code' => $code, 'card' => card( 'ben' ) ], parent_of( 'f2' ) );
      $member = pcall( 'join', [ 'code' => $code, 'card' => card( 'ana' ) ], parent_of( 'f1' ) );
      echo json_encode( [ $again, $member ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_request_pending', 409), failed('xp_party_already_member', 409)]);
  });

  it('shows the leader family the request, and the asking family its own', () => {
    const res = runRoutes(`
      [ $pid, $code ] = make_party();
      pcall( 'join', [ 'code' => $code, 'card' => card( 'ben', 'Ben' ) ], parent_of( 'f2' ) );
      $leader = pcall( 'mine', [], parent_of( 'f1' ) )['data']['requests'];
      $asker = pcall( 'mine', [], parent_of( 'f2' ) )['data']['requests'];
      $other = pcall( 'mine', [], parent_of( 'f3' ) )['data']['requests'];
      echo json_encode( [ $leader, $asker, $other ] );`);
    const [leader, asker, other] = res;
    assert.deepStrictEqual([leader.incoming.length, leader.outgoing.length], [1, 0]);
    assert.deepStrictEqual([asker.incoming.length, asker.outgoing.length], [0, 1]);
    assert.deepStrictEqual([other.incoming.length, other.outgoing.length], [0, 0]);
    assert.strictEqual(leader.incoming[0].familyId, 'f2');
    assert.strictEqual(leader.incoming[0].heroId, 'ben');
    assert.strictEqual(leader.incoming[0].card.nickname, 'Ben');
  });
});

describe('Party routes: settling a request', () => {
  const OPEN = `
    [ $pid, $code ] = make_party();
    $rid = pcall( 'join', [ 'code' => $code, 'card' => card( 'ben', 'Ben' ) ], parent_of( 'f2' ) )['data']['request']['id'];`;

  it('lets the leader family accept, which seats the hero and tells the feed', () => {
    const res = runRoutes(`${OPEN}
      $r = pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'approve' ], parent_of( 'f1' ) );
      $feed = pcall( 'feed', [ 'id' => $pid, 'since' => 0 ], parent_of( 'f2' ) )['data'];
      echo json_encode( [ $r, $feed['party']['members'], array_column( $feed['events'], 'kind' ), pcall( 'mine', [], parent_of( 'f1' ) )['data']['requests']['incoming'] ] );`);
    const [accepted, members, kinds, incoming] = res;
    assert.strictEqual(accepted.status, 200);
    assert.strictEqual(accepted.data.request.resolution, 'accepted');
    assert.deepStrictEqual([accepted.data.member.familyId, accepted.data.member.heroId, accepted.data.member.role], ['f2', 'ben', 'member']);
    assert.deepStrictEqual(members.map((m) => m.heroId), ['ana', 'ben']);
    assert.deepStrictEqual(kinds, ['join', 'join']);
    assert.deepStrictEqual(incoming, []);
  });

  it('lets the leader hero own key accept too', () => {
    const res = runRoutes(`${OPEN}
      echo json_encode( pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'approve' ], hero_of( 'f1', 'ana' ) )['status'] );`);
    assert.strictEqual(res, 200);
  });

  it('keeps out a hero key that is not the leader, and a family that is not involved', () => {
    const res = runRoutes(`${OPEN}
      echo json_encode( [ pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'approve' ], hero_of( 'f1', 'zed' ) ), pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'approve' ], parent_of( 'f3' ) ) ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_forbidden', 403), failed('xp_party_forbidden', 403)]);
  });

  it('lets the asking family withdraw its request, but never accept it', () => {
    const res = runRoutes(`${OPEN}
      $accept = pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'approve' ], parent_of( 'f2' ) );
      $decline = pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'decline' ], parent_of( 'f2' ) );
      $members = count( pcall( 'feed', [ 'id' => $pid ], parent_of( 'f1' ) )['data']['party']['members'] );
      echo json_encode( [ $accept, $decline['data']['request']['resolution'], $members ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_forbidden', 403), 'declined', 1]);
  });

  it('lets the leader decline, and answers a request already settled as gone', () => {
    const res = runRoutes(`${OPEN}
      $d = pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'decline' ], parent_of( 'f1' ) );
      $again = pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'approve' ], parent_of( 'f1' ) );
      echo json_encode( [ $d['data']['request']['resolution'], $again ] );`);
    assert.deepStrictEqual(res, ['declined', failed('xp_party_request_gone', 404)]);
  });

  it('refuses a decision that is neither', () => {
    const res = runRoutes(`${OPEN}
      echo json_encode( pcall( 'resolve_request', [ 'id' => $pid, 'rid' => $rid, 'decision' => 'maybe' ], parent_of( 'f1' ) ) );`);
    assert.deepStrictEqual(res, failed('xp_party_bad_decision', 400));
  });

  it('answers a request that belongs to another party as gone', () => {
    const res = runRoutes(`${OPEN}
      echo json_encode( pcall( 'resolve_request', [ 'id' => 'some-other-party', 'rid' => $rid, 'decision' => 'approve' ], parent_of( 'f1' ) ) );`);
    assert.deepStrictEqual(res, failed('xp_party_request_gone', 404));
  });
});

describe('Party routes: leaving and the leader', () => {
  it('lets a hero leave with its own key, and its family parent remove it', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      $leave = pcall( 'remove_member', [ 'id' => $pid, 'family_id' => 'f2', 'hero_id' => 'ben' ], hero_of( 'f2', 'ben' ) );
      $roster = count( pcall( 'feed', [ 'id' => $pid ], parent_of( 'f1' ) )['data']['party']['members'] );
      echo json_encode( [ $leave['data'], $roster ] );`);
    assert.deepStrictEqual(res, [{ left: true, disbanded: false }, 1]);
  });

  it('does not let the leader, another family or another hero remove a member', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      $args = [ 'id' => $pid, 'family_id' => 'f2', 'hero_id' => 'ben' ];
      echo json_encode( [ pcall( 'remove_member', $args, parent_of( 'f1' ) ), pcall( 'remove_member', $args, parent_of( 'f3' ) ), pcall( 'remove_member', $args, hero_of( 'f2', 'dot' ) ) ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_forbidden', 403), failed('xp_party_forbidden', 403), failed('xp_party_forbidden', 403)]);
  });

  it('answers a hero who is not in the party as not a member', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      echo json_encode( pcall( 'remove_member', [ 'id' => $pid, 'family_id' => 'f2', 'hero_id' => 'dot' ], parent_of( 'f2' ) ) );`);
    assert.deepStrictEqual(res, failed('xp_party_not_member', 404));
  });

  it('hands the lead to the member who joined next when the leader leaves', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      pcall( 'remove_member', [ 'id' => $pid, 'family_id' => 'f1', 'hero_id' => 'ana' ], parent_of( 'f1' ) );
      $party = pcall( 'feed', [ 'id' => $pid ], parent_of( 'f2' ) )['data']['party'];
      echo json_encode( [ $party['leader'], array_map( function ( $m ) { return [ $m['heroId'], $m['role'] ]; }, $party['members'] ) ] );`);
    assert.deepStrictEqual(res, [{ familyId: 'f2', heroId: 'ben' }, [['ben', 'leader']]]);
  });

  it('ends the party when the last member leaves', () => {
    const res = runRoutes(`
      [ $pid, $code ] = make_party();
      $left = pcall( 'remove_member', [ 'id' => $pid, 'family_id' => 'f1', 'hero_id' => 'ana' ], parent_of( 'f1' ) );
      echo json_encode( [ $left['data'], pcall( 'feed', [ 'id' => $pid ], parent_of( 'f1' ) ), pcall( 'join', [ 'code' => $code, 'card' => card( 'ben' ) ], parent_of( 'f2' ) ) ] );`);
    assert.deepStrictEqual(res, [{ left: true, disbanded: true }, failed('xp_party_not_found', 404), failed('xp_party_code_unknown', 404)]);
  });
});

describe('Party routes: the code', () => {
  it('lets the leader rotate it, which kills the old code and opens a new one', () => {
    const res = runRoutes(`
      [ $pid, $old ] = make_party();
      $new = pcall( 'rotate_code', [ 'id' => $pid ], parent_of( 'f1' ) )['data']['code'];
      echo json_encode( [ $new !== $old, pcall( 'join', [ 'code' => $old, 'card' => card( 'ben' ) ], parent_of( 'f2' ) ), pcall( 'join', [ 'code' => $new, 'card' => card( 'ben' ) ], parent_of( 'f2' ) )['status'] ] );`);
    assert.deepStrictEqual(res, [true, failed('xp_party_code_unknown', 404), 201]);
  });

  it('does not let a member who is not the leader rotate it', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      echo json_encode( [ pcall( 'rotate_code', [ 'id' => $pid ], parent_of( 'f2' ) ), pcall( 'rotate_code', [ 'id' => $pid ], hero_of( 'f1', 'zed' ) ) ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_forbidden', 403), failed('xp_party_forbidden', 403)]);
  });

  it('shows the code to the leader only, unless the party lets members share it', () => {
    const res = runRoutes(`
      two_party( 'leader' );
      $seen = function () { return [ pcall( 'mine', [], parent_of( 'f1' ) )['data']['parties'][0]['code'] ?? null, pcall( 'mine', [], parent_of( 'f2' ) )['data']['parties'][0]['code'] ?? null ]; };
      $leader_rule = $seen();
      echo json_encode( [ is_string( $leader_rule[0] ), $leader_rule[1] ] );`);
    assert.deepStrictEqual(res, [true, null]);

    const shared = runRoutes(`
      two_party( 'members' );
      echo json_encode( [ is_string( pcall( 'mine', [], parent_of( 'f2' ) )['data']['parties'][0]['code'] ?? null ) ] );`);
    assert.deepStrictEqual(shared, [true]);
  });
});

describe('Party routes: blocking a party', () => {
  it('takes the family heroes out, declines its open request and keeps it from joining again', () => {
    const res = runRoutes(`
      [ $pid, $code ] = two_party();
      pcall( 'join', [ 'code' => $code, 'card' => card( 'dot' ) ], parent_of( 'f2' ) );
      $blocked = pcall( 'block', [ 'code' => $code ], parent_of( 'f2' ) );
      $roster = array_column( pcall( 'feed', [ 'id' => $pid ], parent_of( 'f1' ) )['data']['party']['members'], 'heroId' );
      echo json_encode( [ $blocked['data'], $roster, pcall( 'join', [ 'code' => $code, 'card' => card( 'ben' ) ], parent_of( 'f2' ) ), pcall( 'mine', [], parent_of( 'f2' ) )['data']['requests']['outgoing'], pcall( 'mine', [], parent_of( 'f2' ) )['data']['parties'] ] );`);
    const [blocked, roster, again, outgoing, parties] = res;
    assert.ok(blocked.partyId);
    assert.deepStrictEqual(roster, ['ana']);
    assert.deepStrictEqual(again, failed('xp_party_blocked', 403));
    assert.deepStrictEqual(outgoing, []);
    assert.deepStrictEqual(parties, []);
  });

  it('answers an unknown code as unknown, and lets only a parent block', () => {
    const res = runRoutes(`
      [ , $code ] = make_party();
      echo json_encode( [ pcall( 'block', [ 'code' => 'ZZZZ2345' ], parent_of( 'f2' ) ), pcall( 'block', [ 'code' => $code ], hero_of( 'f2', 'ben' ) ) ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_code_unknown', 404), failed('xp_party_forbidden', 403)]);
  });
});

describe('Party routes: reading a party', () => {
  it('lists the parties of a family for a parent and of one hero for a hero key', () => {
    const res = runRoutes(`
      two_party();
      echo json_encode( [ count( pcall( 'mine', [], parent_of( 'f2' ) )['data']['parties'] ), count( pcall( 'mine', [], hero_of( 'f2', 'ben' ) )['data']['parties'] ), count( pcall( 'mine', [], hero_of( 'f2', 'dot' ) )['data']['parties'] ), count( pcall( 'mine', [], parent_of( 'f3' ) )['data']['parties'] ) ] );`);
    assert.deepStrictEqual(res, [1, 1, 0, 0]);
  });

  it('gives a member the roster with public cards, the events after a number and the latest number', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      $all = pcall( 'feed', [ 'id' => $pid ], parent_of( 'f2' ) )['data'];
      $after = pcall( 'feed', [ 'id' => $pid, 'since' => 1 ], hero_of( 'f2', 'ben' ) )['data'];
      echo json_encode( [ $all['seq'], array_column( $all['events'], 'seq' ), array_column( $after['events'], 'seq' ), $all['party']['members'][1]['card'] ] );`);
    assert.deepStrictEqual(res[0], 2);
    assert.deepStrictEqual(res[1], [1, 2]);
    assert.deepStrictEqual(res[2], [2]);
    assert.deepStrictEqual(Object.keys(res[3]).sort(), ['heroId', 'jobClass', 'level', 'look', 'nickname']);
  });

  it('caps how many events one read returns', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      echo json_encode( [ count( pcall( 'feed', [ 'id' => $pid, 'limit' => 1 ], parent_of( 'f2' ) )['data']['events'] ), count( pcall( 'feed', [ 'id' => $pid, 'limit' => 9999 ], parent_of( 'f2' ) )['data']['events'] ) ] );`);
    assert.deepStrictEqual(res, [1, 2]);
  });

  it('keeps out a family that is not in the party, and a hero of a member family who is not', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      echo json_encode( [ pcall( 'feed', [ 'id' => $pid ], parent_of( 'f3' ) ), pcall( 'feed', [ 'id' => $pid ], hero_of( 'f2', 'dot' ) ), pcall( 'feed', [ 'id' => 'nope' ], parent_of( 'f2' ) ) ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_not_member', 403), failed('xp_party_not_member', 403), failed('xp_party_not_found', 404)]);
  });
});

describe('Party routes: starting a raid', () => {
  it('lets the leader start one, sized to the party, and puts it on the feed', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      $r = pcall( 'start_raid', [ 'id' => $pid, 'beast_id' => 'beast-fluffalo', 'window' => 'quick' ], parent_of( 'f1' ) );
      echo json_encode( [ $r, array_column( pcall( 'feed', [ 'id' => $pid ], parent_of( 'f2' ) )['data']['events'], 'kind' ) ] );`);
    const [started, kinds] = res;
    assert.strictEqual(started.status, 201);
    assert.deepStrictEqual(
      { ...started.data.raid, id: 'x' },
      { id: 'x', beastId: 'beast-fluffalo', window: 'quick', maxHp: 180, memberCount: 2, startedAt: iso(NOW), endsAt: iso(NOW + 3 * DAY), hp: 180, status: 'active', top: null, last: null, strikes: [] }
    );
    assert.deepStrictEqual(kinds, ['join', 'join', 'raid']);
  });

  it('shows the raid on the party for every member', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      pcall( 'start_raid', [ 'id' => $pid, 'beast_id' => 'beast-fluffalo', 'window' => 'standard' ], hero_of( 'f1', 'ana' ) );
      echo json_encode( [ pcall( 'mine', [], parent_of( 'f2' ) )['data']['parties'][0]['raid']['maxHp'] ] );`);
    assert.deepStrictEqual(res, [420]);
  });

  it('turns away anyone but the leader', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      $args = [ 'id' => $pid, 'beast_id' => 'beast-fluffalo', 'window' => 'quick' ];
      echo json_encode( [ pcall( 'start_raid', $args, parent_of( 'f2' ) ), pcall( 'start_raid', $args, hero_of( 'f2', 'ben' ) ), pcall( 'start_raid', $args, parent_of( 'f3' ) ) ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_forbidden', 403), failed('xp_party_forbidden', 403), failed('xp_party_forbidden', 403)]);
  });

  it('wants at least two members', () => {
    const res = runRoutes(`
      [ $pid ] = make_party();
      echo json_encode( pcall( 'start_raid', [ 'id' => $pid, 'beast_id' => 'beast-fluffalo', 'window' => 'quick' ], parent_of( 'f1' ) ) );`);
    assert.deepStrictEqual(res, failed('xp_party_too_small', 409));
  });

  it('takes a library beast and a known window only', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      $base = [ 'id' => $pid, 'beast_id' => 'beast-fluffalo', 'window' => 'quick' ];
      echo json_encode( [
        pcall( 'start_raid', [ 'beast_id' => 'Fluffalo' ] + $base, parent_of( 'f1' ) ),
        pcall( 'start_raid', [ 'beast_id' => '../etc' ] + $base, parent_of( 'f1' ) ),
        pcall( 'start_raid', [ 'beast_id' => 'beast-' ] + $base, parent_of( 'f1' ) ),
        pcall( 'start_raid', [ 'window' => 'forever' ] + $base, parent_of( 'f1' ) ),
      ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_bad_beast', 400), failed('xp_party_bad_beast', 400), failed('xp_party_bad_beast', 400), failed('xp_party_bad_window', 400)]);
  });

  it('allows one raid at a time, and a new one once the window has closed', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      $args = [ 'id' => $pid, 'beast_id' => 'beast-fluffalo', 'window' => 'quick' ];
      pcall( 'start_raid', $args, parent_of( 'f1' ) );
      $second = pcall( 'start_raid', $args, parent_of( 'f1' ) );
      $GLOBALS['now'] += 4 * 86400;
      $status = pcall( 'feed', [ 'id' => $pid ], parent_of( 'f1' ) )['data']['party']['raid']['status'];
      $third = pcall( 'start_raid', $args, parent_of( 'f1' ) );
      echo json_encode( [ $second, $status, $third['status'] ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_raid_running', 409), 'retreated', 201]);
  });
});

describe('Party routes: a strike', () => {
  const RAID = `
    [ $pid ] = two_party();
    $raid = pcall( 'start_raid', [ 'id' => $pid, 'beast_id' => 'beast-fluffalo', 'window' => 'quick' ], parent_of( 'f1' ) )['data']['raid']['id'];`;

  it('counts a member strike sent by its family, and answers the raid', () => {
    const res = runRoutes(`${RAID}
      echo json_encode( hit( $raid, 'k1', 'f2', 'ben', 30 ) );`);
    assert.strictEqual(res.status, 200);
    assert.deepStrictEqual({ accepted: res.data.accepted, damage: res.data.damage, reason: res.data.reason }, { accepted: true, damage: 30, reason: '' });
    assert.strictEqual(res.data.raid.hp, 150);
    assert.deepStrictEqual(res.data.raid.strikes.map((s) => [s.key, s.familyId, s.heroId, s.damage]), [['k1', 'f2', 'ben', 30]]);
    assert.deepStrictEqual(res.data.raid.top, { familyId: 'f2', heroId: 'ben' });
  });

  it('counts one for a hero key too, and a resend once', () => {
    const res = runRoutes(`${RAID}
      $first = hit( $raid, 'k1', 'f2', 'ben', 30, hero_of( 'f2', 'ben' ) );
      $again = hit( $raid, 'k1', 'f2', 'ben', 30, hero_of( 'f2', 'ben' ) );
      echo json_encode( [ $first['data']['accepted'], $again['status'], $again['data']['accepted'], $again['data']['reason'], $again['data']['raid']['hp'] ] );`);
    assert.deepStrictEqual(res, [true, 200, false, 'duplicate', 150]);
  });

  it('will not let a hero key strike for another hero, and counts a parent only for heroes of its own family', () => {
    const res = runRoutes(`${RAID}
      echo json_encode( [ hit( $raid, 'k1', 'f2', 'ben', 30, hero_of( 'f2', 'dot' ) ), hit( $raid, 'k2', 'f1', 'ana', 30, hero_of( 'f1', 'zed' ) ), hit( $raid, 'k3', 'f2', 'ana', 30 ) ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_forbidden', 403), failed('xp_party_forbidden', 403), failed('xp_party_not_member', 403)]);
  });

  it('turns away a hero who is not in the party', () => {
    const res = runRoutes(`${RAID}
      echo json_encode( hit( $raid, 'k1', 'f2', 'dot', 30 ) );`);
    assert.deepStrictEqual(res, failed('xp_party_not_member', 403));
  });

  it('keeps a member family from striking a party it is not in', () => {
    const res = runRoutes(`${RAID}
      echo json_encode( hit( $raid, 'k1', 'f3', 'cam', 30 ) );`);
    assert.deepStrictEqual(res, failed('xp_party_not_member', 403));
  });

  it('holds a hero to the daily cap', () => {
    const res = runRoutes(`${RAID}
      $a = hit( $raid, 'k1', 'f2', 'ben', 40 );
      $b = hit( $raid, 'k2', 'f2', 'ben', 40 );
      $c = hit( $raid, 'k3', 'f2', 'ben', 40 );
      echo json_encode( [ $a['data']['damage'], $b['data']['damage'], $c['data']['accepted'], $c['data']['reason'], $c['data']['raid']['hp'] ] );`);
    assert.deepStrictEqual(res, [40, 20, false, 'no_damage', 120]);
  });

  it('refuses a strike that is malformed', () => {
    const res = runRoutes(`${RAID}
      $day = gmdate( 'Y-m-d', $GLOBALS['now'] );
      $base = [ 'id' => $raid, 'key' => 'k1', 'hero_id' => 'ben', 'damage' => 30, 'day' => $day, 'at' => iso( $GLOBALS['now'] ) ];
      echo json_encode( [
        pcall( 'strike', [ 'damage' => 'x' ] + $base, parent_of( 'f2' ) ),
        pcall( 'strike', [ 'damage' => 0 ] + $base, parent_of( 'f2' ) ),
        pcall( 'strike', [ 'damage' => 131 ] + $base, parent_of( 'f2' ) ),
        pcall( 'strike', [ 'key' => '' ] + $base, parent_of( 'f2' ) ),
        pcall( 'strike', [ 'key' => str_repeat( 'k', 81 ) ] + $base, parent_of( 'f2' ) ),
        pcall( 'strike', [ 'key' => 'has space' ] + $base, parent_of( 'f2' ) ),
        pcall( 'strike', [ 'at' => 'whenever' ] + $base, parent_of( 'f2' ) ),
        pcall( 'strike', [ 'day' => 'whenever' ] + $base, parent_of( 'f2' ) ),
      ] );`);
    for (const outcome of res) assert.deepStrictEqual(outcome, failed('xp_party_strike_invalid', 400));
  });

  it('pulls a strike from the future back to now, and turns away a stale one', () => {
    const res = runRoutes(`${RAID}
      $future = pcall( 'strike', [ 'id' => $raid, 'key' => 'k1', 'hero_id' => 'ben', 'damage' => 30, 'day' => gmdate( 'Y-m-d', $GLOBALS['now'] ), 'at' => iso( $GLOBALS['now'] + 5 * 86400 ) ], parent_of( 'f2' ) );
      $stale = pcall( 'strike', [ 'id' => $raid, 'key' => 'k2', 'hero_id' => 'ben', 'damage' => 30, 'day' => gmdate( 'Y-m-d', $GLOBALS['now'] - 20 * 86400 ), 'at' => iso( $GLOBALS['now'] - 20 * 86400 ) ], parent_of( 'f2' ) );
      echo json_encode( [ $future['data']['raid']['strikes'][0]['at'], $stale ] );`);
    assert.deepStrictEqual(res, [iso(NOW), failed('xp_party_strike_stale', 409)]);
  });

  it('closes the raid when the beast falls, tells the feed once, and counts nothing after', () => {
    const res = runRoutes(`${RAID}
      hit( $raid, 'a1', 'f1', 'ana', 60 );
      hit( $raid, 'b1', 'f2', 'ben', 60 );
      $GLOBALS['now'] += 86400;
      $last = hit( $raid, 'a2', 'f1', 'ana', 60 );
      $late = hit( $raid, 'b2', 'f2', 'ben', 60 );
      $kinds = array_column( pcall( 'feed', [ 'id' => $pid ], parent_of( 'f1' ) )['data']['events'], 'kind' );
      echo json_encode( [ $last['data']['raid'], $late['data'], array_count_values( $kinds ) ] );`);
    const [raid, late, kinds] = res;
    assert.deepStrictEqual([raid.hp, raid.status, raid.top, raid.last], [0, 'defeated', { familyId: 'f1', heroId: 'ana' }, { familyId: 'f1', heroId: 'ana' }]);
    assert.deepStrictEqual([late.accepted, late.reason], [false, 'closed']);
    assert.strictEqual(kinds.defeat, 1);
    assert.strictEqual(kinds.strike, 3);
  });

  it('turns away a strike for a raid that does not exist', () => {
    const res = runRoutes(`${RAID}
      echo json_encode( hit( 'nope', 'k1', 'f2', 'ben', 30 ) );`);
    assert.deepStrictEqual(res, failed('xp_party_raid_not_found', 404));
  });

  it('takes a strike back for the hero it belongs to only, and gives the health back', () => {
    const res = runRoutes(`${RAID}
      hit( $raid, 'k1', 'f2', 'ben', 30 );
      $other = pcall( 'withdraw_strike', [ 'id' => $raid, 'key' => 'k1' ], parent_of( 'f1' ) );
      $own = pcall( 'withdraw_strike', [ 'id' => $raid, 'key' => 'k1' ], parent_of( 'f2' ) );
      $again = pcall( 'withdraw_strike', [ 'id' => $raid, 'key' => 'k1' ], parent_of( 'f2' ) );
      $hp = pcall( 'feed', [ 'id' => $pid ], parent_of( 'f1' ) )['data']['party']['raid']['hp'];
      echo json_encode( [ $other, $own['data'], $again, $hp ] );`);
    assert.deepStrictEqual(res, [failed('xp_party_forbidden', 403), { removed: true }, failed('xp_party_strike_not_found', 404), 180]);
  });
});

describe('Party routes: preset words', () => {
  it('lets a member send a preset, which lands on the feed', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      $r = pcall( 'preset', [ 'id' => $pid, 'hero_id' => 'ben', 'preset' => 'gg' ], parent_of( 'f2' ) );
      $events = pcall( 'feed', [ 'id' => $pid, 'since' => 2 ], parent_of( 'f1' ) )['data']['events'];
      echo json_encode( [ $r['status'], $events ] );`);
    assert.strictEqual(res[0], 201);
    assert.deepStrictEqual(res[1].map((e) => [e.kind, e.familyId, e.heroId, e.data]), [['preset', 'f2', 'ben', { preset: 'gg' }]]);
  });

  it('lets a preset name another member it is for', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      pcall( 'preset', [ 'id' => $pid, 'hero_id' => 'ben', 'preset' => 'cheer', 'to_family' => 'f1', 'to_hero' => 'ana' ], hero_of( 'f2', 'ben' ) );
      echo json_encode( end( pcall( 'feed', [ 'id' => $pid ], parent_of( 'f1' ) )['data']['events'] )['data'] );`);
    assert.deepStrictEqual(res, { preset: 'cheer', toFamily: 'f1', toHero: 'ana' });
  });

  it('refuses words that are not on the list, a target who is not a member, a hero key sending for another hero and a sender who is not in the party', () => {
    const res = runRoutes(`
      [ $pid ] = two_party();
      $base = [ 'id' => $pid, 'hero_id' => 'ben', 'preset' => 'gg' ];
      echo json_encode( [
        pcall( 'preset', [ 'preset' => 'hello there' ] + $base, parent_of( 'f2' ) ),
        pcall( 'preset', [ 'preset' => 'toString' ] + $base, parent_of( 'f2' ) ),
        pcall( 'preset', [ 'to_family' => 'f3', 'to_hero' => 'cam' ] + $base, parent_of( 'f2' ) ),
        pcall( 'preset', $base, hero_of( 'f2', 'dot' ) ),
        pcall( 'preset', $base, parent_of( 'f3' ) ),
        pcall( 'preset', [ 'hero_id' => 'dot' ] + $base, parent_of( 'f2' ) ),
      ] );`);
    assert.deepStrictEqual(res, [
      failed('xp_party_bad_preset', 400),
      failed('xp_party_bad_preset', 400),
      failed('xp_party_bad_target', 400),
      failed('xp_party_forbidden', 403),
      failed('xp_party_not_member', 403),
      failed('xp_party_not_member', 403),
    ]);
  });
});

describe('Party routes: registration', () => {
  const REGISTER = (home) => `
    $GLOBALS['routes'] = [];
    function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][] = [ $ns, $route, $args['methods'], is_callable( $args['callback'] ), is_callable( $args['permission_callback'] ) ]; }
    function home_url() { return '${home}'; }
    require_once '${'/var/www/html/wp-content/plugins/xophz-compass-xp'}/includes/family/class-xophz-compass-xp-family-rules.php';
    require_once '${'/var/www/html/wp-content/plugins/xophz-compass-xp'}/includes/family/class-xophz-compass-xp-family-event-rules.php';
    require_once '${'/var/www/html/wp-content/plugins/xophz-compass-xp'}/includes/family/class-xophz-compass-xp-family-schema.php';
    $rest->register_routes();
    echo json_encode( $GLOBALS['routes'] );`;

  it('registers the twelve routes under xp-party/v1 on a family site, each with a callback and a permission callback', () => {
    const routes = runRoutes(REGISTER('https://doit.forthexp.com/'));
    assert.strictEqual(routes.length, 12);
    assert.ok(routes.every(([ns, , , callback, permission]) => ns === 'xp-party/v1' && callback && permission));
    const table = routes.map(([, route, methods]) => `${methods} ${route}`).sort();
    assert.deepStrictEqual(table, [
      'DELETE /raids/(?P<id>[A-Za-z0-9-]{1,36})/strikes/(?P<key>[A-Za-z0-9:._-]{1,80})',
      'DELETE /parties/(?P<id>[A-Za-z0-9-]{1,36})/members/(?P<family_id>[A-Za-z0-9-]{1,36})/(?P<hero_id>[A-Za-z0-9_-]{1,64})',
      'GET /parties/(?P<id>[A-Za-z0-9-]{1,36})/feed',
      'GET /parties/mine',
      'POST /codes/(?P<code>[A-Za-z0-9-]{1,20})/block',
      'POST /parties',
      'POST /parties/(?P<id>[A-Za-z0-9-]{1,36})/code/rotate',
      'POST /parties/(?P<id>[A-Za-z0-9-]{1,36})/presets',
      'POST /parties/(?P<id>[A-Za-z0-9-]{1,36})/raids',
      'POST /parties/(?P<id>[A-Za-z0-9-]{1,36})/requests/(?P<rid>[A-Za-z0-9-]{1,36})',
      'POST /parties/join',
      'POST /raids/(?P<id>[A-Za-z0-9-]{1,36})/strikes',
    ].sort());
  });

  it('registers nothing on a site that is not a family site', () => {
    assert.deepStrictEqual(runRoutes(REGISTER('https://www.forthexp.com/')), []);
  });
});

describe('Party identity', () => {
  const identity = (callerAnswer, family) => runRoutes(`
    require_once '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/party/class-xophz-compass-xp-party-identity.php';
    class Stub_Family_Rest { public function caller( $r ) { return ${callerAnswer}; } }
    class Stub_Family_Store { public function family_of_user( $id ) { return ${family}; } }
    $who = new Xophz_Compass_Xp_Party_Identity( new Stub_Family_Rest(), new Stub_Family_Store() );
    $out = $who->caller( new Req( [] ) );
    echo json_encode( is_wp_error( $out ) ? [ 'error' => $out->get_error_code(), 'status' => $out->get_error_data()['status'] ] : $out );`);

  it('names the family of a signed-in parent', () => {
    assert.deepStrictEqual(identity("[ 'kind' => 'parent', 'user_id' => 5 ]", "[ 'family_id' => 'f1' ]"), { kind: 'parent', user_id: 5, family_id: 'f1' });
  });

  it('names the family and hero of a hero key', () => {
    assert.deepStrictEqual(identity("[ 'kind' => 'hero', 'device' => [ 'family_id' => 'f2', 'hero_id' => 'ben' ] ]", 'null'), { kind: 'hero', family_id: 'f2', hero_id: 'ben' });
  });

  it('answers nobody for nobody, and passes an identity error on', () => {
    assert.deepStrictEqual(identity("[ 'kind' => 'none' ]", 'null'), { kind: 'none' });
    assert.deepStrictEqual(identity("new WP_Error( 'xp_family_key_revoked', 'Unlinked.', [ 'status' => 401 ] )", 'null'), { error: 'xp_family_key_revoked', status: 401 });
  });

  it('answers a parent with no family yet as having none', () => {
    assert.deepStrictEqual(identity("[ 'kind' => 'parent', 'user_id' => 5 ]", 'null'), { error: 'xp_family_no_family', status: 404 });
  });
});

describe('Party routes: loading', () => {
  it('the plugin requires the store, the identity and the routes after the family classes, and hooks the routes', () => {
    const main = fs.readFileSync(MAIN_FILE, 'utf8');
    const at = (file) => main.indexOf(`includes/party/class-xophz-compass-xp-party-${file}.php'`);
    const familyRest = main.indexOf("includes/family/class-xophz-compass-xp-family-rest.php'");
    assert.ok(at('store') > familyRest && at('identity') > familyRest && at('rest') > at('identity'), 'require_once order');
    assert.ok(main.includes("$this->define_class_hooks( 'Xophz_Compass_Xp_Party_Rest' );"));
  });

  it('the plugin class lints', () => {
    const lint = lintPhpFile(MAIN_FILE);
    assert.strictEqual(lint.isValid, true, lint.output);
  });
});

describe('Party routes: the file', () => {
  it('is valid PHP', () => {
    const res = lintPhpFile(CLASS_FILE);
    assert.ok(res.isValid, res.output);
  });

  it('has no em dashes', () => {
    for (const rel of [CLASS_REL, '../includes/party/class-xophz-compass-xp-party-identity.php', 'party-routes.test.mjs']) assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
  });
});
