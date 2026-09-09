<?php
/**
 * Branded chrome around WooCommerce's native My Account (orders, account
 * details, logout, etc. — [woocommerce_my_account] shortcode). The
 * original app's separate custom /my-orders page is superseded by this
 * native area's Orders tab; see page-my-orders.php for the redirect that
 * keeps that old link working.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div class="w-[94vw] max-w-none mx-auto px-4 py-10 space-y-8">
	<div class="pb-6 border-b border-stone-100">
		<p class="storefront-eyebrow mb-2">Your Account</p>
		<h1 class="storefront-heading text-4xl">My Account</h1>
	</div>
	<?php while ( have_posts() ) : the_post(); ?>
		<?php the_content(); ?>
	<?php endwhile; ?>
</div>

<?php get_footer(); ?>
