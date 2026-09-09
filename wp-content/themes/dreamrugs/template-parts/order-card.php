<?php
/**
 * One order card in page-my-orders.php's x-for loop — split into its own
 * file purely because it's used twice on that page (logged-in list and
 * guest-lookup results) and PHP has no equivalent to extracting a JSX
 * component; the actual state/behavior all lives in myOrdersList() in
 * assets/my-orders.js, same "PHP renders markup, one JS file owns
 * interactivity" split as every other Alpine template in this theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<template x-for="order in orders" :key="order.order_id">
	<div class="border border-stone-200">
		<button @click="toggleExpand(order)" class="w-full flex items-center gap-4 px-5 py-4 text-left hover:bg-stone-50 transition-colors">
			<span class="text-stone-400 flex-shrink-0"><?php dreamrugs_icon( 'package', 16 ); ?></span>
			<div class="flex-1 min-w-0">
				<p class="font-serif text-base font-light text-stone-900 truncate" x-text="order.rug_name + (order.item_count > 1 ? (' +' + (order.item_count - 1) + ' more') : '')"></p>
				<p class="text-stone-400 text-xs mt-0.5" x-text="'Order #' + order.order_id + ' · ' + (order.created_at || '—')"></p>
			</div>
			<div class="flex items-center gap-3 flex-shrink-0">
				<template x-if="(order.order_total ?? order.final_price) != null">
					<span class="text-stone-900 font-medium text-sm" x-text="fmtPrice(order.order_total ?? order.final_price, order.price_currency)"></span>
				</template>
				<span class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 border font-medium" :class="statusMeta(order.status).color">
					<span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="statusMeta(order.status).dot"></span>
					<span x-text="statusMeta(order.status).label"></span>
				</span>
				<span class="text-stone-400">
					<template x-if="order.expanded"><?php dreamrugs_icon( 'chevron-up', 13 ); ?></template>
					<template x-if="!order.expanded"><?php dreamrugs_icon( 'chevron-down', 13 ); ?></template>
				</span>
			</div>
		</button>

		<div x-show="order.expanded" x-cloak class="border-t border-stone-100 px-5 py-5 space-y-4 bg-white">

			<template x-if="order.item_count > 1">
				<div class="border border-stone-100 bg-stone-50 divide-y divide-stone-100">
					<template x-for="it in order.items" :key="it.rug_name + it.qty">
						<div class="flex items-center justify-between px-3 py-2 text-sm">
							<span class="text-stone-700" x-text="it.rug_name + ' × ' + it.qty"></span>
							<span class="text-stone-900" x-text="fmtPrice(it.final_price, it.price_currency)"></span>
						</div>
					</template>
				</div>
			</template>

			<div class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-4">
				<div>
					<p class="text-stone-400 text-xs uppercase tracking-widest mb-1" x-text="(order.rug_shape === 'circle' ? 'Diameter' : 'Size') + ' (per piece)'"></p>
					<p class="text-stone-900 text-sm" x-text="order.size"></p>
				</div>
				<div>
					<p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Quantity</p>
					<p class="text-stone-900 text-sm" x-text="order.qty + ' pc' + (order.qty !== 1 ? 's' : '')"></p>
				</div>
				<div>
					<p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Est. Delivery</p>
					<p class="text-stone-900 text-sm flex items-center gap-1"><?php dreamrugs_icon( 'clock', 11, 'text-stone-400' ); ?> <span x-text="order.estimated_delivery ? ('By ' + order.estimated_delivery) : 'TBD'"></span></p>
				</div>
				<div>
					<p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Type</p>
					<p class="text-sm" :class="order.rush_order ? 'text-amber-600' : 'text-stone-500'" x-text="order.rush_order ? 'Rush' : 'Standard'"></p>
				</div>
				<template x-if="order.material_name">
					<div><p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Material</p><p class="text-stone-900 text-sm" x-text="order.material_name"></p></div>
				</template>
				<template x-if="order.weave_type">
					<div><p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Weave</p><p class="text-stone-900 text-sm capitalize" x-text="order.weave_type"></p></div>
				</template>
				<template x-if="order.price_per_piece != null && order.qty > 1">
					<div><p class="text-stone-400 text-xs uppercase tracking-widest mb-1">Price / Piece</p><p class="text-stone-900 text-sm" x-text="fmtPrice(order.price_per_piece, order.price_currency)"></p></div>
				</template>
			</div>

			<!-- Timeline — lazy loaded -->
			<div class="border border-stone-100 bg-stone-50 px-4 py-3">
				<p class="text-stone-400 text-xs uppercase tracking-widest mb-3 flex items-center gap-1.5"><?php dreamrugs_icon( 'clock', 11 ); ?> Order Timeline</p>
				<template x-if="order.timelineLoading"><div class="flex items-center gap-2 py-2"><div class="w-3.5 h-3.5 border border-stone-400 border-t-transparent rounded-full animate-spin"></div><span class="text-stone-400 text-xs">Loading timeline…</span></div></template>
				<template x-if="order.timelineError"><p class="text-stone-400 text-xs py-1">Could not load order timeline.</p></template>
				<template x-if="order.timeline && !order.timelineLoading">
					<div class="space-y-3">
						<template x-for="(entry, i) in order.timeline" :key="i">
							<div class="flex items-start gap-2.5">
								<span class="w-2 h-2 rounded-full flex-shrink-0 mt-1" :class="statusMeta(entry.status).dot"></span>
								<div class="flex-1 flex items-baseline justify-between gap-2">
									<span class="text-stone-800 text-sm" x-text="statusMeta(entry.status).label"></span>
									<span class="text-stone-400 text-xs flex-shrink-0" x-text="fmtDate(entry.at)"></span>
								</div>
							</div>
						</template>
					</div>
				</template>
			</div>

			<!-- Price breakdown — lazy loaded -->
			<div class="border border-stone-100 bg-stone-50 px-4 py-3 space-y-1.5">
				<p class="text-stone-400 text-xs uppercase tracking-widest mb-2 flex items-center gap-1.5"><?php dreamrugs_icon( 'layers', 11 ); ?> Price Calculation</p>
				<template x-if="order.breakdownLoading"><div class="flex items-center gap-2 py-2"><div class="w-3.5 h-3.5 border border-stone-400 border-t-transparent rounded-full animate-spin"></div><span class="text-stone-400 text-xs">Loading breakdown…</span></div></template>
				<template x-if="order.breakdownError"><p class="text-stone-400 text-xs py-1">Could not load detailed breakdown.</p></template>

				<template x-if="order.breakdown && !order.breakdownLoading">
					<div>
						<div class="flex justify-between text-xs pb-1.5 border-b border-stone-200 mb-1">
							<span class="text-stone-500">Rug subtotal</span>
							<span class="text-stone-700" x-text="fmtPrice(order.breakdown.subtotal, order.breakdown.price_currency)"></span>
						</div>
						<template x-for="(line, i) in (order.breakdown.breakdown || []).slice(1)" :key="i">
							<div class="flex justify-between text-xs" :class="lineColor(line)">
								<span x-text="line.rule || line.label || ''"></span>
								<span x-text="(line.amount < 0 ? '−' : '+') + fmtPrice(Math.abs(line.amount), order.breakdown.price_currency)"></span>
							</div>
						</template>
						<template x-if="order.breakdown.gst_inclusive">
							<div>
								<div class="flex justify-between text-xs pt-1.5 border-t border-stone-200">
									<span class="text-stone-400">Pre-tax total</span>
									<span class="text-stone-700" x-text="fmtPrice(order.breakdown.pre_gst_price, order.breakdown.price_currency)"></span>
								</div>
								<template x-if="order.breakdown.gst_split && order.breakdown.gst_split.type === 'cgst_sgst'">
									<div>
										<div class="flex justify-between text-xs"><span class="text-stone-400" x-text="'CGST (' + order.breakdown.gst_split.cgst_pct.toFixed(1) + '%)'"></span><span class="text-stone-700" x-text="'+' + fmtPrice(order.breakdown.gst_amount / 2, order.breakdown.price_currency)"></span></div>
										<div class="flex justify-between text-xs"><span class="text-stone-400" x-text="'SGST (' + order.breakdown.gst_split.sgst_pct.toFixed(1) + '%)'"></span><span class="text-stone-700" x-text="'+' + fmtPrice(order.breakdown.gst_amount / 2, order.breakdown.price_currency)"></span></div>
									</div>
								</template>
								<template x-if="order.breakdown.gst_split && order.breakdown.gst_split.type === 'igst'">
									<div class="flex justify-between text-xs"><span class="text-stone-400" x-text="'IGST (' + order.breakdown.gst_split.igst_pct.toFixed(0) + '%)'"></span><span class="text-stone-700" x-text="'+' + fmtPrice(order.breakdown.gst_amount, order.breakdown.price_currency)"></span></div>
								</template>
							</div>
						</template>
					</div>
				</template>

				<template x-if="!order.breakdown && !order.breakdownLoading && !order.breakdownError && order.final_price != null">
					<div>
						<template x-if="order.base_price != null"><div class="flex justify-between text-xs"><span class="text-stone-400">Subtotal</span><span class="text-stone-700" x-text="fmtPrice(order.base_price, order.price_currency)"></span></div></template>
						<template x-if="order.rush_order"><div class="flex justify-between text-xs"><span class="text-amber-600">Rush surcharge</span><span class="text-amber-600">included</span></div></template>
					</div>
				</template>

				<template x-if="order.final_price != null">
					<div class="flex justify-between text-sm font-medium pt-1.5 border-t border-stone-200">
						<span class="text-stone-900" x-text="(order.breakdown ? order.breakdown.gst_inclusive : order.gst_inclusive) ? 'Total (incl. Tax)' : 'Total'"></span>
						<span class="text-stone-900" x-text="fmtPrice(order.final_price, order.price_currency)"></span>
					</div>
				</template>
			</div>

			<template x-if="order.shipping_address">
				<div class="bg-stone-50 border border-stone-100 px-4 py-3">
					<p class="text-stone-400 text-xs uppercase tracking-widest mb-1 flex items-center gap-1"><?php dreamrugs_icon( 'map-pin', 11 ); ?> Deliver To</p>
					<p class="text-stone-700 text-sm whitespace-pre-line" x-text="order.shipping_address"></p>
				</div>
			</template>

			<template x-if="order.estimated_delivery">
				<div class="flex items-center gap-2 bg-teal-50 border border-teal-100 px-3 py-2">
					<span class="text-teal-600 flex-shrink-0"><?php dreamrugs_icon( 'truck', 13 ); ?></span>
					<p class="text-stone-600 text-xs">Estimated delivery: <span class="text-stone-900 font-medium" x-text="order.estimated_delivery"></span></p>
				</div>
			</template>

			<template x-if="loggedIn && cancellableStatuses.includes(order.status)">
				<div class="flex flex-wrap items-center gap-3">
					<button @click="openCancelModal(order)" class="flex items-center gap-2 border border-red-200 hover:border-red-400 text-red-500 hover:text-red-700 text-xs font-medium px-4 py-2 transition-colors uppercase tracking-wider">
						<?php dreamrugs_icon( 'x-circle', 13 ); ?> Cancel Order
					</button>
				</div>
			</template>
		</div>
	</div>
</template>
