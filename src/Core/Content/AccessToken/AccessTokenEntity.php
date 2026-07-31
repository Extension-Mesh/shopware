<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\AccessToken;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

final class AccessTokenEntity extends Entity
{
    use EntityIdTrait;

    protected string $customerId;
    protected string $salesChannelId;
    protected ?string $label = null;
    protected ?\DateTimeInterface $lastUsedAt = null;
    protected ?\DateTimeInterface $revokedAt = null;
    protected ?CustomerEntity $customer = null;
    protected ?SalesChannelEntity $salesChannel = null;

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function setCustomerId(string $value): void
    {
        $this->customerId = $value;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $value): void
    {
        $this->salesChannelId = $value;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $value): void
    {
        $this->label = $value;
    }

    public function getLastUsedAt(): ?\DateTimeInterface
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeInterface $value): void
    {
        $this->lastUsedAt = $value;
    }

    public function getRevokedAt(): ?\DateTimeInterface
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeInterface $value): void
    {
        $this->revokedAt = $value;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $value): void
    {
        $this->customer = $value;
    }

    public function getSalesChannel(): ?SalesChannelEntity
    {
        return $this->salesChannel;
    }

    public function setSalesChannel(?SalesChannelEntity $value): void
    {
        $this->salesChannel = $value;
    }
}
