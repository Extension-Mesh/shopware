<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;

final class ZipValidator
{
    private const MAX_ENTRIES = 10000;
    private const MAX_UNCOMPRESSED_BYTES = 512 * 1024 * 1024;

    public function validate(string $path, string $technicalName, string $expectedVersion): void
    {
        $archive = new \ZipArchive();
        $result = $archive->open($path);
        if ($result !== true) {
            throw ExtensionMeshException::artifactRejected('the file is not a readable ZIP archive.');
        }

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > self::MAX_ENTRIES) {
                throw ExtensionMeshException::artifactRejected('the ZIP contains an invalid number of entries.');
            }

            $totalSize = 0;
            $seen = [];
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $stat = $archive->statIndex($index);
                if ($stat === false) {
                    throw ExtensionMeshException::artifactRejected('a ZIP entry could not be inspected.');
                }

                $name = $stat['name'];
                $this->validateEntryName($name, $technicalName);
                if (isset($seen[$name])) {
                    throw ExtensionMeshException::artifactRejected(\sprintf('duplicate ZIP entry "%s".', $name));
                }
                $seen[$name] = true;

                $totalSize += $stat['size'];
                if ($totalSize > self::MAX_UNCOMPRESSED_BYTES) {
                    throw ExtensionMeshException::artifactRejected('the uncompressed ZIP exceeds 512 MiB.');
                }

                if ($this->isSymlink($archive, $index)) {
                    throw ExtensionMeshException::artifactRejected(\sprintf('symbolic link "%s" is not allowed.', $name));
                }

                if ($stat['encryption_method'] !== 0) {
                    throw ExtensionMeshException::artifactRejected('encrypted ZIP entries are not supported.');
                }
            }

            $composerPath = $technicalName . '/composer.json';
            $composerJson = $archive->getFromName($composerPath);
            if (!\is_string($composerJson)) {
                throw ExtensionMeshException::artifactRejected(\sprintf('"%s" is missing.', $composerPath));
            }

            $this->validateComposerJson($composerJson, $technicalName, $expectedVersion);
        } finally {
            $archive->close();
        }
    }

    private function validateEntryName(string $name, string $technicalName): void
    {
        if ($name === '' || \str_contains($name, "\0") || \str_contains($name, '\\')) {
            throw ExtensionMeshException::artifactRejected('a ZIP entry has an invalid name.');
        }

        if (\str_starts_with($name, '/') || \preg_match('/^[A-Za-z]:\//', $name)) {
            throw ExtensionMeshException::artifactRejected('absolute ZIP paths are not allowed.');
        }

        $segments = \explode('/', \rtrim($name, '/'));
        if ($segments[0] !== $technicalName) {
            throw ExtensionMeshException::artifactRejected(
                \sprintf('all ZIP entries must be contained in "%s/".', $technicalName)
            );
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw ExtensionMeshException::artifactRejected('ZIP path traversal was detected.');
            }
        }
    }

    private function isSymlink(\ZipArchive $archive, int $index): bool
    {
        $operationsSystem = 0;
        $attributes = 0;
        if (!$archive->getExternalAttributesIndex($index, $operationsSystem, $attributes)) {
            return false;
        }

        $unixMode = ($attributes >> 16) & 0xF000;

        return $unixMode === 0xA000;
    }

    private function validateComposerJson(string $json, string $technicalName, string $expectedVersion): void
    {
        try {
            $composer = \json_decode($json, true, 128, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw ExtensionMeshException::artifactRejected('composer.json is invalid: ' . $exception->getMessage());
        }

        if (!\is_array($composer) || ($composer['type'] ?? null) !== 'shopware-platform-plugin') {
            throw ExtensionMeshException::artifactRejected('composer.json is not a Shopware platform plugin.');
        }

        $pluginClass = $composer['extra']['shopware-plugin-class'] ?? null;
        if (!\is_string($pluginClass) || $pluginClass === '') {
            throw ExtensionMeshException::artifactRejected('composer.json has no shopware-plugin-class.');
        }

        $classParts = \explode('\\', $pluginClass);
        if (\end($classParts) !== $technicalName) {
            throw ExtensionMeshException::artifactRejected(
                'the ZIP root does not match the configured Shopware plugin class.'
            );
        }

        $composerVersion = $composer['version'] ?? null;
        if (!\is_string($composerVersion) || $composerVersion === '') {
            throw ExtensionMeshException::artifactRejected('composer.json has no release version.');
        }
        if (\version_compare($composerVersion, $expectedVersion, '!=')) {
            throw ExtensionMeshException::artifactRejected(
                \sprintf('composer version "%s" does not match registry version "%s".', $composerVersion, $expectedVersion)
            );
        }
    }
}
