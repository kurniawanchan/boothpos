#!/usr/bin/env python3
"""Inline the WebP screenshots into the deck template as data URIs.

The Artifact CSP blocks every external image host, and a published page has no
access to the repo's files, so <img src="screenshots/..."> would render as
silent broken boxes. Base64 data URIs are the only thing that survives publishing.
"""
import base64, pathlib, re, sys

BASE = pathlib.Path("/Users/chan/www/boothpos/docs/presentation")
TPL = BASE / "deck.template.html"
OUT = BASE / "boothpos-presentation.html"
WEB = BASE / "web"
DATE = "10 September 2026"

# Human-readable alt text per shot: the deck is a sales document, and a
# screenshot with no alt is invisible to anyone using a screen reader.
ALT = {
    "01-login": "BoothPOS sign-in screen with username and password fields",
    "03-pos": "Point of sale screen with the product grid, seller and category filters and an empty cart",
    "13-purchase-orders": "Purchase order list with supplier, status, date and value",
    "19-roles": "Role list showing the four roles that ship with the product",
    "21-settings-payment": "Invoice payment settings storing bank details and payment instructions",
    "22-profile": "Self-service profile screen for changing password and photo",
    "25-invoices": "Invoice list with unpaid, paid and total summary tiles",
    "26-business-types": "Business type list used when onboarding a company",
    "29-pos-payment": "Payment panel with tendered-cash shortcuts and automatic change",
    "37-sales-items-popup": "Products sold popup listing the items behind a past transaction",
    "41-product-detail": "Product detail showing each variant with its item code, price and stock",
    "43-stock-adjustment": "Stock adjustment dialog requiring a reason for each corrected line",
    "44-material-vendor-prices": "Vendor prices dialog for one material with a preferred supplier flag",
    "46-company-detail": "Company edit form with business type, licence package and contact details",
    "47-session-overview": "Cashier session overview with live transaction count and takings",
    "50-event-detail": "Event edit form with name, location, dates and event cost",
    "02-dashboard": "BoothPOS dashboard showing net sales, transaction count, gross profit and a per-seller results panel for the running event",
    "04-cashier-session": "Cashier session screen for opening a shift with a counted cash float",
    "05-sales": "Sales list showing every transaction with its number, customer, sellers, cashier and total",
    "06-preorders": "Pre-order list with status filters and a summary bar of counts and outstanding balance",
    "07-products": "Product management screen listing products with their sellers, categories, codes and variant counts",
    "08-stock": "Stock screen showing the append-only movement history with before and after levels",
    "09-sellers": "Seller directory listing the artists sharing the booth",
    "10-categories": "Category management screen with codes and display order",
    "11-customers": "Customer records with contact details and purchase history",
    "12-events": "Event list with location, dates and event cost",
    "14-vendors": "Supplier directory with contact details",
    "15-materials": "Raw material catalogue with units and reference prices",
    "16-reports": "Seller recap report showing units, sales, payable, paid and outstanding per artist",
    "17-activity-log": "Activity log listing who changed what and when",
    "18-users": "Staff account management with roles, photos and last-access times",
    "20-settings": "Store settings screen with store identity, mode and licence tier",
    "23-companies": "Company onboarding tracker list",
    "24-licenses": "Licence catalogue listing packages with tier, price and payment type",
    "27-pos-variant-picker": "Point of sale variant picker showing each variant's item code, price and stock",
    "28-pos-cart-three-sellers": "Point of sale cart holding items from three different sellers in one basket",
    "30-pos-split-payment": "Payment panel with fifty thousand rupiah already recorded in cash and the remainder pending on QRIS, requiring proof",
    "31-report-cost-profit": "Cost and profit report showing revenue, cost of goods, gross profit, event cost and net profit",
    "32-report-seller-cost": "Seller cost report breaking profit down per artist",
    "33-report-purchases": "Purchases report listing supplier orders with status and value",
    "34-report-stock-by-seller": "Stock by seller report with per-SKU drill-down",
    "35-report-preorder": "Pre-order report grouped by stage against amount paid",
    "36-sales-receipt-modal": "On-screen receipt showing store identity, itemised line, split payment of transfer and cash, and the event footer",
    "38-preorder-detail": "Pre-order detail showing the five-stage status tracker",
    "39-preorder-invoice": "Pre-order invoice document with status pill and PDF download",
    "40-master-data-import": "Bulk master-data import dialog explaining the ten-sheet template and preview run",
    "42-variant-bom-cost": "Bill of materials and material cost breakdown for a variant, shown beside the manually kept cost price",
    "45-invoice-document": "Invoice detail document with company, licence and frozen totals",
    "48-purchase-order-detail": "Purchase order detail showing received status and printable invoice",
    "49-preorder-record-payment": "Recording a settlement payment against a pre-order",
    "51-role-permissions": "Role editor showing the per-screen menu access checklist",
    "52-settings-store-profile": "Store profile settings including the brand accent colour",
    "53-license-activation": "Licence activation lock screen asking for the key issued after purchase",
}

html = TPL.read_text()
missing, used, total_bytes = [], [], 0

def repl(m):
    global total_bytes
    key = m.group(1)
    f = WEB / f"{key}.webp"
    if not f.exists():
        missing.append(key)
        return f"<!-- MISSING {key} -->"
    raw = f.read_bytes()
    total_bytes += len(raw)
    used.append(key)
    b64 = base64.b64encode(raw).decode()
    alt = ALT.get(key, key.replace("-", " "))
    return (f'<img src="data:image/webp;base64,{b64}" alt="{alt}" '
            f'width="1440" height="900" loading="lazy" decoding="async">')

html = re.sub(r"\{\{IMG:([a-z0-9\-]+)\}\}", repl, html)
html = html.replace("{{DATE}}", DATE)

leftover = re.findall(r"\{\{[A-Z]+[^}]*\}\}", html)
OUT.write_text(html)

print(f"images inlined : {len(used)}")
print(f"source bytes   : {total_bytes/1024:.0f} KB  -> base64 ~{total_bytes*4/3/1024:.0f} KB")
print(f"output file    : {OUT}  ({OUT.stat().st_size/1024/1024:.2f} MB)")
if missing:
    print(f"MISSING SHOTS  : {missing}")
if leftover:
    print(f"UNREPLACED     : {leftover}")
slides = html.count('class="slide')
print(f"slides         : {slides}")
if OUT.stat().st_size > 15_500_000:
    print("WARNING: over the 16MB artifact limit")
    sys.exit(1)
