<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Core\Content\RegistrySource\RegistrySourceCollection;
use ExtensionMesh\Shopware\Core\Content\RegistrySource\RegistrySourceEntity;
use ExtensionMesh\Shopware\Infrastructure\Http\NetworkPolicy;
use ExtensionMesh\Shopware\Infrastructure\Http\RegistryUrlNormalizer;
use ExtensionMesh\Shopware\Infrastructure\Http\SafeHttpClient;
use ExtensionMesh\Shopware\Infrastructure\Persistence\ExtensionOwnershipRepository;
use ExtensionMesh\Shopware\Infrastructure\Persistence\RegistrySourceRepository;
use ExtensionMesh\Shopware\Infrastructure\Security\CredentialCipher;
use ExtensionMesh\Shopware\Repository\GithubRepositoryProvider;
use ExtensionMesh\Shopware\Service\CatalogService;
use ExtensionMesh\Shopware\Service\ExtensionInstaller;
use ExtensionMesh\Shopware\Service\PluginArchiveInspector;
use ExtensionMesh\Shopware\Service\RegistryLoader;
use ExtensionMesh\Shopware\Service\RegistryParser;
use ExtensionMesh\Shopware\Service\ZipValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Plugin\PluginManagementService;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class RegistryLoaderTest extends TestCase
{
    private const URL = 'https://raw.githubusercontent.com/acme/plugin/extension-mesh-registry/extension-mesh-registry.json';
    private const API = 'https://api.github.com/repos/acme/plugin';

    public function testAnExistingRegistryTakesPrecedenceOverGithubReleases(): void
    {
        $json = '{"schemaVersion":1,"name":"Published registry","extensions":[]}';
        $requests = [];
        $loader = $this->loader([self::URL => new MockResponse($json)], $requests);

        self::assertSame($json, $loader->load(self::URL, null));
        self::assertSame([self::URL], $requests);
    }

    #[DataProvider('credentials')]
    public function testItDiscoversValidatedStablePluginZipsAndSelectsACompatibleVersion(?string $token): void
    {
        $newZip = $this->pluginZip('2.0.0', '^6.8');
        $compatibleZip = $this->pluginZip('1.2.0', '~6.7.0');
        $sourceOnly = $this->release(4, []);
        $sourceOnly['zipball_url'] = self::API . '/zipball/v4.0.0';
        $beta = $this->release(3, [30]);
        $beta['prerelease'] = true;
        $draft = $this->release(5, [50]);
        $draft['draft'] = true;
        $requests = [];
        $loader = $this->loader([
            self::URL => $this->missing(),
            self::API . '/releases?per_page=100' => $this->jsonResponse([
                $draft, $sourceOnly, $beta, $this->release(2, [19, 20]), $this->release(1, [10, 11]),
            ]),
            self::API . '/releases/assets/19' => new MockResponse('not an installable plugin ZIP'),
            self::API . '/releases/assets/20' => new MockResponse($newZip),
            self::API . '/releases/assets/10' => new MockResponse($compatibleZip),
            self::API . '/releases/assets/11' => new MockResponse($compatibleZip),
        ], $requests, $token);

        $registry = $this->parser()->parse($loader->load(self::URL, $token));
        self::assertSame('acme/plugin', $registry['name']);
        self::assertCount(1, $registry['extensions']);
        $extension = $registry['extensions'][0];
        self::assertSame('AcmePlugin', $extension['name']);
        self::assertSame(['de-DE' => 'Beispiel', 'en-GB' => 'Example'], $extension['label']);
        self::assertSame('Acme', $extension['manufacturer']);
        self::assertSame('MIT', $extension['license']);
        self::assertCount(2, $extension['releases']);
        self::assertSame(\hash('sha256', $compatibleZip), $extension['releases'][1]['sha256']);
        $release = $this->parser()->newestCompatibleRelease($extension['releases'], '6.7.12.1', '8.3.0');
        self::assertNotNull($release);
        self::assertSame('1.2.0', $release['version']);
        self::assertSame(self::API . '/releases/assets/10', $release['downloadUrl']);
        self::assertNull($this->parser()->newestCompatibleRelease($extension['releases'], '6.7.12.1', '8.1.0'));
        self::assertNotContains(self::API . '/releases/assets/30', $requests);
        self::assertNotContains(self::API . '/releases/assets/50', $requests);
    }

    /** @return iterable<string, array{?string}> */
    public static function credentials(): iterable
    {
        yield 'public anonymous repository' => [null];
        yield 'authenticated repository' => ['github-read-token'];
    }

    public function testRefreshReusesPinnedAssetsAndDiscoversNewReleasesAndReplacedAssets(): void
    {
        $zip = $this->pluginZip('1.0.0', '~6.7.0');
        $requests = [];
        $first = $this->loader([
            self::URL => $this->missing(),
            self::API . '/releases?per_page=100' => $this->jsonResponse([$this->release(1, [10])]),
            self::API . '/releases/assets/10' => new MockResponse($zip),
        ], $requests)->load(self::URL, null);

        $requests = [];
        $next = $this->loader([
            self::URL => $this->missing(),
            self::API . '/releases?per_page=100' => $this->jsonResponse([
                $this->release(2, [20]), $this->release(1, [10]),
            ]),
            self::API . '/releases/assets/20' => new MockResponse($this->pluginZip('1.1.0', '~6.7.0')),
        ], $requests)->load(self::URL, null, $first);
        $registry = $this->parser()->parse($next);
        self::assertCount(2, $registry['extensions'][0]['releases']);
        self::assertNotContains(self::API . '/releases/assets/10', $requests);
        self::assertSame(\hash('sha256', $zip), $registry['extensions'][0]['releases'][1]['sha256']);
        self::assertSame('1.1.0', $registry['extensions'][0]['releases'][0]['version']);

        $replacement = $this->pluginZip('1.0.0', '^6.6');
        $requests = [];
        $last = $this->loader([
            self::URL => $this->missing(),
            self::API . '/releases?per_page=100' => $this->jsonResponse([$this->release(1, [12])]),
            self::API . '/releases/assets/12' => new MockResponse($replacement),
        ], $requests)->load(self::URL, null, $next);
        $releases = $this->parser()->parse($last)['extensions'][0]['releases'];
        self::assertCount(1, $releases);
        self::assertSame(\hash('sha256', $replacement), $releases[0]['sha256']);
        self::assertSame('^6.6', $releases[0]['shopware']);
    }

    public function testItExplainsWhenOnlyAutomaticSourceArchivesExist(): void
    {
        $release = $this->release(1, []);
        $release['zipball_url'] = self::API . '/zipball/v1.0.0';
        $requests = [];
        $loader = $this->loader([
            self::URL => $this->missing(),
            self::API . '/releases?per_page=100' => $this->jsonResponse([$release]),
        ], $requests);

        $this->expectException(ExtensionMeshException::class);
        $this->expectExceptionMessage('Publish a built plugin ZIP as a release asset');
        $loader->load(self::URL, null);
    }

    public function testItExplainsWhenGithubRepositoryAccessIsMissing(): void
    {
        $requests = [];
        $loader = $this->loader([
            self::URL => $this->missing(),
            self::API . '/releases?per_page=100' => $this->missing(),
        ], $requests);

        $this->expectException(ExtensionMeshException::class);
        $this->expectExceptionMessage('was not found or the access token cannot read it');
        $loader->load(self::URL, null);
    }

    #[DataProvider('errorsWithoutFallback')]
    public function testItDoesNotMaskUnrelatedHttpErrors(string $url, int $status): void
    {
        $requests = [];
        $loader = $this->loader([$url => new MockResponse('', ['http_code' => $status])], $requests);

        try {
            $loader->load($url, null);
            self::fail('The HTTP error must be propagated.');
        } catch (ExtensionMeshException $exception) {
            self::assertSame($status, $exception->getCode());
            self::assertSame([$url], $requests);
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function errorsWithoutFallback(): iterable
    {
        yield 'ordinary registry missing' => ['http://127.0.0.1/registry.json', 404];
        yield 'authentication rejected' => [self::URL, 401];
        yield 'rate limited' => [self::URL, 403];
        yield 'server failure' => [self::URL, 500];
    }

    public function testGithubCredentialsAreRestrictedToAssetsInTheConfiguredRepository(): void
    {
        $requests = [];
        $loader = $this->loader([], $requests);
        self::assertSame('https://api.github.com', $loader->credentialOrigin(self::URL, self::API . '/releases/assets/10'));
        foreach ([
            'https://api.github.com/repos/another/plugin/releases/assets/10',
            self::API . '/contents/composer.json',
            self::API . '/releases/assets/10?redirect=elsewhere',
            'https://api.github.com.attacker.test/repos/acme/plugin/releases/assets/10',
        ] as $url) {
            self::assertSame('https://raw.githubusercontent.com', $loader->credentialOrigin(self::URL, $url));
        }
        self::assertSame(
            'https://registry.example.test:8443',
            $loader->credentialOrigin('https://registry.example.test:8443/registry', self::API . '/releases/assets/10')
        );
    }

    public function testAddingARepositoryPersistsTheGeneratedRegistryAndEncryptedToken(): void
    {
        $context = Context::createCLIContext();
        $cipher = new CredentialCipher('test-app-secret');
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('search')->willReturn(new EntitySearchResult(
            'extension_mesh_registry_source', 0, new RegistrySourceCollection(),
            new AggregationResultCollection(), new Criteria(), $context
        ));
        $repository->expects(self::once())->method('searchIds')
            ->willReturn(new IdSearchResult(0, [], new Criteria(), $context));
        $repository->expects(self::once())->method('create')
            ->with(self::callback(function (array $writes) use ($cipher): bool {
                self::assertTrue(Uuid::isValid($writes[0]['id']));
                self::assertSame('https://github.com/acme/plugin', $writes[0]['url']);
                self::assertSame(self::URL, $writes[0]['normalizedUrl']);
                self::assertSame('github-read-token', $cipher->decrypt($writes[0]['credentialCiphertext']));
                self::assertSame(
                    'AcmePlugin',
                    $this->parser()->parse($writes[0]['cachedRegistry'])['extensions'][0]['name']
                );

                return true;
            }), $context)
            ->willReturn(EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []));
        $requests = [];
        $loader = $this->loader([
            self::URL => $this->missing(),
            self::API . '/releases?per_page=100' => $this->jsonResponse([$this->release(1, [10])]),
            self::API . '/releases/assets/10' => new MockResponse($this->pluginZip('1.0.0', '~6.7.0')),
        ], $requests, 'github-read-token');
        $catalog = new CatalogService(
            new RegistrySourceRepository($repository), new RegistryUrlNormalizer(), $loader, $this->parser(),
            $cipher, new ExtensionOwnershipRepository($this->createMock(EntityRepository::class))
        );

        $result = $catalog->addSourceIdempotently('https://github.com/acme/plugin', 'github-read-token', $context);
        self::assertTrue($result['created']);
        self::assertCount(3, $requests);
    }

    #[DataProvider('downloadIntegrity')]
    public function testCatalogDownloadUsesGithubAuthenticationAndValidatesThePinnedZip(bool $tampered): void
    {
        $context = Context::createCLIContext();
        $cipher = new CredentialCipher('test-app-secret');
        $zip = $this->pluginZip('1.2.0', '~6.7.0');
        $requests = [];
        $json = $this->loader([
            self::URL => $this->missing(),
            self::API . '/releases?per_page=100' => $this->jsonResponse([$this->release(1, [10])]),
            self::API . '/releases/assets/10' => new MockResponse($zip),
        ], $requests)->load(self::URL, null);
        $source = new RegistrySourceEntity();
        $source->setId(Uuid::randomHex());
        $source->setUrl('https://github.com/acme/plugin');
        $source->setNormalizedUrl(self::URL);
        $source->setEnabled(true);
        $source->setCachedRegistry($json);
        $source->setCredentialCiphertext($cipher->encrypt('github-read-token'));
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('search')->willReturn(new EntitySearchResult(
            'extension_mesh_registry_source', 1, new RegistrySourceCollection([$source]),
            new AggregationResultCollection(), new Criteria(), $context
        ));
        $ownershipRepository = $this->createMock(EntityRepository::class);
        $ownershipRepository->expects($tampered ? self::never() : self::once())->method('upsert')
            ->with(self::callback(static function (array $writes): bool {
                self::assertSame('AcmePlugin', $writes[0]['technicalName']);
                self::assertSame(self::URL, $writes[0]['registryUrl']);

                return true;
            }), $context)
            ->willReturn(EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []));
        $ownership = new ExtensionOwnershipRepository($ownershipRepository);
        $requests = [];
        $catalog = new CatalogService(
            new RegistrySourceRepository($repository), new RegistryUrlNormalizer(), $this->loader([], $requests),
            $this->parser(), $cipher, $ownership
        );
        $download = $catalog->download($source->getId(), 'AcmePlugin', '6.7.12.1', '8.3.0', $context, false);
        self::assertSame('https://api.github.com', $download['credentialOrigin']);
        self::assertSame('1.2.0', $download['release']['version']);
        $downloadBytes = $tampered ? $this->pluginZip('1.2.0', '^6.8') : $zip;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($downloadBytes): MockResponse {
            if ($url === self::API . '/releases/assets/10') {
                self::assertSame(['Authorization: Bearer github-read-token'], $options['normalized_headers']['authorization']);
                self::assertSame(['Accept: application/octet-stream'], $options['normalized_headers']['accept']);

                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => ['Location: http://127.0.0.1/plugin.zip'],
                ]);
            }
            self::assertSame('http://127.0.0.1/plugin.zip', $url);
            self::assertArrayNotHasKey('authorization', $options['normalized_headers']);

            return new MockResponse($downloadBytes);
        });
        $management = $this->createMock(PluginManagementService::class);
        $management->expects($tampered ? self::never() : self::once())->method('uploadPlugin')
            ->with(self::callback(static function (UploadedFile $file) use ($zip): bool {
                self::assertSame('AcmePlugin-1.2.0.zip', $file->getClientOriginalName());
                self::assertSame(\hash('sha256', $zip), \hash_file('sha256', $file->getPathname()));

                return true;
            }), $context);
        $installer = new ExtensionInstaller(
            new SafeHttpClient($client, new NetworkPolicy(true), new Filesystem()),
            new ZipValidator(), $management, new Filesystem(), $ownership
        );
        if ($tampered) {
            $this->expectException(ExtensionMeshException::class);
            $this->expectExceptionMessage('SHA-256 digest does not match');
        }
        $installer->prepare(
            $download['release'], 'AcmePlugin', $download['registryUrl'], $context,
            $download['accessToken'], $download['credentialOrigin']
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function downloadIntegrity(): iterable
    {
        yield 'unchanged release ZIP' => [false];
        yield 'changed release ZIP' => [true];
    }

    /** @param array<string, MockResponse> $responses
     *  @param list<string> $requests
     */
    private function loader(array $responses, array &$requests, ?string $token = null): RegistryLoader
    {
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use ($responses, &$requests, $token): MockResponse {
                self::assertSame('GET', $method);
                $requests[] = $url;
                self::assertArrayHasKey($url, $responses, 'Unexpected HTTP request: ' . $url);
                if ($token === null) {
                    self::assertArrayNotHasKey('authorization', $options['normalized_headers']);
                } else {
                    self::assertSame(['Authorization: Bearer ' . $token], $options['normalized_headers']['authorization']);
                }
                if (\str_contains($url, '/releases/assets/')) {
                    self::assertSame(['Accept: application/octet-stream'], $options['normalized_headers']['accept']);
                }

                return $responses[$url];
            }
        );
        $http = new SafeHttpClient($client, new NetworkPolicy(true), new Filesystem());

        return new RegistryLoader(
            $http,
            new GithubRepositoryProvider($http),
            new PluginArchiveInspector(new ZipValidator()),
            $this->parser()
        );
    }

    private function parser(): RegistryParser
    {
        return new RegistryParser(new NetworkPolicy(true));
    }

    private function missing(): MockResponse
    {
        return new MockResponse('', ['http_code' => 404]);
    }

    /** @param array<mixed> $document */
    private function jsonResponse(array $document): MockResponse
    {
        return new MockResponse((string) \json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /** @param list<int> $assets
     *  @return array<string, mixed>
     */
    private function release(int $id, array $assets): array
    {
        return [
            'id' => $id,
            'tag_name' => 'v' . $id . '.0.0',
            'name' => 'Release ' . $id,
            'published_at' => '2026-07-28T12:00:00Z',
            'draft' => false,
            'prerelease' => false,
            'assets' => \array_map(static fn (int $asset): array => [
                'id' => $asset,
                'name' => 'plugin-' . $asset . '.zip',
                'size' => 1024,
                'url' => self::API . '/releases/assets/' . $asset,
            ], $assets),
        ];
    }

    private function pluginZip(string $version, string $shopware): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'extension-mesh-test-');
        self::assertIsString($path);
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open(\sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \basename($path), \ZipArchive::OVERWRITE));
            self::assertTrue($zip->addFromString('AcmePlugin/composer.json', (string) \json_encode([
                'type' => 'shopware-platform-plugin',
                'version' => $version,
                'require' => ['shopware/core' => $shopware, 'php' => '>=8.2'],
                'license' => 'MIT',
                'authors' => [['name' => 'Acme']],
                'extra' => [
                    'shopware-plugin-class' => 'Acme\\AcmePlugin',
                    'label' => ['de-DE' => 'Beispiel', 'en-GB' => 'Example'],
                ],
            ], \JSON_THROW_ON_ERROR)));
            self::assertTrue($zip->close());
            $bytes = \file_get_contents($path);
            self::assertIsString($bytes);

            return $bytes;
        } finally {
            @\unlink(\sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \basename($path));
        }
    }
}
