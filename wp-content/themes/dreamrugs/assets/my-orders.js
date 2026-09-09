/**
 * Alpine component backing page-my-orders.php — ported from
 * CustomerMyOrders.tsx's OrderCard + list/filter/sort/guest-lookup logic.
 * Talks to the /dreamrugs/v1/orders REST routes built in
 * class-customer-orders-rest-routes.php. Every call sends X-WP-Nonce (see
 * functions.php's dreamrugs-rest-config handle) for the same reason
 * my-quotes.js does — without it, a logged-in session's requests would
 * resolve as guest lookups server-side (is_user_logged_in() returns false
 * inside a REST dispatch with no nonce), which for /orders specifically
 * would silently show nothing rather than obviously failing, since a
 * logged-in customer simply has no ?email= param to fall back to.
 */
function drcRestHeaders(extra) {
	return Object.assign({ 'X-WP-Nonce': window.dreamrugsRest ? window.dreamrugsRest.nonce : '' }, extra || {});
}

const STATUS_META = {
	pending:        { label: 'Order Placed',   color: 'text-amber-700 border-amber-200 bg-amber-50',    dot: 'bg-amber-400' },
	processing:     { label: 'Confirmed',      color: 'text-blue-700 border-blue-200 bg-blue-50',       dot: 'bg-blue-500' },
	in_production:  { label: 'In Production',  color: 'text-purple-700 border-purple-200 bg-purple-50', dot: 'bg-purple-500' },
	quality_check:  { label: 'Quality Check',  color: 'text-indigo-700 border-indigo-200 bg-indigo-50', dot: 'bg-indigo-500' },
	shipped:        { label: 'Shipped',        color: 'text-teal-700 border-teal-200 bg-teal-50',       dot: 'bg-teal-500' },
	delivered:      { label: 'Delivered',      color: 'text-green-700 border-green-200 bg-green-50',    dot: 'bg-green-500' },
	cancelled:      { label: 'Cancelled',      color: 'text-red-600 border-red-200 bg-red-50',          dot: 'bg-red-400' },
};

const CANCELLABLE_STATUSES = [ 'pending', 'processing', 'in_production' ];
const PAGE_SIZE = 10;

