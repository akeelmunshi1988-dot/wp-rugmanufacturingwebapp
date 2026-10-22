/**
 * Vendor admin screens for the DreamRugs wp-admin menu (Phase 5). Plain
 * fetch + DOM — no build step, matching the "server renders a shell, one JS
 * file owns interactivity" split already used by the storefront theme's
 * Alpine components, just without Alpine (not loaded in wp-admin). Every
 * call sends X-WP-Nonce (window.drcAdmin.nonce) for the same reason the
 * frontend's dreamrugs-rest-config exists: without it, WordPress's REST API
 * never authenticates the cookie session, regardless of permission_callback.
 */
(function () {
	'use strict';

	function api( path, opts ) {
		opts = opts || {};
		var headers = Object.assign( { 'X-WP-Nonce': window.drcAdmin.nonce }, opts.headers || {} );
		if ( opts.body ) headers['Content-Type'] = 'application/json';
		return fetch( window.drcAdmin.root + path, Object.assign( {}, opts, { headers: headers, credentials: 'same-origin' } ) )
			.then( function ( res ) {
				return res.text().then( function ( text ) {
					var data = null;
					try { data = text ? JSON.parse( text ) : null; } catch ( e ) { /* non-JSON body */ }
					if ( ! res.ok ) {
						var msg = ( data && data.message ) ? data.message : ( 'Request failed (' + res.status + ')' );
						throw new Error( msg );
					}
					return data;
				} );
			} );
	}

	function fmtMoney( amount ) {
		if ( amount === null || amount === undefined ) return '—';
		try {
			return new Intl.NumberFormat( 'en-IN', { style: 'currency', currency: window.drcAdmin.currency || 'INR', maximumFractionDigits: 0 } ).format( amount );
		} catch ( e ) {
			return ( window.drcAdmin.currency || 'INR' ) + ' ' + Math.round( amount ).toLocaleString();
		}
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s === null || s === undefined ? '' : String( s );
		return d.innerHTML;
	}

	function el( html ) {
		var t = document.createElement( 'template' );
		t.innerHTML = html.trim();
		return t.content.firstElementChild;
	}

	// ── Dashboard ─────────────────────────────────────────────────────────

	function initDashboard() {
		var root = document.getElementById( 'drc-dashboard-root' );
		if ( ! root ) return;

		api( '/admin/dashboard-stats' ).then( function ( d ) {
			var cards = [
				[ 'Total Orders', d.total_orders ],
				[ 'Revenue (accepted quotes)', fmtMoney( d.total_revenue ) ],
				[ 'Active Quotes', d.active_quotes ],
				[ 'Low Stock Materials', d.low_stock_materials ],
				[ 'Orders In Production', d.orders_in_production ],
				[ 'Orders Pending', d.orders_pending ],
			].map( function ( c ) {
				return '<div class="drc-card"><div class="drc-card-label">' + esc( c[0] ) + '</div><div class="drc-card-value">' + esc( c[1] ) + '</div></div>';
			} ).join( '' );

			var recentQuotes = d.recent_quotes.map( function ( q ) {
				return '<tr><td>#' + q.id + '</td><td>' + esc( q.customer_name || '—' ) + '</td><td>' + esc( q.rug_name || 'Custom' ) + '</td><td><span class="drc-badge drc-badge-' + q.status + '">' + q.status + '</span></td><td>' + fmtMoney( q.final_price ) + '</td></tr>';
			} ).join( '' ) || '<tr><td colspan="5">No quotes yet.</td></tr>';

			var recentOrders = d.recent_orders.map( function ( o ) {
				return '<tr><td><a href="' + esc( '/wp-admin/admin.php?page=wc-orders&action=edit&id=' + o.id ) + '">#' + o.id + '</a></td><td>' + esc( o.customer_name || '—' ) + '</td><td>' + esc( o.status ) + '</td><td>' + fmtMoney( o.final_price ) + '</td></tr>';
			} ).join( '' ) || '<tr><td colspan="4">No orders yet.</td></tr>';

			var monthly = d.monthly_revenue.map( function ( m ) {
				return '<tr><td>' + esc( m.month ) + '</td><td>' + fmtMoney( m.revenue ) + '</td><td>' + m.orders + '</td></tr>';
			} ).join( '' );

			root.innerHTML =
				'<div class="drc-cards">' + cards + '</div>' +
				'<div style="display:flex;gap:24px;flex-wrap:wrap;">' +
				'<div style="flex:1;min-width:320px;"><h2>Recent Quotes</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Customer</th><th>Rug</th><th>Status</th><th>Price</th></tr></thead><tbody>' + recentQuotes + '</tbody></table></div>' +
				'<div style="flex:1;min-width:320px;"><h2>Recent Orders</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Customer</th><th>Status</th><th>Total</th></tr></thead><tbody>' + recentOrders + '</tbody></table></div>' +
				'</div>' +
				'<h2>Monthly Revenue (accepted quotes)</h2><table class="widefat striped" style="max-width:500px;"><thead><tr><th>Month</th><th>Revenue</th><th>Orders</th></tr></thead><tbody>' + monthly + '</tbody></table>';
		} ).catch( function ( e ) {
			root.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>';
		} );
	}

	// ── Quotes ────────────────────────────────────────────────────────────

	var QUOTE_STATUSES = [ 'draft', 'sent', 'accepted', 'rejected' ];
	var STATUS_LABELS = { draft: 'Draft', sent: 'Sent', accepted: 'Accepted', rejected: 'Rejected' };

	function restUrl( path, params ) {
		var qs = new URLSearchParams( Object.assign( { _wpnonce: window.drcAdmin.nonce }, params || {} ) );
		return window.drcAdmin.root + path + '?' + qs;
	}

	/** Same message as the original Quotes.tsx buildWhatsAppUrl(). */
	function whatsAppUrl( q ) {
		var phone = ( q.customer_phone || '' ).replace( /\D/g, '' );
		var msg = [
			'Hi ' + ( q.customer_name || 'there' ) + ',',
			'',
			'Here is your quote from us:',
			'📋 Rug    : ' + q.rug_name,
			q.size_display ? '📐 Size   : ' + q.size_display : '',
			'📦 Qty    : ' + ( q.qty || 1 ),
			'💰 Total  : ' + ( q.final_price !== null ? fmtMoney( q.final_price ) : 'TBD' ),
			q.rush_order ? '⚡ Rush' : '',
			'',
			'This quote is valid for 15 days. Please confirm to proceed.',
			'— ' + ( window.drcAdmin.businessName || 'DreamRugsCreation' ) + ' Team',
		].filter( Boolean ).join( '\n' );
		return ( phone ? 'https://wa.me/' + phone : 'https://wa.me/' ) + '?text=' + encodeURIComponent( msg );
	}

	function initQuotes() {
		var root = document.getElementById( 'drc-quotes-root' );
		if ( ! root ) return;

		var urlParams = new URLSearchParams( window.location.search );
		var deepLink = urlParams.get( 'quote' );
		var state = {
			status: 'all', page: 1, search: deepLink || urlParams.get( 'search' ) || '', expanded: deepLink ? parseInt( deepLink, 10 ) : null,
			materials: [], rush: false, custom: false, dateFrom: '', dateTo: '', view: 'list',
		};

		function fetchMaterials() {
			if ( state.materials.length ) return Promise.resolve();
			return api( '/admin/materials' ).then( function ( m ) { state.materials = m; } ).catch( function () { state.materials = []; } );
		}

		function renderShell() {
			var tabs = [ 'all' ].concat( QUOTE_STATUSES ).map( function ( s ) {
				return '<button type="button" class="drc-tab' + ( s === state.status ? ' active' : '' ) + '" data-status="' + s + '">' + ( 'all' === s ? 'All' : STATUS_LABELS[ s ] ) + '</button>';
			} ).join( '' );
			root.innerHTML =
				'<p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">' +
				'<a class="button button-primary" href="admin.php?page=dreamrugs-quote-builder">+ New quote (Quote Builder)</a>' +
				'<span style="margin-left:auto;">View: <button type="button" class="button drc-view" data-view="list">List</button> <button type="button" class="button drc-view" data-view="pipeline">Pipeline</button></span></p>' +
				'<div class="drc-tabs">' + tabs + '</div>' +
				'<div class="drc-filters" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:8px 0;">' +
				'<input type="search" id="drc-quote-search" placeholder="Search name, email, rug or #id" value="' + esc( state.search ) + '" style="min-width:280px;">' +
				'<label><input type="checkbox" id="drc-f-rush"> Rush only</label>' +
				'<label><input type="checkbox" id="drc-f-custom"> Custom requests only</label>' +
				'<label>From <input type="date" id="drc-f-from"></label>' +
				'<label>To <input type="date" id="drc-f-to"></label>' +
				'</div>' +
				'<div id="drc-quotes-list">Loading…</div>' +
				'<p id="drc-quotes-pager"></p>';

			root.querySelectorAll( '.drc-tab' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () { state.status = btn.dataset.status; state.page = 1; load(); } );
			} );
			root.querySelectorAll( '.drc-view' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () { state.view = btn.dataset.view; state.page = 1; load(); } );
			} );
			var search = root.querySelector( '#drc-quote-search' );
			var debounce;
			search.addEventListener( 'input', function () {
				clearTimeout( debounce );
				debounce = setTimeout( function () { state.search = search.value; state.page = 1; load(); }, 400 );
			} );
			[ [ '#drc-f-rush', 'rush', 'checked' ], [ '#drc-f-custom', 'custom', 'checked' ], [ '#drc-f-from', 'dateFrom', 'value' ], [ '#drc-f-to', 'dateTo', 'value' ] ].forEach( function ( f ) {
				root.querySelector( f[0] ).addEventListener( 'change', function ( e ) { state[ f[1] ] = e.target[ f[2] ]; state.page = 1; load(); } );
			} );
		}

		function materialOptions( selectedId ) {
			return '<option value="">Select material…</option>' + state.materials.map( function ( m ) {
				return '<option value="' + m.id + '"' + ( selectedId == m.id ? ' selected' : '' ) + '>' + esc( m.name ) + ' (' + esc( m.type ) + ', ' + esc( m.color ) + ')</option>';
			} ).join( '' );
		}

		function imageStrip( urls ) {
			return ( urls || [] ).map( function ( u ) {
				return '<a href="' + esc( u ) + '" target="_blank" rel="noopener"><img src="' + esc( u ) + '" style="width:72px;height:72px;object-fit:cover;border:1px solid #dcdcde;margin:0 6px 6px 0;"></a>';
			} ).join( '' );
		}

		function renderDetail( q ) {
			var open = 'draft' === q.status || 'sent' === q.status;
			var priceForm = '';
			if ( open ) {
				priceForm =
					'<h4 style="margin:12px 0 4px;">Price</h4>' +
					'<div class="drc-field-row">' +
					'<label>Material<select class="drc-material">' + materialOptions( q.material_id ) + '</select></label>' +
					'<label>Margin %<input type="number" step="0.1" class="drc-margin" value="' + ( q.margin_pct !== null ? q.margin_pct : '' ) + '" placeholder="default"></label>' +
					'<label>Discount %<input type="number" step="0.1" min="0" max="100" class="drc-discount" value="' + ( q.manual_discount_pct !== null ? q.manual_discount_pct : '' ) + '"></label>' +
					'<label>Shipping<input type="number" step="0.01" min="0" class="drc-shipping" value="' + ( q.shipping_cost !== null ? q.shipping_cost : '' ) + '"></label>' +
					'</div>' +
					'<div class="drc-field-row">' +
					'<label>Width (m)<input type="number" step="0.01" min="0.1" class="drc-w" value="' + ( q.custom_size_w !== null ? q.custom_size_w : '' ) + '"></label>' +
					'<label>Length (m)<input type="number" step="0.01" min="0.1" class="drc-h" value="' + ( q.custom_size_h !== null ? q.custom_size_h : '' ) + '"></label>' +
					'<label>Shape<select class="drc-shape">' + [ 'rect', 'circle', 'oval' ].map( function ( s ) { return '<option value="' + s + '"' + ( ( q.rug_shape || 'rect' ) === s ? ' selected' : '' ) + '>' + ( 'rect' === s ? 'Rectangle' : s.charAt( 0 ).toUpperCase() + s.slice( 1 ) ) + '</option>'; } ).join( '' ) + '</select></label>' +
					'<label>Or flat final price<input type="number" step="0.01" class="drc-flat-price" value="" placeholder="' + fmtMoney( q.final_price ) + '"></label>' +
					'</div>' +
					'<div class="drc-field-row"><label style="flex:1;">Vendor notes (shown to the customer)<textarea class="drc-vendor-notes" rows="2" style="width:100%;">' + esc( q.vendor_notes || '' ) + '</textarea></label></div>' +
					'<div class="drc-actions">' +
					'<button type="button" class="button drc-preview">Preview Price</button>' +
					'<button type="button" class="button button-primary drc-send">' + ( 'draft' === q.status ? 'Set Price & Send' : 'Re-send with New Price' ) + '</button>' +
					'<button type="button" class="button drc-reject">Reject</button>' +
					'<button type="button" class="button drc-accept"' + ( q.final_price ? '' : ' disabled title="Set a price first"' ) + '>Accept - Create Order</button>' +
					'</div>' +
					'<div class="drc-preview-result"></div>';
			} else if ( 'rejected' === q.status ) {
				priceForm = '<div class="drc-actions"><button type="button" class="button button-primary drc-revise">Revise &amp; Resend</button></div>';
			} else if ( 'accepted' === q.status ) {
				priceForm = '<p>Order: ' + ( q.wc_order_id ? '<a href="admin.php?page=wc-orders&action=edit&id=' + q.wc_order_id + '">#' + q.wc_order_id + '</a>' : '—' ) + '</p>';
			}

			var docs = q.final_price
				? '<h4 style="margin:12px 0 4px;">Documents &amp; sharing</h4><div class="drc-actions">' +
					[ 'proforma', 'tax', 'export' ].map( function ( t ) {
						return '<a class="button" href="' + esc( restUrl( '/admin/quotes/' + q.id + '/invoice', { invoice_type: t } ) ) + '">' + t.charAt( 0 ).toUpperCase() + t.slice( 1 ) + ' PDF</a>';
					} ).join( '' ) +
					'<button type="button" class="button drc-email-inv" data-type="proforma">Email proforma</button>' +
					'<button type="button" class="button drc-email-inv" data-type="tax">Email tax invoice</button>' +
					'<a class="button" target="_blank" rel="noopener" href="' + esc( whatsAppUrl( q ) ) + '">WhatsApp</a>' +
					'</div>'
				: '';

			var statusCtl = open
				? '<label style="margin-right:12px;">Change status <select class="drc-status-select">' + QUOTE_STATUSES.map( function ( s ) { return '<option value="' + s + '"' + ( s === q.status ? ' selected' : '' ) + '>' + STATUS_LABELS[ s ] + '</option>'; } ).join( '' ) + '</select></label>'
				: '';

			return '<div class="drc-quote-detail">' +
				'<p><strong>Customer:</strong> ' + esc( q.customer_name || '—' ) + ' (' + esc( q.customer_email || '—' ) + ( q.customer_phone ? ', ' + esc( q.customer_phone ) : '' ) + ')</p>' +
				'<p><strong>Rug:</strong> ' + esc( q.rug_name ) + ( q.selected_color ? ' · ' + esc( q.selected_color ) : '' ) + ' · <strong>Size:</strong> ' + esc( q.size_display || '—' ) + ' · <strong>Qty:</strong> ' + q.qty + ( q.rush_order ? ' · <strong>Rush</strong>' : '' ) + ( q.expected_delivery_days ? ' · <strong>Lead time:</strong> ' + q.expected_delivery_days + ' days' : '' ) + '</p>' +
				( q.is_custom_request ? '<p><strong>Custom request:</strong> room: ' + esc( q.room_type || '—' ) + ', material pref: ' + esc( q.material_preference || '—' ) + ', budget: ' + esc( q.budget_range || '—' ) + ( q.expected_delivery ? ', delivery: ' + esc( q.expected_delivery ) : '' ) + '</p>' : '' ) +
				( q.notes ? '<p><strong>Customer notes:</strong> ' + esc( q.notes ) + '</p>' : '' ) +
				( q.customer_response_notes ? '<p><strong>Customer response:</strong> ' + esc( q.customer_response_notes ) + '</p>' : '' ) +
				( q.review_request_count ? '<p><strong>Review requests:</strong> ' + q.review_request_count + ' / 5</p>' : '' ) +
				( q.reference_image_urls && q.reference_image_urls.length ? '<p style="margin-bottom:2px;"><strong>Customer reference images:</strong></p><div>' + imageStrip( q.reference_image_urls ) + '</div>' : '' ) +
				'<p style="margin-bottom:2px;"><strong>Your design samples</strong> (shown to the customer in My Quotes):</p>' +
				'<div class="drc-samples">' + imageStrip( q.vendor_sample_image_urls ) + '</div>' +
				'<p><input type="file" class="drc-sample-file" accept="image/jpeg,image/png,image/webp"> <button type="button" class="button drc-sample-upload">Upload sample</button>' +
				( q.vendor_sample_image_urls && q.vendor_sample_image_urls.length ? ' <button type="button" class="button-link-delete drc-sample-clear">Remove all samples</button>' : '' ) + '</p>' +
				priceForm + docs +
				'<p style="margin-top:12px;">' + statusCtl + '<button type="button" class="button-link-delete drc-delete">Delete quote</button></p>' +
				'<div class="drc-detail-msg"></div>' +
				'</div>';
		}

		function wireDetail( detailTr, q ) {
			var msgBox = detailTr.querySelector( '.drc-detail-msg' );
			function showError( e ) { msgBox.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; }
			function showMsg( m ) { msgBox.innerHTML = '<p class="drc-msg">' + esc( m ) + '</p>'; }
			function val( sel ) { var n = detailTr.querySelector( sel ); return n ? n.value : ''; }
			function num( sel ) { var v = val( sel ); return '' === v ? null : parseFloat( v ); }
			function on( sel, fn ) { var n = detailTr.querySelector( sel ); if ( n ) n.addEventListener( 'click', fn ); }
			function adjustBody() {
				return {
					margin_pct: num( '.drc-margin' ),
					manual_discount_pct: num( '.drc-discount' ),
					shipping_cost: num( '.drc-shipping' ),
					custom_size_w: num( '.drc-w' ),
					custom_size_h: num( '.drc-h' ),
					rug_shape: val( '.drc-shape' ) || null,
				};
			}

			on( '.drc-preview', function () {
				var materialId = val( '.drc-material' );
				if ( ! materialId ) { showError( new Error( 'Select a material to preview.' ) ); return; }
				var body = Object.assign( adjustBody(), { material_id: parseInt( materialId, 10 ) } );
				api( '/admin/quotes/' + q.id + '/adjust/preview', { method: 'POST', body: JSON.stringify( body ) } )
					.then( function ( r ) {
						var lines = ( r.breakdown || [] ).map( function ( b ) { return '<li>' + esc( b.label || b.rule ) + ': ' + fmtMoney( b.amount ) + '</li>'; } ).join( '' );
						detailTr.querySelector( '.drc-preview-result' ).innerHTML = '<p><strong>Preview:</strong> ' + fmtMoney( r.final_price ) + ' total (' + r.total_sqm + ' sqm @ margin ' + r.profit_margin_pct + '%)</p><ul style="margin:0 0 8px 18px;list-style:disc;">' + lines + '</ul>';
					} ).catch( showError );
			} );

			on( '.drc-send', function () {
				var materialId = val( '.drc-material' );
				var flatPrice = val( '.drc-flat-price' );
				var vendorNotes = val( '.drc-vendor-notes' );
				var req;
				if ( flatPrice ) {
					req = api( '/admin/quotes/' + q.id + '/adjust', { method: 'PATCH', body: JSON.stringify( Object.assign( adjustBody(), { final_price: parseFloat( flatPrice ), vendor_notes: vendorNotes } ) ) } );
				} else if ( materialId ) {
					req = api( '/admin/quotes/' + q.id + '/adjust', { method: 'PATCH', body: JSON.stringify( Object.assign( adjustBody(), { material_id: parseInt( materialId, 10 ), vendor_notes: vendorNotes } ) ) } );
				} else if ( 'sent' === q.status ) {
					req = api( '/admin/quotes/' + q.id + '/send', { method: 'PATCH', body: JSON.stringify( { vendor_notes: vendorNotes } ) } );
				} else {
					showError( new Error( 'Select a material or type a flat price first.' ) );
					return;
				}
				req.then( function () { showMsg( 'Priced and sent to customer.' ); load(); } ).catch( showError );
			} );

			on( '.drc-reject', function () {
				var reason = window.prompt( 'Reason for rejecting this quote (optional):', '' );
				if ( reason === null ) return;
				api( '/admin/quotes/' + q.id + '/reject', { method: 'PATCH', body: JSON.stringify( { reason: reason } ) } )
					.then( function () { showMsg( 'Quote rejected.' ); load(); } ).catch( showError );
			} );

			on( '.drc-accept', function () {
				if ( ! window.confirm( 'Create an order for this quote and email the customer a payment link?' ) ) return;
				api( '/admin/quotes/' + q.id + '/accept', { method: 'PATCH' } )
					.then( function ( r ) { showMsg( 'Order #' + r.order_id + ' created. Customer emailed a payment link.' ); load(); } )
					.catch( showError );
			} );

			on( '.drc-revise', function () {
				api( '/admin/quotes/' + q.id + '/revise', { method: 'POST' } )
					.then( function ( r ) { showMsg( 'Revised into new Quote #' + r.id + ' (draft).' ); load(); } ).catch( showError );
			} );

			detailTr.querySelectorAll( '.drc-email-inv' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var to = window.prompt( 'Send to:', q.customer_email || '' );
					if ( ! to ) return;
					btn.disabled = true;
					api( '/admin/quotes/' + q.id + '/send-email', { method: 'POST', body: JSON.stringify( { invoice_type: btn.dataset.type, recipient_email: to } ) } )
						.then( function ( r ) { showMsg( r.message ); } ).catch( showError ).finally( function () { btn.disabled = false; } );
				} );
			} );

			on( '.drc-sample-upload', function () {
				var input = detailTr.querySelector( '.drc-sample-file' );
				if ( ! input.files.length ) { showError( new Error( 'Choose an image first.' ) ); return; }
				var fd = new FormData();
				fd.append( 'file', input.files[0] );
				fetch( window.drcAdmin.root + '/admin/quotes/' + q.id + '/sample-images/upload', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-WP-Nonce': window.drcAdmin.nonce } } )
					.then( function ( r ) { return r.json().then( function ( d ) { if ( ! r.ok ) throw new Error( d.message || 'Upload failed' ); return d; } ); } )
					.then( function ( d ) { q.vendor_sample_image_urls = d.vendor_sample_image_urls; detailTr.querySelector( '.drc-samples' ).innerHTML = imageStrip( d.vendor_sample_image_urls ); input.value = ''; showMsg( 'Sample uploaded.' ); } )
					.catch( showError );
			} );

			on( '.drc-sample-clear', function () {
				if ( ! window.confirm( 'Remove all design samples from this quote?' ) ) return;
				api( '/admin/quotes/' + q.id + '/sample-images', { method: 'PATCH', body: JSON.stringify( { image_urls: [] } ) } )
					.then( function () { showMsg( 'Samples removed.' ); load(); } ).catch( showError );
			} );

			var statusSel = detailTr.querySelector( '.drc-status-select' );
			if ( statusSel ) {
				statusSel.addEventListener( 'change', function () {
					var to = statusSel.value;
					var msg = 'accepted' === to ? 'Mark accepted? This creates an order and emails the customer a payment link.' : ( 'rejected' === to ? 'Mark rejected? The customer is emailed.' : 'Change status to ' + STATUS_LABELS[ to ] + '?' );
					if ( ! window.confirm( msg ) ) { statusSel.value = q.status; return; }
					api( '/admin/quotes/' + q.id + '/status', { method: 'PATCH', body: JSON.stringify( { status: to } ) } )
						.then( function () { showMsg( 'Status updated.' ); load(); } ).catch( function ( e ) { statusSel.value = q.status; showError( e ); } );
				} );
			}

			on( '.drc-delete', function () {
				if ( ! window.confirm( 'Permanently delete quote #' + q.id + '?' ) ) return;
				api( '/admin/quotes/' + q.id, { method: 'DELETE' } )
					.then( function () { state.expanded = null; load(); } ).catch( showError );
			} );
		}

		function queryParams( extra ) {
			var params = new URLSearchParams( Object.assign( { page: state.page, page_size: 20 }, extra || {} ) );
			if ( 'all' !== state.status && ! ( extra && extra.status ) ) params.set( 'status', state.status );
			if ( state.search ) params.set( 'search', state.search.replace( /^#/, '' ) );
			if ( state.rush ) params.set( 'rush_order', '1' );
			if ( state.custom ) params.set( 'is_custom_request', '1' );
			if ( state.dateFrom ) params.set( 'date_from', state.dateFrom );
			if ( state.dateTo ) params.set( 'date_to', state.dateTo );
			return params;
		}

		function syncShell( counts ) {
			root.querySelectorAll( '.drc-tab' ).forEach( function ( btn ) {
				var s = btn.dataset.status;
				btn.classList.toggle( 'active', s === state.status );
				btn.style.display = 'pipeline' === state.view ? 'none' : '';
				btn.textContent = ( 'all' === s ? 'All' : STATUS_LABELS[ s ] ) + ' (' + ( counts[ s ] || 0 ) + ')';
			} );
			root.querySelectorAll( '.drc-view' ).forEach( function ( btn ) { btn.classList.toggle( 'button-primary', btn.dataset.view === state.view ); } );
		}

		function renderList( data, list ) {
			if ( ! data.items.length ) {
				list.innerHTML = '<p>No quotes in this view.</p>';
				return;
			}
			var table = el( '<table class="widefat striped"><thead><tr><th>ID</th><th>Customer</th><th>Rug</th><th>Qty</th><th>Price</th><th>Status</th><th>Created</th></tr></thead><tbody></tbody></table>' );
			var tbody = table.querySelector( 'tbody' );
			data.items.forEach( function ( q ) {
				var tr = el( '<tr class="drc-quote-row" style="cursor:pointer;"><td>#' + q.id + ( q.rush_order ? ' ⚡' : '' ) + '</td><td>' + esc( q.customer_name || '—' ) + '</td><td>' + esc( q.rug_name ) + '</td><td>' + q.qty + '</td><td>' + fmtMoney( q.final_price ) + '</td><td><span class="drc-badge drc-badge-' + q.status + '">' + q.status + '</span>' + ( q.review_request_count ? ' <span title="Review requests">🔔' + q.review_request_count + '</span>' : '' ) + '</td><td>' + esc( q.created_at ) + '</td></tr>' );
				tbody.appendChild( tr );
				var detailTr = document.createElement( 'tr' );
				var detailTd = document.createElement( 'td' );
				detailTd.colSpan = 7;
				detailTd.innerHTML = renderDetail( q );
				detailTr.appendChild( detailTd );
				detailTr.style.display = state.expanded === q.id ? '' : 'none';
				tbody.appendChild( detailTr );
				wireDetail( detailTd, q );
				tr.addEventListener( 'click', function () {
					var showing = detailTr.style.display !== 'none';
					Array.prototype.forEach.call( tbody.children, function ( child, idx ) { if ( idx % 2 === 1 ) child.style.display = 'none'; } );
					detailTr.style.display = showing ? 'none' : '';
					state.expanded = showing ? null : q.id;
				} );
			} );
			list.innerHTML = '';
			list.appendChild( table );

			var pager = root.querySelector( '#drc-quotes-pager' );
			pager.innerHTML = 'Page ' + data.page + ' of ' + Math.max( 1, data.pages ) + ' (' + data.total + ' total)' +
				( data.page > 1 ? ' <button type="button" class="button drc-prev">« Prev</button>' : '' ) +
				( data.page < data.pages ? ' <button type="button" class="button drc-next">Next »</button>' : '' );
			var prev = pager.querySelector( '.drc-prev' );
			if ( prev ) prev.addEventListener( 'click', function () { state.page--; load(); } );
			var next = pager.querySelector( '.drc-next' );
			if ( next ) next.addEventListener( 'click', function () { state.page++; load(); } );
		}

		/** Kanban: one column per status (the original's pipeline view). */
		function renderPipeline( columns, list ) {
			root.querySelector( '#drc-quotes-pager' ).innerHTML = '';
			var html = '<div style="display:grid;grid-template-columns:repeat(4,minmax(220px,1fr));gap:12px;align-items:start;">' +
				QUOTE_STATUSES.map( function ( s ) {
					var col = columns[ s ];
					var cards = col.items.map( function ( q ) {
						return '<div class="drc-card drc-pipe-card" data-id="' + q.id + '" style="cursor:pointer;margin-bottom:8px;">' +
							'<div style="display:flex;justify-content:space-between;"><strong>#' + q.id + ( q.rush_order ? ' ⚡' : '' ) + '</strong><span>' + fmtMoney( q.final_price ) + '</span></div>' +
							'<div>' + esc( q.customer_name || '—' ) + '</div>' +
							'<div style="color:#646970;font-size:12px;">' + esc( q.rug_name ) + ' · ' + esc( q.size_display || '' ) + ' · ×' + q.qty + '</div>' +
							'<div style="color:#646970;font-size:12px;">' + esc( q.created_at ) + ( q.review_request_count ? ' · 🔔' + q.review_request_count : '' ) + '</div>' +
							'</div>';
					} ).join( '' ) || '<p style="color:#646970;">Nothing here.</p>';
					return '<div><h3 style="margin:0 0 8px;">' + STATUS_LABELS[ s ] + ' <span style="color:#646970;font-weight:400;">(' + col.total + ')</span></h3>' + cards +
						( col.total > col.items.length ? '<p style="color:#646970;font-size:12px;">Showing ' + col.items.length + ' of ' + col.total + ' — use List view to see all.</p>' : '' ) + '</div>';
				} ).join( '' ) + '</div><div id="drc-pipe-detail" style="margin-top:16px;"></div>';
			list.innerHTML = html;
			var all = [];
			QUOTE_STATUSES.forEach( function ( s ) { all = all.concat( columns[ s ].items ); } );
			list.querySelectorAll( '.drc-pipe-card' ).forEach( function ( card ) {
				card.addEventListener( 'click', function () {
					var q = all.find( function ( x ) { return x.id === parseInt( card.dataset.id, 10 ); } );
					var box = list.querySelector( '#drc-pipe-detail' );
					box.innerHTML = '<div class="drc-card"><h3 style="margin-top:0;">Quote #' + q.id + '</h3>' + renderDetail( q ) + '</div>';
					wireDetail( box, q );
					box.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				} );
			} );
		}

		function load() {
			var list = root.querySelector( '#drc-quotes-list' );
			fetchMaterials().then( function () {
				if ( 'pipeline' === state.view ) {
					return Promise.all( QUOTE_STATUSES.map( function ( s ) {
						return api( '/admin/quotes?' + queryParams( { status: s, page: 1, page_size: 50 } ) );
					} ) ).then( function ( results ) {
						var columns = {};
						QUOTE_STATUSES.forEach( function ( s, i ) { columns[ s ] = { items: results[ i ].items, total: results[ i ].total }; } );
						syncShell( results[0].status_counts );
						renderPipeline( columns, list );
					} );
				}
				return api( '/admin/quotes?' + queryParams() ).then( function ( data ) {
					syncShell( data.status_counts );
					renderList( data, list );
				} );
			} ).catch( function ( e ) {
				list.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>';
			} );
		}

		renderShell();
		load();
	}

	// ── Quote Builder ─────────────────────────────────────────────────────
	// Ports QuoteBuilder.tsx: pick a rug/size/material/qty, calculate with
	// the live engine, choose or add a customer, then save as a draft or
	// save & send.

	function initQuoteBuilder() {
		var root = document.getElementById( 'drc-quote-builder-root' );
		if ( ! root ) return;
		var state = { rugs: [], materials: [], result: null, customer: null };

		Promise.all( [ api( '/admin/catalog/rugs' ), api( '/admin/materials' ) ] ).then( function ( r ) {
			state.rugs = r[0];
			state.materials = r[1];
			render();
		} ).catch( function ( e ) { root.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; } );

		function render() {
			root.innerHTML =
				'<div style="display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,1fr);gap:24px;align-items:start;">' +
				'<div class="drc-card"><h2 style="margin-top:0;">1. Rug &amp; size</h2>' +
				'<table class="form-table"><tbody>' +
				'<tr><th>Rug</th><td><select id="qb-rug" style="min-width:260px;"><option value="">Select a rug…</option>' + state.rugs.map( function ( r ) { return '<option value="' + r.id + '">' + esc( r.name ) + '</option>'; } ).join( '' ) + '</select></td></tr>' +
				'<tr><th>Catalog size</th><td><select id="qb-size"><option value="">Custom size</option></select></td></tr>' +
				'<tr><th>Width × Length</th><td><input id="qb-w" type="number" step="0.1" min="0.1" style="width:90px;"> × <input id="qb-h" type="number" step="0.1" min="0.1" style="width:90px;"> <select id="qb-unit"><option value="ft">ft</option><option value="cm">cm</option><option value="m">m</option></select></td></tr>' +
				'<tr><th>Shape</th><td><select id="qb-shape"><option value="rect">Rectangle</option><option value="circle">Circle</option><option value="oval">Oval</option></select></td></tr>' +
				'<tr><th>Material</th><td><select id="qb-material"><option value="">Select material…</option>' + state.materials.map( function ( m ) { return '<option value="' + m.id + '">' + esc( m.name ) + ' (' + esc( m.type ) + ', ' + esc( m.color ) + ')</option>'; } ).join( '' ) + '</select></td></tr>' +
				'<tr><th>Colour</th><td><select id="qb-color"><option value="">—</option></select></td></tr>' +
				'<tr><th>Quantity</th><td><input id="qb-qty" type="number" min="1" value="1" style="width:90px;"></td></tr>' +
				'<tr><th>Rush order</th><td><label><input id="qb-rush" type="checkbox"> Early delivery (+rush fee)</label></td></tr>' +
				'<tr><th>Discount %</th><td><input id="qb-discount" type="number" step="0.1" min="0" max="100" style="width:90px;"></td></tr>' +
				'</tbody></table>' +
				'<p><button type="button" class="button button-primary" id="qb-calc">Calculate price</button></p>' +
				'<div id="qb-result"></div></div>' +
				'<div class="drc-card"><h2 style="margin-top:0;">2. Customer</h2>' +
				'<p><input type="search" id="qb-cust-search" placeholder="Search customers by name or email" style="width:100%;"></p>' +
				'<div id="qb-cust-results"></div>' +
				'<p id="qb-cust-picked" style="font-weight:600;"></p>' +
				'<p style="margin-top:16px;"><strong>…or a new customer</strong></p>' +
				'<p><input id="qb-new-name" placeholder="Name" style="width:100%;margin-bottom:6px;"><input id="qb-new-email" type="email" placeholder="Email" style="width:100%;margin-bottom:6px;"><input id="qb-new-phone" placeholder="Phone / WhatsApp" style="width:100%;"></p>' +
				'<h2>3. Send</h2>' +
				'<p><label>Expected delivery (days)<br><input id="qb-days" type="number" min="1" style="width:120px;"></label></p>' +
				'<p><label>Note to customer<br><textarea id="qb-notes" rows="3" style="width:100%;"></textarea></label></p>' +
				'<p><button type="button" class="button" id="qb-save">Save as draft</button> <button type="button" class="button button-primary" id="qb-send">Save &amp; send to customer</button></p>' +
				'<div id="qb-msg"></div></div>' +
				'</div>';
			wire();
		}

		function $( id ) { return root.querySelector( '#' + id ); }
		function toMetres( v, unit ) { v = parseFloat( v ); return 'cm' === unit ? v / 100 : ( 'm' === unit ? v : v * 0.3048 ); }

		function wire() {
			$( 'qb-rug' ).addEventListener( 'change', function () {
				var rug = state.rugs.find( function ( r ) { return r.id === parseInt( $( 'qb-rug' ).value, 10 ); } );
				$( 'qb-size' ).innerHTML = '<option value="">Custom size</option>' + ( rug ? rug.sizes.map( function ( sz, i ) { return '<option value="' + i + '">' + esc( sz.ft ? sz.ft + ' ft' : '' ) + ( sz.cm ? ' (' + esc( sz.cm ) + ' cm)' : '' ) + '</option>'; } ).join( '' ) : '' );
				$( 'qb-color' ).innerHTML = '<option value="">—</option>' + ( rug ? rug.colors.map( function ( c ) { return '<option>' + esc( c ) + '</option>'; } ).join( '' ) : '' );
				if ( rug && rug.material_id ) $( 'qb-material' ).value = rug.material_id;
				state.result = null; $( 'qb-result' ).innerHTML = '';
			} );
			$( 'qb-size' ).addEventListener( 'change', function () {
				var rug = state.rugs.find( function ( r ) { return r.id === parseInt( $( 'qb-rug' ).value, 10 ); } );
				var sz = rug && '' !== $( 'qb-size' ).value ? rug.sizes[ parseInt( $( 'qb-size' ).value, 10 ) ] : null;
				if ( ! sz ) return;
				var ft = ( sz.ft || '' ).split( /[x×]/ ), cm = ( sz.cm || '' ).split( /[x×]/ );
				if ( 2 === ft.length ) { $( 'qb-w' ).value = ft[0].trim(); $( 'qb-h' ).value = ft[1].trim(); $( 'qb-unit' ).value = 'ft'; }
				else if ( 2 === cm.length ) { $( 'qb-w' ).value = cm[0].trim(); $( 'qb-h' ).value = cm[1].trim(); $( 'qb-unit' ).value = 'cm'; }
			} );

			$( 'qb-calc' ).addEventListener( 'click', function () {
				var out = $( 'qb-result' );
				if ( ! $( 'qb-rug' ).value || ! $( 'qb-w' ).value || ! $( 'qb-h' ).value || ! $( 'qb-material' ).value ) {
					out.innerHTML = '<p class="drc-error">Pick a rug, size and material first.</p>';
					return;
				}
				var unit = $( 'qb-unit' ).value;
				api( '/admin/quotes/calculate', { method: 'POST', body: JSON.stringify( {
					product_id: parseInt( $( 'qb-rug' ).value, 10 ),
					size_w: toMetres( $( 'qb-w' ).value, unit ),
					size_h: toMetres( $( 'qb-h' ).value, unit ),
					material_id: parseInt( $( 'qb-material' ).value, 10 ),
					qty: parseInt( $( 'qb-qty' ).value, 10 ) || 1,
					rush_order: $( 'qb-rush' ).checked,
					manual_discount_pct: $( 'qb-discount' ).value === '' ? null : parseFloat( $( 'qb-discount' ).value ),
					shape: $( 'qb-shape' ).value,
				} ) } ).then( function ( r ) {
					state.result = r;
					state.result._w = toMetres( $( 'qb-w' ).value, unit );
					state.result._h = toMetres( $( 'qb-h' ).value, unit );
					$( 'qb-days' ).value = r.estimated_days;
					var lines = ( r.breakdown || [] ).map( function ( b ) { return '<tr><td>' + esc( b.label || b.rule ) + '</td><td style="text-align:right;">' + fmtMoney( b.amount ) + '</td></tr>'; } ).join( '' );
					out.innerHTML = '<table class="widefat striped"><tbody>' + lines +
						'<tr><td><strong>Total</strong></td><td style="text-align:right;"><strong>' + fmtMoney( r.final_price ) + '</strong></td></tr></tbody></table>' +
						'<p style="color:#646970;">' + esc( r.material_message || '' ) + ' · ' + esc( r.moq_message || '' ) + ' · ~' + r.estimated_days + ' days' + ( r.rush_available ? '' : ' (rush would not save time)' ) + '</p>';
				} ).catch( function ( e ) { out.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; } );
			} );

			var debounce;
			$( 'qb-cust-search' ).addEventListener( 'input', function () {
				clearTimeout( debounce );
				debounce = setTimeout( function () {
					var term = $( 'qb-cust-search' ).value.trim();
					if ( term.length < 2 ) { $( 'qb-cust-results' ).innerHTML = ''; return; }
					api( '/admin/customers/search?search=' + encodeURIComponent( term ) ).then( function ( list ) {
						$( 'qb-cust-results' ).innerHTML = list.length ? list.map( function ( c ) {
							return '<button type="button" class="button-link qb-pick" data-id="' + c.id + '" style="display:block;margin:4px 0;">' + esc( c.name ) + ' — ' + esc( c.email ) + '</button>';
						} ).join( '' ) : '<p style="color:#646970;">No match — add them as a new customer below.</p>';
						$( 'qb-cust-results' ).querySelectorAll( '.qb-pick' ).forEach( function ( b ) {
							b.addEventListener( 'click', function () {
								state.customer = list.find( function ( c ) { return c.id === parseInt( b.dataset.id, 10 ); } );
								$( 'qb-cust-picked' ).textContent = 'Selected: ' + state.customer.name + ' (' + state.customer.email + ')';
								$( 'qb-cust-results' ).innerHTML = '';
							} );
						} );
					} );
				}, 300 );
			} );

			function save( send ) {
				var msg = $( 'qb-msg' );
				var r = state.result;
				if ( ! r ) { msg.innerHTML = '<p class="drc-error">Calculate a price first.</p>'; return; }
				var body = {
					product_id: parseInt( $( 'qb-rug' ).value, 10 ),
					material_id: parseInt( $( 'qb-material' ).value, 10 ),
					custom_size_w: r._w, custom_size_h: r._h,
					rug_shape: $( 'qb-shape' ).value,
					qty: parseInt( $( 'qb-qty' ).value, 10 ) || 1,
					rush_order: $( 'qb-rush' ).checked,
					selected_color: $( 'qb-color' ).value || null,
					manual_discount_pct: $( 'qb-discount' ).value === '' ? null : parseFloat( $( 'qb-discount' ).value ),
					base_price: r.subtotal, final_price: r.final_price, margin_pct: r.profit_margin_pct, gst_pct: r.gst_pct,
					expected_delivery_days: parseInt( $( 'qb-days' ).value, 10 ) || r.estimated_days,
					vendor_notes: $( 'qb-notes' ).value,
					send: send,
				};
				if ( state.customer ) {
					body.customer_user_id = state.customer.id;
				} else {
					body.customer_name = $( 'qb-new-name' ).value.trim();
					body.customer_email = $( 'qb-new-email' ).value.trim();
					body.customer_phone = $( 'qb-new-phone' ).value.trim();
				}
				api( '/admin/quotes/create', { method: 'POST', body: JSON.stringify( body ) } ).then( function ( q ) {
					msg.innerHTML = '<p class="drc-msg">Quote #' + q.id + ( send ? ' sent to the customer.' : ' saved as a draft.' ) + ' <a href="admin.php?page=dreamrugs-quotes&quote=' + q.id + '">Open it</a></p>';
				} ).catch( function ( e ) { msg.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; } );
			}
			$( 'qb-save' ).addEventListener( 'click', function () { save( false ); } );
			$( 'qb-send' ).addEventListener( 'click', function () { save( true ); } );
		}
	}

	// ── Email Templates ───────────────────────────────────────────────────

	function initEmailTemplates() {
		var root = document.getElementById( 'drc-email-templates-root' );
		if ( ! root ) return;

		function load() {
			api( '/admin/email-templates' ).then( function ( list ) {
				root.innerHTML = '<p style="color:#646970;">Edit the emails customers and your team receive. Use <code>{{placeholders}}</code> exactly as listed; leave the HTML body empty to send plain text. WooCommerce\'s own order emails are under WooCommerce → Settings → Emails.</p>' +
					list.map( function ( t ) {
						return '<details class="drc-card" data-key="' + t.key + '" style="margin-bottom:10px;"><summary style="cursor:pointer;font-weight:600;">' + esc( t.name ) + ( t.is_modified ? ' <span class="drc-badge drc-badge-sent">customised</span>' : '' ) + ( t.is_active ? '' : ' <span class="drc-badge drc-badge-rejected">using default</span>' ) + '</summary>' +
							'<p style="color:#646970;font-size:12px;">Placeholders: ' + t.placeholders.map( function ( p ) { return '<code>{{' + esc( p ) + '}}</code>'; } ).join( ' ' ) + '</p>' +
							'<p><label>Subject<br><input class="et-subject" value="' + esc( t.subject ) + '" style="width:100%;"></label></p>' +
							'<p><label>Plain-text body<br><textarea class="et-text" rows="8" style="width:100%;font-family:monospace;font-size:12px;">' + esc( t.body_text ) + '</textarea></label></p>' +
							'<p><label>HTML body (optional)<br><textarea class="et-html" rows="8" style="width:100%;font-family:monospace;font-size:12px;">' + esc( t.body_html ) + '</textarea></label></p>' +
							'<p><label><input type="checkbox" class="et-active"' + ( t.is_active ? ' checked' : '' ) + '> Use this version (unchecked = send the built-in default)</label></p>' +
							'<p><button type="button" class="button button-primary et-save">Save</button> <button type="button" class="button et-test">Send me a test</button> <button type="button" class="button-link-delete et-reset">Reset to default</button></p>' +
							'<div class="et-msg"></div></details>';
					} ).join( '' );
				root.querySelectorAll( 'details[data-key]' ).forEach( function ( box ) {
					var key = box.dataset.key, msg = box.querySelector( '.et-msg' );
					function ok( m ) { msg.innerHTML = '<p class="drc-msg">' + esc( m ) + '</p>'; }
					function bad( e ) { msg.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; }
					box.querySelector( '.et-save' ).addEventListener( 'click', function () {
						api( '/admin/email-templates/' + key, { method: 'PUT', body: JSON.stringify( {
							subject: box.querySelector( '.et-subject' ).value,
							body_text: box.querySelector( '.et-text' ).value,
							body_html: box.querySelector( '.et-html' ).value,
							is_active: box.querySelector( '.et-active' ).checked,
						} ) } ).then( function () { ok( 'Saved.' ); } ).catch( bad );
					} );
					box.querySelector( '.et-test' ).addEventListener( 'click', function () {
						api( '/admin/email-templates/' + key + '/test', { method: 'POST' } ).then( function ( r ) { ok( r.message ); } ).catch( bad );
					} );
					box.querySelector( '.et-reset' ).addEventListener( 'click', function () {
						if ( ! window.confirm( 'Discard your changes to this email and go back to the default?' ) ) return;
						api( '/admin/email-templates/' + key + '/reset', { method: 'POST' } ).then( load ).catch( bad );
					} );
				} );
			} ).catch( function ( e ) { root.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; } );
		}
		load();
	}

	// ── Newsletter ────────────────────────────────────────────────────────

	function initNewsletter() {
		var root = document.getElementById( 'drc-newsletter-root' );
		if ( ! root ) return;
		function load() {
			api( '/admin/newsletter' ).then( function ( rows ) {
				root.innerHTML = '<p><a class="button" href="' + esc( restUrl( '/admin/newsletter/export' ) ) + '">Export CSV</a> <span style="color:#646970;margin-left:8px;">' + rows.length + ' subscriber' + ( 1 === rows.length ? '' : 's' ) + '</span></p>' +
					'<table class="widefat striped"><thead><tr><th>Email</th><th>Source</th><th>Subscribed</th><th></th></tr></thead><tbody>' +
					( rows.map( function ( r ) {
						return '<tr><td><a href="mailto:' + esc( r.email ) + '">' + esc( r.email ) + '</a></td><td>' + esc( r.source || '—' ) + '</td><td>' + esc( r.subscribed_at ) + '</td><td><button type="button" class="button-link-delete nl-del" data-email="' + esc( r.email ) + '">Remove</button></td></tr>';
					} ).join( '' ) || '<tr><td colspan="4">No subscribers yet.</td></tr>' ) +
					'</tbody></table>';
				root.querySelectorAll( '.nl-del' ).forEach( function ( b ) {
					b.addEventListener( 'click', function () {
						if ( ! window.confirm( 'Remove ' + b.dataset.email + '?' ) ) return;
						api( '/admin/newsletter?email=' + encodeURIComponent( b.dataset.email ), { method: 'DELETE' } ).then( load );
					} );
				} );
			} ).catch( function ( e ) { root.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; } );
		}
		load();
	}

	// ── Catalog Masters ───────────────────────────────────────────────────

	function initCatalogMasters() {
		var root = document.getElementById( 'drc-catalog-masters-root' );
		if ( ! root ) return;
		var TYPES = [ [ 'weaves', 'Weave types', 'Shown in the "Shop by Weave Type" menu (inactive ones are hidden) and offered on each rug.' ], [ 'piles', 'Pile heights', 'Offered on each rug and as a catalog filter.' ], [ 'sizes', 'Common sizes', 'Suggested when adding a size (variation) to a rug. cm is never calculated — enter it if you sell that size in cm.' ] ];
		root.innerHTML = '<p>Spaces, moods and materials are edited under <a href="edit-tags.php?taxonomy=room_type&post_type=product">Products → Room Types</a>, <a href="edit-tags.php?taxonomy=mood_tag&post_type=product">Mood Tags</a> and <a href="edit-tags.php?taxonomy=material_type&post_type=product">Material</a>.</p>' +
			TYPES.map( function ( t ) { return '<div class="drc-card" data-master="' + t[0] + '" style="margin-bottom:16px;"><h2 style="margin-top:0;">' + t[1] + '</h2><p style="color:#646970;">' + t[2] + '</p><div class="drc-master-body">Loading…</div></div>'; } ).join( '' ) +
			'<div class="drc-card"><h2 style="margin-top:0;">Collection page images</h2><p style="color:#646970;">Up to 3 images with captions at the top of each collection page. Empty slots are filled automatically from rugs in that collection, then the "All rugs" set, then workshop photos.</p><div id="drc-displays">Loading…</div></div>';

		TYPES.forEach( function ( t ) {
			var card = root.querySelector( '[data-master="' + t[0] + '"]' ), body = card.querySelector( '.drc-master-body' );
			var isSize = 'sizes' === t[0];
			function row( r ) {
				return '<tr>' + ( isSize
					? '<td><input class="m-ft" value="' + esc( r.ft || '' ) + '" placeholder="6x9" style="width:100%;"></td><td><input class="m-cm" value="' + esc( r.cm || '' ) + '" placeholder="180x270" style="width:100%;"></td>'
					: '<td><input class="m-name" value="' + esc( r.name || '' ) + '" style="width:100%;"></td>' ) +
					'<td style="width:90px;"><label><input type="checkbox" class="m-active"' + ( false === r.active ? '' : ' checked' ) + '> Active</label></td>' +
					'<td style="white-space:nowrap;width:150px;"><button type="button" class="button button-small m-up">&uarr;</button> <button type="button" class="button button-small m-down">&darr;</button> <button type="button" class="button-link-delete m-del">Remove</button></td></tr>';
			}
			function wireRow( tr ) {
				var tb = tr.parentNode;
				tr.querySelector( '.m-up' ).addEventListener( 'click', function () { if ( tr.previousElementSibling ) tb.insertBefore( tr, tr.previousElementSibling ); } );
				tr.querySelector( '.m-down' ).addEventListener( 'click', function () { if ( tr.nextElementSibling ) tb.insertBefore( tr.nextElementSibling, tr ); } );
				tr.querySelector( '.m-del' ).addEventListener( 'click', function () { tr.remove(); } );
			}
			api( '/admin/masters/' + t[0] ).then( function ( rows ) {
				body.innerHTML = '<table class="widefat striped" style="max-width:720px;"><thead><tr>' + ( isSize ? '<th>ft</th><th>cm (optional)</th>' : '<th>Name</th>' ) + '<th></th><th></th></tr></thead><tbody>' + rows.map( row ).join( '' ) + '</tbody></table>' +
					'<p><button type="button" class="button m-add">+ Add</button> <button type="button" class="button button-primary m-save">Save</button> <span class="m-msg"></span></p>';
				var tb = body.querySelector( 'tbody' );
				tb.querySelectorAll( 'tr' ).forEach( wireRow );
				body.querySelector( '.m-add' ).addEventListener( 'click', function () { var w = document.createElement( 'tbody' ); w.innerHTML = row( {} ); var tr = w.firstElementChild; tb.appendChild( tr ); wireRow( tr ); } );
				body.querySelector( '.m-save' ).addEventListener( 'click', function () {
					var out = Array.from( tb.querySelectorAll( 'tr' ) ).map( function ( tr ) {
						var r = { active: tr.querySelector( '.m-active' ).checked };
						if ( isSize ) { r.ft = tr.querySelector( '.m-ft' ).value; r.cm = tr.querySelector( '.m-cm' ).value; } else { r.name = tr.querySelector( '.m-name' ).value; }
						return r;
					} );
					api( '/admin/masters/' + t[0], { method: 'PUT', body: JSON.stringify( { rows: out } ) } )
						.then( function () { body.querySelector( '.m-msg' ).innerHTML = '<span class="drc-msg">Saved.</span>'; } )
						.catch( function ( e ) { body.querySelector( '.m-msg' ).innerHTML = '<span class="drc-error">' + esc( e.message ) + '</span>'; } );
				} );
			} );
		} );

		var box = root.querySelector( '#drc-displays' );
		api( '/admin/collection-displays' ).then( function ( pages ) {
			if ( ! pages.length ) { box.innerHTML = '<p>No collection pages yet — add room types, moods, materials or weaves first.</p>'; return; }
			box.innerHTML = pages.map( function ( pg ) {
				var slots = [ 0, 1, 2 ].map( function ( i ) {
					var img = pg.images[ i ] || {};
					return '<td><div class="drc-repeater-image"><div class="drc-image-preview" style="margin-bottom:6px;"></div><input type="hidden" data-image-value value="' + esc( img.image_url || '' ) + '"><button type="button" class="button button-small drc-image-choose">Choose</button> <button type="button" class="button-link-delete drc-image-remove">Remove</button></div><input class="d-cap" placeholder="Caption" value="' + esc( img.caption || '' ) + '" style="width:100%;margin-top:4px;"></td>';
				} ).join( '' );
				return '<details data-key="' + esc( pg.key ) + '" style="margin-bottom:6px;"><summary style="cursor:pointer;"><strong>' + esc( pg.label ) + '</strong> <span style="color:#646970;">(' + esc( pg.facet ) + ' · ' + pg.images.length + ' image' + ( 1 === pg.images.length ? '' : 's' ) + ')</span></summary>' +
					'<table class="widefat" style="max-width:900px;margin:8px 0;"><tr>' + slots + '</tr></table><p><button type="button" class="button button-primary d-save">Save</button> <span class="d-msg"></span></p></details>';
			} ).join( '' );
			box.querySelectorAll( 'details' ).forEach( function ( d ) {
				d.querySelectorAll( '.drc-repeater-image' ).forEach( function ( c ) { wireImagePicker( c, { emptyText: '<p style="color:#646970;font-size:12px;">No image</p>' } ); } );
				d.querySelector( '.d-save' ).addEventListener( 'click', function () {
					var images = Array.from( d.querySelectorAll( 'td' ) ).map( function ( td ) { return { image_url: td.querySelector( '[data-image-value]' ).value, caption: td.querySelector( '.d-cap' ).value }; } );
					api( '/admin/collection-displays', { method: 'PUT', body: JSON.stringify( { key: d.dataset.key, images: images } ) } )
						.then( function () { d.querySelector( '.d-msg' ).innerHTML = '<span class="drc-msg">Saved.</span>'; } )
						.catch( function ( e ) { d.querySelector( '.d-msg' ).innerHTML = '<span class="drc-error">' + esc( e.message ) + '</span>'; } );
				} );
			} );
		} ).catch( function ( e ) { box.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; } );
	}

	// ── Website Menu ──────────────────────────────────────────────────────
	// One row per nav item, mega-menu heading and link: show/hide + rename
	// (storefront_menu_visibility / storefront_menu_labels).

	function initWebsiteMenu() {
		var root = document.getElementById( 'drc-website-menu-root' );
		if ( ! root ) return;
		api( '/admin/menu-structure' ).then( function ( m ) {
			function row( key, def, indent ) {
				var hidden = m.visibility[ key ] === false;
				return '<tr><td style="padding-left:' + ( 10 + indent * 24 ) + 'px;">' + esc( def ) + '<br><code style="font-size:11px;color:#646970;">' + esc( key ) + '</code></td>' +
					'<td><input data-label="' + esc( key ) + '" value="' + esc( m.labels[ key ] || '' ) + '" placeholder="' + esc( def ) + '" style="width:100%;"></td>' +
					'<td style="width:80px;text-align:center;"><input type="checkbox" data-show="' + esc( key ) + '"' + ( hidden ? '' : ' checked' ) + '></td></tr>';
			}
			var rows = m.nav.map( function ( n ) { return row( n.key, n.default, 0 ); } ).join( '' );
			Object.keys( m.mega ).forEach( function ( g ) {
				var group = m.mega[ g ];
				rows += row( group.key, group.default + ' (mega menu heading)', 1 );
				group.links.forEach( function ( l ) { rows += row( l.key, l.default, 2 ); } );
			} );
			root.innerHTML = '<p style="color:#646970;">Rename or hide anything in the header menu. Leave a label empty to use the default. The footer uses the same link labels.</p>' +
				'<table class="widefat striped" style="max-width:900px;"><thead><tr><th>Menu item</th><th>Custom label</th><th>Show</th></tr></thead><tbody>' + rows + '</tbody></table>' +
				'<p><button type="button" class="button button-primary" id="drc-menu-save">Save menu</button> <span id="drc-menu-msg"></span></p>';
			root.querySelector( '#drc-menu-save' ).addEventListener( 'click', function () {
				var labels = {}, visibility = {};
				root.querySelectorAll( '[data-label]' ).forEach( function ( i ) { if ( i.value.trim() ) labels[ i.dataset.label ] = i.value.trim(); } );
				root.querySelectorAll( '[data-show]' ).forEach( function ( c ) { if ( ! c.checked ) visibility[ c.dataset.show ] = false; } );
				api( '/admin/settings', { method: 'PUT', body: JSON.stringify( { storefront_menu_labels: labels, storefront_menu_visibility: visibility } ) } )
					.then( function () { root.querySelector( '#drc-menu-msg' ).innerHTML = '<span class="drc-msg">Saved.</span>'; } )
					.catch( function ( e ) { root.querySelector( '#drc-menu-msg' ).innerHTML = '<span class="drc-error">' + esc( e.message ) + '</span>'; } );
			} );
		} ).catch( function ( e ) { root.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; } );
	}

	// ── Inventory ─────────────────────────────────────────────────────────

	function initInventory() {
		var root = document.getElementById( 'drc-inventory-root' );
		if ( ! root ) return;

		function load() {
			api( '/admin/materials' ).then( function ( materials ) {
				var rows = materials.map( function ( m ) {
					return '<tr data-id="' + m.id + '">' +
						'<td><input class="drc-m-name" value="' + esc( m.name ) + '" style="width:100%;"></td>' +
						'<td><input class="drc-m-type" value="' + esc( m.type ) + '" style="width:100%;"></td>' +
						'<td><input class="drc-m-color" value="' + esc( m.color ) + '" style="width:100%;"></td>' +
						'<td><input class="drc-m-stock" type="number" step="0.1" value="' + m.stock_meters + '" style="width:90px;"></td>' +
						'<td><input class="drc-m-cost" type="number" step="0.01" value="' + m.cost_per_sqm + '" style="width:90px;"></td>' +
						'<td><input class="drc-m-avail" type="checkbox"' + ( m.is_available ? ' checked' : '' ) + '></td>' +
						'<td>' +
						'<button type="button" class="button drc-m-save">Save</button> ' +
						'<button type="button" class="button drc-m-restock">Restock</button> ' +
						'<button type="button" class="button drc-m-delete">Delete</button>' +
						'</td></tr>';
				} ).join( '' );

				root.innerHTML =
					'<h2>Add Material</h2>' +
					'<div class="drc-field-row">' +
					'<label>Name<input id="drc-new-name"></label>' +
					'<label>Type<input id="drc-new-type"></label>' +
					'<label>Color<input id="drc-new-color"></label>' +
					'<label>Stock (m)<input id="drc-new-stock" type="number" step="0.1" value="0"></label>' +
					'<label>Cost/sqm<input id="drc-new-cost" type="number" step="0.01" value="0"></label>' +
					'<label>&nbsp;<button type="button" class="button button-primary" id="drc-new-add">Add Material</button></label>' +
					'</div>' +
					'<div class="drc-msg-box"></div>' +
					'<h2>Materials</h2>' +
					'<table class="widefat striped"><thead><tr><th>Name</th><th>Type</th><th>Color</th><th>Stock (m)</th><th>Cost/sqm</th><th>Available</th><th>Actions</th></tr></thead><tbody>' + ( rows || '<tr><td colspan="7">No materials yet.</td></tr>' ) + '</tbody></table>';

				var msgBox = root.querySelector( '.drc-msg-box' );
				function showError( e ) { msgBox.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; }
				function showMsg( m ) { msgBox.innerHTML = '<p class="drc-msg">' + esc( m ) + '</p>'; }

				root.querySelector( '#drc-new-add' ).addEventListener( 'click', function () {
					var body = {
						name: root.querySelector( '#drc-new-name' ).value,
						type: root.querySelector( '#drc-new-type' ).value,
						color: root.querySelector( '#drc-new-color' ).value,
						stock_meters: parseFloat( root.querySelector( '#drc-new-stock' ).value || '0' ),
						cost_per_sqm: parseFloat( root.querySelector( '#drc-new-cost' ).value || '0' ),
					};
					if ( ! body.name || ! body.type || ! body.color ) { showError( new Error( 'Name, type, and color are required.' ) ); return; }
					api( '/admin/materials', { method: 'POST', body: JSON.stringify( body ) } )
						.then( function () { showMsg( 'Material added.' ); load(); } ).catch( showError );
				} );

				root.querySelectorAll( 'tbody tr[data-id]' ).forEach( function ( tr ) {
					var id = tr.dataset.id;
					tr.querySelector( '.drc-m-save' ).addEventListener( 'click', function () {
						var body = {
							name: tr.querySelector( '.drc-m-name' ).value,
							type: tr.querySelector( '.drc-m-type' ).value,
							color: tr.querySelector( '.drc-m-color' ).value,
							stock_meters: parseFloat( tr.querySelector( '.drc-m-stock' ).value ),
							cost_per_sqm: parseFloat( tr.querySelector( '.drc-m-cost' ).value ),
							is_available: tr.querySelector( '.drc-m-avail' ).checked,
						};
						api( '/admin/materials/' + id, { method: 'PUT', body: JSON.stringify( body ) } )
							.then( function () { showMsg( 'Material #' + id + ' saved.' ); } ).catch( showError );
					} );
					tr.querySelector( '.drc-m-restock' ).addEventListener( 'click', function () {
						var qty = window.prompt( 'How many meters to add to stock?' );
						if ( ! qty ) return;
						api( '/admin/materials/' + id + '/restock', { method: 'POST', body: JSON.stringify( { qty_meters: parseFloat( qty ) } ) } )
							.then( function () { showMsg( 'Restocked.' ); load(); } ).catch( showError );
					} );
					tr.querySelector( '.drc-m-delete' ).addEventListener( 'click', function () {
						if ( ! window.confirm( 'Delete this material?' ) ) return;
						api( '/admin/materials/' + id, { method: 'DELETE' } )
							.then( function () { showMsg( 'Deleted.' ); load(); } ).catch( showError );
					} );
				} );
			} ).catch( function ( e ) {
				root.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>';
			} );
		}

		load();
	}

	// ── Pricing / MOQ / Production-timeline rules ───────────────────────

	var RULE_TABS = {
		'pricing-rules': {
			label: 'Pricing Rules',
			cols: [ [ 'name', 'text', 'Name' ], [ 'rule_type', 'text', 'Type' ], [ 'min_qty', 'number', 'Min Qty' ], [ 'max_qty', 'number', 'Max Qty' ], [ 'multiplier', 'number', 'Multiplier' ], [ 'flat_fee', 'number', 'Flat Fee' ], [ 'description', 'text', 'Description' ] ],
		},
		'moq-rules': {
			label: 'MOQ Rules',
			cols: [ [ 'rug_type', 'text', 'Rug Type' ], [ 'minimum_sqm', 'number', 'Min Sqm' ], [ 'minimum_pieces', 'number', 'Min Pieces' ], [ 'notes', 'text', 'Notes' ] ],
		},
		'production-timelines': {
			label: 'Production Timelines',
			cols: [ [ 'order_type', 'text', 'Order Type' ], [ 'base_days', 'number', 'Base Days' ], [ 'complexity_multiplier_per_sqm', 'number', 'Complexity ×/sqm' ], [ 'notes', 'text', 'Notes' ] ],
		},
	};

	function initPricingRules() {
		var root = document.getElementById( 'drc-pricing-rules-root' );
		if ( ! root ) return;

		var activeTab = 'pricing-rules';

		function renderTabs() {
			return '<div class="drc-tabs">' + Object.keys( RULE_TABS ).map( function ( key ) {
				return '<button type="button" class="drc-tab' + ( key === activeTab ? ' active' : '' ) + '" data-tab="' + key + '">' + RULE_TABS[ key ].label + '</button>';
			} ).join( '' ) + '</div>';
		}

		function load() {
			var config = RULE_TABS[ activeTab ];
			api( '/admin/' + activeTab ).then( function ( rows ) {
				var headCols = config.cols.map( function ( c ) { return '<th>' + esc( c[2] ) + '</th>'; } ).join( '' );
				var bodyRows = rows.map( function ( row ) {
					var cells = config.cols.map( function ( c ) {
						return '<td><input class="drc-rule-field" data-col="' + c[0] + '" type="' + c[1] + '" value="' + esc( row[ c[0] ] === null ? '' : row[ c[0] ] ) + '" style="width:100%;"></td>';
					} ).join( '' );
					return '<tr data-id="' + row.id + '">' + cells + '<td><button type="button" class="button drc-rule-save">Save</button> <button type="button" class="button drc-rule-delete">Delete</button></td></tr>';
				} ).join( '' );
				var newCells = config.cols.map( function ( c ) {
					return '<td><input class="drc-rule-new-field" data-col="' + c[0] + '" type="' + c[1] + '" style="width:100%;"></td>';
				} ).join( '' );

				root.innerHTML = renderTabs() +
					'<div class="drc-msg-box"></div>' +
					'<table class="widefat striped"><thead><tr>' + headCols + '<th>Actions</th></tr></thead><tbody>' +
					bodyRows +
					'<tr>' + newCells + '<td><button type="button" class="button button-primary" id="drc-rule-add">Add</button></td></tr>' +
					'</tbody></table>';

				root.querySelectorAll( '.drc-tab' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () { activeTab = btn.dataset.tab; load(); } );
				} );

				var msgBox = root.querySelector( '.drc-msg-box' );
				function showError( e ) { msgBox.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; }
				function showMsg( m ) { msgBox.innerHTML = '<p class="drc-msg">' + esc( m ) + '</p>'; }

				function readRow( tr, selector ) {
					var body = {};
					tr.querySelectorAll( selector ).forEach( function ( input ) {
						body[ input.dataset.col ] = input.value === '' ? null : ( 'number' === input.type ? parseFloat( input.value ) : input.value );
					} );
					return body;
				}

				root.querySelectorAll( 'tbody tr[data-id]' ).forEach( function ( tr ) {
					var id = tr.dataset.id;
					tr.querySelector( '.drc-rule-save' ).addEventListener( 'click', function () {
						api( '/admin/' + activeTab + '/' + id, { method: 'PUT', body: JSON.stringify( readRow( tr, '.drc-rule-field' ) ) } )
							.then( function () { showMsg( 'Saved.' ); } ).catch( showError );
					} );
					tr.querySelector( '.drc-rule-delete' ).addEventListener( 'click', function () {
						if ( ! window.confirm( 'Delete this rule?' ) ) return;
						api( '/admin/' + activeTab + '/' + id, { method: 'DELETE' } )
							.then( function () { showMsg( 'Deleted.' ); load(); } ).catch( showError );
					} );
				} );

				var addBtn = root.querySelector( '#drc-rule-add' );
				addBtn.addEventListener( 'click', function () {
					var body = readRow( addBtn.closest( 'tr' ), '.drc-rule-new-field' );
					api( '/admin/' + activeTab, { method: 'POST', body: JSON.stringify( body ) } )
						.then( function () { showMsg( 'Added.' ); load(); } ).catch( showError );
				} );
			} ).catch( function ( e ) {
				root.innerHTML = renderTabs() + '<p class="drc-error">' + esc( e.message ) + '</p>';
			} );
		}

		load();
	}

	// ── Trade enquiries ──────────────────────────────────────────────────

	var PROFESSION_LABELS = {
		architect: 'Architect', interior_designer: 'Interior Designer', retailer: 'Retailer / Rug Showroom',
		wholesaler: 'Wholesaler', private_label: 'Private Label Brand', hospitality: 'Hospitality Company',
	};

	function initTradeEnquiries() {
		var root = document.getElementById( 'drc-trade-enquiries-root' );
		if ( ! root ) return;

		function load() {
			api( '/admin/trade-enquiries' ).then( function ( rows ) {
				if ( ! rows.length ) {
					root.innerHTML = '<p>No trade enquiries yet.</p>';
					return;
				}
				var cards = rows.map( function ( e ) {
					return '<div class="drc-enquiry-card" data-id="' + e.id + '" style="border:1px solid #dcdcde;background:#fff;padding:16px;margin-bottom:12px;' + ( e.is_read ? '' : 'border-left:4px solid #d97706;' ) + '">' +
						'<div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">' +
						'<div><strong>' + esc( e.first_name + ' ' + e.last_name ) + '</strong> · ' + esc( e.company ) +
						'<div style="color:#646970;font-size:13px;"><a href="mailto:' + esc( e.email ) + '">' + esc( e.email ) + '</a> · <a href="tel:' + esc( e.phone ) + '">' + esc( e.phone ) + '</a></div></div>' +
						( e.is_read ? '' : '<span class="drc-badge" style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;height:fit-content;">New</span>' ) +
						'</div>' +
						'<div style="margin-top:10px;font-size:13px;color:#3c434a;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:4px;">' +
						'<p><strong>Profession:</strong> ' + esc( PROFESSION_LABELS[ e.profession ] || e.profession ) + '</p>' +
						'<p><strong>City:</strong> ' + esc( e.city || '—' ) + '</p>' +
						'<p><strong>Country:</strong> ' + esc( e.country || '—' ) + '</p>' +
						'<p><strong>Project type:</strong> ' + esc( e.project_type || '—' ) + '</p>' +
						( e.website ? '<p><strong>Website:</strong> <a href="' + esc( e.website ) + '" target="_blank" rel="noreferrer">' + esc( e.website ) + '</a></p>' : '' ) +
						'</div>' +
						'<p style="margin-top:10px;white-space:pre-wrap;">' + esc( e.project_brief ) + '</p>' +
						'<div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;">' +
						'<time style="color:#646970;font-size:12px;">' + esc( new Date( e.created_at ).toLocaleString() ) + '</time>' +
						'<div>' + ( e.is_read ? '' : '<button type="button" class="button drc-enquiry-read">Mark read</button> ' ) + '<button type="button" class="button drc-enquiry-delete">Delete</button></div>' +
						'</div></div>';
				} ).join( '' );
				root.innerHTML = '<div class="drc-msg-box"></div>' + cards;

				root.querySelectorAll( '.drc-enquiry-card' ).forEach( function ( card ) {
					var id = card.dataset.id;
					var readBtn = card.querySelector( '.drc-enquiry-read' );
					if ( readBtn ) {
						readBtn.addEventListener( 'click', function () {
							api( '/admin/trade-enquiries/' + id + '/read', { method: 'PATCH' } ).then( load );
						} );
					}
					card.querySelector( '.drc-enquiry-delete' ).addEventListener( 'click', function () {
						if ( ! window.confirm( 'Delete this enquiry permanently?' ) ) return;
						api( '/admin/trade-enquiries/' + id, { method: 'DELETE' } ).then( load );
					} );
				} );
			} ).catch( function ( e ) {
				root.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>';
			} );
		}

		load();
	}

	// ── Homepage enquiries ───────────────────────────────────────────────

	function initHomepageEnquiries() {
		var root = document.getElementById( 'drc-homepage-enquiries-root' );
		if ( ! root ) return;

		function load() {
			api( '/admin/homepage-enquiries' ).then( function ( rows ) {
				if ( ! rows.length ) {
					root.innerHTML = '<p>No homepage enquiries yet.</p>';
					return;
				}
				var cards = rows.map( function ( e ) {
					return '<div class="drc-enquiry-card" data-id="' + e.id + '" style="border:1px solid #dcdcde;background:#fff;padding:16px;margin-bottom:12px;' + ( e.is_read ? '' : 'border-left:4px solid #d97706;' ) + '">' +
						'<div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;">' +
						'<div><strong>' + esc( e.name ) + '</strong> · ' + esc( e.subject ) +
						'<div style="color:#646970;font-size:13px;"><a href="mailto:' + esc( e.email ) + '">' + esc( e.email ) + '</a></div></div>' +
						( e.is_read ? '' : '<span class="drc-badge" style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;height:fit-content;">New</span>' ) +
						'</div>' +
						'<p style="margin-top:10px;white-space:pre-wrap;">' + esc( e.message ) + '</p>' +
						'<div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;">' +
						'<time style="color:#646970;font-size:12px;">' + esc( new Date( e.created_at ).toLocaleString() ) + '</time>' +
						'<div>' + ( e.is_read ? '' : '<button type="button" class="button drc-enquiry-read">Mark read</button> ' ) + '<button type="button" class="button drc-enquiry-delete">Delete</button></div>' +
						'</div></div>';
				} ).join( '' );
				root.innerHTML = '<div class="drc-msg-box"></div>' + cards;

				root.querySelectorAll( '.drc-enquiry-card' ).forEach( function ( card ) {
					var id = card.dataset.id;
					var readBtn = card.querySelector( '.drc-enquiry-read' );
					if ( readBtn ) {
						readBtn.addEventListener( 'click', function () {
							api( '/admin/homepage-enquiries/' + id + '/read', { method: 'PATCH' } ).then( load );
						} );
					}
					card.querySelector( '.drc-enquiry-delete' ).addEventListener( 'click', function () {
						if ( ! window.confirm( 'Delete this enquiry permanently?' ) ) return;
						api( '/admin/homepage-enquiries/' + id, { method: 'DELETE' } ).then( load );
					} );
				} );
			} ).catch( function ( e ) {
				root.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>';
			} );
		}

		load();
	}

	// ── Settings ──────────────────────────────────────────────────────────

	var SETTINGS_FIELDS = [
		[ 'gstin', 'text', 'GSTIN' ],
		[ 'state_code', 'text', 'State Code' ],
		[ 'address', 'text', 'Business Address' ],
		[ 'lut_number', 'text', 'LUT Number (export)' ],
		[ 'currency', 'readonly', 'Pricing Currency (set under WooCommerce → Settings → General)' ],
		[ 'default_profit_margin_pct', 'number', 'Default Profit Margin %' ],
		[ 'rush_surcharge_pct', 'number', 'Rush Surcharge %' ],
		[ 'default_gst_pct', 'number', 'Default GST %' ],
		[ 'gst_inclusive', 'checkbox', 'GST Inclusive Pricing' ],
		[ 'large_format_threshold_sqm', 'number', 'Large-Format Threshold (sqm)' ],
		[ 'large_format_surcharge_pct', 'number', 'Large-Format Surcharge %' ],
		[ 'default_size_unit', 'text', 'Default Size Unit' ],
		[ 'contact_emails', 'list', 'Contact Emails (one per line)' ],
		[ 'contact_phones', 'list', 'Contact Phones (one per line — the first is used for WhatsApp)' ],
		[ 'contact_address', 'text', 'Contact Address' ],
		[ 'contact_hours', 'text', 'Contact Hours' ],
		[ 'social_links', 'social', 'Social Media Links' ],
		[ 'certifications', 'repeater', 'Certifications & Badges (footer)', { cols: [ [ 'image_url', 'image', 'Badge' ], [ 'label', 'text', 'Label' ] ] } ],
		[ 'hero_image_url', 'image', 'Homepage Hero Image' ],
		[ 'hero_images', 'repeater', 'Homepage Hero Carousel', { cols: [ [ 'image_url', 'image', 'Image' ], [ 'alt_text', 'text', 'Alt Text' ], [ 'eyebrow', 'text', 'Eyebrow (optional override)' ], [ 'headline', 'text', 'Headline (optional override)' ], [ 'button_text', 'text', 'Button Text (optional override)' ] ] } ],
		[ 'hero_eyebrow', 'text', 'Homepage Hero Eyebrow' ],
		[ 'hero_heading', 'text', 'Homepage Hero Heading' ],
		[ 'hero_cta_label', 'text', 'Homepage Hero CTA Label' ],
		[ 'catalog_pdf_url', 'image', 'Catalog / Lookbook PDF', { library: [ 'application/pdf' ] } ],
		[ 'enabled_currencies', 'currency-list', 'Currencies customers can be charged in (picked automatically from their country)' ],
		[ 'homepage_full_bleed_image_url', 'image', 'Full-Bleed Section: Image' ],
		[ 'homepage_full_bleed_alt_text', 'text', 'Full-Bleed Section: Alt Text' ],
		[ 'homepage_full_bleed_enabled', 'checkbox', 'Full-Bleed Section: Show on Homepage' ],
		[ 'homepage_values_eyebrow', 'text', 'Values Section: Eyebrow' ],
		[ 'homepage_values_headline', 'text', 'Values Section: Headline' ],
		[ 'homepage_values_headline_accent', 'text', 'Values Section: Headline (Accent)' ],
		[ 'homepage_values_description', 'textarea', 'Values Section: Description' ],
		[ 'homepage_values_items', 'repeater', 'Values Section: Points', { cols: [ [ 'icon', 'text', 'Icon' ], [ 'title', 'text', 'Title' ], [ 'description', 'text', 'Description' ] ] } ],
		[ 'homepage_values_enabled', 'checkbox', 'Values Section: Show on Homepage' ],
		[ 'homepage_intro_title_line_one', 'text', 'Intro Section: Title Line 1' ],
		[ 'homepage_intro_title_line_two', 'text', 'Intro Section: Title Line 2' ],
		[ 'homepage_intro_label', 'text', 'Intro Section: Label' ],
		[ 'homepage_intro_description', 'textarea', 'Intro Section: Description' ],
		[ 'homepage_intro_cta_label', 'text', 'Intro Section: CTA Label' ],
		[ 'homepage_intro_cta_url', 'text', 'Intro Section: CTA URL' ],
		[ 'homepage_intro_trusted_by_text', 'text', 'Intro Section: "Trusted By" Text' ],
		[ 'homepage_intro_enabled', 'checkbox', 'Intro Section: Show on Homepage' ],
		[ 'homepage_gallery_titles', 'list', 'Project Gallery Section: Rotating Titles (one per line, up to 20)' ],
		[ 'trending_product_ids', 'product-list', 'Trending Rugs (Homepage) — leave empty to show newest rugs automatically' ],
		[ 'show_direct_purchase', 'checkbox', 'Show Direct Purchase (price, Add to Cart, Buy Now) — off shows "Request a Quote" only' ],
		[ 'homepage_contact_image_url', 'image', 'Contact Section: Image' ],
		[ 'homepage_contact_image_alt', 'text', 'Contact Section: Alt Text' ],
		[ 'homepage_contact_heading', 'text', 'Contact Section: Heading' ],
		[ 'homepage_contact_consent_text', 'text', 'Contact Section: Consent Text' ],
		[ 'homepage_contact_button_label', 'text', 'Contact Section: Button Label' ],
		[ 'homepage_contact_success_message', 'text', 'Contact Section: Success Message' ],
		[ 'homepage_contact_enabled', 'checkbox', 'Contact Section: Show on Homepage' ],
		[ 'about_us_content_html', 'textarea', 'About Page: Story Body (HTML)' ],
		[ 'about_page', 'about', 'About Page: Sections' ],
		[ 'refund_cancellation_policy_html', 'textarea', 'Refund & Cancellation Policy (HTML)' ],
		[ 'privacy_policy_html', 'textarea', 'Privacy Policy (HTML)' ],
		[ 'default_catalog_additional_information_html', 'textarea', 'Default Product Additional Info (HTML)' ],
		[ 'product_accordion_sections', 'repeater', 'Extra Product Accordion Sections', { cols: [ [ 'id', 'text', 'ID' ], [ 'title', 'text', 'Title' ], [ 'html', 'textarea', 'HTML' ] ] } ],
		[ 'default_shipping_rate', 'number', 'Default Shipping Rate' ],
		[ 'cancellation_window_hours', 'number', 'Cancellation Window (hours)' ],
		[ 'exchange_rates_auto', 'checkbox', 'Refresh exchange rates automatically every day (off = only your manual values)' ],
		[ 'exchange_rates', 'rates', 'Currency Exchange Rates' ],
		[ 'vendor_notification_email', 'text', 'Vendor Notification Email' ],
		[ 'require_email_verification', 'checkbox', 'Customers must confirm their email before signing in' ],
		[ 'turnstile_site_key', 'text', 'Cloudflare Turnstile Site Key (spam protection on public forms — leave empty for honeypot only)' ],
		[ 'turnstile_secret_key', 'secret', 'Cloudflare Turnstile Secret Key' ],
		[ 'india_access_keys', 'text', 'India access keys (comma-separated personal tokens — when set, visitors from India need yoursite.com/?key=TOKEN once; leave empty to allow everyone)' ],
		[ 'order_tracking_eyebrow', 'text', 'Order Tracking Page: Eyebrow' ],
		[ 'order_tracking_heading', 'text', 'Order Tracking Page: Heading' ],
		[ 'order_tracking_body', 'text', 'Order Tracking Page: Body Text' ],
		[ 'order_tracking_shipping_policy_url', 'text', 'Order Tracking Page: Shipping Policy URL' ],
		[ 'order_tracking_media_url', 'image', 'Order Tracking Page: Supporting Image/Video', { library: [ 'image', 'video' ], typeKey: 'order_tracking_media_type' } ],
		[ 'order_tracking_carriers', 'repeater', 'Order Tracking Page: Carrier Logos', { cols: [ [ 'image_url', 'image', 'Logo' ], [ 'label', 'text', 'Name' ] ] } ],
		[ 'order_tracking_steps', 'repeater', 'Order Tracking Page: Dispatch Steps', { cols: [ [ 'title', 'text', 'Title' ], [ 'description', 'text', 'Description' ] ] } ],
		[ 'colour_matching_eyebrow', 'text', 'Colour Matching Page: Eyebrow' ],
		[ 'colour_matching_heading', 'text', 'Colour Matching Page: Heading' ],
		[ 'colour_matching_body', 'text', 'Colour Matching Page: Body Text' ],
		[ 'colour_matching_items', 'repeater', 'Colour Matching Page: Reference Images', { cols: [ [ 'image_url', 'image', 'Image' ], [ 'title', 'text', 'Title' ], [ 'description', 'text', 'Description' ] ] } ],
	];

	// Ported from utils/currency.ts's CURRENCIES — matches the 14 currencies
	// the storefront's header dropdown offers. INR is excluded here since
	// it's this store's real transactional currency (get_woocommerce_currency()),
	// whose rate against itself is always 1 and isn't meaningful to edit.
	var RATE_CURRENCIES = [ 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'SGD', 'CHF', 'SAR', 'QAR', 'JPY', 'NZD', 'ZAR' ];

	/** Structure of the /about page (DRC_Settings 'about_page'), mirroring
	 * dreamrugs_about_page_defaults() in the theme's inc/about-page-content.php.
	 * Each section: an `enabled` toggle, some scalar `fields`, and optionally
	 * one repeatable `list`. `{business}` in any text is swapped for the site
	 * name at render time by the theme — leave it as-is here. Empty fields
	 * fall back to the theme's built-in copy (deep-merged), so a section only
	 * needs the bits you want to override. */
	var ABOUT_SECTIONS = [
		[ 'hero', 'Hero', [
			[ 'eyebrow', 'text', 'Eyebrow' ], [ 'heading', 'textarea', 'Heading' ], [ 'body', 'textarea', 'Body' ],
			[ 'cta_label', 'text', 'CTA Label' ], [ 'image_url', 'image', 'Image' ], [ 'image_alt', 'text', 'Image Alt' ],
		] ],
		[ 'credentials', 'Credentials Strip', [], { key: 'items', label: 'Items', cols: [ [ 'title', 'text', 'Title' ], [ 'subtitle', 'text', 'Subtitle' ] ] } ],
		[ 'story', 'Story', [
			[ 'eyebrow', 'text', 'Eyebrow' ], [ 'heading', 'textarea', 'Heading' ], [ 'quote', 'textarea', 'Pull Quote' ],
			[ 'primary_image_url', 'image', 'Primary Image' ], [ 'primary_image_alt', 'text', 'Primary Image Alt' ],
			[ 'secondary_image_url', 'image', 'Secondary Image' ], [ 'secondary_image_alt', 'text', 'Secondary Image Alt' ],
		] ],
		[ 'process', 'Process', [
			[ 'eyebrow', 'text', 'Eyebrow' ], [ 'heading', 'textarea', 'Heading' ], [ 'intro', 'textarea', 'Intro' ],
		], { key: 'steps', label: 'Steps', cols: [ [ 'number', 'text', 'No.' ], [ 'title', 'text', 'Title' ], [ 'text', 'textarea', 'Text' ], [ 'image_url', 'image', 'Image' ], [ 'image_alt', 'text', 'Image Alt' ] ] } ],
		[ 'principles', 'Principles', [
			[ 'eyebrow', 'text', 'Eyebrow' ], [ 'heading', 'text', 'Heading' ], [ 'intro', 'textarea', 'Intro' ],
		], { key: 'items', label: 'Items', cols: [ [ 'icon', 'text', 'Icon' ], [ 'title', 'text', 'Title' ], [ 'text', 'textarea', 'Text' ] ] } ],
		[ 'founder', 'Founder Note', [
			[ 'eyebrow', 'text', 'Eyebrow' ], [ 'heading', 'text', 'Heading' ], [ 'body', 'textarea', 'Body' ],
			[ 'name', 'text', 'Name' ], [ 'role', 'text', 'Role' ], [ 'caption', 'text', 'Image Caption' ],
			[ 'image_url', 'image', 'Image' ], [ 'image_alt', 'text', 'Image Alt' ],
		] ],
		[ 'cta', 'Closing CTA', [
			[ 'eyebrow', 'text', 'Eyebrow' ], [ 'heading', 'textarea', 'Heading' ], [ 'body', 'textarea', 'Body' ],
			[ 'primary_label', 'text', 'Primary Button' ], [ 'secondary_label', 'text', 'Secondary Button' ],
		] ],
	];

	function renderAboutSection(def, value) {
		var sectionKey = def[0], title = def[1], fields = def[2] || [], list = def[3];
		value = value || {};
		var enabled = value.enabled !== false; // default on, matching the theme
		var fieldRows = fields.map( function ( f ) {
			var k = f[0], type = f[1], label = f[2], v = value[ k ] == null ? '' : value[ k ];
			var control;
			if ( 'image' === type ) {
				control = '<div class="drc-about-image" data-col="' + k + '">' +
					'<div class="drc-image-preview" style="margin-bottom:6px;"></div>' +
					'<input type="hidden" data-image-value value="' + esc( v ) + '">' +
					'<button type="button" class="button button-small drc-image-choose">' + ( v ? 'Change' : 'Choose' ) + '</button> ' +
					( v ? '<button type="button" class="button button-small drc-image-remove">Remove</button>' : '' ) +
					'</div>';
			} else if ( 'textarea' === type ) {
				control = '<textarea data-col="' + k + '" rows="3" style="width:100%;max-width:560px;">' + esc( v ) + '</textarea>';
			} else {
				control = '<input type="text" data-col="' + k + '" value="' + esc( v ) + '" style="width:100%;max-width:560px;">';
			}
			return '<tr><th scope="row" style="font-weight:400;">' + esc( label ) + '</th><td>' + control + '</td></tr>';
		} ).join( '' );

		var listHtml = '';
		if ( list ) {
			var rows = ( value[ list.key ] || [] ).map( function ( row ) { return renderRepeaterRow( list, row ); } ).join( '' );
			var head = list.cols.map( function ( c ) { return '<th>' + esc( c[2] ) + '</th>'; } ).join( '' );
			listHtml =
				'<p style="margin:8px 0 4px;font-weight:600;">' + esc( list.label ) + '</p>' +
				'<div class="drc-about-list" data-list-key="' + list.key + '">' +
				'<table class="widefat" style="max-width:760px;"><thead><tr>' + head + '<th></th></tr></thead><tbody>' + rows + '</tbody></table>' +
				'<p><button type="button" class="button drc-repeater-add">+ Add ' + esc( list.label.replace( /s$/, '' ) ) + '</button></p>' +
				'</div>';
		}

		return '<fieldset class="drc-about-section" data-section="' + sectionKey + '" style="border:1px solid #dcdcde;padding:12px 16px;margin:0 0 14px;">' +
			'<legend style="padding:0 6px;font-weight:600;">' + esc( title ) + '</legend>' +
			'<label style="display:block;margin-bottom:8px;"><input type="checkbox" data-about-enabled' + ( enabled ? ' checked' : '' ) + '> Show this section</label>' +
			( fieldRows ? '<table class="form-table" style="margin:0;"><tbody>' + fieldRows + '</tbody></table>' : '' ) +
			listHtml +
			'</fieldset>';
	}

	function renderAboutField(value) {
		value = value || {};
		return '<div class="drc-about-field" data-key="about_page" data-type="about">' +
			'<p style="color:#646970;font-size:12px;margin-top:0;">Leave any field blank to use the theme’s built-in wording. <code>{business}</code> is replaced with the site name.</p>' +
			ABOUT_SECTIONS.map( function ( def ) { return renderAboutSection( def, value[ def[0] ] ); } ).join( '' ) +
			'</div>';
	}

	function wireAboutField(field) {
		field.querySelectorAll( '.drc-about-image' ).forEach( function ( img ) { wireImagePicker( img, {} ); } );
		field.querySelectorAll( '.drc-about-list' ).forEach( function ( listField ) {
			// Resolve via the containing section, not the list key — two
			// sections both use the list key "items" (credentials, principles).
			var secKey = listField.closest( '.drc-about-section' ).dataset.section;
			var cfg = ABOUT_SECTIONS.find( function ( d ) { return d[0] === secKey; } )[3];
			listField.querySelectorAll( 'tbody tr' ).forEach( wireRepeaterRow );
			listField.querySelector( '.drc-repeater-add' ).addEventListener( 'click', function () {
				var tbody = listField.querySelector( 'tbody' );
				var wrap = document.createElement( 'tbody' );
				wrap.innerHTML = renderRepeaterRow( cfg, {} );
				var tr = wrap.firstElementChild;
				tbody.appendChild( tr );
				wireRepeaterRow( tr );
			} );
		} );
	}

	function readAboutField(field) {
		var out = {};
		field.querySelectorAll( '.drc-about-section' ).forEach( function ( sec ) {
			var key = sec.dataset.section;
			var def = ABOUT_SECTIONS.find( function ( d ) { return d[0] === key; } );
			var obj = { enabled: sec.querySelector( '[data-about-enabled]' ).checked };
			sec.querySelectorAll( 'table.form-table [data-col]' ).forEach( function ( input ) {
				if ( input.closest( '.drc-about-image' ) ) {
					obj[ input.closest( '.drc-about-image' ).dataset.col ] = input.closest( '.drc-about-image' ).querySelector( '[data-image-value]' ).value;
				} else {
					obj[ input.dataset.col ] = input.value;
				}
			} );
			var listField = sec.querySelector( '.drc-about-list' );
			if ( listField && def[3] ) {
				obj[ def[3].key ] = readRepeaterRows( listField, def[3] );
			}
			out[ key ] = obj;
		} );
		return out;
	}

	/** Shared by the top-level 'image' settings field and each 'repeater'
	 * column of type 'image' — one wp.media() picker wired to a hidden
	 * value input plus a small preview. `onSelect(attachment)` lets the
	 * caller react to the picked attachment (e.g. the order-tracking media
	 * field also records whether it's an image or a video). */
	function wireImagePicker( container, opts ) {
		opts = opts || {};
		var hidden = container.querySelector( '[data-image-value]' );
		var preview = container.querySelector( '.drc-image-preview' );
		var isVideo = 'video' === opts.currentType;
		function renderPreview( url, video ) {
			if ( ! url ) {
				preview.innerHTML = opts.emptyText || '<p style="color:#646970;">No image set.</p>';
				return;
			}
			preview.innerHTML = video
				? '<video src="' + esc( url ) + '" style="max-width:220px;max-height:140px;display:block;" muted></video>'
				: '<img src="' + esc( url ) + '" style="max-width:220px;max-height:140px;display:block;border:1px solid #dcdcde;">';
		}
		renderPreview( hidden.value, isVideo );
		container.querySelector( '.drc-image-choose' ).addEventListener( 'click', function () {
			var frame = wp.media( {
				title: 'Select ' + ( opts.library && opts.library.indexOf( 'video' ) !== -1 ? 'Image or Video' : 'Image' ),
				button: { text: 'Use this' },
				multiple: false,
				library: opts.library ? { type: opts.library } : {},
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				hidden.value = attachment.url;
				renderPreview( attachment.url, 'video' === attachment.type );
				if ( opts.onSelect ) opts.onSelect( attachment );
			} );
			frame.open();
		} );
		var removeBtn = container.querySelector( '.drc-image-remove' );
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				hidden.value = '';
				renderPreview( '', false );
				if ( opts.onSelect ) opts.onSelect( { url: '', type: '' } );
			} );
		}
	}

	function renderRepeaterRow( config, row ) {
		row = row || {};
		var cells = config.cols.map( function ( c ) {
			var colKey = c[0], colType = c[1];
			if ( 'image' === colType ) {
				var url = row[ colKey ] || '';
				return '<td><div class="drc-repeater-image" style="min-width:140px;">' +
					'<div class="drc-image-preview" style="margin-bottom:6px;">' + ( url ? '<img src="' + esc( url ) + '" style="max-width:90px;max-height:60px;display:block;border:1px solid #dcdcde;">' : '<p style="color:#646970;font-size:12px;">No image</p>' ) + '</div>' +
					'<input type="hidden" data-image-value data-col="' + colKey + '" value="' + esc( url ) + '">' +
					'<button type="button" class="button button-small drc-image-choose">Choose</button> ' +
					( url ? '<button type="button" class="button button-small drc-image-remove">Remove</button>' : '' ) +
					'</div></td>';
			}
			if ( 'textarea' === colType ) {
				return '<td><textarea class="drc-repeater-cell" data-col="' + colKey + '" rows="3" style="width:100%;">' + esc( row[ colKey ] || '' ) + '</textarea></td>';
			}
			return '<td><input type="text" class="drc-repeater-cell" data-col="' + colKey + '" value="' + esc( row[ colKey ] || '' ) + '" style="width:100%;"></td>';
		} ).join( '' );
		return '<tr>' + cells + '<td><button type="button" class="button-link-delete drc-repeater-remove">Remove</button></td></tr>';
	}

	function wireRepeaterRow( tr ) {
		var imageCell = tr.querySelector( '.drc-repeater-image' );
		if ( imageCell ) {
			wireImagePicker( imageCell, {} );
		}
		tr.querySelector( '.drc-repeater-remove' ).addEventListener( 'click', function () {
			tr.remove();
		} );
	}

	function readRepeaterRows( field, config ) {
		return Array.from( field.querySelectorAll( 'tbody tr' ) ).map( function ( tr ) {
			var item = {};
			config.cols.forEach( function ( c ) {
				var colKey = c[0], colType = c[1];
				var input = 'image' === colType ? tr.querySelector( '[data-image-value]' ) : tr.querySelector( '[data-col="' + colKey + '"]' );
				item[ colKey ] = input ? input.value : '';
			} );
			return item;
		} ).filter( function ( item ) {
			return Object.keys( item ).some( function ( k ) { return item[ k ] !== ''; } );
		} );
	}

	/** Renders one row of the Trending Rugs picker: a <select> of every
	 * published product (current value included even if since unpublished,
	 * so it isn't silently dropped without the admin noticing) plus
	 * up/down/remove controls — mirrors the original app's TrendingRugs.tsx
	 * admin page's reorder UI, just inline as one settings field. */
	function renderProductListRow( products, productId ) {
		var options = products.map( function ( p ) {
			return '<option value="' + p.id + '"' + ( p.id === productId ? ' selected' : '' ) + '>' + esc( p.name ) + '</option>';
		} ).join( '' );
		return '<tr>' +
			'<td><select class="drc-product-list-select" style="min-width:280px;">' + options + '</select></td>' +
			'<td style="white-space:nowrap;">' +
			'<button type="button" class="button button-small drc-product-list-up" title="Move up">&uarr;</button> ' +
			'<button type="button" class="button button-small drc-product-list-down" title="Move down">&darr;</button> ' +
			'<button type="button" class="button-link-delete drc-product-list-remove">Remove</button>' +
			'</td></tr>';
	}

	function wireProductListRow( tr, tbody ) {
		tr.querySelector( '.drc-product-list-remove' ).addEventListener( 'click', function () { tr.remove(); } );
		tr.querySelector( '.drc-product-list-up' ).addEventListener( 'click', function () {
			var prev = tr.previousElementSibling;
			if ( prev ) tbody.insertBefore( tr, prev );
		} );
		tr.querySelector( '.drc-product-list-down' ).addEventListener( 'click', function () {
			var next = tr.nextElementSibling;
			if ( next ) tbody.insertBefore( next, tr );
		} );
	}

	function initSettings() {
		var root = document.getElementById( 'drc-settings-root' );
		if ( ! root ) return;

		Promise.all( [ api( '/admin/settings' ), api( '/admin/products/simple' ).catch( function () { return []; } ) ] ).then( function ( results ) {
			var settings = results[0];
			var products = results[1];
			var rows = SETTINGS_FIELDS.map( function ( f ) {
				var key = f[0], type = f[1], label = f[2], config = f[3] || {};
				var value = settings[ key ];
				var input;
				if ( 'checkbox' === type ) {
					input = '<input type="checkbox" data-key="' + key + '" data-type="checkbox"' + ( value ? ' checked' : '' ) + '>';
				} else if ( 'image' === type ) {
					input =
						'<div class="drc-image-field" data-key="' + key + '" data-type="image">' +
						( config.typeKey ? '<input type="hidden" data-image-type-value value="' + esc( settings[ config.typeKey ] || '' ) + '">' : '' ) +
						'<div class="drc-image-preview" style="margin-bottom:8px;"></div>' +
						'<input type="hidden" data-image-value value="' + esc( value || '' ) + '">' +
						'<button type="button" class="button drc-image-choose">' + ( value ? 'Change' : 'Choose' ) + '</button> ' +
						( value ? '<button type="button" class="button drc-image-remove">Remove</button>' : '' ) +
						'</div>';
				} else if ( 'repeater' === type ) {
					var repeaterRows = ( value || [] ).map( function ( row ) { return renderRepeaterRow( config, row ); } ).join( '' );
					var headCols = config.cols.map( function ( c ) { return '<th>' + esc( c[2] ) + '</th>'; } ).join( '' );
					input =
						'<div class="drc-repeater-field" data-key="' + key + '" data-type="repeater">' +
						'<table class="widefat" style="max-width:640px;"><thead><tr>' + headCols + '<th></th></tr></thead>' +
						'<tbody>' + repeaterRows + '</tbody></table>' +
						'<p><button type="button" class="button drc-repeater-add">+ Add row</button></p>' +
						'</div>';
				} else if ( 'rates' === type ) {
					var rates = value || {};
					var syncedAt = settings.exchange_rates_synced_at;
					var syncError = settings.exchange_rates_last_error;
					var syncStatus = syncError
						? '<span class="drc-error">Last sync failed: ' + esc( syncError ) + '</span>'
						: ( syncedAt ? 'Last synced ' + esc( syncedAt ) + ' UTC' : 'Never synced yet.' );
					input =
						'<div class="drc-rates-field" data-key="' + key + '" data-type="rates">' +
						'<p style="color:#646970;margin-top:0;">How many units of each currency equal 1 ' + esc( window.drcAdmin.currency || 'INR' ) + '. Refreshed automatically once a day from a live rate source; edit a value by hand to override it until the next sync.</p>' +
						'<p><button type="button" class="button" id="drc-sync-rates-now">Sync Now</button> <span id="drc-sync-status" style="margin-left:8px;color:#646970;">' + syncStatus + '</span></p>' +
						'<table class="widefat" style="max-width:420px;"><tbody>' +
						RATE_CURRENCIES.map( function ( code ) {
							return '<tr><td>' + code + '</td><td><input type="number" step="any" min="0" data-rate-currency="' + code + '" value="' + esc( rates[ code ] !== undefined ? rates[ code ] : '' ) + '" style="width:140px;" placeholder="e.g. ' + ( 'USD' === code ? '0.012' : '' ) + '"></td></tr>';
						} ).join( '' ) +
						'</tbody></table>' +
						'</div>';
				} else if ( 'secret' === type ) {
					input = '<input type="password" autocomplete="new-password" data-key="' + key + '" data-type="secret" value="' + ( '__set__' === value ? '__set__' : '' ) + '" placeholder="' + ( '__set__' === value ? 'Saved — type to replace' : '' ) + '" style="width:340px;">';
				} else if ( 'readonly' === type ) {
					input = '<code>' + esc( value || '' ) + '</code>';
				} else if ( 'list' === type ) {
					input = '<textarea data-key="' + key + '" data-type="list" rows="4" style="width:100%;max-width:520px;">' + esc( ( value || [] ).join( '\n' ) ) + '</textarea>';
				} else if ( 'social' === type ) {
					var links = value || {};
					input = '<div data-key="' + key + '" data-type="social"><table class="widefat" style="max-width:560px;"><tbody>' +
						[ [ 'instagram', 'Instagram' ], [ 'pinterest', 'Pinterest' ], [ 'linkedin', 'LinkedIn' ], [ 'facebook', 'Facebook' ], [ 'youtube', 'YouTube' ], [ 'x', 'X' ] ].map( function ( p ) {
							return '<tr><td style="width:110px;">' + p[1] + '</td><td><input type="url" data-social="' + p[0] + '" value="' + esc( links[ p[0] ] || '' ) + '" placeholder="https://' + ( 'x' === p[0] ? 'x.com' : p[0] + '.com' ) + '/yourstudio" style="width:100%;"></td></tr>';
						} ).join( '' ) + '</tbody></table><p style="color:#646970;font-size:12px;">Shown as icons in the header and the footer\'s Follow section.</p></div>';
				} else if ( 'textarea' === type ) {
					input = '<textarea data-key="' + key + '" data-type="textarea" rows="6" style="width:100%;max-width:640px;">' + esc( value || '' ) + '</textarea>';
				} else if ( 'about' === type ) {
					input = renderAboutField( value );
				} else if ( 'json' === type ) {
					var jsonText = value && Object.keys( value ).length ? JSON.stringify( value, null, 2 ) : '';
					input = '<textarea data-key="' + key + '" data-type="json" rows="12" style="width:100%;max-width:640px;font-family:monospace;font-size:12px;" placeholder="{}">' + esc( jsonText ) + '</textarea>' +
						'<p style="color:#646970;font-size:12px;">Advanced: raw JSON. Leave blank to use the theme’s built-in defaults.</p>';
				} else if ( 'currency-list' === type ) {
					var enabledCurrencies = value || [];
					input = '<div class="drc-currency-list" data-key="' + key + '" data-type="currency-list" style="display:flex;flex-wrap:wrap;gap:10px;max-width:520px;">' +
						RATE_CURRENCIES.map( function ( code ) {
							return '<label style="display:flex;align-items:center;gap:4px;"><input type="checkbox" value="' + code + '"' + ( enabledCurrencies.indexOf( code ) !== -1 ? ' checked' : '' ) + '> ' + code + '</label>';
						} ).join( '' ) +
						'</div><p style="color:#646970;font-size:12px;margin-bottom:0;">Leave all unchecked to show every currency that has a rate set above.</p>';
				} else if ( 'product-list' === type ) {
					var pickedRows = ( value || [] ).map( function ( id ) { return renderProductListRow( products, id ); } ).join( '' );
					input = '<div class="drc-product-list-field" data-key="' + key + '" data-type="product-list">' +
						'<table class="widefat" style="max-width:520px;"><tbody>' + pickedRows + '</tbody></table>' +
						'<p><button type="button" class="button drc-product-list-add"' + ( products.length ? '' : ' disabled' ) + '>+ Add rug</button></p>' +
						( products.length ? '' : '<p style="color:#646970;font-size:12px;">No published rugs found yet.</p>' ) +
						'</div>';
				} else if ( 'label-map' === type ) {
					var labelRows = Object.keys( value || {} ).map( function ( k ) { return renderRepeaterRow( config, { key: k, label: value[ k ] } ); } ).join( '' );
					input = '<div class="drc-repeater-field" data-key="' + key + '" data-type="label-map">' +
						'<table class="widefat" style="max-width:520px;"><thead><tr>' + config.cols.map( function ( c ) { return '<th>' + esc( c[2] ) + '</th>'; } ).join( '' ) + '<th></th></tr></thead>' +
						'<tbody>' + labelRows + '</tbody></table>' +
						'<p><button type="button" class="button drc-repeater-add">+ Add override</button></p>' +
						'</div>';
				} else {
					input = '<input type="' + type + '" step="any" data-key="' + key + '" data-type="' + type + '" value="' + esc( value === null || value === undefined ? '' : value ) + '" style="width:340px;">';
				}
				return '<tr><th scope="row">' + esc( label ) + '</th><td>' + input + '</td></tr>';
			} ).join( '' );

			root.innerHTML =
				'<div class="drc-msg-box"></div>' +
				'<table class="form-table"><tbody>' + rows + '</tbody></table>' +
				'<p><button type="button" class="button button-primary" id="drc-settings-save">Save Settings</button></p>';

			root.querySelectorAll( '.drc-image-field' ).forEach( function ( field ) {
				var fieldConfig = SETTINGS_FIELDS.find( function ( f ) { return f[0] === field.dataset.key; } );
				var config = ( fieldConfig && fieldConfig[3] ) || {};
				var typeHidden = field.querySelector( '[data-image-type-value]' );
				wireImagePicker( field, {
					library: config.library,
					currentType: typeHidden ? typeHidden.value : '',
					emptyText: '<p style="color:#646970;">No image set — falls back to a stock photo on the homepage.</p>',
					onSelect: function ( attachment ) {
						if ( typeHidden ) typeHidden.value = attachment.type || '';
					},
				} );
			} );

			root.querySelectorAll( '.drc-repeater-field' ).forEach( function ( field ) {
				var fieldConfig = SETTINGS_FIELDS.find( function ( f ) { return f[0] === field.dataset.key; } )[3];
				field.querySelectorAll( 'tbody tr' ).forEach( wireRepeaterRow );
				field.querySelector( '.drc-repeater-add' ).addEventListener( 'click', function () {
					var tbody = field.querySelector( 'tbody' );
					var wrapper = document.createElement( 'tbody' );
					wrapper.innerHTML = renderRepeaterRow( fieldConfig, {} );
					var tr = wrapper.firstElementChild;
					tbody.appendChild( tr );
					wireRepeaterRow( tr );
				} );
			} );

			root.querySelectorAll( '.drc-about-field' ).forEach( wireAboutField );

			root.querySelectorAll( '.drc-product-list-field' ).forEach( function ( field ) {
				var tbody = field.querySelector( 'tbody' );
				tbody.querySelectorAll( 'tr' ).forEach( function ( tr ) { wireProductListRow( tr, tbody ); } );
				var addBtn = field.querySelector( '.drc-product-list-add' );
				if ( addBtn ) {
					addBtn.addEventListener( 'click', function () {
						var used = Array.from( tbody.querySelectorAll( '.drc-product-list-select' ) ).map( function ( s ) { return parseInt( s.value, 10 ); } );
						var next = products.find( function ( p ) { return used.indexOf( p.id ) === -1; } ) || products[0];
						var wrapper = document.createElement( 'tbody' );
						wrapper.innerHTML = renderProductListRow( products, next ? next.id : null );
						var tr = wrapper.firstElementChild;
						tbody.appendChild( tr );
						wireProductListRow( tr, tbody );
					} );
				}
			} );

			var syncBtn = root.querySelector( '#drc-sync-rates-now' );
			if ( syncBtn ) {
				syncBtn.addEventListener( 'click', function () {
					var statusEl = root.querySelector( '#drc-sync-status' );
					syncBtn.disabled = true;
					statusEl.textContent = 'Syncing…';
					api( '/admin/currency/sync', { method: 'POST' } )
						.then( function ( r ) {
							root.querySelectorAll( '[data-rate-currency]' ).forEach( function ( rateInput ) {
								var code = rateInput.dataset.rateCurrency;
								if ( r.exchange_rates && r.exchange_rates[ code ] !== undefined ) {
									rateInput.value = r.exchange_rates[ code ];
								}
							} );
							statusEl.textContent = 'Last synced ' + r.synced_at + ' UTC';
						} )
						.catch( function ( e ) {
							statusEl.innerHTML = '<span class="drc-error">Sync failed: ' + esc( e.message ) + '</span>';
						} )
						.finally( function () { syncBtn.disabled = false; } );
				} );
			}

			var msgBox = root.querySelector( '.drc-msg-box' );
			root.querySelector( '#drc-settings-save' ).addEventListener( 'click', function () {
				var body = {};
				root.querySelectorAll( '[data-key]' ).forEach( function ( input ) {
					var type = input.dataset.type;
					if ( 'image' === type ) {
						body[ input.dataset.key ] = input.querySelector( '[data-image-value]' ).value;
						var typeHidden = input.querySelector( '[data-image-type-value]' );
						var fieldConfig = SETTINGS_FIELDS.find( function ( f ) { return f[0] === input.dataset.key; } )[3];
						if ( typeHidden && fieldConfig && fieldConfig.typeKey ) {
							body[ fieldConfig.typeKey ] = body[ input.dataset.key ] ? typeHidden.value : '';
						}
					} else if ( 'repeater' === type ) {
						var config = SETTINGS_FIELDS.find( function ( f ) { return f[0] === input.dataset.key; } )[3];
						body[ input.dataset.key ] = readRepeaterRows( input, config );
					} else if ( 'rates' === type ) {
						var rates = {};
						input.querySelectorAll( '[data-rate-currency]' ).forEach( function ( rateInput ) {
							if ( rateInput.value !== '' ) rates[ rateInput.dataset.rateCurrency ] = parseFloat( rateInput.value );
						} );
						body[ input.dataset.key ] = rates;
					} else if ( 'currency-list' === type ) {
						body[ input.dataset.key ] = Array.from( input.querySelectorAll( 'input[type="checkbox"]:checked' ) ).map( function ( cb ) { return cb.value; } );
					} else if ( 'label-map' === type ) {
						var labelMapConfig = SETTINGS_FIELDS.find( function ( f ) { return f[0] === input.dataset.key; } )[3];
						var map = {};
						readRepeaterRows( input, labelMapConfig ).forEach( function ( row ) {
							if ( row.key ) map[ row.key ] = row.label || '';
						} );
						body[ input.dataset.key ] = map;
					} else if ( 'product-list' === type ) {
						body[ input.dataset.key ] = Array.from( input.querySelectorAll( '.drc-product-list-select' ) ).map( function ( s ) { return parseInt( s.value, 10 ); } );
					} else if ( 'about' === type ) {
						body[ input.dataset.key ] = readAboutField( input );
					} else if ( 'list' === type ) {
						body[ input.dataset.key ] = input.value.split( '\n' ).map( function ( l ) { return l.trim(); } ).filter( Boolean );
					} else if ( 'social' === type ) {
						var social = {};
						input.querySelectorAll( '[data-social]' ).forEach( function ( i ) { if ( i.value.trim() ) social[ i.dataset.social ] = i.value.trim(); } );
						body[ input.dataset.key ] = social;
					} else {
						body[ input.dataset.key ] = 'checkbox' === type ? input.checked : ( 'number' === type ? parseFloat( input.value ) : input.value );
					}
				} );
				api( '/admin/settings', { method: 'PUT', body: JSON.stringify( body ) } )
					.then( function () { msgBox.innerHTML = '<p class="drc-msg">Settings saved.</p>'; } )
					.catch( function ( e ) { msgBox.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; } );
			} );
		} ).catch( function ( e ) {
			root.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initDashboard();
		initQuotes();
		initQuoteBuilder();
		initEmailTemplates();
		initNewsletter();
		initCatalogMasters();
		initWebsiteMenu();
		initInventory();
		initPricingRules();
		initTradeEnquiries();
		initHomepageEnquiries();
		initSettings();
	} );
})();
