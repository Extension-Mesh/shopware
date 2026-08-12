<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service\Sync;

final readonly class LocalExtension
{
    public function __construct(
        public string $technicalName,
        public string $version,
        public bool $installed,
        public bool $active
    ) {
    }
}
