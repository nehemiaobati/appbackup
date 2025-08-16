<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * EncryptionService.php
 *
 * This Service class provides methods for encrypting and decrypting data
 * using AES-256-CBC cipher. It relies on an application-specific encryption key
 * defined in the `.env` file to secure sensitive information such as API keys.
 */

namespace App\Services;

use RuntimeException;

class EncryptionService
{
    /**
     * @var string The encryption cipher to be used (AES-256-CBC).
     */
    private const ENCRYPTION_CIPHER = 'aes-256-cbc';

    /**
     * @var string The encryption key loaded from environment variables.
     */
    private string $key;

    /**
     * EncryptionService constructor.
     * Initializes the encryption key from the `APP_ENCRYPTION_KEY` environment variable.
     *
     * @throws RuntimeException If the `APP_ENCRYPTION_KEY` is not set, as it's critical for security.
     */
    public function __construct()
    {
        $this->key = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
        if (empty($this->key)) {
            // Log the error for debugging purposes.
            error_log("EncryptionService Error: APP_ENCRYPTION_KEY is not set in the .env file.");
            throw new RuntimeException("APP_ENCRYPTION_KEY is not set in the .env file.");
        }
    }

    /**
     * Encrypts a given string of data.
     * The encrypted data is base64-encoded and includes the Initialization Vector (IV)
     * prepended to it for secure decryption.
     *
     * @param string $data The plaintext data to encrypt.
     * @return string The base64-encoded encrypted data.
     * @throws RuntimeException If the encryption process fails.
     */
    public function encrypt(string $data): string
    {
        // Determine the required IV length for the chosen cipher.
        $ivLength = openssl_cipher_iv_length(self::ENCRYPTION_CIPHER);
        // Generate a cryptographically secure random IV.
        $iv = openssl_random_pseudo_bytes($ivLength);
        
        // Perform the encryption. OPENSSL_RAW_DATA ensures raw binary output.
        $encrypted = openssl_encrypt($data, self::ENCRYPTION_CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            // Log the error for debugging purposes.
            error_log("EncryptionService Error: Encryption failed for data.");
            throw new RuntimeException("Encryption failed.");
        }
        // Prepend the IV to the encrypted data and base64-encode the result for safe storage/transmission.
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypts a base64-encoded encrypted string.
     * The IV is extracted from the beginning of the decoded string before decryption.
     *
     * @param string $data The base64-encoded encrypted data to decrypt.
     * @return string The decrypted plaintext data.
     * @throws RuntimeException If the decryption process fails (e.g., invalid key, corrupted data).
     */
    public function decrypt(string $data): string
    {
        // Decode the base64 string back to its binary form.
        $data = base64_decode($data);
        // Determine the IV length and extract the IV from the beginning of the data.
        $ivLength = openssl_cipher_iv_length(self::ENCRYPTION_CIPHER);
        $iv = substr($data, 0, $ivLength);
        // Extract the actual encrypted data.
        $encrypted = substr($data, $ivLength);

        // Perform the decryption.
        $decrypted = openssl_decrypt($encrypted, self::ENCRYPTION_CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            // Log the error for debugging purposes.
            error_log("EncryptionService Error: Decryption failed for data.");
            throw new RuntimeException("Decryption failed.");
        }
        return $decrypted;
    }
}
