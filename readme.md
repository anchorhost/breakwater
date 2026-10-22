# Breakwater

By [Anchor Hosting](https://anchor.host). Spam protection for WordPress comments, registrations and contact forms. No API keys, no accounts, no setup: activate it and every supported form is covered.

Requires PHP 8.1+ (on older PHP it shows an admin notice and stays off) and WordPress 6.0+.

## What it checks

Each submission is scored. At 5 points it is spam (`breakwater_threshold`).

| Signal | Points | |
|---|---|---|
| Honeypot filled | 10 | Hidden field added by the script |
| No browser proof | 6 | The script never ran: a bot posting straight to the form |
| Proof of work forged or wrong | 8 | |
| Proof of work reused / not finished / expired | 3 / 2 / 1 | |
| Automated browser (`navigator.webdriver`) | 4 | |
| Bot user agent (curl, python, HeadlessChrome, ...) | 4 | |
| Sent under 2.5s after page load / no clicks or keys | 3 / 2 | |
| BBCode or HTML links | 4 | |
| 3+ links / 1-2 links | 3 / 1 | |
| Link in the name field | 3 | |
| Message mostly in a script the site doesn't use | 4 | Skipped for sites in those languages |
| Spam phrases | 1 each, max 3 | `breakwater_phrases` |
| Throwaway email domain / domain can't receive mail | 3 | List in `data/disposable-domains.txt` (CC0) |
| Greets the site by its address / a link to another site carrying it / names it | 4 / 3 / 1 | Mail-merge outreach ("Hello Example Com"); the strongest counts. Skipped when the site's name is its address |
| Same message already caught as spam today | 3 | |
| IP sent spam 2+ times in the last hour | 2 | |
| Fleet: IP sent spam to 4+ / 11+ sites | 2 / 3 | Only with a fleet list (below) |
| Fleet: sender sent spam to 3+ / 6+ sites | 3 / 5 | |
| Fleet: same or near-identical message on 4+ / 11+ sites | 3 / 5 | Exact hash or MinHash bands, so reworded copies match |

**Fleet list.** A host can store a list of hashes its other sites have seen (`breakwater_reputation`, via `Breakwater\Reputation::store()` or `wp breakwater reputation --import=<file>`); Breakwater scores against it and ignores it once it is 7 days old. On Anchor Hosting, CaptainCore builds it from every site's verdicts and pushes it during the daily sync. Only one-way hashes travel; a "Not spam" on any site takes a sender off the list. Without a list, the fleet signals simply never fire.

The proof of work is stateless and cache-safe: the page carries only the endpoint URL, the browser fetches a signed challenge from `/wp-json/breakwater/v1/challenge` when someone first touches a field, and each solution is accepted once. The signing key is derived from the site's own salts, so every site has its own and nothing is shared.

The proof travels in hidden fields added to the form and, as a fallback, in two first-party cookies (`bw_token`, `bw_meta`; SameSite=Lax, 12 hours). The cookies matter for form plugins that post their own payload instead of the form's inputs (Ninja Forms, Constant Contact, MailPoet).

Logged-in users who can edit posts or moderate comments are never checked (`breakwater_skip`).

## When it gets one wrong

Nothing is ever lost on CleanTalk-style servers: every held submission stays on the site.

- **Daily digest.** Submissions that were stopped go out once a day to whoever receives that form's notifications (Gravity Forms notifications, CF7's mail recipient, WPForms notifications; the site admin otherwise), each with a **Deliver** link. The link opens a confirmation page whose button sends it on, so mail scanners that open links cannot deliver anything. Obvious bots (honeypot filled, no browser, forged proof of work, automated browser, script injection) are counted but not listed. Turn it off with `define( 'BREAKWATER_DIGEST', false );` or the `breakwater_digest_enabled` filter; `wp breakwater digest [--dry-run]` sends it now.
- **"Not spam" hands it back, wherever it is clicked**: the Breakwater log, a Deliver link, the form plugin's own Not spam button, or `wp breakwater release <id>`. The submission ends up as if it had never been held: saved where the plugin saves it, with the plugin's own emails and integrations run (the table below says what that means for each). Only one click wins, a replay is never judged again, and what happened is shown and kept on the log row. When a plugin can't take it back (a deleted form, uploads, payments, an email taken since), the fields are emailed to the form's recipients instead.
- **The sender gets the benefit of the doubt.** They are trusted on that site for a year (a -10 signal, so a bot borrowing the address still trips the honeypot or browser checks), and the correction is reported to the fleet so the sender leaves the shared reputation list.
- **Sign-ups end in a confirmation.** A released registration or mailing-list sign-up always sends the person something to act on (a set-password link, a confirmation email). Nobody is logged in or silently subscribed.
- **Retrying is safe.** A person sending the same message again after being refused is not counted against: their own earlier attempt never feeds the repeat or busy-IP signals. Refused sign-ups are told to wait for review instead, since a retry would find the email taken after a release.

