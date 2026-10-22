<?php
/** Ported from frontend/src/pages/NotFound.tsx. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
dreamrugs_seo( array(
	'title'       => 'Page Not Found',
	'description' => "The page you're looking for doesn't exist or may have been moved.",
	'noindex'     => true,
) );
get_header();
?>
<section class="max-w-2xl mx-auto px-4 py-32 text-center">
	<p class="storefront-eyebrow mb-3">404</p>
	<h1 class="storefront-heading text-5xl leading-[1.1] mb-6">Page not found</h1>
	<p class="text-stone-500 text-lg leading-relaxed mb-10">
		The page you're looking for doesn't exist, may have been moved, or the rug you were viewing is no longer in our catalog.
	</p>
	<div class="flex items-center justify-center gap-4">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="storefront-cta-solid px-6 py-3">Back to Home</a>
		<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="storefront-cta-outline px-6 py-3">Browse Catalog</a>
	</div>
</section>
<?php get_footer(); ?>
