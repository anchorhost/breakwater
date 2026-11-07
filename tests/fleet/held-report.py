"""Summarise held.sh output and flag held rows a person should read.

    python3 -I tests/fleet/held-report.py <held.sh output>

A row is flagged when it could be a real person:
  * soft      nothing only automation trips (honeypot, no_js, pow_invalid, webdriver, injection)
  * cache     held on no_js with no content or fleet signal: a page cached before the script
              went in sends every visitor without a proof
  * typed     the visitor typed into the message box and no automated browser was detected
The output repeats excerpts from customer sites: keep it out of any repository.
"""
import collections
import sys

CERTAIN = {"honeypot", "no_js", "pow_invalid", "webdriver", "injection"}
CONTENT = {"links", "many_links", "phrases", "site_address", "injection", "bot_name", "foreign_script",
           "disposable_email", "dead_email_domain"}


def flags(signals, typed):
    names = set(filter(None, signals.split(",")))
    out = []
    if not names & CERTAIN:
        out.append("soft")
    if "no_js" in names and not names & CONTENT and not any(n.startswith("fleet_") for n in names):
        out.append("cache")
    if typed.isdigit() and int(typed) > 0 and "webdriver" not in names:
        out.append("typed")
    return out


def main(path):
    sites, rows = {}, []
    for line in open(path, encoding="utf-8", errors="replace"):
        line = line.rstrip("\n")
        if line.startswith("BWSUM|"):
            parts = line.split("|", 3)
            sites[parts[1]] = parts[2:] if len(parts) > 2 else []
        elif line.startswith("BWROW|"):
            p = line.split("|", 11)
            if len(p) == 12:
                rows.append(dict(home=p[1], id=p[2], created=p[3], source=p[4], form=p[5], score=p[6],
                                 signals=p[7], typed=p[8].split("=", 1)[-1], pasted=p[9].split("=", 1)[-1],
                                 outcome=p[10], excerpt=p[11]))

    totals = collections.Counter()
    modes = collections.Counter()
    for home, info in sites.items():
        if len(info) >= 2:
            modes[info[0]] += 1
            for pair in info[1].split():
                key, _, value = pair.partition("=")
                if value.isdigit():
                    totals[key] += int(value)
        else:
            modes[" ".join(info) or "no answer"] += 1
    print(f"Sites: {len(sites)}  " + ", ".join(f"{m}: {n}" for m, n in modes.most_common()))
    print("Totals: " + ", ".join(f"{k} {v}" for k, v in totals.items()))
    print("Signals on spam rows: " + ", ".join(
        f"{s} {n}" for s, n in collections.Counter(
            s for r in rows for s in r["signals"].split(",") if s).most_common(15)))
    print("Sources: " + ", ".join(f"{s} {n}" for s, n in collections.Counter(r["source"] for r in rows).most_common()))

    flagged = [(r, f) for r in rows for f in [flags(r["signals"], r["typed"])] if f]
    print(f"\nFlagged for review: {len(flagged)} of {len(rows)} spam rows\n")
    for r, f in flagged:
        print(f"[{','.join(f)}] {r['home']} #{r['id']} {r['created']} {r['source']} {r['form']} "
              f"score {r['score']} [{r['signals']}] typed={r['typed']} pasted={r['pasted']}"
              + (f" ({r['outcome']})" if r["outcome"] else ""))
        print(f"    {r['excerpt']}")


if __name__ == "__main__":
    main(sys.argv[1])
