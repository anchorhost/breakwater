#!/usr/bin/env python3
"""Backtest report: how the content checks score what the fleet already labelled.

Usage: analyze.py samples.jsonl results.jsonl outdir
Writes report.md plus review files (flagged ham, missed spam) to outdir.
"""
import collections, hashlib, json, os, re, sys

THRESHOLD = 5
src, res, outdir = sys.argv[1], sys.argv[2], sys.argv[3]
os.makedirs(outdir, exist_ok=True)
scores = {}
for line in open(res):
    r = json.loads(line)
    scores[r['id']] = r
samples = [json.loads(l) for l in open(src)]
# The sample comment every install ships with was never submitted by anyone.
samples = [s for s in samples if s['id'] in scores and not s.get('logged_in') and s['email'] != 'wapuu@wordpress.example']
for s in samples:
    s['score'] = scores[s['id']]['score']
    s['signals'] = scores[s['id']]['signals']
    s['flag'] = s['score'] >= THRESHOLD

lines = []
say = lines.append
pct = lambda a, b: '%.1f%%' % (100.0 * a / b) if b else '-'


def by_label(s):
    if s['label'] != 'spam':
        return s['label']
    by = s['by']
    if not by:
        return 'spam (no note)'
    if by == ['gf_honeypot'] or 'gf_honeypot' in by and len(by) == 1:
        return 'spam: GF honeypot'
    return 'spam: ' + '+'.join(sorted(by))


# Fleet reputation: IPs and emails already caught as spam, by site and time.
first_spam = {'ip': collections.defaultdict(list), 'email': collections.defaultdict(list)}
for s in samples:
    if s['label'] == 'spam':
        if s['ip']:
            first_spam['ip'][s['ip']].append((s['date'], s['site']))
        if s['email']:
            first_spam['email'][s['email'].lower()].append((s['date'], s['site']))


def fingerprint(s):
    # The longest field is the message; drop links, digits and the site's own name so the
    # same pitch sent to many sites ("Hello Foresthillinn Net") hashes the same.
    vals = sorted((v for v in s['fields'].values() if isinstance(v, str)), key=len)
    text = vals[-1] if vals else ''
    host = re.sub(r'^https?://(www\.)?', '', s['home']).split('/')[0].split('.')[0].lower()
    text = re.sub(r'https?://\S+|www\.\S+|\d+', ' ', text.lower())
    text = text.replace(host, ' ')
    text = re.sub(r'[^\w]+', ' ', text).strip()
    return hashlib.sha1(text.encode()).hexdigest() if len(text) >= 40 else ''


content_sites = collections.defaultdict(list)
for s in samples:
    s['fp'] = fingerprint(s)
    if s['fp']:
        content_sites[s['fp']].append((s['date'], s['site']))


def other_sites(s, index, key):
    return len({site for d, site in index.get(key, []) if d < s['date'] and site != s['site']}) if key else 0


# Points a fleet service would add, by how many OTHER sites saw it first. One or two sites is
# ordinary (people ask several contractors the same thing; offices share IPs), so nothing below 3.
TIERS = {
    'rep_ip': [(10, 3), (3, 2)],
    'rep_email': [(5, 5), (2, 3)],
    'rep_content': [(10, 5), (3, 3)],
}


def tier(kind, n):
    for at_least, points in TIERS[kind]:
        if n >= at_least:
            return points
    return 0


for s in samples:
    s['n_ip'] = other_sites(s, first_spam['ip'], s['ip'])
    s['n_email'] = other_sites(s, first_spam['email'], s['email'].lower())
    s['n_content'] = other_sites(s, content_sites, s['fp'])
    s['rep_ip'] = tier('rep_ip', s['n_ip'])
    s['rep_email'] = tier('rep_email', s['n_email'])
    s['rep_content'] = tier('rep_content', s['n_content'])
    s['fleet_score'] = s['score'] + s['rep_ip'] + s['rep_email'] + s['rep_content']
    s['fleet_flag'] = s['fleet_score'] >= THRESHOLD

