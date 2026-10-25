/**
 * The xp-pro license product: Xophz_Compass_Xp_Pro registers it on Gatekeeper's
 * gatekeeper_license_products filter. The option names are the names the shipped records already
 * live under (no data migration), so every one is asserted exactly. When the Gatekeeper plugin
 * sits next to this one, its registry validates the config for real.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson, lintPhpFile } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PLUGINS = '/var/www/html/wp-content/plugins';
const PRO = `require_once '${PLUGINS}/xophz-compass-xp/includes/class-xophz-compass-xp-pro.php';\n`;
const REGISTRY_REL = '../../xophz-compass-gatekeeper/includes/class-gatekeeper-license-products.php';
const HAS_REGISTRY = fs.existsSync(path.join(HERE, REGISTRY_REL));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';

function lit(value) {
  return `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;
}

/** get_option() answering from $GLOBALS['options'], defined before the harness stub gets the chance. */
const OPTIONS = `
  $GLOBALS['options'] = [];
  function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
`;

/** Run `body` after the class is loaded, with options stored in $GLOBALS['options']. */
const run = (body) => runPhpJson(`${OPTIONS}\n${PRO}\n${body}`);

/** The registered xp-pro config with its callables replaced by true/false (is_callable) for JSON. */
const CONFIG_JSON = `
  $pro     = new Xophz_Compass_Xp_Pro( 'xophz-compass-xp', '1' );
  $config  = $pro->register_product( [] )['xp-pro'];
  $summary = $config;
  foreach ( [ 'account_validator', 'device_validator', 'plans' ] as $k ) { $summary[ $k ] = is_callable( $config[ $k ] ); }
  $summary['strings']['key_email_lines'] = is_callable( $config['strings']['key_email_lines'] );
`;

describe('XP Pro product: registration', () => {
  it('hooks gatekeeper_license_products with register_product', () => {
    const res = run(`
      $pro = new Xophz_Compass_Xp_Pro( 'xophz-compass-xp', '1' );
      echo json_encode( $pro->filter_hooks['gatekeeper_license_products'] ?? null );`);
    assert.deepStrictEqual(res, ['register_product', 10, 1]);
  });

  it('adds xp-pro to the products given, leaves the others alone, and survives a non-array', () => {
    const res = run(`
      $pro   = new Xophz_Compass_Xp_Pro( 'xophz-compass-xp', '1' );
      $other = [ 'label' => 'Other' ];
      $with  = $pro->register_product( [ 'seo' => $other ] );
      $bare  = $pro->register_product( null );
      echo json_encode( [ array_keys( $with ), $with['seo'] === $other, array_keys( $bare ), is_array( $with['xp-pro'] ) ] );`);
    assert.deepStrictEqual(res, [['seo', 'xp-pro'], true, ['xp-pro'], true]);
  });

  it('the product id is xp-pro and the Stripe product name stays doitforthexp', () => {
    const res = run(`echo json_encode( [ Xophz_Compass_Xp_Pro::LICENSE_PRODUCT, Xophz_Compass_Xp_Pro::PRODUCT ] );`);
    assert.deepStrictEqual(res, ['xp-pro', 'doitforthexp']);
  });
});

