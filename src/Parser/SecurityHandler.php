<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

use Artygrand\Pdfplumber\Exception;

/**
 * The Standard security handler: RC4 and AES-128 (revisions 2-4) and AES-256 (revisions 5-6).
 */
final class SecurityHandler
{
    private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        ."\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private string $key;

    /** Method for strings and for streams: 'rc4', 'aes128', 'aes256' or 'identity'. */
    private string $stringMethod;

    private string $streamMethod;

    private bool $encryptMetadata = true;

    private function __construct(
        private readonly int $revision,
        private readonly int $permissions,
        private readonly string $owner,
        private readonly string $user,
        private readonly string $documentId,
        private int $keyLength,
    ) {
    }

    public static function create(Dict $encrypt, string $documentId, string $password): self
    {
        $filter = $encrypt->get('Filter');
        if (! $filter instanceof Name || $filter->name !== 'Standard') {
            throw new Exception('Unsupported PDF encryption filter');
        }
        $version = self::int($encrypt->get('V'), 0);
        $revision = self::int($encrypt->get('R'), 0);
        $permissions = self::int($encrypt->get('P'), 0) & 0xFFFFFFFF;
        $handler = new self(
            $revision,
            $permissions,
            self::str($encrypt->get('O')),
            self::str($encrypt->get('U')),
            $documentId,
            intdiv(self::int($encrypt->get('Length'), 40), 8),
        );

        if ($version === 1 || $version === 2) {
            $handler->stringMethod = $handler->streamMethod = 'rc4';
        } elseif ($version === 4 || $version === 5) {
            $handler->keyLength = $version === 4 ? 16 : 32;
            $handler->encryptMetadata = $encrypt->get('EncryptMetadata') !== false;
            $filters = $encrypt->get('CF');
            $handler->stringMethod = self::cryptMethod($filters, $encrypt->get('StrF'));
            $handler->streamMethod = self::cryptMethod($filters, $encrypt->get('StmF'));
        } else {
            throw new Exception("Unsupported PDF encryption version {$version}");
        }

        $key = $revision >= 5
            ? $handler->keyR6($encrypt, $password)
            : $handler->userKey($password) ?? $handler->ownerKey($password);
        if ($key === null) {
            throw new Exception('Incorrect PDF password');
        }
        $handler->key = $key;

        return $handler;
    }

    public function decryptString(int $id, int $gen, string $data): string
    {
        return $this->decrypt($this->stringMethod, $id, $gen, $data);
    }

    public function decryptStream(int $id, int $gen, string $data, Dict $dict): string
    {
        $type = $dict->get('Type');
        if ($type instanceof Name && $type->name === 'Metadata' && ! $this->encryptMetadata) {
            return $data;
        }

        return $this->decrypt($this->streamMethod, $id, $gen, $data);
    }

    private function decrypt(string $method, int $id, int $gen, string $data): string
    {
        if ($data === '' || $method === 'identity') {
            return $data;
        }
        if ($method === 'aes256') {
            return self::aesDecrypt('aes-256-cbc', $this->key, $data);
        }
        $key = $this->key.substr(pack('V', $id), 0, 3).substr(pack('V', $gen), 0, 2);
        if ($method === 'aes128') {
            $key .= 'sAlT';
        }
        $key = substr(md5($key, true), 0, min(strlen($this->key) + 5, 16));

        return $method === 'aes128' ? self::aesDecrypt('aes-128-cbc', $key, $data) : self::rc4($key, $data);
    }

    private function userKey(string $password): ?string
    {
        $key = $this->fileKey($password);

        return $this->checkUserKey($key) ? $key : null;
    }

    private function ownerKey(string $password): ?string
    {
        $hash = md5(substr($password.self::PADDING, 0, 32), true);
        if ($this->revision >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5($hash, true);
            }
        }
        $key = substr($hash, 0, $this->revision >= 3 ? $this->keyLength : 5);
        if ($this->revision === 2) {
            $user = self::rc4($key, $this->owner);
        } else {
            $user = $this->owner;
            for ($i = 19; $i >= 0; $i--) {
                $user = self::rc4(self::xorKey($key, $i), $user);
            }
        }

