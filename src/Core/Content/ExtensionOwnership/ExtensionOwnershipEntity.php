<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\ExtensionOwnership;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

final class ExtensionOwnershipEntity extends Entity
{
    protected string $technicalName;
    protected string $registryUrl;
    protected \DateTimeInterface $preparedAt;

    public function getTechnicalName(): string
    {
        return $this->technicalName;
    }

    public function setTechnicalName(string $value): void
    {
        $this->technicalName = $value;
    }

    public function getRegistryUrl(): string
    {
        return $this->registryUrl;
    }

    public function setRegistryUrl(string $value): void
    {
        $this->registryUrl = $value;
    }

    public function getPreparedAt(): \DateTimeInterface
    {
        return $this->preparedAt;
    }

    public function setPreparedAt(\DateTimeInterface $value): void
    {
        $this->preparedAt = $value;
    }
}
