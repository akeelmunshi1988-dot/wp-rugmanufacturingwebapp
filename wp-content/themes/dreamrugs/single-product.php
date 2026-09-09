<?php
/**
 * Ported from frontend/src/pages/CustomerRugDetail.tsx. The single biggest
 * template in this port — covers the image gallery/lightbox, the size
 * configurator with a live price estimate (calls the already-verified
 * /dreamrugs/v1/quotes/estimate REST route), Add to Cart / Buy Now via
 * WooCommerce's native cart, and the full Request-a-Quote modal (submits
 * to /dreamrugs/v1/quotes — same fields/logic as the modal already
 * fixed earlier: dropdown arrows, text color, and skipping personal-detail
 * fields when the customer is already logged in).
 *
 * Deliberate simplification: the original embeds a full custom
 * login/register modal so an unauthenticated visitor can sign up without
 * leaving the quote flow. WordPress already has its own account system
 * (wp-login.php, WooCommerce's My Account registration) — rather than
 * duplicate that as a second parallel auth UI, an unauthenticated
 * "Submit Quote Request" click redirects to wp_login_url() with
 * redirect_to back to this product. Revisit only if product research
 * shows the extra redirect meaningfully hurts quote conversion.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WooCommerce populates the $product global via its own `the_post` hook
// (wc_setup_product_data) — that only fires once the_post() actually runs,
// which a fully custom template (no woocommerce_template_loop Loop
// wrapper) must do explicitly rather than assume is already done.
if ( ! have_posts() ) {
	get_header();
	echo '<p class="max-w-2xl mx-auto px-4 py-32 text-center text-stone-400">Product not found.</p>';
	get_footer();
	return;
}
the_post();
$product    = wc_get_product( get_the_ID() );
$product_id = $product->get_id();

$material_id     = (int) $product->get_meta( '_drc_material_id' );
$material_name   = $product->get_meta( '_drc_material_name' ) ?: 'Selected material';
$material_type   = $product->get_meta( '_drc_material_type' ) ?: '';
$weave_type      = $product->get_meta( '_drc_weave_type' ) ?: '';
$pile_height     = $product->get_meta( '_drc_pile_height' ) ?: '';
$lead_time_days  = (int) ( $product->get_meta( '_drc_lead_time_days' ) ?: 21 );
$about_html      = $product->get_meta( '_drc_about_content_html' ) ?: '';
$show_direct_purchase = (bool) dreamrugs_settings()['show_direct_purchase'];
$stock_quantity  = $product->get_stock_quantity();

// WooCommerce's own (non-AJAX) add-to-cart handler requires a submitted
// attribute_{name} field matching the variation's attribute value, or it
// silently rejects the request with a notice — a bare variation_id is not
// enough. Every DRC-priced variable product is expected to have exactly
// one variation attribute, conventionally named "Size" (slug "size") with
// its value set equal to the variation's own _drc_size_ft meta — see the
// add-to-cart forms below, which submit attribute_<name> accordingly.
$variations             = array();
$size_attribute_name    = 'size';
if ( $product->is_type( 'variable' ) ) {
	foreach ( $product->get_children() as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			continue;
		}
		$ft = $variation->get_meta( '_drc_size_ft' );
		if ( ! $ft ) {
			continue;
		}
		$variation_attrs = $variation->get_attributes();
		if ( ! empty( $variation_attrs ) ) {
			$size_attribute_name = array_key_first( $variation_attrs );
		}
		$variations[] = array(
			'variation_id'    => $variation_id,
			'ft'              => $ft,
			'cm'              => $variation->get_meta( '_drc_size_cm' ) ?: null, // vendor-typed only, never computed from ft — see DRC_Size_Format's header comment
			'attribute_value' => $variation_attrs[ $size_attribute_name ] ?? $ft,
			'price'           => (float) $variation->get_price(),
			'lead_time_days'  => (int) ( $variation->get_meta( '_drc_lead_time_days' ) ?: $lead_time_days ),
		);
	}
}

$gallery_ids    = $product->get_gallery_image_ids();
$gallery_urls   = array_map( fn( $id ) => wp_get_attachment_image_url( $id, 'large' ), $gallery_ids );
$cover_url      = wp_get_attachment_image_url( $product->get_image_id(), 'large' );

// Customer-selectable colorways (DRC_Color_Options) — see that class's
// header comment. Threaded through to the Alpine component below so
// selecting a color can swap the cover shot (and, when this rug has no
// separate gallery, the lifestyle shot too) to that color's own photo,
// mirroring CustomerRugDetail.tsx's coverImage/selectedColorOption logic.
$color_options = DRC_Color_Options::get( $product_id );

$current_user_id = get_current_user_id();
$active_quote     = null;
if ( $current_user_id ) {
	global $wpdb;
	$active_quote = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, status, final_price, price_currency FROM {$wpdb->prefix}drc_quotes
		 WHERE customer_user_id = %d AND product_id = %d AND status IN ('sent','draft')
		 ORDER BY created_at DESC LIMIT 1",
		$current_user_id,
		$product_id
	), ARRAY_A );
}

$room_types            = array( 'Living Room', 'Bedroom', 'Dining Room', 'Hallway / Entryway', 'Office', 'Outdoor', 'Other' );
$quote_materials        = array(
	'wool' => 'Wool', 'silk' => 'Silk', 'cotton' => 'Cotton', 'synthetic' => 'Synthetic',
	'no_preference' => 'No preference', 'other' => 'Other',
);
$quote_delivery         = array( 'No preference', 'ASAP / Rush', 'Within 4 weeks', '1–2 months', '2–3 months or more' );
$default_material_pref  = isset( $quote_materials[ $material_type ] ) ? $material_type : 'other';
$default_room_match     = null;
foreach ( $room_types as $rt ) {
	if ( strtolower( $rt ) === strtolower( str_replace( '_', ' ', ( get_the_terms( $product_id, 'room_type' )[0]->slug ?? '' ) ) ) ) {
		$default_room_match = $rt;
		break;
	}
}

get_header();
?>

<div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8"
	x-data="rugDetail({
		productId: <?php echo (int) $product_id; ?>,
		materialId: <?php echo (int) $material_id; ?>,
		variations: <?php echo esc_attr( wp_json_encode( $variations ) ); ?>,
		colorOptions: <?php echo esc_attr( wp_json_encode( $color_options ) ); ?>,
		coverUrl: '<?php echo esc_js( $cover_url ?: '' ); ?>',
		productTitle: '<?php echo esc_js( $product->get_name() ); ?>',
		leadTimeDays: <?php echo (int) $lead_time_days; ?>,
		restUrl: '<?php echo esc_js( rest_url( 'dreamrugs/v1' ) ); ?>',
		isLoggedIn: <?php echo $current_user_id ? 'true' : 'false'; ?>,
		loginUrl: '<?php echo esc_js( wp_login_url( get_permalink() ) ); ?>',
		myQuotesUrl: '<?php echo esc_js( home_url( '/my-quotes' ) ); ?>',
		customerName: '<?php echo esc_js( $current_user_id ? wp_get_current_user()->display_name : '' ); ?>',
		customerEmail: '<?php echo esc_js( $current_user_id ? wp_get_current_user()->user_email : '' ); ?>',
		defaultRoomType: '<?php echo esc_js( $default_room_match ?: $room_types[0] ); ?>',
		defaultMaterialPref: '<?php echo esc_js( $default_material_pref ); ?>',
		materialOther: '<?php echo esc_js( $material_name ); ?>',
		faqs: <?php echo esc_attr( wp_json_encode( DRC_Content_Types::get_faqs_for_product( $product_id ) ) ); ?>,
	})"
>
	<div class="flex items-center gap-2 text-xs text-stone-400 min-w-0">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hover:text-stone-900 transition-colors">Home</a>
		<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
		<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="hover:text-stone-900 transition-colors">Collection</a>
		<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
		<span class="text-stone-600 truncate"><?php the_title(); ?></span>
	</div>

	<?php if ( $active_quote ) : ?>
		<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4 px-4 py-3 border <?php echo 'sent' === $active_quote['status'] ? 'bg-blue-50 border-blue-200' : 'bg-stone-50 border-stone-200'; ?>">
			<div class="flex items-center gap-3 min-w-0">
				<span class="<?php echo 'sent' === $active_quote['status'] ? 'text-blue-500' : 'text-stone-400'; ?> flex-shrink-0"><?php dreamrugs_icon( 'file-text', 15 ); ?></span>
				<div class="min-w-0">
					<p class="text-sm font-medium <?php echo 'sent' === $active_quote['status'] ? 'text-blue-800' : 'text-stone-600'; ?>">
						<?php echo 'sent' === $active_quote['status'] ? 'Your quote is ready for review' : 'Quote under review'; ?>
					</p>
					<p class="text-xs text-stone-400">
						Quote #<?php echo (int) $active_quote['id']; ?>
						<?php if ( null !== $active_quote['final_price'] && 'sent' === $active_quote['status'] ) : ?>
							· <span class="font-medium text-stone-700"><?php echo wp_kses_post( wc_price( $active_quote['final_price'], array( 'currency' => $active_quote['price_currency'] ) ) ); ?></span>
						<?php endif; ?>
					</p>
				</div>
			</div>
			<a href="<?php echo esc_url( home_url( '/my-quotes' ) ); ?>" class="flex items-center justify-center gap-1.5 text-xs font-medium px-3 py-2 border transition-colors flex-shrink-0 w-full sm:w-auto <?php echo 'sent' === $active_quote['status'] ? 'bg-stone-900 border-stone-900 text-white hover:bg-stone-800' : 'border-stone-300 text-stone-600 hover:border-stone-600 hover:text-stone-900'; ?>">
				<?php echo 'sent' === $active_quote['status'] ? 'Accept / Decline' : 'View Quote'; ?>
			</a>
		</div>
	<?php endif; ?>

	<div class="grid grid-cols-1 lg:grid-cols-5 gap-5 lg:items-stretch mb-10">
		<div class="lg:col-span-2 overflow-hidden bg-stone-100 aspect-[4/5] lg:aspect-auto lg:h-[clamp(470px,42vw,650px)]">
			<template x-if="coverImage()">
				<button type="button" @click="expandedImage = { src: coverImage(), alt: productTitle + (selectedColor ? ' — ' + selectedColor : '') }" class="block w-full h-full cursor-zoom-in">
					<img :key="coverImage()" :src="coverImage()" :alt="productTitle + (selectedColor ? ' — ' + selectedColor : '')" class="w-full h-full object-cover" fetchpriority="high" />
				</button>
			</template>
			<template x-if="!coverImage()">
				<div class="w-full h-full flex items-center justify-center"><?php dreamrugs_icon( 'layers', 48, 'text-stone-300' ); ?></div>
			</template>
		</div>

		<?php if ( empty( $gallery_urls ) ) : ?>
			<div class="lg:col-span-3 overflow-hidden bg-stone-100 aspect-[4/3] lg:aspect-auto lg:h-[clamp(470px,42vw,650px)]">
				<template x-if="coverImage()">
					<button type="button" @click="expandedImage = { src: coverImage(), alt: productTitle + ' in a room setting' }" class="block w-full h-full cursor-zoom-in">
						<img :key="coverImage()" :src="coverImage()" :alt="productTitle + ' in a room setting'" class="w-full h-full object-cover" loading="lazy" />
					</button>
				</template>
				<template x-if="!coverImage()">
					<div class="w-full h-full flex items-center justify-center"><?php dreamrugs_icon( 'layers', 48, 'text-stone-300' ); ?></div>
				</template>
			</div>
		<?php else : ?>
			<div class="lg:col-span-3 relative overflow-hidden bg-stone-100 group aspect-[4/3] lg:aspect-auto lg:h-[clamp(470px,42vw,650px)]" x-data="{ slide: 0, images: <?php echo esc_attr( wp_json_encode( array_values( $gallery_urls ) ) ); ?> }">
				<template x-for="(img, i) in images" :key="i">
					<button type="button" x-show="slide === i" @click="expandedImage = { src: img, alt: '<?php the_title_attribute(); ?> in a room setting' }" class="block w-full h-full cursor-zoom-in">
						<img :src="img" alt="<?php the_title_attribute(); ?> in a room setting" class="w-full h-full object-cover" loading="lazy" />
					</button>
				</template>
				<template x-if="images.length > 1">
					<div>
						<button type="button" @click="slide = (slide - 1 + images.length) % images.length" aria-label="Previous image" class="absolute left-2 top-1/2 -translate-y-1/2 w-10 h-10 lg:w-8 lg:h-8 flex items-center justify-center bg-white/80 hover:bg-white text-stone-700 opacity-100 lg:opacity-0 lg:group-hover:opacity-100 transition-opacity"><?php dreamrugs_icon( 'chevron-left', 16 ); ?></button>
						<button type="button" @click="slide = (slide + 1) % images.length" aria-label="Next image" class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-10 lg:w-8 lg:h-8 flex items-center justify-center bg-white/80 hover:bg-white text-stone-700 opacity-100 lg:opacity-0 lg:group-hover:opacity-100 transition-opacity"><?php dreamrugs_icon( 'chevron-right', 16 ); ?></button>
						<div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex items-center gap-1.5">
							<template x-for="(img, i) in images" :key="'dot'+i">
								<button type="button" @click="slide = i" class="w-5 h-5 flex items-center justify-center">
									<span class="block w-1.5 h-1.5 rounded-full transition-colors" :class="slide === i ? 'bg-white' : 'bg-white/50 hover:bg-white/80'"></span>
								</button>
							</template>
						</div>
					</div>
				</template>
			</div>
		<?php endif; ?>
	</div>

	<div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-center py-8 border-y border-stone-200">
		<section class="lg:col-span-8 space-y-4 min-w-0">
			<div>
				<h1 class="font-serif text-4xl font-light text-stone-900 tracking-tight"><?php the_title(); ?></h1>
				<?php if ( $show_direct_purchase && '' !== $product->get_price() ) : ?>
					<div class="flex flex-wrap items-end gap-x-2 gap-y-1 mt-3">
						<p class="text-stone-900 font-medium text-xl"><?php echo wp_kses_post( dreamrugs_price_span( (float) $product->get_price(), wc_price( $product->get_price() ) ) ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( ! $product->is_in_stock() ) : ?>
				<p class="text-red-500 text-sm font-medium">Out of stock</p>
			<?php elseif ( null !== $stock_quantity && $stock_quantity <= 5 ) : ?>
				<p class="text-amber-600 text-sm">Only <?php echo (int) $stock_quantity; ?> left</p>
			<?php endif; ?>
		</section>

		<section class="lg:col-span-4 space-y-4">
			<button type="button" @click="openQuoteModal()" <?php disabled( ! $product->is_in_stock() ); ?> class="w-full storefront-cta-solid py-4">Request a Quote</button>
			<?php if ( $show_direct_purchase ) : ?>
				<button type="button" onclick="document.getElementById('rug-configurator').scrollIntoView({behavior:'smooth',block:'start'})" <?php disabled( ! $product->is_in_stock() ); ?> class="w-full storefront-cta-outline disabled:border-stone-200 disabled:text-stone-300 py-4">Add to Cart</button>
			<?php endif; ?>
		</section>
	</div>

	<div id="rug-configurator" class="grid grid-cols-1 lg:grid-cols-5 gap-8 lg:gap-12 items-start scroll-mt-32 pt-8 border-t border-stone-100">
		<section class="lg:col-span-3 min-w-0">
			<h2 class="font-serif text-2xl font-light text-stone-900 mb-3">Description</h2>
			<?php if ( $product->get_description() ) : ?>
				<div class="prose-content"><?php echo wp_kses_post( wpautop( $product->get_description() ) ); ?></div>
			<?php elseif ( $about_html ) : ?>
				<div class="prose-content"><?php echo wp_kses_post( $about_html ); ?></div>
			<?php else : ?>
				<p class="text-stone-400 text-sm">No additional description is available.</p>
			<?php endif; ?>

			<?php
			$drc_settings_pdp = dreamrugs_settings();
			$drc_accordion_sections = ! empty( $drc_settings_pdp['product_accordion_sections'] ) ? $drc_settings_pdp['product_accordion_sections'] : array();
			?>
			<div class="mt-6 divide-y divide-stone-200 border-y border-stone-200" x-data="{ open: 'product' }">
				<div>
					<button type="button" @click="open = open === 'product' ? null : 'product'" :aria-expanded="open === 'product'" class="w-full flex items-center justify-between gap-4 py-5 text-left text-stone-900 hover:text-stone-600 transition-colors">
						<span class="text-base font-medium">Product Details</span>
						<span class="text-xl font-light leading-none" aria-hidden="true" x-text="open === 'product' ? '−' : '+'"></span>
					</button>
					<div x-show="open === 'product'" x-cloak class="prose-content text-stone-600 text-sm leading-relaxed pb-6 pr-6">
						<ul>
							<li><strong>Material:</strong> <?php echo esc_html( $material_name ); ?></li>
							<li><strong>Weave:</strong> <?php echo esc_html( $weave_type ?: 'Handcrafted' ); ?></li>
							<li><strong>Pile:</strong> <?php echo esc_html( $pile_height ?: 'Made to order' ); ?></li>
							<li x-show="selectedColor"><strong>Color:</strong> <span x-text="selectedColor"></span></li>
						</ul>
						<?php echo wp_kses_post( $drc_settings_pdp['default_catalog_additional_information_html'] ?? '' ); ?>
					</div>
				</div>
				<?php foreach ( $drc_accordion_sections as $section ) : ?>
					<div>
						<button type="button" @click="open = open === '<?php echo esc_js( $section['id'] ); ?>' ? null : '<?php echo esc_js( $section['id'] ); ?>'" :aria-expanded="open === '<?php echo esc_js( $section['id'] ); ?>'" class="w-full flex items-center justify-between gap-4 py-5 text-left text-stone-900 hover:text-stone-600 transition-colors">
							<span class="text-base font-medium"><?php echo esc_html( $section['title'] ); ?></span>
							<span class="text-xl font-light leading-none" aria-hidden="true" x-text="open === '<?php echo esc_js( $section['id'] ); ?>' ? '−' : '+'"></span>
						</button>
						<div x-show="open === '<?php echo esc_js( $section['id'] ); ?>'" x-cloak class="prose-content text-stone-600 text-sm leading-relaxed pb-6 pr-6"><?php echo wp_kses_post( $section['html'] ); ?></div>
					</div>
				<?php endforeach; ?>
				<?php if ( ! empty( $drc_settings_pdp['catalog_pdf_url'] ) ) : ?>
					<a href="<?php echo esc_url( $drc_settings_pdp['catalog_pdf_url'] ); ?>" target="_blank" rel="noreferrer" class="flex items-center justify-between gap-4 py-5 text-stone-900 hover:text-stone-600 transition-colors">
						<span class="text-base font-medium">Tearsheet</span>
						<?php dreamrugs_icon( 'external-link', 15 ); ?>
					</a>
				<?php endif; ?>
			</div>
		</section>

		<div class="lg:col-span-2">
			<div class="space-y-5 w-full">
				<div class="border border-stone-200">
					<div class="px-5 py-4 border-b border-stone-100">
						<h2 class="font-serif text-2xl font-light text-stone-900">Choose Size &amp; Purchase</h2>
						<p class="text-stone-400 text-sm mt-1">Select an available size for an instant estimate and direct checkout.</p>
					</div>
					<div class="p-4 sm:p-5 space-y-4">
						<?php if ( ! empty( $color_options ) ) : ?>
							<div class="space-y-2">
								<p class="text-stone-400 text-xs font-medium uppercase tracking-widest">Color</p>
								<div class="flex flex-wrap gap-2">
									<template x-for="color in colorOptions" :key="color.name">
										<button
											type="button"
											@click="selectColor(color.name)"
											class="flex items-center gap-2 border px-3 py-2 text-xs transition-colors"
											:class="selectedColor === color.name ? 'border-stone-900 text-stone-900' : 'border-stone-200 text-stone-600 hover:border-stone-400'"
											:aria-pressed="selectedColor === color.name"
										>
											<span class="w-4 h-4 rounded-full border border-black/10" :style="`background-color: ${color.hex}`"></span>
											<span x-text="color.name"></span>
											<template x-if="selectedColor === color.name"><?php dreamrugs_icon( 'check-circle', 12 ); ?></template>
										</button>
									</template>
								</div>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $variations ) ) : ?>
							<div class="space-y-2">
								<div class="flex items-center justify-between gap-3">
									<p class="text-stone-400 text-xs font-medium uppercase tracking-widest">Standard Sizes</p>
									<div class="flex items-center border border-stone-200 p-0.5 flex-shrink-0">
										<template x-for="unit in ['ft', 'cm']" :key="unit">
											<button type="button" @click="sizeUnit = unit" class="px-2 py-1 text-[10px] uppercase tracking-wider transition-colors" :class="sizeUnit === unit ? 'bg-stone-900 text-white' : 'text-stone-400 hover:text-stone-900'" x-text="unit"></button>
										</template>
									</div>
								</div>
								<div class="flex flex-wrap gap-1.5" x-show="hasSizeInUnit()">
									<template x-for="(size, i) in variations" :key="i">
										<button type="button" x-show="sizeLabel(size)" @click="selectSize(i)" class="border px-3 py-1.5 text-xs transition-colors" :class="selectedIndex === i ? 'bg-stone-900 border-stone-900 text-white' : 'border-stone-200 text-stone-600 hover:border-stone-400 hover:text-stone-900'" x-text="sizeLabel(size)"></button>
									</template>
								</div>
								<p class="text-stone-400 text-xs italic" x-show="!hasSizeInUnit()" x-text="'No sizes listed in ' + sizeUnit.toUpperCase() + ' yet.'"></p>
							</div>
						<?php endif; ?>

						<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
							<div>
								<label class="text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider">Quantity</label>
								<input type="number" min="1" x-model.number="qty" @change="estimate()" class="w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 text-sm focus:outline-none transition-colors" />
							</div>
							<div class="flex items-end pb-0.5">
								<label class="flex items-center gap-2 cursor-pointer w-full">
									<div class="relative flex-shrink-0">
										<input type="checkbox" x-model="rushOrder" @change="estimate()" class="sr-only" />
										<div class="w-9 h-5 rounded-full transition-colors" :class="rushOrder ? 'bg-stone-900' : 'bg-stone-200'">
											<div class="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform" :class="rushOrder ? 'translate-x-5' : 'translate-x-0.5'"></div>
										</div>
									</div>
									<div><p class="text-stone-700 text-xs font-medium">Rush</p><p class="text-stone-400 text-xs" x-text="rushLabel()"></p></div>
								</label>
							</div>
						</div>

						<?php if ( ! $show_direct_purchase ) : ?>
							<button type="button" @click="openQuoteModal()" :disabled="selectedIndex === null" class="w-full storefront-cta-solid py-3.5 disabled:opacity-40 disabled:cursor-not-allowed">Request a Quote</button>
						<?php else : ?>
						<template x-if="selectedIndex !== null">
							<div>
								<button type="button" @click="estimate()" :disabled="calcLoading" class="w-full flex items-center justify-center gap-2 text-xs font-medium text-stone-600 hover:text-stone-900 border border-stone-200 hover:border-stone-400 px-3 py-2.5 transition-colors uppercase tracking-wider">
									<span x-show="!calcLoading"><?php dreamrugs_icon( 'zap', 13 ); ?></span>
									<span x-show="calcLoading" class="w-3.5 h-3.5 border border-stone-400 border-t-transparent rounded-full animate-spin inline-block"></span>
									Estimate
								</button>
								<template x-if="priceResult">
									<div class="mt-2 border border-stone-100 bg-stone-50 p-3 space-y-1.5">
										<template x-if="priceResult.rush_surcharge > 0">
											<div class="flex justify-between text-xs"><span class="text-amber-600">Rush fee</span><span class="text-amber-600" x-text="'+' + fmtPrice(priceResult.rush_surcharge)"></span></div>
										</template>
										<div class="flex justify-between text-sm font-medium pt-1 border-t border-stone-200"><span class="text-stone-900">Estimated Total</span><span class="text-stone-900" x-text="fmtPrice(priceResult.final_price)"></span></div>
										<p class="text-stone-400 text-xs" x-text="'Expected delivery: ~' + priceResult.estimated_days + ' days'"></p>
										<template x-if="!priceResult.material_available">
											<div class="border border-amber-200 bg-amber-50 p-3 space-y-2">
												<p class="text-amber-800 text-xs leading-relaxed">Immediate checkout is unavailable for this size and quantity because current material stock is insufficient. This rug is still available for a custom quote.</p>
												<button type="button" @click="openQuoteModal()" class="text-xs font-medium uppercase tracking-wider text-stone-900 border-b border-stone-500 pb-0.5">Request a Quote</button>
											</div>
										</template>
										<div class="flex flex-col sm:flex-row gap-2 mt-1">
											<form method="post" :action="'<?php echo esc_url( wc_get_cart_url() ); ?>'" class="flex-1">
												<input type="hidden" name="add-to-cart" value="<?php echo (int) $product_id; ?>" />
												<input type="hidden" name="variation_id" :value="variations[selectedIndex] ? variations[selectedIndex].variation_id : ''" />
												<input type="hidden" name="quantity" :value="qty" />
												<input type="hidden" name="attribute_<?php echo esc_attr( $size_attribute_name ); ?>" :value="variations[selectedIndex] ? variations[selectedIndex].attribute_value : ''" />
												<input type="hidden" name="drc_rush_order" :value="rushOrder ? '1' : ''" />
													<input type="hidden" name="drc_selected_color" :value="selectedColor" />
												<button type="submit" :disabled="!priceResult.material_available" class="w-full flex items-center justify-center gap-2 border border-stone-300 hover:border-stone-900 disabled:border-stone-200 disabled:text-stone-300 text-stone-900 text-xs font-medium tracking-widest uppercase py-2.5 transition-colors"><?php dreamrugs_icon( 'shopping-cart', 13 ); ?> Add to Cart</button>
											</form>
											<form method="post" :action="'<?php echo esc_url( wc_get_checkout_url() ); ?>'" class="flex-1">
												<input type="hidden" name="add-to-cart" value="<?php echo (int) $product_id; ?>" />
												<input type="hidden" name="variation_id" :value="variations[selectedIndex] ? variations[selectedIndex].variation_id : ''" />
												<input type="hidden" name="quantity" :value="qty" />
												<input type="hidden" name="attribute_<?php echo esc_attr( $size_attribute_name ); ?>" :value="variations[selectedIndex] ? variations[selectedIndex].attribute_value : ''" />
												<input type="hidden" name="drc_rush_order" :value="rushOrder ? '1' : ''" />
													<input type="hidden" name="drc_selected_color" :value="selectedColor" />
												<button type="submit" :disabled="!priceResult.material_available" class="w-full bg-stone-900 hover:bg-stone-800 disabled:bg-stone-200 disabled:text-stone-400 text-white text-xs font-medium tracking-widest uppercase py-2.5 transition-colors">Buy Now</button>
											</form>
										</div>
									</div>
								</template>
							</div>
						</template>

						<template x-if="selectedIndex === null">
							<div class="border border-stone-200 px-4 py-5 text-center">
								<?php dreamrugs_icon( 'zap', 18, 'mx-auto text-stone-300 mb-2' ); ?>
								<p class="text-stone-600 text-sm">Your estimate will appear here</p>
								<p class="text-stone-400 text-xs mt-1">Select a size above to continue.</p>
							</div>
						</template>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<section x-show="faqs.length > 0" class="max-w-4xl border-t border-stone-100 pt-10 pb-4">
		<p class="storefront-eyebrow text-stone-400">Helpful answers</p>
		<h2 class="font-serif text-3xl font-light text-stone-900 mt-2 mb-6">Frequently Asked Questions</h2>
		<div class="divide-y divide-stone-200 border-y border-stone-200">
			<template x-for="item in faqs" :key="item.id">
				<div>
					<button type="button" @click="openFaq = openFaq === item.id ? null : item.id" :aria-expanded="openFaq === item.id" class="w-full flex items-center justify-between gap-4 py-5 text-left text-stone-900">
						<span class="font-medium" x-text="item.question"></span>
						<span class="flex-shrink-0 transition-transform" :class="openFaq === item.id ? 'rotate-180' : ''"><?php dreamrugs_icon( 'chevron-down', 18 ); ?></span>
					</button>
					<p x-show="openFaq === item.id" class="pb-5 pr-10 text-stone-600 text-sm leading-relaxed whitespace-pre-line" x-text="item.answer"></p>
				</div>
			</template>
		</div>
	</section>

	<!-- Full-screen image preview -->
	<template x-if="expandedImage">
		<div role="dialog" aria-modal="true" aria-label="Expanded rug image" class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/90 p-4 sm:p-8" @click="expandedImage = null">
			<button type="button" @click="expandedImage = null" aria-label="Close expanded image" class="absolute right-4 top-4 sm:right-6 sm:top-6 w-10 h-10 flex items-center justify-center bg-white text-stone-900 hover:bg-stone-100 transition-colors"><?php dreamrugs_icon( 'x', 20 ); ?></button>
			<img :src="expandedImage.src" :alt="expandedImage.alt" class="w-full h-full object-contain" @click.stop />
		</div>
	</template>

	<!-- Request a Quote modal -->
	<template x-if="quoteModalOpen">
		<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-stone-900/60 backdrop-blur-sm">
			<div role="dialog" aria-modal="true" aria-label="Request a quote" class="w-full max-w-3xl max-h-[92vh] overflow-y-auto bg-white shadow-2xl">
				<div class="flex items-center justify-between px-5 py-4 border-b border-stone-100">
					<div><p class="text-stone-400 text-xs uppercase tracking-widest">Request a Quote</p><h3 class="font-serif text-xl font-light text-stone-900 mt-0.5"><?php the_title(); ?></h3></div>
					<button @click="quoteModalOpen = false" class="text-stone-400 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'x', 18 ); ?></button>
				</div>
				<div class="p-5 space-y-5">
					<div class="flex gap-3 bg-stone-50 border border-stone-100 p-3">
						<?php if ( $cover_url ) : ?><img src="<?php echo esc_url( $cover_url ); ?>" alt="" class="w-16 h-20 object-contain bg-white" /><?php endif; ?>
						<div class="min-w-0">
							<p class="text-stone-900 text-sm font-medium"><?php the_title(); ?></p>
							<p class="text-stone-500 text-xs mt-1"><?php echo esc_html( $material_name . ( $weave_type ? " · {$weave_type}" : '' ) ); ?></p>
							<p class="text-stone-400 text-xs mt-1">Rug details are pre-selected.</p>
						</div>
					</div>

					<?php if ( $current_user_id ) : ?>
						<div class="flex items-center gap-2 bg-stone-50 border border-stone-200 px-3 py-2.5">
							<span class="text-green-600 flex-shrink-0"><?php dreamrugs_icon( 'check', 13 ); ?></span>
							<div class="min-w-0"><p class="text-stone-900 text-xs font-medium truncate"><?php echo esc_html( wp_get_current_user()->display_name ); ?></p><p class="text-stone-400 text-xs truncate"><?php echo esc_html( wp_get_current_user()->user_email ); ?></p></div>
						</div>
					<?php else : ?>
						<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
							<div><label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Full Name *</label><input x-model="quote.name" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400" /></div>
							<div><label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Email *</label><input type="email" x-model="quote.email" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400" /></div>
							<div><label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Phone / WhatsApp</label><input x-model="quote.phone" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400" /></div>
							<div><label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Company</label><input x-model="quote.company" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400" /></div>
						</div>
					<?php endif; ?>

					<div>
						<label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Requested Size *</label>
						<div class="grid grid-cols-[1fr_1fr_110px] gap-2">
							<div><label class="text-stone-400 text-xs block mb-1">Width</label><input type="number" min="0.1" step="0.1" x-model="quote.size_w" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400" /></div>
							<div><label class="text-stone-400 text-xs block mb-1">Height</label><input type="number" min="0.1" step="0.1" x-model="quote.size_h" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400" /></div>
							<div>
								<label class="text-stone-400 text-xs block mb-1">Unit</label>
								<div class="relative">
									<select x-model="quote.unit" class="w-full appearance-none border border-stone-200 bg-white px-2 pr-7 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400">
										<option value="ft">ft</option><option value="cm">cm</option>
									</select>
									<?php dreamrugs_icon( 'chevron-down', 14, 'pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-stone-400' ); ?>
								</div>
							</div>
						</div>
						<p class="text-stone-400 text-xs mt-1">Enter the exact dimensions you want us to quote.</p>
					</div>

					<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
						<div>
							<label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Room / Purpose</label>
							<div class="relative">
								<select x-model="quote.room_type" class="w-full appearance-none border border-stone-200 bg-white px-3 pr-8 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400">
									<?php foreach ( $room_types as $rt ) : ?><option value="<?php echo esc_attr( $rt ); ?>"><?php echo esc_html( $rt ); ?></option><?php endforeach; ?>
								</select>
								<?php dreamrugs_icon( 'chevron-down', 14, 'pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-stone-400' ); ?>
							</div>
						</div>
						<div>
							<label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Material Preference</label>
							<div class="relative">
								<select x-model="quote.material_preference" class="w-full appearance-none border border-stone-200 bg-white px-3 pr-8 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400">
									<?php foreach ( $quote_materials as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
								</select>
								<?php dreamrugs_icon( 'chevron-down', 14, 'pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-stone-400' ); ?>
							</div>
						</div>
						<template x-if="quote.material_preference === 'other'">
							<div class="sm:col-span-2"><input x-model="quote.material_other" placeholder="Specify material" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400" /></div>
						</template>
						<div>
							<label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Estimated Budget</label>
							<input x-model="quote.budget_range" type="text" maxlength="100" placeholder="e.g. GBP 500–1,000 total or USD 500 per rug" class="w-full border border-stone-200 bg-white px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400" />
						</div>
						<div>
							<label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Expected Delivery</label>
							<div class="relative">
								<select x-model="quote.expected_delivery" class="w-full appearance-none border border-stone-200 bg-white px-3 pr-8 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400">
									<?php foreach ( $quote_delivery as $d ) : ?><option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option><?php endforeach; ?>
								</select>
								<?php dreamrugs_icon( 'chevron-down', 14, 'pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-stone-400' ); ?>
							</div>
						</div>
					</div>

					<div>
						<label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Describe Your Requirements</label>
						<textarea rows="3" maxlength="1500" x-model="quote.notes" placeholder="Colors, placement, changes, or anything else we should know…" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm resize-none focus:outline-none focus:border-stone-400"></textarea>
					</div>

					<template x-if="submitError">
						<div class="flex items-center gap-2 bg-red-50 border border-red-200 p-3 text-red-600 text-xs"><?php dreamrugs_icon( 'alert-triangle', 12 ); ?> <span x-text="submitError"></span></div>
					</template>

					<button type="button" @click="submitQuote()" :disabled="submitting" class="w-full bg-stone-900 hover:bg-stone-800 disabled:bg-stone-200 disabled:text-stone-400 text-white text-xs font-medium tracking-widest uppercase py-3.5 transition-colors flex items-center justify-center gap-2">
						<span x-show="submitting" class="w-4 h-4 border border-white/30 border-t-white rounded-full animate-spin inline-block"></span>
						<span x-text="submitting ? 'Submitting…' : (isLoggedIn ? 'Submit Quote Request' : 'Sign In & Submit')"></span>
					</button>
				</div>
			</div>
		</div>
	</template>
</div>

<?php get_footer(); ?>
