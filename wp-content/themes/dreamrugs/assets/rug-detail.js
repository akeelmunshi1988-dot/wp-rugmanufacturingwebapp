/**
 * Alpine component backing single-product.php — replaces the React state
 * from CustomerRugDetail.tsx (selected size, qty/rush, live price
 * estimate, the quote-request modal). Calls the dreamrugs-core REST API
 * built in class-rest-routes.php: POST /quotes/estimate (already verified
 * against the ported quote engine) and POST /quotes.
 *
 * /quotes itself has permission_callback '__return_true' (guests can
 * submit quotes too), but a logged-in customer's submission still needs
 * X-WP-Nonce sent — without it, WordPress's REST API doesn't recognize the
 * cookie session at all for this request, so get_current_user_id() on the
 * server silently returns 0 and the quote saves as a guest submission
 * instead of linking to the account (see functions.php's
 * dreamrugs-rest-config handle for where the nonce comes from).
 */
function drcRestHeaders(extra) {
	return Object.assign({ 'X-WP-Nonce': window.dreamrugsRest ? window.dreamrugsRest.nonce : '' }, extra || {});
}

function rugDetail(config) {
	return {
		productId: config.productId,
		materialId: config.materialId,
		variations: config.variations,
		colorOptions: config.colorOptions || [],
		coverUrl: config.coverUrl || '',
		productTitle: config.productTitle || '',
		leadTimeDays: config.leadTimeDays,
		restUrl: config.restUrl,
		isLoggedIn: config.isLoggedIn,
		loginUrl: config.loginUrl,

		selectedIndex: config.variations.length ? 0 : null,
		sizeUnit: 'ft',
		selectedColor: config.colorOptions && config.colorOptions.length ? config.colorOptions[0].name : '',
		qty: 1,
		rushOrder: false,
		priceResult: null,
		calcLoading: false,
		expandedImage: null,
		faqs: config.faqs || [],
		openFaq: null,

		quoteModalOpen: false,
		submitting: false,
		submitError: null,
		quote: {
			name: config.customerName,
			email: config.customerEmail,
			phone: '',
			company: '',
			room_type: config.defaultRoomType,
			material_preference: config.defaultMaterialPref,
			material_other: config.materialOther,
			budget_range: '',
			expected_delivery: '',
			size_w: '',
			size_h: '',
			unit: 'ft',
			notes: '',
		},

		init() {
			if (this.selectedIndex !== null) this.estimate();
		},

		selectedLeadTime() {
			if (this.priceResult) return this.priceResult.estimated_days;
			if (this.selectedIndex !== null && this.variations[this.selectedIndex]) {
				return this.variations[this.selectedIndex].lead_time_days || this.leadTimeDays;
			}
			return this.leadTimeDays;
		},

		rushLabel() {
			if (this.priceResult) return `${this.priceResult.standard_days}d → ${this.priceResult.rush_days}d · +25% fee`;
			return '+25% fee';
		},

		fmtPrice(amount) {
			const currency = (this.priceResult && this.priceResult.price_currency) || 'INR';
			try {
				return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount);
			} catch (e) {
				return `${currency} ${Math.round(amount).toLocaleString()}`;
			}
		},

		selectSize(i) {
			this.selectedIndex = i;
			this.priceResult = null;
			this.estimate();
		},

		/** Null when this size has no vendor-entered cm value and sizeUnit is
		 * 'cm' — callers hide the button rather than falling back to a
		 * computed ft->cm conversion (cm is vendor-typed only, never derived;
		 * see DRC_Size_Format's header comment). Selection itself is tracked
		 * by array index (selectedIndex), not by this label's text, so
		 * switching units can never strand a selection the way a
		 * value-based selection would — the toggle always stays usable. */
		sizeLabel(size) {
			if (this.sizeUnit === 'cm') return size.cm ? `${size.cm} cm` : null;
			return `${size.ft} ft`;
		},

		hasSizeInUnit() {
			return this.variations.some((size) => this.sizeLabel(size));
		},

		selectColor(name) {
			this.selectedColor = name;
		},

		/** The chosen colorway's own photo when it has one, else the rug's
		 * base cover shot — mirrors CustomerRugDetail.tsx's coverImage. */
		coverImage() {
			const option = this.colorOptions.find((c) => c.name === this.selectedColor);
			return (option && option.image_url) || this.coverUrl || '';
		},

		parseFt(ft) {
			const parts = String(ft).toLowerCase().split(/[x×]/).map((p) => parseFloat(p.trim()));
			if (parts.length !== 2 || parts.some(Number.isNaN)) return null;
			return [parts[0] * 0.3048, parts[1] * 0.3048];
		},

		async estimate() {
			if (this.selectedIndex === null) return;
			const size = this.variations[this.selectedIndex];
			const dims = this.parseFt(size.ft);
			if (!dims) return;
			this.calcLoading = true;
			try {
				const res = await fetch(`${this.restUrl}/quotes/estimate`, {
					method: 'POST',
					headers: drcRestHeaders({ 'Content-Type': 'application/json' }),
					body: JSON.stringify({
						product_id: this.productId,
						size_w: dims[0],
						size_h: dims[1],
						material_id: this.materialId,
						qty: this.qty,
						rush_order: this.rushOrder,
						shape: 'rect',
					}),
				});
				const data = await res.json();
				if (!res.ok) throw new Error(data.message || 'Estimate failed');
				this.priceResult = data;
				if (!data.rush_available && this.rushOrder) this.rushOrder = false;
			} catch (e) {
				console.error('Price estimate failed:', e);
			} finally {
				this.calcLoading = false;
			}
		},

		openQuoteModal() {
			if (this.selectedIndex !== null) {
				const size = this.variations[this.selectedIndex];
				this.quote.size_w = size.ft.split(/[x×]/)[0]?.trim() || '';
				this.quote.size_h = size.ft.split(/[x×]/)[1]?.trim() || '';
				this.quote.unit = 'ft';
				const days = size.lead_time_days || this.leadTimeDays;
				this.quote.expected_delivery = days <= 28 ? 'Within 4 weeks' : days <= 60 ? '1–2 months' : '2–3 months or more';
			}
			if (!this.quote.expected_delivery) this.quote.expected_delivery = 'No preference';
			this.submitError = null;
			this.quoteModalOpen = true;
		},

		async submitQuote() {
			if (!this.isLoggedIn) {
				if (!this.quote.name.trim() || !this.quote.email.trim()) {
					this.submitError = 'Name and email are required.';
					return;
				}
			}
			if (!(parseFloat(this.quote.size_w) > 0) || !(parseFloat(this.quote.size_h) > 0)) {
				this.submitError = 'Enter the requested rug width and length.';
				return;
			}
			if (this.quote.material_preference === 'other' && !this.quote.material_other.trim()) {
				this.submitError = 'Please specify the preferred material.';
				return;
			}
			if (!this.isLoggedIn) {
				window.location.href = this.loginUrl;
				return;
			}
			this.submitting = true;
			this.submitError = null;
			try {
				const res = await fetch(`${this.restUrl}/quotes`, {
					method: 'POST',
					headers: drcRestHeaders({ 'Content-Type': 'application/json' }),
					body: JSON.stringify({
						product_id: this.productId,
						size_w: this.quote.size_w,
						size_h: this.quote.size_h,
						shape: 'rect',
						qty: 1,
						rush_order: false,
						notes: this.quote.notes,
						room_type: this.quote.room_type,
						material_preference: this.quote.material_preference === 'other' ? this.quote.material_other : this.quote.material_preference,
						budget_range: this.quote.budget_range,
						expected_delivery: this.quote.expected_delivery,
						selected_color: this.selectedColor || null,
					}),
				});
				const data = await res.json();
				if (!res.ok) throw new Error(data.message || 'Failed to submit quote.');
				this.quoteModalOpen = false;
				window.location.href = config.myQuotesUrl;
			} catch (e) {
				this.submitError = e.message || 'Failed to submit quote. Please try again.';
			} finally {
				this.submitting = false;
			}
		},
	};
}
