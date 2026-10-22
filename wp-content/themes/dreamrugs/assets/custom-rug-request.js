/**
 * Alpine component backing page-custom-rug-request.php — ported from
 * CustomerCustomRugRequest.tsx's repeatable multi-rug form + image upload.
 * Talks to /dreamrugs/v1/custom-rug-request(/upload-image), both public
 * (guests submit before creating an account), but a logged-in customer's
 * submission still needs X-WP-Nonce sent — without it, WordPress's REST API
 * doesn't recognize the cookie session at all for this request, so the
 * request would save as a guest submission instead of linking to the
 * account, same reasoning as rug-detail.js/my-quotes.js.
 */
function drcRestHeaders(extra) {
	return Object.assign({ 'X-WP-Nonce': window.dreamrugsRest ? window.dreamrugsRest.nonce : '' }, extra || {});
}

const ROOM_TYPES = ['Living Room', 'Bedroom', 'Dining Room', 'Hallway / Entryway', 'Office', 'Outdoor', 'Other'];
// "ASAP / Early Delivery" gets a UI-only "(extra cost)" suffix and triggers
// an amber cost-warning note — see the deliveryLabel()/showRushWarning
// handling below, matching CustomerCustomRugRequest.tsx exactly.
const DELIVERY_OPTIONS = ['No preference', 'ASAP / Early Delivery', 'Within 6-7 weeks', '1–2 months', '2–3 months or more'];
const MAX_IMAGES = 15;
const MAX_RUGS = 10;

const TO_METRES = { ft: 0.3048, m: 1, cm: 0.01 };

let rugKeySeq = 0;
function newRug() {
	return {
		_k: ++rugKeySeq,
		room_type: ROOM_TYPES[0],
		size_w: '',
		size_h: '',
		unit: 'ft',
		material_preference: 'no_preference',
		material_other: '',
		budget_range: '',
		qty: '1',
		expected_delivery: DELIVERY_OPTIONS[0],
		notes: '',
		images: [],
		uploading: false,
	};
}

function customRugRequest(config) {
	return {
		restUrl: config.restUrl,
		loggedIn: config.loggedIn,
		myQuotesUrl: config.myQuotesUrl,
		roomTypes: ROOM_TYPES,
		materials: [],
		materialsError: false,
		deliveryOptions: DELIVERY_OPTIONS,
		maxImages: MAX_IMAGES,

		form: { name: config.name || '', email: config.email || '', phone: '', company: '' },
		rugs: [newRug()],
		submitting: false,
		error: '',
		submitted: null,

		init() {
			fetch(`${this.restUrl}/materials`, { credentials: 'same-origin' })
				.then((res) => { if (!res.ok) throw new Error(); return res.json(); })
				.then((data) => { this.materials = data; })
				.catch(() => { this.materialsError = true; });
		},

		deliveryLabel(d) {
			return d === 'ASAP / Early Delivery' ? 'ASAP / Early Delivery (extra cost)' : d;
		},

		addRug() {
			if (this.rugs.length >= MAX_RUGS) return;
			this.rugs.push(newRug());
		},

		removeRug(i) {
			this.rugs.splice(i, 1);
		},

		async uploadImage(i, event) {
			const file = event.target.files && event.target.files[0];
			event.target.value = '';
			if (!file) return;
			const rug = this.rugs[i];
			if (rug.images.length >= MAX_IMAGES) return;
			rug.uploading = true;
			this.error = '';
			try {
				const fd = new FormData();
				fd.append('file', file);
				const res = await fetch(`${this.restUrl}/custom-rug-request/upload-image`, {
					method: 'POST', credentials: 'same-origin', headers: drcRestHeaders(), body: fd,
				});
				const data = await res.json();
				if (!res.ok) throw new Error(data.message || 'Image upload failed.');
				rug.images.push(data.url);
			} catch (e) {
				this.error = e.message || 'Image upload failed.';
			} finally {
				rug.uploading = false;
			}
		},

		toMetres(value, unit) {
			return parseFloat(value) * (TO_METRES[unit] || 1);
		},

		async submit() {
			this.error = '';
			if (!this.loggedIn && (!this.form.name.trim() || !this.form.email.trim())) {
				this.error = 'Name and email are required.';
				return;
			}
			for (const rug of this.rugs) {
				if (!Number.isInteger(Number(rug.qty)) || Number(rug.qty) < 1 || Number(rug.qty) > 10000) {
					this.error = 'Quantity must be a whole number between 1 and 10,000 for every rug.';
					return;
				}
				if (!String(rug.size_w).trim() || !String(rug.size_h).trim()) {
					this.error = 'Approximate size is required for every rug.';
					return;
				}
				if (rug.material_preference === 'other' && !rug.material_other.trim()) {
					this.error = 'Please specify the material for each rug where you selected "Other".';
					return;
				}
			}

			this.submitting = true;
			try {
				const res = await fetch(`${this.restUrl}/custom-rug-request`, {
					method: 'POST',
					credentials: 'same-origin',
					headers: drcRestHeaders(Object.assign({ 'Content-Type': 'application/json' }, window.drcBot ? window.drcBot.headers(this.$root.querySelector('form')) : {})),
					body: JSON.stringify({
						name: this.form.name.trim(),
						email: this.form.email.trim(),
						phone: this.form.phone || undefined,
						company: this.form.company || undefined,
						items: this.rugs.map((rug) => ({
							room_type: rug.room_type || undefined,
							size_w: this.toMetres(rug.size_w, rug.unit),
							size_h: this.toMetres(rug.size_h, rug.unit),
							material_preference: rug.material_preference === 'other' ? rug.material_other.trim() : rug.material_preference,
							budget_range: rug.budget_range.trim() || undefined,
							qty: Number(rug.qty),
							expected_delivery: rug.expected_delivery || undefined,
							notes: rug.notes || undefined,
							reference_image_urls: rug.images.length > 0 ? rug.images : undefined,
						})),
					}),
				});
				const data = await res.json();
				if (!res.ok) throw new Error(data.message || 'Something went wrong. Please try again.');
				this.submitted = data;
			} catch (e) {
				this.error = e.message || 'Something went wrong. Please try again.';
			} finally {
				this.submitting = false;
			}
		},
	};
}
