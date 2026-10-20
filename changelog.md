# Changelog

## Unreleased (0.3.0)

* **"Not spam" hands a submission back to its form.** Wherever it is clicked (Tools → Breakwater, a digest Deliver link, or the form plugin's own button), a held submission now ends up as if it had never been held, and the result is shown and kept on the log row. Only one click wins, and a replay is never judged again. `wp breakwater release <id>` does the same from the command line.
  * Gravity Forms: restored to the entries, and its notifications and feeds run (Gravity Forms runs neither when an entry is marked Not Spam).
  * Comments: approved and the post's author told when WordPress would have approved it, otherwise pending with the moderators told.
* **Updates from GitHub.** The regular plugin offers new releases on the Plugins screen, from `manifest.json` on `main` and the release's zip. The single-file mu-plugin leaves the updater out, and a git checkout is never updated.
* **Released Gravity Forms entries send their notifications and feeds.** Gravity Forms skips both for spam entries and doesn't run them when an entry is marked Not Spam, so a released entry sat in the list unseen. Breakwater now sends them once when an entry it logged leaves Spam, from Gravity Forms' own button, the Breakwater log or a digest Deliver link. Entries that were never held are not sent twice.

## 0.2.1 (2026-10-19)

* **Multi-page Gravity Forms no longer hold people who send the last page quickly.** The clock and counts carry over a form submit in the same tab, Next and Previous are never held, and a resumed draft is not judged on time.
* **Save and Continue is checked.** A draft with a signal only automation produces is refused, since drafts make Gravity Forms email resume links to any address.
* Single-file mu-plugin build and fleet deploy script, which purges the page cache after installing.

## 0.2.0 (2026-10-19)

* First version: on-site scoring, browser proof of work, 19 integrations, daily digest with Deliver links, fleet reputation list, monitor mode alongside CleanTalk or Akismet.
