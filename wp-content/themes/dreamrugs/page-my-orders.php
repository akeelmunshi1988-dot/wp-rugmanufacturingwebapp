<?php
/**
 * Ported from frontend/src/pages/CustomerMyOrders.tsx — a custom page, not a
 * styled-native WooCommerce one, for the same reason page-my-quotes.php is:
 * the rich per-order view here (quote-engine price breakdown, a production
 * timeline, a refund-preview cancel flow, guest-by-email lookup) has no
 * native WooCommerce equivalent. An earlier version of this file just
 * redirected /my-orders to WooCommerce's bare native My Account > Orders
 * tab — that tab has none of the above, so it was a real functionality
 * gap, not a genuine equivalent. Talks to the REST routes in
 * class-customer-orders-rest-routes.php built for exactly this.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
$is_logged_in = is_user_logged_in();
?>

<div class="max-w-3xl mx-auto px-4" x-data="myOrdersList({ restUrl: '<?php echo esc_js( rest_url( 'dreamrugs/v1' ) ); ?>', loggedIn: <?php echo $is_logged_in ? 'true' : 'false'; ?>, loginUrl: '<?php echo esc_js( dreamrugs_login_url( home_url( '/my-orders/' ) ) ); ?>' })">

	<?php if ( $is_logged_in ) : ?>

		<div class="py-14 border-b border-stone-100 flex items-end justify-between gap-4 flex-wrap">
			<div>
				<p class="storefront-eyebrow mb-2">Account</p>
				<h1 class="storefront-heading text-4xl">My Orders</h1>
				<p class="text-stone-400 text-sm mt-1">
					<?php echo esc_html( wp_get_current_user()->display_name ); ?> ·
					<span x-text="total"></span> <span x-text="total !== 1 ? 'orders' : 'order'"></span>
				</p>
			</div>
			<div class="flex items-center gap-4">
				<button @click="fetchOrders()" :disabled="loading" class="flex items-center gap-1.5 text-xs text-stone-400 hover:text-stone-900 transition-colors uppercase tracking-wider">
					<span :class="loading ? 'animate-spin' : ''" class="inline-block"><?php dreamrugs_icon( 'refresh-cw', 12 ); ?></span> Refresh
				</button>
				<a href="<?php echo esc_url( home_url( '/my-quotes' ) ); ?>" class="text-xs text-stone-500 hover:text-stone-900 transition-colors border-b border-stone-300 pb-0.5 uppercase tracking-wider">View My Quotes</a>
			</div>
		</div>

		<div class="py-4 flex gap-2 flex-wrap border-b border-stone-100">
			<template x-for="opt in [ { key: 'all', label: 'All' }, { key: 'pending', label: 'Placed' }, { key: 'processing', label: 'Confirmed' }, { key: 'in_production', label: 'In Production' }, { key: 'shipped', label: 'Shipped' }, { key: 'delivered', label: 'Delivered' } ]" :key="opt.key">
				<button @click="setFilter(opt.key)" class="text-xs px-3 py-1.5 border transition-colors" :class="filter === opt.key ? 'bg-stone-900 text-white border-stone-900' : 'text-stone-500 border-stone-200 hover:border-stone-400 hover:text-stone-900'">
					<span x-text="opt.label"></span>
				</button>
			</template>
		</div>

		<div class="py-3 border-b border-stone-100">
			<div class="flex flex-wrap gap-2 items-end">
				<div>
					<p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Sort</p>
					<div class="relative">
						<select x-model="sortBy" @change="fetchOrders()" class="appearance-none border border-stone-200 text-stone-700 text-xs pl-2.5 pr-7 py-2 focus:outline-none focus:border-stone-400 bg-white">
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
					<button @click="fetchOrders()" class="storefront-cta-solid px-3 py-2">Apply</button>
					<button x-show="hasActiveDateFilter()" @click="clearDateFilters()" class="text-xs px-3 py-2 border border-stone-200 hover:border-stone-400 text-stone-500 hover:text-stone-900 uppercase tracking-wider transition-colors">Clear</button>
				</div>
			</div>
		</div>

		<div class="py-8 space-y-3">
			<template x-if="error"><div class="flex items-center gap-2 bg-red-50 border border-red-200 p-3 text-red-600 text-sm"><?php dreamrugs_icon( 'alert-triangle', 14 ); ?> <span x-text="error"></span></div></template>
			<template x-if="loading"><div class="flex justify-center py-16"><div class="w-6 h-6 border border-stone-400 border-t-stone-900 rounded-full animate-spin"></div></div></template>
			<template x-if="!loading && orders.length === 0">
				<div class="text-center py-20 space-y-4">
					<?php dreamrugs_icon( 'package', 32, 'text-stone-300 mx-auto' ); ?>
					<p class="text-stone-400 text-sm" x-text="filter === 'all' ? 'No orders yet.' : ('No ' + filter.replace('_',' ') + ' orders.')"></p>
				</div>
			</template>

			<?php require __DIR__ . '/template-parts/order-card.php'; ?>

			<template x-if="hasMore()">
				<button @click="loadMore()" :disabled="loadingMore" class="w-full py-3 border border-stone-200 hover:border-stone-400 text-stone-500 hover:text-stone-900 text-xs font-medium tracking-widest uppercase transition-colors disabled:opacity-50 flex items-center justify-center gap-2">
					<span x-show="loadingMore" class="w-3.5 h-3.5 border border-stone-400 border-t-transparent rounded-full animate-spin inline-block"></span>
					<span x-text="loadingMore ? 'Loading…' : ('Load More (' + (total - orders.length) + ' remaining)')"></span>
				</button>
			</template>
		</div>

	<?php else : ?>

		<div class="py-14 border-b border-stone-100">
			<p class="storefront-eyebrow mb-2">Track</p>
			<h1 class="storefront-heading text-4xl">My Orders</h1>
			<p class="text-stone-400 text-sm mt-1">
				Enter your email to track orders, or
				<a href="<?php echo esc_url( dreamrugs_login_url( home_url( '/my-orders/' ) ) ); ?>" class="text-stone-900 underline underline-offset-2 hover:no-underline">sign in</a>
				for invoice access and order management.
			</p>
		</div>

		<div class="py-8 space-y-6">
			<form @submit.prevent="guestSearch()" class="flex gap-2">
				<input type="email" x-model="guestEmail" placeholder="your@email.com" required class="flex-1 border border-stone-200 focus:border-stone-400 px-4 py-3 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors" />
				<button type="submit" :disabled="loading" class="storefront-cta-solid px-6 py-3 transition-colors flex items-center gap-2 flex-shrink-0">
					<span x-show="loading" class="w-4 h-4 border border-white/30 border-t-white rounded-full animate-spin inline-block"></span>
					<span x-show="!loading"><?php dreamrugs_icon( 'search', 13 ); ?></span>
					Look Up
				</button>
			</form>

			<div class="flex items-center gap-3 border border-stone-100 bg-stone-50 px-4 py-3">
				<span class="text-stone-400 flex-shrink-0"><?php dreamrugs_icon( 'log-in', 15 ); ?></span>
				<p class="text-stone-500 text-sm">
					<a href="<?php echo esc_url( dreamrugs_login_url( home_url( '/my-orders/' ) ) ); ?>" class="text-stone-900 underline underline-offset-2 hover:no-underline font-medium">Sign in</a>
					to manage your orders and quotes.
				</p>
			</div>

			<template x-if="error"><div class="flex items-center gap-2 bg-red-50 border border-red-200 p-3 text-red-600 text-sm"><?php dreamrugs_icon( 'alert-triangle', 14 ); ?> <span x-text="error"></span></div></template>

			<template x-if="searched && orders.length === 0">
				<div class="text-center py-16 space-y-3">
					<?php dreamrugs_icon( 'package', 32, 'text-stone-300 mx-auto' ); ?>
					<p class="text-stone-400 text-sm">No orders found for <span class="text-stone-700 font-medium" x-text="searchedEmail"></span>.</p>
				</div>
			</template>

			<template x-if="searched && orders.length > 0">
				<div class="space-y-3">
					<p class="text-stone-400 text-sm">Found <span class="text-stone-900 font-medium" x-text="total"></span> <span x-text="total !== 1 ? 'orders' : 'order'"></span> for <span x-text="searchedEmail"></span></p>
					<?php require __DIR__ . '/template-parts/order-card.php'; ?>
					<template x-if="hasMore()">
						<button @click="loadMore()" :disabled="loadingMore" class="w-full py-3 border border-stone-200 hover:border-stone-400 text-stone-500 hover:text-stone-900 text-xs font-medium tracking-widest uppercase transition-colors disabled:opacity-50">
							<span x-text="loadingMore ? 'Loading…' : ('Load More (' + (total - orders.length) + ' remaining)')"></span>
						</button>
					</template>
				</div>
			</template>

			<template x-if="!searched">
				<div class="text-center py-16 space-y-2">
					<?php dreamrugs_icon( 'search', 28, 'text-stone-300 mx-auto' ); ?>
					<p class="text-stone-400 text-sm">Enter your email above to track your orders</p>
				</div>
			</template>
		</div>

	<?php endif; ?>

	<!-- Cancel confirmation modal -->
	<div x-show="cancelModal !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-stone-900/60 backdrop-blur-sm">
		<div class="w-full max-w-sm bg-white shadow-2xl overflow-hidden" @click.outside="cancelModal = null">
			<div class="flex items-center justify-between px-5 py-4 border-b border-stone-100">
				<h3 class="font-serif text-lg font-light text-stone-900 flex items-center gap-2">
					<span class="text-red-500"><?php dreamrugs_icon( 'alert-triangle', 16 ); ?></span> Cancel Order #<span x-text="cancelTarget ? cancelTarget.order_id : ''"></span>
				</h3>
				<button @click="cancelModal = null" class="text-stone-400 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'x', 18 ); ?></button>
			</div>
			<div class="px-5 py-5 space-y-4">
				<template x-if="cancelModal === 'loading'"><div class="flex items-center justify-center py-6"><div class="w-5 h-5 border-2 border-stone-300 border-t-stone-800 rounded-full animate-spin"></div></div></template>
				<template x-if="cancelModal === 'error'"><p class="text-red-500 text-sm">Could not check cancellation eligibility. Please try again.</p></template>
				<template x-if="cancelModal && cancelModal !== 'loading' && cancelModal !== 'error' && !cancelModal.eligible">
					<div class="flex items-start gap-2 bg-red-50 border border-red-200 p-3 text-red-600 text-sm">
						<span class="flex-shrink-0 mt-0.5"><?php dreamrugs_icon( 'alert-triangle', 14 ); ?></span> <span x-text="cancelModal ? cancelModal.reason : ''"></span>
					</div>
				</template>
				<template x-if="cancelModal && cancelModal !== 'loading' && cancelModal !== 'error' && cancelModal.eligible">
					<div class="bg-stone-50 border border-stone-200 p-4 space-y-2">
						<template x-if="cancelModal.has_payment">
							<p class="text-stone-700 text-sm">This order was paid online. Cancelling now refunds <span class="text-stone-900 font-semibold" x-text="Math.round(cancelModal.refund_pct * 100) + '%'"></span> per policy — <span class="text-stone-900 font-semibold" x-text="fmtPrice(cancelModal.refund_amount, cancelModal.price_currency)"></span> back to your original payment method via Razorpay.</p>
						</template>
						<template x-if="!cancelModal.has_payment">
							<p class="text-stone-500 text-sm">No online payment is on file for this order — cancelling will just update its status.</p>
						</template>
						<p class="text-stone-400 text-xs">Refunds are processed instantly when possible, otherwise within 5–7 business days.</p>
					</div>
				</template>
				<template x-if="cancelError"><div class="flex items-center gap-2 bg-red-50 border border-red-200 p-2.5 text-red-600 text-xs"><?php dreamrugs_icon( 'alert-triangle', 13 ); ?> <span x-text="cancelError"></span></div></template>
			</div>
			<div class="flex gap-3 px-5 pb-5">
				<button @click="cancelModal = null" class="flex-1 py-2.5 border border-stone-200 text-stone-600 text-sm hover:bg-stone-50 transition-colors">Keep Order</button>
				<button @click="confirmCancel()" :disabled="cancelling || cancelModal === 'loading' || cancelModal === 'error' || !(cancelModal && cancelModal.eligible)" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 disabled:opacity-40 text-white text-sm font-medium transition-colors flex items-center justify-center gap-2">
					<span x-show="cancelling" class="w-4 h-4 border border-white/30 border-t-white rounded-full animate-spin inline-block"></span>
					<span x-text="cancelling ? '' : 'Confirm Cancellation'"></span>
				</button>
			</div>
		</div>
	</div>
</div>

<?php get_footer(); ?>
