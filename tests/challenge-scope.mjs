// A proof of work is a request to the site, so the page script should ask for one only when a
// visitor starts on a form Breakwater checks: never for a search box. Focuses the search box,
// counts challenge requests, then focuses the page's form and counts again.
//
// Usage: node tests/challenge-scope.mjs https://protect-avada.localhost/avada/ [more pages]
import { createRequire } from 'module';
const require = createRequire( import.meta.url );
const { chromium } = require( process.env.PLAYWRIGHT || 'playwright' );

const pages = process.argv.slice( 2 );
if ( ! pages.length ) {
	console.error( 'Usage: node tests/challenge-scope.mjs <page url> [...]' );
	process.exit( 1 );
}
const browser = await chromium.launch();
let failed = 0;
for ( const url of pages ) {
	const context = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	let challenges = 0;
	page.on( 'request', ( request ) => {
		if ( request.url().includes( 'breakwater/v1/challenge' ) ) {
			challenges++;
		}
	} );
	await page.goto( url, { waitUntil: 'networkidle' } );
	const search = page.locator( 'input[type="search"]:visible, input[name="s"]:visible' ).first();
	let afterSearch = null;
	if ( await search.count() ) {
		await search.focus(); // A header can sit over the box; focus is what the script listens for.
		await page.keyboard.type( 'deck', { delay: 40 } );
		await page.waitForTimeout( 1500 );
		afterSearch = challenges;
	}
	const field = page.locator( 'form:not([role="search"]) textarea:visible, form:not([role="search"]) input[type="email"]:visible, form:not([role="search"]) input[type="text"]:not([name="s"]):visible' ).first();
	let afterForm = null;
	if ( await field.count() ) {
		await field.focus();
		await page.waitForTimeout( 2500 );
		afterForm = challenges;
	}
	const ok = ( afterSearch === null || afterSearch === 0 ) && ( afterForm === null || afterForm >= 1 );
	failed += ok ? 0 : 1;
	console.log( `${ ok ? 'ok  ' : 'FAIL' } ${ url }  after search box: ${ afterSearch ?? 'no search box' }  after form field: ${ afterForm ?? 'no form' }` );
	await context.close();
}
await browser.close();
process.exit( failed ? 1 : 0 );
