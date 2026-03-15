#!/usr/bin/env python3
"""
Playwright test (v2): verify search + filter flow on development.panuts.com
Fixes: proper select_option call, form action check on the SF form specifically.
"""

import asyncio
from playwright.async_api import async_playwright


async def run_test():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        context = await browser.new_context(viewport={"width": 1280, "height": 800})
        page = await context.new_page()

        # ── Step 1 ───────────────────────────────────────────────────────────
        print("Step 1: Navigating to https://development.panuts.com/ ...")
        await page.goto("https://development.panuts.com/", wait_until="networkidle", timeout=30000)

        # ── Step 2 ───────────────────────────────────────────────────────────
        print('Step 2: Searching for "santa julia" ...')
        search_input = page.locator(".aws-search-field").first
        await search_input.wait_for(state="visible", timeout=10000)
        await search_input.fill("santa julia")
        await search_input.press("Enter")

        # ── Step 3 ───────────────────────────────────────────────────────────
        print("Step 3: Waiting for search results ...")
        await page.wait_for_load_state("networkidle", timeout=30000)

        url_after_search = page.url
        result_count_before = await page.locator(".woocommerce-result-count").first.inner_text()
        product_items_before = await page.locator("ul.products > li").count()

        print(f"  URL:                  {url_after_search}")
        print(f"  Result count text:    {result_count_before.strip()!r}")
        print(f"  Visible product cards: {product_items_before}")

        # ── Step 4: Check form action BEFORE filter change ───────────────────
        print("\nStep 4: searchandfilter form action BEFORE filter change:")
        # The Search & Filter plugin form typically has class "searchandfilter"
        sf_forms = page.locator("form.searchandfilter")
        sf_count = await sf_forms.count()
        form_action_before = None
        for i in range(sf_count):
            action = await sf_forms.nth(i).get_attribute("action")
            print(f"  SF form [{i}] action: {action!r}")
            if i == 0:
                form_action_before = action

        # ── Step 5: Change the sort-order select ────────────────────────────
        print('\nStep 5: Changing sort order to "Precio: alto - bajo" ...')
        sort_sel = page.locator("select[name='_sf_sort_order[]']")
        await sort_sel.wait_for(state="visible", timeout=10000)

        # Read available options for logging
        options = await sort_sel.evaluate("""
            sel => Array.from(sel.options).map(o => ({value: o.value, text: o.text}))
        """)
        print(f"  Available options: {options}")

        # Pick the "alto" option by its text
        alto_option = next((o for o in options if "alto" in o["text"].lower()), None)
        if alto_option is None:
            print("  ERROR: Could not find 'alto' option in sort select!")
        else:
            print(f"  Selecting option: {alto_option}")
            await sort_sel.select_option(value=alto_option["value"])

            # Search & Filter forms usually submit via a button or auto-submit
            # Try clicking the submit button first
            submit_btn = page.locator("form.searchandfilter input[type='submit'], form.searchandfilter button[type='submit']").first
            if await submit_btn.count() > 0:
                print("  Clicking submit button ...")
                await submit_btn.click()
            else:
                print("  No submit button — triggering form submit via JS ...")
                await page.evaluate("document.querySelector('form.searchandfilter').submit()")

            await page.wait_for_load_state("networkidle", timeout=30000)

        # ── Steps 6-7: URL and count after filter ───────────────────────────
        url_after_filter = page.url
        result_count_after_el = page.locator(".woocommerce-result-count")
        result_count_after = ""
        if await result_count_after_el.count() > 0:
            result_count_after = await result_count_after_el.first.inner_text()
        product_items_after = await page.locator("ul.products > li").count()

        print(f"\nStep 6-7:")
        print(f"  URL after filter:          {url_after_filter}")
        print(f"  Result count text (after): {result_count_after.strip()!r}")
        print(f"  Visible product cards:     {product_items_after}")

        # ── Step 8: URL preserves search term? ──────────────────────────────
        has_search = "s=santa" in url_after_filter
        print(f"\nStep 8: URL still contains s=santa[+julia]: {has_search}")

        # ── Step 9: form action AFTER filter ────────────────────────────────
        print("\nStep 9: searchandfilter form action AFTER filter change:")
        sf_forms2 = page.locator("form.searchandfilter")
        sf_count2 = await sf_forms2.count()
        form_action_after = None
        for i in range(sf_count2):
            action = await sf_forms2.nth(i).get_attribute("action")
            print(f"  SF form [{i}] action: {action!r}")
            if i == 0:
                form_action_after = action

        # ── SUMMARY ─────────────────────────────────────────────────────────
        print()
        print("=" * 65)
        print("SUMMARY")
        print("=" * 65)
        print(f"URL after search:                 {url_after_search}")
        print(f"Result count BEFORE filter:       {result_count_before.strip()!r}")
        print(f"URL after filter change:          {url_after_filter}")
        print(f"Result count AFTER  filter:       {result_count_after.strip()!r}")
        print(f"Search term (s=santa) in URL:     {has_search}")
        print(f"Form action BEFORE filter change: {form_action_before!r}")
        print(f"Form action AFTER  filter change: {form_action_after!r}")

        print()

        # Parse total from result count text, e.g. "Mostrando 1–12 de 63 resultados"
        import re
        def extract_total(text):
            m = re.search(r'de\s+(\d+)', text)
            return int(m.group(1)) if m else None

        total_before = extract_total(result_count_before)
        total_after  = extract_total(result_count_after) if result_count_after else None

        print(f"Total products BEFORE filter: {total_before}")
        print(f"Total products AFTER  filter: {total_after}")

        # Verdicts
        count_ok  = total_before is not None and total_after is not None and abs(total_after - total_before) <= 5
        url_ok    = has_search
        # Expected: action should include s=santa+julia (not just /shop/)
        action_ok = form_action_after is not None and ("s=santa" in form_action_after or "post_type=product" in form_action_after)

        print()
        print("Verdicts:")
        print(f"  Product count preserved (~{total_before}): {'PASS' if count_ok else 'FAIL'}")
        print(f"  URL preserves search term:               {'PASS' if url_ok else 'FAIL'}")
        print(f"  Form action includes search context:     {'PASS' if action_ok else 'FAIL (action=' + str(form_action_after) + ')'}")

        await browser.close()


asyncio.run(run_test())
