<?php

namespace App\Helpers;

class EncryptionHelper
{
    private static string $cipher = 'AES-256-CBC';
    private static int $ivLength = 16; // For AES-256-CBC

    public static function encrypt(string $data): string
    {
        $key = self::getKey(); // 32-byte key
        $iv = random_bytes(self::$ivLength);
        $encrypted = openssl_encrypt($data, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);

        // Generate HMAC to verify integrity
        $hmac = hash_hmac('sha256', $iv . $encrypted, $key, true);

        // Final format: [IV][HMAC][Encrypted]
        return base64_encode($iv . $hmac . $encrypted);
    }

    public static function decrypt(string $encryptedData): string|null
    {
        $key = self::getKey();
        $decoded = base64_decode($encryptedData, true);

        if ($decoded === false || strlen($decoded) < self::$ivLength + 32) {
            return null; // Invalid or corrupted input
        }

        $iv = substr($decoded, 0, self::$ivLength);
        $hmac = substr($decoded, self::$ivLength, 32);
        $ciphertext = substr($decoded, self::$ivLength + 32);

        // Verify HMAC before decrypting
        $calculatedHmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        if (!hash_equals($hmac, $calculatedHmac)) {
            return null; // Tampered data
        }

        return openssl_decrypt($ciphertext, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);
    }

    private static function getKey(): string
    {
        $secret = config('encryption.custom_key', 'fallback-default-key');
        return hash('sha256', $secret, true); // 32-byte key
    }
}
