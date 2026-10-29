/**
 * Party rules (parties and raids): the pure rules the site judges with, loaded alone with no WordPress. Every number and
 * every case here has a twin in the app (src/lib/services/party/raidRules.ts and partyRules.ts, their jest specs): the site
 * and the app must compute the same health, the same caps and the same reward gate from the same strikes.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLASS_REL = '../includes/party/class-xophz-compass-xp-party-rules.php';
const CLASS_FILE = path.join(HERE, CLASS_REL);

const RULES = "require_once '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/party/class-xophz-compass-xp-party-rules.php';\n";
const CLS = 'Xophz_Compass_Xp_Party_Rules';

const php = (body) => runPhpJson(`${RULES}${body}`);

/** A JS value as a PHP expression (decoded from JSON), safe inside a single-quoted PHP string. */
const lit = (value) => `json_decode( '${JSON.stringify(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}', true )`;

/** A hero card as the app sends it. */
const CARD = {
  heroId: 'hero-1',
  nickname: 'Mina',
  level: 7,
  jobClass: 'paladin',
  look: { skin: 'peach', hair: 'short', outfit: 'paladin-1' },
};

const DAY = 86400;
/** 2026-10-28 08:00:00 UTC, the raid's start in these cases. */
const START = 1793174400;
const day = (n) => new Date((START + n * DAY) * 1000).toISOString().slice(0, 10);

const RAID = { max_hp: 100, window: 'standard', started_at: START };
const MEMBERS = ['f1|ana', 'f1|ben'];
const blow = (key, member, damage, n = 0, hour = 1) => ({ strike_key: key, member, damage, day: day(n), at: START + n * DAY + hour * 3600 });

/** Runs strike() over a list of blows in order, feeding each accepted one back as counted; answers every verdict. */
function play(raid, blows, members = MEMBERS) {
  return php(`
    $raid = ${lit(raid)}; $members = ${lit(members)}; $counted = []; $out = [];
    foreach ( ${lit(blows)} as $b ) {
      $v = ${CLS}::strike( $raid, $counted, $members, $b );
      if ( $v['accepted'] ) { $counted[] = [ 'strike_key' => $b['strike_key'], 'member' => $b['member'], 'damage' => $v['damage'], 'day' => $b['day'], 'at' => $b['at'] ]; }
      $out[] = $v;
    }
    echo json_encode( [ 'verdicts' => $out, 'counted' => $counted ] );`);
}

describe('Party rules: constants', () => {
  it('holds the twins of the app constants', () => {
    const res = php(`echo json_encode( [ ${CLS}::RAID_DAYS, ${CLS}::DAMAGE_PER_STEP, ${CLS}::DAILY_STRIKE_CAP, ${CLS}::HP_PER_HERO_DAY ] );`);
    assert.deepStrictEqual(res, [{ quick: 3, standard: 7, epic: 14 }, 10, 60, 30]);
  });

  it('offers the same presets as the app, and nothing else', () => {
    const res = php(`echo json_encode( ${CLS}::PRESETS );`);
    assert.deepStrictEqual(res, ['gg', 'nice', 'onit', 'ready', 'help', 'thanks', 'wait', 'brb', 'heart', 'star', 'party', 'cheer', 'poke']);
    const checks = php(`echo json_encode( [ ${CLS}::is_preset( 'gg' ), ${CLS}::is_preset( '' ), ${CLS}::is_preset( 'toString' ), ${CLS}::is_preset( 'free text' ), ${CLS}::is_preset( null ), ${CLS}::is_preset( 7 ) ] );`);
    assert.deepStrictEqual(checks, [true, false, false, false, false, false]);
  });
});

describe('Party rules: the hero card', () => {
  const card = (c) => php(`echo json_encode( ${CLS}::card( ${lit(c)} ) );`);

  it('accepts the five public fields and answers them unchanged', () => {
    assert.deepStrictEqual(card(CARD), { ok: true, card: CARD });
    assert.strictEqual(card({ ...CARD, look: null }).ok, true);
  });

  it('refuses any field beyond the five, whatever it holds', () => {
    for (const extra of [{ pin: '1234' }, { email: 'x' }, { realName: 'Amelia' }, { birthday: '2014-02-03' }]) {
      assert.deepStrictEqual(card({ ...CARD, ...extra }), { ok: false, code: 'xp_party_card_invalid' });
    }
  });

  it('refuses a missing field', () => {
    for (const key of Object.keys(CARD)) {
      const { [key]: _gone, ...rest } = CARD;
      assert.strictEqual(card(rest).ok, false, `without ${key}`);
    }
  });

  it('refuses a bad hero id, nickname, level or class', () => {
    const bad = [
      { heroId: '' }, { heroId: 'a b' }, { heroId: 'x'.repeat(65) },
      { nickname: '' }, { nickname: 'x'.repeat(25) }, { nickname: 'bad\u0007name' },
      { level: 0 }, { level: 1000 }, { level: '7' }, { level: 7.5 },
      { jobClass: 'x'.repeat(33) }, { jobClass: 5 },
    ];
    for (const over of bad) assert.strictEqual(card({ ...CARD, ...over }).ok, false, JSON.stringify(over));
  });

  it('refuses a look with an unknown field, a non-text value or a long value', () => {
    for (const look of [{ secret: 'x' }, { skin: 5 }, { skin: 'x'.repeat(41) }, 'peach', [1, 2]]) {
      assert.strictEqual(card({ ...CARD, look }).ok, false, JSON.stringify(look));
    }
  });
});

