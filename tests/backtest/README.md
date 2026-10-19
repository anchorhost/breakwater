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
