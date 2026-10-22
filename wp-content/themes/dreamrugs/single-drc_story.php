<?php
/**
 * Ported from frontend/src/pages/StoryDetail.tsx — /stories/{slug}, the
 * story behind one unique design: inspiration line, admin-written rich text,
 * its photos and videos (click-to-expand lightbox, Alpine), and a card
 * linking to the catalog rug it describes. Data comes from
 * DRC_Design_Stories::format_detail() (dreamrugs-core). Unpublished stories
 * never reach this template (WordPress 404s them for the public; editors
 * still get the native preview).
 *
 * The <title>, meta description and Article JSON-LD that StoryDetail.tsx
 * set via <SEO> come from Rank Math (defaults supplied by
 * DRC_Design_Stories' rank_math_* filters).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$story    = DRC_Design_Stories::format_detail( get_queried_object() );
$cover    = $story['cover_image_url'] ?: ( $story['rug']['image_url'] ?? null );
$rug      = $story['rug'];
$body     = $story['body_html']; // Already sanitized against the prose allowlist (DRC_Design_Stories::sanitize_body_html()).
$media    = $story['media'];
$media_n  = count( $media );
$list_url = get_post_type_archive_link( DRC_Design_Stories::POST_TYPE );

get_header();
?>

<div x-data="{ expandedSrc: null, expandedAlt: '' }" @keydown.escape.window="expandedSrc = null">

	<?php // ── Header ─────────────────────────────────────────────────────── ?>
	<section class="bg-[#f3f1e8] pt-8 pb-16 md:pb-24">
		<div class="mx-auto w-[90vw]">
			<nav class="flex items-center gap-2 text-xs text-stone-400 mb-12 md:mb-16" aria-label="Breadcrumb">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hover:text-stone-900 transition-colors">Home</a>
				<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
				<a href="<?php echo esc_url( $list_url ); ?>" class="hover:text-stone-900 transition-colors">Design Stories</a>
				<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
				<span class="text-stone-600 truncate"><?php echo esc_html( $story['title'] ); ?></span>
			</nav>
			<p class="mb-8 text-xs font-semibold uppercase tracking-[0.22em] text-stone-500">The Story Behind the Design</p>
			<div class="grid gap-10 md:grid-cols-12 md:items-end">
				<h1 class="font-condensed text-[clamp(3rem,5.1vw,6.2rem)] font-medium uppercase leading-[0.98] tracking-[-0.035em] text-[#191d27] md:col-span-10">
					<?php echo esc_html( $story['title'] ); ?>
				</h1>
				<?php if ( $story['inspiration'] ) : ?>
					<p class="text-base leading-relaxed text-stone-500 md:col-span-6 md:col-start-7 md:text-lg">
						<span class="text-[#9b9a93] font-condensed uppercase tracking-[0.06em] text-lg md:text-xl mr-2">Inspiration —</span>
						<?php echo esc_html( $story['inspiration'] ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<?php if ( $cover ) : ?>
		<div class="mx-auto w-[90vw] -mt-8 md:-mt-12">
			<button type="button" data-src="<?php echo esc_url( $cover ); ?>" data-alt="<?php echo esc_attr( $story['title'] ); ?>"
				@click="expandedSrc = $el.dataset.src; expandedAlt = $el.dataset.alt"
				class="block w-full bg-stone-100 cursor-zoom-in">
				<img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( $story['title'] ); ?>" class="block w-full max-h-[80vh] object-cover" />
			</button>
		</div>
	<?php endif; ?>

	<?php // ── Story + linked rug ─────────────────────────────────────────── ?>
	<div class="mx-auto w-[90vw] py-16 md:py-24 grid grid-cols-1 lg:grid-cols-12 gap-12">
		<article class="<?php echo $rug ? 'lg:col-span-7' : 'lg:col-span-8 lg:col-start-3'; ?> prose-content text-lg">
			<?php if ( $body ) : ?>
				<div><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses()'d against the prose allowlist in DRC_Design_Stories::sanitize_body_html(). ?></div>
			<?php elseif ( $story['inspiration'] ) : ?>
				<p><?php echo esc_html( $story['inspiration'] ); ?></p>
			<?php endif; ?>
		</article>

		<?php if ( $rug ) : ?>
			<aside class="lg:col-span-4 lg:col-start-9 h-fit lg:sticky lg:top-28 border border-stone-200 bg-[#f7f5ef] p-6 space-y-5">
				<p class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-500">This Design</p>
				<?php if ( $rug['image_url'] ) : ?>
					<a href="<?php echo esc_url( $rug['url'] ); ?>" class="block bg-white">
						<img src="<?php echo esc_url( $rug['image_url'] ); ?>" alt="<?php echo esc_attr( $rug['name'] ); ?>" loading="lazy" class="w-full aspect-[3/4] object-contain" />
					</a>
				<?php endif; ?>
				<h2 class="font-serif text-xl font-light text-stone-900 leading-snug"><?php echo esc_html( $rug['name'] ); ?></h2>
				<div class="flex flex-col gap-3">
					<a href="<?php echo esc_url( $rug['url'] ); ?>"
						class="inline-flex items-center justify-center gap-2 bg-[#191d27] px-6 py-3.5 font-condensed text-lg font-medium uppercase tracking-[0.04em] text-white transition-colors hover:bg-rug-700">
						View this rug <?php dreamrugs_icon( 'arrow-right', 16 ); ?>
					</a>
					<a href="<?php echo esc_url( home_url( '/custom-rug-request' ) ); ?>"
						class="inline-flex items-center justify-center border border-stone-300 px-6 py-3 text-xs font-semibold uppercase tracking-[0.18em] text-stone-700 transition-colors hover:border-stone-900 hover:text-stone-900">
						Commission your own version
					</a>
				</div>
			</aside>
		<?php endif; ?>
	</div>

	<?php // ── Photos & videos ────────────────────────────────────────────── ?>
	<?php if ( $media_n > 0 ) : ?>
		<section class="border-t border-stone-200 bg-[#f7f5ef] py-16 md:py-24">
			<div class="mx-auto w-[90vw]">
				<p class="mb-10 text-xs font-semibold uppercase tracking-[0.22em] text-stone-500">From Sketch to Loom</p>
				<div class="grid grid-cols-1 sm:grid-cols-2 items-start gap-6 md:gap-8">
					<?php foreach ( $media as $i => $m ) :
						$alt = $m['caption'] ?: sprintf( '%s — %s %d', $story['title'], 'video' === $m['type'] ? 'video' : 'photo', $i + 1 );
						// A lone trailing item spans the full width rather than leaving a gap.
						$wide = 1 === $media_n % 2 && $i === $media_n - 1;
						?>
						<figure class="<?php echo $wide ? 'sm:col-span-2' : ''; ?> <?php echo 1 === $i % 2 ? 'sm:mt-14' : ''; ?>">
							<?php if ( 'video' === $m['type'] ) : ?>
								<video src="<?php echo esc_url( $m['url'] ); ?>" <?php echo $m['poster_url'] ? 'poster="' . esc_url( $m['poster_url'] ) . '"' : ''; ?>
									controls playsinline preload="metadata" class="block w-full bg-stone-900" aria-label="<?php echo esc_attr( $alt ); ?>"></video>
							<?php else : ?>
								<button type="button" data-src="<?php echo esc_url( $m['url'] ); ?>" data-alt="<?php echo esc_attr( $alt ); ?>"
									@click="expandedSrc = $el.dataset.src; expandedAlt = $el.dataset.alt"
									class="block w-full bg-stone-100 cursor-zoom-in">
									<img src="<?php echo esc_url( $m['url'] ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" class="block w-full h-auto" />
								</button>
							<?php endif; ?>
							<?php if ( $m['caption'] ) : ?>
								<figcaption class="pt-3 text-sm text-stone-500"><?php echo esc_html( $m['caption'] ); ?></figcaption>
							<?php endif; ?>
						</figure>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<div class="mx-auto w-[90vw] py-10 border-t border-stone-100">
		<a href="<?php echo esc_url( $list_url ); ?>" class="storefront-link-arrow">
			<?php dreamrugs_icon( 'chevron-left', 13 ); ?> All Design Stories
		</a>
	</div>

	<div x-show="expandedSrc" x-cloak role="dialog" aria-modal="true" aria-label="Expanded photo"
		class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/90 p-4 sm:p-8" @click="expandedSrc = null">
		<button type="button" @click="expandedSrc = null" aria-label="Close expanded image"
			class="absolute right-4 top-4 sm:right-6 sm:top-6 w-10 h-10 flex items-center justify-center bg-white text-stone-900 hover:bg-stone-100 transition-colors">
			<?php dreamrugs_icon( 'x', 20 ); ?>
		</button>
		<img :src="expandedSrc" :alt="expandedAlt" class="w-full h-full object-contain" @click.stop="" />
	</div>
</div>

<?php get_footer(); ?>
