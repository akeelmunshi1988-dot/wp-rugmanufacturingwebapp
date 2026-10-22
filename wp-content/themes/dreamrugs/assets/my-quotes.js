/**
 * Alpine component backing page-my-quotes.php — ported from
 * CustomerMyQuotes.tsx's QuoteCard + list/filter/sort logic. Talks to the
 * enriched /dreamrugs/v1/quotes (GET) and /quotes/{id}/{action} (PATCH)
 * REST routes. Every call sends X-WP-Nonce (see functions.php's
 * dreamrugs-rest-config handle) — without it WordPress's REST API never
 * authenticates the cookie session at all, regardless of the route's own
 * permission_callback, and every request here 401s.
 */
function drcRestHeaders(extra) {
	return Object.assign({ 'X-WP-Nonce': window.dreamrugsRest ? window.dreamrugsRest.nonce : '' }, extra || {});
}

function myQuotesList(config) {
	return {
		restUrl: config.restUrl,
		quotes: [],
		total: 0,
		actionNeeded: 0,
		loading: false,
		error: '',
		filter: 'all',
		sortBy: 'date_desc',
		dateFrom: '',
		dateTo: '',
		pageSize: 10,
		visible: 10,

		init() {
			this.fetchQuotes();
		},

		async fetchQuotes() {
			this.loading = true;
			this.error = '';
			try {
				const params = new URLSearchParams({ sort_by: this.sortBy });
				if ( 'all' !== this.filter ) params.set( 'status', this.filter );
				if ( this.dateFrom ) params.set( 'date_from', this.dateFrom );
				if ( this.dateTo ) params.set( 'date_to', this.dateTo );
				const res = await fetch( `${this.restUrl}/quotes?${params}`, { credentials: 'same-origin', headers: drcRestHeaders() } );
				if ( ! res.ok ) throw new Error( 'Failed to load quotes.' );
				const data = await res.json();
				this.quotes = data.items.map( ( q ) => ( { ...q, expanded: 'sent' === q.status, responding: null, actionError: '', actionLoading: false, reviewLoading: false, reviewMsg: '', acceptNotes: '', promoCode: '' } ) );
				this.visible = this.pageSize;
				this.total = data.total;
				this.actionNeeded = data.action_needed;
			} catch ( e ) {
				this.error = e.message || 'Failed to load quotes.';
			} finally {
				this.loading = false;
			}
		},

		/** Same paging as the original's "Load more" — the list is already
		 * fetched; this only reveals it in steps. */
		shownQuotes() {
			return this.quotes.slice( 0, this.visible );
		},

		loadMore() {
			this.visible += this.pageSize;
		},

		setFilter( key ) {
			this.filter = key;
			this.fetchQuotes();
		},

		setSort( val ) {
			this.sortBy = val;
			this.fetchQuotes();
		},

		hasActiveDateFilter() {
			return !! ( this.dateFrom || this.dateTo );
		},

		clearDateFilters() {
			this.dateFrom = '';
			this.dateTo = '';
			this.fetchQuotes();
		},

		fmtPrice( amount, currency ) {
			try {
				return new Intl.NumberFormat( 'en-IN', { style: 'currency', currency: currency || 'INR', maximumFractionDigits: 0 } ).format( amount );
			} catch ( e ) {
				return `${currency || 'INR'} ${Math.round( amount ).toLocaleString()}`;
			}
		},

		statusMeta( status ) {
			return {
				draft:    { label: 'Under Review', color: 'text-stone-400 border-stone-200 bg-stone-50', dot: 'bg-stone-300' },
				sent:     { label: 'Awaiting Your Response', color: 'text-blue-600 border-blue-200 bg-blue-50', dot: 'bg-blue-500' },
				accepted: { label: 'Accepted', color: 'text-green-700 border-green-200 bg-green-50', dot: 'bg-green-500' },
				rejected: { label: 'Rejected', color: 'text-red-600 border-red-200 bg-red-50', dot: 'bg-red-400' },
			}[ status ] || { label: status, color: 'text-stone-400 border-stone-200 bg-stone-50', dot: 'bg-stone-300' };
		},

		async requestReview( quote ) {
			quote.reviewLoading = true;
			try {
				const res = await fetch( `${this.restUrl}/quotes/${quote.quote_id}/request-review`, {
					method: 'PATCH', credentials: 'same-origin', headers: drcRestHeaders( { 'Content-Type': 'application/json' } ), body: '{}',
				} );
				const data = await res.json();
				if ( ! res.ok ) throw new Error( data.message );
				quote.review_request_count = data.review_request_count;
				quote.reviewMsg = 'Request sent.';
			} catch ( e ) {
				quote.reviewMsg = e.message || 'Failed to send request.';
			} finally {
				quote.reviewLoading = false;
			}
		},

		/** Accept is a special case: on success the customer must land on
		 * the WooCommerce payment page for the order DRC_Order_Creator just
		 * created for this specific quote, not the generic cart checkout
		 * (which would checkout whatever's sitting in their cart instead). */
		async acceptAndPay( quote ) {
			quote.actionLoading = true;
			quote.actionError = '';
			try {
				const res = await fetch( `${this.restUrl}/quotes/${quote.quote_id}/accept`, {
					method: 'PATCH', credentials: 'same-origin', headers: drcRestHeaders( { 'Content-Type': 'application/json' } ),
					body: JSON.stringify( { notes: quote.acceptNotes || '', promo_code: ( quote.promoCode || '' ).trim() } ),
				} );
				const data = await res.json();
				if ( ! res.ok ) throw new Error( data.message || 'Could not accept this quote.' );
				if ( data.pay_url ) {
					window.location.href = data.pay_url;
					return;
				}
				this.fetchQuotes();
			} catch ( e ) {
				quote.actionError = e.message || 'Could not accept this quote.';
				quote.actionLoading = false;
			}
		},

		async respond( quote, action, payload ) {
			quote.actionLoading = true;
			quote.actionError = '';
			try {
				const res = await fetch( `${this.restUrl}/quotes/${quote.quote_id}/${action}`, {
					method: 'PATCH', credentials: 'same-origin', headers: drcRestHeaders( { 'Content-Type': 'application/json' } ), body: JSON.stringify( payload || {} ),
				} );
				const data = await res.json();
				if ( ! res.ok ) throw new Error( data.message || 'Something went wrong.' );
				quote.responding = null;
				this.fetchQuotes();
			} catch ( e ) {
				quote.actionError = e.message || 'Something went wrong.';
			} finally {
				quote.actionLoading = false;
			}
		},
	};
}
