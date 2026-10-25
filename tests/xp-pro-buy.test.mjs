/**
 * XP Pro catalog and the /buy/doitforthexp/{plan} resolver for Bazaar.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const DEVICE = 'devicetest01';
const RETURN_URL = 'http://localhost:8100/#/pro/return';

const PRO = "require_once '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/class-xophz-compass-xp-pro.php';\n";

function lit(value) {
  return `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;
}

/** Call the resolver and report either the resolved array or the error code and status. */
function resolve(segments, query, { loadWp = false, before = '' } = {}) {
  return runPhpJson(
    `${PRO}
    ${before}
    $pro = new Xophz_Compass_Xp_Pro( 'xophz-compass-xp', '1' );
    $r = $pro->resolve_buy_request( null, ${lit(segments)}, ${lit(query)} );
    echo json_encode( is_wp_error( $r )
      ? [ 'error' => $r->get_error_code(), 'status' => $r->get_error_data()['status'] ?? null ]
      : $r );`,
    { loadWp }
  );
}

const goodQuery = (extra = {}) => ({ family_id: FAMILY, device_id: DEVICE, return_url: RETURN_URL, ...extra });

describe('XP Pro buy: resolver scope', () => {
  it('other segments pass $resolved through untouched', () => {
    const res = runPhpJson(`${PRO}
      $pro = new Xophz_Compass_Xp_Pro( 'xophz-compass-xp', '1' );
      $input = [ 'handled' => true, 'name' => 'something else' ];
      echo json_encode( [
        $pro->resolve_buy_request( $input, ${lit(['xp', 'developer'])}, [] ) === $input,
        $pro->resolve_buy_request( null, ${lit(['xp', 'developer'])}, [] ) === null,
        $pro->resolve_buy_request( null, [], [] ) === null,
        $pro->resolve_buy_request( null, ${lit(['forthexp', 'growth'])}, [] ) === null,
      ] );`);
    assert.deepStrictEqual(res, [true, true, true, true]);
  });
});

describe('XP Pro buy: plans', () => {
  it('monthly returns 4.99 subscription month with 7 trial days and the metadata', () => {
    const res = resolve(['doitforthexp', 'monthly'], goodQuery());
    assert.strictEqual(res.handled, true);
    assert.strictEqual(res.price, 4.99);
    assert.strictEqual(res.mode, 'subscription');
    assert.strictEqual(res.interval, 'month');
    assert.strictEqual(res.trial_days, 7);
    assert.deepStrictEqual(res.metadata, {
      product: 'doitforthexp',
      plan: 'monthly',
      family_id: FAMILY,
      device_id: DEVICE,
      plugin_slug: 'doitforthexp',
    });
  });

  it('yearly is 39.99 per year with 7 trial days', () => {
    const res = resolve(['doitforthexp', 'yearly'], goodQuery());
    assert.strictEqual(res.price, 39.99);
    assert.strictEqual(res.mode, 'subscription');
    assert.strictEqual(res.interval, 'year');
    assert.strictEqual(res.trial_days, 7);
  });

  it('lifetime is 79 payment', () => {
    const res = resolve(['doitforthexp', 'lifetime'], goodQuery());
    assert.strictEqual(res.price, 79);
    assert.strictEqual(res.mode, 'payment');
    assert.ok(!res.interval);
    assert.ok(!res.trial_days);
    assert.strictEqual(res.metadata.plan, 'lifetime');
  });

  it('product_name, not name, is set, and the urls are left for Bazaar', () => {
    const res = resolve(['doitforthexp', 'yearly'], goodQuery());
    assert.strictEqual(res.product_name, 'Do It For The XP: XP Pro (Yearly)');
    assert.ok(!('name' in res));
    assert.ok(!('success_url' in res));
    assert.ok(!('cancel_url' in res));
  });

  it('lifetime off sale is 404', () => {
    const res = resolve(['doitforthexp', 'lifetime'], goodQuery(), {
      loadWp: true,
      before: `update_option( 'xp_pro_lifetime_on_sale', '0' ); register_shutdown_function( function () { delete_option( 'xp_pro_lifetime_on_sale' ); } );`,
    });
    assert.deepStrictEqual(res, { error: 'not_found', status: 404 });
  });

  it('an unknown plan is 404', () => {
    for (const plan of ['platinum', '', 'Monthly', '../lifetime']) {
      const res = resolve(['doitforthexp', plan], goodQuery());
      assert.deepStrictEqual(res, { error: 'not_found', status: 404 }, `plan ${JSON.stringify(plan)}`);
    }
    assert.deepStrictEqual(resolve(['doitforthexp'], goodQuery()), { error: 'not_found', status: 404 });
  });
});

