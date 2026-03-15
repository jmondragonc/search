#!/usr/bin/env python3
"""
Playwright test: verify search + filter flow on development.panuts.com
"""

import re
import asyncio
from playwright.async_api import async_playwright


async def run_test():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        context = await browser.new_context(viewport={"width": 1280, "height": 800})
        page = await context.new_page()

        # ── Step 1: Go to the home page ──────────────────────────────────────
        print("Step 1: Navigating to https://development.panuts.com/ ...")
        await page.goto("https://development.panuts.com/", wait_until="networkidle", timeout=30000)
        print(f"  URL: {page.url}")

        # ── Step 2: Type "santa julia" in the AWS search input and press Enter ─
        print('\nStep 2: Typing "santa julia" in .aws-search-field and pressing Enter ...')
        search_input = page.locator(".aws-search-field").first
        await search_input.wait_for(state="visible", timeout=10000)
        await search_input.click()
        await search_input.fill("santa julia")
        await search_input.press("Enter")

        # ── Step 3: Wait for search results page ────────────────────────────
        print("\nStep 3: Waiting for search results page ...")
        await page.wait_for_load_state("networkidle", timeout=30000)
        url_after_search = page.url
        print(f"  URL after search: {url_after_search}")

        # Count products before filter
        product_items = page.locator("li.product, .product-item, ul.products > li")
        count_before = await product_items.count()
        print(f"  Product count before filter: {count_before}")

        # Also try to read a "showing X results" / woocommerce result count element
        result_count_el = page.locator(".woocommerce-result-count, .aws-result-count, .products-count")
        if await result_count_el.count() > 0:
            rc_text = await result_count_el.first.inner_text()
            print(f"  Result count text: {rc_text.strip()!r}")
        else:
            print("  (No result-count element found)")

        # ── Check form action before filter change ───────────────────────────
        print("\nStep 4: Checking searchandfilter form action BEFORE filter change ...")
        form_el = page.locator("form.searchandfilter, form[data-action], form[action]").first
        if await form_el.count() > 0:
            form_action_before = await form_el.get_attribute("action")
            print(f"  form action (before): {form_action_before!r}")
        else:
            print("  (No searchandfilter form found)")
            form_action_before = None

        # ── Step 5: Change "Orden" filter to "Precio: alto - bajo" ──────────
        print('\nStep 5: Changing "Orden" filter to "Precio: alto - bajo" ...')

        # Try WooCommerce native orderby select first
        orderby_sel = page.locator("select.orderby, select[name='orderby']")
        sf_order_sel = page.locator("select[name='orderby'], .widget_search_filter select")

        # Collect all <select> elements and their options for debugging
        selects = page.locator("select")
        sel_count = await selects.count()
        print(f"  Found {sel_count} <select> elements on page:")
        for i in range(sel_count):
            sel = selects.nth(i)
            name = await sel.get_attribute("name") or "(no name)"
            sel_id = await sel.get_attribute("id") or "(no id)"
            options_raw = await sel.inner_text()
            options_clean = options_raw.strip().replace("\n", " | ")
            print(f"    [{i}] name={name!r} id={sel_id!r}  options: {options_clean[:120]}")

        # Try to select by visible option text
        changed = False
        for i in range(sel_count):
            sel = selects.nth(i)
            options_html = await sel.inner_html()
            if "alto" in options_html.lower() or "precio" in options_html.lower() or "price" in options_html.lower():
                print(f"  -> Selecting 'Precio: alto - bajo' on select index {i}")
                try:
                    await sel.select_option(label=re.compile(r"alto", re.IGNORECASE))
                    changed = True
                except Exception:
                    # Fallback: try value "price-desc" or similar
                    try:
                        await sel.select_option(value="price-desc")
                        changed = True
                    except Exception:
                        pass
                if changed:
                    break

        if not changed:
            print("  WARNING: Could not find the Orden/price select. Trying WooCommerce orderby ...")
            try:
                await page.select_option("select.orderby", label=re.compile(r"alto", re.IGNORECASE))
                changed = True
            except Exception as e:
                print(f"  ERROR: {e}")

        if changed:
            print("  Filter changed. Waiting for page update ...")
            await page.wait_for_load_state("networkidle", timeout=30000)
        else:
            print("  Could not change filter.")

        # ── Step 6 & 7: Note URL and product count after filter ──────────────
        url_after_filter = page.url
        print(f"\nStep 6-7: URL after filter change: {url_after_filter}")

        product_items_after = page.locator("li.product, .product-item, ul.products > li")
        count_after = await product_items_after.count()
        print(f"  Product count after filter: {count_after}")

        result_count_el2 = page.locator(".woocommerce-result-count, .aws-result-count, .products-count")
        if await result_count_el2.count() > 0:
            rc_text2 = await result_count_el2.first.inner_text()
            print(f"  Result count text after filter: {rc_text2.strip()!r}")

        # ── Step 8: Check if URL still has s=santa+julia ────────────────────
        print("\nStep 8: Checking URL for search term preservation ...")
        has_search_param = "s=santa" in url_after_filter or "s=santa+julia" in url_after_filter
        print(f"  URL contains 's=santa+julia': {has_search_param}")
        print(f"  URL contains 's=': {'s=' in url_after_filter}")

        # ── Step 9: Check form action after filter ───────────────────────────
        print("\nStep 9: Checking searchandfilter form action AFTER filter change ...")
        form_el2 = page.locator("form.searchandfilter, form[action*='shop'], form[action*='s=']").first
        if await form_el2.count() > 0:
            form_action_after = await form_el2.get_attribute("action")
            print(f"  form action (after): {form_action_after!r}")
        else:
            # Try all forms
            forms = page.locator("form")
            f_count = await forms.count()
            print(f"  No searchandfilter form found directly. Checking all {f_count} forms ...")
            for i in range(f_count):
                f = forms.nth(i)
                action = await f.get_attribute("action") or ""
                cls = await f.get_attribute("class") or ""
                if "searchandfilter" in cls or "s=" in action or "shop" in action or "search" in action:
                    print(f"    Form [{i}]: class={cls!r} action={action!r}")
            form_action_after = None

        # ── Summary ──────────────────────────────────────────────────────────
        print("\n" + "=" * 60)
        print("SUMMARY")
        print("=" * 60)
        print(f"URL after search:        {url_after_search}")
        print(f"Product count BEFORE filter: {count_before}")
        print(f"URL after filter:        {url_after_filter}")
        print(f"Product count AFTER  filter: {count_after}")
        print(f"Search term preserved in URL: {has_search_param}")
        if form_action_before is not None:
            print(f"Form action BEFORE filter: {form_action_before!r}")
        if 'form_action_after' in dir() and form_action_after:
            print(f"Form action AFTER  filter: {form_action_after!r}")

        # Assessment
        print()
        if count_before <= 100 and count_after <= 100 and has_search_param:
            print("PASS: Search context (santa julia) preserved after filter change.")
        elif count_after > 500:
            print("FAIL: Product count jumped after filter change — search context was lost.")
        else:
            print("UNCERTAIN: Counts look reasonable but verify manually.")

        await browser.close()


asyncio.run(run_test())
