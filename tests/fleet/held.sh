#!/bin/bash
#
#   Held submissions on fleet sites running Breakwater, for reviewing a rollout.
#
#   captaincore ssh <slugs> --script=held.sh --parallel=20 --label -- --days=7
#
#   Each site prints one summary line, then one line per held (blocked) or logged-as-spam row:
#     BWSUM|<home>|<version> <mode>|checked=<n> spam=<n> held=<n> released=<n> notspam=<n>
#     BWROW|<home>|<id>|<created>|<source>|<form>|<score>|<signals>|typed=<n>|pasted=<n>|<outcome>|<excerpt>
#   Excerpts carry what people typed: keep the output out of any repository.
#   Read-only. Values travel between <bw> markers because plugins can print after wp eval.

days=7
for arg in "$@"; do
  case "$arg" in
    --days=*) days="${arg#*=}" ;;
  esac
done
[[ "$days" =~ ^[0-9]+$ ]] || days=7

[ -f wp-config.php ] || cd ~/public 2>/dev/null
if [ ! -f wp-config.php ]; then
  echo "BWSUM|$(pwd)|skip|not a WordPress root"
  exit 0
fi

read -r -d '' probe <<'PHPEOF'
<?php
$days = (int) getenv( 'BW_DAYS' ) ?: 7;
$home = home_url();
if ( ! class_exists( 'Breakwater\\Plugin', false ) ) {
	echo "<bw>BWSUM|$home|not running|</bw>\n";
	return;
}
global $wpdb;
$t     = $wpdb->prefix . 'breakwater_log';
$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
$sum   = $wpdb->get_row( $wpdb->prepare(
	"SELECT COUNT(*) checked, SUM(verdict = 'spam') spam, SUM(action = 'blocked') held,
	SUM(outcome <> '') released, SUM(feedback = 'ham') notspam FROM $t WHERE created_at > %s", $since
) );
printf( "<bw>BWSUM|%s|%s %s|checked=%d spam=%d held=%d released=%d notspam=%d</bw>\n", $home,
	Breakwater\Plugin::VERSION, Breakwater\Plugin::mode()->value,
	$sum->checked, $sum->spam, $sum->held, $sum->released, $sum->notspam );
$clean = static fn( $v ) => str_replace( [ '|', "\n", "\r", '<', '>' ], [ '/', ' ', ' ', '', '' ], (string) $v );
foreach ( $wpdb->get_results( $wpdb->prepare(
	"SELECT id, created_at, source, form, score, signals, payload, outcome, feedback, excerpt FROM $t
	WHERE created_at > %s AND verdict = 'spam' ORDER BY id", $since
) ) as $r ) {
	$signals = json_decode( (string) $r->signals, true );
	$names   = is_array( $signals ) ? implode( ',', array_keys( $signals ) ) : '';
	$meta    = json_decode( (string) $r->payload, true )['meta'] ?? [];
	printf( "<bw>BWROW|%s|%d|%s|%s|%s|%d|%s|typed=%s|pasted=%s|%s|%s</bw>\n", $home, $r->id, $r->created_at,
		$r->source, $clean( mb_substr( (string) $r->form, 0, 60 ) ), $r->score, $names,
		$meta['t'] ?? '-', $meta['p'] ?? '-', $clean( $r->feedback ?: $r->outcome ),
		$clean( mb_substr( preg_replace( '/\s+/', ' ', (string) $r->excerpt ), 0, 160 ) ) );
}
PHPEOF

printf '%s\n' "$probe" | BW_DAYS="$days" timeout 90 wp eval-file - --skip-themes 2>/dev/null \
  | sed -n 's/.*<bw>\(.*\)<\/bw>.*/\1/p'