describe('XP Pro product: the config', () => {
  it('has exactly the keys Gatekeeper reads', () => {
    const res = run(`${CONFIG_JSON} echo json_encode( array_keys( $config ) );`);
    assert.deepStrictEqual([...res].sort(), [
      'account_param',
      'account_validator',
      'app_name',
      'checkout_product',
      'device_param',
      'device_validator',
      'grace_seconds',
      'key_groups',
      'key_prefix',
      'label',
      'max_devices',
      'option_prefix',
      'plans',
      'route_prefix',
      'settings',
      'strings',
      'stripe_setup_option',
      'trial_seconds',
      'webhooks_option',
    ]);
  });

  it('carries the epic values: keys, accounts, devices, grace, trial and the Stripe name', () => {
    const res = run(`${CONFIG_JSON} echo json_encode( $summary );`);
    assert.strictEqual(res.label, 'XP Pro');
    assert.strictEqual(res.app_name, 'Do It For The XP');
    assert.strictEqual(res.key_prefix, 'XP-PRO-');
    assert.strictEqual(res.key_groups, 3);
    assert.strictEqual(res.account_param, 'family_id');
    assert.strictEqual(res.device_param, 'device_id');
    assert.strictEqual(res.max_devices, 10);
    assert.strictEqual(res.grace_seconds, 259200, 'three days');
    assert.strictEqual(res.trial_seconds, 604800, 'seven days');
    assert.strictEqual(res.checkout_product, 'doitforthexp');
    assert.strictEqual(res.route_prefix, 'xp-pro');
    assert.strictEqual(res.account_validator, true);
    assert.strictEqual(res.device_validator, true);
    assert.strictEqual(res.plans, true);
  });

  it('every option name is the name the shipped data lives under', () => {
    const res = run(`${CONFIG_JSON} echo json_encode( $summary );`);
    assert.strictEqual(res.option_prefix, 'gk_xp_pro_');
    assert.strictEqual(res.webhooks_option, 'gatekeeper_xp_pro_webhooks');
    assert.strictEqual(res.stripe_setup_option, 'gatekeeper_xp_pro_stripe_setup');
    assert.strictEqual(res.settings.ai_daily_limit.option, 'xp_pro_ai_daily_limit');
    assert.strictEqual(res.settings.lifetime_on_sale.option, 'xp_pro_lifetime_on_sale');
  });

  it('the settings schema is two rows: ai_daily_limit (int, 100) and lifetime_on_sale (bool, true)', () => {
    const res = run(`${CONFIG_JSON} echo json_encode( $config['settings'] );`);
    assert.deepStrictEqual(res, {
      ai_daily_limit: { label: 'AI requests per family per day', type: 'int', option: 'xp_pro_ai_daily_limit', default: 100 },
      lifetime_on_sale: { label: 'Lifetime plan on sale', type: 'bool', option: 'xp_pro_lifetime_on_sale', default: true },
    });
  });

  it('the account validator takes a lowercase uuid4 and the device validator 8 to 64 safe characters', () => {
    const res = run(`
      ${CONFIG_JSON}
      $acc = $config['account_validator'];
      $dev = $config['device_validator'];
      echo json_encode( [
        $acc( '${FAMILY}' ), $acc( strtoupper( '${FAMILY}' ) ), $acc( 'not-a-uuid' ), $acc( '' ),
        $dev( 'devicetest01' ), $dev( 'short' ), $dev( str_repeat( 'd', 64 ) ), $dev( str_repeat( 'd', 65 ) ), $dev( 'has space here' ),
      ] );`);
    assert.deepStrictEqual(res, [true, false, false, false, true, false, true, false, false]);
  });

  it('the buy resolver and the config share the same validators', () => {
    const res = run(`
      ${CONFIG_JSON}
      echo json_encode( [ $config['account_validator'], $config['device_validator'] ] );`);
    assert.deepStrictEqual(res, [['Xophz_Compass_Xp_Pro', 'is_uuid4'], ['Xophz_Compass_Xp_Pro', 'is_device_id']]);
  });
});

describe('XP Pro product: plans', () => {
  it('the plans callable is get_plans and returns the catalog keyed monthly, yearly, lifetime', () => {
    const res = run(`
      ${CONFIG_JSON}
      echo json_encode( [ $config['plans'], call_user_func( $config['plans'] ) === Xophz_Compass_Xp_Pro::get_plans(), call_user_func( $config['plans'] ) ] );`);
    assert.deepStrictEqual(res[0], ['Xophz_Compass_Xp_Pro', 'get_plans']);
    assert.strictEqual(res[1], true);
    assert.deepStrictEqual(Object.keys(res[2]), ['monthly', 'yearly', 'lifetime']);
    for (const plan of Object.values(res[2])) {
      assert.deepStrictEqual(Object.keys(plan).sort(), ['interval', 'mode', 'on_sale', 'price', 'store_product_id', 'trial_days']);
    }
  });

  it('the lifetime plan is the one with mode payment, and the store product ids are the ones the apps sell', () => {
    const res = run(`${CONFIG_JSON} echo json_encode( call_user_func( $config['plans'] ) );`);
    const payment = Object.keys(res).filter((id) => res[id].mode === 'payment');
    assert.deepStrictEqual(payment, ['lifetime']);
    assert.deepStrictEqual(
      Object.values(res).map((p) => p.store_product_id),
      ['xp_pro_monthly', 'xp_pro_yearly', 'xp_pro_lifetime'],
    );
  });

  it('the plans callable follows xp_pro_lifetime_on_sale at once', () => {
    const res = run(`
      ${CONFIG_JSON}
      $out = [ call_user_func( $config['plans'] )['lifetime']['on_sale'] ];
      $GLOBALS['options']['xp_pro_lifetime_on_sale'] = '0';
      $out[] = call_user_func( $config['plans'] )['lifetime']['on_sale'];
      $GLOBALS['options']['xp_pro_lifetime_on_sale'] = '1';
      $out[] = call_user_func( $config['plans'] )['lifetime']['on_sale'];
      echo json_encode( $out );`);
    assert.deepStrictEqual(res, [true, false, true]);
  });

  it('keeps filling the legacy gatekeeper_xp_pro_plans filter for a Gatekeeper without products', () => {
    const res = run(`
      $pro = new Xophz_Compass_Xp_Pro( 'xophz-compass-xp', '1' );
      echo json_encode( $pro->provide_plans( [] ) === Xophz_Compass_Xp_Pro::get_plans() );`);
    assert.strictEqual(res, true);
  });
});

