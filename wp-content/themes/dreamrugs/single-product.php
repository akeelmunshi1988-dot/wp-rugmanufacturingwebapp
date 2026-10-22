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

// Sizes (smallest first), the vendor's default size and the variation
// attribute name — see DRC_Product_Data::size_variations(); the add-to-cart
// forms below submit attribute_<name> accordingly.
$drc_sizes           = DRC_Product_Data::size_variations( $product );
$variations          = $drc_sizes['variations'];
$default_size_index  = $drc_sizes['default_index'];
$size_attribute_name = $drc_sizes['attribute_name'];

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
// Same options as CustomerRugDetail.tsx: "No preference", every available
// material by name (the /materials list), then "Other".
global $wpdb;
$quote_materials = array( 'no_preference' => 'No preference' );
foreach ( $wpdb->get_col( "SELECT name FROM {$wpdb->prefix}drc_materials WHERE is_available = 1 ORDER BY name ASC" ) as $drc_mat_name ) {
	$quote_materials[ $drc_mat_name ] = $drc_mat_name;
}
$quote_materials['other'] = 'Other';
$quote_delivery         = array( 'No preference', 'ASAP / Early Delivery', 'Within 6-7 weeks', '1–2 months', '2–3 months or more' );
$default_material_pref  = isset( $quote_materials[ $material_name ] ) ? $material_name : 'no_preference';
$default_room_match     = null;
foreach ( $room_types as $rt ) {
	if ( strtolower( $rt ) === strtolower( str_replace( '_', ' ', ( get_the_terms( $product_id, 'room_type' )[0]->slug ?? '' ) ) ) ) {
		$default_room_match = $rt;
		break;
	}
}

// ── SEO (CustomerRugDetail.tsx <SEO>) ──────────────────────────────
// "Rug" in the title matches what people search; skipped when the name
// already says so. Product JSON-LD with the default size's engine price,
// rating summary, shipping/returns; breadcrumb and FAQ blocks.
$drc_rug_title    = preg_match( '/\b(rug|carpet|dhurrie|runner)s?\b/i', $product->get_name() ) ? $product->get_name() : $product->get_name() . ' Rug';
$drc_desc         = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
$drc_desc         = $drc_desc ?: sprintf( '%s: a %s rug%s, handmade in India and custom-made to your exact size.', $product->get_name(), strtolower( $material_name ), $weave_type ? ', ' . $weave_type : '' );
$drc_cover        = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
$drc_url          = get_permalink( $product_id );
$drc_reviews_seo  = class_exists( 'DRC_Reviews' ) ? DRC_Reviews::summary( $product_id ) : array( 'review_count' => 0 );
$drc_display_price = DRC_Product_Data::display_price( $product, $drc_sizes );
$drc_ship_countries = function_exists( 'WC' ) ? array_keys( WC()->countries->get_shipping_countries() ) : array();
$drc_product_ld = array_filter( array(
	'@context'        => 'https://schema.org',
	'@type'           => 'Product',
	'name'            => $product->get_name(),
	'description'     => $drc_desc,
	'image'           => $drc_cover ?: null,
	'material'        => $material_name,
	'url'             => $drc_url,
	'sku'             => $product->get_sku() ?: null,
	'brand'           => array( '@type' => 'Brand', 'name' => get_bloginfo( 'name' ) ),
	'countryOfOrigin' => 'IN',
	'aggregateRating' => $drc_reviews_seo['review_count'] > 0 ? array( '@type' => 'AggregateRating', 'ratingValue' => $drc_reviews_seo['average_rating'], 'reviewCount' => $drc_reviews_seo['review_count'] ) : null,
	'offers'          => null !== $drc_display_price ? array(
		'@type'                   => 'Offer',
		'price'                   => $drc_display_price,
		'priceCurrency'           => DRC_Multi_Currency::base(),
		'availability'            => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		'url'                     => $drc_url,
		// Custom-made rugs: no returns once delivered (refund policy page).
		'hasMerchantReturnPolicy' => array( '@type' => 'MerchantReturnPolicy', 'applicableCountry' => $drc_ship_countries, 'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted' ),
		'shippingDetails'         => array(
			'@type'               => 'OfferShippingDetails',
			'shippingRate'        => array( '@type' => 'MonetaryAmount', 'value' => (float) ( dreamrugs_settings()['default_shipping_rate'] ?? 0 ), 'currency' => DRC_Multi_Currency::base() ),
			'shippingDestination' => array_map( function ( $c ) { return array( '@type' => 'DefinedRegion', 'addressCountry' => $c ); }, $drc_ship_countries ),
		),
	) : null,
) );
dreamrugs_seo( array(
	'title'       => $drc_rug_title,
	'description' => $drc_desc,
	'canonical'   => $drc_url,
	'jsonld'      => array_values( array_filter( array(
		$drc_product_ld,
		dreamrugs_breadcrumb_jsonld( array( array( 'Home', home_url( '/' ) ), array( 'Collection', get_permalink( wc_get_page_id( 'shop' ) ) ), array( $product->get_name(), $drc_url ) ) ),
		dreamrugs_faq_jsonld( array_map( function ( $f ) { return array( 'q' => $f['question'], 'a' => $f['answer'] ); }, DRC_Content_Types::get_faqs_for_product( $product_id ) ) ),
	) ) ),
) );

get_header();
?>

