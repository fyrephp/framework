<?php
declare(strict_types=1);

namespace Fyre\Security\Encryption\Handlers;

use Fyre\Security\Encryption\Encrypter;
use Fyre\Security\Encryption\Exceptions\EncryptionException;
use InvalidArgumentException;
use Override;

use function is_string;
use function mb_strlen;
use function random_bytes;
use function serialize;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function sodium_memzero;
use function sodium_pad;
use function sodium_unpad;
use function unserialize;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_MACBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Encrypter implementation using libsodium authenticated encryption.
 *
 * Data is serialized, padded, and encrypted using secretbox, which authenticates the
 * ciphertext as part of its construction.
 */
class SodiumEncrypter extends Encrypter
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected static array $defaults = [
        'blockSize' => 16,
    ];

    /**
     * Constructs a SodiumEncrypter.
     *
     * @param array<string, mixed> $options The Encrypter options.
     *
     * @throws InvalidArgumentException If a Sodium encrypter option is not valid.
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);

        if ($this->config['blockSize'] <= 0) {
            throw new InvalidArgumentException('Sodium encrypter option `blockSize` must be greater than 0.');
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws EncryptionException If decryption fails.
     */
    #[Override]
    public function decrypt(string $data, string $key): mixed
    {
        if (mb_strlen($data, '8bit') < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new EncryptionException('Decryption failed.');
        }

        $nonce = static::substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = static::substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $secret = $this->generateSecret($key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        try {
            $data = sodium_crypto_secretbox_open($cipher, $nonce, $secret);

            if ($data === false) {
                throw new EncryptionException('Decryption failed.');
            }

            $data = sodium_unpad($data, $this->config['blockSize']);

            return unserialize($data);
        } finally {
            sodium_memzero($cipher);
            sodium_memzero($secret);

            if (is_string($data)) {
                sodium_memzero($data);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function encrypt(mixed $data, string $key): string
    {
        $nonce = $this->generateKey(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $data = serialize($data) |> sodium_pad(?, $this->config['blockSize']);
        $secret = $this->generateSecret($key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        try {
            return $nonce.sodium_crypto_secretbox($data, $nonce, $secret);
        } finally {
            sodium_memzero($data);
            sodium_memzero($secret);
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function generateKey(int|null $length = null): string
    {
        $length ??= SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

        if ($length < 1) {
            throw new InvalidArgumentException('Key length must be greater than 0.');
        }

        return random_bytes($length);
    }
}
