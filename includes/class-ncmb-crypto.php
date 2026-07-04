<?php
/**
 * Symmetric encryption for sensitive settings (the app password).
 *
 * Key derivation: by default from the WordPress salts (unique per site).
 * Overridable via the NCMB_ENCRYPTION_KEY constant in wp-config.php for storage
 * separated from the WordPress key material.
 *
 * Prefers libsodium (PHP core since 7.2), falls back to OpenSSL (AES-256-CBC +
 * HMAC). If neither is available, the value is returned unencrypted.
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCMB_Crypto {

	const PREFIX_SODIUM  = 'ncmbs1:';
	const PREFIX_OPENSSL = 'ncmbo1:';

	/**
	 * Returns a 32-byte raw key.
	 */
	private static function key() {
		if ( defined( 'NCMB_ENCRYPTION_KEY' ) && '' !== NCMB_ENCRYPTION_KEY ) {
			$material = (string) NCMB_ENCRYPTION_KEY;
		} else {
			$material = wp_salt( 'auth' );
		}
		return hash( 'sha256', 'ncmb|' . $material, true );
	}

	/**
	 * Encrypts a string. Empty input stays empty.
	 *
	 * @param string $plaintext
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			$out    = self::PREFIX_SODIUM . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary payload, not obfuscation.
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}
			return $out;
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 16 );
			$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			if ( false === $cipher ) {
				return $plaintext;
			}
			$hmac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
			return self::PREFIX_OPENSSL . base64_encode( $iv . $hmac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary payload, not obfuscation.
		}

		// No crypto extension available: plaintext (better than data loss).
		return $plaintext;
	}

	/**
	 * Decrypts a string produced by encrypt().
	 * Values without a known prefix are treated as plaintext (backward
	 * compatibility) and returned unchanged.
	 *
	 * @param string $stored
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		$key = self::key();

		if ( 0 === strpos( $stored, self::PREFIX_SODIUM ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_SODIUM ) ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return ( false === $plain ) ? '' : $plain;
		}

		if ( 0 === strpos( $stored, self::PREFIX_OPENSSL ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_OPENSSL ) ), true );
			if ( false === $raw || strlen( $raw ) <= 48 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 16 );
			$hmac   = substr( $raw, 16, 32 );
			$cipher = substr( $raw, 48 );
			$calc   = hash_hmac( 'sha256', $iv . $cipher, $key, true );
			if ( ! hash_equals( $calc, $hmac ) ) {
				return ''; // Tampered or wrong key.
			}
			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return ( false === $plain ) ? '' : $plain;
		}

		// Unknown/no prefix: treat as plaintext (migration from < 0.3.0).
		return $stored;
	}
}
