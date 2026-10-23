// Multi-page Gravity Forms: a person who takes their time on the first pages and then ticks the
// last page's consent box and sends at once, the same person resuming from Save and Continue in a
// new browser, a spammer, and a headless bot. Form and pages from tests/setup/gf-multipage.php.
// Usage: node tests/multipage.mjs https://protect.localhost
import { createRequire } from 'module';
import { execSync, execFileSync } from 'child_process';
import { appendFileSync } from 'fs';
const require = createRequire( import.meta.url );
const { chromium } = require( process.env.PLAYWRIGHT || 'playwright' );

const base = process.argv[ 2 ] || 'https://protect.localhost';
const wpPath = process.env.WP_PATH || `${ process.env.HOME }/Cove/Sites/protect.localhost/public`;
const form = Number( process.env.FORM || 2 );
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const stamp = Date.now().toString( 36 );

const people = {
	human: { name: 'Jane Smith', email: `jane+${ stamp }@gmail.com`, message: `Hi there, I'd like a quote for a 12x16 deck with composite boards. We're in Lititz, available weekdays after 3. (${ stamp })` },
	spammer: { name: 'SEO Expert', email: `seo${ stamp }@mailinator.com`, message: `Hello, your website is not on the first page of Google. Our SEO services and link building will rank your website. https://a.xyz https://b.xyz https://c.xyz ${ stamp }` },
};

function wp( php ) {
	return execFileSync( 'wp', [ `--path=${ wpPath }`, 'eval', 'global $wpdb; ' + php ], { stdio: [ 'ignore', 'pipe', 'ignore' ] } ).toString().trim();
}

function draftCount() {
	return Number( wp( 'echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gf_draft_submissions" );' ) );
}

function lastLog() {
	const out = execSync( `wp --path=${ wpPath } breakwater log --limit=1 --format=json 2>/dev/null` ).toString();
	return JSON.parse( out )[ 0 ] || {};
}

async function type( page, selector, text, fast ) {
	const input = page.locator( selector ).first();
	await input.click();
	await ( fast ? input.fill( text ) : input.pressSequentially( text, { delay: 8 } ) );
}

// Next/Submit: a full page load without AJAX, a re-rendered form with it.
async function next( page, ajax, button, waitFor ) {
	if ( ajax ) {
		await page.click( button );
	} else {
		await Promise.all( [ page.waitForNavigation( { timeout: 15000 } ).catch( () => {} ), page.click( button ) ] );
	}
	await page.waitForSelector( waitFor, { timeout: 15000 } ).catch( () => {} );
}

async function finish( page, ajax ) {
	await page.waitForSelector( `#input_${ form }_4_1`, { state: 'visible', timeout: 15000 } );
	await page.click( `#input_${ form }_4_1` );
	await next( page, ajax, `#gform_submit_button_${ form }`, '.gform_confirmation_message, .gform_validation_errors' );
}

