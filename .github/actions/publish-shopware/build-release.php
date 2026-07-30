<?php declare(strict_types=1);

/**
 * This script intentionally has no Composer dependencies so it can run in any
 * conventional public Shopware plugin repository.
 */

function fail(string $message): never
{
    fwrite(STDERR, "ExtensionMesh: {$message}\n");
    exit(1);
}

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fail("environment variable {$name} is required.");
    }

    return $value;
}

/**
 * @param array<string, mixed> $values
 *
 * @return array<string, string>
 */
function localized(array $values, string $fallback): array
{
    $result = [];
    foreach ($values as $locale => $value) {
        if (is_string($locale) && is_string($value) && $value !== '') {
            $result[$locale] = $value;
        }
    }

    return $result !== [] ? $result : ['en-GB' => $fallback];
}

function output(string $name, string $value): void
{
    $outputFile = requiredEnvironment('GITHUB_OUTPUT');
    if (file_put_contents($outputFile, "{$name}={$value}\n", FILE_APPEND) === false) {
        fail('could not write GitHub Action outputs.');
    }
}

function sourceTimestamp(string $repositoryRoot): int
{
    $command = sprintf(
        'git -C %s log -1 --format=%%ct HEAD',
        escapeshellarg($repositoryRoot)
    );
    exec($command, $output, $exitCode);
    $timestamp = trim(implode("\n", $output));

    if ($exitCode !== 0 || !preg_match('/^[0-9]+$/', $timestamp)) {
        fail('the source commit timestamp could not be determined.');
    }

    return (int) $timestamp;
}

/**
 * @return list<array<string, mixed>>
 */
function previousReleases(string $repository, string $technicalName, string $currentVersion): array
{
    if (getenv('EXTENSION_MESH_SKIP_HISTORY') === '1') {
        return [];
    }

    $token = requiredEnvironment('EXTENSION_MESH_GITHUB_TOKEN');
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'User-Agent: ExtensionMesh-Publisher/1',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $context = stream_context_create([
        'http' => [
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ]);

    $apiUrl = sprintf('https://api.github.com/repos/%s/releases?per_page=100', $repository);
    $response = file_get_contents($apiUrl, false, $context);
    if (!is_string($response)) {
        fail('previous GitHub releases could not be inspected.');
    }

    try {
        $githubReleases = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail('GitHub returned invalid release metadata: ' . $exception->getMessage());
    }
    if (!is_array($githubReleases) || !array_is_list($githubReleases)) {
        fail('GitHub did not return a release list. Check the workflow permissions.');
    }

    $history = [];
    $versions = [$currentVersion => true];
    foreach ($githubReleases as $githubRelease) {
        if (!is_array($githubRelease) || ($githubRelease['draft'] ?? true) === true) {
            continue;
        }

        $assets = $githubRelease['assets'] ?? null;
        if (!is_array($assets)) {
            continue;
        }

        foreach ($assets as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? null) !== 'extension-mesh-release.json') {
                continue;
            }

            $assetApiUrl = $asset['url'] ?? null;
            $expectedAssetApiPrefix = sprintf(
                'https://api.github.com/repos/%s/releases/assets/',
                $repository
            );
            if (!is_string($assetApiUrl) || !str_starts_with($assetApiUrl, $expectedAssetApiPrefix)) {
                fail('a previous release manifest has an invalid asset API URL.');
            }

            $assetHeaders = $headers;
            $assetHeaders[0] = 'Accept: application/octet-stream';
            $assetContext = stream_context_create([
                'http' => [
                    'header' => implode("\r\n", $assetHeaders),
                    'ignore_errors' => true,
                    'timeout' => 30,
                ],
            ]);
            $manifestJson = file_get_contents($assetApiUrl, false, $assetContext);
            if (!is_string($manifestJson)) {
                fail('a previous extension-mesh-release.json could not be downloaded.');
            }

            try {
                $manifest = json_decode($manifestJson, true, 128, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                fail('a previous release manifest is invalid: ' . $exception->getMessage());
            }

            if (!is_array($manifest)
                || ($manifest['schemaVersion'] ?? null) !== 1
                || ($manifest['repository'] ?? null) !== $repository
                || ($manifest['extension']['name'] ?? null) !== $technicalName
                || !is_array($manifest['extension']['releases'] ?? null)
            ) {
                fail('a previous release manifest does not belong to this repository and technical name.');
            }

            foreach ($manifest['extension']['releases'] as $release) {
                $version = is_array($release) ? ($release['version'] ?? null) : null;
                if (!is_string($version) || isset($versions[$version])) {
                    continue;
                }

                $required = ['shopware', 'downloadUrl', 'sha256', 'releasedAt'];
                foreach ($required as $field) {
                    if (!isset($release[$field]) || !is_string($release[$field])) {
                        fail("a previous release is missing \"{$field}\".");
                    }
                }
                if (!preg_match('/^[a-f0-9]{64}$/', $release['sha256'])) {
                    fail('a previous release has an invalid SHA-256 digest.');
                }

                $versions[$version] = true;
                $history[] = $release;
                if (count($history) >= 199) {
                    return $history;
                }
            }
        }
    }

    return $history;
}

