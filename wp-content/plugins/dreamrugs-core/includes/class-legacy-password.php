<?php
/**
 * Legacy bcrypt password compatibility.
 *
 * The original FastAPI app stored `Customer.hashed_password` as a bcrypt
 * hash (passlib / `$2b$` prefix). WordPress hashes with phpass (`$P$` /
 * `$wp$` on 6.8+). Without this shim, every migrated customer would be
 * locked out until they ran a password reset.
 *
 * How it works (the standard approach — see the migration plan's
 * "Password migration" note):
 *   1. `check_password` filter — when WordPress's own phpass check fails
 *      and the stored hash looks like bcrypt, verify the entered password
 *      with PHP's native `password_verify()`.
 *   2. On a match, transparently re-hash to WordPress's native format via
 *      `wp_set_password()` so every subsequent login uses core's path and
 *      the bcrypt hash is gone after first successful login.
 *
 * The importer (migration/migrate_to_woocommerce.py) writes the raw bcrypt
 * string straight into `wp_users.user_pass` for customers that had a
 * password; guest customers (`hashed_password IS NULL` in the source) are
 * imported as order/quote metadata only and never reach this code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Legacy_Password {

	public static function init() {
		add_filter( 'check_password', array( __CLASS__, 'check_password' ), 10, 4 );
	}

	/**
	 * @param bool       $check    Whether the password matched (WordPress's own result so far).
	 * @param string     $password The plaintext password being tried.
	 * @param string     $hash     The stored hash from wp_users.user_pass.
	 * @param string|int $user_id  The user ID (may be '' on some legacy call sites).
	 * @return bool
	 */
	public static function check_password( $check, $password, $hash, $user_id ) {
		if ( $check ) {
			return $check; // Core (or an earlier filter) already accepted it.
		}

		if ( ! self::is_bcrypt_hash( $hash ) ) {
			return $check;
		}

		if ( ! password_verify( $password, $hash ) ) {
			return $check; // Legacy hash, but wrong password.
		}

		// Correct legacy password — upgrade the stored hash in place so this
		// branch only ever runs once per migrated user. Guard against a
		// missing/zero user id (some internal WP callers pass '').
		$user_id = (int) $user_id;
		if ( $user_id > 0 ) {
			self::rehash( $user_id, $password );
		}

		return true;
	}

	/** bcrypt hashes are exactly 60 chars and start with $2a$ / $2b$ / $2y$. */
	private static function is_bcrypt_hash( string $hash ): bool {
		return strlen( $hash ) === 60 && (bool) preg_match( '/^\$2[aby]\$/', $hash );
	}

	/**
	 * Re-hash to WordPress's native format without disturbing the session
	 * that is being established by this very login. `wp_set_password()`
	 * updates the row and clears the user cache but — unlike a password
	 * *reset* — does not destroy auth cookies, so the in-flight login
	 * completes normally.
	 */
	private static function rehash( int $user_id, string $password ): void {
		// Avoid recursion: wp_set_password() does not call check_password,
		// but be explicit that this runs once.
		static $done = array();
		if ( isset( $done[ $user_id ] ) ) {
			return;
		}
		$done[ $user_id ] = true;

		wp_set_password( $password, $user_id );
	}
}