async function run( browser, who, ajax, options = {} ) {
	const p = people[ who ];
	const email = p.email.replace( '@', `.${ ( options.label || who ).replace( /[^a-z]/g, '' ) }${ ajax ? 'ajax' : 'post' }@` );
	const fast = !! options.fast;
	const context = await browser.newContext( options.context || { userAgent: UA, ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	const before = lastLog().id || 0;
	await page.goto( `${ base }/gf-multi-${ ajax ? 'ajax' : 'post' }/`, { waitUntil: 'load' } );
	await page.waitForTimeout( fast ? 200 : 2000 );
	await type( page, `#input_${ form }_1`, p.name, fast );
	await type( page, `#input_${ form }_2`, email, fast );
	await page.waitForTimeout( fast ? 0 : 600 );
	await next( page, ajax, `#gform_next_button_${ form }_10`, `#input_${ form }_3` );
	await page.waitForTimeout( fast ? 0 : 1500 );
	await type( page, `#input_${ form }_3`, p.message, fast );
	await page.waitForTimeout( fast ? 0 : 600 );

	let resumed = null;
	if ( options.resume ) {
		// Save, then come back from the resume link in a fresh browser and finish quickly.
		const drafts = draftCount();
		const saving = lastLog().id || 0;
		await next( page, ajax, `.gform_page:visible .gform_save_link`, '.form_saved_message, .gform_confirmation_message' );
		await context.close();
		if ( draftCount() === drafts ) {
			const log = lastLog();
			const refused = Number( log.id ) > Number( saving );
			return { who: options.label || who, ajax, id: refused ? Number( log.id ) : null, verdict: refused ? log.verdict : '', draft: true, logged: 'draft refused: ' + ( Number( log.id ) > Number( saving ) ? `${ log.verdict }/${ log.action } ${ log.score } [${ log.signals }]` : 'no log row' ), shown: '' };
		}
		// The newest draft's token, so the test needs no saved-form confirmation set up.
		const uuid = wp( 'echo $wpdb->get_var( "SELECT uuid FROM {$wpdb->prefix}gf_draft_submissions ORDER BY date_created DESC LIMIT 1" );' );
		const link = `${ base }/gf-multi-${ ajax ? 'ajax' : 'post' }/?gf_token=${ uuid }`;
		resumed = await browser.newContext( { userAgent: UA, ignoreHTTPSErrors: true } );
		const again = await resumed.newPage();
		await again.goto( link, { waitUntil: 'load' } );
		await again.click( `#gform_next_button_${ form }_11` ).catch( () => {} );
		if ( ! ajax ) {
			await again.waitForLoadState( 'load' );
		}
		await finish( again, ajax );
		return report( again, resumed, before, options.label || who, ajax );
	}

	await next( page, ajax, `#gform_next_button_${ form }_11`, `#input_${ form }_4_1` );
	// The last page holds only a consent box: tick it and send straight away.
	await finish( page, ajax );
	return report( page, context, before, options.label || who, ajax );
}

async function report( page, context, before, who, ajax ) {
	await page.waitForTimeout( 500 );
	const shown = ( await page.locator( 'body' ).innerText() ).match( /Thanks[^.!]*|There was a problem[^.]*/i );
	const log = lastLog();
	await context.close();
	const fresh = Number( log.id ) > Number( before );
	return { who, ajax, id: fresh ? Number( log.id ) : null, verdict: fresh ? log.verdict : '', logged: fresh ? `${ log.verdict }/${ log.action } ${ log.score } [${ log.signals }]` : 'NO NEW LOG ROW', shown: shown ? shown[ 0 ].trim().slice( 0, 60 ) : '' };
}

// One retry (Cove can restart PHP mid-run), then the error is recorded instead of ending the suite.
async function attempt( fn, row ) {
	for ( let i = 0; ; i++ ) {
		try {
			return await fn();
		} catch ( error ) {
			if ( i ) {
				return { ...row, id: null, verdict: '', error: error.message.split( '\n' )[ 0 ] };
			}
		}
	}
}

const results = [];
const browser = await chromium.launch( { args: [ '--disable-blink-features=AutomationControlled' ] } );
for ( const ajax of [ true, false ] ) {
	results.push( await attempt( () => run( browser, 'human', ajax ), { who: 'human', ajax } ) );
	results.push( await attempt( () => run( browser, 'human', ajax, { resume: true, label: 'resumed' } ), { who: 'resumed', ajax } ) );
	results.push( await attempt( () => run( browser, 'spammer', ajax ), { who: 'spammer', ajax } ) );
}
await browser.close();
const headless = await chromium.launch();
for ( const ajax of [ true, false ] ) {
	results.push( await attempt( () => run( headless, 'human', ajax, { fast: true, label: 'headless-bot', context: { ignoreHTTPSErrors: true } } ), { who: 'headless-bot', ajax } ) );
	results.push( await attempt( () => run( headless, 'human', ajax, { fast: true, resume: true, label: 'draft-bot', context: { ignoreHTTPSErrors: true } } ), { who: 'draft-bot', ajax } ) );
}
await headless.close();
console.table( results.map( ( { id, verdict, draft, ...row } ) => row ) );

// tests/release-check.mjs reads every suite's rows from the file named by RESULTS.
if ( process.env.RESULTS ) {
	appendFileSync( process.env.RESULTS, results.map( ( row ) => JSON.stringify( { suite: 'multipage', site: base, ...row } ) ).join( '\n' ) + '\n' );
}
