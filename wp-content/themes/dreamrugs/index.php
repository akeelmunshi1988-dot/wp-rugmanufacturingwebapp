<?php
/**
 * Generic fallback template. Page-specific templates (front-page.php,
 * archive-product.php, single-product.php, page-about.php, etc.) are
 * ported from the original app's frontend/src/pages/*.tsx one at a time —
 * see the migration plan's Phase 3. This file only needs to exist so
 * WordPress recognizes the theme as complete; it should rarely actually
 * render once the specific templates below it are in place.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div class="w-[94vw] max-w-none mx-auto px-4 py-24">
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : the_post(); ?>
			<article <?php post_class( 'mb-16' ); ?>>
				<h1 class="storefront-heading text-3xl mb-4"><?php the_title(); ?></h1>
				<div class="prose-content"><?php the_content(); ?></div>
			</article>
		<?php endwhile; ?>
	<?php else : ?>
		<p class="text-stone-500">Nothing found.</p>
	<?php endif; ?>
</div>

<?php get_footer(); ?>
