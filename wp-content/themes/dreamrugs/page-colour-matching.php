<?php
/**
 * Ported from frontend/src/pages/RugServices.tsx, page: 'colour'. Content
 * (eyebrow/heading/body/reference-image items) is admin-editable via
 * DRC_Settings::colour_matching_* — see the admin Settings screen's
 * "Colour Matching Page" repeater field. The first item renders large
 * (spanning two rows) and the rest in the smaller grid, matching the
 * original page's layout exactly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s       = dreamrugs_settings();
$eyebrow = $s['colour_matching_eyebrow'] ?: 'Professional colour matching';
$heading = $s['colour_matching_heading'] ?: 'Specify colour with confidence.';
$body    = $s['colour_matching_body'] ?: "Bring your palette into the conversation. Share a colour reference, fabric swatch or yarn sample with your rug brief so we can discuss the closest match for your chosen material.\n\nInclude the reference system and code, such as Pantone, when available. Screens and fibres can show colour differently; discuss a physical sample before confirming your final palette.";
$items   = ! empty( $s['colour_matching_items'] ) ? $s['colour_matching_items'] : array(
	array( 'title' => 'Pantone Colours', 'description' => 'Reference a Pantone code or attach your selected swatch.', 'image_url' => get_stylesheet_directory_uri() . '/assets/journey/design.jpg' ),
	array( 'title' => 'ARS Wool & Viscose Silk', 'description' => 'Wool Box 1200 and 1400 · ARS 1000 Viscose Silk Box.', 'image_url' => get_stylesheet_directory_uri() . '/assets/materials/wool.jpg' ),
	array( 'title' => 'Additional ARS Systems', 'description' => 'ARS 600 Box · ARS 700 Viscose Silk Box.', 'image_url' => get_stylesheet_directory_uri() . '/assets/materials/silk.jpg' ),
);
$contact_email = $s['contact_emails'][0] ?? '';

get_header();
?>

<section class="bg-[#e8e2d6] px-[5vw] py-20 text-[#20221c] lg:py-28">
	<div class="grid items-center gap-12 lg:grid-cols-[0.9fr_1.4fr] lg:gap-[7vw]">
		<div>
			<p class="storefront-eyebrow mb-6"><?php echo esc_html( $eyebrow ); ?></p>
			<h1 class="storefront-heading max-w-2xl text-5xl md:text-6xl"><?php echo esc_html( $heading ); ?></h1>
			<div class="mt-8 max-w-lg space-y-6 text-sm leading-7 opacity-80">
				<?php foreach ( explode( "\n\n", $body ) as $paragraph ) : ?>
					<p><?php echo esc_html( $paragraph ); ?></p>
				<?php endforeach; ?>
				<?php if ( $contact_email ) : ?>
					<a class="inline-flex items-center justify-center bg-[#20221c] px-6 py-4 text-[11px] font-semibold uppercase tracking-[0.15em] text-white hover:bg-[#414436] transition-colors" href="mailto:<?php echo esc_attr( $contact_email ); ?>?subject=Rug%20colour%20matching%20enquiry">Email a colour request ↗</a>
				<?php else : ?>
					<a class="inline-flex items-center justify-center bg-[#20221c] px-6 py-4 text-[11px] font-semibold uppercase tracking-[0.15em] text-white hover:bg-[#414436] transition-colors" href="<?php echo esc_url( home_url( '/custom-rug-request' ) ); ?>">Start a colour request ↗</a>
				<?php endif; ?>
			</div>
		</div>
		<div class="grid gap-4 sm:grid-cols-2">
			<?php foreach ( $items as $i => $item ) : ?>
				<figure class="bg-[#f7f5ef] p-4 <?php echo 0 === $i ? 'sm:row-span-2' : ''; ?>">
					<?php if ( ! empty( $item['image_url'] ) ) : ?>
						<img src="<?php echo esc_url( $item['image_url'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" class="w-full object-contain bg-white <?php echo 0 === $i ? 'aspect-[4/5]' : 'aspect-[16/9]'; ?>" />
					<?php endif; ?>
					<figcaption class="pt-4">
						<span class="storefront-eyebrow">0<?php echo esc_html( $i + 1 ); ?></span>
						<h2 class="storefront-heading mt-2 text-xl"><?php echo esc_html( $item['title'] ); ?></h2>
						<p class="mt-2 text-xs text-stone-600"><?php echo esc_html( $item['description'] ); ?></p>
					</figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<?php get_footer(); ?>
