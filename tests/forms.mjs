// Generic end-to-end check for any page with one form on it: a person, a spammer in a real
// browser, and a headless bot fill whatever fields the form has (by type), submit, and the
// Breakwater log says what happened.
//
// Usage: WP_PATH=/path/to/site/public node tests/forms.mjs https://site.localhost /page-a/ /page-b/
import { createRequire } from 'module';
import { execSync } from 'child_process';
import { appendFileSync } from 'fs';
const require = createRequire( import.meta.url );
const { chromium } = require( process.env.PLAYWRIGHT || 'playwright' );

const [ base, ...paths ] = process.argv.slice( 2 );
const wpPath = process.env.WP_PATH;
if ( ! base || ! paths.length || ! wpPath ) {
	console.error( 'Usage: WP_PATH=/site/public node tests/forms.mjs https://site.localhost /page/ [/page/ ...]' );
	process.exit( 1 );
}
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const stamp = Date.now().toString( 36 );

const people = {
	human: {
		first: 'Jane', last: 'Smith', email: 'jane.smith@gmail.com', url: '', subject: 'Deck quote',
		message: `Hi there, I'd like a quote for a 12x16 deck with composite boards. We're in Lititz, available weekdays after 3. (${ stamp })`,
	},
	spammer: {
		first: 'SEO', last: 'Expert', email: 'seo.expert@mailinator.com', url: 'https://cheap-seo.xyz', subject: 'SEO services',
		message: `Hello, your website is not on the first page of Google. Our SEO services and link building will rank your website. https://a.xyz https://b.xyz https://c.xyz ${ stamp }`,
	},
};

function lastLog() {
	const out = execSync( `wp --path=${ wpPath } breakwater log --limit=1 --format=json 2>/dev/null` ).toString();
	return JSON.parse( out || '[]' )[ 0 ] || {};
}

// The form on the page: the first one holding a visible text box, email box or textarea.
async function findForm( page ) {
	const candidates = page.locator( 'form' ).filter( { has: page.locator( 'textarea:visible, input[type="email"]:visible, input[type="text"]:visible' ) } );
	if ( await candidates.count() ) {
		return candidates.first();
	}
	return page.locator( 'body' );
}

async function fill( form, person, tag, fast ) {
	const unique = ( value ) => value.replace( '@', `+${ tag }${ stamp }@` );
	const type = async ( el, value ) => {
		if ( fast ) {
			return el.fill( value, { timeout: 3000 } );
		}
		try {
			await el.click( { timeout: 2000 } );
			await el.pressSequentially( value, { delay: 6, timeout: 10000 } );
		} catch {
			await el.fill( value, { timeout: 3000 } ); // Covered by a label or overlay: fill it directly.
		}
	};
	for ( const el of await form.locator( 'input:visible, textarea:visible, select:visible' ).all() ) {
		try {
			await fillOne( el, type, person, unique, tag );
		} catch ( error ) {
			console.error( 'could not fill a field:', error.message.split( '\n' )[ 0 ] );
		}
	}
}

// Honeypots are "visible" to Playwright but not to people: transparent, tiny, off-screen,
// hidden from assistive tech, or out of the tab order. A person never fills those.
function humanCanSee( el ) {
	return el.evaluate( ( node ) => {
		const box = node.getBoundingClientRect();
		const style = getComputedStyle( node );
		if ( box.width < 4 || box.height < 4 || box.right < 0 || box.bottom < 0 || Number( style.opacity ) < 0.1 || node.tabIndex < 0 ) {
			return false;
		}
		for ( let up = node; up; up = up.parentElement ) {
			const s = getComputedStyle( up );
			if ( up.getAttribute( 'aria-hidden' ) === 'true' || Number( s.opacity ) < 0.1 || ( s.overflow === 'hidden' && up.getBoundingClientRect().height < 4 ) ) {
				return false;
			}
		}
		return true;
	} );
}

