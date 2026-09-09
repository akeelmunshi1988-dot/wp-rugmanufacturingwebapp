<?php
/**
 * Branded chrome (breadcrumb/heading) around WooCommerce's own
 * [woocommerce_cart] shortcode output (rendered by the_content() below) —
 * the bespoke line-item/sidebar styling itself lives in the shortcode's
 * template-part override, woocommerce/cart/cart.php, which the_content()
 * pulls in. An earlier attempt placed a fully custom cart markup at the
 * theme root as cart.php, on the mistaken assumption WordPress's
 * page-template hierarchy applied to it — it does not; theme-root
 * cart.php/checkout.php overrides only apply to the *shortcode's internal
 * template parts* (yourtheme/woocommerce/cart/cart.php), not to the page
 * itself, so that file was silently never loaded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div class="w-[94vw] max-w-none mx-auto px-4 py-10 space-y-8">
	<div class="flex items-center gap-2 text-xs text-stone-400">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hover:text-stone-900 transition-colors">Home</a>
		<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
		<span class="text-stone-600">Cart</span>
	</div>
	<div class="pb-6 border-b border-stone-100">
		<p class="storefront-eyebrow mb-2">Your Selection</p>
		<h1 class="storefront-heading text-4xl">Cart</h1>
	</div>

	<?php while ( have_posts() ) : the_post(); ?>
		<?php the_content(); ?>
	<?php endwhile; ?>
</div>

<?php get_footer(); ?>
