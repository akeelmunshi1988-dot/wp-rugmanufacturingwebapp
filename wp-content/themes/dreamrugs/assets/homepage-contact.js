/**
 * Alpine component backing front-page.php's "Have Questions? Get in Touch!"
 * section — ported from CustomerHome.tsx's handleContactSubmit(), posting
 * to POST /dreamrugs/v1/homepage-enquiry (the WP equivalent of the original
 * app's POST /api/customer/homepage-enquiries). Public/unauthenticated, but
 * still sends X-WP-Nonce for the same reason every other public dreamrugs/v1
 * POST here does (custom-rug-request.js, rug-detail.js) — without it a
 * logged-in visitor's submission would still work, but WordPress's REST API
 * needs the nonce to recognize the cookie session at all for any state check.
 */
function drcHomepageContactHeaders(extra) {
	return Object.assign({ 'X-WP-Nonce': window.dreamrugsRest ? window.dreamrugsRest.nonce : '' }, extra || {});
}

function homepageContact(config) {
	return {
		restUrl: config.restUrl,
		form: { name: '', email: '', subject: '', message: '', consent: false },
		submitting: false,
		submitted: false,
		error: '',

		async submit() {
			this.error = '';
			this.submitting = true;
			try {
				const res = await fetch(`${this.restUrl}/homepage-enquiry`, {
					method: 'POST',
					credentials: 'same-origin',
					headers: drcHomepageContactHeaders(Object.assign({ 'Content-Type': 'application/json' }, window.drcBot ? window.drcBot.headers(this.$root.querySelector('form')) : {})),
					body: JSON.stringify(this.form),
				});
				const data = await res.json().catch(() => ({}));
				if (!res.ok) throw new Error(data.message || 'We could not send your message. Please try again.');
				this.form = { name: '', email: '', subject: '', message: '', consent: false };
				this.submitted = true;
			} catch (e) {
				this.error = e.message || 'We could not send your message. Please try again.';
			} finally {
				this.submitting = false;
			}
		},

		sendAnother() {
			this.submitted = false;
		},
	};
}
