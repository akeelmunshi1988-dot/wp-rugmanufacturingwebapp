/**
 * Cart quantity stepper + remove-item, ported to be AJAX rather than a
 * full-page form postback — matches CustomerCart.tsx, whose Minus/Plus
 * buttons call updateItem() directly with no separate "Update cart" submit
 * step. Mutations go through WooCommerce's own Store API
 * (/wp-json/wc/store/v1/cart/*, nonce action 'wc_store_api' — see
 * window.drcStoreApi below) rather than a custom endpoint, so tax/coupon/
 * stock logic stays exactly WooCommerce's own. After a successful mutation,
 * this re-fetches the current cart page and swaps in the freshly
 * server-rendered #drc-cart-root — reusing this theme's own already-correct
 * PHP price/total formatting instead of re-implementing it in JS.
 *
 * Event delegation on `document` (not the buttons directly) is deliberate:
 * #drc-cart-root's *contents* get replaced wholesale on every refresh, so
 * directly-bound listeners would need re-attaching after every mutation.
 * Delegating from a node that itself survives the innerHTML swap needs no
 * re-binding at all.
 */
(function () {
	'use strict';

	function cartItemRow(el) {
		return el.closest('[data-drc-cart-item-key]');
	}

	async function storeApiFetch(path, body) {
		const cfg = window.drcStoreApi || {};
		const res = await fetch(`${cfg.root}${path}`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Nonce: cfg.nonce || '' },
			body: JSON.stringify(body),
		});
		if (!res.ok) {
			const data = await res.json().catch(() => ({}));
			throw new Error(data.message || 'Could not update your cart.');
		}
		return res.json();
	}

	async function refreshCartRoot() {
		const res = await fetch(window.location.href, { credentials: 'same-origin' });
		const html = await res.text();
		const doc = new DOMParser().parseFromString(html, 'text/html');
		const freshRoot = doc.getElementById('drc-cart-root');
		const currentRoot = document.getElementById('drc-cart-root');
		if (freshRoot && currentRoot) {
			currentRoot.innerHTML = freshRoot.innerHTML;
			// The freshly-injected .drc-price spans start out showing raw
			// store-currency amounts (that's what the server just rendered) —
			// re-run the currency conversion so they reflect whatever the
			// visitor had selected before this refresh, matching every other
			// price already on the page.
			if (window.drcCurrency) window.drcCurrency.reapply();
		}
		// The header's cart-count badge lives outside #drc-cart-root (it's on
		// every page, not just /cart/), so it needs updating separately from
		// the same fetched document rather than being covered by the swap above.
		['drc-header-cart-badge', 'drc-header-cart-mobile'].forEach((id) => {
			const fresh = doc.getElementById(id);
			const current = document.getElementById(id);
			if (!fresh || !current) return;
			const count = parseInt(fresh.dataset.drcCartCount || '0', 10);
			current.dataset.drcCartCount = String(count);
			current.textContent = fresh.textContent;
			if (id === 'drc-header-cart-badge') {
				current.style.display = count > 0 ? '' : 'none';
			}
		});
	}

	function setRowBusy(row, busy) {
		row.querySelectorAll('button').forEach((b) => { b.disabled = busy; });
		row.style.opacity = busy ? '0.5' : '';
	}

	document.addEventListener('click', async (e) => {
		const minusBtn = e.target.closest('[data-drc-qty-minus]');
		const plusBtn = e.target.closest('[data-drc-qty-plus]');
		const removeBtn = e.target.closest('[data-drc-remove-item]');
		if (!minusBtn && !plusBtn && !removeBtn) return;

		const row = cartItemRow(e.target);
		if (!row) return;
		const key = row.dataset.drcCartItemKey;

		setRowBusy(row, true);
		try {
			if (removeBtn) {
				await storeApiFetch('/cart/remove-item', { key });
			} else {
				const valueEl = row.querySelector('[data-drc-qty-value]');
				const current = parseInt(valueEl.textContent, 10) || 1;
				const next = plusBtn ? current + 1 : Math.max(1, current - 1);
				if (next === current) return;
				await storeApiFetch('/cart/update-item', { key, quantity: next });
			}
			await refreshCartRoot();
		} catch (err) {
			alert(err.message || 'Could not update your cart. Please try again.'); // eslint-disable-line no-alert -- a transient, low-frequency failure path; a full toast system is unwarranted here.
			setRowBusy(row, false);
		}
	});
})();
