<?php
/**
 * WooCommerce checkout template-part override (wc_get_template
 * ('checkout/form-billing.php')) — a content fragment, invoked from this
 * theme's woocommerce/checkout/form-checkout.php via
 * do_action('woocommerce_checkout_billing').
 *
 * Ported from CustomerCheckout.tsx's "Contact Details" + "Delivery Address"
 * sections. WooCommerce's own field registration/validation/nonce/AJAX
 * totals-refresh all stay intact — this only changes which fields render,
 * in what order, and their CSS classes (via woocommerce_form_field()'s own
 * input_class/label_class args), not how they're processed. Two real
 * differences from the original by design, not oversights:
 *   - billing_last_name is unset via the woocommerce_checkout_fields filter
 *     in functions.php and billing_first_name relabeled "Full Name" — the
 *     original has one combined name field, and WooCommerce always ships
 *     first/last name as two.
 *   - the "Sign in instead" / guest-vs-account flow is WooCommerce's own
 *     native login-or-guest handling (the "Create an account?" checkbox
 *     below when registration is optional), not the original's custom
 *     mid-checkout auth modal — rebuilding that modal would mean
 *     reimplementing WooCommerce's own checkout submission flow from
 *     scratch, which is exactly the risk this override approach avoids.
 */

defined( 'ABSPATH' ) || exit;

$input_class = array( 'w-full', 'border', 'border-stone-200', 'focus:border-stone-400', 'px-3', 'py-2.5', 'text-stone-900', 'placeholder-stone-300', 'text-sm', 'focus:outline-none', 'transition-colors', 'bg-white' );
$label_class = array( 'text-stone-600', 'text-xs', 'font-medium', 'block', 'mb-1.5', 'uppercase', 'tracking-wider' );