$repositoryRoot = requiredEnvironment('GITHUB_WORKSPACE');
$repository = requiredEnvironment('GITHUB_REPOSITORY');
$tagOverride = getenv('EXTENSION_MESH_TAG');
$tag = is_string($tagOverride) && $tagOverride !== ''
    ? $tagOverride
    : requiredEnvironment('GITHUB_REF_NAME');
$outputDirectory = requiredEnvironment('EXTENSION_MESH_OUTPUT');
$sourceTimestamp = sourceTimestamp($repositoryRoot);

if (!preg_match('/^v?([0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.-]+)?)$/', $tag, $versionMatch)) {
    fail("tag \"{$tag}\" is not a supported release version.");
}
$version = $versionMatch[1];

$composerPath = $repositoryRoot . '/composer.json';
$composerJson = file_get_contents($composerPath);
if (!is_string($composerJson)) {
    fail('composer.json was not found at the repository root.');
}

try {
    $composer = json_decode($composerJson, true, 128, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fail('composer.json is invalid: ' . $exception->getMessage());
}

if (!is_array($composer) || ($composer['type'] ?? null) !== 'shopware-platform-plugin') {
    fail('composer.json must have type "shopware-platform-plugin".');
}

$pluginClass = $composer['extra']['shopware-plugin-class'] ?? null;
if (!is_string($pluginClass) || $pluginClass === '') {
    fail('composer.json must define extra.shopware-plugin-class.');
}
$classParts = explode('\\', $pluginClass);
$technicalName = end($classParts);
if (!is_string($technicalName) || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $technicalName)) {
    fail('the Shopware plugin class does not produce a valid technical name.');
}

$composerVersion = $composer['version'] ?? null;
if (is_string($composerVersion) && version_compare($composerVersion, $version, '!=')) {
    fail("tag version {$version} does not match composer version {$composerVersion}.");
}

$shopwareConstraint = $composer['require']['shopware/core'] ?? null;
if (!is_string($shopwareConstraint) || $shopwareConstraint === '') {
    fail('composer.json must constrain shopware/core.');
}
$phpConstraint = $composer['require']['php'] ?? null;
if (!is_string($phpConstraint) || $phpConstraint === '') {
    fail('composer.json must constrain PHP.');
}

$autoload = $composer['autoload']['psr-4'] ?? [];
if (!is_array($autoload)) {
    fail('composer.json must configure PSR-4 autoloading.');
}

$classFound = false;
foreach ($autoload as $namespace => $directory) {
    if (!is_string($namespace) || !is_string($directory) || !str_starts_with($pluginClass, $namespace)) {
        continue;
    }

    $relativeClass = substr($pluginClass, strlen($namespace));
    $classPath = $repositoryRoot . '/' . trim($directory, '/') . '/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($classPath)) {
        $classFound = true;
        break;
    }
}
if (!$classFound) {
    fail("plugin class {$pluginClass} could not be found through PSR-4 autoloading.");
}

if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
    fail('release output directory could not be created.');
}

$archiveName = $technicalName . '-' . $version . '.zip';
$archivePath = $outputDirectory . '/' . $archiveName;

$pathSpecs = [
    '.',
    ':(exclude).github',
    ':(exclude)docker',
    ':(exclude)docs',
    ':(exclude)tests',
    ':(exclude)node_modules',
    ':(exclude).gitignore',
    ':(exclude).gitattributes',
    ':(exclude)compose.yaml',
    ':(exclude)docker-compose.yml',
    ':(exclude)docker-compose.yaml',
    ':(exclude)Makefile',
    ':(exclude)phpunit.xml',
    ':(exclude)phpunit.xml.dist',
    ':(exclude)phpstan.neon',
    ':(exclude)phpstan.neon.dist',
];

