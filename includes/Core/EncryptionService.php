<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts secrets (rate provider API keys) at rest, rather than
 * storing them as plain text in wp_options — anyone with read access to
 * the database (a backup file, a misconfigured export, a compromised
 * read-only DB user) shouldn't get a usable API key just from a table
 * dump.
 *
 * Uses WordPress's own AUTH_KEY/AUTH_SALT (defined in wp-config.php,
 * unique per install, never stored in the database) to derive an
 * AES-256-GCM key via openssl — this plugin's uninstall.php already
 * deletes every option it created, so there's no separate key-rotation
 * story to build: removing the plugin removes the encrypted values
 * along with everything else, and the encryption key itself was never
 * plugin-managed data to begin with.
 *
 * Falls back to storing the value as-is (never silently corrupting
 * data) if openssl isn't available or wp-config.php's default
 * placeholder salts are still in place — encryption is only as strong
 * as a real secret key, and a site that hasn't set its own salts has a
 * bigger problem than this plugin can solve.
 */
class EncryptionService {

	private const CIPHER = 'aes-256-gcm';
	private const PREFIX = 'wcmcs_enc:'; // Marks a value as ciphertext, so already-plaintext values from before this feature still decrypt (are returned as-is).

	public static function isAvailable(): bool {
		return function_exists( 'openssl_encrypt' ) && self::hasRealSalt();
	}

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! self::isAvailable() ) {
			return $plaintext;
		}

		$key = self::key();
		$iv  = random_bytes( 12 );
		$tag = '';

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext ) {
			return $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	public static function decrypt( string $value ): string {
		if ( ! str_starts_with( $value, self::PREFIX ) ) {
			return $value; // Not encrypted (plaintext from before this feature, or encryption unavailable) — return as-is.
		}

		if ( ! self::isAvailable() ) {
			return ''; // Can't decrypt without the key material — never return ciphertext as if it were a usable secret.
		}

		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );

		if ( false === $raw || strlen( $raw ) < 28 ) {
			return '';
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? '' : $plaintext;
	}

	private static function key(): string {
		// A 32-byte key derived from WordPress's own auth salts — never
		// stored anywhere by this plugin, only ever recomputed from
		// wp-config.php's constants.
		return hash( 'sha256', AUTH_KEY . AUTH_SALT, true );
	}

	private static function hasRealSalt(): bool {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
			return false;
		}

		// The literal placeholder text WordPress's default wp-config
		// sample ships with — a site that never replaced it has no real
		// secret to derive a key from.
		return false === strpos( AUTH_KEY, 'put your unique phrase here' ) && strlen( AUTH_KEY ) >= 32;
	}

	/** Reads and decrypts an option written by encryptedUpdateOption(). */
	public static function getDecryptedOption( string $optionName ): string {
		return self::decrypt( (string) get_option( $optionName, '' ) );
	}

	/**
	 * Encrypts and stores a secret, but only when a non-empty value is
	 * given — leaving a password-style field blank on save must never
	 * wipe an already-configured key.
	 */
	public static function encryptedUpdateOption( string $optionName, string $newValue ): void {
		if ( '' === trim( $newValue ) ) {
			return;
		}

		update_option( $optionName, self::encrypt( trim( $newValue ) ) );
	}
}
