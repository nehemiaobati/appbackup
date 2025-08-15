<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class EncryptionService
{
    private const ENCRYPTION_CIPHER = 'aes-256-cbc';
    private string $key;

    public function __construct()
    {
        $this->key = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
        if (empty($this->key)) {
            throw new RuntimeException("APP_ENCRYPTION_KEY is not set in the .env file.");
        }
    }

    public function encrypt(string $data): string
    {
        $ivLength = openssl_cipher_iv_length(self::ENCRYPTION_CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $encrypted = openssl_encrypt($data, self::ENCRYPTION_CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new RuntimeException("Encryption failed.");
        }
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $data): string
    {
        $data = base64_decode($data);
        $ivLength = openssl_cipher_iv_length(self::ENCRYPTION_CIPHER);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);

        $decrypted = openssl_decrypt($encrypted, self::ENCRYPTION_CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new RuntimeException("Decryption failed.");
        }
        return $decrypted;
    }
}
