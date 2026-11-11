<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The community catalog is used only with a valid Ed25519 signature of its exact bytes: the
 * SHA-256 of a package is worth what the list naming it is worth.
 */
final class PluginCatalogSignatureTest extends TestCase
{
    private string $dir;
    private string $secret;
    private string $keyBefore;
    private string|false $dirBefore;
    private string $settingBefore;
    /** @var array<string, string|null> */
    private array $files = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/src/core/PluginManager.php';
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->keyBefore = PluginManager::$catalogKey;
        PluginManager::$catalogKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->dir = sys_get_temp_dir() . '/aipbx-catalog-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->dirBefore = getenv('AIPBX_CUSTOM_PLUGINS_DIR');
        putenv('AIPBX_CUSTOM_PLUGINS_DIR=' . $this->dir);
        $this->settingBefore = (string) getSystemSetting('plugins_community_catalog', '0');
        setSystemSetting('plugins_community_catalog', '1');
        PluginManager::$fetch = fn(string $url): ?string => $this->files[basename($url)] ?? null;
    }

    protected function tearDown(): void
    {
        PluginManager::$fetch = null;
        PluginManager::$catalogKey = $this->keyBefore;
        setSystemSetting('plugins_community_catalog', $this->settingBefore);
        putenv($this->dirBefore === false ? 'AIPBX_CUSTOM_PLUGINS_DIR' : 'AIPBX_CUSTOM_PLUGINS_DIR=' . $this->dirBefore);
        PluginManager::resetCache();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function publish(string $slug, int $generated, ?string $signWith = null): string
    {
        $json = json_encode(['generated' => $generated, 'plugins' => [['slug' => $slug, 'version' => '1.0.0', 'sha256' => str_repeat('a', 64)]]]);
        $this->files = ['catalog.json' => $json, 'catalog.json.sig' => base64_encode(sodium_crypto_sign_detached($json, $signWith ?? $this->secret))];
        return $json;
    }

    /** @return list<string> the plugins a fresh look at the published catalog lists */
    private function listed(): array
    {
        @touch($this->dir . '/.catalog.json', time() - 7200); // older than the hour the list is kept
        clearstatcache();
        PluginManager::resetCache();
        return array_keys(PluginManager::communityCatalog());
    }

    public function testASignedCatalogIsUsed(): void
    {
        $this->publish('signed-one', 100);
        $this->assertSame(['signed-one'], $this->listed());
    }

    public function testWithoutAValidSignatureTheLastGoodListStays(): void
    {
        $this->publish('good-one', 100);
        $this->assertSame(['good-one'], $this->listed());

        // No signature file at all (a catalog from before signing, or a stripped one).
        $this->publish('evil-one', 200);
        $this->files['catalog.json.sig'] = null;
        $this->assertSame(['good-one'], $this->listed());

        // The list changed after it was signed.
        $json = $this->publish('good-one', 300);
        $this->files['catalog.json'] = str_replace('good-one', 'evil-two', $json);
        $this->assertSame(['good-one'], $this->listed());

        // Signed, but with another key.
        $this->publish('evil-three', 400, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->assertSame(['good-one'], $this->listed());
    }

    public function testAnOlderSignedCatalogCannotComeBack(): void
    {
        $this->publish('new-one', 500);
        $this->assertSame(['new-one'], $this->listed());
        $this->publish('old-one', 400);
        $this->assertSame(['new-one'], $this->listed());
        $this->publish('newer-one', 600);
        $this->assertSame(['newer-one'], $this->listed());
    }

    public function testNothingIsListedFromAnUnsignedFirstCatalog(): void
    {
        $this->publish('evil-one', 100);
        $this->files['catalog.json.sig'] = 'not a signature';
        $this->assertSame([], $this->listed());
    }

    public function testThePinnedKeyVerifiesWhatTheCatalogBuilderSigns(): void
    {
        $keyFile = dirname(__DIR__, 4) . '/aipbx-plugins/secrets/catalog-signing.key';
        if (!is_readable($keyFile)) {
            $this->markTestSkipped('the signing key is only on the maintainer\'s machine');
        }
        $secret = base64_decode(trim((string) file_get_contents($keyFile)), true);
        $this->assertSame($this->keyBefore, base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)), 'the pinned public key belongs to the signing key');
    }
}
