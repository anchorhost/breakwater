#!/usr/bin/env python3
"""Turn the fleet export (BT| lines) into one labelled sample per line.

Usage: normalize.py export.txt samples.jsonl
Labels come from what the site already decided: GF status spam/active/trash, comment
spam/approved/pending. 'by' names who decided, read from GF entry notes or comment meta.
"""
import base64, json, re, sys, zlib

HEADER = re.compile(r'== (\S+) ==')


def by_from_notes(notes):
    found = []
    for n in notes or []:
        u, t, v = (n.get('u') or ''), (n.get('t') or ''), (n.get('v') or '')
        text = (u + ' ' + t + ' ' + v).lower()
        if 'honeypot' in text:
            found.append('gf_honeypot')
        elif 'cleantalk' in text:
            found.append('cleantalk')
        elif 'akismet' in text:
            found.append('akismet')
        elif 'recaptcha' in text or 'turnstile' in text or 'captcha' in text:
            found.append('captcha')
        elif 'spam' in v.lower():
            found.append('other:' + (u or t)[:40])
    return sorted(set(found))


def gf_sample(site, o, e):
    email = name = url = ''
    texts, fields, first, last = [], {}, '', ''
    for key, ftype, label, value in e['vals']:
        fields[(label or key)[:60] + ' [' + key + ']'] = value
        if ftype in ('consent', 'hidden'):
            continue  # the site's own text and tracking, skipped by the adapter too
        if ftype == 'email' and not email:
            email = value.strip()
            continue
        if ftype == 'name':
            if key.endswith('.3'):
                first = value
            elif key.endswith('.6'):
                last = value
            elif '.' not in key:
                name = value
        if ftype == 'website' and not url:
            url = value
        texts.append(value)
    name = name or (first + ' ' + last).strip()
    return {
        'site': site, 'home': o['home'], 'locale': o['locale'], 'ct': o['ct'], 'kind': 'gf',
        'id': 'gf:%s:%s' % (site, e['id']), 'form': '%s: %s' % (e['form'], e['title']), 'date': e['date'],
        'label': {'spam': 'spam', 'active': 'ham', 'trash': 'trash'}[e['status']], 'by': by_from_notes(e['notes']),
        'ip': e['ip'] or '', 'agent': e['agent'] or '', 'email': email, 'name': name, 'url': url,
        'content': '\n'.join(texts), 'fields': fields, 'page': e['url'],
    }


def comment_sample(site, o, c):
    return {
        'site': site, 'home': o['home'], 'locale': o['locale'], 'ct': o['ct'], 'kind': 'comment',
        'id': 'c:%s:%s' % (site, c['id']), 'form': 'comment', 'date': c['d'],
        'label': {'spam': 'spam', '1': 'ham', '0': 'pending'}[c['s']], 'by': c.get('by') or [],
        'ip': c['ip'] or '', 'agent': c['ag'] or '', 'email': c['e'] or '', 'name': c['a'] or '', 'url': c['u'] or '',
        'content': ((c['c'] or '') + '\n' + (c['u'] or '')).strip(), 'logged_in': c.get('uid') not in (None, '0', 0),
        'fields': {'author': c['a'], 'email': c['e'], 'url': c['u'], 'comment': c['c']}, 'page': '',
    }


def main(src, dst):
    site, sites, bad, n = None, 0, [], 0
    with open(dst, 'w') as out:
        for line in open(src, errors='replace'):
            m = HEADER.search(line)
            if m:
                site = m.group(1)
                continue
            if not line.startswith('BT|') or site is None:
                continue
            blob = line[3:].strip()
            try:
                o = json.loads(zlib.decompress(base64.b64decode(blob)))
            except Exception:
                bad.append(site)
                continue
            sites += 1
            for e in o['gf']:
                out.write(json.dumps(gf_sample(site, o, e)) + '\n'); n += 1
            for c in o['comments']:
                out.write(json.dumps(comment_sample(site, o, c)) + '\n'); n += 1
    print('%d sites, %d samples, %d unreadable: %s' % (sites, n, len(bad), ' '.join(bad[:20])))


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
