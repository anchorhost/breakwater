/**
 * Breakwater: adds browser proof to every POST form on the page.
 *
 * - bw_url   honeypot, hidden from people and assistive tech
 * - bw_meta  time on page, clicks/taps and keys, webdriver flag
 * - bw_token proof of work for a challenge fetched live (never cached in the page)
 *
 * The token and meta also go in short-lived cookies, because some form plugins post their
 * own payload (a JSON model, one serialized field) instead of the form's inputs. A cookie
 * rides along with any same-site request however the payload is built.
 *
 * The work starts when someone touches a field, so most visitors never pay for it.
 *
 * Time and counts carry over a form submit that loads a new page in the same tab (a multi-page
 * form's Next, a validation error), so the last step of a long form is not judged on its own.
 */
( function () {
	'use strict';

	var config = window.breakwater || {};
	if ( ! config.endpoint || ! window.fetch ) {
		return;
	}

	var started = Date.now();
	var touches = 0;
	var keys = 0;
	carried();
	var token = '';
	var solving = null;

	var secure = location.protocol === 'https:' ? '; Secure' : '';

	function cookie( name, value, seconds ) {
		document.cookie = name + '=' + encodeURIComponent( value ) + '; path=/; max-age=' + seconds + '; SameSite=Lax' + secure;
	}

	function remember() {
		cookie( 'bw_meta', meta(), 43200 );
	}

	document.addEventListener( 'pointerdown', function ( event ) {
		touches++;
		remember();
		// Gravity Forms turns pages with form.submit(), which fires no submit event.
		if ( event.target && event.target.closest && event.target.closest( 'form button, form [type="submit"], form [type="button"]' ) ) {
			carry();
		}
		// A click on something that sends a form: fetch a fresh proof for the next send,
		// since AJAX forms never fire a submit event we could hook.
		if ( event.target && event.target.closest && event.target.closest( 'button, [type="submit"], [role="button"]' ) && ( token || solving ) ) {
			setTimeout( refresh, 3000 );
		}
	}, { capture: true, passive: true } );
	document.addEventListener( 'touchstart', function () { touches++; remember(); }, { capture: true, passive: true } );
	document.addEventListener( 'keydown', function ( event ) {
		keys++;
		if ( event.key === 'Enter' && event.target && event.target.closest && event.target.closest( 'form' ) ) {
			carry();
		}
	}, true );
	document.addEventListener( 'input', remember, true );
	document.addEventListener( 'focusin', function ( event ) {
		if ( event.target && event.target.matches && event.target.matches( 'input, textarea, select' ) ) {
			solve();
		}
	}, true );

	// Picks up where the page before left off, if it was a form sent less than 30 minutes ago.
	function carried() {
		try {
			var saved = JSON.parse( sessionStorage.getItem( 'bw_carry' ) || 'null' );
			sessionStorage.removeItem( 'bw_carry' );
			if ( saved && Date.now() - saved.at < 1800000 && saved.s <= Date.now() ) {
				started = saved.s;
				touches = saved.i;
				keys = saved.k;
			}
		} catch ( e ) {}
	}

	function carry() {
		try {
			sessionStorage.setItem( 'bw_carry', JSON.stringify( { s: started, i: touches, k: keys, at: Date.now() } ) );
		} catch ( e ) {}
	}

	// Gravity Forms Next and Previous: nothing is judged until the last page, so never hold them.
	function pageTurn( form ) {
		var id = ( form.id || '' ).replace( /^gform_/, '' );
		var target = form.querySelector( 'input[name="gform_target_page_number_' + id + '"]' );
		var save = form.querySelector( 'input[name="gform_save"]' );
		return !! target && target.value !== '' && target.value !== '0' && ! ( save && save.value );
	}

	function meta() {
		return JSON.stringify( {
			e: Date.now() - started,
			i: touches,
			k: keys,
			w: navigator.webdriver ? 1 : 0,
			v: 1
		} );
	}

	function hex( buffer ) {
		var bytes = new Uint8Array( buffer );
		var out = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			out += ( bytes[ i ] < 16 ? '0' : '' ) + bytes[ i ].toString( 16 );
		}
		return out;
	}

	function digest( text ) {
		return crypto.subtle.digest( 'SHA-256', new TextEncoder().encode( text ) ).then( hex );
	}

	// Hash in batches so the main thread stays responsive.
	function search( task, from ) {
		var batch = [];
		var end = Math.min( from + 500, task.max + 1 );
		for ( var n = from; n < end; n++ ) {
			batch.push( digest( task.salt + n ) );
		}
		return Promise.all( batch ).then( function ( hashes ) {
			var found = hashes.indexOf( task.challenge );
			if ( found !== -1 ) {
				return from + found;
			}
			if ( end > task.max ) {
				throw new Error( 'no solution' );
			}
			return search( task, end );
		} );
	}

	function solve() {
		if ( token ) {
			return Promise.resolve( token );
		}
		if ( solving ) {
			return solving;
		}
		if ( ! window.crypto || ! crypto.subtle || ! window.TextEncoder ) {
			return Promise.resolve( '' );
		}
		var url = config.endpoint + ( config.endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) + '_=' + Date.now();
		solving = fetch( url, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( task ) {
				return search( task, 0 ).then( function ( number ) {
					token = btoa( JSON.stringify( {
						salt: task.salt,
						challenge: task.challenge,
						signature: task.signature,
						number: number
					} ) );
					cookie( 'bw_token', token, 43200 );
					remember();
					fill();
					return token;
				} );
			} )
			.catch( function () { return ''; } )
			.then( function ( value ) {
				solving = null;
				return value;
			} );
		return solving;
	}

	function field( form, name, value ) {
		var input = form.querySelector( 'input[name="' + name + '"]' );
		if ( ! input ) {
			input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			form.appendChild( input );
		}
		input.value = value;
	}

	function protectable( form ) {
		var method = ( form.getAttribute( 'method' ) || 'get' ).toLowerCase();
		return method === 'post' || form.matches( '.elementor-form, .wpcf7-form, .wpforms-form, form[id^="gform_"]' );
	}

	function prepare( form ) {
		if ( form.dataset.bw || ! protectable( form ) ) {
			return;
		}
		form.dataset.bw = '1';

		var trap = document.createElement( 'div' );
		trap.setAttribute( 'aria-hidden', 'true' );
		trap.style.cssText = 'position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;';
		trap.innerHTML = '<label>Leave this field empty<input type="text" name="bw_url" value="" tabindex="-1" autocomplete="off"></label>';
		form.appendChild( trap );

		field( form, 'bw_meta', meta() );
		field( form, 'bw_token', token );

		[ 'focusin', 'pointerdown', 'touchstart' ].forEach( function ( type ) {
			form.addEventListener( type, solve, { passive: true } );
		} );
		form.addEventListener( 'input', function () {
			field( form, 'bw_meta', meta() );
		} );
	}

	function fill() {
		document.querySelectorAll( 'form[data-bw]' ).forEach( function ( form ) {
			field( form, 'bw_token', token );
			field( form, 'bw_meta', meta() );
		} );
	}

	function scan( root ) {
		if ( root.tagName === 'FORM' ) {
			prepare( root );
		}
		if ( root.querySelectorAll ) {
			root.querySelectorAll( 'form' ).forEach( prepare );
		}
	}

	// Hold a native submit until the work is done, then send it on. Each solution is
	// single-use, so the next submit (an AJAX form sent again) gets a fresh one.
	window.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || form.tagName !== 'FORM' ) {
			return;
		}
		prepare( form );
		if ( ! form.dataset.bw ) {
			return;
		}
		field( form, 'bw_meta', meta() );
		remember();
		carry();
		if ( pageTurn( form ) ) {
			solve();
			return;
		}
		if ( token ) {
			field( form, 'bw_token', token );
			spent();
			return;
		}
		if ( form.dataset.bwWaiting ) {
			return; // Already holding this form; a second click sends it as-is.
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		form.dataset.bwWaiting = '1';
		var submitter = event.submitter;
		var timeout = new Promise( function ( resolve ) { setTimeout( resolve, 4000 ); } );
		Promise.race( [ solve(), timeout ] ).then( function () {
			field( form, 'bw_token', token );
			field( form, 'bw_meta', meta() );
			spent();
			if ( form.requestSubmit ) {
				form.requestSubmit( submitter && submitter.form === form ? submitter : undefined );
			} else {
				form.submit();
			}
			delete form.dataset.bwWaiting;
		} );
	}, true );

	// The token was just used. The cookie keeps it until the replacement is ready, so a
	// request still on its way is never sent without one.
	function spent() {
		setTimeout( refresh, 1500 );
	}

	function refresh() {
		if ( solving ) {
			return;
		}
		token = '';
		solve();
	}

	function start() {
		scan( document );
		new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) {
					if ( node.nodeType === 1 ) {
						scan( node );
					}
				} );
			} );
		} ).observe( document.documentElement, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
