"""A bot with no browser: scrape each form, fill it, POST it straight back.
Usage: python3 -I tests/nojs.py https://protect.localhost"""
import html.parser, json, os, ssl, subprocess, sys, time, urllib.parse, urllib.request

BASE = sys.argv[1] if len(sys.argv) > 1 else 'https://protect.localhost'
WP = os.environ.get('WP_PATH') or os.path.expanduser('~/Cove/Sites/protect.localhost/public')
CTX = ssl._create_unverified_context()
STAMP = str(int(time.time()))
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36'

class Forms(html.parser.HTMLParser):
    def __init__(self):
        super().__init__(); self.forms = []; self.cur = None; self.ta = None
    def handle_starttag(self, tag, a):
        a = dict(a)
        if tag == 'form': self.cur = {'attrs': a, 'fields': {}}; self.forms.append(self.cur)
        elif self.cur is not None and tag in ('input', 'button') and a.get('name'):
            if a.get('type') in ('checkbox', 'radio') and 'checked' not in a: return
            self.cur['fields'].setdefault(a['name'], a.get('value') or '')
        elif self.cur is not None and tag == 'textarea' and a.get('name'): self.ta = a['name']; self.cur['fields'][a['name']] = ''
    def handle_data(self, d):
        if self.ta: self.cur['fields'][self.ta] += d
    def handle_endtag(self, tag):
        if tag == 'textarea': self.ta = None
        if tag == 'form': self.cur = None

def get(url):
    return urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': UA}), context=CTX).read().decode()

def post(url, data):
    req = urllib.request.Request(url, urllib.parse.urlencode(data).encode(), headers={'User-Agent': UA, 'Content-Type': 'application/x-www-form-urlencoded'})
    try: return urllib.request.urlopen(req, context=CTX).read().decode()[:300]
    except urllib.error.HTTPError as e: return f'HTTP {e.code}'

def wp(*args):
    return subprocess.run(['wp', f'--path={WP}', *args], capture_output=True, text=True).stdout

def form_on(path, match):
    p = Forms(); p.feed(get(BASE + path))
    return next(f for f in p.forms if match(f))

def message(i):
    return f'Hello, I would like more information about your services. Please call me back today. {STAMP}-{i}'

cases = []
f = form_on('/gf-post/', lambda f: 'gform_1' == f['attrs'].get('id'))
d = dict(f['fields']); d.update({'input_1': 'Bot One', 'input_2': f'bot1{STAMP}@gmail.com', 'input_3': message(1)})
cases.append(('gravityforms', lambda: post(BASE + '/gf-post/', d)))
f = form_on('/cf7/', lambda f: 'wpcf7-form' in f['attrs'].get('class', ''))
d2 = dict(f['fields']); d2.update({'your-name': 'Bot Two', 'your-email': f'bot2{STAMP}@gmail.com', 'your-subject': 'Hi', 'your-message': message(2)})
cases.append(('contact-form-7', lambda: post(BASE + '/cf7/', d2)))
f = form_on('/wpforms/', lambda f: 'wpforms-form' in f['attrs'].get('class', ''))
d3 = dict(f['fields']); d3.update({'wpforms[fields][1]': 'Bot Three', 'wpforms[fields][2]': f'bot3{STAMP}@gmail.com', 'wpforms[fields][3]': message(3)})
cases.append(('wpforms', lambda: post(BASE + '/wpforms/', d3)))
f = form_on('/elementor/', lambda f: 'elementor-form' in f['attrs'].get('class', ''))
d4 = dict(f['fields']); d4.update({'form_fields[name]': 'Bot Four', 'form_fields[email]': f'bot4{STAMP}@gmail.com', 'form_fields[message]': message(4), 'action': 'elementor_pro_forms_send_form'})
cases.append(('elementor', lambda: post(BASE + '/wp-admin/admin-ajax.php', d4)))
f = form_on('/comment-test/', lambda f: f['attrs'].get('id') == 'commentform')
d5 = dict(f['fields']); d5.update({'author': 'Bot Five', 'email': f'bot5{STAMP}@gmail.com', 'comment': message(5)})
cases.append(('comment', lambda: post(BASE + '/wp-comments-post.php', d5)))
f = form_on('/wp-login.php?action=register', lambda f: f['attrs'].get('id') == 'registerform')
d6 = dict(f['fields']); d6.update({'user_login': f'botsix{STAMP}', 'user_email': f'bot6{STAMP}@gmail.com'})
cases.append(('registration', lambda: post(BASE + '/wp-login.php?action=register', d6)))
f = form_on('/my-account/', lambda f: 'woocommerce-form-register' in f['attrs'].get('class', ''))
d7 = dict(f['fields']); d7.update({'email': f'bot7{STAMP}@gmail.com', 'register': 'Register'})
cases.append(('woocommerce', lambda: post(BASE + '/my-account/', d7)))

for name, send in cases:
    before = json.loads(wp('breakwater', 'log', '--limit=1', '--format=json') or '[]')
    send()
    after = json.loads(wp('breakwater', 'log', '--limit=1', '--format=json') or '[]')
    row = after[0] if after and (not before or after[0]['id'] != before[0]['id']) else None
    print(f"{name:15s}", f"{row['verdict']}/{row['action']} {row['score']} [{row['signals']}]" if row else 'NO NEW LOG ROW')
    # tests/release-check.mjs reads every suite's rows from the file named by RESULTS.
    if os.environ.get('RESULTS'):
        with open(os.environ['RESULTS'], 'a') as out:
            out.write(json.dumps({'suite': 'nojs', 'site': BASE, 'who': 'no-js-bot', 'form': name, 'id': int(row['id']) if row else None, 'verdict': row['verdict'] if row else ''}) + '\n')
