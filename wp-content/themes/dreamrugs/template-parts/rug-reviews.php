<?php
/**
 * Ported from components/RugReviews.tsx — summary (average, stars,
 * per-star bars), approved reviews and the "Write a review" form. Data and
 * submission go through DRC_Reviews (WooCommerce product reviews, held for
 * approval under Products → Reviews).
 *
 * @var WC_Product $product
 * @var array      $drc_review_summary DRC_Reviews::summary()
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$drc_user = wp_get_current_user();
?>
<section id="reviews" class="scroll-mt-32 max-w-4xl border-t border-stone-100 pt-10 pb-4"
	x-data="rugReviews({
		restUrl: '<?php echo esc_js( rest_url( 'dreamrugs/v1/customer/products/' . $product->get_id() . '/reviews' ) ); ?>',
		summary: <?php echo esc_attr( wp_json_encode( $drc_review_summary ) ); ?>,
		name: '<?php echo esc_js( $drc_user->ID ? $drc_user->display_name : '' ); ?>',
		email: '<?php echo esc_js( $drc_user->ID ? $drc_user->user_email : '' ); ?>',
		loggedIn: <?php echo $drc_user->ID ? 'true' : 'false'; ?>,
	})">
	<p class="storefront-eyebrow text-stone-400">What customers say</p>
	<div class="mt-2 mb-6 flex flex-wrap items-end justify-between gap-4">
		<h2 class="font-serif text-3xl font-light text-stone-900">Reviews</h2>
		<button type="button" x-show="!formOpen" @click="formOpen = true; thanks = ''" class="storefront-cta-outline px-6 py-3">Write a review</button>
	</div>

	<template x-if="summary.review_count > 0">
		<div class="mb-8 grid gap-6 border-y border-stone-200 py-6 sm:grid-cols-[auto_1fr] sm:gap-12">
			<div>
				<p class="font-serif text-5xl font-light text-stone-900" x-text="Number(summary.average_rating).toFixed(1)"></p>
				<span class="mt-2 inline-flex items-center gap-0.5" :aria-label="summary.average_rating + ' out of 5 stars'">
					<template x-for="n in 5" :key="'avg' + n">
						<span class="relative inline-block w-[18px] h-[18px]">
							<span class="absolute inset-0 text-stone-300"><?php dreamrugs_icon( 'star', 18 ); ?></span>
							<span class="absolute inset-0 overflow-hidden text-[#85501b] [&_svg]:fill-current" :style="'width:' + starFill(summary.average_rating, n) + '%'"><?php dreamrugs_icon( 'star', 18 ); ?></span>
						</span>
					</template>
				</span>
				<p class="mt-1 text-sm text-stone-500" x-text="summary.review_count + (summary.review_count === 1 ? ' review' : ' reviews')"></p>
			</div>
			<div class="space-y-1.5 self-center">
				<template x-for="n in [5, 4, 3, 2, 1]" :key="'bar' + n">
					<div class="flex items-center gap-3 text-sm text-stone-600">
						<span class="w-12 flex-shrink-0" x-text="n + ' star'"></span>
						<span class="h-2 flex-1 bg-stone-100"><span class="block h-2 bg-[#85501b]" :style="'width:' + (summary.review_count ? (summary.rating_counts[n] || 0) / summary.review_count * 100 : 0) + '%'"></span></span>
						<span class="w-6 text-right tabular-nums text-stone-500" x-text="summary.rating_counts[n] || 0"></span>
					</div>
				</template>
			</div>
		</div>
	</template>

	<p x-show="thanks" x-cloak class="mb-6 border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" x-text="thanks"></p>

	<form x-show="formOpen" x-cloak @submit.prevent="submit($el)" data-drc-bot-form class="mb-10 space-y-4 border border-stone-200 bg-stone-50 p-5 sm:p-6">
		<p class="font-serif text-xl text-stone-900">Review <?php echo esc_html( $product->get_name() ); ?></p>
		<div>
			<p class="mb-2 text-xs font-semibold uppercase tracking-widest text-stone-500">Your rating</p>
			<div class="flex items-center gap-3">
				<div class="flex items-center gap-1" role="radiogroup" aria-label="Your rating" @mouseleave="hover = 0">
					<template x-for="n in 5" :key="'pick' + n">
						<button type="button" role="radio" :aria-checked="rating === n" :aria-label="n + (n > 1 ? ' stars' : ' star')" @mouseenter="hover = n" @click="rating = n; error = ''" class="p-0.5" :class="n <= (hover || rating) ? 'text-[#85501b] [&_svg]:fill-current' : 'text-stone-300'"><?php dreamrugs_icon( 'star', 26 ); ?></button>
					</template>
				</div>
				<span x-show="hover || rating" class="text-sm text-stone-600" x-text="['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'][hover || rating]"></span>
			</div>
		</div>
		<div class="grid gap-4 sm:grid-cols-2">
			<label class="block space-y-1.5">
				<span class="text-xs font-semibold uppercase tracking-widest text-stone-500">Name (shown publicly)</span>
				<input required maxlength="150" x-model="name" class="w-full bg-white border border-stone-300 focus:border-stone-600 px-3 py-2.5 text-sm text-stone-900 placeholder-stone-400 focus:outline-none transition-colors" />
			</label>
			<label class="block space-y-1.5">
				<span class="text-xs font-semibold uppercase tracking-widest text-stone-500">Email (never shown)</span>
				<input required type="email" x-model="email" :disabled="loggedIn" class="w-full bg-white border border-stone-300 focus:border-stone-600 px-3 py-2.5 text-sm text-stone-900 placeholder-stone-400 focus:outline-none transition-colors disabled:bg-stone-100 disabled:text-stone-500" />
			</label>
		</div>
		<label class="block space-y-1.5">
			<span class="text-xs font-semibold uppercase tracking-widest text-stone-500">Title (optional)</span>
			<input maxlength="150" x-model="title" placeholder="Sum it up in a few words" class="w-full bg-white border border-stone-300 focus:border-stone-600 px-3 py-2.5 text-sm text-stone-900 placeholder-stone-400 focus:outline-none transition-colors" />
		</label>
		<label class="block space-y-1.5">
			<span class="text-xs font-semibold uppercase tracking-widest text-stone-500">Your review</span>
			<textarea required minlength="10" maxlength="3000" rows="5" x-model="body" placeholder="How does it look, feel and wear in your space?" class="w-full bg-white border border-stone-300 focus:border-stone-600 px-3 py-2.5 text-sm text-stone-900 placeholder-stone-400 focus:outline-none transition-colors resize-y"></textarea>
		</label>
		<p x-show="error" class="text-sm text-red-600" x-text="error"></p>
		<div class="flex flex-wrap items-center gap-3">
			<button type="submit" :disabled="submitting" class="storefront-cta-solid px-8 py-3" x-text="submitting ? 'Sending…' : 'Submit review'"></button>
			<button type="button" @click="formOpen = false; error = ''" class="px-4 py-3 text-sm text-stone-500 hover:text-stone-900">Cancel</button>
			<p class="text-xs text-stone-400">Reviews appear after a quick check by our team.</p>
		</div>
		<?php if ( function_exists( 'dreamrugs_bot_fields' ) ) { dreamrugs_bot_fields(); } ?>
	</form>

	<p x-show="summary.review_count === 0 && !formOpen" class="text-sm text-stone-500">No reviews yet — be the first to share how this rug looks in your home.</p>
	<div x-show="summary.review_count > 0" class="divide-y divide-stone-200">
		<template x-for="review in summary.reviews" :key="review.id">
			<article class="py-6">
				<div class="flex flex-wrap items-center gap-x-3 gap-y-1">
					<span class="inline-flex items-center gap-0.5" :aria-label="review.rating + ' out of 5 stars'">
						<template x-for="n in 5" :key="review.id + '-' + n">
							<span :class="n <= review.rating ? 'text-[#85501b] [&_svg]:fill-current' : 'text-stone-300'"><?php dreamrugs_icon( 'star', 15 ); ?></span>
						</template>
					</span>
					<h3 x-show="review.title" class="font-medium text-stone-900" x-text="review.title"></h3>
				</div>
				<p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-stone-700" x-text="review.body"></p>
				<p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-500">
					<span class="font-medium text-stone-700" x-text="review.name"></span>
					<span x-show="review.is_verified_buyer" class="inline-flex items-center gap-1 text-green-700"><?php dreamrugs_icon( 'check-circle', 13 ); ?> Verified buyer</span>
					<time x-show="review.created_at" x-text="new Date(review.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })"></time>
				</p>
			</article>
		</template>
	</div>
</section>
