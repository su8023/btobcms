#!/usr/bin/env python3
"""前台真实抓取验证：产业带分类 + 标杆采购样例。

用法: python3 scripts/verify_sourcing_catalog.py [base_url]
退出码 0 = 全部通过，1 = 有失败项。
"""

import re
import sys
import urllib.error
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://www.chinasupplier.shop").rstrip("/")
UA = {"User-Agent": "Mozilla/5.0 (compatible; btobcms-audit/1.0)"}

BELT_SLUGS = [
    "consumer-electronics-iot",
    "precision-cnc-industrial-hardware",
    "silicone-plastics-tooling",
    "led-commercial-lighting-energy",
    "packaging-printing-custom-boxes",
    "apparel-fabrics-protective-gear",
]

PRODUCT_SLUGS = [
    "custom-cnc-machining-precision-lathe-parts",
    "oem-odm-smart-electronics-turnkey-pcba-assembly",
    "high-precision-injection-molded-components-tooling",
    "industrial-high-bay-led-lighting-energy-fixtures",
    "custom-corrugated-shipping-boxes-eco-packaging",
    "heavy-duty-industrial-workwear-safety-apparel",
]

# 旧阀门内容关键词：首页/列表页出现即视为未清理干净
LEGACY_PATTERNS = [
    r"gate\s*valve", r"ball\s*valve", r"submersible\s*pump",
    r"centrifugal\s*pump", r"steel\s*pipe", r"pipe\s*elbow", r"pipe\s*flange",
]

results = []


def fetch(path):
    """返回 (status, body_text)。非 200 一律记录失败。"""
    url = BASE + path
    req = urllib.request.Request(url, headers=UA)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")
    except Exception as e:  # noqa: BLE001
        return 0, str(e)


def check(name, ok, detail=""):
    results.append((name, bool(ok), detail))
    print(("PASS  " if ok else "FAIL  ") + name + (f"  | {detail}" if detail else ""))


def main():
    # ---------- 首页 ----------
    status, home = fetch("/")
    check("GET / 返回 200", status == 200, f"status={status} len={len(home)}")

    for slug in BELT_SLUGS:
        check(f"首页出现产业带分类 {slug}", slug in home)

    legacy_hits = sorted({p for p in LEGACY_PATTERNS if re.search(p, home, re.I)})
    check("首页无旧阀门品类文案", not legacy_hits, ",".join(legacy_hits))

    check("首页无 'Showing 0 products'", "Showing 0 products" not in home)
    check("首页无 'No products found'", "No products found" not in home)

    # ---------- 产品列表 ----------
    status, products = fetch("/products")
    check("GET /products 返回 200", status == 200, f"status={status} len={len(products)}")
    check("/products 无 'Showing 0 products'", "Showing 0 products" not in products)
    check("/products 无 'No products found'", "No products found" not in products)

    for slug in PRODUCT_SLUGS:
        check(f"/products 列出样例 {slug}", slug in products)

    legacy_hits = sorted({p for p in LEGACY_PATTERNS if re.search(p, products, re.I)})
    check("/products 无旧阀门产品", not legacy_hits, ",".join(legacy_hits))

    # ---------- 产品详情页 ----------
    for slug in PRODUCT_SLUGS:
        status, body = fetch(f"/product/{slug}")
        check(f"GET /product/{slug} 返回 200", status == 200, f"status={status}")
        check(f"/product/{slug} 无 500 痕迹", "500" not in body[:2000] or "Server Error" not in body)

    # ---------- 分类页 ----------
    for slug in BELT_SLUGS:
        status, body = fetch(f"/category/{slug}")
        check(f"GET /category/{slug} 返回 200", status == 200, f"status={status}")

    failed = [r for r in results if not r[1]]
    print(f"\n合计 {len(results)} 项，通过 {len(results) - len(failed)}，失败 {len(failed)}")

    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