<div class="w-[94vw] max-w-none mx-auto px-4 sm:px-6 lg:px-8 pt-6 pb-16 space-y-16 lg:space-y-24 text-showroom-charcoal"
	x-data="rugDetail({
		productId: <?php echo (int) $product_id; ?>,
		galleryUrls: <?php echo esc_attr( wp_json_encode( array_values( array_filter( $gallery_urls ) ) ) ); ?>,
		defaultSizeIndex: <?php echo (int) $default_size_index; ?>,
		defaultSizeUnit: '<?php echo esc_js( 'cm' === ( dreamrugs_settings()['default_size_unit'] ?? 'ft' ) ? 'cm' : 'ft' ); ?>',
		materialId: <?php echo (int) $material_id; ?>,
		variations: <?php echo esc_attr( wp_json_encode( $variations ) ); ?>,
		colorOptions: <?php echo esc_attr( wp_json_encode( $color_options ) ); ?>,
		coverUrl: '<?php echo esc_js( $cover_url ?: '' ); ?>',
		productTitle: '<?php echo esc_js( $product->get_name() ); ?>',
		leadTimeDays: <?php echo (int) $lead_time_days; ?>,
		restUrl: '<?php echo esc_js( rest_url( 'dreamrugs/v1' ) ); ?>',
		isLoggedIn: <?php echo $current_user_id ? 'true' : 'false'; ?>,
		loginUrl: '<?php echo esc_js( dreamrugs_login_url( get_permalink() ) ); ?>',
		myQuotesUrl: '<?php echo esc_js( home_url( '/my-quotes' ) ); ?>',
		customerName: '<?php echo esc_js( $current_user_id ? wp_get_current_user()->display_name : '' ); ?>',
		customerEmail: '<?php echo esc_js( $current_user_id ? wp_get_current_user()->user_email : '' ); ?>',
		defaultRoomType: '<?php echo esc_js( $default_room_match ?: $room_types[0] ); ?>',
		defaultMaterialPref: '<?php echo esc_js( $default_material_pref ); ?>',
		materialOther: '<?php echo esc_js( $material_name ); ?>',
		faqs: <?php echo esc_attr( wp_json_encode( DRC_Content_Types::get_faqs_for_product( $product_id ) ) ); ?>,
	})"
