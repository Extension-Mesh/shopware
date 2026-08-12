<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Command;

use ExtensionMesh\Shopware\Command\SyncCommand;
use ExtensionMesh\Shopware\Service\Sync\SyncAction;
use ExtensionMesh\Shopware\Service\Sync\SyncActionResult;
use ExtensionMesh\Shopware\Service\Sync\SyncOptions;
use ExtensionMesh\Shopware\Service\Sync\SyncResult;
use ExtensionMesh\Shopware\Service\Sync\SyncRunner;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncCommandTest extends TestCase
{
    public function testJsonModeProducesOnlyStableJson(): void
    {
        $action = new SyncAction(
            'AcmePlugin',
            SyncAction::UPDATE,
            '1.0.0',
            '1.1.0',
            'source-id',
            'https://registry.example'
        );
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects(self::once())
            ->method('synchronize')
            ->with(self::callback(static fn (SyncOptions $options): bool => $options->update && $options->dryRun))
            ->willReturn(new SyncResult([new SyncActionResult($action, 'planned', true)]));
        $tester = new CommandTester(new SyncCommand($runner));

        $status = $tester->execute(['--update' => true, '--dry-run' => true, '--json' => true]);
        $decoded = \json_decode(\trim($tester->getDisplay()), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $status);
        self::assertTrue($decoded['success']);
        self::assertSame('AcmePlugin', $decoded['actions'][0]['technicalName']);
        self::assertSame(1, $decoded['summary']['update']);
    }

    public function testJsonFailureHasNonZeroStatusAndStructuredError(): void
    {
        $runner = $this->createMock(SyncRunner::class);
        $runner->method('synchronize')->willThrowException(new \RuntimeException('catalog unavailable'));
        $tester = new CommandTester(new SyncCommand($runner));

        $status = $tester->execute(['--json' => true]);
        $decoded = \json_decode(\trim($tester->getDisplay()), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $status);
        self::assertFalse($decoded['success']);
        self::assertSame(['catalog unavailable'], $decoded['errors']);
    }
}
