/**
 * XP Submodule Test Runner
 */

import { harness } from '../../xophz-compass/tests/harness/test-framework.mjs';
import './doit-mount.test.mjs';

const results = await harness.run();
if (results.failed > 0) {
  console.error(`XP Tests FAILED: ${results.failed} failed, ${results.passed} passed.`);
  process.exit(1);
} else {
  console.log(`XP Tests PASSED: ${results.passed} passed.`);
  process.exit(0);
}
