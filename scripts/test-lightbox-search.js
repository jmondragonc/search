/**
 * Playwright test: Lightbox "Buscar" button search flow on development.panuts.com
 * Tests whether s=santa+julia is preserved after changing the Orden filter.
 *
 * Key findings from initial run:
 * - The lightbox trigger is #wcm-header-trigger (inside #wcm-header-bar)
 * - The lightbox overlay is #wcm-lightbox-overlay with class wcm-is-classic
 * - The lightbox search input ID is wcm-header-input
 * - AWS search is separate (id: 69b5af280c3c8, class: aws-search-field) — NOT what we want
 */

const { chromium } = require('/Users/joseph/.nvm/versions/node/v23.11.1/lib/node_modules/@playwright/test/node_modules/playwright');
const path = require('path');
const fs = require('fs');

const SCREENSHOTS_DIR = path.join(__dirname, '../screenshots-lightbox-test');

if (!fs.existsSync(SCREENSHOTS_DIR)) {
  fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
}

async function screenshot(page, name) {
  const file = path.join(SCREENSHOTS_DIR, `${name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  console.log(`  [screenshot] ${file}`);
}

async function main() {
  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  const context = await browser.newContext({
    viewport: { width: 1400, height: 900 },
    locale: 'es-ES',
  });

  const consoleLogs = [];
  const jsErrors = [];

  const page = await context.newPage();

  page.on('console', msg => {
    const text = `[${msg.type()}] ${msg.text()}`;
    consoleLogs.push(text);
    if (msg.type() === 'error') {
      jsErrors.push(text);
    }
  });

  page.on('pageerror', err => {
    jsErrors.push(`[pageerror] ${err.message}`);
  });

  // -----------------------------------------------------------------------
  console.log('\n=== STEP 1: Navigate to https://development.panuts.com/ ===');
  await page.goto('https://development.panuts.com/', { waitUntil: 'networkidle', timeout: 30000 });
  const url1 = page.url();
  console.log('  URL:', url1);
  await screenshot(page, '01-homepage');

  // -----------------------------------------------------------------------
  console.log('\n=== STEP 2: Click the "Buscar" lightbox trigger (#wcm-header-trigger) ===');

  // The trigger is inside #wcm-header-bar > #wcm-header-inner > #wcm-header-trigger
  // It has a child <span> with "Buscar" text
  const trigger = page.locator('#wcm-header-trigger').first();
  const triggerCount = await trigger.count();
  console.log('  #wcm-header-trigger found:', triggerCount > 0);

  if (triggerCount > 0) {
    const box = await trigger.boundingBox();
    console.log('  Trigger bounding box:', JSON.stringify(box));
    await trigger.click();
    console.log('  Clicked #wcm-header-trigger');
  } else {
    // Fallback: find via text
    console.log('  Fallback: clicking span/a with text "Buscar" in right header area');
    await page.evaluate(() => {
      const all = [...document.querySelectorAll('#wcm-header-bar *, a, button, span')];
      const el = all.find(e => e.textContent.trim().includes('Buscar') && e.getBoundingClientRect().x > 600);
      if (el) el.click();
    });
  }

  // Wait for the wcm lightbox overlay to become visible
  console.log('  Waiting for #wcm-lightbox-overlay to appear...');
  await page.waitForSelector('#wcm-lightbox-overlay.wcm-open', { timeout: 10000 }).catch(async () => {
    console.log('  wcm-lightbox-overlay.wcm-open not found, checking current state...');
    const overlayClass = await page.evaluate(() => {
      const el = document.getElementById('wcm-lightbox-overlay');
      return el ? { class: el.className, display: getComputedStyle(el).display } : 'not found';
    });
    console.log('  Overlay state:', JSON.stringify(overlayClass));
  });

  await page.waitForTimeout(1000);
  await screenshot(page, '03-lightbox-opened');

  // Dump lightbox HTML
  const lightboxHTML = await page.evaluate(() => {
    const el = document.getElementById('wcm-lightbox-overlay');
    return el ? el.outerHTML.substring(0, 3000) : 'not found';
  });
  console.log('  Lightbox HTML:\n', lightboxHTML.substring(0, 2000));

  // -----------------------------------------------------------------------
  console.log('\n=== STEP 3: Type "santa julia" in the lightbox input (#wcm-header-input) ===');

  // The lightbox input is #wcm-header-input (visible when lightbox is open)
  const lightboxInput = page.locator('#wcm-lightbox-overlay input').first();
  const lightboxInputCount = await lightboxInput.count();
  console.log('  #wcm-lightbox-overlay input count:', lightboxInputCount);

  if (lightboxInputCount === 0) {
    // Try the ID directly
    const byId = page.locator('#wcm-header-input');
    const byIdCount = await byId.count();
    console.log('  #wcm-header-input count:', byIdCount);
  }

  // Use a more flexible locator
  const searchInput = page.locator('#wcm-lightbox-overlay input, #wcm-header-input').first();
  await searchInput.waitFor({ state: 'visible', timeout: 10000 }).catch(e => {
    console.log('  Input not visible:', e.message);
  });

  const inputBox = await searchInput.boundingBox().catch(() => null);
  console.log('  Input bounding box:', JSON.stringify(inputBox));

  await searchInput.click();
  await searchInput.fill('santa julia');
  console.log('  Typed "santa julia"');

  await page.waitForTimeout(800);
  await screenshot(page, '04-typed-query');

  // -----------------------------------------------------------------------
  console.log('\n=== STEP 4: Press Enter or click "Ver todos" ===');

  // Check for "Ver todos" link
  const verTodosLink = page.locator('a:has-text("Ver todos"), a:has-text("ver todos"), .wcm-all-results, [class*="ver-todos"]').first();
  const vtCount = await verTodosLink.count();
  console.log('  "Ver todos" link count:', vtCount);

  if (vtCount > 0) {
    const vtBox = await verTodosLink.boundingBox();
    console.log('  "Ver todos" box:', JSON.stringify(vtBox));
    await screenshot(page, '05-ver-todos-visible');

    const vtHref = await verTodosLink.getAttribute('href');
    console.log('  "Ver todos" href:', vtHref);

    await verTodosLink.click();
    console.log('  Clicked "Ver todos"');
  } else {
    console.log('  No "Ver todos" found, pressing Enter...');
    // Check what links exist in the lightbox
    const lightboxLinks = await page.evaluate(() => {
      const overlay = document.getElementById('wcm-lightbox-overlay');
      if (!overlay) return [];
      return [...overlay.querySelectorAll('a')].map(a => ({
        text: a.textContent.trim().substring(0, 50),
        href: a.href,
        class: a.className,
      }));
    });
    console.log('  Links inside lightbox:', JSON.stringify(lightboxLinks));

    await searchInput.press('Enter');
    console.log('  Pressed Enter');
  }

  // -----------------------------------------------------------------------
  console.log('\n=== STEP 5: Check URL after search ===');
  await page.waitForLoadState('networkidle', { timeout: 25000 }).catch(() => {});
  await page.waitForTimeout(2000);
  const urlAfterSearch = page.url();
  console.log('  URL after search:', urlAfterSearch);
  console.log('  Has s=santa:', urlAfterSearch.includes('s=santa') || urlAfterSearch.includes('s=Santa'));
  console.log('  Has post_type=product:', urlAfterSearch.includes('post_type=product'));
  await screenshot(page, '06-search-results-page');

  // -----------------------------------------------------------------------
  console.log('\n=== STEP 6: Count products on results page ===');
  await page.waitForTimeout(1500);

  const productInfo = await page.evaluate(() => {
    const selectors = [
      'ul.products li.product',
      '.products .product',
      '.woocommerce ul.products li',
      'article.product',
      '.facetwp-template .product',
      '.product-item',
      '[class*="product-grid"] [class*="product-item"]',
    ];
    for (const s of selectors) {
      const els = document.querySelectorAll(s);
      if (els.length > 0) return { selector: s, count: els.length };
    }
    return { selector: 'none', count: 0 };
  });
  console.log('  Products found:', JSON.stringify(productInfo));

  const resultCountText = await page.evaluate(() => {
    const selectors = ['.woocommerce-result-count', '.result-count', '.facetwp-counts', '.facetwp-pager-label', '[class*="result"]'];
    for (const s of selectors) {
      const el = document.querySelector(s);
      if (el && el.textContent.trim()) return { selector: s, text: el.textContent.trim() };
    }
    return null;
  });
  console.log('  Result count text:', JSON.stringify(resultCountText));

  // -----------------------------------------------------------------------
  console.log('\n=== STEP 7: Inspect form.searchandfilter action attribute ===');

  const formInfo = await page.evaluate(() => {
    // Try Search & Filter Pro form
    const sfForms = [...document.querySelectorAll('form')].filter(f =>
      f.className.includes('searchandfilter') || f.id.includes('searchandfilter') ||
      f.action.includes('searchandfilter') || f.querySelector('[name="_sft_s"]') !== null
    );

    if (sfForms.length > 0) {
      return sfForms.map(f => ({
        id: f.id,
        class: f.className,
        action: f.action,
        method: f.method,
        hiddenFields: [...f.querySelectorAll('input[type="hidden"]')].map(i => ({ name: i.name, value: i.value })),
        sField: (() => {
          const el = f.querySelector('[name="s"], [name="_sft_s"]');
          return el ? { name: el.name, value: el.value, type: el.type } : null;
        })(),
      }));
    }

    // Fallback: all forms
    return [...document.querySelectorAll('form')].map(f => ({
      id: f.id,
      class: f.className.substring(0, 80),
      action: f.action,
      method: f.method,
    }));
  });
  console.log('  Form info:', JSON.stringify(formInfo, null, 2));

  // -----------------------------------------------------------------------
  console.log('\n=== STEP 8: Change "Orden" filter to "Precio: alto - bajo" ===');

  const allSelects = await page.evaluate(() => {
    return [...document.querySelectorAll('select')].map(el => ({
      name: el.name,
      id: el.id,
      class: el.className.substring(0, 60),
      currentValue: el.value,
      options: [...el.options].map(o => ({ value: o.value, text: o.text.trim() })),
      rect: (() => { const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width) }; })(),
    }));
  });
  console.log('  All selects on page:', JSON.stringify(allSelects, null, 2));

  await screenshot(page, '08-before-filter');

  // Try standard WooCommerce orderby
  let filteredWithSelect = false;
  for (const sel of ['select.orderby', 'select[name="orderby"]', '.woocommerce-ordering select']) {
    const el = page.locator(sel).first();
    if (await el.count() > 0) {
      const opts = await el.evaluate(e => [...e.options].map(o => ({ value: o.value, text: o.text.trim() })));
      console.log(`  Orderby options (${sel}):`, JSON.stringify(opts));

      const target = opts.find(o =>
        o.text.toLowerCase().includes('alto') ||
        o.value === 'price-desc' || o.value === 'price_high' ||
        o.text.toLowerCase().includes('price') && o.text.toLowerCase().includes('high')
      );

      if (target) {
        console.log('  Selecting:', JSON.stringify(target));
        await el.selectOption(target.value);
        filteredWithSelect = true;
        break;
      }
    }
  }

  if (!filteredWithSelect) {
    // Try Search & Filter Pro or FacetWP sort selects
    for (const info of allSelects) {
      const priceDec = info.options.find(o =>
        o.text.toLowerCase().includes('alto') ||
        o.value.includes('price') ||
        o.text.toLowerCase().includes('precio')
      );
      if (priceDec) {
        console.log(`  Found price-sort option in select[name="${info.name}"]`, JSON.stringify(priceDec));
        const el = page.locator(`select[name="${info.name}"]`).first();
        await el.selectOption(priceDec.value);
        filteredWithSelect = true;
        break;
      }
    }
  }

  if (!filteredWithSelect) {
    console.log('  Could not find an "alto-bajo" price sort option in any select. Looking for FacetWP or other controls...');
    // Check for FacetWP sort
    const facetwpSortHTML = await page.evaluate(() => {
      const el = document.querySelector('.facetwp-sort, [data-name="sort"], [data-type="sort"]');
      return el ? el.outerHTML.substring(0, 500) : null;
    });
    console.log('  FacetWP sort HTML:', facetwpSortHTML);
  }

  // -----------------------------------------------------------------------
  console.log('\n=== STEP 9: Check URL after filter change ===');
  await page.waitForLoadState('networkidle', { timeout: 25000 }).catch(() => {});
  await page.waitForTimeout(2000);
  const urlAfterFilter = page.url();
  console.log('  URL after filter:', urlAfterFilter);

  const sPreserved = urlAfterFilter.toLowerCase().includes('s=santa');
  const sBecameEmpty = /[?&]s=(&|$)/.test(urlAfterFilter);
  console.log('  s=santa+julia preserved:', sPreserved);
  console.log('  s= became empty:', sBecameEmpty);

  await screenshot(page, '09-after-filter-change');

  // Count products after filter
  const productInfoAfter = await page.evaluate(() => {
    const selectors = [
      'ul.products li.product',
      '.products .product',
      'article.product',
      '.product-item',
    ];
    for (const s of selectors) {
      const els = document.querySelectorAll(s);
      if (els.length > 0) return { selector: s, count: els.length };
    }
    return { selector: 'none', count: 0 };
  });
  console.log('  Products after filter:', JSON.stringify(productInfoAfter));

  const resultCountAfter = await page.evaluate(() => {
    const selectors = ['.woocommerce-result-count', '.result-count', '.facetwp-counts'];
    for (const s of selectors) {
      const el = document.querySelector(s);
      if (el && el.textContent.trim()) return { selector: s, text: el.textContent.trim() };
    }
    return null;
  });
  console.log('  Result count text after filter:', JSON.stringify(resultCountAfter));

  // -----------------------------------------------------------------------
  console.log('\n=== JS ERRORS (accumulated) ===');
  if (jsErrors.length === 0) {
    console.log('  No JS errors detected');
  } else {
    jsErrors.forEach(e => console.log(' ', e));
  }

  console.log('\n=== ALL CONSOLE MESSAGES (errors & warnings only) ===');
  consoleLogs.filter(l => l.startsWith('[error]') || l.startsWith('[warning]')).forEach(l => console.log(' ', l));

  // -----------------------------------------------------------------------
  console.log('\n\n======================================================');
  console.log('                    FINAL SUMMARY');
  console.log('======================================================');
  console.log('Step 1 - Homepage URL           :', url1);
  console.log('Step 5 - URL after search       :', urlAfterSearch);
  console.log('       - s=santa+julia present  :', urlAfterSearch.toLowerCase().includes('s=santa'));
  console.log('       - post_type=product      :', urlAfterSearch.includes('post_type=product'));
  console.log('Step 6 - Products before filter :', productInfo.count, `(via ${productInfo.selector})`);
  console.log('       - Result text            :', resultCountText ? resultCountText.text : 'n/a');
  console.log('Step 9 - URL after filter       :', urlAfterFilter);
  console.log('       - s=santa+julia preserved:', sPreserved);
  console.log('       - s= became empty        :', sBecameEmpty);
  console.log('       - Products after filter  :', productInfoAfter.count, `(via ${productInfoAfter.selector})`);
  console.log('       - Result text            :', resultCountAfter ? resultCountAfter.text : 'n/a');
  console.log('JS errors                       :', jsErrors.length);
  console.log('Screenshots dir                 :', SCREENSHOTS_DIR);
  console.log('======================================================\n');

  await browser.close();
}

main().catch(err => {
  console.error('FATAL:', err);
  process.exit(1);
});
