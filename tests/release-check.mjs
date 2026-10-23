// Before a release: bring every sandbox's plugins and themes up to date, run every suite against
// every integration, then hand each held spammer back with "Not spam" and check it goes through.
// Prints one table and exits non-zero when anything is wrong.
//
// Usage: PLAYWRIGHT=/path/to/node_modules/playwright node tests/release-check.mjs [--update] [--mu] [--only=protect,forms,...]
//   --update  update plugins, themes and core on each sandbox first (Breakwater itself is left alone: it is a symlink)
//   --mu      run protect.localhost's suites against the single-file mu-plugin build instead of the plugin
//
// Suites run one site at a time; the personas share an IP, so `spam_ip` on a person is expected.
import { execFileSync } from 'child_process';
import { existsSync, lstatSync, mkdtempSync, readFileSync } from 'fs';
import { tmpdir } from 'os';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = dirname( here );
const sites = `${ process.env.HOME }/Cove/Sites`;
const args = process.argv.slice( 2 );
const update = args.includes( '--update' );
const mu = args.includes( '--mu' );
const only = ( args.find( ( a ) => a.startsWith( '--only=' ) ) || '' ).slice( 7 ).split( ',' ).filter( Boolean );

const SITES = [
	{ key: 'protect', host: 'protect.localhost', suites: [ [ 'browser.mjs' ], [ 'multipage.mjs' ], [ 'nojs.py' ] ] },
	{ key: 'forms', host: 'protect-forms.localhost', suites: [
		[ 'forms.mjs', '/jetpack/', '/ninja/', '/fluent/', '/mc4wp/', '/formidable/', '/forminator/', '/mailpoet/', '/ctct/', '/kadence/', '/um-register/' ],
		[ 'kadence.mjs' ],
	] },
	{ key: 'divi', host: 'protect-divi.localhost', suites: [ [ 'forms.mjs', '/divi/' ] ] },
	{ key: 'avada', host: 'protect-avada.localhost', suites: [ [ 'forms.mjs', '/avada/' ] ] },
	{ key: 'bb', host: 'protect-bb.localhost', suites: [ [ 'forms.mjs', '/bb-contact/', '/bb-subscribe/' ] ] },
	{ key: 'breakdance', host: 'protect-breakdance.localhost', suites: [ [ 'forms.mjs', '/breakdance-form/' ] ] },
];

// Who should end up where. A bot the form plugin refuses itself (Constant Contact's own timer,
// WPForms on a direct POST) leaves no row; that still counts, as a note.
const EXPECT = { human: 'ham', resumed: 'ham', spammer: 'spam', 'headless-bot': 'spam', 'draft-bot': 'spam', 'no-js-bot': 'spam' };

// Sign-up forms take only a name and an email, so the spammer persona sends nothing to judge but a
// throwaway address (3 points); it is held only once other suites have given the IP a history.
const SIGNUPS = [ 'registration', 'woocommerce', '/mc4wp/', '/mailpoet/', '/ctct/', '/um-register/', '/bb-subscribe/' ];

function wp( site, ...cmd ) {
	return execFileSync( 'wp', [ `--path=${ sites }/${ site.host }/public`, ...cmd ], { stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 600000 } ).toString().trim();
}

function tryWp( site, ...cmd ) {
	try {
		return { ok: true, out: wp( site, ...cmd ) };
	} catch ( error ) {
		return { ok: false, out: ( error.stderr || error.stdout || error.message ).toString().trim().split( '\n' ).pop() };
	}
}

const problems = [];
const notes = [];
const rows = [];
const versions = [];

