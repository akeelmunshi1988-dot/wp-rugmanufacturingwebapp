<?php
/** /guides — GuidesIndex.tsx. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
dreamrugs_seo( array(
	'title'       => 'Rug & Carpet Buying Guides',
	'description' => 'Practical guides to buying handmade rugs and carpets: weaves, materials, custom sizes, care, and rug making in Bhadohi, India.',
	'canonical'   => home_url( '/guides/' ),
) );
get_header();
?>
<div class="w-[94vw] max-w-4xl mx-auto px-4 py-12 md:py-16">
	<p class="storefront-eyebrow text-stone-500">Learn</p>
	<h1 class="storefront-heading text-4xl md:text-5xl text-stone-900 mt-2 mb-4">Rug &amp; Carpet Buying Guides</h1>
	<p class="text-lg text-stone-600 leading-8 mb-12">Everything you need to choose, order and look after a handmade rug.</p>
	<ul class="divide-y divide-stone-200 border-y border-stone-200">
		<?php foreach ( dreamrugs_guides() as $guide ) : ?>
			<li class="py-6">
				<a href="<?php echo esc_url( home_url( '/guides/' . $guide['slug'] . '/' ) ); ?>" class="group block">
					<h2 class="font-serif text-2xl text-stone-900 group-hover:underline"><?php echo esc_html( $guide['title'] ); ?></h2>
					<p class="mt-2 text-stone-600 leading-7"><?php echo esc_html( $guide['description'] ); ?></p>
					<span class="storefront-link-arrow text-stone-900 mt-3">Read the guide <?php dreamrugs_icon( 'arrow-right', 14 ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php get_footer(); ?>
