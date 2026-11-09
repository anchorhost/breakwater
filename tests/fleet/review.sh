#!/bin/bash
#
#   Follow-up review of a Breakwater rollout. Read-only.
#
#   captaincore ssh <slugs> --script=review.sh --parallel=20 --label -- --days=14 [--all]
#
#   The window starts at the install (the must-use file's time) when that is later. Web log counts
#   cover every access log file touched in the window. Entries saved by logged-in editors have no
#   log row by design, so gf_unlogged counts those too.
#
#   Lines (values between <bw> markers):
#     BWS|<home>|key=value ...                      one per site
#     BWR|<home>|<id>|<created>|<source>|<form>|<verdict>/<action>|<score>|<signals>|typed|pasted|<other>|<feedback>|<excerpt>
#         every spam row, clean rows scoring 2+ or marked, and with --all every row
#   Excerpts carry what people typed: keep the output out of any repository.

days=14
every=0
for arg in "$@"; do
  case "$arg" in
    --days=*) days="${arg#*=}" ;;
    --all) every=1 ;;
  esac
done
[[ "$days" =~ ^[0-9]+$ ]] || days=14

[ -f wp-config.php ] || cd ~/public 2>/dev/null
if [ ! -f wp-config.php ]; then
  echo "BWS|$(pwd)|skip=not-wordpress"
  exit 0
fi

# Web logs: challenge fetches and all requests, in the files that cover the window.
logs=""
if [ -d ../logs ]; then
  files=$(find ../logs/ -maxdepth 1 -name 'access.log*' -mtime -"$days" 2>/dev/null)
  if [ -n "$files" ]; then
    all=0; chal=0; posts=0
    for f in $files; do
      case "$f" in *.gz) c=zcat ;; *) c=cat ;; esac
      a=$($c "$f" 2>/dev/null | wc -l); all=$((all + a))
      b=$($c "$f" 2>/dev/null | grep -c 'breakwater/v1/challenge'); chal=$((chal + b))
      p=$($c "$f" 2>/dev/null | grep -c 'POST "/wp-admin/admin-ajax.php'); posts=$((posts + p))
    done
    logs="req=$all challenge=$chal ajax_posts=$posts"
  fi
  errs=$(cat ../logs/error.log* 2>/dev/null | grep -ci 'breakwater')
  logs="$logs php_errors=$errs"
  sample=$(cat ../logs/error.log* 2>/dev/null | grep -i 'breakwater' | tail -2 | tr '|\n' '/ ' | cut -c1-400)
  [ -n "$sample" ] && logs="$logs err_sample=${sample// /_}"
fi

read -r -d '' probe <<'PHPEOF'
<?php
$days = (int) getenv( 'BW_DAYS' ) ?: 14;
$home = home_url();
$out  = [];
if ( ! class_exists( 'Breakwater\\Plugin', false ) ) {
	echo "<bw>BWS|$home|running=no " . getenv( 'BW_LOGS' ) . "</bw>\n";
	return;
}
global $wpdb;
$mu    = WPMU_PLUGIN_DIR . '/breakwater.php';
$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
if ( file_exists( $mu ) ) {
	$installed = gmdate( 'Y-m-d H:i:s', filemtime( $mu ) );
	$since     = max( $since, $installed );
	$out[]     = 'installed=' . str_replace( ' ', 'T', $installed );
	$out[]     = 'mu_kb=' . round( filesize( $mu ) / 1024 );
}
$out[] = 'version=' . Breakwater\Plugin::VERSION;
$out[] = 'mode=' . Breakwater\Plugin::mode()->value;
$out[] = 'php=' . PHP_VERSION;
$out[] = 'wp=' . get_bloginfo( 'version' );

$t = $wpdb->prefix . 'breakwater_log';
foreach ( $wpdb->get_results( $wpdb->prepare(
	"SELECT source, verdict, action, COUNT(*) n FROM $t WHERE created_at > %s GROUP BY source, verdict, action", $since
) ) as $r ) {
	$out[] = "rows.{$r->source}.{$r->verdict}.{$r->action}={$r->n}";
}
$fb = $wpdb->get_results( $wpdb->prepare( "SELECT feedback, COUNT(*) n FROM $t WHERE created_at > %s AND feedback <> '' GROUP BY feedback", $since ) );
foreach ( $fb as $r ) {
	$out[] = "feedback.{$r->feedback}={$r->n}";
}
$out[] = 'released=' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE created_at > %s AND outcome <> ''", $since ) );
$out[] = 'log_rows_all=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
$size  = $wpdb->get_row( $wpdb->prepare(
	'SELECT ROUND((data_length + index_length)/1024) kb FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $t
) );
$out[] = 'log_kb=' . (int) ( $size->kb ?? 0 );

