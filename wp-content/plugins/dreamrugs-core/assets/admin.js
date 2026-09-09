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

	function initQuotes() {
		var root = document.getElementById( 'drc-quotes-root' );
		if ( ! root ) return;

		var state = { status: 'all', page: 1, search: '', expanded: null, materials: [] };

		function fetchMaterials() {
			return api( '/admin/materials' ).then( function ( m ) { state.materials = m; } ).catch( function () { state.materials = []; } );
		}

		function renderShell( statusCounts ) {
			var tabs = [ 'all', 'draft', 'sent', 'accepted', 'rejected' ].map( function ( s ) {
				var count = statusCounts ? ( statusCounts[ s ] || 0 ) : '';
				return '<button type="button" class="drc-tab' + ( s === state.status ? ' active' : '' ) + '" data-status="' + s + '">' + s.charAt(0).toUpperCase() + s.slice(1) + ( statusCounts ? ' (' + count + ')' : '' ) + '</button>';
			} ).join( '' );
			root.innerHTML =
				'<div class="drc-tabs">' + tabs + '</div>' +
				'<p><input type="search" id="drc-quote-search" placeholder="Search by name, email, or #id" value="' + esc( state.search ) + '" style="min-width:280px;"></p>' +
				'<div id="drc-quotes-list">Loading…</div>' +
				'<p id="drc-quotes-pager"></p>';

			root.querySelectorAll( '.drc-tab' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					state.status = btn.dataset.status;
					state.page = 1;
					load();
				} );
			} );
			var search = root.querySelector( '#drc-quote-search' );
			var debounce;
			search.addEventListener( 'input', function () {
				clearTimeout( debounce );
				debounce = setTimeout( function () {
					state.search = search.value;
					state.page = 1;
					load();
				}, 400 );
			} );
		}

		function materialOptions( selectedId ) {
			return '<option value="">Select material…</option>' + state.materials.map( function ( m ) {
				return '<option value="' + m.id + '"' + ( selectedId == m.id ? ' selected' : '' ) + '>' + esc( m.name ) + ' (' + esc( m.type ) + ', ' + esc( m.color ) + ')</option>';
			} ).join( '' );
		}

		function renderDetail( q ) {
			var priceForm = '';
			if ( 'draft' === q.status || 'sent' === q.status ) {
				priceForm =
					'<div class="drc-field-row">' +
					'<label>Material<select class="drc-material">' + materialOptions( q.material_id ) + '</select></label>' +
					'<label>Margin %<input type="number" step="0.1" class="drc-margin" value="' + ( q.margin_pct !== null ? q.margin_pct : '' ) + '" placeholder="default"></label>' +
					'<label>Or flat final price<input type="number" step="0.01" class="drc-flat-price" value="" placeholder="' + fmtMoney( q.final_price ) + '"></label>' +
					'</div>' +
					'<div class="drc-field-row"><label style="flex:1;">Vendor notes<textarea class="drc-vendor-notes" rows="2" style="width:100%;">' + esc( q.vendor_notes || '' ) + '</textarea></label></div>' +
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
				priceForm = '<p>Order: ' + ( q.wc_order_id ? '<a href="/wp-admin/admin.php?page=wc-orders&action=edit&id=' + q.wc_order_id + '">#' + q.wc_order_id + '</a>' : '—' ) + '</p>';
			}

			return '<div class="drc-quote-detail">' +
				'<p><strong>Customer:</strong> ' + esc( q.customer_name || '—' ) + ' (' + esc( q.customer_email || '—' ) + ')</p>' +
				( q.is_custom_request ? '<p><strong>Custom request:</strong> ' + q.custom_size_w + 'm × ' + q.custom_size_h + 'm, ' + esc( q.rug_shape ) + ', room: ' + esc( q.room_type || '—' ) + ', material pref: ' + esc( q.material_preference || '—' ) + ', budget: ' + esc( q.budget_range || '—' ) + '</p>' : '' ) +
				( q.notes ? '<p><strong>Customer notes:</strong> ' + esc( q.notes ) + '</p>' : '' ) +
				( q.customer_response_notes ? '<p><strong>Customer response:</strong> ' + esc( q.customer_response_notes ) + '</p>' : '' ) +
				priceForm +
				'<div class="drc-detail-msg"></div>' +
				'</div>';
		}

		function wireDetail( tr, detailTr, q ) {
			var msgBox = detailTr.querySelector( '.drc-detail-msg' );
			function showError( e ) { msgBox.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>'; }
			function showMsg( m ) { msgBox.innerHTML = '<p class="drc-msg">' + esc( m ) + '</p>'; }

			var previewBtn = detailTr.querySelector( '.drc-preview' );
			if ( previewBtn ) {
				previewBtn.addEventListener( 'click', function () {
					var materialId = detailTr.querySelector( '.drc-material' ).value;
					if ( ! materialId ) { showError( new Error( 'Select a material to preview.' ) ); return; }
					var margin = detailTr.querySelector( '.drc-margin' ).value;
					api( '/admin/quotes/' + q.id + '/adjust/preview', { method: 'POST', body: JSON.stringify( { material_id: parseInt( materialId, 10 ), margin_pct: margin ? parseFloat( margin ) : null } ) } )
						.then( function ( r ) {
							detailTr.querySelector( '.drc-preview-result' ).innerHTML = '<p><strong>Preview:</strong> ' + fmtMoney( r.final_price ) + ' total (' + r.total_sqm + ' sqm @ margin ' + r.profit_margin_pct + '%)</p>';
						} ).catch( showError );
				} );
			}

			var sendBtn = detailTr.querySelector( '.drc-send' );
			if ( sendBtn ) {
				sendBtn.addEventListener( 'click', function () {
					var materialId = detailTr.querySelector( '.drc-material' ).value;
					var flatPrice = detailTr.querySelector( '.drc-flat-price' ).value;
					var vendorNotes = detailTr.querySelector( '.drc-vendor-notes' ).value;
					var margin = detailTr.querySelector( '.drc-margin' ).value;

					if ( materialId ) {
						api( '/admin/quotes/' + q.id + '/adjust', { method: 'PATCH', body: JSON.stringify( { material_id: parseInt( materialId, 10 ), margin_pct: margin ? parseFloat( margin ) : null, vendor_notes: vendorNotes } ) } )
							.then( function () { showMsg( 'Priced and sent to customer.' ); load(); } ).catch( showError );
					} else if ( flatPrice ) {
						api( '/admin/quotes/' + q.id + '/adjust', { method: 'PATCH', body: JSON.stringify( { final_price: parseFloat( flatPrice ), vendor_notes: vendorNotes } ) } )
							.then( function () { showMsg( 'Priced and sent to customer.' ); load(); } ).catch( showError );
					} else if ( 'sent' === q.status ) {
						api( '/admin/quotes/' + q.id + '/send', { method: 'PATCH', body: JSON.stringify( { vendor_notes: vendorNotes } ) } )
							.then( function () { showMsg( 'Re-sent to customer.' ); load(); } ).catch( showError );
					} else {
						showError( new Error( 'Select a material or type a flat price first.' ) );
					}
				} );
			}

			var rejectBtn = detailTr.querySelector( '.drc-reject' );
			if ( rejectBtn ) {
				rejectBtn.addEventListener( 'click', function () {
					var reason = window.prompt( 'Reason for rejecting this quote (optional):', '' );
					if ( reason === null ) return;
					api( '/admin/quotes/' + q.id + '/reject', { method: 'PATCH', body: JSON.stringify( { reason: reason } ) } )
						.then( function () { showMsg( 'Quote rejected.' ); load(); } ).catch( showError );
				} );
			}

			var acceptBtn = detailTr.querySelector( '.drc-accept' );
		if ( acceptBtn ) {
			acceptBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Create an order for this quote and email the customer a payment link?' ) ) return;
				api( '/admin/quotes/' + q.id + '/accept', { method: 'PATCH' } )
					.then( function ( r ) { showMsg( 'Order #' + r.order_id + ' created. Customer emailed a payment link.' ); load(); } )
					.catch( showError );
			} );
		}

		var reviseBtn = detailTr.querySelector( '.drc-revise' );
			if ( reviseBtn ) {
				reviseBtn.addEventListener( 'click', function () {
					api( '/admin/quotes/' + q.id + '/revise', { method: 'POST' } )
						.then( function ( r ) { showMsg( 'Revised into new Quote #' + r.id + ' (draft).' ); load(); } ).catch( showError );
				} );
			}
		}

		function load() {
			var list = root.querySelector( '#drc-quotes-list' );
			var params = new URLSearchParams( { page: state.page, page_size: 20 } );
			if ( 'all' !== state.status ) params.set( 'status', state.status );
			if ( state.search ) params.set( 'search', state.search );

			fetchMaterials().then( function () {
				return api( '/admin/quotes?' + params );
			} ).then( function ( data ) {
				if ( ! root.querySelector( '.drc-tabs' ) ) {
					renderShell( data.status_counts );
					list = root.querySelector( '#drc-quotes-list' );
				} else {
					root.querySelectorAll( '.drc-tab' ).forEach( function ( btn ) {
						var s = btn.dataset.status;
						btn.classList.toggle( 'active', s === state.status );
						btn.textContent = s.charAt(0).toUpperCase() + s.slice(1) + ' (' + ( data.status_counts[ s ] || 0 ) + ')';
					} );
				}

				if ( ! data.items.length ) {
					list.innerHTML = '<p>No quotes in this view.</p>';
					return;
				}

				var table = el( '<table class="widefat striped"><thead><tr><th>ID</th><th>Customer</th><th>Rug</th><th>Qty</th><th>Price</th><th>Status</th><th>Created</th></tr></thead><tbody></tbody></table>' );
				var tbody = table.querySelector( 'tbody' );
				data.items.forEach( function ( q ) {
					var tr = el( '<tr class="drc-quote-row" style="cursor:pointer;"><td>#' + q.id + '</td><td>' + esc( q.customer_name || '—' ) + '</td><td>' + esc( q.rug_name ) + '</td><td>' + q.qty + '</td><td>' + fmtMoney( q.final_price ) + '</td><td><span class="drc-badge drc-badge-' + q.status + '">' + q.status + '</span></td><td>' + esc( q.created_at ) + '</td></tr>' );
					tbody.appendChild( tr );
					var detailTr = document.createElement( 'tr' );
					var detailTd = document.createElement( 'td' );
					detailTd.colSpan = 7;
					detailTd.innerHTML = renderDetail( q );
					detailTr.appendChild( detailTd );
					detailTr.style.display = state.expanded === q.id ? '' : 'none';
					tbody.appendChild( detailTr );
					wireDetail( tr, detailTd, q );

					tr.addEventListener( 'click', function () {
						var showing = detailTr.style.display !== 'none';
						// Collapse every other row's detail (rows alternate
						// main/detail, so odd child indices are all details),
						// then toggle just this one.
						Array.prototype.forEach.call( tbody.children, function ( child, idx ) {
							if ( idx % 2 === 1 ) child.style.display = 'none';
						} );
						tbody.querySelectorAll( '.drc-quote-row' ).forEach( function ( r ) { r.classList.remove( 'expanded' ); } );
						detailTr.style.display = showing ? 'none' : '';
						state.expanded = showing ? null : q.id;
						tr.classList.toggle( 'expanded', ! showing );
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
			} ).catch( function ( e ) {
				list.innerHTML = '<p class="drc-error">' + esc( e.message ) + '</p>';
			} );
		}

		renderShell( null );
		load();
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
		[ 'currency', 'text', 'Currency' ],
		[ 'default_profit_margin_pct', 'number', 'Default Profit Margin %' ],
		[ 'rush_surcharge_pct', 'number', 'Rush Surcharge %' ],
		[ 'default_gst_pct', 'number', 'Default GST %' ],
		[ 'gst_inclusive', 'checkbox', 'GST Inclusive Pricing' ],
		[ 'large_format_threshold_sqm', 'number', 'Large-Format Threshold (sqm)' ],
		[ 'large_format_surcharge_pct', 'number', 'Large-Format Surcharge %' ],
		[ 'default_size_unit', 'text', 'Default Size Unit' ],
		[ 'contact_address', 'text', 'Contact Address' ],
		[ 'contact_hours', 'text', 'Contact Hours' ],
		[ 'hero_image_url', 'image', 'Homepage Hero Image' ],
		[ 'hero_images', 'repeater', 'Homepage Hero Carousel', { cols: [ [ 'image_url', 'image', 'Image' ], [ 'alt_text', 'text', 'Alt Text' ], [ 'eyebrow', 'text', 'Eyebrow (optional override)' ], [ 'headline', 'text', 'Headline (optional override)' ], [ 'button_text', 'text', 'Button Text (optional override)' ] ] } ],
		[ 'hero_eyebrow', 'text', 'Homepage Hero Eyebrow' ],
		[ 'hero_heading', 'text', 'Homepage Hero Heading' ],
		[ 'hero_cta_label', 'text', 'Homepage Hero CTA Label' ],
		[ 'catalog_pdf_url', 'image', 'Catalog / Lookbook PDF', { library: [ 'application/pdf' ] } ],
		[ 'enabled_currencies', 'currency-list', 'Currencies Shown In Switcher' ],
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
		[ 'storefront_menu_labels', 'label-map', 'Navigation Label Overrides', { cols: [ [ 'key', 'text', 'Menu Item Key' ], [ 'label', 'text', 'Custom Label' ] ] } ],
		[ 'default_shipping_rate', 'number', 'Default Shipping Rate' ],
		[ 'cancellation_window_hours', 'number', 'Cancellation Window (hours)' ],
		[ 'exchange_rates', 'rates', 'Display Currency Exchange Rates' ],
		[ 'vendor_notification_email', 'text', 'Vendor Notification Email' ],
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
		initInventory();
		initPricingRules();
		initTradeEnquiries();
		initHomepageEnquiries();
		initSettings();
	} );
})();
