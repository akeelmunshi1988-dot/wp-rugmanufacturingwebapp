<?php
/**
 * Ported from frontend/src/pages/RugServices.tsx, page: 'trade' (the
 * TradeForm component). Submits to the new public REST route
 * /dreamrugs/v1/trade-enquiry (class-rest-routes.php::create_trade_enquiry())
 * which stores a drc_trade_enquiry post (class-content-types.php) and emails
 * the vendor. Styled identically to single-product.php's "Request a Quote"
 * modal (same label/input classes), per this session's styling pass on the
 * original page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$label_class = 'text-stone-500 text-xs font-medium block mb-1 uppercase tracking-wider';
$input_class = 'w-full border border-stone-200 px-3 py-2.5 text-stone-900 text-sm focus:outline-none focus:border-stone-400';

$professions = array(
	'architect'          => 'Architect',
	'interior_designer'  => 'Interior Designer',
	'retailer'           => 'Retailer / Rug Showroom',
	'wholesaler'         => 'Wholesaler',
	'private_label'      => 'Private Label Brand',
	'hospitality'        => 'Hospitality Company',
);

get_header();
?>

<section class="bg-[#e8e2d6] px-[5vw] py-20 text-[#20221c] lg:py-28">
	<div class="grid gap-14 lg:grid-cols-[0.85fr_1.35fr] lg:gap-[8vw]">
		<div>
			<p class="storefront-eyebrow mb-6">For design and trade professionals</p>
			<h1 class="storefront-heading max-w-2xl text-5xl md:text-6xl">Tell us about your practice and brief.</h1>
			<div class="mt-8 max-w-lg space-y-6 text-sm leading-7 opacity-80">
				<p>Share the intended use, sizes, quantities, materials and target date. Include your design direction so the studio can discuss development, sampling and production with you.</p>
				<p>For architects, interior designers, retailers and hospitality teams planning their next rug project.</p>
			</div>
		</div>

		<form
			class="space-y-6"
			x-data="{
				submitting: false, message: '',
				submit() {
					this.submitting = true; this.message = '';
					var form = new FormData($el);
					var body = Object.fromEntries(form.entries());
					fetch('<?php echo esc_url( rest_url( 'dreamrugs/v1/trade-enquiry' ) ); ?>', {
						method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
					}).then(function (r) {
						if (!r.ok) return r.json().then(function (d) { throw new Error(d.message || 'Something went wrong.'); });
						return r.json();
					}).then(() => {
						this.message = 'Thank you — your enquiry has been sent. Our team will be in touch shortly.';
						$el.reset();
					}).catch((e) => { this.message = e.message; }).finally(() => { this.submitting = false; });
				}
			}"
			@submit.prevent="submit()"
		>
			<div class="border-b border-stone-300 pb-6">
				<p class="storefront-eyebrow">Trade partner enquiry</p>
				<h2 class="storefront-heading mt-3 text-3xl">Tell us about your practice.</h2>
			</div>

			<div class="grid gap-5 sm:grid-cols-2">
				<div><label class="<?php echo esc_attr( $label_class ); ?>">First name *</label><input name="first_name" required maxlength="150" autocomplete="given-name" class="<?php echo esc_attr( $input_class ); ?>" /></div>
				<div><label class="<?php echo esc_attr( $label_class ); ?>">Last name *</label><input name="last_name" required maxlength="150" autocomplete="family-name" class="<?php echo esc_attr( $input_class ); ?>" /></div>
				<div><label class="<?php echo esc_attr( $label_class ); ?>">Email *</label><input type="email" name="email" required autocomplete="email" class="<?php echo esc_attr( $input_class ); ?>" /></div>
				<div><label class="<?php echo esc_attr( $label_class ); ?>">Phone *</label><input type="tel" name="phone" required autocomplete="tel" class="<?php echo esc_attr( $input_class ); ?>" /></div>
				<div><label class="<?php echo esc_attr( $label_class ); ?>">City</label><input name="city" maxlength="150" autocomplete="address-level2" class="<?php echo esc_attr( $input_class ); ?>" /></div>
				<div><label class="<?php echo esc_attr( $label_class ); ?>">Country</label><input name="country" maxlength="150" autocomplete="country-name" class="<?php echo esc_attr( $input_class ); ?>" /></div>
			</div>

			<fieldset class="border-y border-stone-300 py-5">
				<legend class="<?php echo esc_attr( $label_class ); ?>">Which best describes you? *</legend>
				<div class="grid gap-3 pt-3 sm:grid-cols-2 lg:grid-cols-3">
					<?php foreach ( $professions as $value => $label ) : ?>
						<label class="flex items-center gap-2.5 border border-stone-200 px-3 py-2.5 text-sm text-stone-700 cursor-pointer transition-colors has-[:checked]:border-stone-400 has-[:checked]:bg-stone-50">
							<input type="radio" name="profession" value="<?php echo esc_attr( $value ); ?>" required class="accent-stone-800" /><?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="grid gap-5 sm:grid-cols-2">
				<div><label class="<?php echo esc_attr( $label_class ); ?>">Company / studio *</label><input name="company" required maxlength="200" class="<?php echo esc_attr( $input_class ); ?>" /></div>
				<div><label class="<?php echo esc_attr( $label_class ); ?>">Website / Instagram</label><input name="website" maxlength="300" placeholder="https://" class="<?php echo esc_attr( $input_class ); ?>" /></div>
			</div>

			<div>
				<label class="<?php echo esc_attr( $label_class ); ?>">Primary project type</label>
				<div class="relative">
					<select name="project_type" class="<?php echo esc_attr( $input_class ); ?> appearance-none bg-white pr-8">
						<?php foreach ( array( 'Residential', 'Hospitality', 'Commercial', 'Retail collection', 'Other' ) as $type ) : ?>
							<option><?php echo esc_html( $type ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php dreamrugs_icon( 'chevron-down', 14, 'pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-stone-400' ); ?>
				</div>
			</div>

			<div>
				<label class="<?php echo esc_attr( $label_class ); ?>">Tell us about your work *</label>
				<textarea name="project_brief" required maxlength="2000" rows="5" placeholder="Project scope, sizes, quantities, materials, timeline and design direction" class="<?php echo esc_attr( $input_class ); ?> resize-none"></textarea>
			</div>

			<label class="flex items-start gap-3 border border-stone-200 px-3 py-3 text-xs leading-5 text-stone-600 cursor-pointer transition-colors has-[:checked]:border-stone-400 has-[:checked]:bg-stone-50">
				<input type="checkbox" required class="mt-0.5 h-4 w-4 flex-shrink-0 accent-stone-800" />
				<span>I agree to share these details with the studio to discuss my enquiry. <a href="<?php echo esc_url( home_url( '/privacy-policy' ) ); ?>" class="underline hover:text-stone-900">Privacy policy</a></span>
			</label>

			<button
				type="submit"
				:disabled="submitting"
				class="w-full bg-stone-900 hover:bg-stone-800 disabled:bg-stone-200 disabled:text-stone-400 text-white text-xs font-medium tracking-widest uppercase py-3.5 transition-colors flex items-center justify-center gap-2"
			>
				<span x-show="submitting" class="w-4 h-4 border border-white/30 border-t-white rounded-full animate-spin"></span>
				<span x-show="!submitting"><?php dreamrugs_icon( 'send', 13 ); ?></span>
				<span x-text="submitting ? 'Submitting…' : 'Submit Enquiry'"></span>
			</button>

			<p x-show="message" x-text="message" role="status" class="text-sm leading-6"></p>
		</form>
	</div>
</section>

<?php get_footer(); ?>
