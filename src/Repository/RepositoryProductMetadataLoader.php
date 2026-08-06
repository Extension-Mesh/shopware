<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Repository;

use ExtensionMesh\Shopware\Exception\ExtensionMeshException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class RepositoryProductMetadataLoader
{
    private const CONFIG_PATHS = [
        '.shopware-extension.yml',
        '.shopware-extension.yaml',
        'shopware-extension.yml',
        'shopware-extension.yaml',
    ];

    /**
     * @return array{
     *     configPath: ?string,
     *     labels: array<string, string>,
     *     descriptions: array<string, string>,
     *     metaTitles: array<string, string>,
     *     metaDescriptions: array<string, string>,
     *     keywords: array<string, string>,
     *     iconPath: ?string,
     *     imagePaths: list<string>,
     *     store: array<string, mixed>
     * }
     */
    public function load(
        RepositoryProvider $provider,
        string $repository,
        string $apiBaseUrl,
        string $credential,
        string $reference
    ): array {
        $configPath = null;
        $yaml = null;
        foreach (self::CONFIG_PATHS as $candidate) {
            $contents = $provider->readFile($repository, $apiBaseUrl, $credential, $candidate, $reference);
            if ($contents !== null) {
                $configPath = $candidate;
                $yaml = $contents;
                break;
            }
        }

        if ($yaml === null) {
            return $this->empty();
        }

        try {
            $document = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw ExtensionMeshException::invalidRepository(
                \sprintf('%s is invalid YAML: %s', $configPath, $exception->getMessage())
            );
        }
        if (!\is_array($document) || !\is_array($document['store'] ?? null)) {
            return $this->empty();
        }

        $store = $document['store'];
        $metaTitles = $this->localized($store['meta_title'] ?? null);
        $metaDescriptions = $this->localized($store['meta_description'] ?? null);
        $descriptions = $this->localizedFiles(
            $store['description'] ?? null,
            $provider,
            $repository,
            $apiBaseUrl,
            $credential,
            $reference
        );

        $keywords = [];
        if (\is_array($store['tags'] ?? null)) {
            foreach ($store['tags'] as $locale => $tags) {
                if (!\is_string($locale) || !\is_array($tags)) {
                    continue;
                }
                $values = [];
                foreach ($tags as $tag) {
                    if (\is_string($tag) && \trim($tag) !== '') {
                        $values[] = \mb_substr(\trim($tag), 0, 255);
                    }
                }
                if ($values !== []) {
                    $keywords[$this->locale($locale)] = \mb_substr(\implode(', ', $values), 0, 1000);
                }
            }
        }

        $labels = [];
        foreach ($metaTitles as $locale => $title) {
            $labels[$locale] = $this->productLabel($title);
        }

        $iconPath = $store['icon'] ?? null;
        if (\is_string($iconPath) && \trim($iconPath) !== '') {
            $iconPath = $this->path($iconPath);
        } else {
            $iconPath = null;
        }
        $imagePaths = $this->imagePaths(
            $store,
            $provider,
            $repository,
            $apiBaseUrl,
            $credential,
            $reference
        );

        return [
            'configPath' => $configPath,
            'labels' => $labels,
            'descriptions' => $descriptions,
            'metaTitles' => $metaTitles,
            'metaDescriptions' => $metaDescriptions,
            'keywords' => $keywords,
            'iconPath' => $iconPath,
            'imagePaths' => $imagePaths,
            'store' => $store,
        ];
    }

    /**
     * @return array{
     *     configPath: null,
     *     labels: array<string, string>,
     *     descriptions: array<string, string>,
     *     metaTitles: array<string, string>,
     *     metaDescriptions: array<string, string>,
     *     keywords: array<string, string>,
     *     iconPath: null,
     *     imagePaths: list<string>,
     *     store: array<string, mixed>
     * }
     */
    private function empty(): array
    {
        return [
            'configPath' => null,
            'labels' => [],
            'descriptions' => [],
            'metaTitles' => [],
            'metaDescriptions' => [],
            'keywords' => [],
            'iconPath' => null,
            'imagePaths' => [],
            'store' => [],
        ];
    }

    /**
     * @param array<string, mixed> $store
     *
     * @return list<string>
     */
    private function imagePaths(
        array $store,
        RepositoryProvider $provider,
        string $repository,
        string $apiBaseUrl,
        string $credential,
        string $reference
    ): array {
        $directory = $store['image_directory'] ?? null;
        if (\is_string($directory) && \trim($directory) !== '') {
            $directory = $this->path($directory);
        } else {
            $directory = null;
        }

        if (\is_array($store['images'] ?? null)) {
            $images = [];
            foreach ($store['images'] as $position => $image) {
                if (!\is_array($image) || !\is_string($image['file'] ?? null)) {
                    continue;
                }
                $file = $this->path($image['file']);
                $path = $directory === null ? $file : $directory . '/' . $file;
                if (!\preg_match('/\.(?:png|jpe?g|webp)$/iD', $path)) {
                    continue;
                }
                $images[] = [
                    'path' => $path,
                    'priority' => \is_int($image['priority'] ?? null)
                        ? $image['priority']
                        : \PHP_INT_MAX,
                    'position' => $position,
                ];
            }
            \usort($images, static function (array $left, array $right): int {
                $priority = $left['priority'] <=> $right['priority'];

                return $priority !== 0 ? $priority : $left['position'] <=> $right['position'];
            });

            return \array_slice(\array_values(\array_unique(\array_column($images, 'path'))), 0, 8);
        }

        if ($directory === null) {
            return [];
        }
        $defaultLocale = \is_string($store['default_locale'] ?? null)
            ? $this->locale($store['default_locale'])
            : 'en-GB';
        $localizedDirectory = $directory . '/' . \substr($defaultLocale, 0, 2);
        $files = $provider->listFiles(
            $repository,
            $apiBaseUrl,
            $credential,
            $localizedDirectory,
            $reference
        );
        if ($files === []) {
            $files = $provider->listFiles(
                $repository,
                $apiBaseUrl,
                $credential,
                $directory,
                $reference
            );
        }

        $paths = [];
        foreach ($files as $file) {
            if (\preg_match('/\.(?:png|jpe?g|webp)$/iD', $file['path'])) {
                $paths[] = $file['path'];
            }
            if (\count($paths) === 8) {
                break;
            }
        }

        return $paths;
    }

    /**
     * @return array<string, string>
     */
    private function localized(mixed $value): array
    {
        $values = [];
        if (\is_string($value) && \trim($value) !== '') {
            return ['en-GB' => \mb_substr(\trim($value), 0, 5000)];
        }
        if (!\is_array($value)) {
            return [];
        }

        foreach ($value as $locale => $text) {
            if (\is_string($locale) && \is_string($text) && \trim($text) !== '') {
                $values[$this->locale($locale)] = \mb_substr(\trim($text), 0, 5000);
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function localizedFiles(
        mixed $value,
        RepositoryProvider $provider,
        string $repository,
        string $apiBaseUrl,
        string $credential,
        string $reference
    ): array {
        $values = $this->localized($value);
        foreach ($values as $locale => $text) {
            if (!\str_starts_with($text, 'file:')) {
                continue;
            }
            $path = $this->path(\substr($text, 5));
            $contents = $provider->readFile(
                $repository,
                $apiBaseUrl,
                $credential,
                $path,
                $reference
            );
            if ($contents === null) {
                throw ExtensionMeshException::invalidRepository(
                    \sprintf('referenced description file "%s" was not found.', $path)
                );
            }
            $values[$locale] = \mb_substr(\trim($contents), 0, 100000);
        }

        return $values;
    }

    private function locale(string $locale): string
    {
        $locale = \str_replace('_', '-', \trim($locale));
        if (\preg_match('/^([a-z]{2})-([A-Za-z]{2})$/D', $locale, $match)) {
            return \strtolower($match[1]) . '-' . \strtoupper($match[2]);
        }
        if (\preg_match('/^[a-z]{2}$/iD', $locale)) {
            return \strtolower($locale);
        }

        throw ExtensionMeshException::invalidRepository(\sprintf('locale "%s" is invalid.', $locale));
    }

    private function path(string $path): string
    {
        $path = \str_replace('\\', '/', \trim($path));
        if (
            $path === ''
            || \str_starts_with($path, '/')
            || \str_contains('/' . $path . '/', '/../')
            || \preg_match('/[\x00-\x1f\x7f]/', $path)
        ) {
            throw ExtensionMeshException::invalidRepository('a metadata file path is invalid.');
        }

        return $path;
    }

    private function productLabel(string $metaTitle): string
    {
        $parts = \preg_split('/\s+[|–—-]\s+/u', $metaTitle, 2);
        $label = \is_array($parts) ? $parts[0] : $metaTitle;

        return \mb_substr(\trim($label), 0, 255);
    }
}
