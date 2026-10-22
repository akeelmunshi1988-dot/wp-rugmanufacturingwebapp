<?php
/**
 * Branded customer sign-in — port of frontend/src/pages/CustomerLogin.tsx,
 * ForgotPassword.tsx and ResetPassword.tsx on one page (/account). Form
 * posts and the email-verification / reset links are handled by
 * DRC_Customer_Accounts (plugin), which leaves its result in
 * $GLOBALS['drc_account_state'].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_user_logged_in() && empty( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( home_url( '/my-quotes/' ) );
	exit;
}

$state = $GLOBALS['drc_account_state'] ?? array( 'mode' => 'login', 'error' => '', 'notice' => '', 'email' => '' );
$mode  = $state['mode'];
// phpcs:disable WordPress.Security.NonceVerification -- re-filling the form after a failed post.
$old = function ( string $key, string $fallback = '' ) {
	return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
};
$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
// phpcs:enable

$countries = function_exists( 'WC' ) ? array_values( WC()->countries->get_countries() ) : array( 'India', 'United States', 'United Kingdom' );
$default_country = function_exists( 'WC' ) ? ( WC()->countries->get_countries()[ WC()->countries->get_base_country() ] ?? '' ) : '';
$policy      = class_exists( 'DRC_Customer_Accounts' ) ? DRC_Customer_Accounts::POLICY : '';
$label_class = 'text-stone-600 text-xs font-medium block mb-1.5 uppercase tracking-wider';
$input_class = 'w-full border border-stone-200 focus:border-stone-400 px-3 py-2.5 text-stone-900 placeholder-stone-300 text-sm focus:outline-none transition-colors';
$page_url    = get_permalink();

add_filter( 'wp_robots', 'wp_robots_no_robots' );
add_filter( 'pre_get_document_title', function () use ( $mode ) {
	$titles = array( 'register' => 'Create Account', 'forgot' => 'Forgot Password', 'reset' => 'Reset Password', 'check-email' => 'Check your email' );
	return ( $titles[ $mode ] ?? 'Sign In' ) . ' | ' . get_bloginfo( 'name' );
}, 30 );

$message = function () use ( $state ) {
	if ( $state['error'] ) {
		echo '<div class="flex items-center gap-2 bg-red-50 border border-red-200 p-3 text-red-600 text-xs">';
		dreamrugs_icon( 'alert-triangle', 13, 'flex-shrink-0' );
		echo ' <span>' . wp_kses( $state['error'], array( 'a' => array( 'href' => array() ), 'strong' => array() ) ) . '</span></div>';
	}
	if ( $state['notice'] ) {
		echo '<div class="bg-emerald-50 border border-emerald-200 p-3 text-emerald-700 text-xs">' . esc_html( $state['notice'] ) . '</div>';
	}
};

get_header();
?>
<div class="w-[94vw] max-w-none mx-auto px-4 py-20">
	<div class="max-w-md mx-auto">

	<?php if ( 'check-email' === $mode ) : ?>
		<div class="text-center">
			<?php dreamrugs_icon( 'mail-check', 40, 'text-stone-400 mx-auto mb-6' ); ?>
			<p class="storefront-eyebrow mb-2">Almost there</p>
			<h1 class="storefront-heading text-3xl mb-4">Check your email</h1>
			<p class="text-stone-500 text-sm leading-relaxed">
				We've sent a verification link to <strong class="text-stone-700"><?php echo esc_html( $state['email'] ); ?></strong>.
				Please click the link to activate your account before signing in.
			</p>
			<a href="<?php echo esc_url( $page_url ); ?>" class="inline-block mt-8 text-stone-700 hover:text-stone-900 text-xs font-medium tracking-widest uppercase border-b border-stone-300 pb-0.5 transition-colors">Back to Sign In</a>
		</div>

	<?php elseif ( 'forgot' === $mode || 'reset' === $mode ) : ?>
		<div class="mb-10">
			<p class="storefront-eyebrow mb-2">Account</p>
			<h1 class="storefront-heading text-4xl"><?php echo 'reset' === $mode ? 'Set a New Password' : 'Forgot Password'; ?></h1>
			<p class="text-stone-500 text-sm mt-3 leading-relaxed">
				<?php echo 'reset' === $mode ? 'Choose a new password for your account.' : "Enter the email you registered with and we'll send you a link to reset your password."; ?>
			</p>
		</div>
		<form method="post" action="<?php echo esc_url( $page_url ); ?>" class="space-y-4">
			<?php if ( 'reset' === $mode ) : ?>
				<input type="hidden" name="drc_account_action" value="reset">
				<?php wp_nonce_field( 'drc_account_reset' ); ?>
				<input type="hidden" name="key" value="<?php echo esc_attr( $state['key'] ?? '' ); ?>">
				<input type="hidden" name="login" value="<?php echo esc_attr( $state['login'] ?? '' ); ?>">
				<div>
					<label class="<?php echo esc_attr( $label_class ); ?>" for="drc-pass">New Password *</label>
					<input id="drc-pass" name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="••••••••" class="<?php echo esc_attr( $input_class ); ?>">
					<p class="text-stone-400 text-xs mt-1.5"><?php echo esc_html( $policy ); ?></p>
				</div>
				<div>
					<label class="<?php echo esc_attr( $label_class ); ?>" for="drc-pass2">Confirm Password *</label>
					<input id="drc-pass2" name="password2" type="password" required minlength="8" autocomplete="new-password" placeholder="••••••••" class="<?php echo esc_attr( $input_class ); ?>">
				</div>
			<?php else : ?>
				<input type="hidden" name="drc_account_action" value="forgot">
				<?php wp_nonce_field( 'drc_account_forgot' ); ?>
				<div>
					<label class="<?php echo esc_attr( $label_class ); ?>" for="drc-email">Email *</label>
					<input id="drc-email" name="email" type="email" required value="<?php echo esc_attr( $state['email'] ); ?>" placeholder="you@example.com" class="<?php echo esc_attr( $input_class ); ?>">
				</div>
			<?php endif; ?>
			<?php $message(); ?>
			<button type="submit" class="w-full storefront-cta-solid py-4 transition-colors flex items-center justify-center gap-2 mt-2">
				<?php echo 'reset' === $mode ? 'Update Password' : 'Send Reset Link'; ?>
			</button>
		</form>
		<p class="text-stone-400 text-xs mt-6">
			<a href="<?php echo esc_url( $page_url ); ?>" class="hover:text-stone-700 transition-colors border-b border-stone-200 pb-0.5">← Back to Sign In</a>
		</p>

	<?php else : ?>
		<div x-data="{ mode: '<?php echo esc_js( 'register' === $mode ? 'register' : 'login' ); ?>', show: false }">
			<div class="mb-10">
				<p class="storefront-eyebrow mb-2">Account</p>
				<h1 class="storefront-heading text-4xl" x-text="mode === 'login' ? 'Sign In' : 'Create Account'"><?php echo 'register' === $mode ? 'Create Account' : 'Sign In'; ?></h1>
				<p class="text-stone-500 text-sm mt-3 leading-relaxed" x-text="mode === 'login' ? 'View your quotes, track orders and download invoices.' : 'Register to manage your quotes and orders in one place.'">View your quotes, track orders and download invoices.</p>
			</div>

			<div class="flex border-b border-stone-200 mb-8">
				<button type="button" @click="mode = 'login'" :class="mode === 'login' ? 'text-stone-900 border-b-2 border-stone-900 -mb-px' : 'text-stone-400 hover:text-stone-700'" class="flex-1 py-2.5 text-xs font-medium tracking-wider uppercase transition-colors">Sign In</button>
				<button type="button" @click="mode = 'register'" :class="mode === 'register' ? 'text-stone-900 border-b-2 border-stone-900 -mb-px' : 'text-stone-400 hover:text-stone-700'" class="flex-1 py-2.5 text-xs font-medium tracking-wider uppercase transition-colors">Register</button>
			</div>

			<form method="post" action="<?php echo esc_url( $page_url ); ?>" class="space-y-4">
				<input type="hidden" name="drc_account_action" :value="mode" value="login">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
				<?php /* One nonce per action; the hidden mode picks which one is checked. */ ?>
				<input type="hidden" name="_wpnonce" :value="mode === 'login' ? '<?php echo esc_js( wp_create_nonce( 'drc_account_login' ) ); ?>' : '<?php echo esc_js( wp_create_nonce( 'drc_account_register' ) ); ?>'" value="<?php echo esc_attr( wp_create_nonce( 'drc_account_login' ) ); ?>">

				<div x-show="mode === 'register'" x-cloak>
					<label class="<?php echo esc_attr( $label_class ); ?>" for="drc-name">Full Name *</label>
					<input id="drc-name" name="full_name" value="<?php echo esc_attr( $old( 'full_name' ) ); ?>" placeholder="Your full name" :required="mode === 'register'" class="<?php echo esc_attr( $input_class ); ?>">
				</div>

				<div>
					<label class="<?php echo esc_attr( $label_class ); ?>" for="drc-email">Email *</label>
					<input id="drc-email" name="email" type="email" required autocomplete="email" value="<?php echo esc_attr( $state['email'] ); ?>" placeholder="you@example.com" class="<?php echo esc_attr( $input_class ); ?>">
				</div>

				<div>
					<label class="<?php echo esc_attr( $label_class ); ?>" for="drc-password">Password *</label>
					<div class="relative">
						<input id="drc-password" name="password" :type="show ? 'text' : 'password'" type="password" required :minlength="mode === 'register' ? 8 : 1" :autocomplete="mode === 'register' ? 'new-password' : 'current-password'" placeholder="••••••••" class="<?php echo esc_attr( $input_class ); ?> pr-14">
						<button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-stone-400 hover:text-stone-700 text-[10px] uppercase tracking-wider transition-colors" x-text="show ? 'Hide' : 'Show'">Show</button>
					</div>
					<p x-show="mode === 'register'" x-cloak class="text-stone-400 text-xs mt-1.5"><?php echo esc_html( $policy ); ?></p>
					<div x-show="mode === 'login'" class="text-right mt-1.5">
						<a href="<?php echo esc_url( add_query_arg( 'mode', 'forgot', $page_url ) ); ?>" class="text-stone-400 hover:text-stone-700 text-xs transition-colors">Forgot password?</a>
					</div>
				</div>

				<div x-show="mode === 'register'" x-cloak class="grid grid-cols-2 gap-3">
					<div>
						<label class="<?php echo esc_attr( $label_class ); ?>" for="drc-phone">Phone</label>
						<input id="drc-phone" name="phone" type="tel" value="<?php echo esc_attr( $old( 'phone' ) ); ?>" placeholder="+91 98765 43210" class="<?php echo esc_attr( $input_class ); ?>">
					</div>
					<div>
						<label class="<?php echo esc_attr( $label_class ); ?>" for="drc-company">Company</label>
						<input id="drc-company" name="company" value="<?php echo esc_attr( $old( 'company' ) ); ?>" placeholder="Optional" class="<?php echo esc_attr( $input_class ); ?>">
					</div>
				</div>

				<div x-show="mode === 'register'" x-cloak>
					<label class="<?php echo esc_attr( $label_class ); ?>" for="drc-country">Country *</label>
					<?php $selected_country = $old( 'country', $default_country ); ?>
					<select id="drc-country" name="country" :required="mode === 'register'" class="<?php echo esc_attr( $input_class ); ?> bg-white">
						<?php foreach ( $countries as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $selected_country, $c ); ?>><?php echo esc_html( $c ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<?php $message(); ?>

				<button type="submit" class="w-full storefront-cta-solid py-4 transition-colors flex items-center justify-center gap-2 mt-2">
					<span x-show="mode === 'login'" class="flex items-center gap-2"><?php dreamrugs_icon( 'log-in', 13 ); ?> Sign In</span>
					<span x-show="mode === 'register'" x-cloak class="flex items-center gap-2"><?php dreamrugs_icon( 'user-plus', 13 ); ?> Create Account</span>
				</button>
				<?php dreamrugs_bot_fields(); ?>
			</form>

			<?php if ( has_action( 'nsl_login_form' ) || shortcode_exists( 'nextend_social_login' ) ) : ?>
				<div class="mt-6"><?php echo do_shortcode( '[nextend_social_login redirect="' . esc_url( home_url( '/my-quotes/' ) ) . '"]' ); ?></div>
			<?php endif; ?>

			<p class="text-stone-400 text-xs mt-6 leading-relaxed">
				Already placed an order without an account?
				<button type="button" @click="mode = 'register'" class="text-stone-700 hover:text-stone-900 transition-colors border-b border-stone-300 pb-0.5">Register with the same email</button>
				to link it automatically.
			</p>
		</div>
		<p class="text-stone-400 text-xs mt-4">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hover:text-stone-700 transition-colors border-b border-stone-200 pb-0.5">← Back to Shop</a>
		</p>
	<?php endif; ?>

	</div>
</div>
<?php
get_footer();
