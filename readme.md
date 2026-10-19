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
- **"Not spam" counts, wherever it is clicked**: the Breakwater log, a Deliver link, Gravity Forms' Not Spam, or approving a spam comment. The sender is trusted on that site for a year (a -10 signal, so a bot borrowing the address still trips the honeypot or browser checks), a Gravity Forms entry or comment is taken out of Spam, and the correction is reported to the fleet so the sender leaves the shared reputation list.
- **Retrying is safe.** A person sending the same message again after being refused is not counted against: their own earlier attempt never feeds the repeat or busy-IP signals.

## Integrations

| Integration | Hook | Spam goes to |
|---|---|---|
| Comments | `pre_comment_approved` | The Spam folder |
| Gravity Forms | `gform_entry_is_spam` | Spam entries, reason in the entry notes |
| Contact Form 7 | `wpcf7_spam` | Flamingo's Spam folder when Flamingo is active |
| Jetpack Forms | `jetpack_contact_form_is_spam` | Feedback marked spam, email skipped |
| Formidable Forms 7+ | `frm_validate_entry` + spam source `breakwater` | Spam entries, actions and emails skipped (older: form error) |
| Forminator | `forminator_spam_protection` | The form's spam setting: refused (default) or spam entry |
| Constant Contact Forms | `constant_contact_maybe_spam` | Refused with its spam message, nothing sent to the API |
| Mailchimp for WP | `mc4wp_form_errors` | Refused with "Your submission was marked as spam." |
| Divi contact form (4 and 5) | module attributes: forces Divi's spam check to fail | Refused with Divi's "You must be a human" |
| Avada Forms | `fusion_builder_form_submission_data` | Refused with the form's error notice, nothing saved |
| Elementor Pro, WPForms, Ninja Forms, Fluent Forms, Kadence (Advanced and basic Form), MailPoet, Ultimate Member, WordPress and WooCommerce registration | each plugin's validation hook | Refused with a message |

`wp breakwater integrations
wp breakwater digest [--dry-run]` lists them and which ones are on the site. Adapters live in `src/Adapters/`, one class each, extending `Adapter` and listed in `Registry` (filter: `breakwater_adapters`).

Every spam submission keeps its full payload in the Breakwater log (Tools → Breakwater) for 30 days. Forms without a spam folder get an "Email to admin" action, so a false positive is never lost. Passwords and card fields are redacted before anything is stored.

## Modes

- **Automatic** (default): monitor while CleanTalk is active, protect otherwise.
- **Protect**: act on spam.
- **Monitor**: check and log everything, stop nothing. Verdicts from CleanTalk or Akismet are logged next to ours for comparison.
- **Off**

Pin a mode fleet-wide with `define( 'BREAKWATER_MODE', 'monitor' );`.

## WP-CLI

```
wp breakwater status [--days=30] [--format=json]
wp breakwater integrations
wp breakwater log [--verdict=spam] [--source=gravityforms] [--limit=20] [--format=json]
wp breakwater check --content="..." --email=... [--name=...]
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

`tests/setup/forms-site.php` builds a form for each free plugin (Jetpack in offline mode, Ninja, Fluent, MC4WP, Formidable, Forminator, MailPoet, Constant Contact, Kadence, Ultimate Member) on a sandbox site: `wp eval-file tests/setup/forms-site.php`. The sandbox also needs MailPoet's per-IP throttle off (`mailpoet_subscription_limit_enabled`), since every test persona shares one IP. Divi and Avada are tested on their own sites with a contact form module / a `fusion_form` set to AJAX.

`tests/backtest/` replays the fleet's labelled submissions through the checker (see its README).