describe('XP Pro product: the key email', () => {
  it('the subject, the lines and the support line are the ones Gatekeeper mailed before', () => {
    const res = run(`
      ${CONFIG_JSON}
      $s = $config['strings'];
      echo json_encode( [ $s['key_email_subject'], call_user_func( $s['key_email_lines'], [ 'license_key' => 'XP-PRO-ABCD-EFGH-JKLM' ] ), $s['support_line'] ] );`);
    assert.strictEqual(res[0], 'Your XP Pro key');
    assert.deepStrictEqual(res[1], [
      'Thank you for getting XP Pro.',
      '',
      'Your key: XP-PRO-ABCD-EFGH-JKLM',
      '',
      'XP Pro is already on the device you bought it from. To use it on another device:',
      '1. Open Do It For The XP.',
      '2. Go to Settings > XP Pro > Restore with key.',
      '3. Type the key above.',
      '',
      'Keep this email. The key works on up to 10 devices.',
    ]);
    assert.strictEqual(res[2], 'Questions? Write to {support_email}.');
  });

  it('a record without a key still gives lines', () => {
    const res = run(`echo json_encode( Xophz_Compass_Xp_Pro::key_email_lines( [] )[2] );`);
    assert.strictEqual(res, 'Your key: ');
  });
});

describe('XP Pro product: Gatekeeper accepts it', () => {
  // The real registry validates the config, fills the defaults and would log and skip a bad one.
  const registry = (body) => runPhpJson(
    `${OPTIONS}
     $log_file = tempnam( sys_get_temp_dir(), 'xp-pro-log' );
     ini_set( 'log_errors', '1' );
     ini_set( 'error_log', $log_file );
     $GLOBALS['hooks'] = [];
     function add_filter( $tag, $cb ) { $GLOBALS['hooks'][ $tag ][] = $cb; }
     function apply_filters( $tag, $value, ...$args ) {
       foreach ( $GLOBALS['hooks'][ $tag ] ?? [] as $cb ) { $value = $cb( $value, ...$args ); }
       return $value;
     }
     require_once '${PLUGINS}/xophz-compass-gatekeeper/includes/class-gatekeeper-license-products.php';
     ${PRO}
     $pro = new Xophz_Compass_Xp_Pro( 'xophz-compass-xp', '1' );
     add_filter( 'gatekeeper_license_products', [ $pro, 'register_product' ] );
     Gatekeeper_License_Products::reset();
     ${body}`,
  );

  it('validates, registers for real (not the built-in transition default) and logs nothing', () => {
    if (!HAS_REGISTRY) return;
    const res = registry(`
      $product = Gatekeeper_License_Products::get( 'xp-pro' );
      echo json_encode( [
        null === $product ? null : $product['builtin'],
        Gatekeeper_License_Products::validate( null === $product ? [] : $product ),
        trim( (string) file_get_contents( $log_file ) ),
        array_keys( Gatekeeper_License_Products::all() ),
        null === $product ? null : $product['key_alphabet'],
        null === $product ? null : $product['route_prefix'],
        Gatekeeper_License_Products::by_route_prefix( 'xp-pro' )['id'] ?? null,
      ] );`);
    assert.strictEqual(res[0], false, 'registered by this plugin, not the built-in definition');
    assert.strictEqual(res[1], null, 'the registry finds nothing wrong with it');
    assert.strictEqual(res[2], '', 'nothing was logged: not skipped, no transition notice');
    assert.deepStrictEqual(res[3], ['xp-pro']);
    assert.strictEqual(res[4], 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
    assert.strictEqual(res[5], 'xp-pro');
    assert.strictEqual(res[6], 'xp-pro');
  });

  it('finds the product by the webhook id configured in gatekeeper_xp_pro_webhooks', () => {
    if (!HAS_REGISTRY) return;
    const res = registry(`
      $GLOBALS['options']['gatekeeper_xp_pro_webhooks'] = [ 'stripe' => 11, 'stripe_test' => 12, 'revenuecat' => 13 ];
      $out = [];
      foreach ( [ 11, 12, 13, 99 ] as $id ) {
        $hit   = Gatekeeper_License_Products::by_webhook_id( $id );
        $out[] = null === $hit ? null : [ $hit[0]['id'], $hit[1] ];
      }
      echo json_encode( $out );`);
    assert.deepStrictEqual(res, [['xp-pro', 'stripe'], ['xp-pro', 'stripe'], ['xp-pro', 'revenuecat'], null]);
  });
});

describe('XP Pro product: the file', () => {
  it('lints', () => {
    const lint = lintPhpFile(path.join(HERE, '../includes/class-xophz-compass-xp-pro.php'));
    assert.strictEqual(lint.isValid, true, lint.output);
  });

  it('files carry no em dashes', () => {
    for (const rel of ['../includes/class-xophz-compass-xp-pro.php', 'xp-pro-product.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
