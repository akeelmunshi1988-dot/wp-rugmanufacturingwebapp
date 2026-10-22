<?php
/** /guides/{slug} — GuidePage.tsx. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$guide = dreamrugs_find_guide( (string) get_query_var( 'drc_guide' ) );
$url   = home_url( '/guides/' . $guide['slug'] . '/' );
$brand = get_bloginfo( 'name' );
dreamrugs_seo( array(
	'title'       => $guide['seoTitle'],
	'description' => $guide['description'],
	'canonical'   => $url,
	'jsonld'      => array_values( array_filter( array(
		array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => $guide['title'],
			'description'      => $guide['description'],
			'datePublished'    => $guide['datePublished'],
			'mainEntityOfPage' => $url,
			'author'           => array( '@type' => 'Organization', 'name' => $brand ),
			'publisher'        => array( '@type' => 'Organization', 'name' => $brand ),
		),
		dreamrugs_breadcrumb_jsonld( array( array( 'Home', home_url( '/' ) ), array( 'Rug Guides', home_url( '/guides/' ) ), array( $guide['title'], $url ) ) ),
		dreamrugs_faq_jsonld( $guide['faq'] ?? array() ),
	) ) ),
) );
get_header();
?>
<article class="w-[94vw] max-w-3xl mx-auto px-4 py-12 md:py-16">
	<nav class="flex items-center gap-2 text-xs text-stone-400 mb-8">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hover:text-stone-900">Home</a>
		<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
		<a href="<?php echo esc_url( home_url( '/guides/' ) ); ?>" class="hover:text-stone-900">Rug Guides</a>
	</nav>
	<p class="storefront-eyebrow text-stone-500">Rug Guide</p>
	<h1 class="storefront-heading text-4xl md:text-5xl text-stone-900 mt-2 mb-8"><?php echo esc_html( $guide['title'] ); ?></h1>
	<p class="text-lg text-stone-700 leading-8 mb-12"><?php echo esc_html( $guide['intro'] ); ?></p>

	<?php foreach ( $guide['sections'] as $section ) : ?>
		<section class="mb-10">
			<h2 class="font-serif text-2xl md:text-3xl text-stone-900 mb-4"><?php echo esc_html( $section['heading'] ); ?></h2>
			<?php foreach ( $section['paragraphs'] as $p ) : ?>
				<p class="text-stone-600 leading-8 mb-4"><?php echo esc_html( $p ); ?></p>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>

	<?php if ( ! empty( $guide['faq'] ) ) : ?>
		<section class="mt-14">
			<h2 class="font-serif text-2xl md:text-3xl text-stone-900 mb-6">Frequently asked questions</h2>
			<div class="divide-y divide-stone-200 border-y border-stone-200">
				<?php foreach ( $guide['faq'] as $item ) : ?>
					<div class="py-5">
						<h3 class="font-serif text-xl text-stone-900"><?php echo esc_html( $item['q'] ); ?></h3>
						<p class="mt-2 text-stone-600 leading-7"><?php echo esc_html( $item['a'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<div class="mt-12 flex flex-wrap gap-x-8 gap-y-3">
		<?php foreach ( (array) ( $guide['related'] ?? array() ) as $link ) : ?>
			<a href="<?php echo esc_url( dreamrugs_app_url( $link['to'] ) ); ?>" class="storefront-link-arrow text-stone-900"><?php echo esc_html( $link['label'] ); ?> <?php dreamrugs_icon( 'arrow-right', 14 ); ?></a>
		<?php endforeach; ?>
	</div>
</article>
<?php get_footer(); ?>