>
	<div class="space-y-6">
	<nav aria-label="Breadcrumb" class="flex items-center gap-2 text-[11px] uppercase tracking-[0.18em] text-showroom-grey min-w-0">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hover:text-showroom-charcoal transition-colors">Home</a>
		<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
		<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="hover:text-showroom-charcoal transition-colors">Collection</a>
		<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
		<span class="text-showroom-charcoal truncate" aria-current="page"><?php the_title(); ?></span>
	</nav>

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

	<?php
	// ── Showroom layout (port of CustomerRugDetail.tsx's 60/40 redesign) ──
	$drc_settings_pdp       = dreamrugs_settings();
	$drc_accordion_sections = ! empty( $drc_settings_pdp['product_accordion_sections'] ) ? $drc_settings_pdp['product_accordion_sections'] : array();
	$drc_extra_info_html    = $product->get_meta( '_drc_additional_information_html' ) ?: ( $drc_settings_pdp['default_catalog_additional_information_html'] ?? '' ); // this rug's own text, else the store default
	$drc_short_desc         = trim( wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ) );
	// Plain words ("high", "flat") read better capitalised; measured values ("8-9 MM") stay as entered.
	$drc_pile_label = ( $pile_height && preg_match( '/^[a-z\s_-]+$/', $pile_height ) ) ? ucfirst( str_replace( array( '_', '-' ), ' ', $pile_height ) ) : $pile_height;
	$drc_specs      = array_filter( array(
		array( 'layers', 'Material', $product->get_meta( '_drc_material_name' ) ?: '' ),
		array( 'grid', 'Weaving Technique', $weave_type ),
		array( 'ruler', 'Pile Height', $drc_pile_label ),
		array( 'map-pin', 'Origin', 'Handmade in Bhadohi, India' ),
		array( 'pencil-ruler', 'Customisation', 'Custom sizes & colours on request' ),
		// Production lead time hidden for now at the owner's request (same as the original app).
	), fn( $spec ) => '' !== (string) $spec[2] );
	$drc_rating_summary = class_exists( 'DRC_Reviews' ) ? DRC_Reviews::summary( $product_id ) : null;
	$drc_has_info       = $drc_extra_info_html || $drc_accordion_sections || ! empty( $drc_settings_pdp['catalog_pdf_url'] );
	?>

	<!-- Main product area: gallery 60% / details 40%. DOM order (gallery, details,
	     Good to Know) is the mobile order; on desktop Good to Know moves under the
	     gallery, beside the lower half of the details panel. -->
	<div class="grid grid-cols-1 gap-10 lg:grid-cols-5 lg:gap-x-14 xl:gap-x-20 lg:gap-y-14">

		<!-- Gallery: every frame object-contain on ivory, so a rug's corners are never cropped -->
		<div class="lg:col-span-3 lg:col-start-1 lg:row-start-1 min-w-0 space-y-3">
			<template x-if="galleryImage()">
				<div class="group relative aspect-square overflow-hidden bg-showroom-ivory lg:aspect-[6/5]">
					<button type="button"
						@click="openCarousel(galleryImage().src)"
						@mousemove="setZoom($event)"
						@mouseleave="zoomOrigin = null"
						:aria-label="'View ' + galleryImage().alt + ' full screen'"
						class="block h-full w-full cursor-zoom-in focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-showroom-charcoal">
						<img :key="galleryImage().src" :src="galleryImage().src" :alt="galleryImage().alt" decoding="async"
							:fetchpriority="galleryIndex === 0 ? 'high' : 'auto'"
							:style="zoomOrigin ? 'transform-origin:' + zoomOrigin : ''"
							class="h-full w-full object-contain p-4 sm:p-8 motion-safe:animate-[showroom-fade_450ms_ease-out] motion-safe:transition-transform motion-safe:duration-500 motion-safe:ease-out"
							:class="zoomOrigin ? '[@media(hover:hover)_and_(pointer:fine)]:scale-[1.6]' : ''" />
					</button>
					<span class="pointer-events-none absolute right-3 top-3 flex h-9 w-9 items-center justify-center bg-white/85 text-showroom-charcoal opacity-0 transition-opacity duration-300 group-hover:opacity-100" aria-hidden="true"><?php dreamrugs_icon( 'maximize', 15 ); ?></span>
					<template x-if="previewImages().length > 1">
						<div>
							<button type="button" @click="galleryStep(-1)" aria-label="Previous image" class="absolute left-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center bg-white/85 text-showroom-charcoal transition-opacity duration-300 hover:bg-white lg:opacity-0 lg:group-hover:opacity-100 lg:focus-visible:opacity-100"><?php dreamrugs_icon( 'chevron-left', 18 ); ?></button>
							<button type="button" @click="galleryStep(1)" aria-label="Next image" class="absolute right-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center bg-white/85 text-showroom-charcoal transition-opacity duration-300 hover:bg-white lg:opacity-0 lg:group-hover:opacity-100 lg:focus-visible:opacity-100"><?php dreamrugs_icon( 'chevron-right', 18 ); ?></button>
							<span class="pointer-events-none absolute bottom-3 left-3 bg-white/85 px-2 py-1 text-[11px] tabular-nums tracking-wider text-showroom-grey" x-text="(galleryIndex + 1) + ' / ' + previewImages().length"></span>
						</div>
					</template>
				</div>
			</template>
			<template x-if="!galleryImage()">
				<div class="flex aspect-square items-center justify-center bg-showroom-ivory lg:aspect-[6/5]"><?php dreamrugs_icon( 'layers', 48, 'text-showroom-border' ); ?></div>
			</template>
			<template x-if="previewImages().length > 1">
				<ul class="grid grid-cols-6 gap-2 sm:gap-3" aria-label="Product images">
					<template x-for="(thumb, i) in previewImages()" :key="thumb.src + i">
						<li>
							<button type="button" @click="galleryIndex = i" :aria-label="'Show image ' + (i + 1) + ': ' + thumb.alt" :aria-current="galleryIndex === i ? 'true' : null"
								class="block aspect-square w-full overflow-hidden bg-showroom-ivory outline-none ring-offset-2 transition-all duration-300 focus-visible:ring-1 focus-visible:ring-showroom-charcoal"
								:class="galleryIndex === i ? 'ring-1 ring-showroom-charcoal' : 'opacity-70 hover:opacity-100'">
								<img :src="thumb.src" alt="" loading="lazy" decoding="async" class="h-full w-full object-contain p-1.5" />
							</button>
						</li>
					</template>
				</ul>
			</template>
		</div>

		<!-- Details panel. Sticky only where the whole panel fits under the fixed header, so it can never hide the quote button. -->
		<div class="lg:col-span-2 lg:col-start-4 lg:row-start-1 lg:row-span-2 min-w-0 lg:self-start lg:[@media(min-height:900px)]:sticky lg:[@media(min-height:900px)]:top-[126px]">
			<div class="space-y-8 text-showroom-charcoal">
				<header class="space-y-3">
					<?php if ( $weave_type ) : ?>
						<a href="<?php echo esc_url( home_url( '/weaves/' . sanitize_title( $weave_type ) . '/' ) ); ?>" class="inline-block text-[11px] font-medium uppercase tracking-[0.28em] text-showroom-gold hover:text-showroom-charcoal transition-colors"><?php echo esc_html( $weave_type ); ?> Collection</a>
					<?php endif; ?>
					<h1 class="font-serif text-4xl font-light leading-[1.08] tracking-tight text-showroom-charcoal sm:text-5xl"><?php the_title(); ?></h1>
					<?php $drc_subtitle = array_filter( array( $weave_type, $product->get_meta( '_drc_material_name' ) ?: '' ) ); ?>
					<?php if ( $drc_subtitle ) : ?>
						<p class="text-xs uppercase tracking-[0.2em] text-showroom-grey">
							<?php foreach ( array_values( $drc_subtitle ) as $drc_i => $drc_part ) : ?>
								<span class="inline-block"><?php if ( $drc_i > 0 ) : ?><span class="px-3 text-showroom-border" aria-hidden="true">|</span><?php endif; ?><?php echo esc_html( $drc_part ); ?></span>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
					<?php if ( $drc_rating_summary && $drc_rating_summary['review_count'] > 0 ) : ?>
						<a href="#reviews" class="inline-flex items-center gap-2 text-sm text-showroom-grey hover:text-showroom-charcoal transition-colors">
							<span class="inline-flex items-center gap-0.5">
								<?php for ( $drc_n = 1; $drc_n <= 5; $drc_n++ ) : ?>
									<span class="<?php echo $drc_n <= round( $drc_rating_summary['average_rating'] ) ? 'text-[#85501b] [&_svg]:fill-current' : 'text-stone-300'; ?>"><?php dreamrugs_icon( 'star', 14 ); ?></span>
								<?php endfor; ?>
							</span>
							<span><?php echo esc_html( number_format( (float) $drc_rating_summary['average_rating'], 1 ) . ' · ' . $drc_rating_summary['review_count'] . ( 1 === $drc_rating_summary['review_count'] ? ' review' : ' reviews' ) ); ?></span>
						</a>
					<?php endif; ?>
					<?php if ( $show_direct_purchase && '' !== $product->get_price() ) : ?>
						<p class="text-showroom-charcoal font-medium text-xl"><?php echo wp_kses_post( dreamrugs_price_span( (float) $product->get_price(), wc_price( $product->get_price() ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! $product->is_in_stock() ) : ?>
						<p class="text-red-600 text-sm font-medium">Currently unavailable</p>
					<?php elseif ( null !== $stock_quantity && $stock_quantity <= 5 ) : ?>
						<p class="text-amber-700 text-sm">Only <?php echo (int) $stock_quantity; ?> left</p>
					<?php endif; ?>
				</header>

				<?php if ( $drc_short_desc ) : ?>
					<div>
						<p class="whitespace-pre-line text-[15px] leading-relaxed text-showroom-grey" :class="descriptionExpanded ? '' : 'line-clamp-3'"><?php echo esc_html( $drc_short_desc ); ?></p>
						<?php if ( mb_strlen( $drc_short_desc ) > 180 ) : ?>
							<button type="button" @click="descriptionExpanded = !descriptionExpanded" :aria-expanded="descriptionExpanded" class="mt-2 text-[11px] font-medium uppercase tracking-[0.2em] text-showroom-charcoal border-b border-showroom-gold pb-0.5 hover:text-showroom-gold transition-colors" x-text="descriptionExpanded ? 'Read less' : 'Read more'">Read more</button>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<!-- Specifications -->
				<dl class="divide-y divide-showroom-border border-y border-showroom-border">
					<?php foreach ( $drc_specs as list( $drc_icon, $drc_label, $drc_value ) ) : ?>
						<div class="flex items-center gap-4 py-3">
							<span class="flex-shrink-0 text-showroom-gold" aria-hidden="true"><?php dreamrugs_icon( $drc_icon, 16 ); ?></span>
							<dt class="w-32 flex-shrink-0 text-[11px] uppercase tracking-[0.16em] text-showroom-grey sm:w-40"><?php echo esc_html( $drc_label ); ?></dt>
							<dd class="min-w-0 text-sm text-showroom-charcoal"><?php echo esc_html( $drc_value ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>

				<div id="rug-configurator" class="space-y-8 scroll-mt-32">
					<?php if ( ! empty( $color_options ) ) : ?>
						<div class="space-y-3">
							<p class="text-[11px] font-medium uppercase tracking-[0.22em] text-showroom-charcoal">Colour<span class="ml-2 normal-case tracking-normal font-normal text-showroom-grey" x-text="selectedColor"></span></p>
							<div class="flex flex-wrap gap-2">
								<template x-for="color in colorOptions" :key="color.name">
									<button type="button" @click="selectColor(color.name)" :aria-pressed="selectedColor === color.name"
										class="flex items-center gap-2 border px-3 py-2 text-xs transition-colors duration-300"
										:class="selectedColor === color.name ? 'border-showroom-charcoal text-showroom-charcoal' : 'border-showroom-border text-showroom-grey hover:border-showroom-gold'">
										<span class="w-4 h-4 rounded-full border border-black/10" :style="`background-color: ${color.hex}`"></span>
										<span x-text="color.name"></span>
									</button>
								</template>
							</div>
						</div>
					<?php endif; ?>

					<!-- Size tiles + Custom Size (port of RugSizeSelector.tsx). A size with no
					     vendor-entered cm value is hidden in cm mode, never computed. -->
					<fieldset aria-labelledby="rug-size-label" class="space-y-3">
						<div class="flex items-center justify-between gap-3">
							<span id="rug-size-label" class="text-[11px] font-medium uppercase tracking-[0.22em] text-showroom-charcoal">Select Size</span>
							<div class="flex items-center border border-showroom-border p-0.5" role="group" aria-label="Measurement unit">
								<template x-for="unit in ['ft', 'cm']" :key="unit">
									<button type="button" @click="changeUnit(unit)" :aria-pressed="sizeUnit === unit" x-text="unit"
										class="px-2.5 py-1 text-[10px] uppercase tracking-[0.18em] transition-colors duration-300"
										:class="sizeUnit === unit ? 'bg-showroom-charcoal text-white' : 'text-showroom-grey hover:text-showroom-charcoal'"></button>
								</template>
							</div>
						</div>
						<div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
							<template x-for="(size, i) in variations" :key="i + sizeUnit">
								<template x-if="sizeTileLabel(size)">
									<button type="button" @click="selectCatalogSize(i)" :aria-pressed="!customSize && selectedIndex === i"
										class="relative flex min-h-[52px] items-center justify-center px-3 py-3 text-sm tracking-wide transition-all duration-300 motion-safe:active:scale-[0.98] focus-visible:outline focus-visible:outline-1 focus-visible:outline-offset-2 focus-visible:outline-showroom-charcoal"
										:class="!customSize && selectedIndex === i ? 'border border-showroom-charcoal bg-showroom-charcoal text-white' : 'border border-showroom-border bg-white text-showroom-charcoal hover:border-showroom-gold'">
										<span x-text="sizeTileLabel(size)"></span>
										<span x-show="!customSize && selectedIndex === i" class="absolute right-2 top-2 text-showroom-gold" aria-hidden="true"><?php dreamrugs_icon( 'check', 12 ); ?></span>
									</button>
								</template>
							</template>
							<button type="button" @click="selectCustomSize()" :aria-pressed="customSize" aria-controls="custom-size-fields"
								class="relative flex min-h-[52px] items-center justify-center px-3 py-3 text-sm tracking-wide transition-all duration-300 motion-safe:active:scale-[0.98] focus-visible:outline focus-visible:outline-1 focus-visible:outline-offset-2 focus-visible:outline-showroom-charcoal"
								:class="customSize ? 'border border-showroom-charcoal bg-showroom-charcoal text-white' : 'border border-showroom-border bg-white text-showroom-charcoal hover:border-showroom-gold'">
								Custom Size
								<span x-show="customSize" class="absolute right-2 top-2 text-showroom-gold" aria-hidden="true"><?php dreamrugs_icon( 'check', 12 ); ?></span>
							</button>
						</div>
						<?php if ( ! empty( $variations ) ) : ?>
							<p class="text-xs italic text-showroom-grey" x-show="!hasSizeInUnit()" x-text="'No standard sizes listed in ' + sizeUnit + ' — switch to ' + (sizeUnit === 'cm' ? 'ft' : 'cm') + ' or choose Custom Size.'"></p>
						<?php endif; ?>
						<div id="custom-size-fields" x-show="customSize" x-cloak class="space-y-2 border-l border-showroom-gold/60 pl-4 motion-safe:animate-[showroom-fade_350ms_ease-out]">
							<div class="grid grid-cols-2 gap-3">
								<div>
									<label for="custom-size-width" class="mb-1 block text-[11px] uppercase tracking-[0.18em] text-showroom-grey">Width (<span x-text="sizeUnit"></span>)</label>
									<input id="custom-size-width" type="number" inputmode="decimal" min="0.1" :step="sizeUnit === 'cm' ? '1' : '0.1'" x-model="customW" @input="customError = null" :aria-invalid="!!customError && !(parseFloat(customW) > 0)" :aria-describedby="customError ? 'custom-size-error' : null" class="w-full border border-showroom-border bg-white px-3 py-2.5 text-sm text-showroom-charcoal transition-colors focus:border-showroom-charcoal focus:outline-none" />
								</div>
								<div>
									<label for="custom-size-length" class="mb-1 block text-[11px] uppercase tracking-[0.18em] text-showroom-grey">Length (<span x-text="sizeUnit"></span>)</label>
									<input id="custom-size-length" type="number" inputmode="decimal" min="0.1" :step="sizeUnit === 'cm' ? '1' : '0.1'" x-model="customH" @input="customError = null" :aria-invalid="!!customError && !(parseFloat(customH) > 0)" :aria-describedby="customError ? 'custom-size-error' : null" class="w-full border border-showroom-border bg-white px-3 py-2.5 text-sm text-showroom-charcoal transition-colors focus:border-showroom-charcoal focus:outline-none" />
								</div>
							</div>
							<p class="text-xs text-showroom-grey">Woven to your exact measurements. Use the ft / cm switch above to change units.</p>
							<p id="custom-size-error" role="alert" class="text-xs text-red-700" x-show="customError" x-text="customError"></p>
						</div>
					</fieldset>

					<?php if ( $show_direct_purchase ) : ?>
						<!-- Direct purchase (catalog sizes only): quantity, rush, live estimate, Add to Cart / Buy Now -->
						<div x-show="!customSize && selectedIndex !== null" class="space-y-3 border border-showroom-border p-4">
							<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
								<div>
									<label class="text-showroom-grey text-[11px] block mb-1.5 uppercase tracking-[0.18em]">Quantity</label>
									<input type="number" min="1" x-model.number="qty" @change="estimate()" class="w-full border border-showroom-border focus:border-showroom-charcoal px-3 py-2.5 text-showroom-charcoal text-sm focus:outline-none transition-colors" />
								</div>
								<div class="flex items-end pb-0.5">
									<label class="flex items-center gap-2 cursor-pointer w-full">
										<div class="relative flex-shrink-0">
											<input type="checkbox" x-model="rushOrder" @change="estimate()" class="sr-only" />
											<div class="w-9 h-5 rounded-full transition-colors" :class="rushOrder ? 'bg-showroom-charcoal' : 'bg-stone-200'">
												<div class="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform" :class="rushOrder ? 'translate-x-5' : 'translate-x-0.5'"></div>
											</div>
										</div>
										<div><p class="text-showroom-charcoal text-xs font-medium">Rush</p><p class="text-showroom-grey text-xs" x-text="rushLabel()"></p></div>
									</label>
								</div>
							</div>
							<button type="button" @click="estimate()" :disabled="calcLoading" class="w-full flex items-center justify-center gap-2 text-xs font-medium text-showroom-grey hover:text-showroom-charcoal border border-showroom-border hover:border-showroom-charcoal px-3 py-2.5 transition-colors uppercase tracking-wider">
								<span x-show="!calcLoading"><?php dreamrugs_icon( 'zap', 13 ); ?></span>
								<span x-show="calcLoading" class="w-3.5 h-3.5 border border-stone-400 border-t-transparent rounded-full animate-spin inline-block"></span>
								Estimate
							</button>
							<template x-if="priceResult">
								<div class="border border-showroom-border bg-showroom-ivory p-3 space-y-1.5">
									<template x-if="priceResult.rush_surcharge > 0">
										<div class="flex justify-between text-xs"><span class="text-amber-700">Rush fee</span><span class="text-amber-700" x-text="'+' + fmtPrice(priceResult.rush_surcharge)"></span></div>
									</template>
									<div class="flex justify-between text-sm font-medium pt-1 border-t border-showroom-border"><span>Estimated Total</span><span x-text="fmtPrice(priceResult.final_price)"></span></div>
									<p class="text-showroom-grey text-xs" x-text="'Expected delivery: ~' + priceResult.estimated_days + ' days'"></p>
									<template x-if="!priceResult.material_available">
										<p class="border border-amber-200 bg-amber-50 p-3 text-amber-800 text-xs leading-relaxed">Immediate checkout is unavailable for this size and quantity because current material stock is insufficient. This rug is still available for a personalised quote below.</p>
									</template>
									<div class="flex flex-col sm:flex-row gap-2 pt-1">
										<?php foreach ( array( array( wc_get_cart_url(), 'Add to Cart', 'border border-showroom-border hover:border-showroom-charcoal disabled:text-stone-300 text-showroom-charcoal' ), array( wc_get_checkout_url(), 'Buy Now', 'bg-showroom-charcoal hover:bg-stone-800 disabled:bg-stone-200 disabled:text-stone-400 text-white' ) ) as list( $drc_action, $drc_cta, $drc_cta_class ) ) : ?>
											<form method="post" action="<?php echo esc_url( $drc_action ); ?>" class="flex-1">
												<input type="hidden" name="add-to-cart" value="<?php echo (int) $product_id; ?>" />
												<input type="hidden" name="variation_id" :value="variations[selectedIndex] ? variations[selectedIndex].variation_id : ''" />
												<input type="hidden" name="quantity" :value="qty" />
												<input type="hidden" name="attribute_<?php echo esc_attr( $size_attribute_name ); ?>" :value="variations[selectedIndex] ? variations[selectedIndex].attribute_value : ''" />
												<input type="hidden" name="drc_rush_order" :value="rushOrder ? '1' : ''" />
												<input type="hidden" name="drc_selected_color" :value="selectedColor" />
												<button type="submit" :disabled="!priceResult.material_available" class="w-full text-xs font-medium tracking-widest uppercase py-2.5 transition-colors <?php echo esc_attr( $drc_cta_class ); ?>"><?php echo esc_html( $drc_cta ); ?></button>
											</form>
										<?php endforeach; ?>
									</div>
								</div>
							</template>
						</div>
					<?php endif; ?>

					<!-- Request a quote -->
					<div class="bg-showroom-ivory px-6 py-7 space-y-4">
						<h2 class="font-serif text-2xl font-light text-showroom-charcoal">Made for Your Space</h2>
						<p class="text-sm leading-relaxed text-showroom-grey">Every rug is thoughtfully handcrafted to order. Select your preferred dimensions or request a rug made specifically for your space.</p>
						<button type="button" @click="requestQuote()" <?php disabled( ! $product->is_in_stock() ); ?>
							class="group w-full storefront-cta-solid bg-showroom-charcoal px-3 py-4 inline-flex items-center justify-center gap-2 sm:gap-3 text-[11px] sm:text-xs tracking-[0.1em] sm:tracking-[0.2em] disabled:cursor-not-allowed">
							Request a Personalised Quote
							<span aria-hidden="true" class="transition-transform duration-300 motion-safe:group-hover:translate-x-1">→</span>
						</button>
						<p class="text-center text-xs text-showroom-grey">No account needed · We reply within 24 hours</p>
					</div>
				</div>
			</div>
		</div>

		<?php if ( $drc_has_info ) : ?>
			<!-- Good to Know: vendor-managed information (samples, care, shipping, …) -->
			<section aria-labelledby="rug-info-heading" class="lg:col-span-3 lg:col-start-1 lg:row-start-2 min-w-0 lg:self-start" x-data="{ open: null }">
				<p class="text-[11px] font-medium uppercase tracking-[0.25em] text-showroom-gold">Care &amp; Delivery</p>
				<h2 id="rug-info-heading" class="mt-2 mb-4 font-serif text-3xl font-light leading-tight text-showroom-charcoal">Good to Know</h2>
				<div class="divide-y divide-showroom-border border-y border-showroom-border">
					<?php
					// Material / weave / pile now live in the specifications list; the
					// accordion keeps only the rug's own extra HTML plus the store sections.
					$drc_info_rows = array();
					if ( $drc_extra_info_html ) {
						$drc_info_rows[] = array( 'id' => 'product', 'title' => 'Additional Information', 'html' => $drc_extra_info_html );
					}
					$drc_info_rows = array_merge( $drc_info_rows, $drc_accordion_sections );
					foreach ( $drc_info_rows as $section ) :
						$drc_sid = esc_js( $section['id'] );
						?>
						<div>
							<button type="button" @click="open = open === '<?php echo $drc_sid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>' ? null : '<?php echo $drc_sid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>'" :aria-expanded="open === '<?php echo $drc_sid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>'"
								class="w-full flex items-center justify-between gap-4 py-5 text-left text-showroom-charcoal hover:text-showroom-gold transition-colors">
								<span class="font-serif text-xl"><?php echo esc_html( $section['title'] ); ?></span>
								<span class="flex-shrink-0 transition-transform duration-300" :class="open === '<?php echo $drc_sid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>' ? 'rotate-180' : ''" aria-hidden="true"><?php dreamrugs_icon( 'chevron-down', 18 ); ?></span>
							</button>
							<div x-show="open === '<?php echo $drc_sid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>'" x-cloak class="prose-content text-showroom-grey text-sm leading-relaxed pb-6 pr-6 motion-safe:animate-[showroom-fade_300ms_ease-out]"><?php echo wp_kses_post( $section['html'] ); ?></div>
						</div>
					<?php endforeach; ?>
					<?php if ( ! empty( $drc_settings_pdp['catalog_pdf_url'] ) ) : ?>
						<a href="<?php echo esc_url( $drc_settings_pdp['catalog_pdf_url'] ); ?>" target="_blank" rel="noreferrer" class="flex items-center justify-between gap-4 py-5 text-showroom-charcoal hover:text-showroom-gold transition-colors">
							<span class="font-serif text-xl">Tearsheet</span>
							<?php dreamrugs_icon( 'external-link', 15 ); ?>
						</a>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>
	</div>

	</div>

	<?php
	// The story behind the rug: this rug's long-form "about" copy and its
	// design story, when either exists (DreamRugs → Design Stories).
	$drc_story = class_exists( 'DRC_Design_Stories' ) ? DRC_Design_Stories::get_for_product( $product_id ) : null;
	if ( $about_html || $drc_story ) :
		?>
		<section aria-labelledby="rug-story-heading" class="grid gap-10 lg:grid-cols-12 lg:gap-16">
			<div class="lg:col-span-4">
				<p class="text-[11px] font-medium uppercase tracking-[0.25em] text-showroom-gold">The Design</p>
				<h2 id="rug-story-heading" class="mt-3 font-serif text-3xl font-light leading-tight text-showroom-charcoal sm:text-4xl">The Story Behind the Rug</h2>
			</div>
			<div class="lg:col-span-8 space-y-8 max-w-3xl">
				<?php if ( $about_html ) : ?>
					<div class="prose-content text-showroom-grey"><?php echo wp_kses_post( $about_html ); ?></div>
				<?php endif; ?>
				<?php if ( $drc_story ) : ?>
					<a href="<?php echo esc_url( $drc_story['url'] ); ?>" class="group grid grid-cols-[96px_1fr] sm:grid-cols-[160px_1fr] items-center gap-6 border-y border-showroom-border py-6">
						<div class="aspect-square overflow-hidden bg-showroom-ivory">
							<?php if ( $drc_story['cover_image_url'] ) : ?>
								<img src="<?php echo esc_url( $drc_story['cover_image_url'] ); ?>" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover transition-transform duration-700 motion-safe:group-hover:scale-[1.03]" />
							<?php endif; ?>
						</div>
						<div class="min-w-0 space-y-2">
							<p class="font-serif text-2xl font-light text-showroom-charcoal leading-snug"><?php echo esc_html( $drc_story['title'] ); ?></p>
							<?php if ( $drc_story['inspiration'] ) : ?>
								<p class="text-sm text-showroom-grey line-clamp-2"><?php echo esc_html( $drc_story['inspiration'] ); ?></p>
							<?php endif; ?>
							<span class="storefront-link-arrow">Read the story <?php dreamrugs_icon( 'chevron-right', 13, 'transition-transform motion-safe:group-hover:translate-x-0.5' ); ?></span>
						</div>
					</a>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php get_template_part( 'template-parts/rug-craftsmanship' ); ?>

	<?php
	$drc_review_summary = class_exists( 'DRC_Reviews' ) ? DRC_Reviews::summary( $product_id ) : array( 'average_rating' => null, 'review_count' => 0, 'rating_counts' => array(), 'reviews' => array() );
	require get_theme_file_path( 'template-parts/rug-reviews.php' );
	?>

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

	<?php get_template_part( 'template-parts/related-rugs', null, array( 'product' => $product ) ); ?>

	<!-- Full-screen image preview -->
	<template x-if="expandedImage">
		<div role="dialog" aria-modal="true" aria-label="Expanded rug image" class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/90 p-4 sm:p-8" @click="closeCarousel()">
			<button type="button" @click="closeCarousel()" aria-label="Close expanded image" class="absolute right-4 top-4 sm:right-6 sm:top-6 w-10 h-10 flex items-center justify-center bg-white text-stone-900 hover:bg-stone-100 transition-colors"><?php dreamrugs_icon( 'x', 20 ); ?></button>
			<img :src="expandedImage.src" :alt="expandedImage.alt" class="w-full h-full object-contain" @click.stop />
			<template x-if="previewImages().length > 1">
				<div>
					<button type="button" @click.stop="showCarouselImage(expandedIndex - 1)" aria-label="Previous image" class="absolute left-3 sm:left-6 top-1/2 -translate-y-1/2 w-11 h-11 flex items-center justify-center bg-white/90 hover:bg-white text-stone-900 transition-colors"><?php dreamrugs_icon( 'chevron-left', 22 ); ?></button>
					<button type="button" @click.stop="showCarouselImage(expandedIndex + 1)" aria-label="Next image" class="absolute right-3 sm:right-6 top-1/2 -translate-y-1/2 w-11 h-11 flex items-center justify-center bg-white/90 hover:bg-white text-stone-900 transition-colors"><?php dreamrugs_icon( 'chevron-right', 22 ); ?></button>
					<div class="absolute bottom-4 sm:bottom-6 left-1/2 -translate-x-1/2 flex items-center gap-3 rounded-full bg-stone-950/65 px-4 py-2" @click.stop>
						<template x-for="(img, i) in previewImages()" :key="'pv' + i">
							<button type="button" @click="showCarouselImage(i)" :aria-label="'Show image ' + (i + 1)" :aria-current="expandedIndex === i ? 'true' : null" class="rounded-full transition-all" :class="expandedIndex === i ? 'w-6 h-2 bg-white' : 'w-2 h-2 bg-white/50 hover:bg-white/80'"></button>
						</template>
						<span class="ml-1 text-[11px] tabular-nums text-white/80" x-text="(expandedIndex + 1) + '/' + previewImages().length"></span>
					</div>
				</div>
			</template>
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
				<template x-if="quoteSuccess">
					<div class="p-8 text-center space-y-4">
						<span class="text-green-600 inline-flex"><?php dreamrugs_icon( 'check-circle', 40 ); ?></span>
						<h3 class="font-serif text-2xl font-light text-stone-900">Quote Requested</h3>
						<p class="text-stone-600 text-sm">Quote #<span x-text="quoteSuccess.quote_id"></span></p>
						<p class="text-stone-500 text-sm" x-text="quoteSuccess.message"></p>
						<p x-show="quoteSuccess.lead_time_days" class="text-stone-400 text-xs">Expected delivery: <span x-text="quoteSuccess.lead_time_days"></span> days</p>
						<button type="button" @click="quoteModalOpen = false" class="inline-block text-sm text-stone-500 hover:text-stone-900 transition-colors border-b border-stone-300 pb-0.5">Continue browsing</button>
					</div>
				</template>
				<div x-show="!quoteSuccess" class="p-5 space-y-5">
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
							<label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Quantity *</label>
							<input type="number" min="1" step="1" x-model.number="quote.qty" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400" />
						</div>
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
									<?php foreach ( $quote_delivery as $d ) : ?><option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( 'ASAP / Early Delivery' === $d ? 'ASAP / Early Delivery (extra cost)' : $d ); ?></option><?php endforeach; ?>
								</select>
								<?php dreamrugs_icon( 'chevron-down', 14, 'pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-stone-400' ); ?>
							</div>
							<p x-show="quote.expected_delivery === 'ASAP / Early Delivery'" x-cloak class="mt-1 text-xs text-amber-700">Early delivery incurs an extra cost. The final charge and delivery date will be confirmed in your quote.</p>
						</div>
					</div>

					<div>
						<label class="text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider">Describe Your Requirements</label>
						<textarea rows="3" maxlength="3000" x-model="quote.notes" aria-describedby="quote-requirements-count" placeholder="Colors, placement, changes, or anything else we should know…" class="w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm resize-none focus:outline-none focus:border-stone-400"></textarea>
						<p id="quote-requirements-count" class="text-right text-stone-400 text-xs mt-0.5" x-text="quote.notes.length + ' / 3000'"></p>
					</div>

					<div>
						<label class="text-stone-500 text-xs font-medium block mb-2 uppercase tracking-wider">Reference Images (<span x-text="quote.reference_image_urls.length"></span>/3)</label>
						<div class="flex flex-wrap gap-2">
							<template x-for="url in quote.reference_image_urls" :key="url">
								<div class="relative w-20 h-20 bg-stone-100">
									<img :src="url" alt="Reference image" class="w-full h-full object-cover" />
									<button type="button" @click="quote.reference_image_urls = quote.reference_image_urls.filter((u) => u !== url)" aria-label="Remove image" class="absolute top-1 right-1 w-5 h-5 bg-white/90 text-stone-700 flex items-center justify-center"><?php dreamrugs_icon( 'x', 12 ); ?></button>
								</div>
							</template>
							<template x-if="quote.reference_image_urls.length < 3">
								<label class="w-20 h-20 border border-dashed border-stone-300 hover:border-stone-500 flex flex-col items-center justify-center gap-1 text-stone-400 text-xs cursor-pointer transition-colors">
									<span x-show="!quote.uploading"><?php dreamrugs_icon( 'upload', 16 ); ?></span>
									<span x-show="quote.uploading" class="w-4 h-4 border border-stone-300 border-t-stone-700 rounded-full animate-spin"></span>
									<span x-text="quote.uploading ? 'Uploading' : 'Add'"></span>
									<input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" :disabled="quote.uploading" @change="uploadReference($event.target.files[0]); $event.target.value = ''" />
								</label>
							</template>
						</div>
						<p class="text-stone-400 text-xs mt-1">Optional — a room photo or design inspiration (JPEG/PNG/WebP, max 10MB).</p>
					</div>

					<?php dreamrugs_bot_fields(); ?>
					<template x-if="submitError">
						<div class="flex items-center gap-2 bg-red-50 border border-red-200 p-3 text-red-600 text-xs"><?php dreamrugs_icon( 'alert-triangle', 12 ); ?> <span x-text="submitError"></span></div>
					</template>

					<button type="button" @click="submitQuote()" :disabled="submitting" class="w-full bg-stone-900 hover:bg-stone-800 disabled:bg-stone-200 disabled:text-stone-400 text-white text-xs font-medium tracking-widest uppercase py-3.5 transition-colors flex items-center justify-center gap-2">
						<span x-show="submitting" class="w-4 h-4 border border-white/30 border-t-white rounded-full animate-spin inline-block"></span>
						<span x-text="submitting ? 'Submitting…' : 'Submit Quote Request'"></span>
					</button>
				</div>
			</div>
		</div>
	</template>
</div>

<?php get_footer(); ?>
