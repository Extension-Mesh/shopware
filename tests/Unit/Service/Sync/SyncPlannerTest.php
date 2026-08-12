<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service\Sync;

use ExtensionMesh\Shopware\Service\Sync\LocalExtension;
use ExtensionMesh\Shopware\Service\Sync\SyncAction;
use ExtensionMesh\Shopware\Service\Sync\SyncPlanner;
use PHPUnit\Framework\TestCase;

final class SyncPlannerTest extends TestCase
{
    public function testItPlansCatalogAndOwnershipStatesWithoutManagingUnrelatedPlugins(): void
    {
        $registry = 'https://registry.example/registry.json';
        $catalog = [
            $this->extension('NewPlugin', '1.0.0', $registry),
            $this->extension('CurrentPlugin', '1.0.0', $registry),
            $this->extension('UpdatePlugin', '2.0.0', $registry),
            $this->extension('InactivePlugin', '1.0.0', $registry),
            $this->extension('ManualPlugin', '2.0.0', $registry),
        ];
        $local = [
            'CurrentPlugin' => new LocalExtension('CurrentPlugin', '1.0.0', true, true),
            'UpdatePlugin' => new LocalExtension('UpdatePlugin', '1.0.0', true, true),
            'InactivePlugin' => new LocalExtension('InactivePlugin', '1.0.0', true, false),
            'OldPlugin' => new LocalExtension('OldPlugin', '1.0.0', true, true),
            'ManualPlugin' => new LocalExtension('ManualPlugin', '1.0.0', true, true),
            'UnrelatedPlugin' => new LocalExtension('UnrelatedPlugin', '1.0.0', true, true),
        ];
        $ownership = [
            'CurrentPlugin' => $registry,
            'UpdatePlugin' => $registry,
            'InactivePlugin' => $registry,
            'OldPlugin' => $registry,
        ];

        $plan = (new SyncPlanner())->plan($catalog, $local, $ownership);
        $actions = [];
        foreach ($plan->actions as $action) {
            $actions[$action->technicalName] = $action->action;
        }

        self::assertSame([
            'CurrentPlugin' => SyncAction::CURRENT,
            'InactivePlugin' => SyncAction::ACTIVATE,
            'ManualPlugin' => SyncAction::UNMANAGED,
            'NewPlugin' => SyncAction::INSTALL,
            'OldPlugin' => SyncAction::PRUNE,
            'UpdatePlugin' => SyncAction::UPDATE,
        ], $actions);
        self::assertArrayNotHasKey('UnrelatedPlugin', $actions);
    }

    public function testUninstalledPreparedPluginCanBeInstalledButUnownedFilesAreSkipped(): void
    {
        $registry = 'https://registry.example/registry.json';
        $catalog = [
            $this->extension('PreparedPlugin', '1.0.0', $registry),
            $this->extension('LoosePlugin', '1.0.0', $registry),
        ];
        $local = [
            'PreparedPlugin' => new LocalExtension('PreparedPlugin', '1.0.0', false, false),
            'LoosePlugin' => new LocalExtension('LoosePlugin', '1.0.0', false, false),
        ];

        $plan = (new SyncPlanner())->plan($catalog, $local, ['PreparedPlugin' => $registry]);

        self::assertSame(SyncAction::UNMANAGED, $plan->actions[0]->action);
        self::assertSame('LoosePlugin', $plan->actions[0]->technicalName);
        self::assertSame(SyncAction::INSTALL, $plan->actions[1]->action);
        self::assertSame('PreparedPlugin', $plan->actions[1]->technicalName);
    }

    public function testOutdatedInactivePluginPlansUpdateAndActivationIndependently(): void
    {
        $registry = 'https://registry.example/registry.json';
        $plan = (new SyncPlanner())->plan(
            [$this->extension('InactiveUpdatePlugin', '2.0.0', $registry)],
            ['InactiveUpdatePlugin' => new LocalExtension('InactiveUpdatePlugin', '1.0.0', true, false)],
            ['InactiveUpdatePlugin' => $registry]
        );

        self::assertCount(2, $plan->actions);
        self::assertSame(SyncAction::UPDATE, $plan->actions[0]->action);
        self::assertSame(SyncAction::ACTIVATE, $plan->actions[1]->action);
    }

    /** @return array<string, mixed> */
    private function extension(string $name, string $version, string $registry): array
    {
        return [
            'name' => $name,
            'version' => $version,
            'extensionMesh' => [
                'registryId' => '0123456789abcdef0123456789abcdef',
                'registryUrl' => $registry,
                'conflict' => false,
            ],
        ];
    }
}
