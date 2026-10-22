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
		galleryUrls: config.galleryUrls || [],
		activeSlide: 0,
		expandedIndex: 0,
		productTitle: config.productTitle || '',
		leadTimeDays: config.leadTimeDays,
		restUrl: config.restUrl,
		isLoggedIn: config.isLoggedIn,
		loginUrl: config.loginUrl,

		selectedIndex: config.variations.length ? (config.defaultSizeIndex || 0) : null,
		// Same key as the catalog's ft/cm toggle, so the shopper's choice
		// follows them between pages; else the store's default unit.
		sizeUnit: (function () { try { const u = localStorage.getItem('dreamrugs_measurement_unit'); return u === 'cm' || u === 'ft' ? u : (config.defaultSizeUnit || 'ft'); } catch (e) { return config.defaultSizeUnit || 'ft'; } })(),
		selectedColor: config.colorOptions && config.colorOptions.length ? config.colorOptions[0].name : '',
		qty: 1,
		rushOrder: false,
		priceResult: null,
		calcLoading: false,
		expandedImage: null,
		faqs: config.faqs || [],
		openFaq: null,

		// Showroom layout (port of the original's RugGallery / RugSizeSelector):
		// galleryIndex walks previewImages(); zoomOrigin drives the hover zoom.
		galleryIndex: 0,
		zoomOrigin: null,
		descriptionExpanded: false,
		// Custom Size tile: while on, customW/customH (in sizeUnit) replace the
		// selected catalog size for the quote request.
		customSize: false,
		customW: '',
		customH: '',
		customError: null,

		quoteModalOpen: false,
		submitting: false,
		submitError: null,
		quoteSuccess: null,
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
			qty: 1,
			notes: '',
			reference_image_urls: [],
			uploading: false,
		},

		init() {
			this.$watch('sizeUnit', (u) => { try { localStorage.setItem('dreamrugs_measurement_unit', u); } catch (e) {} });
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

		/** Size tile: back to a catalog size (leaves Custom Size). */
		selectCatalogSize(i) {
			this.customSize = false;
			this.customError = null;
			this.selectSize(i);
		},

		/** Custom Size tile — starts from the selected catalog size's numbers
		 * in the current unit, when that size has them. */
		selectCustomSize() {
			if (!this.customSize && this.selectedIndex !== null && !this.customW && !this.customH) {
				const size = this.variations[this.selectedIndex];
				const label = this.sizeUnit === 'cm' ? size.cm : size.ft;
				const parts = String(label || '').split(/[x×]/).map((p) => parseFloat(p));
				if (parts.length === 2 && parts.every((n) => n > 0)) [this.customW, this.customH] = parts.map(String);
			}
			this.customSize = true;
			this.customError = null;
		},

		/** ft/cm switch. Typed custom dimensions are re-expressed in the new
		 * unit (same rounding as the original's fmtDim: cm whole, ft 1 dp)
		 * rather than reinterpreted, so 6 ft never silently becomes 6 cm. */
		changeUnit(next) {
			const previous = this.sizeUnit;
			if (this.customSize && previous !== next) {
				const convert = (v) => {
					const n = parseFloat(v);
					if (!(n > 0)) return v;
					const m = this.toMetres(n, previous);
					return String(next === 'cm' ? Math.round(m * 100) : Number((m / 0.3048).toFixed(1)));
				};
				this.customW = convert(this.customW);
				this.customH = convert(this.customH);
			}
			this.sizeUnit = next;
		},

		/** "6x9 ft" -> "6 × 9 ft" for the tiles; free-text sizes pass through. */
		sizeTileLabel(size) {
			const label = this.sizeLabel(size);
			return label ? label.replace(/(\d)\s*[x×]\s*(\d)/i, '$1 × $2') : null;
		},

		/** "Request a Personalised Quote": validates a custom size first. */
		requestQuote() {
			if (this.customSize && !(parseFloat(this.customW) > 0 && parseFloat(this.customH) > 0)) {
				this.customError = 'Enter a width and length greater than zero to continue.';
				const field = document.getElementById('custom-size-width');
				if (field) field.focus();
				return;
			}
			this.openQuoteModal();
		},

		/** Gallery: step through previewImages() (cover, then room photos). */
		galleryStep(delta) {
			const n = this.previewImages().length;
			if (n) this.galleryIndex = (this.galleryIndex + delta + n) % n;
		},

		galleryImage() {
			const list = this.previewImages();
			return list[Math.min(this.galleryIndex, list.length - 1)] || null;
		},

		setZoom(event) {
			const rect = event.currentTarget.getBoundingClientRect();
			this.zoomOrigin = `${((event.clientX - rect.left) / rect.width) * 100}% ${((event.clientY - rect.top) / rect.height) * 100}%`;
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
			this.galleryIndex = 0;
		},

		/** Lifestyle panel: gallery photos, or the cover when there are none. */
		lifestyleImages() {
			if (this.galleryUrls.length) return this.galleryUrls;
			return this.coverImage() ? [this.coverImage()] : [];
		},

		/** Lightbox set: cover first, then every gallery photo (previewImages). */
		previewImages() {
			const cover = this.coverImage();
			const list = cover ? [{ src: cover, alt: this.productTitle + (this.selectedColor ? ' — ' + this.selectedColor : '') }] : [];
			this.galleryUrls.filter((u) => u !== cover).forEach((u) => list.push({ src: u, alt: this.productTitle + ' in a room setting' }));
			return list;
		},

		openCarousel(src) {
			const list = this.previewImages();
			const i = list.findIndex((img) => img.src === src);
			this.expandedIndex = i >= 0 ? i : 0;
			this.expandedImage = list[this.expandedIndex] || { src, alt: this.productTitle };
			document.body.style.overflow = 'hidden';
			this._carouselKeys = (e) => {
				if (e.key === 'Escape') this.closeCarousel();
				else if (e.key === 'ArrowLeft') this.showCarouselImage(this.expandedIndex - 1);
				else if (e.key === 'ArrowRight') this.showCarouselImage(this.expandedIndex + 1);
			};
			document.addEventListener('keydown', this._carouselKeys);
		},

		showCarouselImage(index) {
			const list = this.previewImages();
			if (!list.length) return;
			this.expandedIndex = (index + list.length) % list.length;
			this.expandedImage = list[this.expandedIndex];
		},

		closeCarousel() {
			this.expandedImage = null;
			document.body.style.overflow = '';
			if (this._carouselKeys) document.removeEventListener('keydown', this._carouselKeys);
		},

		/** The chosen colorway's own photo when it has one, else the rug's
		 * base cover shot — mirrors CustomerRugDetail.tsx's coverImage. */
		coverImage() {
			const option = this.colorOptions.find((c) => c.name === this.selectedColor);
			return (option && option.image_url) || this.coverUrl || '';
		},

		/** Same conversion as the original's utils/size.ts toMetres() —
		 * wp_drc_quotes.custom_size_w/h are metres, like Quote.custom_size_w/h. */
		toMetres(value, unit) {
			const n = parseFloat(value);
			if (!Number.isFinite(n)) return 0;
			return unit === 'cm' ? n / 100 : n * 0.3048;
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
			if (this.customSize) {
				this.quote.size_w = this.customW;
				this.quote.size_h = this.customH;
				this.quote.unit = this.sizeUnit;
				this.quote.qty = Math.max(1, parseInt(this.qty, 10) || 1);
				if (!this.quote.expected_delivery) this.quote.expected_delivery = 'No preference';
			} else if (this.selectedIndex !== null) {
				const size = this.variations[this.selectedIndex];
				this.quote.size_w = size.ft.split(/[x×]/)[0]?.trim() || '';
				this.quote.size_h = size.ft.split(/[x×]/)[1]?.trim() || '';
				this.quote.unit = 'ft';
				const days = size.lead_time_days || this.leadTimeDays;
				this.quote.expected_delivery = days <= 49 ? 'Within 6-7 weeks' : days <= 60 ? '1–2 months' : '2–3 months or more';
				this.quote.qty = Math.max(1, parseInt(this.qty, 10) || 1);
			}
			if (!this.quote.expected_delivery) this.quote.expected_delivery = 'No preference';
			this.submitError = null;
			this.quoteSuccess = null;
			this.quoteModalOpen = true;
		},

		/** Same endpoint the custom-rug-request page uses (re-encoded to JPEG
		 * server-side); up to 3, like the original's MAX_QUOTE_IMAGES. */
		async uploadReference(file) {
			if (!file || this.quote.reference_image_urls.length >= 3) return;
			this.quote.uploading = true;
			this.submitError = null;
			try {
				const body = new FormData();
				body.append('file', file);
				const res = await fetch(`${this.restUrl}/custom-rug-request/upload-image`, { method: 'POST', body, headers: drcRestHeaders() });
				const data = await res.json();
				if (!res.ok) throw new Error(data.message || 'Reference image upload failed.');
				this.quote.reference_image_urls.push(data.url);
			} catch (e) {
				this.submitError = e.message || 'Reference image upload failed.';
			} finally {
				this.quote.uploading = false;
			}
		},

		async submitQuote() {
			if (!this.isLoggedIn) {
				if (!this.quote.name.trim() || !this.quote.email.trim()) {
					this.submitError = 'Name and email are required.';
					return;
				}
			}
			if (!(parseInt(this.quote.qty, 10) >= 1)) {
				this.submitError = 'Enter a quantity of at least 1.';
				return;
			}
			if (!(parseFloat(this.quote.size_w) > 0) || !(parseFloat(this.quote.size_h) > 0)) {
				this.submitError = 'Enter the requested rug width and length.';
				return;
			}
			if (this.quote.material_preference === 'other' && !this.quote.material_other.trim()) {
				this.submitError = 'Please specify the preferred material.';
				return;
			}
			this.submitting = true;
			this.submitError = null;
			try {
				const res = await fetch(`${this.restUrl}/quotes`, {
					method: 'POST',
					headers: drcRestHeaders(Object.assign({ 'Content-Type': 'application/json' }, window.drcBot ? window.drcBot.headers(document.querySelector('[aria-label="Request a quote"]')) : {})),
					body: JSON.stringify({
						product_id: this.productId,
						size_w: this.toMetres(this.quote.size_w, this.quote.unit),
						size_h: this.toMetres(this.quote.size_h, this.quote.unit),
						shape: 'rect',
						qty: Math.max(1, parseInt(this.quote.qty, 10) || 1),
						rush_order: this.quote.expected_delivery === 'ASAP / Early Delivery',
						reference_image_urls: this.quote.reference_image_urls,
						name: this.quote.name,
						email: this.quote.email,
						phone: this.quote.phone,
						company: this.quote.company,
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
				if (this.isLoggedIn) {
					this.quoteModalOpen = false;
					window.location.href = config.myQuotesUrl;
					return;
				}
				// Guests have no My Quotes page to land on — confirm in place,
				// like the original's inline "Quote Requested" card.
				this.quoteSuccess = data;
			} catch (e) {
				this.submitError = e.message || 'Failed to submit quote. Please try again.';
			} finally {
				this.submitting = false;
			}
		},
	};
}

/** Alpine component for template-parts/rug-reviews.php (RugReviews.tsx). */
function rugReviews(config) {
	return {
		restUrl: config.restUrl,
		summary: config.summary,
		loggedIn: config.loggedIn,
		name: config.name,
		email: config.email,
		formOpen: false,
		rating: 0,
		hover: 0,
		title: '',
		body: '',
		submitting: false,
		error: '',
		thanks: '',

		starFill(avg, n) {
			return Math.max(0, Math.min(1, (avg || 0) - (n - 1))) * 100;
		},

		async submit(form) {
			if (!this.rating) { this.error = 'Please choose a star rating.'; return; }
			if (this.body.trim().length < 10) { this.error = 'Please write at least a sentence about the rug.'; return; }
			this.submitting = true;
			this.error = '';
			try {
				const bot = window.drcBot ? window.drcBot : null;
				const res = await fetch(this.restUrl, {
					method: 'POST',
					headers: drcRestHeaders(Object.assign({ 'Content-Type': 'application/json' }, bot ? bot.headers(form) : {})),
					body: JSON.stringify(Object.assign({ name: this.name.trim(), email: this.email.trim(), rating: this.rating, title: this.title.trim() || null, body: this.body.trim() }, bot ? bot.fields(form) : {})),
				});
				const data = await res.json();
				if (!res.ok) throw new Error(data.message || 'We could not send your review. Please check the form and try again.');
				this.thanks = data.message;
				this.formOpen = false;
				this.rating = 0; this.title = ''; this.body = '';
			} catch (e) {
				this.error = e.message;
			} finally {
				this.submitting = false;
			}
		},
	};
}

