<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Core\EncryptionService;

/**
 * @covers \WCMCS\Core\EncryptionService
 */
class EncryptionServiceTest extends WcmcsUnitTestCase {

	public function test_is_available_when_a_real_auth_key_is_configured(): void {
		$this->assertTrue( EncryptionService::isAvailable() );
	}

	public function test_encrypting_a_value_produces_something_different_from_the_plaintext(): void {
		$plaintext = 'sk_live_super_secret_api_key';

		$ciphertext = EncryptionService::encrypt( $plaintext );

		$this->assertNotSame( $plaintext, $ciphertext );
		$this->assertStringStartsWith( 'wcmcs_enc:', $ciphertext );
	}

	public function test_decrypting_an_encrypted_value_recovers_the_original_plaintext(): void {
		$plaintext = 'a fairly ordinary looking api key 12345';

		$this->assertSame( $plaintext, EncryptionService::decrypt( EncryptionService::encrypt( $plaintext ) ) );
	}

	public function test_two_encryptions_of_the_same_plaintext_produce_different_ciphertext(): void {
		// A random IV per call — encrypting the same secret twice must
		// never produce identical ciphertext (that would leak whether two
		// stored keys are the same value, and weakens the encryption).
		$plaintext = 'the-same-secret-both-times';

		$this->assertNotSame(
			EncryptionService::encrypt( $plaintext ),
			EncryptionService::encrypt( $plaintext )
		);
	}

	public function test_encrypting_an_empty_string_returns_it_unchanged(): void {
		$this->assertSame( '', EncryptionService::encrypt( '' ) );
	}

	public function test_a_plaintext_value_from_before_this_feature_existed_still_decrypts_as_is(): void {
		// Anything without the wcmcs_enc: prefix is assumed to be a
		// pre-existing, never-encrypted value — an install upgrading to
		// this feature must not lose access to keys already saved.
		$this->assertSame( 'already-plaintext-legacy-key', EncryptionService::decrypt( 'already-plaintext-legacy-key' ) );
	}

	public function test_decrypting_corrupted_ciphertext_fails_safe_to_an_empty_string(): void {
		// Never returns ciphertext-as-if-plaintext, and never a PHP error
		// — a corrupted or truncated stored value should look like "no
		// key configured", not crash the site or leak raw ciphertext.
		$this->assertSame( '', EncryptionService::decrypt( 'wcmcs_enc:not-valid-base64-or-too-short' ) );
	}

	public function test_decrypting_a_value_tampered_with_after_encryption_fails_safe(): void {
		$ciphertext = EncryptionService::encrypt( 'a real secret' );

		// Flip a character inside the payload — GCM's authentication tag
		// must cause this to fail closed, not decrypt to garbage silently
		// accepted as a "valid" key.
		$tampered = substr( $ciphertext, 0, -4 ) . 'XXXX';

		$this->assertSame( '', EncryptionService::decrypt( $tampered ) );
	}

	// Deliberately not covered by this suite: the "site still has the
	// default wp-config placeholder salt" fallback path
	// (EncryptionService::hasRealSalt() returning false). AUTH_KEY/
	// AUTH_SALT are real PHP constants, defined once in tests/bootstrap.php
	// (which every process — including an isolated @runInSeparateProcess
	// one — runs before any test method), so no test in this suite can
	// observe a different value for them without either breaking every
	// other test here that needs a real key, or refactoring
	// EncryptionService to accept injected key material purely for
	// testability. This specific branch was verified manually via a
	// standalone script when EncryptionService was first written
	// (encrypt() with a placeholder AUTH_KEY returns the plaintext
	// unchanged) rather than distorting the class's design for it.
}
