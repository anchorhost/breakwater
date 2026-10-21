#!/usr/bin/env python3
"""What the word filter adds on top of today's checks.

Usage: words_report.py samples.jsonl results.jsonl words.jsonl [from-date] [review-dir]
Baseline = local Checker score (results.jsonl) + the fleet tiers from analyze.py.
Only samples on or after from-date are counted (the filter needs a warm-up). The review
dir gets the kept entries and spam the filter would newly flag: customer data, keep it
out of the repo.
"""
import collections, hashlib, json, os, re, sys

THRESHOLD = 5
src, res, bay = sys.argv[1:4]
since = sys.argv[4] if len(sys.argv) > 4 else '2026-08-04'
dump = sys.argv[5] if len(sys.argv) > 5 else ''

scores = {json.loads(l)['id']: json.loads(l) for l in open(res)}
bayes = {}
for l in open(bay):
    r = json.loads(l)
    bayes[r['id']] = r
samples = [json.loads(l) for l in open(src)]
samples = [s for s in samples if s['id'] in scores and s['id'] in bayes and not s.get('logged_in') and s['email'] != 'wapuu@wordpress.example']

# Fleet tiers, as analyze.py computes them.
first_spam = {'ip': collections.defaultdict(list), 'email': collections.defaultdict(list)}
for s in samples:
    if s['label'] == 'spam':
        if s['ip']:
            first_spam['ip'][s['ip']].append((s['date'], s['site']))
        if s['email']:
            first_spam['email'][s['email'].lower()].append((s['date'], s['site']))


def fingerprint(s):
    vals = sorted((v for v in s['fields'].values() if isinstance(v, str)), key=len)
    text = vals[-1] if vals else ''
    host = re.sub(r'^https?://(www\.)?', '', s['home']).split('/')[0].split('.')[0].lower()
    text = re.sub(r'https?://\S+|www\.\S+|\d+', ' ', text.lower()).replace(host, ' ')
    text = re.sub(r'[^\w]+', ' ', text).strip()
    return hashlib.sha1(text.encode()).hexdigest() if len(text) >= 40 else ''


content_sites = collections.defaultdict(list)
for s in samples:
    s['fp'] = fingerprint(s)
    if s['fp']:
        content_sites[s['fp']].append((s['date'], s['site']))


def other(s, index, key):
    return len({site for d, site in index.get(key, []) if d < s['date'] and site != s['site']}) if key else 0


TIERS = {'ip': [(10, 3), (3, 2)], 'email': [(5, 5), (2, 3)], 'content': [(10, 5), (3, 3)]}


def tier(kind, n):
    return next((p for at, p in TIERS[kind] if n >= at), 0)


for s in samples:
    s['local'] = scores[s['id']]['score']
    s['fleet'] = s['local'] + tier('ip', other(s, first_spam['ip'], s['ip'])) \
        + tier('email', other(s, first_spam['email'], s['email'].lower())) \
        + tier('content', other(s, content_sites, s['fp']))
    s['p'] = bayes[s['id']]['p']
    s['clues'] = bayes[s['id']]['clues']

test = [s for s in samples if s['date'] >= since]


def points(p, plan):
    for at, pts in plan['spam']:
        if p >= at:
            return pts
    for at, pts in plan.get('ham', []):
        if p <= at:
            return pts
    return 0


PLANS = {
    'none': {'spam': []},
    'A: >=.99 +3': {'spam': [(0.99, 3)]},
    'B: >=.99 +4, >=.95 +2': {'spam': [(0.99, 4), (0.95, 2)]},
    'C: >=.995 +5, >=.99 +3, >=.95 +1': {'spam': [(0.995, 5), (0.99, 3), (0.95, 1)]},
    'D: B, and <=.01 -2': {'spam': [(0.99, 4), (0.95, 2)], 'ham': [(0.01, -2)]},
    'E: >=.999 +5': {'spam': [(0.999, 5)]},
}
pct = lambda a, b: '%.1f%%' % (100.0 * a / b) if b else '-'

print('Test window: %s to %s, %d samples\n' % (since, max(s['date'] for s in test)[:10], len(test)))
for kind, name in (('gf', 'Gravity Forms'), ('comment', 'Comments')):
    g = [s for s in test if s['kind'] == kind]
    spam = [s for s in g if s['label'] == 'spam']
    ham = [s for s in g if s['label'] == 'ham']
    texted = [s for s in spam if s['content'].strip()]
    print('## %s: %d spam (%d with text), %d ham\n' % (name, len(spam), len(texted), len(ham)))
    print('| Points from the filter | Spam, local | Ham, local | Spam, local + fleet | Ham, local + fleet | Ham flagged (count) |')
    print('|---|---|---|---|---|---|')
    for label, plan in PLANS.items():
        def caught(group, base):
            return sum(s[base] + points(s['p'], plan) >= THRESHOLD for s in group)
        print('| %s | %s | %s | %s | %s | %d |' % (
            label, pct(caught(spam, 'local'), len(spam)), pct(caught(ham, 'local'), len(ham)),
            pct(caught(spam, 'fleet'), len(spam)), pct(caught(ham, 'fleet'), len(ham)), caught(ham, 'fleet')))
    print('\nFilter alone, share at or above:')
    print('| p | spam | ham |')
    print('|---|---|---|')
    for t in (0.5, 0.9, 0.95, 0.99, 0.995, 0.999):
        print('| >= %s | %s | %s |' % (t, pct(sum(s['p'] >= t for s in spam), len(spam)), pct(sum(s['p'] >= t for s in ham), len(ham))))
    print('| <= 0.01 | %s | %s |' % (pct(sum(s['p'] <= 0.01 for s in spam), len(spam)), pct(sum(s['p'] <= 0.01 for s in ham), len(ham))))
    print()
    if dump:
        os.makedirs(dump, exist_ok=True)
        plan = PLANS['B: >=.99 +4, >=.95 +2']
        with open(os.path.join(dump, kind + '-new-ham-flags.jsonl'), 'w') as f:
            for s in ham:
                if s['fleet'] < THRESHOLD <= s['fleet'] + points(s['p'], plan):
                    f.write(json.dumps({k: s[k] for k in ('id', 'site', 'form', 'date', 'p', 'local', 'fleet', 'clues', 'email', 'content')}) + '\n')
        with open(os.path.join(dump, kind + '-new-spam-caught.jsonl'), 'w') as f:
            for s in spam:
                if s['fleet'] < THRESHOLD <= s['fleet'] + points(s['p'], plan):
                    f.write(json.dumps({k: s[k] for k in ('id', 'p', 'clues', 'content')}) + '\n')
