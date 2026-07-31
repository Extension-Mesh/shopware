<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use ExtensionMesh\Shopware\Infrastructure\Http\SafeHttpClient;
use ExtensionMesh\Shopware\Infrastructure\Persistence\ExtensionOwnershipRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\PluginManagementService;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ExtensionInstaller
{
    public function __construct(
        private readonly SafeHttpClient $httpClient,
        private readonly ZipValidator $zipValidator,
        private readonly PluginManagementService $pluginManagementService,
        private readonly Filesystem $filesystem,
        private readonly ExtensionOwnershipRepository $ownership
    ) {
    }

    /**
     * @param array{
     *     version: string,
     *     downloadUrl: string,
     *     sha256: string
     * } $release
     */
    public function prepare(
        array $release,
        string $technicalName,
        string $registryUrl,
        Context $context,
        ?string $accessToken = null,
        ?string $credentialOrigin = null
    ): void
    {
        $temporaryPath = $this->httpClient->downloadArtifact(
            $release['downloadUrl'],
            $accessToken,
            $credentialOrigin
        );

        try {
            $actualDigest = \hash_file('sha256', $temporaryPath);
            if (!\is_string($actualDigest) || !\hash_equals($release['sha256'], $actualDigest)) {
                throw ExtensionMeshException::artifactRejected('the SHA-256 digest does not match the registry.');
            }

            $this->zipValidator->validate($temporaryPath, $technicalName, $release['version']);

            $uploadedFile = new UploadedFile(
                $temporaryPath,
                $technicalName . '-' . $release['version'] . '.zip',
                'application/zip',
                null,
                true
            );

            $this->pluginManagementService->uploadPlugin($uploadedFile, $context);
            $this->ownership->markPrepared($technicalName, $registryUrl, $context);
        } finally {
            if (\is_file($temporaryPath)) {
                $this->filesystem->remove($temporaryPath);
            }
        }
    }
}