describe('XP Pro buy: input validation', () => {
  const bad = { error: 'invalid_input', status: 400 };

  it('a bad family_id is 400', () => {
    for (const family_id of ['nope', FAMILY.toUpperCase(), '3f6c2a1e-8b4d-1c7a-9e2f-1a2b3c4d5e6f', `${FAMILY}\n`, '', ['x']]) {
      assert.deepStrictEqual(resolve(['doitforthexp', 'monthly'], goodQuery({ family_id })), bad, `family ${JSON.stringify(family_id)}`);
    }
    const missing = goodQuery();
    delete missing.family_id;
    assert.deepStrictEqual(resolve(['doitforthexp', 'monthly'], missing), bad);
  });

  it('a bad device_id is 400', () => {
    for (const device_id of ['short', 'a'.repeat(65), 'has space1', '<script>alert(1)</script>', `${DEVICE}\n`, '', { a: 1 }]) {
      assert.deepStrictEqual(resolve(['doitforthexp', 'monthly'], goodQuery({ device_id })), bad, `device ${JSON.stringify(device_id)}`);
    }
  });

  it('device ids of 8 and 64 characters with dash and underscore are accepted', () => {
    assert.strictEqual(resolve(['doitforthexp', 'monthly'], goodQuery({ device_id: 'a_b-c123' })).handled, true);
    assert.strictEqual(resolve(['doitforthexp', 'monthly'], goodQuery({ device_id: 'a'.repeat(64) })).handled, true);
  });
});

describe('XP Pro buy: catalog for Gatekeeper', () => {
  it('provide_plans returns the three plans', () => {
    const res = runPhpJson(`${PRO}
      $pro = new Xophz_Compass_Xp_Pro( 'xophz-compass-xp', '1' );
      echo json_encode( $pro->provide_plans( [] ) );`);
    assert.deepStrictEqual(Object.keys(res), ['monthly', 'yearly', 'lifetime']);
    assert.deepStrictEqual(res.monthly, { price: 4.99, mode: 'subscription', interval: 'month', trial_days: 7, store_product_id: 'xp_pro_monthly', on_sale: true });
    assert.deepStrictEqual(res.yearly, { price: 39.99, mode: 'subscription', interval: 'year', trial_days: 7, store_product_id: 'xp_pro_yearly', on_sale: true });
    assert.deepStrictEqual(res.lifetime, { price: 79, mode: 'payment', interval: null, trial_days: 0, store_product_id: 'xp_pro_lifetime', on_sale: true });
  });

  it('get_plans reports lifetime on_sale from its argument', () => {
    const res = runPhpJson(`${PRO}
      echo json_encode( [ Xophz_Compass_Xp_Pro::get_plans( false )['lifetime']['on_sale'], Xophz_Compass_Xp_Pro::get_plans( true )['lifetime']['on_sale'] ] );`);
    assert.deepStrictEqual(res, [false, true]);
  });

  it('hooks the Bazaar resolver, the Gatekeeper plans filter and the license product registry', () => {
    const res = runPhpJson(`${PRO}
      $pro = new Xophz_Compass_Xp_Pro( 'xophz-compass-xp', '1' );
      echo json_encode( $pro->filter_hooks );`);
    assert.deepStrictEqual(res, {
      xophz_resolve_buy_request: ['resolve_buy_request', 10, 3],
      gatekeeper_xp_pro_plans: ['provide_plans', 10, 1],
      gatekeeper_license_products: ['register_product', 10, 1],
    });
  });

  it('files carry no em dashes', () => {
    for (const rel of ['../includes/class-xophz-compass-xp-pro.php', 'xp-pro-buy.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