        return $this->userKey($user);
    }

    private function fileKey(string $password): string
    {
        $input = substr($password.self::PADDING, 0, 32).$this->owner.pack('V', $this->permissions).$this->documentId;
        if ($this->revision >= 4 && ! $this->encryptMetadata) {
            $input .= "\xFF\xFF\xFF\xFF";
        }
        $hash = md5($input, true);
        $length = 5;
        if ($this->revision >= 3) {
            $length = $this->keyLength;
            for ($i = 0; $i < 50; $i++) {
                $hash = md5(substr($hash, 0, $length), true);
            }
        }

        return substr($hash, 0, $length);
    }

    private function checkUserKey(string $key): bool
    {
        if ($this->revision === 2) {
            return self::rc4($key, self::PADDING) === $this->user;
        }
        $result = self::rc4($key, md5(self::PADDING.$this->documentId, true));
        for ($i = 1; $i < 20; $i++) {
            $result = self::rc4(self::xorKey($key, $i), $result);
        }

        return substr($result, 0, 16) === substr($this->user, 0, 16);
    }

    private function keyR6(Dict $encrypt, string $password): ?string
    {
        $password = substr($password, 0, 127);
        $ownerEncrypted = self::str($encrypt->get('OE'));
        $userEncrypted = self::str($encrypt->get('UE'));
        $owner = $this->owner;
        $user = $this->user;
        $userKey48 = substr($user, 0, 48);

        if ($this->hashR6($password, substr($owner, 32, 8), $userKey48) === substr($owner, 0, 32)) {
            $intermediate = $this->hashR6($password, substr($owner, 40, 8), $userKey48);

            return self::aesNoPadding($intermediate, $ownerEncrypted);
        }
        if ($this->hashR6($password, substr($user, 32, 8), '') === substr($user, 0, 32)) {
            $intermediate = $this->hashR6($password, substr($user, 40, 8), '');

            return self::aesNoPadding($intermediate, $userEncrypted);
        }

        return null;
    }

    private function hashR6(string $password, string $salt, string $vector): string
    {
        $k = hash('sha256', $password.$salt.$vector, true);
        if ($this->revision === 5) {
            return $k;
        }
        $round = 0;
        $last = 0;
        while ($round < 64 || $last > $round - 32) {
            $k1 = str_repeat($password.$k.$vector, 64);
            $e = (string) openssl_encrypt(
                $k1,
                'aes-128-cbc',
                substr($k, 0, 16),
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                substr($k, 16, 16),
            );
            $sum = 0;
            for ($i = 0; $i < 16; $i++) {
                $sum += ord($e[$i]);
            }
            $k = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $e, true);
            $last = ord($e[strlen($e) - 1]);
            $round++;
        }

        return substr($k, 0, 32);
    }

    private static function cryptMethod(mixed $filters, mixed $name): string
    {
        $name = $name instanceof Name ? $name->name : 'Identity';
        if ($name === 'Identity') {
            return 'identity';
        }
        $filter = $filters instanceof Dict ? $filters->get($name) : null;
        $method = $filter instanceof Dict ? $filter->get('CFM') : null;

        return match ($method instanceof Name ? $method->name : '') {
            'V2' => 'rc4',
            'AESV2' => 'aes128',
            'AESV3' => 'aes256',
            'None' => 'identity',
            default => throw new Exception('Unsupported PDF crypt filter'),
        };
    }

    private static function aesDecrypt(string $cipher, string $key, string $data): string
    {
        if (strlen($data) < 32) {
            return '';
        }
        $plain = openssl_decrypt(
            substr($data, 16),
            $cipher,
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            substr($data, 0, 16),
        );
        if ($plain === false || $plain === '') {
            return '';
        }
        $padding = ord($plain[strlen($plain) - 1]);
        if ($padding >= 1 && $padding <= 16 && $padding <= strlen($plain)
            && substr($plain, -$padding) === str_repeat(chr($padding), $padding)) {
            return substr($plain, 0, -$padding);
        }

        return $plain;
    }

    private static function aesNoPadding(string $key, string $data): string
    {
        return (string) openssl_decrypt(
            $data,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\0", 16),
        );
    }

    private static function rc4(string $key, string $data): string
    {
        static $native = null;
        $native ??= in_array('rc4', openssl_get_cipher_methods(), true);
        if ($native) {
            $out = @openssl_decrypt($data, 'rc4', $key, OPENSSL_RAW_DATA);
            if ($out !== false) {
                return $out;
            }
            $native = false;
        }

        $s = range(0, 255);
        $keyLength = strlen($key);
        for ($i = 0, $j = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLength])) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $out = '';
        $length = strlen($data);
        for ($n = 0, $i = 0, $j = 0; $n < $length; $n++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $s[$i]) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) & 0xFF]);
        }

        return $out;
    }

    private static function xorKey(string $key, int $value): string
    {
        $out = '';
        for ($i = 0, $n = strlen($key); $i < $n; $i++) {
            $out .= chr(ord($key[$i]) ^ $value);
        }

        return $out;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_int($value) ? $value : (is_float($value) ? (int) $value : $default);
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