$fields = $checkout->get_checkout_fields( 'billing' );
$field = function ( $key ) use ( $fields, $checkout, $input_class, $label_class ) {
	if ( ! isset( $fields[ $key ] ) ) {
		return;
	}
	$args = $fields[ $key ];
	$args['input_class'] = $input_class;
	$args['label_class'] = $label_class;
	$args['class']       = array( 'mb-0' );
	woocommerce_form_field( $key, $args, $checkout->get_value( $key ) );
};
?>
<div class="woocommerce-billing-fields">
	<?php do_action( 'woocommerce_before_checkout_billing_form', $checkout ); ?>

	<div class="px-5 py-4 border-b border-stone-100 flex items-center gap-2">
		<?php dreamrugs_icon( 'user', 14, 'text-stone-400' ); ?>
		<p class="storefront-eyebrow">Contact Details</p>
	</div>
	<div class="p-5 space-y-3 woocommerce-billing-fields__field-wrapper">
		<?php if ( is_user_logged_in() ) : ?>
			<div class="flex items-center gap-2 bg-stone-50 border border-stone-200 px-3 py-2.5">
				<span class="text-green-600 flex-shrink-0"><?php dreamrugs_icon( 'check', 13 ); ?></span>
				<div class="min-w-0">
					<p class="text-stone-900 text-xs font-medium truncate"><?php echo esc_html( wp_get_current_user()->display_name ); ?></p>
					<p class="text-stone-400 text-xs truncate"><?php echo esc_html( wp_get_current_user()->user_email ); ?></p>
				</div>
			</div>
			<?php
			// billing_first_name/billing_email are still required POST
			// fields as far as WC_Checkout::validate_checkout() is
			// concerned even though the account chip above hides them from
			// view — confirmed live: skipping their inputs entirely (not
			// just hiding them visually) submits them empty and fails
			// checkout with "Billing Full Name is a required field" despite
			// being logged in. Hidden inputs carry the real account values
			// through instead.
			?>
			<input type="hidden" name="billing_first_name" value="<?php echo esc_attr( $checkout->get_value( 'billing_first_name' ) ?: wp_get_current_user()->display_name ); ?>" />
			<input type="hidden" name="billing_email" value="<?php echo esc_attr( $checkout->get_value( 'billing_email' ) ?: wp_get_current_user()->user_email ); ?>" />
		<?php else : ?>
			<div class="flex items-center justify-between gap-2">
				<p class="text-stone-500 text-sm">Continue as guest</p>
				<a href="<?php echo esc_url( wp_login_url( wc_get_checkout_url() ) ); ?>" class="flex items-center gap-1.5 text-sm text-stone-400 hover:text-stone-900 transition-colors underline underline-offset-2">
					<?php dreamrugs_icon( 'log-in', 13 ); ?> Sign in instead
				</a>
			</div>
		<?php endif; ?>
		<div class="grid grid-cols-2 gap-3">
			<?php
			// Deliberately flat: no per-field wrapper <div> here. WooCommerce's
			// own address-i18n.js resorts these fields by priority and
			// detaches/reattaches them as direct siblings of this container —
			// wrapping each one in its own <div> (as an earlier version of
			// this file did) gets left behind empty when the field is pulled
			// out, breaking the pairing. CSS Grid places direct children into
			// cells on its own, so the fields can be freely reordered by that
			// script (into the same order, since functions.php's priority
			// overrides already match this visual pairing) without breaking
			// the 2-column layout.
			if ( ! is_user_logged_in() ) {
				$field( 'billing_first_name' );
				// Logged-in users get this as the hidden input above instead —
				// a visible field here too would submit a duplicate/conflicting billing_email.
				$field( 'billing_email' );
			}
			$field( 'billing_phone' );
			$field( 'billing_company' );
			?>
		</div>
	</div>

	<div class="px-5 py-4 border-y border-stone-100 flex items-center gap-2">
		<?php dreamrugs_icon( 'map-pin', 14, 'text-stone-400' ); ?>
		<p class="storefront-eyebrow">Delivery Address</p>
	</div>
	<div class="p-5 space-y-3 woocommerce-billing-fields__field-wrapper">
		<?php $field( 'billing_country' ); ?>
		<?php $field( 'billing_address_1' ); ?>
		<?php $field( 'billing_address_2' ); ?>
		<div class="grid grid-cols-2 gap-3">
			<?php
			// Flat, same reason as the Contact Details grid above: no
			// per-field wrapper <div> so address-i18n.js's country-specific
			// field reordering (it also swaps city/state for a plain text
			// input vs dropdown depending on the selected country) can move
			// these without leaving a broken empty wrapper behind.
			$field( 'billing_city' );
			$field( 'billing_state' );
			?>
		</div>
		<?php $field( 'billing_postcode' ); ?>
	</div>

	<?php do_action( 'woocommerce_after_checkout_billing_form', $checkout ); ?>
</div>

<?php if ( ! is_user_logged_in() && $checkout->is_registration_enabled() ) : ?>
	<div class="woocommerce-account-fields px-5 pb-5">
		<?php if ( ! $checkout->is_registration_required() ) : ?>
			<p class="form-row form-row-wide create-account">
				<label class="flex items-center gap-2 text-stone-600 text-sm cursor-pointer">
					<input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="createaccount" <?php checked( ( true === $checkout->get_value( 'createaccount' ) || ( true === apply_filters( 'woocommerce_create_account_default_checked', false ) ) ), true ); ?> type="checkbox" name="createaccount" value="1" />
					<span><?php esc_html_e( 'Create an account?', 'woocommerce' ); ?></span>
				</label>
			</p>
		<?php endif; ?>

		<?php do_action( 'woocommerce_before_checkout_registration_form', $checkout ); ?>

		<?php if ( $checkout->get_checkout_fields( 'account' ) ) : ?>
			<div class="create-account space-y-3 mt-3">
				<?php foreach ( $checkout->get_checkout_fields( 'account' ) as $key => $account_field ) : ?>
					<?php
					$account_field['input_class'] = $input_class;
					$account_field['label_class'] = $label_class;
					$account_field['class']       = array( 'mb-0' );
					woocommerce_form_field( $key, $account_field, $checkout->get_value( $key ) );
					?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php do_action( 'woocommerce_after_checkout_registration_form', $checkout ); ?>
	</div>
<?php endif; ?>
