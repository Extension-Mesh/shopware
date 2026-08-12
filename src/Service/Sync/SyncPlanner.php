<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

use Composer\Semver\Comparator;

final class SyncPlanner
{
    /**
     * @param list<array<string, mixed>> $catalogExtensions
     * @param array<string, LocalExtension> $localExtensions
     * @param array<string, string> $ownership
     */
    public function plan(array $catalogExtensions, array $localExtensions, array $ownership): SyncPlan
    {
        $actions = [];
        $availableFromOwner = [];

        foreach ($catalogExtensions as $extension) {
            $technicalName = $this->string($extension, 'name');
            $availableVersion = $this->string($extension, 'version');
            $mesh = \is_array($extension['extensionMesh'] ?? null) ? $extension['extensionMesh'] : [];
            $registryId = $this->nullableString($mesh, 'registryId');
            $registryUrl = $this->string($mesh, 'registryUrl');
            $conflict = ($mesh['conflict'] ?? false) === true;

            if ($technicalName === '' || $availableVersion === '' || $registryUrl === '') {
                continue;
            }

            $availableFromOwner[$technicalName . "\0" . $registryUrl] = true;
            $local = $localExtensions[$technicalName] ?? null;

            if ($conflict) {
                $actions[$technicalName] = new SyncAction(
                    $technicalName,
                    SyncAction::CONFLICT,
                    $local?->version,
                    $availableVersion,
                    $registryId,
                    $registryUrl,
                    $local === null ? false : $local->active
                );
                continue;
            }

            $owned = isset($ownership[$technicalName])
                && \hash_equals($ownership[$technicalName], $registryUrl);

            if ($local === null) {
                $actions[$technicalName] = new SyncAction(
                    $technicalName,
                    SyncAction::INSTALL,
                    null,
                    $availableVersion,
                    $registryId,
                    $registryUrl
                );
                continue;
            }

            if (!$owned) {
                $actions[$technicalName] = new SyncAction(
                    $technicalName,
                    SyncAction::UNMANAGED,
                    $local->version,
                    $availableVersion,
                    $registryId,
                    $registryUrl,
                    $local->active
                );
                continue;
            }

            if (!$local->installed) {
                $actions[$technicalName] = new SyncAction(
                    $technicalName,
                    SyncAction::INSTALL,
                    null,
                    $availableVersion,
                    $registryId,
                    $registryUrl
                );
                continue;
            }

            if (Comparator::greaterThan($availableVersion, $local->version)) {
                $actions[$technicalName . "\0" . '1-update'] = new SyncAction(
                    $technicalName,
                    SyncAction::UPDATE,
                    $local->version,
                    $availableVersion,
                    $registryId,
                    $registryUrl,
                    $local->active
                );
                if (!$local->active) {
                    $actions[$technicalName . "\0" . '2-activate'] = new SyncAction(
                        $technicalName,
                        SyncAction::ACTIVATE,
                        $local->version,
                        $availableVersion,
                        $registryId,
                        $registryUrl,
                        false
                    );
                }
                continue;
            }

            $actions[$technicalName] = new SyncAction(
                $technicalName,
                $local->active ? SyncAction::CURRENT : SyncAction::ACTIVATE,
                $local->version,
                $availableVersion,
                $registryId,
                $registryUrl,
                $local->active
            );
        }

        foreach ($ownership as $technicalName => $registryUrl) {
            $local = $localExtensions[$technicalName] ?? null;
            if ($local === null || !$local->installed) {
                continue;
            }
            if (isset($availableFromOwner[$technicalName . "\0" . $registryUrl])) {
                continue;
            }

            $actions[$technicalName] = new SyncAction(
                $technicalName,
                SyncAction::PRUNE,
                $local->version,
                null,
                null,
                $registryUrl,
                $local->active
            );
        }

        \ksort($actions);

        return new SyncPlan(\array_values($actions));
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): string
    {
        return \is_string($values[$key] ?? null) ? $values[$key] : '';
    }

    /** @param array<string, mixed> $values */
    private function nullableString(array $values, string $key): ?string
    {
        return \is_string($values[$key] ?? null) ? $values[$key] : null;
    }
}
