# Changelog

## Unreleased (0.3.0)

* **Fixes from the pre-release review.**
  * WPForms payment forms are checked before Stripe, Square or PayPal charge the card. A held payment was charged first.
  * WooCommerce registration is checked however the form sends its nonce; a bot posting it as `_wpnonce` skipped the check.
  * Gravity Forms entries made from code (`GFAPI`, WP-CLI, cron, the REST API) aren't judged as visitors, and Save and Continue no longer logs password or card inputs.
  * Login and password forms in Avada and Forminator are never held.
  * Card fields are redacted whatever their label (Ninja Forms names them `creditcardnumber` and so on), secret field names are matched as words ("Passenger" and "Postcard" are kept, "Security code", "Sort code", "Kennwort" and "Contraseña" are not), and Avada's password fields are left out of what a release needs.
  * A sender marked not spam before no longer gets a bot past the checks: the benefit of the doubt is dropped when a signal only automation trips fires.
  * Deliver links and proofs of work are bound to the site, so they can't be used on another site of a multisite network or another tenant that shares its salts. Deliver links in digests sent before this update stop working.
  * A digest that fails to send is tried again the next day, and a release that fails, or that a second click lost, leaves the submission ready to deliver instead of marked.
  * The page script never freezes the page when the REST API is blocked, a double click no longer sends a held form twice, and Safari before 16 sends forms without waiting for the proof.
  * What one submission can store is limited (known browser-proof keys only, 20,000 characters a field, 100 fields), invalid UTF-8 no longer switches off the fingerprint and foreign-script checks, and no DNS lookup is made for a submission already held.
