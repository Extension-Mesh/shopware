<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Service\Sync;

use ExtensionMesh\Shopware\Service\Sync\ExtensionOperator;
use ExtensionMesh\Shopware\Service\Sync\LocalExtension;
use ExtensionMesh\Shopware\Service\Sync\SyncAction;
use ExtensionMesh\Shopware\Service\Sync\SyncExecutor;
use ExtensionMesh\Shopware\Service\Sync\SyncOptions;
use ExtensionMesh\Shopware\Service\Sync\SyncPlan;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;

final class SyncExecutorTest extends TestCase
{
    public function testItExecutesAllRequestedLifecycleOperations(): void
    {
        $operator = new RecordingOperator();
        $plan = new SyncPlan([
            $this->action('InstallMe', SyncAction::INSTALL),
            $this->action('UpdateMe', SyncAction::UPDATE),
            $this->action('ActivateMe', SyncAction::ACTIVATE),
            $this->action('PruneMe', SyncAction::PRUNE),
        ]);

        $result = (new SyncExecutor($operator))->execute(
            $plan,
            new SyncOptions(install: true, update: true, activate: true, prune: true),
            Context::createCLIContext()
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame([
            'install:InstallMe:activate',
            'update:UpdateMe:activate',
            'activate:ActivateMe',
            'prune:PruneMe',
        ], $operator->calls);
    }

    public function testDryRunCausesNoMutations(): void
    {
        $operator = new RecordingOperator();
        $result = (new SyncExecutor($operator))->execute(
            new SyncPlan([$this->action('InstallMe', SyncAction::INSTALL)]),
            new SyncOptions(install: true, dryRun: true),
            Context::createCLIContext()
        );

        self::assertSame([], $operator->calls);
        self::assertSame('planned', $result->actions[0]->status);
        self::assertTrue($result->isSuccessful());
    }

    public function testFailureDoesNotStopIndependentActionsAndFailsOverallResult(): void
    {
        $operator = new RecordingOperator('UpdateMe');
        $plan = new SyncPlan([
            $this->action('UpdateMe', SyncAction::UPDATE),
            $this->action('ActivateMe', SyncAction::ACTIVATE),
        ]);

        $result = (new SyncExecutor($operator))->execute(
            $plan,
            new SyncOptions(update: true, activate: true),
            Context::createCLIContext()
        );

        self::assertFalse($result->isSuccessful());
        self::assertSame('failed', $result->actions[0]->status);
        self::assertSame('completed', $result->actions[1]->status);
        self::assertSame(1, $result->summary()['failed']);
    }

    private function action(string $name, string $action): SyncAction
    {
        return new SyncAction(
            $name,
            $action,
            $action === SyncAction::INSTALL ? null : '1.0.0',
            $action === SyncAction::PRUNE ? null : '2.0.0',
            $action === SyncAction::PRUNE ? null : '0123456789abcdef0123456789abcdef',
            'https://registry.example/registry.json'
        );
    }
}

final class RecordingOperator implements ExtensionOperator
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly ?string $fail = null)
    {
    }

    public function inventory(Context $context): array
    {
        return [];
    }

    public function install(SyncAction $action, bool $activate, bool $refresh, Context $context): void
    {
        $this->record('install:' . $action->technicalName . ($activate ? ':activate' : ''));
    }

    public function update(SyncAction $action, bool $activate, bool $refresh, Context $context): void
    {
        $this->record('update:' . $action->technicalName . ($activate ? ':activate' : ''));
    }

    public function activate(SyncAction $action, Context $context): void
    {
        $this->record('activate:' . $action->technicalName);
    }

    public function prune(SyncAction $action, Context $context): void
    {
        $this->record('prune:' . $action->technicalName);
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if ($this->fail !== null && \str_contains($call, $this->fail)) {
            throw new \RuntimeException('lifecycle failed');
        }
    }
}
