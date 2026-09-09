<?php
/**
 * Shared craft/lifestyle story block for the weave-type pages
 * (page-templates/weave-story.php) and the material/space/mood taxonomy
 * archives (archive-product.php) — factored out so both share one exact
 * markup/styling implementation rather than duplicating it, matching
 * WeaveTypePage.tsx's single story section (frontend/src/pages/WeaveTypePage.tsx
 * lines ~235-259) reused across both of its own route shapes.
 *
 * Expects one variable set by the including template:
 * @var array $drc_story {name, eyebrow, intro, story, bestFor, character, making, care}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="bg-[#f4f0e6] py-20 md:py-28">
	<div class="w-[94vw] max-w-none mx-auto px-4">
		<div class="grid md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] gap-10 md:gap-[6vw] items-start">
			<div>
				<p class="text-[10px] uppercase tracking-[0.25em] text-stone-500 mb-4">Understanding the craft</p>
				<h2 class="font-serif text-4xl md:text-6xl leading-[0.95] text-stone-900"><?php echo esc_html( $drc_story['eyebrow'] ); ?></h2>
			</div>
			<p class="text-stone-600 leading-8 md:text-lg"><?php echo esc_html( $drc_story['story'] ); ?></p>
		</div>

		<div class="grid sm:grid-cols-2 lg:grid-cols-4 border-t border-b border-stone-300 mt-16">
			<?php
			$drc_story_facts = array(
				array( 'Character', $drc_story['character'] ),
				array( 'Typical making time', $drc_story['making'] ),
				array( 'Best suited to', $drc_story['bestFor'] ),
				array( 'Everyday care', $drc_story['care'] ),
			);
			foreach ( $drc_story_facts as $drc_fact ) :
				?>
				<div class="py-7 lg:px-6 first:pl-0 border-b sm:border-b-0 lg:border-l first:border-l-0 border-stone-300">
					<p class="text-[10px] uppercase tracking-[0.2em] text-stone-400 mb-3"><?php echo esc_html( $drc_fact[0] ); ?></p>
					<p class="font-serif text-xl text-stone-900 leading-snug"><?php echo esc_html( $drc_fact[1] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