for ( const site of SITES.filter( ( s ) => ! only.length || only.includes( s.key ) ) ) {
	const path = `${ sites }/${ site.host }/public`;
	if ( ! existsSync( path ) ) {
		problems.push( `${ site.host }: no sandbox at ${ path }` );
		continue;
	}
	const link = `${ path }/wp-content/plugins/breakwater`;
	if ( ! existsSync( link ) || ! lstatSync( link ).isSymbolicLink() ) {
		problems.push( `${ site.host }: wp-content/plugins/breakwater is not the symlink to the repo` );
		continue;
	}

	if ( update ) {
		console.error( `Updating ${ site.host }` );
		for ( const cmd of [ [ 'core', 'update' ], [ 'core', 'update-db' ], [ 'plugin', 'update', '--all', '--exclude=breakwater' ], [ 'theme', 'update', '--all' ] ] ) {
			const result = tryWp( site, ...cmd );
			if ( ! result.ok ) {
				notes.push( `${ site.host }: wp ${ cmd.join( ' ' ) }: ${ result.out }` );
			}
		}
	}
	const core = tryWp( site, 'core', 'version' ).out;
	for ( const kind of [ 'plugin', 'theme' ] ) {
		const listed = tryWp( site, kind, 'list', '--fields=name,status,version,update', '--format=json' );
		const list = listed.ok ? JSON.parse( listed.out || '[]' ) : [];
		for ( const item of list.filter( ( i ) => i.name !== 'breakwater' && [ 'active', 'active-network', 'must-use', 'parent' ].includes( i.status ) ) ) {
			versions.push( { site: site.key, [ kind ]: item.name, version: item.version } );
			if ( item.update === 'available' ) {
				notes.push( `${ site.host }: ${ item.name } ${ item.version } has an update it could not take (licence?)` );
			}
		}
	}
	versions.push( { site: site.key, core } );

	const useMu = mu && site.key === 'protect';
	if ( useMu ) {
		execFileSync( 'php', [ 'bin/build-mu-plugin.php' ], { cwd: root } );
		execFileSync( 'php', [ 'bin/mu-deploy-script.php', '--mode=protect' ], { cwd: root } );
		execFileSync( 'php', [ 'bin/mu-deploy-script.php', '--remove' ], { cwd: root } );
		wp( site, 'plugin', 'deactivate', 'breakwater', '--quiet' );
		execFileSync( 'bash', [ `${ root }/dist/deploy-mu.sh` ], { cwd: path } );
	}

	try {
		const results = join( mkdtempSync( join( tmpdir(), 'bw-release-' ) ), 'results.jsonl' );
		for ( const [ suite, ...pages ] of site.suites ) {
			const env = { ...process.env, WP_PATH: path, RESULTS: results };
			if ( suite === 'kadence.mjs' ) {
				env.FORM_ID = wp( site, 'post', 'list', '--post_type=kadence_form', '--field=ID', '--posts_per_page=1' );
			}
			console.error( `Running ${ suite } on ${ site.host }` );
			const cmd = suite.endsWith( '.py' ) ? [ 'python3', [ '-I', join( here, suite ), `https://${ site.host }` ] ] : [ 'node', [ join( here, suite ), `https://${ site.host }`, ...pages ] ];
			try {
				execFileSync( cmd[ 0 ], cmd[ 1 ], { env, stdio: [ 'ignore', 'ignore', 'inherit' ], timeout: 900000 } );
			} catch ( error ) {
				problems.push( `${ site.host }: ${ suite } crashed (${ error.message.split( '\n' )[ 0 ] })` );
			}
		}
		const found = existsSync( results ) ? readFileSync( results, 'utf8' ).trim().split( '\n' ).filter( Boolean ).map( ( l ) => JSON.parse( l ) ) : [];
		for ( const row of found ) {
			const want = EXPECT[ row.who ];
			const where = row.form || row.page || ( row.ajax === undefined ? row.suite : `gf multi-page ${ row.ajax ? 'ajax' : 'post' }` );
			let result = row.verdict === want ? 'ok' : 'FAIL';
			if ( row.error ) {
				result = 'FAIL';
			} else if ( ! row.id && want === 'spam' ) {
				result = 'ok (refused by the form itself)';
			} else if ( result === 'FAIL' && row.who === 'spammer' && SIGNUPS.includes( where ) ) {
				result = 'ok (only an email to judge)';
			}
			if ( result === 'FAIL' ) {
				problems.push( `${ site.host } ${ where }: ${ row.who } ${ row.error ? `failed: ${ row.error }` : `should be ${ want }, got ${ row.verdict || 'no log row' }` }` );
			}
			// "Not spam" on what a spammer sent: the form plugin must take it back.
			let released = '';
			if ( row.who === 'spammer' && row.id && row.verdict === 'spam' ) {
				const r = tryWp( site, 'breakwater', 'release', String( row.id ) );
				released = r.out.replace( /^Success:\s*/, '' ).slice( 0, 90 );
				if ( ! r.ok ) {
					problems.push( `${ site.host } ${ where }: release of row ${ row.id } failed: ${ r.out }` );
				}
			}
			rows.push( { site: site.key, form: where, who: row.who, verdict: row.verdict || '-', result, released } );
		}
		if ( ! found.length ) {
			problems.push( `${ site.host }: no results came back` );
		}
	} finally {
		if ( useMu ) {
			execFileSync( 'bash', [ `${ root }/dist/remove-mu.sh` ], { cwd: path } );
			wp( site, 'plugin', 'activate', 'breakwater', '--quiet' );
		}
	}
}

console.log( '\nVersions' );
console.table( versions );
console.log( '\nResults' );
console.table( rows );
for ( const note of notes ) {
	console.log( `note: ${ note }` );
}
for ( const problem of problems ) {
	console.log( `PROBLEM: ${ problem }` );
}
console.log( problems.length ? `\n${ problems.length } problem(s).` : `\nAll ${ rows.length } checks passed.` );
process.exit( problems.length ? 1 : 0 );
