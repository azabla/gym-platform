<?php

declare(strict_types=1);

namespace App\Domains\Attendance\DoorList;

use App\Domains\Attendance\DoorList\Exceptions\DoorListNotSetUp;

/**
 * Signs door lists with Ed25519 (PHP's sodium), so the staff app can check
 * that a list it stored came from the server and wasn't changed on the
 * device. The app keeps the public key. See ADR 0038.
 */
final class DoorListSigner
{
    public function __construct(private ?string $secretKey) {}

    /**
     * A new key pair: the base64 secret key for DOOR_LIST_SIGNING_KEY, and
     * its public key for the app.
     *
     * @return array{secret_key: string, public_key: string}
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    /**
     * The base64 detached signature of the exact text.
     *
     * @throws DoorListNotSetUp
     */
    public function sign(string $text): string
    {
        return base64_encode(sodium_crypto_sign_detached($text, $this->secretKey()));
    }

    /**
     * The base64 public key the app checks signatures with.
     *
     * @throws DoorListNotSetUp
     */
    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secretKey()));
    }

    /**
     * A short name for the key, so the app can tell when it changes: the
     * first 16 hex characters of the public key's SHA-256.
     *
     * @throws DoorListNotSetUp
     */
    public function keyId(): string
    {
        return substr(hash('sha256', sodium_crypto_sign_publickey_from_secretkey($this->secretKey())), 0, 16);
    }

    /**
     * @throws DoorListNotSetUp
     */
    private function secretKey(): string
    {
        $key = $this->secretKey === null || $this->secretKey === '' ? false : base64_decode($this->secretKey, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw DoorListNotSetUp::withoutAKey();
        }

        return $key;
    }
}
