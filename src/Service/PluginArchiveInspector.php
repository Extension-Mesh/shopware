<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;

final class PluginArchiveInspector
{
    public function __construct(private readonly ZipValidator $validator)
    {
    }

    /**
     * @return array{
     *     name: string,
     *     version: string,
     *     shopware: string,
     *     php: ?string,
     *     label: array<string, string>,
     *     description: array<string, string>,
     *     manufacturer: ?string,
     *     license: ?string,
     *     homepage: ?string
     * }
     */
    public function inspect(string $path): array
    {
        $archive = new \ZipArchive();
        if ($archive->open($path) !== true) {
            throw ExtensionMeshException::artifactRejected('the file is not a readable ZIP archive.');
        }

        try {
            $composerPaths = [];
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $name = $archive->getNameIndex($index);
                if (\is_string($name) && \preg_match('#^[A-Za-z][A-Za-z0-9]*/composer\.json$#D', $name)) {
                    $composerPaths[] = $name;
                }
            }
            if (\count($composerPaths) !== 1) {
                throw ExtensionMeshException::artifactRejected(
                    'the ZIP must contain exactly one <technical-name>/composer.json file.'
                );
            }

            $composerJson = $archive->getFromName($composerPaths[0]);
            if (!\is_string($composerJson)) {
                throw ExtensionMeshException::artifactRejected('composer.json could not be read.');
            }
        } finally {
            $archive->close();
        }

        try {
            $composer = \json_decode($composerJson, true, 128, \JSON_THROW_ON_ERROR);
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
        $technicalName = \end($classParts);
        if (!\preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $technicalName)) {
            throw ExtensionMeshException::artifactRejected('the Shopware plugin class has an invalid technical name.');
        }

        $version = $composer['version'] ?? null;
        if (!\is_string($version) || !\preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/D', $version)) {
            throw ExtensionMeshException::artifactRejected('composer.json has no supported release version.');
        }

        $requirements = \is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $shopware = $requirements['shopware/core'] ?? $requirements['shopware/platform'] ?? null;
        if (!\is_string($shopware) || \trim($shopware) === '') {
            throw ExtensionMeshException::artifactRejected('composer.json must require shopware/core.');
        }
        $php = $requirements['php'] ?? null;
        if ($php !== null && !\is_string($php)) {
            throw ExtensionMeshException::artifactRejected('composer.json has an invalid PHP requirement.');
        }

        $this->validator->validate($path, $technicalName, $version);

        $label = $this->localized($composer['extra']['label'] ?? null, $technicalName);
        $description = $this->localized($composer['extra']['description'] ?? null, '');
        if ($description === [] && \is_string($composer['description'] ?? null)) {
            $description = ['en-GB' => $composer['description']];
        }

        $manufacturer = null;
        if (\is_array($composer['authors'] ?? null)) {
            foreach ($composer['authors'] as $author) {
                if (\is_array($author) && \is_string($author['name'] ?? null) && \trim($author['name']) !== '') {
                    $manufacturer = \mb_substr(\trim($author['name']), 0, 255);
                    break;
                }
            }
        }

        return [
            'name' => $technicalName,
            'version' => $version,
            'shopware' => \trim($shopware),
            'php' => \is_string($php) && \trim($php) !== '' ? \trim($php) : null,
            'label' => $label,
            'description' => $description,
            'manufacturer' => $manufacturer,
            'license' => $this->scalarString($composer['license'] ?? null),
            'homepage' => $this->validUrl($composer['homepage'] ?? null),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function localized(mixed $value, string $fallback): array
    {
        if (\is_string($value) && \trim($value) !== '') {
            return ['en-GB' => \mb_substr(\trim($value), 0, 5000)];
        }

        $result = [];
        if (\is_array($value)) {
            foreach ($value as $locale => $text) {
                if (
                    \is_string($locale)
                    && \preg_match('/^[a-z]{2}-[A-Z]{2}$/D', $locale)
                    && \is_string($text)
                ) {
                    $result[$locale] = \mb_substr($text, 0, 5000);
                }
            }
        }

        if ($result === [] && $fallback !== '') {
            return ['en-GB' => $fallback];
        }

        return $result;
    }

    private function scalarString(mixed $value): ?string
    {
        if (\is_array($value)) {
            $value = $value[0] ?? null;
        }

        return \is_string($value) && \trim($value) !== ''
            ? \mb_substr(\trim($value), 0, 255)
            : null;
    }

    private function validUrl(mixed $value): ?string
    {
        if (!\is_string($value) || \filter_var($value, \FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return \in_array(\strtolower((string) \parse_url($value, \PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $value
            : null;
    }
}
