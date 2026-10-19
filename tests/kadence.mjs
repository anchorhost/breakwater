// Kadence Advanced Form through its real AJAX handler. The browser earns the proof cookie by
// typing on a page first (as a visitor would), then posts the form in the same session.
// Usage: WP_PATH=/site/public FORM_ID=44 node tests/kadence.mjs https://site.localhost
import { createRequire } from 'module';
import { execSync } from 'child_process';
const require = createRequire( import.meta.url );
const { chromium } = require( process.env.PLAYWRIGHT || 'playwright' );

const base = process.argv[ 2 ];
const formId = process.env.FORM_ID;
const lastLog = () => JSON.parse( execSync( `wp --path=${ process.env.WP_PATH } breakwater log --limit=1 --format=json 2>/dev/null` ).toString() || '[]' )[ 0 ] || {};
const messages = {
	human: [ 'Jane Smith', 'jane.smith@gmail.com', 'Hi, could you send me a quote for a 12x16 deck? We are in Lititz.' ],
	spammer: [ 'SEO Expert', 'seo.expert@mailinator.com', 'Our SEO services and link building will rank your website on the first page of Google. https://a.xyz https://b.xyz https://c.xyz' ],
};

async function run( browser, who, label, fast ) {
	const context = await browser.newContext( { ignoreHTTPSErrors: true, ...( fast ? {} : { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' } ) } );
	const page = await context.newPage();
	const before = Number( lastLog().id || 0 );
	await page.goto( base + '/ctct/', { waitUntil: 'load' } );
	const input = page.locator( 'input[type="email"]:visible' ).first();
	if ( fast ) {
		await input.fill( 'x@example.com' );
	} else {
		await page.waitForTimeout( 2000 );
		await input.click();
		await input.pressSequentially( 'typing like a person', { delay: 20 } );
		await page.waitForTimeout( 2500 );
	}
	const [ name, email, message ] = messages[ who ];
	const response = await page.request.post( base + '/wp-admin/admin-ajax.php', { form: {
		action: 'kb_process_advanced_form_submit', _kb_adv_form_id: 'ahp-kform', _kb_adv_form_post_id: formId,
		'fieldahp-k1': name, 'fieldahp-k2': email, 'fieldahp-k3': message,
	} } );
	const body = ( await response.text() ).replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).slice( 0, 90 );
	const log = lastLog();
	await context.close();
	return { who: label, logged: Number( log.id ) > before ? `${ log.source } ${ log.verdict }/${ log.action } ${ log.score } [${ log.signals }]` : 'NO NEW LOG ROW', response: body };
}

const results = [];
const browser = await chromium.launch( { args: [ '--disable-blink-features=AutomationControlled' ] } );
results.push( await run( browser, 'human', 'human' ) );
results.push( await run( browser, 'spammer', 'spammer' ) );
await browser.close();
const headless = await chromium.launch();
results.push( await run( headless, 'human', 'headless-bot', true ) );
await headless.close();
console.table( results );
