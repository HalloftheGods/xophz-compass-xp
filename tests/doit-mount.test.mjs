/**
 * Do It For The XP mount: the build is uploaded to public/dist by hand, and only then
 * (or while the dev server is up) does XP mount it at a permalink.
 */

import { describe, it, assert } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';

const DOIT = "require_once '/var/www/html/wp-content/plugins/xophz-compass-xp/includes/class-xophz-compass-xp-doit.php';\n";

function php(code) {
  return runPhpJson(DOIT + code);
}

describe('Doit mount: availability', () => {
  it('is unavailable without a build and outside dev mode', () => {
    const res = php(`
      $missing = sys_get_temp_dir() . '/xp-doit-missing-' . uniqid() . '/index.html';
      echo json_encode(['available' => Xophz_Compass_Xp_Doit::is_available($missing, false)]);
    `);
    assert.strictEqual(res.available, false);
  });

  it('is available once index.html is uploaded', () => {
    const res = php(`
      $dir = sys_get_temp_dir() . '/xp-doit-' . uniqid();
      mkdir($dir);
      file_put_contents($dir . '/index.html', '<html></html>');
      $available = Xophz_Compass_Xp_Doit::is_available($dir . '/index.html', false);
      unlink($dir . '/index.html');
      rmdir($dir);
      echo json_encode(['available' => $available]);
    `);
    assert.strictEqual(res.available, true);
  });

  it('is available in dev mode without a build', () => {
    const res = php(`
      $missing = sys_get_temp_dir() . '/xp-doit-missing-' . uniqid() . '/index.html';
      echo json_encode(['available' => Xophz_Compass_Xp_Doit::is_available($missing, true)]);
    `);
    assert.strictEqual(res.available, true);
  });
});

describe('Doit mount: permalink setting', () => {
  const cases = [
    ['My App', 'my-app'],
    ['/play/doit/', 'play/doit'],
    ['', 'doit'],
    ['   ', 'doit'],
    ['wp-json', 'doit'],
    ['wp-admin/doit', 'doit'],
    ['../etc', 'etc'],
    ['Quest_Board!', 'quest-board'],
  ];

  for (const [input, expected] of cases) {
    it(`sanitizes ${JSON.stringify(input)} to ${expected}`, () => {
      const res = php(`echo json_encode(['slug' => Xophz_Compass_Xp_Doit::sanitize_slug(${JSON.stringify(input)})]);`);
      assert.strictEqual(res.slug, expected);
    });
  }

  it('keeps only the known load modes', () => {
    const res = php(`
      echo json_encode([
        'homepage' => Xophz_Compass_Xp_Doit::sanitize_load_mode('homepage'),
        'page'     => Xophz_Compass_Xp_Doit::sanitize_load_mode('specific_page'),
        'bogus'    => Xophz_Compass_Xp_Doit::sanitize_load_mode('takeover'),
      ]);
    `);
    assert.deepStrictEqual(res, { homepage: 'homepage', page: 'specific_page', bogus: 'routes_only' });
  });
});

describe('Doit mount: rewrite flush signature', () => {
  it('changes when the build appears, the slug changes, or the mode changes', () => {
    const res = php(`
      $base = Xophz_Compass_Xp_Doit::mount_signature(true, 'doit', 'routes_only', 0);
      echo json_encode([
        'stable'  => $base === Xophz_Compass_Xp_Doit::mount_signature(true, 'doit', 'routes_only', 0),
        'removed' => $base !== Xophz_Compass_Xp_Doit::mount_signature(false, 'doit', 'routes_only', 0),
        'slug'    => $base !== Xophz_Compass_Xp_Doit::mount_signature(true, 'app', 'routes_only', 0),
        'mode'    => $base !== Xophz_Compass_Xp_Doit::mount_signature(true, 'doit', 'homepage', 0),
        'page'    => $base !== Xophz_Compass_Xp_Doit::mount_signature(true, 'doit', 'specific_page', 7),
      ]);
    `);
    assert.deepStrictEqual(res, { stable: true, removed: true, slug: true, mode: true, page: true });
  });
});

describe('Doit mount: production HTML', () => {
  const DIST = '/wp-content/plugins/xophz-compass-xp/public/dist/';
  const BUILD = '<!DOCTYPE html><html><head><link rel="icon" href="favicon.ico"><script defer="defer" src="js/chunk-vendors.1a2b.js"></script><link href="./css/app.3c4d.css" rel="stylesheet"></head><body><a href="#/home">Home</a><img src="data:image/png;base64,AAAA"><script src="https://cdn.example.org/x.js"></script><script src="/already/absolute.js"></script><script src="//cdn.example.org/y.js"></script></body></html>';

  function rewrite() {
    return php(`
      echo json_encode(['html' => Xophz_Compass_Xp_Doit::prepare_html(${JSON.stringify(BUILD)}, ${JSON.stringify(DIST)}, 8100)]);
    `).html;
  }

  it('points relative build files at public/dist', () => {
    const html = rewrite();
    assert.ok(html.includes(`href="${DIST}favicon.ico"`), html);
    assert.ok(html.includes(`src="${DIST}js/chunk-vendors.1a2b.js"`), html);
    assert.ok(html.includes(`href="${DIST}css/app.3c4d.css"`), html);
  });

  it('leaves hash links, data URIs, absolute and external URLs alone', () => {
    const html = rewrite();
    assert.ok(html.includes('href="#/home"'), html);
    assert.ok(html.includes('src="data:image/png;base64,AAAA"'), html);
    assert.ok(html.includes('src="https://cdn.example.org/x.js"'), html);
    assert.ok(html.includes('src="/already/absolute.js"'), html);
    assert.ok(html.includes('src="//cdn.example.org/y.js"'), html);
  });

  it('tells webpack to load chunks from public/dist, before any bundle runs', () => {
    const html = rewrite();
    const marker = `window.__XP_PUBLIC_PATH__=${JSON.stringify(DIST)}`;
    assert.ok(html.includes(marker), html);
    assert.ok(html.indexOf(marker) < html.indexOf('<script defer'), 'public path must precede the bundle');
  });
});

describe('Doit mount: dev server HTML', () => {
  it('tells webpack to load chunks from the dev server and rewrites nothing else', () => {
    const dev = '<html><head><script defer src="//localhost:8100/js/app.js"></script></head><body></body></html>';
    const res = php(`
      echo json_encode(['html' => Xophz_Compass_Xp_Doit::prepare_html(${JSON.stringify(dev)}, '/dist/', 8100)]);
    `);
    assert.ok(res.html.includes('window.__XP_PUBLIC_PATH__="//localhost:8100/"'), res.html);
    assert.ok(res.html.includes('src="//localhost:8100/js/app.js"'), res.html);
    assert.ok(!res.html.includes('/dist/'), res.html);
  });
});
