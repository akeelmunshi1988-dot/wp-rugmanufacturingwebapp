/**
 * Handles the "Apply" button next to the always-visible promo code input in
 * woocommerce/checkout/review-order.php (see that file's header comment for
 * why this can't just be a real <form class="checkout_coupon"> the way
 * WooCommerce's own checkout.js expects — nested forms aren't valid HTML,
 * and this input lives inside the outer <form name="checkout"> already).
 *
 * Calls WooCommerce's real wc-ajax=apply_coupon endpoint directly — same
 * request WooCommerce's own checkout.js would send from a real
 * form.checkout_coupon, just triggered manually — so coupon validation
 * itself (minimum spend, usage limits, expiry, per-customer limits, etc.)
 * stays entirely WooCommerce's, not reimplemented here. window.wc_checkout_params
 * (nonce + ajax URL) is already localized by WooCommerce's own checkout.js,
 * which is unconditionally enqueued on the checkout page, so no separate
 * localization is needed here.
 *
 * Listeners are delegated to `document` rather than bound to #drc-coupon-form
 * directly: WooCommerce's own update_checkout AJAX flow (fired on every
 * country/shipping change, not just a coupon apply) replaces #order_review's
 * entire innerHTML with a fresh server render, which would silently orphan
 * any listener bound to the original elements — confirmed this is a real,
 * not theoretical, risk here since the checkout page already triggers that
 * refresh for GST-by-country recalculation.
 */
( function () {
	function clearError( field, wrapper ) {
		field.classList.remove( 'has-error' );
		var existing = wrapper.querySelector( '.coupon-error-notice' );
		if ( existing ) {
			existing.remove();
		}
	}

	function applyCoupon( form ) {
		var field = form.querySelector( '#coupon_code' );
		var btn = form.querySelector( '#drc-apply-coupon' );
		var wrapper = field.closest( '.relative' );
		var code = field.value.trim();
		if ( ! code || ! window.wc_checkout_params ) {
			return;
		}
		clearError( field, wrapper );
		btn.disabled = true;
		btn.textContent = '…';

		var body = new URLSearchParams( {
			security: window.wc_checkout_params.apply_coupon_nonce,
			coupon_code: code,
			billing_email: ( document.querySelector( 'input[name="billing_email"]' ) || {} ).value || '',
		} );

		fetch( window.wc_checkout_params.wc_ajax_url.toString().replace( '%%endpoint%%', 'apply_coupon' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) { return res.text(); } )
			.then( function ( html ) {
				document.querySelectorAll( '.woocommerce-error, .woocommerce-message, .is-error, .is-success' ).forEach( function ( n ) { n.remove(); } );

				if ( html.indexOf( 'woocommerce-error' ) === -1 && html.indexOf( 'is-error' ) === -1 ) {
					// Success — the price-refresh below re-renders this whole
					// template server-side, which swaps the input out for the
					// styled "applied" box (review-order.php's elseif branch),
					// so there's nothing else to update here directly.
					field.value = '';
					if ( window.jQuery ) {
						window.jQuery( document.body ).trigger( 'update_checkout', { update_shipping_method: false } );
					}
				} else {
					var tmp = document.createElement( 'div' );
					tmp.innerHTML = html;
					var message = ( tmp.textContent || '' ).trim() || 'This coupon code is invalid.';
					field.classList.add( 'has-error' );
					var span = document.createElement( 'span' );
					span.className = 'coupon-error-notice';
					span.textContent = message;
					wrapper.appendChild( span );
				}
			} )
			.catch( function () {
				field.classList.add( 'has-error' );
				var span = document.createElement( 'span' );
				span.className = 'coupon-error-notice';
				span.textContent = 'Could not apply this code — please try again.';
				wrapper.appendChild( span );
			} )
			.finally( function () {
				btn.disabled = false;
				btn.textContent = 'Apply';
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '#drc-apply-coupon' );
		if ( ! btn ) {
			return;
		}
		var form = btn.closest( '#drc-coupon-form' );
		if ( form ) {
			applyCoupon( form );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' !== e.key ) {
			return;
		}
		var field = e.target.closest( '#drc-coupon-form #coupon_code' );
		if ( ! field ) {
			return;
		}
		e.preventDefault(); // Stops Enter from bubbling up to the outer checkout <form> and submitting the order.
		applyCoupon( field.closest( '#drc-coupon-form' ) );
	} );

	document.addEventListener( 'input', function ( e ) {
		var field = e.target.closest( '#drc-coupon-form #coupon_code' );
		if ( field ) {
			clearError( field, field.closest( '.relative' ) );
		}
	} );
} )();
