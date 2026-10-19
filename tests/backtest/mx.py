#!/usr/bin/env python3
"""Precompute whether each sender domain can receive mail (MX, else A/AAAA), like the plugin.

Usage: mx.py samples.jsonl mx.json   (reuses an existing mx.json as a cache)
"""
import json, os, subprocess, sys
from concurrent.futures import ThreadPoolExecutor


def receives(domain):
    for rr in ('MX', 'A', 'AAAA'):
        r = subprocess.run(['dig', '+short', '+time=3', '+tries=2', rr, domain + '.'], capture_output=True, text=True)
        if r.stdout.strip():
            return True
    return False


src, dst = sys.argv[1], sys.argv[2]
cache = json.load(open(dst)) if os.path.exists(dst) else {}
domains = set()
for line in open(src):
    e = json.loads(line)['email']
    if '@' in e:
        domains.add(e.rsplit('@', 1)[1].strip().lower())
todo = sorted(d for d in domains if d and d not in cache)
print('%d domains, %d to look up' % (len(domains), len(todo)))
with ThreadPoolExecutor(64) as pool:
    for d, ok in zip(todo, pool.map(receives, todo)):
        cache[d] = ok
json.dump(cache, open(dst, 'w'))
print('%d dead' % sum(1 for v in cache.values() if not v))
