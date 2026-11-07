#!/usr/bin/env python3
"""前台真实抓取验证：12 大类 + 36 款标杆采购样例。

用法: python3 scripts/verify_sourcing_catalog.py [base_url]
退出码 0 = 全部通过，1 = 有失败项。
"""

import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://www.chinasupplier.shop").rstrip("/")
UA = {"User-Agent": "Mozilla/5.0 (compatible; btobcms-audit/1.0)"}

# 分类 / 产品 slug 清单：与 database/data/sourcing_catalog_12.json 一致
DATA = "database/data/sourcing_catalog_12.json"

# 旧阀门品类文案：首页/列表页出现即视为未清理干净
LEGACY_PATTERNS = [
    r"gate\s*valve", r"ball\s*valve", r"submersible\s*pump",
    r"centrifugal\s*pump", r"steel\s*pipe", r"pipe\s*elbow", r"pipe\s*flange",
]

results = []


def load_catalog():
    try:
        with open(DATA, encoding="utf-8") as fh:
            data = json.load(fh)
    except OSError:
        return [], []
    cats = [c["slug"] for c in data.get("categories", [])]
    prods = [(p["slug"], p["category_slug"]) for p in data.get("products", [])]
    return cats, prods


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """不自动跟随 3xx：否则 301 会被 urllib 吃掉，只看到最终 200。"""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def fetch(path):
    """返回 (status, body_text)。非 200 一律记录失败。"""
    url = BASE + path
    req = urllib.request.Request(url, headers=UA)
    try:
        with OPENER.open(req, timeout=30) as resp:
            return resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")
    except Exception as e:  # noqa: BLE001
        return 0, str(e)


def check(name, ok, detail=""):
    results.append((name, bool(ok), detail))
    print(("PASS  " if ok else "FAIL  ") + name + (f"  | {detail}" if detail else ""))


def legacy_hits(body):
    return sorted({p for p in LEGACY_PATTERNS if re.search(p, body, re.I)})


def main():
    cats, prods = load_catalog()

    if not cats or not prods:
        check("读取数据源 " + DATA, False, f"categories={len(cats)} products={len(prods)}")
        return 1

    check("数据源包含 12 个分类 / 36 个产品", len(cats) == 12 and len(prods) == 36,
          f"categories={len(cats)} products={len(prods)}")

    # ---------- 首页 ----------
    status, home = fetch("/")
    check("GET / 返回 200", status == 200, f"status={status} len={len(home)}")

    for slug in cats:
        check(f"首页出现分类 {slug}", slug in home)

    card_count = home.count('class="category-card"')
    check("首页展示 12 张分类卡片", card_count == 12, f"count={card_count}")

    hits = legacy_hits(home)
    check("首页无旧阀门品类文案", not hits, ",".join(hits))

    check("首页无 'Showing 0 products'", "Showing 0 products" not in home)
    check("首页无 'No products found'", "No products found" not in home)

    # ---------- 产品列表（分页 12/页 → 3 页） ----------
    listed = ""
    for page in range(1, 4):
        status, body = fetch("/products?page=" + str(page))
        check(f"GET /products?page={page} 返回 200", status == 200, f"status={status}")
        listed += body

    check("/products 无 'Showing 0 products'", "Showing 0 products" not in listed)
    check("/products 无 'No products found'", "No products found" not in listed)

    missing = [slug for slug, _ in prods if slug not in listed]
    check("/products 列出全部 36 款样例", not missing, ",".join(missing[:5]))

    hits = legacy_hits(listed)
    check("/products 无旧阀门产品", not hits, ",".join(hits))

    # 热搜词展示
    check("/products 展示热搜词 chips", "hot-keywords" in listed or "chip-link" in listed)

    # ---------- 产品详情页 ----------
    for slug, _ in prods:
        status, body = fetch(f"/product/{slug}")
        ok = status == 200 and "Server Error" not in body[:3000]
        check(f"GET /product/{slug} 返回 200 且无 500", ok, f"status={status}")

    # ---------- 分类页 ----------
    for slug in cats:
        status, body = fetch(f"/category/{slug}")
        ok = status == 200 and "Server Error" not in body[:3000]
        check(f"GET /category/{slug} 返回 200 且无 500", ok, f"status={status}")

    # ---------- 旧分类 301 ----------
    for slug in ["industrial-valves", "water-pumps", "steel-pipes", "gate-valves"]:
        status, _ = fetch(f"/category/{slug}")
        check(f"旧分类 /category/{slug} 已 301", status in (301, 302), f"status={status}")

    failed = [r for r in results if not r[1]]
    print(f"\n合计 {len(results)} 项，通过 {len(results) - len(failed)}，失败 {len(failed)}")

    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