async function fillOne( el, type, person, unique, tag ) {
	if ( ! ( await humanCanSee( el ) ) ) {
		return;
	}
	const tagName = await el.evaluate( ( node ) => node.tagName.toLowerCase() );
	const kind = ( ( await el.getAttribute( 'type' ) ) || 'text' ).toLowerCase();
	const name = `${ await el.getAttribute( 'name' ) || '' } ${ await el.getAttribute( 'id' ) || '' } ${ await el.getAttribute( 'placeholder' ) || '' }`.toLowerCase();
	if ( name.includes( 'bw_' ) || [ 'hidden', 'submit', 'button', 'file', 'image', 'reset' ].includes( kind ) ) {
		return;
	}
	if ( tagName === 'select' ) {
		if ( ( await el.locator( 'option' ).count() ) > 1 ) {
			await el.selectOption( { index: 1 }, { timeout: 2000 } );
		}
	} else if ( tagName === 'textarea' ) {
		await type( el, person.message );
	} else if ( kind === 'checkbox' || kind === 'radio' ) {
		await el.check( { timeout: 2000, force: true } ).catch( () => {} );
	} else if ( kind === 'email' || /email/.test( name ) ) {
		await type( el, unique( person.email ) );
	} else if ( kind === 'password' ) {
		await type( el, 'Str0ng!Passw0rd#2026' );
	} else if ( kind === 'tel' || /phone/.test( name ) ) {
		await type( el, '7175550142' );
	} else if ( kind === 'url' || /website|url/.test( name ) ) {
		if ( person.url ) {
			await type( el, person.url );
		}
	} else if ( kind === 'number' ) {
		await type( el, '3' );
	} else if ( kind === 'date' ) {
		await el.fill( '2026-11-02' );
	} else if ( /user_login|username|login/.test( name ) ) {
		await type( el, `${ tag }${ stamp }` );
	} else if ( /last|lname/.test( name ) ) {
		await type( el, person.last );
	} else if ( /name|first|fname/.test( name ) ) {
		await type( el, /first|fname/.test( name ) ? person.first : `${ person.first } ${ person.last }` );
	} else if ( kind === 'text' ) {
		await type( el, person.subject );
	}
}

async function run( browser, who, path, options = {} ) {
	const label = options.label || who;
	const tag = label.replace( /[^a-z]/g, '' );
	const context = await browser.newContext( options.context || { userAgent: UA, ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	const before = Number( lastLog().id || 0 );
	await page.goto( base + path, { waitUntil: 'load' } );
	await page.waitForTimeout( options.fast ? 300 : 2500 );
	const form = await findForm( page );
	await fill( form, people[ who ], tag, options.fast );
	await page.waitForTimeout( options.fast ? 0 : 800 );
	const textBefore = await page.locator( 'body' ).innerText();
	const submit = form.locator( 'button[type="submit"]:visible, input[type="submit"]:visible, button:not([type]):visible, .kb-forms-submit:visible' ).last();
	await Promise.all( [
		page.waitForLoadState( 'load' ).catch( () => {} ),
		submit.click( { timeout: 5000, force: true } ).catch( ( error ) => console.error( path, 'no submit button', error.message.split( '\n' )[ 0 ] ) ),
	] );
	await page.waitForTimeout( 5000 );
	const textAfter = await page.locator( 'body' ).innerText().catch( () => '' );
	// What appeared after submitting: the confirmation or the error.
	const shown = textAfter.split( '\n' ).map( ( line ) => line.trim() ).filter( ( line ) => line && ! textBefore.includes( line ) ).slice( 0, 2 ).join( ' / ' );
	const log = lastLog();
	await context.close();
	const fresh = Number( log.id ) > before;
	return {
		who: label,
		page: path,
		id: fresh ? Number( log.id ) : null,
		verdict: fresh ? log.verdict : '',
		source: fresh ? log.source : '',
		logged: fresh ? `${ log.source } ${ log.verdict }/${ log.action } ${ log.score } [${ log.signals }]` : 'NO NEW LOG ROW',
		shown: shown.slice( 0, 70 ),
	};
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
for ( const path of paths ) {
	results.push( await attempt( () => run( browser, 'human', path ), { who: 'human', page: path } ) );
	results.push( await attempt( () => run( browser, 'spammer', path ), { who: 'spammer', page: path } ) );
}
await browser.close();
// A bot driving stock headless Chromium, filling instantly with an innocent message.
const headless = await chromium.launch();
for ( const path of paths ) {
	results.push( await attempt( () => run( headless, 'human', path, { fast: true, label: 'headless-bot', context: { ignoreHTTPSErrors: true } } ), { who: 'headless-bot', page: path } ) );
}
await headless.close();
console.table( results.map( ( { id, verdict, source, ...row } ) => row ) );

// tests/release-check.mjs reads every suite's rows from the file named by RESULTS.
if ( process.env.RESULTS ) {
	appendFileSync( process.env.RESULTS, results.map( ( row ) => JSON.stringify( { suite: 'forms', site: base, ...row } ) ).join( '\n' ) + '\n' );
}