$archiveCommand = sprintf(
    'git -C %s archive --format=zip --prefix=%s/ --output=%s HEAD -- %s',
    escapeshellarg($repositoryRoot),
    escapeshellarg($technicalName),
    escapeshellarg($archivePath),
    implode(' ', array_map('escapeshellarg', $pathSpecs))
);
exec($archiveCommand, $archiveOutput, $archiveExitCode);
if ($archiveExitCode !== 0 || !is_file($archivePath)) {
    fail('git archive could not create the plugin ZIP.');
}

$archive = new ZipArchive();
if ($archive->open($archivePath) !== true) {
    fail('the generated plugin ZIP could not be opened.');
}
$composer['version'] = $version;
try {
    $packagedComposer = json_encode(
        $composer,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
} catch (JsonException $exception) {
    $archive->close();
    fail('composer.json could not be packaged: ' . $exception->getMessage());
}
$composerEntry = $technicalName . '/composer.json';
if (!$archive->addFromString($composerEntry, $packagedComposer)) {
    $archive->close();
    fail('composer.json could not be stamped with the tag version.');
}
if (!$archive->setMtimeName($composerEntry, $sourceTimestamp)) {
    $archive->close();
    fail('composer.json could not receive the source commit timestamp.');
}
if (!$archive->close()) {
    fail('the generated plugin ZIP could not be finalized.');
}

$digest = hash_file('sha256', $archivePath);
if (!is_string($digest)) {
    fail('the release digest could not be calculated.');
}

$extra = is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
$labels = is_array($extra['label'] ?? null)
    ? localized($extra['label'], $technicalName)
    : ['en-GB' => $technicalName];
$descriptions = is_array($extra['description'] ?? null)
    ? localized($extra['description'], (string) ($composer['description'] ?? ''))
    : ['en-GB' => (string) ($composer['description'] ?? '')];

$assetUrl = sprintf(
    'https://github.com/%s/releases/download/%s/%s',
    $repository,
    rawurlencode($tag),
    rawurlencode($archiveName)
);
$releasedAt = gmdate(DATE_ATOM);
$release = [
    'version' => $version,
    'shopware' => $shopwareConstraint,
    'php' => $phpConstraint,
    'downloadUrl' => $assetUrl,
    'sha256' => $digest,
    'releasedAt' => $releasedAt,
    'security' => false,
    'changelogUrl' => sprintf('https://github.com/%s/releases/tag/%s', $repository, rawurlencode($tag)),
];
$releases = [$release, ...previousReleases($repository, $technicalName, $version)];
usort(
    $releases,
    static fn (array $left, array $right): int => version_compare(
        (string) $right['version'],
        (string) $left['version']
    )
);

$extension = [
    'name' => $technicalName,
    'label' => $labels,
    'description' => $descriptions,
    'manufacturer' => (string) ($composer['authors'][0]['name'] ?? $repository),
    'license' => is_string($composer['license'] ?? null)
        ? $composer['license']
        : implode(' OR ', is_array($composer['license'] ?? null) ? $composer['license'] : []),
    'homepage' => (string) ($composer['homepage'] ?? sprintf('https://github.com/%s', $repository)),
    'releases' => $releases,
];

$releaseManifestPath = $outputDirectory . '/extension-mesh-release.json';
$registryPath = $outputDirectory . '/extension-mesh-registry.json';
$checksumsPath = $outputDirectory . '/SHA256SUMS';

$releaseManifest = [
    'schemaVersion' => 1,
    'repository' => $repository,
    'tag' => $tag,
    'extension' => $extension,
];
$registry = [
    'schemaVersion' => 1,
    'name' => $repository,
    'extensions' => [$extension],
];

$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
file_put_contents($releaseManifestPath, json_encode($releaseManifest, $jsonFlags) . "\n");
file_put_contents($registryPath, json_encode($registry, $jsonFlags) . "\n");
file_put_contents($checksumsPath, "{$digest}  {$archiveName}\n");

output('archive', $archivePath);
output('release-manifest', $releaseManifestPath);
output('registry', $registryPath);
output('checksums', $checksumsPath);
output('technical-name', $technicalName);
output('version', $version);
output('tag', $tag);

fwrite(STDOUT, "Built {$archiveName} and extension-mesh-registry.json\n");
