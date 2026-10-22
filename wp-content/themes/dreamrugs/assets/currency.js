/**
 * Display-only currency converter, ported from CurrencyContext.tsx /
 * utils/currency.ts. This ONLY changes what price text is shown — the
 * customer is always actually charged in the store's one real
 * transactional currency (WooCommerce's own get_woocommerce_currency(),
 * exposed here as config.base_currency) regardless of what's selected,
 * exactly matching the original app's own switcher (it never took payment
 * in anything but the tenant's base_currency either).
 *
 * Every price on the site that should react to this is server-rendered
 * already-correct WooCommerce markup, wrapped in `.drc-price` by
 * dreamrugs_price_span() (functions.php) with the raw numeric amount in
 * data-drc-amount — this script finds all of them and rewrites their text,
 * rather than re-deriving prices itself.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'drc_display_currency';

	// Mirrors utils/currency.ts's REGION_TO_CURRENCY — a conservative
	// browser-locale-only guess (no IP lookup), falling through to the
	// store's own default when the region isn't one of these.
	var REGION_TO_CURRENCY = {
		IN: 'INR', GB: 'GBP',
		DE: 'EUR', FR: 'EUR', IT: 'EUR', ES: 'EUR', NL: 'EUR', BE: 'EUR', AT: 'EUR',
		IE: 'EUR', PT: 'EUR', FI: 'EUR', GR: 'EUR', LU: 'EUR', SK: 'EUR', SI: 'EUR',
		EE: 'EUR', LV: 'EUR', LT: 'EUR', CY: 'EUR', MT: 'EUR', HR: 'EUR',
		US: 'USD', PR: 'USD', EC: 'USD', PA: 'USD',
		CA: 'CAD', AU: 'AUD', AE: 'AED', SG: 'SGD', CH: 'CHF',
		SA: 'SAR', QA: 'QAR', JP: 'JPY', NZ: 'NZD', ZA: 'ZAR',
	};

	function detectCurrencyFromLocale() {
		var locales = ( navigator.languages && navigator.languages.length ) ? navigator.languages : [ navigator.language ];
		for ( var i = 0; i < locales.length; i++ ) {
			var loc = locales[ i ];
			if ( ! loc ) continue;
			var region = ( loc.split( '-' )[ 1 ] || '' ).toUpperCase();
			if ( region && REGION_TO_CURRENCY[ region ] ) return REGION_TO_CURRENCY[ region ];
		}
		return null;
	}

	function getConversionRate( from, to, base, rates ) {
		if ( from === to ) return 1;
		var fromRate = from === base ? 1 : ( rates[ from ] || 0 );
		var toRate = to === base ? 1 : ( rates[ to ] || 0 );
		if ( ! fromRate || ! toRate ) return 1; // unknown rate — show raw value, same fallback as the original
		return toRate / fromRate;
	}

	function fmt( n, currency, fractions ) {
		try {
			// Always 'en-US' (period decimal, comma thousands) regardless of
			// currency, deliberately — matches utils/currency.ts's fmt(), which
			// calls out that some native locales (e.g. de-DE for EUR) use a
			// comma decimal separator that would read as a display bug here.
			return new Intl.NumberFormat( 'en-US', { style: 'currency', currency: currency, maximumFractionDigits: fractions, minimumFractionDigits: fractions } ).format( n );
		} catch ( e ) {
			return currency + ' ' + n.toFixed( fractions );
		}
	}

	function applyCurrency( currency, config ) {
		document.querySelectorAll( '.drc-price' ).forEach( function ( el ) {
			var amount = parseFloat( el.dataset.drcAmount );
			if ( isNaN( amount ) ) return;
			// The amount's own currency: the store base, or — on a cart/checkout
			// being charged in another currency — that currency.
			var from = el.dataset.drcCurrency || config.base_currency;
			var rate = getConversionRate( from, currency, config.base_currency, config.exchange_rates );
			var converted = amount * rate;
			// A nonzero amount that would round away to "0" at 0 decimal places
			// (e.g. a small INR price converted into a stronger currency) still
			// needs decimals to show a meaningful value instead of a misleading
			// zero — same rule as the original.
			var fractions = converted !== 0 && Math.round( converted ) === 0 ? 2 : 0;
			el.textContent = fmt( converted, currency, fractions );
		} );

		// Checkout-only: make it explicit that Razorpay will actually charge
		// the store's real currency regardless of what's displayed above —
		// matches CustomerCheckout.tsx's showsConvertedEstimate messaging,
		// which exists specifically so a shopper viewing prices in USD/EUR/etc.
		// isn't surprised to see an INR charge on their statement.
		var disclaimer = document.getElementById( 'drc-currency-disclaimer' );
		var orderTotal = document.getElementById( 'drc-order-total' );
		if ( disclaimer && orderTotal ) {
			var totalSpan = orderTotal.querySelector( '.drc-price' );
			var chargeCurrency = ( totalSpan && totalSpan.dataset.drcCurrency ) || config.base_currency;
			if ( currency === chargeCurrency ) {
				disclaimer.style.display = 'none';
			} else {
				var amount = parseFloat( totalSpan && totalSpan.dataset.drcAmount );
				if ( ! isNaN( amount ) ) {
					disclaimer.textContent = 'Converted estimate — you will be charged ' + fmt( amount, chargeCurrency, 2 ) + ' (' + chargeCurrency + ').';
					disclaimer.style.display = '';
				}
			}
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var cfg = window.drcCurrencyConfig || { base_currency: 'INR', default_currency: 'INR', exchange_rates: {} };

		// Same rule as DRC_Multi_Currency::selected(): the store currency, or an
		// enabled currency with a known exchange rate — anything else would be
		// displayed in one currency but charged in another.
		function allowed( code ) {
			if ( ! code ) return false;
			if ( code === cfg.base_currency ) return true;
			if ( cfg.currencies && cfg.currencies.length && cfg.currencies.indexOf( code ) === -1 ) return false;
			return ( cfg.exchange_rates || {} )[ code ] > 0;
		}

		// No currency switcher (like the original storefront): the currency is
		// picked automatically — a signed-in customer's country, else the
		// guest's geolocated country (async, below), else the browser locale,
		// else the store default. A choice saved by the old header dropdown
		// can no longer be changed, so it's dropped.
		try { localStorage.removeItem( STORAGE_KEY ); } catch ( e ) {}
		var fromCustomer = cfg.customer_country ? REGION_TO_CURRENCY[ cfg.customer_country.toUpperCase() ] : null;
		var cachedGeo = null; // this session's IP lookup ('' = unknown), see below
		try { cachedGeo = sessionStorage.getItem( 'drc_geo_country' ); } catch ( e ) {}
		var fromGeo = cachedGeo ? REGION_TO_CURRENCY[ cachedGeo ] : null;
		var initial = [ fromCustomer, fromGeo, detectCurrencyFromLocale(), cfg.default_currency ].filter( allowed )[ 0 ] || cfg.base_currency || 'INR';

		// Exposed so other scripts that swap in fresh server-rendered
		// `.drc-price` markup after page load (e.g. assets/cart.js's AJAX
		// quantity-stepper refresh) can re-run the conversion on it.
		window.drcCurrency = {
			reapply: function () { applyCurrency( initial, cfg ); },
		};

		// The server reads this cookie to price and charge the cart/checkout
		// in the same currency (DRC_Multi_Currency).
		function setChargeCookie( code ) {
			document.cookie = STORAGE_KEY + '=' + encodeURIComponent( code ) + ';path=/;max-age=31536000;SameSite=Lax';
		}
		var onCartOrCheckout = document.body.classList.contains( 'woocommerce-cart' ) || ( document.body.classList.contains( 'woocommerce-checkout' ) && ! document.body.classList.contains( 'woocommerce-order-pay' ) && ! document.body.classList.contains( 'woocommerce-order-received' ) );
		var hadCookie = document.cookie.split( '; ' ).some( function ( c ) { return c === STORAGE_KEY + '=' + initial; } );
		setChargeCookie( initial );
		// The cart/checkout was priced before the cookie matched: reload once
		// into the right currency.
		var reloadedOnce = false;
		try { reloadedOnce = '1' === sessionStorage.getItem( 'drc_currency_reloaded' ); } catch ( e ) { reloadedOnce = true; }
		if ( onCartOrCheckout && ! hadCookie && ! reloadedOnce ) {
			try { sessionStorage.setItem( 'drc_currency_reloaded', '1' ); } catch ( e ) {}
			window.location.reload();
			return;
		}

		applyCurrency( initial, cfg );

		// Guests: refine the locale guess with an IP lookup (once per session).
		if ( ! fromCustomer && null === cachedGeo && cfg.root ) {
			var useCountry = function ( code, canReload ) {
				var geoCurrency = code ? REGION_TO_CURRENCY[ code ] : null;
				if ( ! allowed( geoCurrency ) || geoCurrency === initial ) return;
				initial = geoCurrency;
				setChargeCookie( geoCurrency );
				// Reload to re-price the cart — only when the result is remembered,
				// otherwise the next load would look it up and reload again.
				if ( onCartOrCheckout && canReload ) { window.location.reload(); return; }
				applyCurrency( geoCurrency, cfg );
			};
			fetch( cfg.root + '/customer/detect-country' )
				.then( function ( r ) { return r.ok ? r.json() : {}; } )
				.then( function ( data ) {
					var code = ( data && data.country_code ) || '';
					var stored = true;
					try { sessionStorage.setItem( 'drc_geo_country', code ); } catch ( e ) { stored = false; }
					useCountry( code, stored );
				} )
				.catch( function () {} );
		}

		// Fetch live exchange rates (the inline config only carries what was
		// known at page-render time) and re-apply once they're in, so a vendor
		// who just updated rates in DreamRugs -> Settings doesn't need every
		// cached page purged to see the new numbers reflected.
		if ( cfg.root ) {
			fetch( cfg.root + '/currency-config' )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					cfg.base_currency = data.base_currency || cfg.base_currency;
					cfg.exchange_rates = data.exchange_rates || cfg.exchange_rates;
					applyCurrency( initial, cfg );
				} )
				.catch( function () {} );
		}
	} );
})();
