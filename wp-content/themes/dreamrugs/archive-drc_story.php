<?php
/**
 * Ported from frontend/src/pages/CustomerStories.tsx — the public /stories
 * listing: one card per published Design Story (drc_story, see
 * dreamrugs-core's class-design-stories.php), each linking to its own
 * single-drc_story.php page. Reached through WordPress's normal template
 * hierarchy (the CPT's `has_archive => 'stories'`); the main query is
 * already published-only, unpaginated, and sorted by Order then newest
 * (DRC_Design_Stories::order_queries()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_query;
$stories = class_exists( 'DRC_Design_Stories' )
	? array_map( array( 'DRC_Design_Stories', 'format_summary' ), $wp_query->posts )
	: array();

get_header();
?>

<section class="bg-[#f3f1e8] py-16 md:py-24">
	<div class="mx-auto w-[90vw]">
		<p class="mb-8 text-xs font-semibold uppercase tracking-[0.22em] text-stone-500">Design Stories</p>
		<div class="grid gap-10 md:grid-cols-12 md:items-end">
			<h1 class="font-condensed text-[clamp(3rem,5.1vw,6.2rem)] font-medium uppercase leading-[0.98] tracking-[-0.035em] text-[#191d27] md:col-span-10">
				Every design has a story.
				<span class="text-[#9b9a93]">Here is where ours begin.</span>
			</h1>
			<p class="text-base leading-relaxed text-stone-500 md:col-span-6 md:col-start-7 md:text-lg">
				From a faded atlas to a monsoon sky — the ideas behind our one-of-a-kind rugs, and the hands that wove them.
			</p>
		</div>
	</div>
</section>

<div class="mx-auto w-[90vw] py-16 md:py-20">
	<?php if ( empty( $stories ) ) : ?>
		<div class="text-center py-24 space-y-3">
			<?php dreamrugs_icon( 'scroll-text', 40, 'mx-auto text-stone-300' ); ?>
			<p class="text-base text-stone-500 md:text-lg">No stories published yet — check back soon.</p>
		</div>
	<?php else : ?>
		<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-14">
			<?php foreach ( $stories as $index => $s ) : ?>
				<a href="<?php echo esc_url( $s['url'] ); ?>" class="group block <?php echo 1 === $index % 3 ? 'lg:mt-14' : ''; ?>">
					<div class="relative overflow-hidden bg-stone-100 aspect-[4/5]">
						<?php if ( $s['cover_image_url'] ) : ?>
							<img src="<?php echo esc_url( $s['cover_image_url'] ); ?>" alt="<?php echo esc_attr( $s['title'] ); ?>" loading="lazy"
								class="w-full h-full object-cover transition-transform duration-700 motion-safe:group-hover:scale-[1.04]" />
						<?php else : ?>
							<div class="w-full h-full flex items-center justify-center">
								<?php dreamrugs_icon( 'scroll-text', 32, 'text-stone-300' ); ?>
							</div>
						<?php endif; ?>
					</div>
					<div class="pt-5 space-y-2">
						<h2 class="font-condensed text-2xl font-medium uppercase leading-tight text-[#191d27] transition-colors group-hover:text-rug-700"><?php echo esc_html( $s['title'] ); ?></h2>
						<?php if ( $s['inspiration'] ) : ?>
							<p class="text-sm leading-relaxed text-stone-500"><?php echo esc_html( $s['inspiration'] ); ?></p>
						<?php endif; ?>
						<span class="inline-block pt-1 text-xs font-semibold uppercase tracking-[0.18em] text-stone-900 border-b border-stone-300 group-hover:border-stone-900 transition-colors">
							Read the story
						</span>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<?php get_footer(); ?>
