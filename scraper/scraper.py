#!/usr/bin/env python3
"""
Panuts.com product scraper
==========================
Crawls https://panuts.com, extracts product data and writes products.json
to the output directory.

Environment variables:
  SCRAPER_OUTPUT_DIR  – where to write products.json (default: /output)
  SCRAPER_DELAY       – seconds between requests (default: 1.5)
  SCRAPER_MAX_PAGES   – max catalogue pages to crawl (default: 20)
"""

import json
import os
import re
import time
import urllib.robotparser
from dataclasses import dataclass, field, asdict
from typing import Optional
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
BASE_URL        = "https://panuts.com"
OUTPUT_DIR      = os.getenv("SCRAPER_OUTPUT_DIR", "/output")
DELAY           = float(os.getenv("SCRAPER_DELAY", "1.5"))
MAX_PAGES       = int(os.getenv("SCRAPER_MAX_PAGES", "20"))
OUTPUT_FILE     = os.path.join(OUTPUT_DIR, "products.json")

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (compatible; WCMeilisearchBot/1.0; "
        "+https://github.com/local/wc-meilisearch)"
    ),
    "Accept-Language": "es-CO,es;q=0.9,en;q=0.8",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
}

# ---------------------------------------------------------------------------
# Data model
# ---------------------------------------------------------------------------
@dataclass
class Product:
    name: str
    sku: str
    price: float
    regular_price: float
    sale_price: Optional[float]
    description: str
    short_description: str
    categories: list[str]
    tags: list[str]
    images: list[str]          # list of absolute URLs
    stock_status: str          # "instock" | "outofstock"
    url: str
    source: str = "panuts.com"
    external_id: str = ""
    attributes: dict = field(default_factory=dict)  # marca, pais, region, tipo, varietal, volumen

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

import unicodedata

ATTR_LABEL_MAP = {
    "marca":    "marca",
    "pais":     "pais",
    "país":     "pais",
    "region":   "region",
    "región":   "region",
    "tipo":     "tipo",
    "varietal": "varietal",
    "volumen":  "volumen",
}


def _norm(text: str) -> str:
    nfkd = unicodedata.normalize("NFKD", text.lower().strip())
    return "".join(c for c in nfkd if not unicodedata.combining(c))


def extract_attributes(soup: BeautifulSoup) -> dict:
    """Extract WooCommerce product attributes.

    Strategy 1: <dl class="woocommerce-product-attributes"> (works on most products).
    Strategy 2: JSON-LD additionalProperty (fallback for some products).
    """
    attrs: dict = {}

    # Strategy 1 – HTML attribute list
    dl = soup.select_one("dl.woocommerce-product-attributes, table.woocommerce-product-attributes")
    if dl:
        for dt, dd in zip(dl.select("dt"), dl.select("dd")):
            slug = ATTR_LABEL_MAP.get(_norm(dt.get_text(strip=True)))
            if slug:
                value = re.sub(r"\s+", " ", dd.get_text(" ", strip=True)).strip()
                if value:
                    attrs[slug] = value

    if attrs:
        return attrs

    # Strategy 2 – JSON-LD additionalProperty
    for script in soup.select('script[type="application/ld+json"]'):
        try:
            data = json.loads(script.string or "")
            if isinstance(data, list):
                data = next(
                    (d for d in data if isinstance(d, dict) and d.get("@type") == "Product"),
                    {}
                )
            if not isinstance(data, dict) or data.get("@type") != "Product":
                continue
            for prop in data.get("additionalProperty", []):
                name  = prop.get("name", "")
                value = prop.get("value", "")
                if not (name and value):
                    continue
                slug = ATTR_LABEL_MAP.get(_norm(name.replace("pa_", "")))
                if slug:
                    attrs[slug] = str(value).strip()
            if attrs:
                return attrs
        except Exception:
            pass

    return {}


def parse_price(text: str) -> float:
    """Strip currency symbols and parse float.

    Handles two formats:
    - English:  1,556.00  (comma=thousands, dot=decimal) → 1556.00
    - Spanish:  1.556,00  (dot=thousands,  comma=decimal) → 1556.00
    """
    if not text:
        return 0.0
    cleaned = re.sub(r"[^\d,\.]", "", text)
    cleaned = cleaned.strip(".")   # remove leading dots from "S/." currency prefix
    if "," in cleaned and "." in cleaned:
        comma_pos = cleaned.rfind(",")
        dot_pos   = cleaned.rfind(".")
        if dot_pos > comma_pos:
            # English format: 1,556.00 → remove commas
            cleaned = cleaned.replace(",", "")
        else:
            # Spanish format: 1.556,00 → remove dots, comma→dot
            cleaned = cleaned.replace(".", "").replace(",", ".")
    elif "," in cleaned:
        cleaned = cleaned.replace(",", ".")
    try:
        return float(cleaned)
    except ValueError:
        return 0.0


