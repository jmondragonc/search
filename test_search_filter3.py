#!/usr/bin/env python3
"""
Playwright test (v3): verify search + filter flow on development.panuts.com
- Reads the SF form action before submitting
- After submit, re-checks form action on the result page
- Also captures the actual GET URL after submit to confirm form action
"""

import asyncio
import re
from playwright.async_api import async_playwright


def extract_total(text):
    m = re.search(r'de\s+(\d+)', text)
    return int(m.group(1)) if m else None


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
        rc_el = page.locator(".woocommerce-result-count").first
        result_count_before = await rc_el.inner_text()
        total_before = extract_total(result_count_before)

        print(f"  URL:               {url_after_search}")
        print(f"  Result count text: {result_count_before.strip()!r}  => total={total_before}")

        # ── Step 4: Form action BEFORE filter change ─────────────────────────
        print("\nStep 4: searchandfilter form state BEFORE filter change:")
        sf_form = page.locator("form.searchandfilter").first
        form_action_before = await sf_form.get_attribute("action")
        print(f"  form.action: {form_action_before!r}")

        # Read ALL form attributes via JS for completeness
        form_attrs = await sf_form.evaluate("""
            f => ({
                action: f.action,      // resolved absolute URL
                getAttribute: f.getAttribute('action'),
                method: f.method,
                className: f.className,
            })
        """)
        print(f"  form.action (resolved by browser): {form_attrs['action']!r}")
        print(f"  form getAttribute('action'):        {form_attrs['getAttribute']!r}")

        # ── Step 5: Change the sort-order select ────────────────────────────
        print('\nStep 5: Selecting "Precio: alto - bajo" in sort-order select ...')
        sort_sel = page.locator("select[name='_sf_sort_order[]']")
        await sort_sel.select_option(value="_sfm__price+desc+num")
        print("  Selected.")

        # Read form action again right after select change (before submit)
        form_action_presubmit = await sf_form.evaluate("f => f.action")
        print(f"  form.action just before submit (resolved): {form_action_presubmit!r}")
        form_action_attr_presubmit = await sf_form.get_attribute("action")
        print(f"  form getAttribute('action') before submit: {form_action_attr_presubmit!r}")

        # Intercept the outgoing navigation URL
        navigated_url = {"value": None}

        async def on_request(request):
            if request.resource_type == "document":
                navigated_url["value"] = request.url

        page.on("request", on_request)

        # Submit the form
        print("  Submitting form via JS ...")
        async with page.expect_navigation(wait_until="networkidle", timeout=30000):
            await page.evaluate("document.querySelector('form.searchandfilter').submit()")

        print(f"  Navigated to: {navigated_url['value']!r}")

        # ── Steps 6-7 ────────────────────────────────────────────────────────
        url_after_filter = page.url
        rc_el2 = page.locator(".woocommerce-result-count")
        result_count_after = ""
        if await rc_el2.count() > 0:
            result_count_after = await rc_el2.first.inner_text()
        total_after = extract_total(result_count_after)

        print(f"\nStep 6-7:")
        print(f"  Final URL after filter: {url_after_filter}")
        print(f"  Result count text:      {result_count_after.strip()!r}  => total={total_after}")

        # ── Step 8 ──────────────────────────────────────────────────────────
        has_search = "s=santa" in url_after_filter
        print(f"\nStep 8: URL preserves s=santa[+julia]: {has_search}")

        # ── Step 9: Form action AFTER filter ─────────────────────────────────
        print("\nStep 9: searchandfilter form state AFTER filter change:")
        sf_forms2 = page.locator("form.searchandfilter")
        sf_count2 = await sf_forms2.count()
        form_action_after = None
        if sf_count2 > 0:
            form_action_after = await sf_forms2.first.evaluate("f => f.action")
            form_action_after_attr = await sf_forms2.first.get_attribute("action")
            print(f"  form.action (resolved): {form_action_after!r}")
            print(f"  form getAttribute('action'): {form_action_after_attr!r}")
        else:
            print("  No form.searchandfilter found on result page.")

        # ── SUMMARY ─────────────────────────────────────────────────────────
        print()
        print("=" * 70)
        print("SUMMARY")
        print("=" * 70)
        print(f"URL after search:                      {url_after_search}")
        print(f"Total products BEFORE filter:          {total_before}  ({result_count_before.strip()})")
        print(f"Form action BEFORE filter (attr):      {form_action_before!r}")
        print(f"Form action BEFORE filter (resolved):  {form_attrs['action']!r}")
        print(f"Form action just before submit (res):  {form_action_presubmit!r}")
        print(f"Actual navigation URL on submit:       {navigated_url['value']!r}")
        print()
        print(f"URL after filter change:               {url_after_filter}")
        print(f"Total products AFTER  filter:          {total_after}  ({result_count_after.strip()})")
        print(f"Search term (s=santa) in URL:          {has_search}")
        print(f"Form action AFTER filter (resolved):   {form_action_after!r}")

        print()
        print("Verdicts:")
        count_ok  = total_before is not None and total_after is not None and abs(total_after - total_before) <= 5
        url_ok    = has_search
        # The form action (resolved) should NOT be just /shop/ — it should carry the search context
        action_ok = form_action_after is not None and (
            "s=santa" in (form_action_after or "") or
            "post_type=product" in (form_action_after or "")
        )
        nav_ok    = navigated_url["value"] and "s=santa" in navigated_url["value"]

        print(f"  Product count preserved (~{total_before}):       {'PASS' if count_ok else 'FAIL'}")
        print(f"  URL preserves search term after filter: {'PASS' if url_ok else 'FAIL'}")
        print(f"  Form submits to search-aware URL:       {'PASS' if nav_ok else 'FAIL'} (submitted to {navigated_url['value']!r})")
        print(f"  Form action AFTER includes search ctx:  {'PASS' if action_ok else 'FAIL (action=' + str(form_action_after) + ')'}")

        await browser.close()


asyncio.run(run_test())
