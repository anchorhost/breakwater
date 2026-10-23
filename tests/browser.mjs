// End-to-end checks against a local site: a person, a spammer in a real browser, and a headless bot.
// Usage: node tests/browser.mjs https://protect.localhost
import { createRequire } from 'module';
import { execSync } from 'child_process';
const require = createRequire( import.meta.url );
const { chromium } = require( process.env.PLAYWRIGHT || 'playwright' );

const base = process.argv[ 2 ] || 'https://protect.localhost';
const wpPath = process.env.WP_PATH || `${ process.env.HOME }/Cove/Sites/protect.localhost/public`;
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const stamp = Date.now().toString( 36 );

const people = {
	human: { name: 'Jane Smith', email: `jane+${ stamp }@gmail.com`, message: `Hi there, I'd like a quote for a 12x16 deck with composite boards. We're in Lititz, available weekdays after 3. (${ stamp })` },
	spammer: { name: 'SEO Expert', email: `seo${ stamp }@mailinator.com`, message: `Hello, your website is not on the first page of Google. Our SEO services and link building will rank your website. https://a.xyz https://b.xyz https://c.xyz ${ stamp }` },
};

const forms = {
	'gf-ajax': { url: '/gf-ajax/', fill: { '#input_1_1': 'name', '#input_1_2': 'email', '#input_1_3': 'message' }, submit: '#gform_submit_button_1', done: '.gform_confirmation_message, .gform_validation_errors' },
	'gf-post': { url: '/gf-post/', fill: { '#input_1_1': 'name', '#input_1_2': 'email', '#input_1_3': 'message' }, submit: '#gform_submit_button_1', done: '.gform_confirmation_message, .gform_validation_errors', nav: true },
	cf7: { url: '/cf7/', fill: { 'input[name="your-name"]': 'name', 'input[name="your-email"]': 'email', 'input[name="your-subject"]': 'subject', 'textarea[name="your-message"]': 'message' }, submit: '.wpcf7-submit', done: 'form.sent, form.spam, form.invalid, form.failed' },
	wpforms: { url: '/wpforms/', fill: { '#wpforms-18-field_1': 'name', '#wpforms-18-field_2': 'email', '#wpforms-18-field_3': 'message' }, submit: '#wpforms-submit-18', done: '.wpforms-confirmation-container-full, .wpforms-error-container, .wpforms-error-alert' },
	elementor: { url: '/elementor/', fill: { '#form-field-name': 'name', '#form-field-email': 'email', '#form-field-message': 'message' }, submit: '.elementor-form button[type="submit"]', done: '.elementor-message' },
	comment: { url: '/comment-test/', fill: { '#author': 'name', '#email': 'email', '#comment': 'message' }, submit: '#submit', nav: true },
	registration: { url: '/wp-login.php?action=register', fill: { '#user_login': 'login', '#user_email': 'email' }, submit: '#wp-submit', nav: true },
	woocommerce: { url: '/my-account/', fill: { '#reg_email': 'email' }, submit: 'button[name="register"]', nav: true },
};

function lastLog() {
	const out = execSync( `wp --path=${ wpPath } breakwater log --limit=1 --format=json 2>/dev/null` ).toString();
	return JSON.parse( out )[ 0 ] || {};
}

async function run( browser, who, key, options = {} ) {
	const form = forms[ key ];
	const tag = ( options.label || who ).replace( /[^a-z]/g, '' );
	const person = { ...people[ who ], subject: 'Quote', login: ( tag + stamp + key ).replace( /[^a-z0-9]/g, '' ) };
	person.email = person.email.replace( '@', `.${ tag }${ key.replace( /[^a-z]/g, '' ) }@` );
	const context = await browser.newContext( options.context || { userAgent: UA, ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	const before = lastLog().id || 0;
	await page.goto( base + form.url, { waitUntil: 'load' } );
	await page.waitForTimeout( options.fast ? 200 : 2500 );
	for ( const [ selector, prop ] of Object.entries( form.fill ) ) {
		const input = page.locator( selector ).first();
		await input.click();
		if ( options.fast ) {
			await input.fill( person[ prop ] );
		} else {
			await input.pressSequentially( person[ prop ], { delay: 8 } );
		}
	}
	await page.waitForTimeout( options.fast ? 0 : 800 );
	if ( form.nav ) {
		await Promise.all( [ page.waitForNavigation( { timeout: 15000 } ).catch( () => {} ), page.click( form.submit ) ] );
	} else {
		await page.click( form.submit );
		await page.waitForSelector( form.done, { timeout: 15000 } ).catch( () => {} );
	}
	await page.waitForTimeout( 500 );
	const shown = ( await page.locator( 'body' ).innerText() ).match( /Thanks \w+|looks like spam[^.]*|Registration complete|check your email|Hello [^\n(]+|Your comment is awaiting|There was a problem[^.]*|thank you for your message[^.]*|One or more fields[^.]*|An error occurred[^.]*|Please check your email|Your form has been submitted|Thanks for contacting us[^.]*|Thank you for contacting|failed[^.]*|unsuccessful[^.]*/i );
	const log = lastLog();
	await context.close();
	const fresh = Number( log.id ) > Number( before );
	return { who: options.label || who, form: key, logged: fresh ? `${ log.verdict }/${ log.action } ${ log.score } [${ log.signals }]` : 'NO NEW LOG ROW', shown: shown ? shown[ 0 ].trim().slice( 0, 60 ) : '' };
}

const browser = await chromium.launch( { args: [ '--disable-blink-features=AutomationControlled' ] } );
const only = process.env.ONLY ? process.env.ONLY.split( ',' ) : Object.keys( forms );
const results = [];
for ( const key of only ) {
	results.push( await run( browser, 'human', key ) );
	results.push( await run( browser, 'spammer', key ) );
}
await browser.close();
// A bot driving stock headless Chromium, filling instantly.
const headless = await chromium.launch();
for ( const key of only ) {
	results.push( await run( headless, 'human', key, { fast: true, label: 'headless-bot', context: { ignoreHTTPSErrors: true } } ) );
}
await headless.close();
console.table( results );