def get_page(session: requests.Session, url: str) -> Optional[BeautifulSoup]:
    """Fetch a page and return parsed BeautifulSoup, or None on error."""
    try:
        resp = session.get(url, headers=HEADERS, timeout=15)
        resp.raise_for_status()
        return BeautifulSoup(resp.text, "lxml")
    except Exception as exc:
        print(f"  [warn] Could not fetch {url}: {exc}")
        return None


# ---------------------------------------------------------------------------
# robots.txt check
# ---------------------------------------------------------------------------
def build_robot_parser() -> urllib.robotparser.RobotFileParser:
    """
    Fetch robots.txt using our requests session (with proper User-Agent) and
    parse it manually. Python's urllib.robotparser.read() does NOT send custom
    headers, so many sites return 403 — which the spec interprets as "disallow
    all". We avoid that by fetching ourselves and calling rp.parse(lines).
    """
    rp = urllib.robotparser.RobotFileParser()
    robots_url = urljoin(BASE_URL, "/robots.txt")
    rp.set_url(robots_url)

    try:
        import requests as _req
        resp = _req.get(robots_url, headers=HEADERS, timeout=10)
        if resp.status_code == 200:
            rp.parse(resp.text.splitlines())
        elif resp.status_code in (401, 403):
            # Server explicitly forbids access – treat as "disallow all".
            rp.disallow_all = True
        # For 404 or other errors: allow crawling (no restrictions declared).
    except Exception as exc:
        print(f"  [warn] Could not fetch robots.txt: {exc} – proceeding carefully.")

    return rp


# ---------------------------------------------------------------------------
# Product page parser
# ---------------------------------------------------------------------------
def parse_product_page(soup: BeautifulSoup, url: str) -> Optional[Product]:
    """Extract product data from a WooCommerce product page."""
    try:
        # Name
        name_el = (
            soup.select_one(".product_title")
            or soup.select_one("h1.entry-title")
            or soup.select_one("h1")
        )
        name = name_el.get_text(strip=True) if name_el else ""
        if not name:
            return None

        # SKU
        sku_el = soup.select_one(".sku")
        sku = sku_el.get_text(strip=True) if sku_el else ""

        # Prices — panuts uses <bdi> inside .price (not .amount)
        def extract_price(sel: str) -> float:
            el = soup.select_one(sel)
            if not el:
                return 0.0
            bdi = el.select_one("bdi")
            text = bdi.get_text(strip=True) if bdi else el.get_text(strip=True)
            return parse_price(text)

        sale_price_raw    = extract_price(".price ins bdi") or extract_price(".price ins .amount")
        regular_price_raw = extract_price(".price del bdi") or extract_price(".price del .amount")
        current_price_raw = (
            extract_price(".price > .woocommerce-Price-amount bdi")
            or extract_price(".price bdi")
            or extract_price(".price .amount")
        )

        current_price = sale_price_raw or current_price_raw
        regular_price = regular_price_raw if regular_price_raw else current_price
        sale_price    = sale_price_raw if regular_price_raw else None

        # Descriptions
        short_desc_el = soup.select_one(".woocommerce-product-details__short-description")
        short_desc    = short_desc_el.get_text(" ", strip=True) if short_desc_el else ""

        full_desc_el  = soup.select_one("#tab-description .woocommerce-Tabs-panel")
        if not full_desc_el:
            full_desc_el = soup.select_one(".entry-content")
        full_desc = full_desc_el.get_text(" ", strip=True) if full_desc_el else short_desc

        # Categories
        cats = []
        for el in soup.select(".posted_in a"):
            cats.append(el.get_text(strip=True))

        # Tags
        tags = []
        for el in soup.select(".tagged_as a"):
            tags.append(el.get_text(strip=True))

        # Images
        images = []
        for el in soup.select(".woocommerce-product-gallery__image a"):
            href = el.get("href", "")
            if href:
                images.append(href)
        if not images:
            for el in soup.select(".woocommerce-product-gallery__image img"):
                src = el.get("data-large_image") or el.get("src") or ""
                if src:
                    images.append(src)

        # Stock
        stock_el = soup.select_one(".stock")
        if stock_el:
            stock_text   = stock_el.get_text(strip=True).lower()
            stock_status = "instock" if "disponible" in stock_text or "in stock" in stock_text else "outofstock"
        elif soup.select_one(".single_add_to_cart_button"):
            stock_status = "instock"
        else:
            stock_status = "outofstock"

        # External ID from URL slug
        slug = urlparse(url).path.strip("/").split("/")[-1]

        # Attributes from JSON-LD structured data (marca, pais, region, tipo, varietal, volumen)
        attributes = extract_attributes(soup)

        return Product(
            name=name,
            sku=sku,
            price=current_price,
            regular_price=regular_price,
            sale_price=sale_price,
            description=full_desc[:2000],
            short_description=short_desc[:500],
            categories=cats,
            tags=tags,
            images=images[:5],
            stock_status=stock_status,
            url=url,
            external_id=slug,
            attributes=attributes,
        )

    except Exception as exc:
        print(f"  [warn] Error parsing product page {url}: {exc}")
        return None


