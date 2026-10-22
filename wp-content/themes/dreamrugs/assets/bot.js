/**
 * Bot checks for public forms — pairs with DRC_Security (port of the
 * original's useBotProtection hook). Forms marked data-drc-bot-form carry
 * a hidden "website" honeypot and, when a Turnstile site key is set, a
 * Cloudflare widget. Callers send window.drcBot.headers(form) with their
 * request; headers() waits briefly for the Turnstile token if needed.
 */
( function () {
	var cfg = window.drcBotConfig || {};
	var scriptLoading = null;

	function loadTurnstile() {
		if ( ! cfg.siteKey ) return Promise.resolve( false );
		scriptLoading = scriptLoading || new Promise( function ( resolve ) {
			var s = document.createElement( 'script' );
			s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
			s.async = true;
			s.onload = function () { resolve( true ); };
			s.onerror = function () { scriptLoading = null; resolve( false ); };
			document.head.appendChild( s );
		} );
		return scriptLoading;
	}

	function renderWidgets( root ) {
		if ( ! cfg.siteKey ) return;
		loadTurnstile().then( function ( ok ) {
			if ( ! ok || ! window.turnstile ) return;
			( root || document ).querySelectorAll( '.drc-turnstile:not([data-rendered])' ).forEach( function ( el ) {
				el.dataset.rendered = '1';
				window.turnstile.render( el, { sitekey: cfg.siteKey, appearance: 'interaction-only' } );
			} );
		} );
	}

	function token( form ) {
		var input = form && form.querySelector( '[name="cf-turnstile-response"]' );
		return input ? input.value : '';
	}

	window.drcBot = {
		headers: function ( form ) {
			var trap = form && form.querySelector( 'input[name="website"]' );
			return { 'X-Form-Trap': trap ? trap.value : '', 'X-Turnstile-Token': token( form ) };
		},
		fields: function () { return {}; },
		render: renderWidgets,
	};

	document.addEventListener( 'DOMContentLoaded', function () { renderWidgets( document ); } );
	// Forms inside modals appear later (Alpine x-if) — render when they do.
	if ( 'MutationObserver' in window ) {
		new MutationObserver( function () { renderWidgets( document ); } ).observe( document.documentElement, { childList: true, subtree: true } );
	}
} )();