describe('Party rules: raid health, window and codes', () => {
  it('sizes health like the app: days times heroes times thirty', () => {
    const res = php(`echo json_encode( [ ${CLS}::raid_max_hp( 'quick', 2 ), ${CLS}::raid_max_hp( 'standard', 4 ), ${CLS}::raid_max_hp( 'epic', 3 ), ${CLS}::raid_max_hp( 'quick', 0 ), ${CLS}::raid_max_hp( 'quick', 1 ) ] );`);
    assert.deepStrictEqual(res, [180, 840, 1260, 90, 90]);
  });

  it('never asks for more than the daily cap lets the party deal', () => {
    const res = php(`
      $ok = true;
      foreach ( [ 'quick', 'standard', 'epic' ] as $w ) {
        for ( $m = 1; $m <= 8; $m++ ) {
          if ( ${CLS}::raid_max_hp( $w, $m ) > ${CLS}::DAILY_STRIKE_CAP * ${CLS}::RAID_DAYS[ $w ] * $m ) { $ok = false; }
        }
      }
      echo json_encode( [ $ok ] );`);
    assert.deepStrictEqual(res, [true]);
  });

  it('knows the three windows and nothing else', () => {
    const res = php(`echo json_encode( [ ${CLS}::is_window( 'quick' ), ${CLS}::is_window( 'epic' ), ${CLS}::is_window( 'forever' ), ${CLS}::is_window( null ) ] );`);
    assert.deepStrictEqual(res, [true, true, false, false]);
  });

  it('makes an eight character code from letters and digits that cannot be misread', () => {
    const res = php(`
      $codes = [];
      for ( $i = 0; $i < 50; $i++ ) { $codes[] = ${CLS}::new_code(); }
      echo json_encode( [ $codes ] );`);
    for (const code of res[0]) assert.match(code, /^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/);
    assert.ok(new Set(res[0]).size > 45, 'codes vary');
  });

  it('reads a code typed with spaces, dashes and lower case', () => {
    const res = php(`echo json_encode( [ ${CLS}::normalize_code( ' ab-cd 23ef ' ), ${CLS}::is_code_shaped( 'ABCD23EF' ), ${CLS}::is_code_shaped( 'ABCD23E' ), ${CLS}::is_code_shaped( 'ABCD23EI' ), ${CLS}::is_code_shaped( 'abcd23ef' ) ] );`);
    assert.deepStrictEqual(res, ['ABCD23EF', true, false, false, false]);
  });

  it('trusts a hero day only within a day of the site time', () => {
    const res = php(`echo json_encode( [
      ${CLS}::plausible_day( '${day(0)}', ${START} ),
      ${CLS}::plausible_day( '${day(1)}', ${START} ),
      ${CLS}::plausible_day( '${day(-1)}', ${START} ),
      ${CLS}::plausible_day( '${day(2)}', ${START} ),
      ${CLS}::plausible_day( 'tomorrow', ${START} ),
      ${CLS}::plausible_day( '2026-13-45', ${START} ) ] );`);
    assert.deepStrictEqual(res, [true, true, true, false, false, false]);
  });
});

