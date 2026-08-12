<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Command;

use ExtensionMesh\Shopware\Command\RefreshCommand;
use ExtensionMesh\Shopware\Service\CatalogManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshCommandTest extends TestCase
{
    public function testItReportsEachRegistryAndFailsForPartialRefresh(): void
    {
        $catalog = $this->createMock(CatalogManager::class);
        $catalog->method('refreshAll')->willReturn([
            ['id' => 'one', 'url' => 'https://one.example', 'label' => 'One', 'success' => true, 'error' => null],
            ['id' => 'two', 'url' => 'https://two.example', 'label' => 'Two', 'success' => false, 'error' => 'offline'],
        ]);
        $tester = new CommandTester(new RefreshCommand($catalog));

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('One', $tester->getDisplay());
        self::assertStringContainsString('Two', $tester->getDisplay());
        self::assertStringContainsString('offline', $tester->getDisplay());
    }
}
