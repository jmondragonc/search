#!/usr/bin/env python3
"""
Attribute enrichment script
============================
Reads the existing products.json and fetches WooCommerce attributes
from each product's JSON-LD structured data on panuts.com.

Adds an "attributes" dict to each product with keys:
  marca, pais, region, tipo, varietal, volumen

Much faster than a full re-scrape because it only fetches the JSON-LD
from each product page (no catalogue discovery, no image scraping).

Usage (inside the scraper container):
  python enrich-attributes.py
"""

import json
import os
import time
from typing import Optional

import requests
from bs4 import BeautifulSoup

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
OUTPUT_DIR  = os.getenv("SCRAPER_OUTPUT_DIR", "/output")
INPUT_FILE  = os.path.join(OUTPUT_DIR, "products.json")
OUTPUT_FILE = INPUT_FILE            # overwrite in-place
DELAY       = float(os.getenv("SCRAPER_DELAY", "1.0"))

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (compatible; WCMeilisearchBot/1.0; "
        "+https://github.com/local/wc-meilisearch)"
    ),
    "Accept-Language": "es-PE,es;q=0.9,en;q=0.8",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
}

# Attribute slugs we care about (pa_* prefix used by panuts WooCommerce)
WANTED_ATTRS = {"marca", "pais", "region", "tipo", "varietal", "volumen"}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def fetch_attributes(session: requests.Session, url: str) -> dict:
    """Fetch a product page and extract attributes from JSON-LD."""
    try:
        resp = session.get(url, headers=HEADERS, timeout=15)
        resp.raise_for_status()
        soup = BeautifulSoup(resp.text, "lxml")

        for script in soup.select('script[type="application/ld+json"]'):
            try:
                data = json.loads(script.string or "")
                # JSON-LD may be a list
                if isinstance(data, list):
                    data = next(
                        (d for d in data if isinstance(d, dict) and d.get("@type") == "Product"),
                        {}
                    )
                if not isinstance(data, dict) or data.get("@type") != "Product":
                    continue

                attrs: dict = {}
                for prop in data.get("additionalProperty", []):
                    name  = prop.get("name", "")
                    value = prop.get("value", "")
                    if not (name and value):
                        continue
                    # Normalise: "pa_marca" → "marca"
                    key = name.replace("pa_", "").strip().lower()
                    if key in WANTED_ATTRS:
                        attrs[key] = str(value).strip()

                if attrs:
                    return attrs
            except Exception:
                pass
    except Exception as exc:
        print(f"    [warn] {url}: {exc}")
    return {}


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main() -> None:
    if not os.path.exists(INPUT_FILE):
        print(f"[error] {INPUT_FILE} not found. Run the scraper first.")
        return

    with open(INPUT_FILE, encoding="utf-8") as f:
        products: list[dict] = json.load(f)

    total = len(products)
    print("=" * 60)
    print(f"Attribute enrichment – {total} products")
    print("=" * 60)

    session = requests.Session()
    enriched = 0
    skipped  = 0

    for i, product in enumerate(products, 1):
        url = product.get("url", "")
        if not url:
            skipped += 1
            continue

        print(f"  [{i}/{total}] {url}")
        attrs = fetch_attributes(session, url)
        product["attributes"] = attrs

        if attrs:
            enriched += 1
            print(f"    ✓ {attrs}")
        else:
            print(f"    – (no attributes found)")

        time.sleep(DELAY)

    print()
    print(f"Writing enriched data to {OUTPUT_FILE}…")
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(products, f, ensure_ascii=False, indent=2)

    print()
    print("Done!")
    print(f"  Products enriched : {enriched}/{total}")
    print(f"  Skipped           : {skipped}")


if __name__ == "__main__":
    main()
