/**
 * The bootstrap sits a request out instead of fataling while the plugin folder is incomplete.
 *
 * On 2026-10-26 a half-copied deploy left this plugin without includes/class-xophz-compass-xp.php,
 * and the hard require took the seven sites of the network that activate it down for two days.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const MAIN = fs.readFileSync(path.resolve(HERE, '..', 'xophz-compass-xp.php'), 'utf8');

const CORE_STUB = "<?php\nclass Xophz_Compass_Xp { public static function deactivate() {} public function run() {} }\n";
const ABILITIES_STUB = "<?php\n// stub\n";

/** Include a copy of the real main file beside the given includes/ files, in a fresh temp dir. */
function boot(includes) {
  return runPhpJson(`
    $dir = sys_get_temp_dir() . '/xp-boot-' . getmypid() . '-' . mt_rand();
    mkdir( $dir . '/includes', 0755, true );
    file_put_contents( $dir . '/xophz-compass-xp.php', <<<'XPMAIN'
${MAIN}
XPMAIN
    );
    foreach ( json_decode( <<<'JSON'
${JSON.stringify(includes)}
JSON
    , true ) as $name => $code ) {
      file_put_contents( $dir . '/includes/' . $name, $code );
    }
    if ( ! function_exists( 'plugin_dir_path' ) ) { function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } }
    if ( ! function_exists( 'register_activation_hook' ) ) { function register_activation_hook( $f, $cb ) {} }
    if ( ! function_exists( 'register_deactivation_hook' ) ) { function register_deactivation_hook( $f, $cb ) {} }
    ini_set( 'log_errors', '1' );
    ini_set( 'error_log', $dir . '/php.log' );
    include $dir . '/xophz-compass-xp.php';
    echo json_encode([
      'version_defined' => defined( 'XOPHZ_COMPASS_XP_VERSION' ),
      'incomplete'      => defined( 'XOPHZ_COMPASS_XP_INCOMPLETE' ) ? basename( XOPHZ_COMPASS_XP_INCOMPLETE ) : null,
      'core_loaded'     => class_exists( 'Xophz_Compass_Xp', false ),
      'log'             => (string) @file_get_contents( $dir . '/php.log' ),
    ]);
  `);
}

describe('xophz-compass-xp bootstrap guard', () => {
  it('loads normally when the bootstrap includes are present', () => {
    const res = boot({ 'class-xophz-compass-xp.php': CORE_STUB, 'class-xophz-compass-xp-abilities.php': ABILITIES_STUB });
    assert.strictEqual(res.version_defined, true);
    assert.strictEqual(res.core_loaded, true);
    assert.strictEqual(res.incomplete, null);
    assert.strictEqual(res.log, '');
  });

  it('sits the request out, logs and flags it when the core class file is missing', () => {
    const res = boot({ 'class-xophz-compass-xp-abilities.php': ABILITIES_STUB });
    assert.strictEqual(res.version_defined, true, 'the header and version are still read');
    assert.strictEqual(res.core_loaded, false);
    assert.strictEqual(res.incomplete, 'class-xophz-compass-xp.php');
    assert.ok(res.log.includes('class-xophz-compass-xp.php'), `log names the file: ${res.log}`);
    assert.ok(res.log.includes('incomplete'), 'log says the folder is incomplete');
  });

  it('sits the request out when the abilities file is missing', () => {
    const res = boot({ 'class-xophz-compass-xp.php': CORE_STUB });
    assert.strictEqual(res.core_loaded, false, 'nothing is required until every bootstrap include is there');
    assert.strictEqual(res.incomplete, 'class-xophz-compass-xp-abilities.php');
  });
});
