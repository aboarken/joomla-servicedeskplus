<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\KeyVault;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Security\KeyVaultException;

final class KeyVaultTest extends TestCase
{
    private const SECRET = 'a-joomla-secret-of-sufficient-length';

    public function testEncryptsAndDecrypts(): void
    {
        $vault = new KeyVault(self::SECRET);
        $blob  = $vault->encrypt('the-api-key', 'service_key');

        self::assertStringStartsWith('v1:', $blob);
        self::assertStringNotContainsString('the-api-key', $blob);
        self::assertSame('the-api-key', $vault->decrypt($blob, 'service_key'));
    }

    public function testEveryEncryptionUsesANewNonce(): void
    {
        $vault = new KeyVault(self::SECRET);

        self::assertNotSame($vault->encrypt('x', 'c'), $vault->encrypt('x', 'c'));
    }

    public function testAnotherContextCannotDecrypt(): void
    {
        $vault = new KeyVault(self::SECRET);
        $blob  = $vault->encrypt('the-api-key', 'service_key');

        $this->expectException(KeyVaultException::class);
        $vault->decrypt($blob, 'other');
    }

    public function testAnotherSecretCannotDecrypt(): void
    {
        $blob = (new KeyVault(self::SECRET))->encrypt('the-api-key', 'service_key');

        $this->expectException(KeyVaultException::class);
        (new KeyVault(self::SECRET . '-changed'))->decrypt($blob, 'service_key');
    }

    public function testTamperedCiphertextIsRefused(): void
    {
        $vault = new KeyVault(self::SECRET);
        $raw   = base64_decode(substr($vault->encrypt('the-api-key', 'c'), 3), true);
        $raw[\strlen($raw) - 1] = $raw[\strlen($raw) - 1] ^ "\x01";

        $this->expectException(KeyVaultException::class);
        $vault->decrypt('v1:' . base64_encode($raw), 'c');
    }

    public function testShortSecretsAreRefused(): void
    {
        $this->expectException(KeyVaultException::class);
        new KeyVault('short');
    }

    public function testTheKeyIsNotShownWhenDumpedOrSerialised(): void
    {
        $vault = new KeyVault(self::SECRET);

        self::assertStringNotContainsString(hash_hkdf('sha256', self::SECRET, 32, 'com_servicedeskplus/key-encryption/v1'), print_r($vault, true));
        $this->expectException(\LogicException::class);
        serialize($vault);
    }
}
