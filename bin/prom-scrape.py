"""Знімок каталогу starvud.prom.ua → database/prom/catalog.json.

Запуск: python bin/prom-scrape.py, далі python bin/prom-images.py (фото)
і php bin/cli.php import:prom. Повторний запуск оновлює ціни, наявність,
опт і акції, не дублюючи товарів."""
import html
import json
import os
import re
import sys
import time
import urllib.request

BASE = 'https://starvud.prom.ua'
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36'
HERE = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'database', 'prom')
SKIP_IMG = {'4182085306', '4636349382'}  # логотип і favicon


def get(url, binary=False, tries=3):
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept-Language': 'uk'})
            with urllib.request.urlopen(req, timeout=40) as r:
                b = r.read()
                return b if binary else b.decode('utf-8', 'replace')
        except Exception as e:  # noqa
            print('retry', url, e, file=sys.stderr)
            time.sleep(2 + i * 3)
    raise RuntimeError(url)


def text(s):
    s = re.sub(r'<br\s*/?>', '\n', s)
    s = re.sub(r'</(p|li|div|h\d)>', '\n', s)
    s = re.sub(r'<[^>]+>', '', s)
    s = html.unescape(s)
    return re.sub(r'\n{3,}', '\n\n', re.sub(r'[ \t\xa0]+', ' ', s)).strip()


def clean_html(s):
    """Опис лишаємо HTML-ом, але лише з безпечних тегів."""
    s = re.sub(r'<(script|style|iframe)[^>]*>.*?</\1>', '', s, flags=re.S | re.I)
    s = re.sub(r'<(/?)(\w+)[^>]*>', lambda m: '<%s%s>' % (m.group(1), m.group(2).lower())
               if m.group(2).lower() in ('p', 'br', 'ul', 'ol', 'li', 'b', 'strong', 'i', 'em', 'h2', 'h3', 'h4', 'table', 'tr', 'td', 'th', 'tbody') else '', s)
    s = html.unescape(s).replace('\xa0', ' ')
    s = re.sub(r'<p>\s*</p>', '', s)
    return s.strip()


def product_urls():
    urls, page = [], 1
    while True:
        u = BASE + '/ua/product_list' + ('' if page == 1 else '/page_%d' % page)
        t = get(u)
        found = re.findall(r'href="(/ua/p\d+-[^"]+\.html)"', t)
        new = [x for x in dict.fromkeys(found) if x not in urls]
        if not new:
            break
        urls += new
        print('page', page, len(new), file=sys.stderr)
        page += 1
        if page > 40:
            break
    return urls


def parse(url):
    t = get(BASE + url)
    prod, crumbs, tiers = {}, [], []
    for m in re.finditer(r'<script type="application/ld\+json">(.*?)</script>', t, re.S):
        try:
            d = json.loads(m.group(1))
        except Exception:
            continue
        if d.get('@type') == 'PriceSpecification':
            q = d.get('eligibleQuantity', {}).get('value')
            p = d.get('price') or d.get('minPrice')
            if q and p and [int(q), p] not in tiers:
                tiers.append([int(q), p])
        elif d.get('@type') == 'Product':
            prod = d
        elif d.get('@type') == 'BreadcrumbList':
            crumbs = [(i['item']['@id'], i['item']['name']) for i in d['itemListElement']]
    cats = [(u, n) for u, n in crumbs if re.match(r'/ua/g\d+', u)]
    # Фото: беремо в порядку появи на сторінці, найбільший доступний розмір
    ids = []
    for m in re.finditer(r'class="(?:cs-product-image__img|cs-images__img)[^"]*" src="https://images\.prom\.ua/(\d+)_(?:w\d+_h\d+_)?([\w\-.]+?)\.(jpg|png|webp|jpeg)', t):
        if m.group(1) not in SKIP_IMG and m.group(1) not in [i[0] for i in ids]:
            ids.append((m.group(1), m.group(2), m.group(3)))
    attrs = []
    for m in re.finditer(r'data-qaid="attribute_name">(.*?)</td>\s*<td[^>]*data-qaid="attribute_value">(.*?)</td>', t, re.S):
        attrs.append([text(m.group(1)), text(m.group(2))])
    desc_html = ''
    m = re.search(r'data-qaid="product_description"[^>]*>(.*?)</div>\s*(?:</div>|<div class="cs-)', t, re.S)
    if m:
        desc_html = clean_html(m.group(1))
    old = re.search(r'data-qaid="old_product_price"[^>]*>\s*([\d\s\xa0 ,.]+)', t)
    promo_end = re.search(r'<time[^>]*datetime="(\d{4}-\d{2}-\d{2})T[^"]*"[^>]*data-qaid="countdown_timer_label"', t)
    presence = re.search(r'data-qaid="presence_data"[^>]*>(.*?)</', t, re.S)
    offers = prod.get('offers', {})
    return {
        'id': re.search(r'/p(\d+)-', url).group(1),
        'url': url,
        'name': prod.get('name', '').strip(),
        'sku': prod.get('sku', ''),
        'brand': prod.get('brand', ''),
        'price': offers.get('price'),
        'old_price': re.sub(r'[^\d.,]', '', old.group(1)).replace(',', '.') if old else None,
        'promo_ends': promo_end.group(1) if promo_end else None,
        'availability': offers.get('availability', '').rsplit('/', 1)[-1],
        'presence': text(presence.group(1)) if presence else '',
        'category': cats[-1][1] if cats else '',
        'category_url': cats[-1][0] if cats else '',
        'description_text': prod.get('description', ''),
        'description_html': desc_html,
        'attrs': attrs,
        'tiers': sorted(tiers),
        'label': (re.search(r'data-qaid="stripe_label">([^<]+)<', t) or [None, ''])[1].strip(),
        'images': [{'id': i, 'slug': s, 'ext': e} for i, s, e in ids],
    }


def main():
    out = os.path.join(HERE, 'catalog.json')
    urls = product_urls()
    print('total urls', len(urls), file=sys.stderr)
    items = []
    for n, u in enumerate(urls, 1):
        try:
            items.append(parse(u))
        except Exception as e:
            print('FAIL', u, e, file=sys.stderr)
        if n % 10 == 0:
            print(n, file=sys.stderr)
        time.sleep(0.4)
    json.dump(items, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('saved', len(items), file=sys.stderr)


if __name__ == '__main__':
    main()