function myOrdersList(config) {
	return {
		restUrl: config.restUrl,
		loggedIn: config.loggedIn,
		loginUrl: config.loginUrl,
		cancellableStatuses: CANCELLABLE_STATUSES,

		orders: [],
		total: 0,
		page: 1,
		filter: 'all',
		sortBy: 'date_desc',
		dateFrom: '',
		dateTo: '',
		loading: false,
		loadingMore: false,
		error: '',

		guestEmail: '',
		searchedEmail: '',
		searched: false,

		cancelModal: null,
		cancelTarget: null,
		cancelling: false,
		cancelError: '',

		init() {
			if (this.loggedIn) this.fetchOrders();
		},

		statusMeta(status) {
			return STATUS_META[status] || { label: status, color: 'text-stone-400 border-stone-200 bg-stone-50', dot: 'bg-stone-300' };
		},

		fmtPrice(amount, currency) {
			if (amount === null || amount === undefined) return '—';
			try {
				return new Intl.NumberFormat('en-IN', { style: 'currency', currency: currency || 'INR', maximumFractionDigits: 0 }).format(amount);
			} catch (e) {
				return (currency || 'INR') + ' ' + Math.round(amount).toLocaleString();
			}
		},

		fmtDate(iso) {
			if (!iso) return '—';
			try {
				return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
			} catch (e) {
				return '—';
			}
		},

		lineColor(line) {
			const label = (line.rule || line.label || '').toLowerCase();
			const isNegative = line.amount < 0;
			if (isNegative || label.includes('discount')) return 'text-green-600';
			if (label.includes('rush')) return 'text-amber-600';
			if (label.includes('gst')) return 'text-stone-400';
			return 'text-stone-500';
		},

		hasActiveDateFilter() {
			return !!(this.dateFrom || this.dateTo);
		},

		clearDateFilters() {
			this.dateFrom = '';
			this.dateTo = '';
			this.fetchOrders();
		},

		setFilter(key) {
			this.filter = key;
			this.fetchOrders();
		},

		hasMore() {
			return this.orders.length < this.total;
		},

		async fetchOrders(append) {
			append ? (this.loadingMore = true) : (this.loading = true);
			this.error = '';
			const targetPage = append ? this.page + 1 : 1;
			try {
				const params = new URLSearchParams({ page: targetPage, page_size: PAGE_SIZE, sort_by: this.sortBy });
				if ('all' !== this.filter) params.set('status', this.filter);
				if (this.dateFrom) params.set('date_from', this.dateFrom);
				if (this.dateTo) params.set('date_to', this.dateTo);
				if (!this.loggedIn && this.searchedEmail) params.set('email', this.searchedEmail);

				const res = await fetch(`${this.restUrl}/orders?${params}`, { credentials: 'same-origin', headers: drcRestHeaders() });
				if (!res.ok) throw new Error('Failed to load orders.');
				const data = await res.json();
				const mapped = data.items.map((o) => ({ ...o, expanded: false, timeline: null, timelineLoading: false, timelineError: false, breakdown: null, breakdownLoading: false, breakdownError: false }));
				this.orders = append ? [...this.orders, ...mapped] : mapped;
				this.total = data.total;
				this.page = targetPage;
			} catch (e) {
				this.error = e.message || 'Failed to load orders.';
			} finally {
				append ? (this.loadingMore = false) : (this.loading = false);
			}
		},

		loadMore() {
			this.fetchOrders(true);
		},

		async guestSearch() {
			if (!this.guestEmail) return;
			this.searchedEmail = this.guestEmail.trim();
			this.searched = false;
			await this.fetchOrders();
			this.searched = true;
		},

		async toggleExpand(order) {
			order.expanded = !order.expanded;
			if (!order.expanded) return;

			const params = new URLSearchParams();
			if (!this.loggedIn && this.searchedEmail) params.set('email', this.searchedEmail);
			const qs = params.toString() ? `?${params}` : '';

			if (!order.timeline && !order.timelineLoading) {
				order.timelineLoading = true;
				order.timelineError = false;
				try {
					const res = await fetch(`${this.restUrl}/orders/${order.order_id}/timeline${qs}`, { credentials: 'same-origin', headers: drcRestHeaders() });
					if (!res.ok) throw new Error();
					order.timeline = await res.json();
				} catch (e) {
					order.timelineError = true;
				} finally {
					order.timelineLoading = false;
				}
			}

			if (!order.breakdown && !order.breakdownLoading && order.quote_id) {
				order.breakdownLoading = true;
				order.breakdownError = false;
				try {
					const res = await fetch(`${this.restUrl}/orders/${order.order_id}/breakdown${qs}`, { credentials: 'same-origin', headers: drcRestHeaders() });
					if (!res.ok) throw new Error();
					order.breakdown = await res.json();
				} catch (e) {
					order.breakdownError = true;
				} finally {
					order.breakdownLoading = false;
				}
			}
		},

		async openCancelModal(order) {
			this.cancelTarget = order;
			this.cancelModal = 'loading';
			this.cancelError = '';
			try {
				const res = await fetch(`${this.restUrl}/orders/${order.order_id}/cancel-eligibility`, { credentials: 'same-origin', headers: drcRestHeaders() });
				if (!res.ok) throw new Error();
				this.cancelModal = await res.json();
			} catch (e) {
				this.cancelModal = 'error';
			}
		},

		async confirmCancel() {
			if (!this.cancelTarget) return;
			this.cancelling = true;
			this.cancelError = '';
			try {
				const res = await fetch(`${this.restUrl}/orders/${this.cancelTarget.order_id}/cancel`, {
					method: 'POST', credentials: 'same-origin', headers: drcRestHeaders({ 'Content-Type': 'application/json' }), body: '{}',
				});
				const data = await res.json();
				if (!res.ok) throw new Error(data.message || 'Something went wrong. Please try again.');
				this.cancelTarget.status = 'cancelled';
				this.cancelModal = null;
				this.cancelTarget = null;
			} catch (e) {
				this.cancelError = e.message || 'Something went wrong. Please try again.';
			} finally {
				this.cancelling = false;
			}
		},
	};
}
