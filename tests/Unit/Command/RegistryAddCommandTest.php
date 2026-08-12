<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Test\Unit\Command;

use ExtensionMesh\Shopware\Command\RegistryAddCommand;
use ExtensionMesh\Shopware\Service\CatalogManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RegistryAddCommandTest extends TestCase
{
    /** @return iterable<string, array{array{id: string, created: bool, credentialUpdated: bool}, string}> */
    public static function results(): iterable
    {
        yield 'created' => [
            ['id' => 'source-id', 'created' => true, 'credentialUpdated' => false],
            'Registry added',
        ];
        yield 'duplicate' => [
            ['id' => 'source-id', 'created' => false, 'credentialUpdated' => false],
            'no changes made',
        ];
        yield 'credential update' => [
            ['id' => 'source-id', 'created' => false, 'credentialUpdated' => true],
            'credential updated',
        ];
    }

    /** @param array{id: string, created: bool, credentialUpdated: bool} $result */
    #[DataProvider('results')]
    public function testItAddsRegistriesIdempotently(array $result, string $message): void
    {
        $catalog = $this->createMock(CatalogManager::class);
        $catalog->expects(self::once())
            ->method('addSourceIdempotently')
            ->with('https://registry.example', 'secret')
            ->willReturn($result);
        $tester = new CommandTester(new RegistryAddCommand($catalog));

        $status = $tester->execute(['url' => 'https://registry.example', '--token' => 'secret']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertStringNotContainsString('secret', $tester->getDisplay());
    }

    public function testFailureReturnsNonZeroWithoutPrintingCredential(): void
    {
        $catalog = $this->createMock(CatalogManager::class);
        $catalog->method('addSourceIdempotently')->willThrowException(new \RuntimeException('network failed'));
        $tester = new CommandTester(new RegistryAddCommand($catalog));

        $status = $tester->execute(['url' => 'https://registry.example', '--token' => 'top-secret']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringNotContainsString('top-secret', $tester->getDisplay());
    }
}
