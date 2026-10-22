# Backtest

Replays what the fleet already labelled (Gravity Forms spam/active entries, spam/approved
comments, last 90 days) through the plugin's own `Checker`, then simulates fleet reputation.
Runs in a throwaway lab site, never on a customer site. The export holds customer
submissions: keep it local and delete it when done.

1. Export (read-only, core server): `probe.sh` prints one `BT|<gzip json>` line per site.
   Run with `captaincore ssh <slugs> --script=probe.sh --parallel=20 --label`.
2. `normalize.py export.txt samples.jsonl`: one labelled sample per line; `by` says who
   decided (CleanTalk, GF honeypot, Akismet, captcha) from GF entry notes / comment meta.
3. `mx.py samples.jsonl mx.json`: sender domain mail checks, done up front in parallel.
4. In the lab site, with the plugin active:
   `wp eval-file score.php samples.jsonl results.jsonl mx.json` (each sample judged with
   its own site's locale and home URL; no browser signals).
5. `analyze.py samples.jsonl results.jsonl report/`: report.md plus review files
   (flagged ham, missed spam). Fleet signals count other sites that saw the same IP or
   email as spam, or the same message at all, strictly earlier.
6. `fleet_curve.py samples.jsonl`: spam vs ham hit rates by how many other sites are needed.

"Ham" is whatever the site kept, so it includes spam CleanTalk missed: read the flagged-ham
file before calling anything a false positive.

## Word filter

`words.py samples.jsonl words.jsonl` trains a SpamBayes-style word filter (words, word pairs,
link domains and the sender's email domain, Robinson/Fisher combining) on the same labels, day by
day, so each sample is judged by a model built only from earlier days. A word counts once 3 sites
and 5 samples have used it. It abstains when fewer than half of a message's words are known from
kept messages: the kept side is mostly English, so otherwise any Spanish or German message looks
like spam. `--hash=1` runs it on hashed words, as sites would receive them (same results).

`words_report.py samples.jsonl results.jsonl words.jsonl 2026-08-04 <review-dir>` compares points
plans against the local and local + fleet baselines (two weeks of warm-up skipped).

Results (2026-10-21, test window 2026-08-04 to 2026-10-19, plan "+4 at p >= .99, +2 at .95", so
the filter alone never reaches the threshold of 5):

| Gravity Forms | Spam caught | Kept entries flagged |
|---|---|---|
| Local checks | 8.6% | 0.3% |
| Local + words | 40.1% | 2.2% |
| Local + fleet | 46.9% | 2.5% |
| Local + fleet + words | 61.6% | 3.6% |

With the site-address signal (2026-10-21, `results-v3.jsonl`): local 9.6%, local + words 45.8%,
local + fleet 48.8%, local + fleet + words 63.2% of Gravity Forms spam.

Comments: local + fleet 71.2% to 77.6%, no change in flagged approved comments. Of 80 kept
entries the filter newly flagged, sampled at random, all 80 were spam the site had kept (SEO,
virtual assistants, "Vantovo" traffic, product pitches). Single words without pairs only reach
25.5% (local + words).