sites = len({s['site'] for s in samples})
say('# Breakwater backtest\n')
say('%d samples from %d sites, last 90 days. Content and sender signals only: history carries no browser proof, '
    'so the honeypot and proof of work (which stop most bots) are not part of these numbers.\n' % (len(samples), sites))

for kind in ('gf', 'comment'):
    group = [s for s in samples if s['kind'] == kind]
    if not group:
        continue
    say('## %s\n' % ('Gravity Forms entries' if kind == 'gf' else 'Comments'))
    say('| Label | Samples | Flagged, local checks | Fleet: IP | Fleet: email | Fleet: same message | Flagged, local + fleet |')
    say('|---|---|---|---|---|---|---|')
    buckets = collections.defaultdict(list)
    for s in group:
        buckets[by_label(s)].append(s)
    for label in sorted(buckets, key=lambda k: -len(buckets[k])):
        b = buckets[label]
        if len(b) < 20 and label.startswith('spam: other'):
            continue
        f = sum(s['flag'] for s in b)
        ri = sum(bool(s['rep_ip']) for s in b)
        re_ = sum(bool(s['rep_email']) for s in b)
        rc = sum(bool(s['rep_content']) for s in b)
        ff = sum(s['fleet_flag'] for s in b)
        say('| %s | %d | %s | %s | %s | %s | %s |' % (label, len(b), pct(f, len(b)), pct(ri, len(b)), pct(re_, len(b)), pct(rc, len(b)), pct(ff, len(b))))
    say('')

    say('Score distribution (share of each label at or above the score):\n')
    say('| Score | ' + ' | '.join(['spam', 'ham', 'trash' if kind == 'gf' else 'pending']) + ' |')
    say('|---|---|---|---|')
    for t in range(1, 11):
        row = []
        for label in ('spam', 'ham', 'trash' if kind == 'gf' else 'pending'):
            b = [s for s in group if s['label'] == label]
            row.append(pct(sum(s['score'] >= t for s in b), len(b)))
        say('| >= %d | %s |' % (t, ' | '.join(row)))
    say('')

    say('Signals (how often each fires on spam vs ham):\n')
    say('| Signal | Spam | Ham | Ham entries |')
    say('|---|---|---|---|')
    spam = [s for s in group if s['label'] == 'spam']
    ham = [s for s in group if s['label'] == 'ham']
    codes = collections.Counter(c for s in group for c in s['signals'])
    for code, _ in codes.most_common():
        sp = sum(code in s['signals'] for s in spam)
        hm = sum(code in s['signals'] for s in ham)
        say('| %s | %s | %s | %d |' % (code, pct(sp, len(spam)), pct(hm, len(ham)), hm))
    say('')

    empty = [s for s in spam if not s['content'].strip()]
    say('Spam with no message text at all (email-only or name-only forms): %s of spam. Only browser or reputation checks can catch these.\n'
        % pct(len(empty), len(spam)))

    # Review files: every flagged ham, and a sample of missed spam.
    with open(os.path.join(outdir, kind + '-flagged-ham.jsonl'), 'w') as f:
        for s in sorted(ham, key=lambda s: -s['score']):
            if s['flag'] or s['fleet_flag']:
                f.write(json.dumps({k: s[k] for k in ('id', 'home', 'form', 'score', 'fleet_score', 'signals', 'n_ip', 'n_email', 'n_content', 'email', 'name', 'content', 'agent', 'ip')}) + '\n')
    with open(os.path.join(outdir, kind + '-missed-spam.jsonl'), 'w') as f:
        for s in spam:
            if not s['flag']:
                f.write(json.dumps({k: s[k] for k in ('id', 'home', 'form', 'by', 'score', 'signals', 'email', 'name', 'content', 'agent', 'ip')}) + '\n')

open(os.path.join(outdir, 'report.md'), 'w').write('\n'.join(lines) + '\n')
print('\n'.join(lines))
