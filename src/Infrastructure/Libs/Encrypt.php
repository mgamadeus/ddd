<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

use function openssl_digest;

class Encrypt
{
    public const METHOD = 'AES-256-CBC';

    /** @var string Current password used for encrpytion */
    public static ?string $password = null;

    /**
     * Hashes data with salt from Auth config
     * @param string $data
     * @return string
     */
    public static function hashWithSalt(string $data): string
    {
        $salt = Config::getEnv('PASSWORD_HASH');
        return hash('sha256', $salt . $data);
    }

    /**
     * Encrypts data with password, if no password is given, tries to use static $currentPassword
     * @param string $data
     * @param string|null $password
     * @return string|bool
     */
    public static function encrypt(string $data, string $password = null): string|bool
    {
        if (!$password && self::$password) {
            $password = self::$password;
        } elseif (!$password) {
            return false;
        }
        $key = openssl_digest($password, 'SHA256', true);
        $ivLength = openssl_cipher_iv_length(self::METHOD);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt($data, self::METHOD, $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Dewcrypts data with password, if no password is given, tries to use static $currentPassword
     * @param string $encryptedData
     * @param string|null $password
     * @return string
     */
    public static function decrypt(string $encryptedData, string $password = null): string|bool
    {
        if (!$password && self::$password) {
            $password = self::$password;
        } elseif (!$password) {
            return false;
        }
        $ivLength = openssl_cipher_iv_length(self::METHOD);
        $dataWithIv = base64_decode($encryptedData);
        $iv = substr($dataWithIv, 0, $ivLength);
        $encrypted = substr($dataWithIv, $ivLength);

        $key = openssl_digest($password, 'SHA256', true);
        return openssl_decrypt($encrypted, self::METHOD, $key, OPENSSL_RAW_DATA, $iv);
    }
}
