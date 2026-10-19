#!/usr/bin/env python3
"""How many other sites must have seen an IP, email or message before it counts as a fleet signal.

Usage: fleet_curve.py samples.jsonl    (Gravity Forms only; counts strictly earlier, other sites)
"""
import collections, hashlib, json, re, sys
sys.path.insert(0, __file__.rsplit('/', 1)[0])

S = [json.loads(l) for l in open(sys.argv[1])]
S = [s for s in S if s['kind'] == 'gf' and s['label'] in ('spam', 'ham')]


def fp(s):
    vals = sorted((v for v in s['fields'].values() if isinstance(v, str)), key=len)
    text = vals[-1] if vals else ''
    host = re.sub(r'^https?://(www\.)?', '', s['home']).split('/')[0].split('.')[0].lower()
    text = re.sub(r'https?://\S+|www\.\S+|\d+', ' ', text.lower()).replace(host, ' ')
    text = re.sub(r'[^\w]+', ' ', text).strip()
    return hashlib.sha1(text.encode()).hexdigest() if len(text) >= 40 else ''


idx = {k: collections.defaultdict(list) for k in ('ip', 'email', 'msg')}
for s in S:
    s['msg'] = fp(s)
    s['em'] = s['email'].lower()
    if s['ip'] and s['label'] == 'spam':
        idx['ip'][s['ip']].append((s['date'], s['site']))
    if s['em'] and s['label'] == 'spam':
        idx['email'][s['em']].append((s['date'], s['site']))
    if s['msg']:
        idx['msg'][s['msg']].append((s['date'], s['site']))  # any verdict: the pitch itself repeats


def others(s, kind, key):
    return len({site for d, site in idx[kind].get(key, []) if d < s['date'] and site != s['site']})


spam = [s for s in S if s['label'] == 'spam']
ham = [s for s in S if s['label'] == 'ham']
print('| Signal | Other sites needed | Spam caught | Ham hit |')
print('|---|---|---|---|')
for kind, key in (('ip', 'ip'), ('email', 'em'), ('msg', 'msg')):
    for s in S:
        s['n_' + kind] = others(s, kind, s[key]) if s[key] else 0
    for k in (1, 2, 3, 5):
        a = sum(s['n_' + kind] >= k for s in spam)
        b = sum(s['n_' + kind] >= k for s in ham)
        print('| %s | %d+ | %.1f%% | %.2f%% (%d) |' % (kind, k, 100 * a / len(spam), 100 * b / len(ham), b))
json.dump({s['id']: [s['n_ip'], s['n_email'], s['n_msg']] for s in S}, open('fleet_counts.json', 'w'))