## Integrations

| Integration | Spam goes to | "Not spam" does |
|---|---|---|
| Comments and WooCommerce product reviews | The Spam folder | Approved with the post's author told, or pending with the moderators told, as WordPress would have |
| Gravity Forms | Spam entries, reason in the entry notes | Back to the entries, notifications sent and feeds run |
| Contact Form 7 | Flamingo's Spam folder when Flamingo is active, refused otherwise | Submitted again without the spam check: Mail, Mail (2) and every after-send integration; Flamingo's message moves to the inbox |
| Jetpack Forms | Feedback marked spam | Moved to the inbox, email sent, webhooks and integrations run |
| Formidable Forms 7+ | Spam entries | Restored, and the form's pending actions run |
| Forminator | Spam entries (refused on payment and login forms) | Restored, emails and integrations run |
| Fluent Forms | Refused, and kept as a Spam entry | Moved to the entries, emails and integrations run |
| Elementor Pro | Refused | Saved to Submissions, the form's actions run |
| WPForms | Refused (Pro: spam entries) | Notifications and integrations run (Pro: restored) |
| Ninja Forms | Refused | Processed again: saved, every action run |
| Avada Forms | Refused | Saved to the form's submissions, actions and notifications run |
| Kadence (Advanced and basic Form) | Refused | The form's actions run; the basic block's email is rebuilt as Kadence builds it |
| Divi contact form (4 and 5) | Refused with Divi's "You must be a human" | The email rebuilt exactly as Divi sends it |
| Beaver Builder Contact Form | Refused with the module's error | The email sent exactly as Beaver Builder built it |
| Beaver Builder Subscribe Form | Refused, asked to wait for review | Handed to its service: Mailchimp as pending, Email Address as usual; other services emailed |
| Breakdance Form Builder | Refused with Breakdance's form error | The form's actions run (store submission, email, webhooks, integrations) |
| WordPress and WooCommerce registration, Ultimate Member | Refused, asked to wait for review | The account is created and the person gets a set-password email |
| MailPoet, Mailchimp for WP | Refused, asked to wait for review | Subscribed as unconfirmed/pending, so the list asks the person to confirm |
| Constant Contact Forms | Refused | The plugin's own steps after its spam check: notification email, contact added when they opted in |

`wp breakwater integrations` lists them and which ones are on the site. Adapters live in `src/Adapters/`, one class each, extending `Adapter` and listed in `Registry` (filter: `breakwater_adapters`); `release()` is where an adapter hands a submission back.

Every checked submission, spam or clean, keeps its full payload in the Breakwater log (Tools → Breakwater) for 90 days (filter: `breakwater_keep_days`), and a held one keeps what its plugin needs to take it back until it is released. Passwords, card fields and captcha tokens are never stored.

## Modes

- **Automatic** (default): monitor while CleanTalk is active, protect otherwise.
- **Protect**: act on spam.
- **Monitor**: check and log everything, stop nothing. Verdicts from CleanTalk or Akismet are logged next to ours for comparison.
- **Off**

Pin a mode fleet-wide with `define( 'BREAKWATER_MODE', 'monitor' );`.

## Updates

