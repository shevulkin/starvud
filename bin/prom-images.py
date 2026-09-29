"""Завантажує фото товарів з catalog.json у storage/import/prom/img (оригінали)."""
import os
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)
import json, os, urllib.request, concurrent.futures as cf
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128 Safari/537.36'
d=json.load(open('database/prom/catalog.json',encoding='utf-8'))
os.makedirs('storage/import/prom/img', exist_ok=True)
jobs=[(i['id'],i['slug'],i['ext']) for p in d for i in p['images']]
def go(j):
    iid,slug,ext=j; out='storage/import/prom/img/%s.%s'%(iid,ext)
    if os.path.exists(out) and os.path.getsize(out)>1000: return 'skip'
    for u in ['https://images.prom.ua/%s_%s.%s'%(iid,slug,ext),'https://images.prom.ua/%s_w1280_h1280_%s.%s'%(iid,slug,ext)]:
        try:
            b=urllib.request.urlopen(urllib.request.Request(u,headers={'User-Agent':UA}),timeout=60).read()
            if len(b)>1000: open(out,'wb').write(b); return 'ok'
        except Exception as e: err=str(e)
    return 'FAIL '+iid+' '+err
with cf.ThreadPoolExecutor(6) as ex:
    res=list(ex.map(go,jobs))
from collections import Counter
print(Counter(r.split()[0] for r in res)); print([r for r in res if r.startswith('FAIL')][:10])