# ---------------------------------------------------------------------------
# Catalogue crawler
# ---------------------------------------------------------------------------
def discover_product_urls(session: requests.Session, rp: urllib.robotparser.RobotFileParser) -> list[str]:
    """Crawl shop / category pages and collect product URLs."""
    product_urls: set[str] = set()

    # Entry points to try
    entry_points = [
        f"{BASE_URL}/tienda/",
        f"{BASE_URL}/shop/",
        f"{BASE_URL}/productos/",
        BASE_URL + "/",
    ]

    visited_cat_pages: set[str] = set()
    pages_to_visit = []

    for ep in entry_points:
        if rp.can_fetch("*", ep):
            pages_to_visit.append(ep)

    page_count = 0
    while pages_to_visit and page_count < MAX_PAGES:
        page_url = pages_to_visit.pop(0)
        if page_url in visited_cat_pages:
            continue
        visited_cat_pages.add(page_url)
        page_count += 1

        print(f"  Crawling catalogue page {page_count}: {page_url}")
        soup = get_page(session, page_url)
        if not soup:
            time.sleep(DELAY)
            continue

        # Collect product links
        for a in soup.select("a[href]"):
            href = a["href"]
            absolute = urljoin(BASE_URL, href)
            parsed   = urlparse(absolute)
            if parsed.netloc != urlparse(BASE_URL).netloc:
                continue
            path = parsed.path
            # WooCommerce product URLs typically contain /producto/ or /product/
            if re.search(r"/(producto|product)/[^/]+/?$", path):
                product_urls.add(absolute)

        # Follow pagination
        for a in soup.select(".next.page-numbers, a.next"):
            next_href = urljoin(BASE_URL, a.get("href", ""))
            if next_href not in visited_cat_pages and rp.can_fetch("*", next_href):
                pages_to_visit.append(next_href)

        # Follow category links (avoid pagination and misc links)
        for a in soup.select(".product-categories a, .widget_product_categories a"):
            cat_href = urljoin(BASE_URL, a.get("href", ""))
            if cat_href not in visited_cat_pages and rp.can_fetch("*", cat_href):
                pages_to_visit.append(cat_href)

        time.sleep(DELAY)

    return list(product_urls)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main() -> None:
    os.makedirs(OUTPUT_DIR, exist_ok=True)

    print("=" * 60)
    print("Panuts.com scraper – WC Meilisearch")
    print("=" * 60)
    print(f"Base URL   : {BASE_URL}")
    print(f"Output     : {OUTPUT_FILE}")
    print(f"Delay      : {DELAY}s between requests")
    print(f"Max pages  : {MAX_PAGES}")
    print()

    session = requests.Session()
    session.headers.update(HEADERS)

    # Robots.txt compliance.
    rp = build_robot_parser()
    ua = HEADERS["User-Agent"]
    if not rp.can_fetch(ua, BASE_URL + "/") and not rp.can_fetch("*", BASE_URL + "/"):
        print("[error] robots.txt explicitly disallows crawling. Aborting.")
        return

    # Phase 1 – discover product URLs
    print("Phase 1: Discovering product URLs…")
    product_urls = discover_product_urls(session, rp)
    print(f"  Found {len(product_urls)} unique product URL(s).")
    print()

    if not product_urls:
        print("[warn] No products found. Saving empty list.")
        with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
            json.dump([], f, ensure_ascii=False, indent=2)
        return

    # Phase 2 – scrape each product page
    print("Phase 2: Scraping product pages…")
    products: list[dict] = []

    for i, url in enumerate(sorted(product_urls), 1):
        if not rp.can_fetch("*", url):
            print(f"  [{i}/{len(product_urls)}] Skipped (robots.txt): {url}")
            continue

        print(f"  [{i}/{len(product_urls)}] {url}")
        soup = get_page(session, url)
        if soup:
            product = parse_product_page(soup, url)
            if product:
                products.append(asdict(product))
                print(f"    ✓ {product.name} | {product.price} | {product.stock_status}")
            else:
                print(f"    ✗ Could not parse product.")
        time.sleep(DELAY)

    # Phase 3 – write output
    print()
    print(f"Phase 3: Writing {len(products)} product(s) to {OUTPUT_FILE}")
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(products, f, ensure_ascii=False, indent=2)

    print()
    print("Done!")
    print(f"  Products scraped : {len(products)}")
    print(f"  Output file      : {OUTPUT_FILE}")


if __name__ == "__main__":
    main()
