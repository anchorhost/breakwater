#!/usr/bin/env python3
"""Word-filter backtest: a Robinson/Fisher (SpamBayes-style) token classifier trained on
fleet labels, replayed day by day so every sample is judged only by a model built from
samples strictly earlier (as a daily fleet sync would ship it).

Usage: words.py samples.jsonl words.jsonl [--min-sites=3] [--min-docs=5] [--bigrams=1] [--hash=0] [--coverage=0.5]
Writes one line per sample: id, spamminess (0 ham .. 1 spam), the deciding tokens.
"""
import collections, hashlib, json, math, re, sys

args = dict(a[2:].split('=', 1) for a in sys.argv[3:] if a.startswith('--'))
MIN_SITES = int(args.get('min-sites', 3))   # a token counts once this many sites have seen it
MIN_DOCS = int(args.get('min-docs', 5))     # and once it is in this many labelled samples
BIGRAMS = args.get('bigrams', '1') == '1'
HASH = args.get('hash', '0') == '1'         # 16-hex hashes instead of words, as a site would get them
COVERAGE = float(args.get('coverage', 0.5))  # abstain when fewer of the message's words are known from kept messages
MAX_TOKENS = 150                            # strongest tokens combined per message
S, X = 1.0, 0.5                             # Robinson's strength and prior

WORD = re.compile(r"[a-zà-ÿ][a-zà-ÿ'’-]{1,24}")
LINK = re.compile(r'(?:https?://|www\.)([^/\s<>"\']+)', re.I)


def host_word(home):
    return re.sub(r'^https?://(www\.)?', '', home).split('/')[0].split('.')[0].lower()


def is_word(t):
    return ' ' not in t and ':' not in t


def tokens(s):
    text = s['content'] or ''
    out = set()
    for m in LINK.finditer(text):
        out.add('link:' + m.group(1).lower().removeprefix('www.'))
    text = LINK.sub(' ', text.lower())
    own = host_word(s['home'])
    words = [w.strip("'’-") for w in WORD.findall(text)]
    words = [w for w in words if len(w) >= 2 and w != own]
    out.update(words)
    if BIGRAMS:
        out.update(a + ' ' + b for a, b in zip(words, words[1:]))
    if s['email'] and '@' in s['email']:
        out.add('from:' + s['email'].rsplit('@', 1)[1].lower())
    if HASH:  # single words keep a '=' mark so the unfamiliar-words check still finds them
        out = {('=' if is_word(t) else '') + hashlib.sha256(('breakwater-words-v1|' + t).encode()).hexdigest()[:16] for t in out}
    return out


def chi2q(x2, v):
    """P(chi-square with v (even) degrees of freedom >= x2)."""
    m = x2 / 2.0
    total = term = math.exp(-m)
    for i in range(1, v // 2):
        term *= m / i
        total += term
    return min(total, 1.0)


spam_n = collections.Counter()
ham_n = collections.Counter()
sites = collections.defaultdict(set)
docs = {'spam': 0, 'ham': 0}


def prob(t):
    b, g = spam_n[t], ham_n[t]
    n = b + g
    if n < MIN_DOCS or len(sites[t]) < MIN_SITES or not docs['spam'] or not docs['ham']:
        return None
    bs, gs = b / docs['spam'], g / docs['ham']
    p = bs / (bs + gs)
    return (S * X + n * p) / (S + n)


def known(t):
    return ham_n[t] >= 3


def score(toks):
    words = [t for t in toks if (t.startswith('=') if HASH else is_word(t))]
    if COVERAGE and words and sum(known(t) for t in words) / len(words) < COVERAGE:
        return 0.5, [('(unfamiliar words)', 0.5)]
    clues = []
    for t in toks:
        f = prob(t)
        if f is not None and abs(f - 0.5) >= 0.1:
            clues.append((abs(f - 0.5), f, t))
    clues.sort(reverse=True)
    clues = clues[:MAX_TOKENS]
    if not clues:
        return 0.5, []
    n = len(clues)
    h = chi2q(-2.0 * sum(math.log(f) for _, f, _ in clues), 2 * n)
    s = chi2q(-2.0 * sum(math.log(1.0 - f) for _, f, _ in clues), 2 * n)
    return (1.0 + h - s) / 2.0, [(t, round(f, 3)) for _, f, t in clues[:8]]


samples = [json.loads(l) for l in open(sys.argv[1])]
samples = [s for s in samples if not s.get('logged_in') and s['email'] != 'wapuu@wordpress.example']
samples.sort(key=lambda s: s['date'])
by_day = collections.defaultdict(list)
for s in samples:
    by_day[s['date'][:10]].append(s)

with open(sys.argv[2], 'w') as out:
    for day in sorted(by_day):
        batch = [(s, tokens(s)) for s in by_day[day]]
        for s, toks in batch:
            p, clues = score(toks)
            out.write(json.dumps({'id': s['id'], 'p': round(p, 4), 'clues': clues}) + '\n')
        for s, toks in batch:
            if s['label'] not in ('spam', 'ham'):
                continue
            docs[s['label']] += 1
            counter = spam_n if s['label'] == 'spam' else ham_n
            for t in toks:
                counter[t] += 1
                if len(sites[t]) < MIN_SITES:
                    sites[t].add(s['site'])

kept = [t for t in set(spam_n) | set(ham_n) if prob(t) is not None and abs(prob(t) - 0.5) >= 0.1]
strong = [t for t in kept if abs(prob(t) - 0.5) >= 0.4]
print('tokens seen %d, usable %d, strong (p<.1 or >.9) %d; trained on %d spam, %d ham'
      % (len(set(spam_n) | set(ham_n)), len(kept), len(strong), docs['spam'], docs['ham']), file=sys.stderr)
known_words = [t for t in ham_n if (t.startswith('=') if HASH else is_word(t)) and known(t)]
print('words known from kept messages: %d' % len(known_words), file=sys.stderr)