* **Updates only ever come from this repository.** The plugin's `Update URI` keeps WordPress.org out of it, so a plugin registered there under the same name can never replace it. GitHub is asked only during WordPress's own update check, never while an admin page loads, only this repository's release downloads are accepted, and a copy installed under another folder name (GitHub's "Download ZIP") stays active through an update.
* **Personal data.** Deleting the plugin removes its log, settings and scheduled events (kept when the must-use build is installed too). Tools → Export and Erase Personal Data cover the log, and suggested privacy policy text is added.
* **Browsers that don't match their user agent are logged.** A fixed list of bad user agents would catch little: in the fleet backtest, a list of agents seen only in spam caught 0.5% more Gravity Forms spam, because spam sends the same browser strings people do. What spam gets wrong is the rest of the browser. Each log row now keeps the headers browsers send with a form (fetch metadata, client hints, Accept-Language) and notes, at 0 points, a Chrome 12+ versions old, missing fetch metadata or languages, client hints that contradict the user agent, and a script that saw a different agent from the server. Nothing is scored on them yet; the log will show what each is worth.
* **Minn Admin shows the log.** With Minn Admin installed, its Forms screen lists every checked submission with the reasons it scored, and "Not spam" there hands a held one back exactly as Tools → Breakwater does. Both build on a new `Breakwater\Api` that other admin screens can use too.
* **Mail-merge outreach that greets the site by its address is scored.** "Hello Example Com" or "Hey team example.com" adds 4, a link to another site carrying the site's address (`directoryinspector.com/example.com`) 3, naming the address 1, only the strongest counting, and never when the site's name is its address. In the fleet backtest, 122 of 123 kept entries greeted this way were spam the site had kept, and Gravity Forms spam caught by on-site checks rose from 8.6% to 9.6% (with fleet signals, 46.9% to 48.8%).
* **Typing and pasting are recorded.** The browser proof now counts the characters typed and pasted into message boxes (counts only, never the text), and the log keeps the browser proof with each row. Nothing is scored on it yet.
* **"View log" sits with the plugin's details.** The Log link under the plugin's name, unusual for a must-use plugin, is now "View log" after "Visit plugin site" in both plugin lists.
* **Every submission is logged in full for 90 days.** The log kept only the verdict for clean submissions and dropped them after 14 days, and spam after 30. Clean submissions now keep their message and fields too, so a missed spam can be read before it is marked, and every row is kept 90 days (filter: `breakwater_keep_days`).
* **"Not spam" hands a submission back to its form.** Wherever it is clicked (Tools → Breakwater, a digest Deliver link, or the form plugin's own button), a held submission now ends up as if it had never been held, and the result is shown and kept on the log row. Only one click wins, and a replay is never judged again. `wp breakwater release <id>` does the same from the command line.
  * Gravity Forms: restored to the entries, and its notifications and feeds run (Gravity Forms runs neither when an entry is marked Not Spam).
  * Comments: approved and the post's author told when WordPress would have approved it, otherwise pending with the moderators told.
  * WordPress and WooCommerce registration: the account is created as the form would have (WordPress's set-password email, or WooCommerce's welcome email with a set-password link). Nobody is logged in. If the email or username was taken since, it is emailed to the admin instead.
  * Elementor Pro: saved to Elementor's submissions with the original page, time, IP and browser, and the form's actions run (email, webhooks, integrations). Forms with uploads are emailed instead.
  * Contact Form 7: submitted again with the spam checks skipped, so Mail, Mail (2) and every after-send integration run; with Flamingo the spam message moves to the inbox (no duplicate) and Flamingo's own Not spam releases it too.
  * WPForms: Lite sends the notifications and runs the integrations; Pro (untested) keeps held submissions as spam entries and restores them. The check now runs once WPForms has formatted the fields, so bots WPForms refuses itself are no longer logged.
  * Fluent Forms: held submissions are now kept as Spam entries (as Fluent keeps Akismet's), with no emails or integrations run. Released here or with Fluent's own status change, the entry moves to the entries and the form's emails and integrations run. Payment and registration forms are still only refused.
  * Ninja Forms: the submission goes through Ninja's own processing again, so it is saved and every action runs. Forms with uploads, card fields or payments are emailed instead.
  * Kadence: the Advanced Form runs its actions (email and integrations); the basic Form block sends its email exactly as Kadence builds it (its MailerLite and FluentCRM steps aren't run, and the result says so).
  * Avada Forms: saved to the form's submissions, with its actions and notifications run and its submission count raised. Login, registration and password forms, and forms with uploads, are emailed instead.
  * Divi (4 and 5): Divi only emails, so the email is rebuilt exactly as the form builds it (recipient, subject, message pattern, Reply-To) and Divi's submit hook fires.
  * Jetpack Forms: moved to the inbox, with its email (rendered again as not spam) and its webhooks and integrations. Jetpack's own Not spam releases it too. Jetpack deletes spam after 15 days; after that it is emailed instead.
  * Formidable: restored from spam with the form's pending actions run; Formidable's own Not spam releases it without running the actions twice.
  * Forminator: held submissions are now kept as spam entries (on forms that store submissions and take no payment or login) instead of refused, and release restores them and runs Forminator's emails and integrations.
  * MailPoet: subscribed as unconfirmed through MailPoet's own subscribe step, so the person gets MailPoet's confirmation email. Only when MailPoet's signup confirmation is on; otherwise it is emailed to the admin.
  * Mailchimp for WP: sent to Mailchimp as pending on every list, always, so Mailchimp asks the person to confirm. Unsubscribe forms are never subscribed on release, and on MC4WP 4.11.2+ they aren't checked at all.
  * Constant Contact Forms: does what the plugin does after its spam check (notification email, and the contact added or queued when the person opted in). Whether Constant Contact asks them to confirm is the account's own setting.
  * Ultimate Member: the account is created through UM's own registration step without a password, so UM's welcome email asks the person to set one; approval and activation settings apply as usual. Nobody is logged in. Sign-ups without the form's privacy agreement are left for UM to refuse.
* **Beaver Builder forms.** The Contact Form and Subscribe Form modules are checked after Beaver Builder's own checks, and spam is refused with the module's own error. Released, the contact form's email goes out exactly as Beaver Builder built it; a subscribe form is handed to its service (Mailchimp always as pending, so the person confirms; the Email Address service as usual). Other services are emailed to the admin instead.
* **Breakdance forms.** Form Builder submissions are checked after Breakdance's own validation, before any action runs, and spam is refused with Breakdance's form error. Released, the form's actions run from its current settings (store submission, email, webhooks, integrations), and the stored submission is linked to the log row. Forms with uploads, passwords, or login, registration or payment actions are emailed instead.
* **Sign-ups are told to wait, not to retry.** A held registration now says it was flagged for review and that an email follows if it's approved, instead of "your message was not sent". The `breakwater_message` filter gets the kind as a second argument.
* **WooCommerce product reviews are checked.** They were skipped as a comment type of their own; they are now judged and released like any comment.
* **Comment excerpts show quotes plainly.** Excerpts in the log read `I\'d` because the comment was logged before WordPress unslashed it.
* **Divi log rows name their form.** They read "contact form" for every form because the adapter looked for a field Divi never sends; they now name the page and the form, so the digest goes to the form's own recipient.
* **Updates from GitHub.** The regular plugin offers new releases on the Plugins screen, from `manifest.json` on `main` and the release's zip. The single-file mu-plugin leaves the updater out, and a git checkout is never updated.
* **Released Gravity Forms entries send their notifications and feeds.** Gravity Forms skips both for spam entries and doesn't run them when an entry is marked Not Spam, so a released entry sat in the list unseen. Breakwater now sends them once when an entry it logged leaves Spam, from Gravity Forms' own button, the Breakwater log or a digest Deliver link. Entries that were never held are not sent twice.

## 0.2.1 (2026-10-19)

* **Multi-page Gravity Forms no longer hold people who send the last page quickly.** The clock and counts carry over a form submit in the same tab, Next and Previous are never held, and a resumed draft is not judged on time.
* **Save and Continue is checked.** A draft with a signal only automation produces is refused, since drafts make Gravity Forms email resume links to any address.
* Single-file mu-plugin build and fleet deploy script, which purges the page cache after installing.

## 0.2.0 (2026-10-19)

* First version: on-site scoring, browser proof of work, 19 integrations, daily digest with Deliver links, fleet reputation list, monitor mode alongside CleanTalk or Akismet.
