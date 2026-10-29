/**
 * XP Submodule Test Runner
 */

import { harness } from '../../xophz-compass/tests/harness/test-framework.mjs';
import './doit-mount.test.mjs';
import './xp-pro-buy.test.mjs';
import './family-rules.test.mjs';
import './family-event-rules.test.mjs';
import './family-two-phone-events.test.mjs';
import './family-schema.test.mjs';
import './family-records.test.mjs';
import './family-turn.test.mjs';
import './family-pro.test.mjs';
import './party-rules.test.mjs';
import './party-schema.test.mjs';
import './town-hall-membership.test.mjs';

const results = await harness.run();
if (results.failed > 0) {
  for (const f of results.failures) {
    console.error(`FAIL ${f.suiteName} > ${f.testName}\n${f.error.split('\n').slice(0, 3).join('\n')}`);
  }
  console.error(`XP Tests FAILED: ${results.failed} failed, ${results.passed} passed.`);
  process.exit(1);
} else {
  console.log(`XP Tests PASSED: ${results.passed} passed.`);
  process.exit(0);
}
