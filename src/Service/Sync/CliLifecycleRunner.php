<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final readonly class CliLifecycleRunner implements LifecycleRunner
{
    public function __construct(private string $projectDir)
    {
    }

    public function run(string $action, string $technicalName, bool $activate): void
    {
        $command = [
            \PHP_BINARY,
            Path::join($this->projectDir, 'bin/console'),
            'extension-mesh:sync:lifecycle',
            $action,
            $technicalName,
            '--no-ansi',
            '--no-interaction',
        ];
        if ($activate) {
            $command[] = '--activate';
        }

        $process = new Process($command, $this->projectDir, timeout: 900);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
