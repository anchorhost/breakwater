# Competitor audit (2026-10-19)

Source read from wordpress.org's latest releases (read only, never run). Line pointers are into each
plugin's own tree and will drift with new releases.

## Multi-page Gravity Forms

| Plugin | When it judges | Timer / token across pages | Save and Continue |
|---|---|---|---|
| Breakwater 0.2.1 | final submit only (`gform_entry_is_spam`) | carried over in sessionStorage; Next/Previous never held | drafts with an automation-only signal refused |
| Gravity Forms Zero Spam 1.10.2 | final submit (`get_submission_context() === 'form-submit'`) | token reused 7 days; Next/Previous passed through (1.10.1) | drafts without a valid token aborted |
| CleanTalk | final submit (priority 999); page posts skipped | last page only | not checked |
| Akismet | final submit, via Gravity Forms core | last page only | not checked |
| OOPSpam | final submit | PHP session timer (breaks page caching) | not checked; Partial Entries hook returns an undefined value |
| hCaptcha for WP | only when the target page is 0 or not a real page | n/a | |
| Simple Cloudflare Turnstile | skips page turns only on forms that really have pages (closed a bypass) | n/a | |
| WP Armour | every page (`gform_validation`); free support is single-page only | | |
| Stop Spammers | every POST at `init`; a false positive exits mid-form | dead timer | |

Before 0.2.1 Breakwater's clock restarted on each page: a person who ticked a consent box on the
last page and sent it scored `too_fast`, and a resumed draft did too (`tests/multipage.mjs`).

## Techniques worth noting

- **WPBruiser 3.4**: encrypted browser fingerprint echoed under a random field name; minimum time
  counted from token issue; token never expires. Refreshes its token on bfcache `pageshow`, and
  fires a token event for forms that post JSON.
- **Antispam Bee 2.11** (comments): renames the real comment field to a secret per-site name and
  makes `comment` the trap, catching scripts that post without JS. Skips all checks for anyone
  with an approved comment or a Gravatar.
- **Zero Spam 5.7**: signed key valid for 3 uses (a resend after a validation error passes);
  StopForumSpam, Project Honeypot, country blocks. Spam is aborted and lost.
- **OOPSpam 1.2**: remote API, fails open on error or quota. Local: rate limits, reused gclid cap,
  WooCommerce card testing (orders with no attribution data, failed-payment velocity). "Not spam"
  allowlists the email and IP.
- **Stop Spammers**: cheap checks: hosting IP ranges, Accept header, dots and hyphens in emails, URL
  shorteners, TLD lists. Timer is dead code; any bad nonce skips every check.
- **WP Armour**: checks its honeypot field exists, never its value.
- **Titan**: "current year" answer sits in the page; timer written but never checked.
- **Turnstile**: never verifies hostname or action in Cloudflare's response; fails open.

Where blocked spam goes: a spam folder with a reason (Antispam Bee, GF Zero Spam, Titan, OOPSpam);
lost (Zero Spam, WP Armour, WPBruiser, Turnstile, Stop Spammers, CleanTalk's delete option).

## Integrations others have that we don't

Most common across OOPSpam, hCaptcha, Turnstile, Zero Spam and WP Armour: WooCommerce checkout
(classic and Store API) and product reviews, WP login and lost password, GiveWP, bbPress,
BuddyPress, wpDiscuz, MemberPress, PMPro, WS Form, Bricks, SureForms, Spectra, MetForm, Beaver
Builder, Breakdance, Quform, HappyForms, HTML Forms, EDD, Elementor Atomic forms (separate AJAX
action), Gravity Forms Partial Entries and Gravity Perks Nested Forms.

## Backlog, ranked

1. ~~Save and Continue gate~~ and ~~timer across pages~~ and ~~don't hold page turns~~ (0.2.1).
2. WooCommerce checkout + Store API adapter with card-testing signals.
3. Login and lost-password adapter, browser signals only.
4. Builder adapters by fleet prevalence (survey first).
5. bbPress, BuddyPress, wpDiscuz, membership plugins.
6. Server-side timing from the challenge's issue time, and accepting a solution twice within a
   few minutes from the same IP (a resend after a validation error).
7. Antispam Bee's comment-field swap: direct posts score 10 instead of 6.
8. Cheap signals: hosting IP ranges, URL shorteners, missing Accept-Language, reused gclid.
9. UX: reason column in the comments and GF entry lists, "act as a bot" self-test, bfcache token
   refresh, WP Rocket / LiteSpeed JS exclusions.