// Options and transients Breakwater keeps.
$opt = $wpdb->get_row( "SELECT COUNT(*) n, SUM(LENGTH(option_value)) bytes, SUM(autoload IN ('yes','on','auto-on','auto')) auto
	FROM {$wpdb->options} WHERE option_name LIKE 'breakwater%'" );
$out[] = "options={$opt->n} options_bytes={$opt->bytes} options_autoload={$opt->auto}";
$out[] = 'used_tokens=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_breakwater\\_used\\_%'" );
$rep   = get_option( 'breakwater_reputation' );
if ( is_array( $rep ) ) {
	$out[] = 'rep_generated=' . str_replace( ' ', 'T', (string) ( $rep['generated'] ?? '' ) );
	foreach ( [ 'ip', 'email', 'message', 'mband' ] as $k ) {
		$out[] = "rep_$k=" . count( (array) ( $rep[ $k ] ?? [] ) );
	}
	$out[] = 'rep_bytes=' . strlen( maybe_serialize( $rep ) );
} else {
	$out[] = 'rep=none';
}
$out[] = 'trusted=' . count( (array) get_option( 'breakwater_trusted', [] ) );
$out[] = 'object_cache=' . ( wp_using_ext_object_cache() ? 1 : 0 );

// Form entries the plugins saved in the same window, to see what Breakwater never judged.
$gf = $wpdb->prefix . 'gf_entry';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gf ) ) === $gf ) {
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM $gf WHERE date_created > %s GROUP BY status", $since ) ) as $r ) {
		$out[] = "gf.{$r->status}={$r->n}";
	}
	// The same span before the install, for a before/after.
	$span   = time() - strtotime( $since . ' UTC' );
	$before = gmdate( 'Y-m-d H:i:s', strtotime( $since . ' UTC' ) - $span );
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM $gf WHERE date_created > %s AND date_created <= %s GROUP BY status", $before, $since ) ) as $r ) {
		$out[] = "gf_before.{$r->status}={$r->n}";
	}
	// GF entries with no log row near them (same form, within 2 minutes).
	$out[] = 'gf_unlogged=' . (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $gf e WHERE e.date_created > %s AND NOT EXISTS (
			SELECT 1 FROM $t l WHERE l.source = 'gravityforms' AND l.created_at BETWEEN e.date_created - INTERVAL 2 MINUTE AND e.date_created + INTERVAL 2 MINUTE)", $since
	) );
}
$es = $wpdb->prefix . 'e_submissions';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $es ) ) === $es ) {
	$out[] = 'elementor_subs=' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $es WHERE created_at_gmt > %s", $since ) );
	$out[] = 'elementor_unlogged=' . (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $es e WHERE e.created_at_gmt > %s AND NOT EXISTS (
			SELECT 1 FROM $t l WHERE l.source LIKE 'elementor%%' AND l.created_at BETWEEN e.created_at_gmt - INTERVAL 2 MINUTE AND e.created_at_gmt + INTERVAL 2 MINUTE)", $since
	) );
}
$out[] = 'comments=' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_date_gmt > %s AND comment_type IN ('','comment')", $since ) );
$out[] = 'users_new=' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered > %s", $since ) );
$out[] = 'adapters=' . implode( ',', array_map( static fn( $a ) => $a->source(), 
	Breakwater\Adapters\Registry::detected() ) );
$other = [];
foreach ( [ 'cleantalk-spam-protect/cleantalk.php', 'akismet/akismet.php', 'wordfence/wordfence.php', 'wp-armour/wp-armour.php', 'simple-cloudflare-turnstile/simple-cloudflare-turnstile.php' ] as $p ) {
	if ( is_plugin_active( $p ) ) {
		$other[] = dirname( $p );
	}
}
$out[] = 'other=' . ( $other ? implode( ',', $other ) : '-' );
echo '<bw>BWS|' . $home . '|' . implode( ' ', $out ) . ' ' . getenv( 'BW_LOGS' ) . "</bw>\n";

$clean = static fn( $v ) => str_replace( [ '|', "\n", "\r", '<', '>' ], [ '/', ' ', ' ', '', '' ], (string) $v );
foreach ( $wpdb->get_results( $wpdb->prepare(
	"SELECT id, created_at, source, form, verdict, action, score, signals, payload, other_verdict, feedback, outcome, excerpt FROM $t
	WHERE created_at > %s AND (%d = 1 OR verdict = 'spam' OR score >= 2 OR feedback <> '') ORDER BY id", $since, (int) getenv( 'BW_ALL' )
) ) as $r ) {
	$signals = json_decode( (string) $r->signals, true );
	$names   = [];
	foreach ( is_array( $signals ) ? $signals : [] as $k => $v ) {
		$names[] = $k . ':' . ( is_array( $v ) ? ( $v['points'] ?? $v[0] ?? '?' ) : $v );
	}
	$meta = json_decode( (string) $r->payload, true )['meta'] ?? [];
	printf( "<bw>BWR|%s|%d|%s|%s|%s|%s/%s|%d|%s|%s|%s|%s|%s|%s</bw>\n", $home, $r->id, $r->created_at, $r->source,
		$clean( mb_substr( (string) $r->form, 0, 50 ) ), $r->verdict, $r->action, $r->score, implode( ',', $names ),
		$meta['t'] ?? '-', $meta['p'] ?? '-', $r->other_verdict, $clean( $r->feedback . ( $r->outcome ? ' ' . $r->outcome : '' ) ),
		$clean( mb_substr( preg_replace( '/\s+/', ' ', (string) $r->excerpt ), 0, 200 ) ) );
}
PHPEOF

printf '%s\n' "$probe" | BW_DAYS="$days" BW_ALL="$every" BW_LOGS="$logs" timeout 120 wp eval-file - --skip-themes 2>/dev/null \
  | sed -n 's/.*<bw>\(.*\)<\/bw>.*/\1/p'
