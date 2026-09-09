<?php
/**
 * Ported from frontend/src/pages/CustomerMyQuotes.tsx — the customer-facing
 * half of the quote-negotiation lifecycle (accept/reject/negotiate/request-
 * review), talking to the REST routes in class-rest-routes.php built for
 * exactly this. No native WooCommerce equivalent exists for any of this —
 * "priced after human review" isn't a WooCommerce concept — so unlike
 * cart/checkout this is a fully custom page, not a styled-native one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

if ( ! is_user_logged_in() ) :
	?>
	<div class="min-h-[60vh] flex items-center justify-center px-6 py-20">
		<div class="text-center space-y-5 max-w-sm">
			<?php dreamrugs_icon( 'file-text', 32, 'text-stone-300 mx-auto' ); ?>
			<h2 class="storefront-heading text-2xl">Sign in to view your quotes</h2>
			<p class="text-stone-400 text-sm">Log in or create an account to see quotes sent to you by our team.</p>
			<a href="<?php echo esc_url( wp_login_url( home_url( '/my-quotes' ) ) ); ?>" class="w-full storefront-cta-solid py-3.5 transition-colors flex items-center justify-center gap-2"><?php dreamrugs_icon( 'log-in', 13 ); ?> Sign In</a>
		</div>
	</div>
	<?php
	get_footer();
	return;
endif;
?>

<div class="max-w-3xl mx-auto px-4" x-data="myQuotesList({ restUrl: '<?php echo esc_js( rest_url( 'dreamrugs/v1' ) ); ?>' })">
	<div class="py-14 border-b border-stone-100 flex items-end justify-between gap-4 flex-wrap">
		<div>
			<p class="storefront-eyebrow mb-2">Account</p>
			<h1 class="storefront-heading text-4xl">My Quotes</h1>
			<p class="text-stone-400 text-sm mt-1">
				<?php echo esc_html( wp_get_current_user()->display_name ); ?> ·
				<span x-text="total"></span> <span x-text="total !== 1 ? 'quotes' : 'quote'"></span>
				<template x-if="actionNeeded > 0">
					<span class="ml-2 bg-blue-600 text-white text-xs px-2 py-0.5 font-medium" x-text="actionNeeded + ' need response'"></span>
				</template>
			</p>
		</div>
		<button @click="fetchQuotes()" :disabled="loading" class="flex items-center gap-1.5 text-xs text-stone-400 hover:text-stone-900 transition-colors uppercase tracking-wider">
			<span :class="loading ? 'animate-spin' : ''" class="inline-block"><?php dreamrugs_icon( 'refresh-cw', 12 ); ?></span> Refresh
		</button>
	</div>

	<div class="py-4 flex gap-2 flex-wrap border-b border-stone-100">
		<template x-for="opt in [ { key: 'all', label: 'All' }, { key: 'sent', label: 'Needs Response' }, { key: 'accepted', label: 'Accepted' }, { key: 'rejected', label: 'Rejected' }, { key: 'draft', label: 'Pending Review' } ]" :key="opt.key">
			<button @click="setFilter(opt.key)" class="text-xs px-3 py-1.5 border transition-colors" :class="filter === opt.key ? 'bg-stone-900 text-white border-stone-900' : 'text-stone-500 border-stone-200 hover:border-stone-400 hover:text-stone-900'">
				<span x-text="opt.label"></span>
				<template x-if="opt.key === 'sent' && actionNeeded > 0"><span class="ml-1.5 bg-blue-600 text-white text-xs px-1.5 rounded-full" x-text="actionNeeded"></span></template>
			</button>
		</template>
	</div>

	<div class="py-3 border-b border-stone-100">
		<div class="flex flex-wrap gap-2 items-end">
			<div>
				<p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Sort</p>
				<div class="relative">
					<select x-model="sortBy" @change="fetchQuotes()" class="appearance-none border border-stone-200 text-stone-700 text-xs pl-2.5 pr-7 py-2 focus:outline-none focus:border-stone-400 bg-white">
						<option value="date_desc">Date — Newest First</option>
						<option value="date_asc">Date — Oldest First</option>
						<option value="price_asc">Price — Low to High</option>
						<option value="price_desc">Price — High to Low</option>
					</select>
					<?php dreamrugs_icon( 'chevron-down', 12, 'pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-stone-400' ); ?>
				</div>
			</div>
			<div>
				<p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Date Range</p>
				<div class="flex items-center gap-1">
					<input type="date" x-model="dateFrom" class="border border-stone-200 text-stone-700 text-xs px-2 py-2 focus:outline-none focus:border-stone-400" />
					<span class="text-stone-300 text-xs">&ndash;</span>
					<input type="date" x-model="dateTo" class="border border-stone-200 text-stone-700 text-xs px-2 py-2 focus:outline-none focus:border-stone-400" />
				</div>
			</div>
			<div class="flex gap-2 pb-0.5">
				<button @click="fetchQuotes()" class="storefront-cta-solid px-3 py-2">Apply</button>
				<button x-show="hasActiveDateFilter()" @click="clearDateFilters()" class="text-xs px-3 py-2 border border-stone-200 hover:border-stone-400 text-stone-500 hover:text-stone-900 uppercase tracking-wider transition-colors">Clear</button>
			</div>
		</div>
	</div>

	<div class="py-8 space-y-3">
		<template x-if="error">
			<div class="flex items-center gap-2 bg-red-50 border border-red-200 p-3 text-red-600 text-sm"><?php dreamrugs_icon( 'alert-triangle', 14 ); ?> <span x-text="error"></span></div>
		</template>

		<template x-if="loading">
			<div class="flex justify-center py-16"><div class="w-6 h-6 border border-stone-400 border-t-stone-900 rounded-full animate-spin"></div></div>
		</template>

		<template x-if="!loading && quotes.length === 0">
			<div class="text-center py-20 space-y-4">
				<?php dreamrugs_icon( 'file-text', 32, 'text-stone-300 mx-auto' ); ?>
				<p class="text-stone-400 text-sm" x-text="filter === 'all' ? 'No quotes yet. Browse the collection and request a quote.' : ('No ' + filter + ' quotes.')"></p>
				<a x-show="filter === 'all'" href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="inline-block text-sm text-stone-500 hover:text-stone-900 transition-colors border-b border-stone-300 pb-0.5">Browse Collection</a>
			</div>
		</template>

		<template x-for="quote in quotes" :key="quote.quote_id">
			<div class="border transition-colors" :class="quote.status === 'sent' ? 'border-blue-200' : 'border-stone-200'">
				<button @click="quote.expanded = !quote.expanded" class="w-full flex items-center gap-4 px-5 py-4 text-left hover:bg-stone-50 transition-colors">
					<template x-if="quote.rug_image_url"><img :src="quote.rug_image_url" :alt="quote.rug_name" class="w-12 h-12 object-cover flex-shrink-0" /></template>
					<template x-if="!quote.rug_image_url"><div class="w-12 h-12 bg-stone-100 flex items-center justify-center flex-shrink-0"><?php dreamrugs_icon( 'file-text', 16, 'text-stone-300' ); ?></div></template>
					<div class="flex-1 min-w-0">
						<p class="font-serif text-base font-light text-stone-900 truncate" x-text="quote.rug_name"></p>
						<p class="text-stone-400 text-xs mt-0.5" x-text="'Quote #' + quote.quote_id + ' · ' + (quote.created_at || '—')"></p>
					</div>
					<div class="flex items-center gap-3 flex-shrink-0">
						<template x-if="quote.final_price !== null"><span class="text-stone-900 font-medium text-sm" x-text="fmtPrice(quote.final_price, quote.price_currency)"></span></template>
						<span class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 border font-medium" :class="statusMeta(quote.status).color">
							<span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="statusMeta(quote.status).dot"></span>
							<span x-text="statusMeta(quote.status).label"></span>
						</span>
						<span class="text-stone-400"><template x-if="quote.expanded"><?php dreamrugs_icon( 'chevron-up', 13 ); ?></template><template x-if="!quote.expanded"><?php dreamrugs_icon( 'chevron-down', 13 ); ?></template></span>
					</div>
				</button>

				<div x-show="quote.expanded" x-cloak class="border-t border-stone-100 px-5 py-5 space-y-4 bg-white">
					<div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
						<div><p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Qty</p><p class="text-stone-900 text-sm" x-text="quote.qty + ' pc' + (quote.qty !== 1 ? 's' : '')"></p></div>
						<div><p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Type</p><p class="text-sm" :class="quote.rush_order ? 'text-amber-600' : 'text-stone-500'" x-text="quote.rush_order ? 'Rush' : 'Standard'"></p></div>
						<div><p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Total</p><p class="text-stone-900 text-sm font-medium" x-text="quote.final_price !== null ? fmtPrice(quote.final_price, quote.price_currency) : 'TBD'"></p></div>
					</div>

					<template x-if="quote.is_custom_request">
						<div class="bg-stone-50 border border-stone-100 px-4 py-3 space-y-3">
							<p class="text-stone-400 text-xs uppercase tracking-widest">Your Custom Request</p>
							<div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
								<div><p class="text-stone-400 text-xs">Room</p><p class="text-stone-800" x-text="quote.room_type || '—'"></p></div>
								<div><p class="text-stone-400 text-xs">Material preference</p><p class="text-stone-800" x-text="quote.material_preference || '—'"></p></div>
								<div><p class="text-stone-400 text-xs">Budget range</p><p class="text-stone-800" x-text="quote.budget_range || '—'"></p></div>
							</div>
						</div>
					</template>

					<template x-if="quote.notes">
						<div class="bg-stone-50 border border-stone-100 px-4 py-3"><p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Your Notes</p><p class="text-stone-600 text-sm" x-text="quote.notes"></p></div>
					</template>
					<template x-if="quote.vendor_notes">
						<div class="bg-blue-50 border border-blue-100 px-4 py-3"><p class="text-blue-500 text-xs uppercase tracking-widest mb-1">Message from Vendor</p><p class="text-stone-700 text-sm" x-text="quote.vendor_notes"></p></div>
					</template>
					<template x-if="quote.customer_response_notes">
						<div class="bg-stone-50 border border-stone-100 px-4 py-3"><p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Your Response</p><p class="text-stone-600 text-sm" x-text="quote.customer_response_notes"></p></div>
					</template>
					<template x-if="quote.has_order && quote.order_id">
						<div class="flex items-center gap-2 bg-green-50 border border-green-100 px-4 py-3">
							<span class="text-green-600 flex-shrink-0"><?php dreamrugs_icon( 'package', 13 ); ?></span>
							<p class="text-stone-700 text-sm">Order #<span x-text="quote.order_id"></span> placed — <a href="<?php echo esc_url( home_url( '/my-orders' ) ); ?>" class="text-stone-900 underline underline-offset-2 hover:no-underline">Track your order</a></p>
						</div>
					</template>

					<template x-if="quote.status === 'draft'">
						<div class="flex items-center justify-between gap-3 pt-1 border-t border-stone-100">
							<div class="text-xs text-stone-400" x-text="(quote.review_request_count < 5 ? ('Review requests: ' + quote.review_request_count + ' / 5') : 'Maximum review requests reached')"></div>
							<div class="flex items-center gap-2">
								<span x-show="quote.reviewMsg" class="text-xs text-stone-500" x-text="quote.reviewMsg"></span>
								<button @click="requestReview(quote)" :disabled="quote.reviewLoading || quote.review_request_count >= 5" class="flex items-center gap-1.5 text-xs px-3 py-1.5 border border-stone-200 hover:border-stone-400 text-stone-500 hover:text-stone-900 transition-colors disabled:opacity-40 uppercase tracking-wider"><?php dreamrugs_icon( 'bell', 11 ); ?> Request Review</button>
							</div>
						</div>
					</template>

					<template x-if="quote.status === 'sent' && !quote.responding">
						<div class="space-y-2 pt-1">
							<template x-if="quote.final_price !== null">
								<button @click="acceptAndPay(quote)" :disabled="quote.actionLoading" class="w-full storefront-cta-solid py-3 transition-colors flex items-center justify-center gap-2">
									<span x-show="quote.actionLoading" class="w-3.5 h-3.5 border border-white/30 border-t-white rounded-full animate-spin inline-block"></span>
									<span x-show="!quote.actionLoading"><?php dreamrugs_icon( 'package', 13 ); ?></span>
									Accept &amp; Proceed to Payment
								</button>
							</template>
							<template x-if="quote.actionError"><div class="flex items-center gap-2 text-red-600 text-xs"><?php dreamrugs_icon( 'alert-triangle', 12 ); ?> <span x-text="quote.actionError"></span></div></template>
							<div class="grid grid-cols-2 gap-2">
								<button @click="quote.responding = 'negotiate'" class="border border-amber-300 hover:border-amber-500 text-amber-700 text-xs font-medium tracking-widest uppercase py-2.5 transition-colors flex items-center justify-center gap-1.5"><?php dreamrugs_icon( 'message-square', 13 ); ?> Negotiate</button>
								<button @click="quote.responding = 'reject'" class="border border-stone-300 hover:border-red-300 text-stone-500 hover:text-red-600 text-xs font-medium tracking-widest uppercase py-2.5 transition-colors flex items-center justify-center gap-1.5"><?php dreamrugs_icon( 'x-circle', 13 ); ?> Decline</button>
							</div>
						</div>
					</template>

					<div x-show="quote.responding === 'negotiate'" x-cloak class="border border-stone-200 p-4 space-y-3" x-data="{ price: '', qty: quote.qty, removeRush: false, leadDays: '', msg: '' }">
						<p class="text-stone-700 text-sm font-medium">Propose a counter-offer</p>
						<p class="text-stone-400 text-xs">Adjust the fields you'd like to negotiate — our team will review and get back to you.</p>
						<div>
							<label class="text-stone-600 text-xs font-medium uppercase tracking-wider block mb-1.5">Proposed total price</label>
							<input type="number" min="0" step="0.01" x-model="price" class="w-full border border-stone-200 focus:border-amber-400 px-3 py-2 text-stone-900 text-sm focus:outline-none transition-colors" />
						</div>
						<div class="grid grid-cols-2 gap-3">
							<div><label class="text-stone-600 text-xs font-medium uppercase tracking-wider block mb-1.5">Quantity</label><input type="number" min="1" x-model="qty" class="w-full border border-stone-200 focus:border-amber-400 px-3 py-2 text-stone-900 text-sm focus:outline-none transition-colors" /></div>
							<div><label class="text-stone-600 text-xs font-medium uppercase tracking-wider block mb-1.5">Requested delivery (days)</label><input type="number" min="1" x-model="leadDays" class="w-full border border-stone-200 focus:border-amber-400 px-3 py-2 text-stone-900 text-sm focus:outline-none transition-colors" /></div>
						</div>
						<label x-show="quote.rush_order" class="flex items-start gap-3 cursor-pointer border border-amber-100 bg-amber-50 px-3 py-2.5">
							<input type="checkbox" x-model="removeRush" class="mt-0.5 flex-shrink-0" />
							<div><p class="text-amber-800 text-xs font-medium">Remove rush fee</p><p class="text-amber-600 text-xs mt-0.5">Switch to standard delivery to reduce the total price</p></div>
						</label>
						<textarea x-model="msg" rows="3" placeholder="Explain your reasoning…" class="w-full border border-stone-200 focus:border-amber-400 px-3 py-2 text-stone-900 text-sm placeholder-stone-300 focus:outline-none transition-colors resize-none"></textarea>
						<template x-if="quote.actionError"><div class="flex items-center gap-2 text-red-600 text-xs"><?php dreamrugs_icon( 'alert-triangle', 12 ); ?> <span x-text="quote.actionError"></span></div></template>
						<div class="flex gap-2">
							<button @click="respond(quote, 'negotiate', { proposed_price: price || null, proposed_qty: qty, remove_rush: removeRush, requested_lead_days: leadDays || null, message: msg })" :disabled="quote.actionLoading" class="flex-1 bg-amber-600 hover:bg-amber-500 text-white text-xs font-medium tracking-widest uppercase py-2.5 transition-colors flex items-center justify-center gap-2"><?php dreamrugs_icon( 'message-square', 13 ); ?> Send Counter-Offer</button>
							<button @click="quote.responding = null" class="px-5 py-2.5 border border-stone-200 hover:border-stone-400 text-stone-500 text-xs transition-colors">Cancel</button>
						</div>
					</div>

					<div x-show="quote.responding === 'reject'" x-cloak class="border border-stone-200 p-4 space-y-3" x-data="{ reason: '' }">
						<p class="text-stone-700 text-sm font-medium">Reason for declining (optional)</p>
						<textarea x-model="reason" rows="3" placeholder="Let us know why you are declining…" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2 text-stone-900 text-sm placeholder-stone-300 focus:outline-none transition-colors resize-none"></textarea>
						<template x-if="quote.actionError"><div class="flex items-center gap-2 text-red-600 text-xs"><?php dreamrugs_icon( 'alert-triangle', 12 ); ?> <span x-text="quote.actionError"></span></div></template>
						<div class="flex gap-2">
							<button @click="respond(quote, 'reject', { reason })" :disabled="quote.actionLoading" class="flex-1 border border-red-300 text-red-600 hover:bg-red-50 text-xs font-medium tracking-widest uppercase py-2.5 transition-colors flex items-center justify-center gap-2"><?php dreamrugs_icon( 'x-circle', 13 ); ?> Confirm Decline</button>
							<button @click="quote.responding = null" class="px-5 py-2.5 border border-stone-200 hover:border-stone-400 text-stone-500 text-xs transition-colors">Cancel</button>
						</div>
					</div>
				</div>
			</div>
		</template>
	</div>
</div>

<?php get_footer(); ?>
