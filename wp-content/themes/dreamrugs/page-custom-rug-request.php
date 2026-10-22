<?php
/**
 * Ported from frontend/src/pages/CustomerCustomRugRequest.tsx — the
 * bespoke/no-catalog-match request flow, talking to the
 * /dreamrugs/v1/custom-rug-request(/upload-image) REST routes built for
 * exactly this in class-rest-routes.php. No native WooCommerce equivalent
 * exists (WooCommerce has no concept of "priced after human review" or a
 * repeatable multi-item pre-cart form), so this is a fully custom page.
 *
 * The left/right "Custom Rug Journey" step-tile images are admin-managed
 * (drc_rug_step_image CPT, class-content-types.php) — an earlier
 * version of this template dropped them on the mistaken assumption they
 * were fixed, never-migrated media assets; the original app actually serves
 * them from an admin CRUD screen (CustomRugPageImages.tsx / GET
 * /customer/custom-rug-page-images), so they're wired the same way here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$step_images       = DRC_Content_Types::get_custom_rug_step_images();
$image_split_index = (int) ceil( count( $step_images ) / 2 );
$left_step_images  = array_slice( $step_images, 0, $image_split_index );
$right_step_images = array_slice( $step_images, $image_split_index );

$step_tile = function ( array $step, int $index ) {
	?>
	<div class="text-center">
		<div class="relative aspect-square overflow-hidden bg-stone-100">
			<img src="<?php echo esc_url( $step['image_url'] ); ?>" alt="<?php echo esc_attr( $step['title'] ); ?>" class="h-full w-full object-cover" loading="lazy" />
			<span class="absolute top-1 left-1 w-5 h-5 rounded-full bg-white/90 text-stone-900 text-[10px] font-medium flex items-center justify-center"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
		</div>
		<p class="text-stone-700 text-sm font-medium leading-tight mt-2"><?php echo esc_html( $step['title'] ); ?></p>
	</div>
	<?php
};

get_header();
?>

<div class="w-[94vw] max-w-none mx-auto px-4 py-12" x-data="customRugRequest({ restUrl: '<?php echo esc_js( rest_url( 'dreamrugs/v1' ) ); ?>', loggedIn: <?php echo is_user_logged_in() ? 'true' : 'false'; ?>, name: '<?php echo esc_js( is_user_logged_in() ? wp_get_current_user()->display_name : '' ); ?>', email: '<?php echo esc_js( is_user_logged_in() ? wp_get_current_user()->user_email : '' ); ?>', myQuotesUrl: '<?php echo esc_js( home_url( '/my-quotes' ) ); ?>' })">

	<template x-if="!submitted">
		<div>
			<div class="mx-auto mb-6 flex max-w-[1560px] items-center gap-2 text-xs text-stone-400">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="transition-colors hover:text-stone-900">Home</a>
				<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
				<span class="text-stone-600">Request a Custom Rug</span>
			</div>

			<section class="mx-auto max-w-[1560px] bg-[#f2efe5] px-6 py-14 sm:px-10 lg:px-16 lg:py-20">
				<p class="text-xs font-semibold uppercase tracking-[0.28em] text-stone-500">Bespoke Design</p>
				<h1 class="mt-8 max-w-6xl font-sans text-[clamp(3.25rem,7.2vw,7.5rem)] font-medium uppercase leading-[0.88] tracking-[-0.055em] text-[#171c28]">
					<span class="block">Request a</span>
					<span class="block text-[#aaa8a0]">Custom Rug.</span>
				</h1>
				<p class="mt-12 max-w-2xl text-base leading-relaxed text-stone-500 lg:ml-auto lg:mt-14 lg:text-lg">
					No catalog item in mind? Tell us about your space and vision — size, material, colors,
					budget — and our team will get back to you with a personalized quote within 24–48 hours.
					Need more than one rug? Add as many as you like below — we'll quote them together.
				</p>
			</section>

			<?php if ( ! empty( $step_images ) ) : ?>
				<div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 xl:hidden">
					<?php foreach ( $step_images as $index => $step ) : $step_tile( $step, $index ); endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="mx-auto mt-8 grid max-w-[1560px] items-start gap-8 xl:grid-cols-[minmax(180px,260px)_minmax(0,896px)_minmax(180px,260px)] xl:justify-center">
				<aside class="sticky top-6 hidden space-y-6 xl:block" aria-label="Custom rug inspiration images">
					<?php foreach ( $left_step_images as $index => $step ) : $step_tile( $step, $index ); endforeach; ?>
				</aside>

				<form @submit.prevent="submit()" class="min-w-0 space-y-6" data-drc-bot-form>
					<?php dreamrugs_bot_fields(); ?>

					<template x-if="!loggedIn">
						<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
							<div>
								<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Full Name *</label>
								<input x-model="form.name" required placeholder="Your name" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors" />
							</div>
							<div>
								<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Email *</label>
								<input type="email" x-model="form.email" required placeholder="you@example.com" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors" />
							</div>
							<div>
								<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Phone / WhatsApp</label>
								<input type="tel" x-model="form.phone" placeholder="+91 98765 43210" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors" />
							</div>
							<div>
								<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Company</label>
								<input x-model="form.company" placeholder="Optional" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors" />
							</div>
						</div>
					</template>

					<div class="space-y-5">
						<template x-for="(rug, i) in rugs" :key="rug._k">
							<div class="border border-stone-200 p-5 space-y-5 relative">
								<div class="flex items-center justify-between">
									<p class="text-stone-900 font-serif text-lg font-light" x-text="'Rug ' + (i + 1)"></p>
									<button x-show="rugs.length > 1" type="button" @click="removeRug(i)" class="text-stone-400 hover:text-red-600 transition-colors flex items-center gap-1 text-xs">
										<?php dreamrugs_icon( 'trash-2', 13 ); ?> Remove
									</button>
								</div>

								<div>
									<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Room / Purpose</label>
									<select x-model="rug.room_type" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 text-sm focus:outline-none transition-colors bg-white">
										<template x-for="r in roomTypes" :key="r"><option :value="r" x-text="r"></option></template>
									</select>
								</div>

								<div>
									<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Approximate Size *</label>
									<div class="grid grid-cols-3 gap-3">
										<input type="number" min="0" step="0.1" x-model="rug.size_w" required placeholder="Width" class="border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors" />
										<input type="number" min="0" step="0.1" x-model="rug.size_h" required placeholder="Length" class="border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors" />
										<select x-model="rug.unit" class="border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 text-sm focus:outline-none transition-colors bg-white">
											<option value="ft">ft</option>
											<option value="m">m</option>
											<option value="cm">cm</option>
										</select>
									</div>
								</div>

								<div>
									<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Material Preference</label>
									<select x-model="rug.material_preference" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 text-sm focus:outline-none transition-colors bg-white">
										<option value="no_preference">No preference</option>
										<template x-for="m in materials" :key="m.id"><option :value="m.name" x-text="m.name"></option></template>
										<option value="other">Other</option>
									</select>
									<p x-show="materialsError" class="mt-1 text-xs text-stone-500">Materials could not be loaded. You can specify your preference using Other.</p>
									<input x-show="rug.material_preference === 'other'" x-cloak x-model="rug.material_other" :required="rug.material_preference === 'other'" maxlength="150" placeholder="Tell us the material you have in mind" class="mt-3 w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors" />
								</div>

								<div>
									<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Estimated Budget</label>
									<input x-model="rug.budget_range" type="text" :aria-label="'Estimated budget for rug ' + (i + 1)" maxlength="100" placeholder="e.g. GBP 500–1,000 total or USD 500 per rug" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 text-sm focus:outline-none transition-colors bg-white" />
									<p class="mt-1 text-xs text-stone-400">Include your currency and whether the budget is per rug or total.</p>
								</div>

								<div>
									<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Quantity *</label>
									<input type="number" min="1" max="10000" step="1" required x-model="rug.qty" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 text-sm focus:outline-none bg-white" />
								</div>

								<div>
									<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Expected Delivery (optional)</label>
									<select x-model="rug.expected_delivery" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 text-sm focus:outline-none transition-colors bg-white">
										<template x-for="d in deliveryOptions" :key="d"><option :value="d" x-text="deliveryLabel(d)"></option></template>
									</select>
									<p x-show="rug.expected_delivery === 'ASAP / Early Delivery'" class="mt-1 text-xs text-amber-700">Early delivery incurs an extra cost. The final charge and delivery date will be confirmed in your quote.</p>
								</div>

								<div>
									<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Describe Your Vision</label>
									<textarea x-model="rug.notes" rows="4" maxlength="3000" placeholder="Colors, patterns, inspiration, anything else that helps us understand what you're picturing…" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors resize-none"></textarea>
									<p class="text-stone-400 text-xs mt-1 text-right" x-text="rug.notes.length + '/3000'"></p>
								</div>

								<div>
									<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">
										Reference Images (<span x-text="rug.images.length"></span>/<span x-text="maxImages"></span>)
									</label>
									<div class="flex flex-wrap gap-3">
										<template x-for="url in rug.images" :key="url">
											<div class="relative w-20 h-20 border border-stone-200">
												<img :src="url" alt="Reference" class="w-full h-full object-cover" />
												<button type="button" @click="rug.images = rug.images.filter(u => u !== url)" class="absolute -top-2 -right-2 w-5 h-5 bg-stone-900 text-white rounded-full flex items-center justify-center">
													<?php dreamrugs_icon( 'x', 11 ); ?>
												</button>
											</div>
										</template>
										<label x-show="rug.images.length < maxImages" class="w-20 h-20 border border-dashed border-stone-300 hover:border-stone-500 flex items-center justify-center cursor-pointer transition-colors">
											<span x-show="rug.uploading" class="w-4 h-4 border border-stone-400 border-t-transparent rounded-full animate-spin inline-block"></span>
											<span x-show="!rug.uploading"><?php dreamrugs_icon( 'upload', 16, 'text-stone-400' ); ?></span>
											<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" :disabled="rug.uploading" @change="uploadImage(i, $event)" />
										</label>
									</div>
								</div>
							</div>
						</template>

						<button x-show="rugs.length < 10" type="button" @click="addRug()" class="w-full border border-dashed border-stone-300 hover:border-stone-500 text-stone-500 hover:text-stone-900 text-xs font-medium tracking-widest uppercase py-3 transition-colors flex items-center justify-center gap-2">
							<?php dreamrugs_icon( 'plus', 13 ); ?> Add Another Rug
						</button>
					</div>

					<template x-if="error">
						<div class="flex items-center gap-2 bg-red-50 border border-red-200 p-3 text-red-600 text-xs">
							<?php dreamrugs_icon( 'alert-triangle', 12 ); ?> <span x-text="error"></span>
						</div>
					</template>

					<button type="submit" :disabled="submitting || rugs.some(r => r.uploading)" class="w-full storefront-cta-solid py-4 transition-colors flex items-center justify-center gap-2">
						<span x-show="submitting" class="w-4 h-4 border border-white/30 border-t-white rounded-full animate-spin inline-block"></span>
						<span x-show="!submitting"><?php dreamrugs_icon( 'send', 13 ); ?></span>
						<span x-text="submitting ? 'Submitting…' : (rugs.length > 1 ? ('Submit Request (' + rugs.length + ' Rugs)') : 'Submit Request')"></span>
					</button>
				</form>

				<aside class="sticky top-6 hidden space-y-6 xl:block" aria-label="Custom rug inspiration images">
					<?php foreach ( $right_step_images as $index => $step ) : $step_tile( $step, $index + count( $left_step_images ) ); endforeach; ?>
				</aside>
			</div>
		</div>
	</template>

	<template x-if="submitted">
		<div class="max-w-xl mx-auto py-24 text-center space-y-5">
			<span class="text-green-600 flex justify-center"><?php dreamrugs_icon( 'check-circle', 44 ); ?></span>
			<h1 class="storefront-heading text-3xl">Request Received</h1>
			<p class="text-stone-500 text-sm leading-relaxed" x-text="submitted.message"></p>
			<p x-show="submitted.quote_ids.length > 1" class="text-stone-400 text-xs">
				<span x-text="submitted.quote_ids.length"></span> rugs received as one request — we'll quote and can ship them together.
			</p>
			<div class="flex flex-wrap items-center justify-center gap-4 pt-2">
				<a :href="myQuotesUrl" class="text-sm text-stone-700 hover:text-stone-900 border-b border-stone-300 hover:border-stone-900 pb-0.5 transition-colors">View My Quotes</a>
				<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="text-sm text-stone-700 hover:text-stone-900 border-b border-stone-300 hover:border-stone-900 pb-0.5 transition-colors">Continue Browsing</a>
			</div>
		</div>
	</template>
</div>

<?php get_footer(); ?>
