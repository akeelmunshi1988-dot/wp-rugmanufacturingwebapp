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
			var rate = getConversionRate( config.base_currency, currency, config.base_currency, config.exchange_rates );
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
			if ( currency === config.base_currency ) {
				disclaimer.style.display = 'none';
			} else {
				var amount = parseFloat( ( orderTotal.querySelector( '.drc-price' ) || {} ).dataset && orderTotal.querySelector( '.drc-price' ).dataset.drcAmount );
				if ( ! isNaN( amount ) ) {
					disclaimer.textContent = 'Converted estimate — you will be charged ' + fmt( amount, config.base_currency, 2 ) + ' (' + config.base_currency + ') via UPI/Bank Transfer.';
					disclaimer.style.display = '';
				}
			}
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var select = document.getElementById( 'drc-currency-select' );
		var cfg = window.drcCurrencyConfig || { base_currency: 'INR', default_currency: 'INR', exchange_rates: {} };

		// Exposed so other scripts that swap in fresh server-rendered
		// `.drc-price` markup after page load (e.g. assets/cart.js's AJAX
		// quantity-stepper refresh) can re-run the conversion on the new
		// elements, rather than those elements sitting unconverted until the
		// visitor happens to touch the currency dropdown again.
		window.drcCurrency = {
			reapply: function () {
				applyCurrency( select ? select.value : ( localStorage.getItem( STORAGE_KEY ) || cfg.default_currency || 'INR' ), cfg );
			},
		};

		var initial = localStorage.getItem( STORAGE_KEY ) || detectCurrencyFromLocale() || cfg.default_currency || 'INR';
		if ( select ) {
			select.value = initial;
			// The requested locale/stored currency might not be one of the 14
			// options actually rendered — fall back to the store default rather
			// than leaving the <select> on its first option unintentionally.
			if ( select.value !== initial ) {
				select.value = cfg.default_currency || 'INR';
				initial = select.value;
			}
		}

		applyCurrency( initial, cfg );

		if ( select ) {
			select.addEventListener( 'change', function () {
				localStorage.setItem( STORAGE_KEY, select.value );
				applyCurrency( select.value, cfg );
			} );
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
					applyCurrency( select ? select.value : initial, cfg );
				} )
				.catch( function () {} );
		}
	} );
})();