The regular plugin updates itself from GitHub: `manifest.json` on `main` names the latest version and its release zip, and WordPress offers it on the Plugins screen like any other update (auto-updates work too). The manifest is fetched at most every 12 hours. A copy running from a git checkout is never offered updates, and the single-file mu-plugin leaves the updater out entirely: it is updated by deploying a new build.

## Single-file mu-plugin

For hosts deploying across many sites, Breakwater builds into one self-contained file: every class in bracketed namespace blocks, with the script, admin stylesheet, mark and disposable-domain list embedded (`src/Assets.php` serves them from the bundle instead of from files).

```
php bin/build-mu-plugin.php                       # → dist/breakwater.php
php bin/mu-deploy-script.php --mode=monitor       # → dist/deploy-mu.sh (embeds the build)
php bin/mu-deploy-script.php --remove             # → dist/remove-mu.sh
```

`deploy-mu.sh` runs in a site's web root (for example `captaincore ssh <sites> --script=dist/deploy-mu.sh --parallel=20 --label`). Per site it:

- skips anything that isn't WordPress, runs PHP older than 8.1 (the file would not parse), or has the regular plugin active;
- writes `mu-plugins/breakwater.php` atomically after checking its sha256;
- sets the mode given with `--mode` only on sites that have never had one (`--force-mode` sets it everywhere);
- loads WordPress to confirm Breakwater boots, and removes the file again if it doesn't;
- prints one line: `BREAKWATER|<home>|installed|<version> <mode>` (or `skip` / `failed` with the reason).

`remove-mu.sh` takes the file out and keeps the site's settings and log. When both the mu-plugin and the regular plugin are present, the mu-plugin loads first and the plugin stands aside.

Each GitHub release carries the build as `breakwater-mu.php` with its `breakwater-mu.php.sha256`. CaptainCore's `deploy-breakwater` remote script installs it from the latest release the same way (`captaincore ssh <sites> --script=deploy-breakwater --mode=monitor --parallel=20 --label`; `--version=<tag>` pins a release, `--remove` takes it out), and puts the previous copy back if a new one doesn't boot. The `deploy-mu.sh` route is for unreleased builds.

## WP-CLI

```
wp breakwater status [--days=30] [--format=json]
wp breakwater integrations
wp breakwater log [--verdict=spam] [--source=gravityforms] [--limit=20] [--format=json]
wp breakwater check --content="..." --email=... [--name=...]
wp breakwater release <id>
wp breakwater digest [--dry-run]
```

## Tests

```
# The original eight forms (Gravity Forms, CF7 + Flamingo, WPForms, Elementor Pro, comments, registration, WooCommerce)
PLAYWRIGHT=/path/to/node_modules/playwright node tests/browser.mjs https://protect.localhost
python3 -I tests/nojs.py https://protect.localhost

# Any page with a form: fills fields by type, skips honeypots a person can't see
WP_PATH=/site/public node tests/forms.mjs https://site.localhost /page-a/ /page-b/

# Kadence Advanced Form through its AJAX handler
WP_PATH=/site/public FORM_ID=44 node tests/kadence.mjs https://site.localhost
```

`tests/setup/forms-site.php` builds a form for each free plugin (Jetpack in offline mode, Ninja, Fluent, MC4WP, Formidable, Forminator, MailPoet, Constant Contact, Kadence, Ultimate Member) on a sandbox site: `wp eval-file tests/setup/forms-site.php`. The sandbox also needs MailPoet's per-IP throttle off (`mailpoet_subscription_limit_enabled`), since every test persona shares one IP. Divi and Avada are tested on their own sites with a contact form module / a `fusion_form` set to AJAX; Beaver Builder and Breakdance on theirs, built by `tests/setup/beaver-builder.php` and `tests/setup/breakdance.php`.

`tests/setup/mail-log.php`, copied into a sandbox's mu-plugins, appends every `wp_mail()` to `wp-content/bw-mail.log`, which is how a release is shown to have sent its emails once.

`tests/backtest/` replays the fleet's labelled submissions through the checker (see its README).
