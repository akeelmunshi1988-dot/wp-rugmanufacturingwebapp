<?php
/** Keyword landing pages (/moroccan-rugs, …) — LandingPage.tsx. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$page = dreamrugs_find_landing( (string) get_query_var( 'drc_landing' ) );
if ( ! $page ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}
$url = home_url( trailingslashit( $page['path'] ) );
dreamrugs_seo( array(
	'title'       => $page['seoTitle'],
	'description' => $page['description'],
	'canonical'   => $url,
	'jsonld'      => array_values( array_filter( array(
		dreamrugs_breadcrumb_jsonld( array( array( 'Home', home_url( '/' ) ), array( $page['title'], $url ) ) ),
		dreamrugs_faq_jsonld( $page['faq'] ?? array() ),
	) ) ),
) );

// Rugs matching the page's search term (the original's /customer/catalog?search=).
$rugs = array();
if ( ! empty( $page['rugSearch'] ) ) {
	$rugs = wc_get_products( array( 'status' => 'publish', 'limit' => 60, 's' => $page['rugSearch'] ) );
}
get_header();
?>
<article class="w-[94vw] max-w-3xl mx-auto px-4 py-12 md:py-16">
	<nav class="flex items-center gap-2 text-xs text-stone-400 mb-8">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hover:text-stone-900">Home</a>
		<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
		<span class="text-stone-600"><?php echo esc_html( $page['title'] ); ?></span>
	</nav>
	<p class="storefront-eyebrow text-stone-500"><?php echo esc_html( $page['eyebrow'] ); ?></p>
	<h1 class="storefront-heading text-4xl md:text-5xl text-stone-900 mt-2 mb-8"><?php echo esc_html( $page['title'] ); ?></h1>
	<p class="text-lg text-stone-700 leading-8 mb-8"><?php echo esc_html( $page['intro'] ); ?></p>
	<a href="<?php echo esc_url( dreamrugs_app_url( $page['cta']['to'] ) ); ?>" class="storefront-link-arrow text-stone-900 mb-12"><?php echo esc_html( $page['cta']['label'] ); ?> <?php dreamrugs_icon( 'arrow-right', 14 ); ?></a>

	<?php foreach ( $page['sections'] as $section ) : ?>
		<section class="mt-12">
			<h2 class="font-serif text-2xl md:text-3xl text-stone-900 mb-4"><?php echo esc_html( $section['heading'] ); ?></h2>
			<?php foreach ( $section['paragraphs'] as $p ) : ?>
				<p class="text-stone-600 leading-8 mb-4"><?php echo esc_html( $p ); ?></p>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>
</article>

<?php if ( $rugs ) : ?>
	<section class="w-[94vw] mx-auto px-4 pb-8">
		<h2 class="font-serif text-3xl md:text-4xl text-stone-900 mb-8"><?php echo esc_html( $page['rugsHeading'] ?? 'From our collection' ); ?></h2>
		<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-12">
			<?php foreach ( $rugs as $rug ) :
				$img      = wp_get_attachment_image_url( $rug->get_image_id(), 'large' );
				$material = $rug->get_meta( '_drc_material_name' );
				$weave    = $rug->get_meta( '_drc_weave_type' );
				?>
				<a href="<?php echo esc_url( get_permalink( $rug->get_id() ) ); ?>" class="group block">
					<div class="aspect-[4/5] bg-white overflow-hidden">
						<?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $rug->get_name() . ( $material ? ', handmade ' . $material . ' rug' : '' ) ); ?>" loading="lazy" class="w-full h-full object-contain transition-transform duration-500 group-hover:scale-[1.03]" /><?php endif; ?>
					</div>
					<div class="pt-4 text-center space-y-1">
						<p class="text-[10px] uppercase tracking-[0.18em] text-stone-400"><?php echo esc_html( trim( $material . ( $weave ? ' · ' . $weave : '' ), ' ·' ) ); ?></p>
						<h3 class="font-serif text-lg text-stone-900"><?php echo esc_html( $rug->get_name() ); ?></h3>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<section class="w-[94vw] max-w-3xl mx-auto px-4 py-12 md:py-16">
	<?php if ( ! empty( $page['faq'] ) ) : ?>
		<h2 class="font-serif text-2xl md:text-3xl text-stone-900 mb-6">Frequently asked questions</h2>
		<div class="divide-y divide-stone-200 border-y border-stone-200">
			<?php foreach ( $page['faq'] as $item ) : ?>
				<div class="py-5">
					<h3 class="font-serif text-xl text-stone-900"><?php echo esc_html( $item['q'] ); ?></h3>
					<p class="mt-2 text-stone-600 leading-7"><?php echo esc_html( $item['a'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<div class="mt-12 flex flex-wrap gap-x-8 gap-y-3">
		<a href="<?php echo esc_url( dreamrugs_app_url( $page['cta']['to'] ) ); ?>" class="storefront-link-arrow text-stone-900"><?php echo esc_html( $page['cta']['label'] ); ?> <?php dreamrugs_icon( 'arrow-right', 14 ); ?></a>
		<?php foreach ( (array) ( $page['links'] ?? array() ) as $link ) : ?>
			<a href="<?php echo esc_url( dreamrugs_app_url( $link['to'] ) ); ?>" class="storefront-link-arrow text-stone-900"><?php echo esc_html( $link['label'] ); ?> <?php dreamrugs_icon( 'arrow-right', 14 ); ?></a>
		<?php endforeach; ?>
	</div>
</section>
<?php get_footer(); ?>