describe('Party rules: a strike', () => {
  const accepted = (res) => res.verdicts.map((v) => v.accepted);
  const damages = (res) => res.counted.map((c) => c.damage);

  it('counts a member strike', () => {
    const res = play(RAID, [blow('k1', 'f1|ana', 30)]);
    assert.deepStrictEqual(res.verdicts[0], { accepted: true, damage: 30, reason: '' });
  });

  it('counts a strike key once however often it arrives', () => {
    const res = play(RAID, [blow('k1', 'f1|ana', 30), blow('k1', 'f1|ana', 30)]);
    assert.deepStrictEqual(accepted(res), [true, false]);
    assert.strictEqual(res.verdicts[1].reason, 'duplicate');
  });

  it('ignores a hero who is not in the party', () => {
    const res = play(RAID, [blow('k1', 'f2|cam', 30)]);
    assert.deepStrictEqual(res.verdicts[0], { accepted: false, damage: 0, reason: 'not_member' });
  });

  it('holds a hero to the daily cap per raid, then starts a new day fresh', () => {
    const res = play({ ...RAID, max_hp: 1000 }, [blow('k1', 'f1|ana', 40, 0, 1), blow('k2', 'f1|ana', 40, 0, 2), blow('k3', 'f1|ana', 40, 0, 3), blow('k4', 'f1|ana', 40, 1, 1)]);
    assert.deepStrictEqual(damages(res), [40, 20, 40]);
    assert.strictEqual(res.verdicts[2].reason, 'no_damage');
  });

  it('keeps one hero cap from touching another', () => {
    const res = play({ ...RAID, max_hp: 1000 }, [blow('k1', 'f1|ana', 200), blow('k2', 'f1|ben', 30)]);
    assert.deepStrictEqual(damages(res), [60, 30]);
  });

  it('trims the last blow to what is left of the beast and closes the raid', () => {
    const res = play({ ...RAID, max_hp: 100 }, [blow('k1', 'f1|ana', 60), blow('k2', 'f1|ben', 60), blow('k3', 'f1|ana', 60, 1)]);
    assert.deepStrictEqual(damages(res), [60, 40]);
    assert.deepStrictEqual(res.verdicts[2], { accepted: false, damage: 0, reason: 'closed' });
  });

  it('ignores a strike from before the raid began, and one at or after its end', () => {
    const before = { ...blow('k1', 'f1|ana', 30), at: START - 1 };
    const atEnd = { ...blow('k2', 'f1|ana', 30), at: START + 7 * DAY, day: day(7) };
    const justBefore = { ...blow('k3', 'f1|ana', 30), at: START + 7 * DAY - 1, day: day(7) };
    const res = play(RAID, [before, atEnd, justBefore]);
    assert.deepStrictEqual(accepted(res), [false, false, true]);
    assert.strictEqual(res.verdicts[0].reason, 'closed');
  });

  it('ignores a blow with no damage, a negative one, or no plausible day', () => {
    const res = play(RAID, [blow('k1', 'f1|ana', 0), blow('k2', 'f1|ana', -5), { ...blow('k3', 'f1|ana', 30), day: 'whenever' }]);
    assert.deepStrictEqual(res.verdicts.map((v) => v.reason), ['no_damage', 'no_damage', 'bad_day']);
  });
});

describe('Party rules: where a raid stands', () => {
  const state = (raid, blows, now) => {
    const res = play(raid, blows);
    return php(`echo json_encode( ${CLS}::raid_state( ${lit(raid)}, ${lit(res.counted)}, ${now} ) );`);
  };

  it('starts active at full health', () => {
    assert.deepStrictEqual(state(RAID, [], START + DAY), { hp: 100, status: 'active', top: null, last: null });
  });

  it('subtracts every strike and names the top striker so far', () => {
    const res = state(RAID, [blow('k1', 'f1|ana', 30), blow('k2', 'f1|ben', 20)], START + DAY);
    assert.deepStrictEqual(res, { hp: 50, status: 'active', top: 'f1|ana', last: null });
  });

  it('is defeated at zero health, with the last hit and the top striker named', () => {
    const res = state(RAID, [blow('k1', 'f1|ana', 60), blow('k2', 'f1|ben', 30), blow('k3', 'f1|ben', 30, 1)], START + 2 * DAY);
    assert.deepStrictEqual(res, { hp: 0, status: 'defeated', top: 'f1|ana', last: 'f1|ben' });
  });

  it('breaks a tie for top striker toward the hero who struck first', () => {
    const res = state(RAID, [blow('k1', 'f1|ben', 20, 0, 1), blow('k2', 'f1|ana', 20, 0, 2)], START + DAY);
    assert.strictEqual(res.top, 'f1|ben');
  });

  it('retreats exactly when the window closes with the beast still standing', () => {
    const blows = [blow('k1', 'f1|ana', 30)];
    assert.strictEqual(state(RAID, blows, START + 7 * DAY - 1).status, 'active');
    assert.deepStrictEqual(state(RAID, blows, START + 7 * DAY), { hp: 70, status: 'retreated', top: 'f1|ana', last: null });
  });

  it('stays defeated after the window when the beast fell inside it', () => {
    const res = state({ ...RAID, max_hp: 50 }, [blow('k1', 'f1|ana', 50)], START + 30 * DAY);
    assert.strictEqual(res.status, 'defeated');
  });
});

describe('Party rules: the file', () => {
  it('is valid PHP', () => {
    const res = lintPhpFile(CLASS_FILE);
    assert.ok(res.isValid, res.output);
  });

  it('has no em dashes', () => {
    assertNoEmDashes(fs.readFileSync(CLASS_FILE, 'utf8'), CLASS_REL);
    assertNoEmDashes(fs.readFileSync(path.join(HERE, 'party-rules.test.mjs'), 'utf8'), 'party-rules.test.mjs');
  });
});
