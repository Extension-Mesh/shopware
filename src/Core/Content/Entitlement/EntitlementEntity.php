<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\Entitlement;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

final class EntitlementEntity extends Entity
{
    use EntityIdTrait;

    protected string $customerId;
    protected string $productId;
    protected string $productVersionId;
    protected string $salesChannelId;
    protected ?string $orderId = null;
    protected ?string $orderVersionId = null;
    protected bool $enabled;
    protected ?\DateTimeInterface $validUntil = null;
    protected ?CustomerEntity $customer = null;
    protected ?ProductEntity $product = null;
    protected ?SalesChannelEntity $salesChannel = null;
    protected ?OrderEntity $order = null;

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function setCustomerId(string $value): void
    {
        $this->customerId = $value;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function setProductId(string $value): void
    {
        $this->productId = $value;
    }

    public function getProductVersionId(): string
    {
        return $this->productVersionId;
    }

    public function setProductVersionId(string $value): void
    {
        $this->productVersionId = $value;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $value): void
    {
        $this->salesChannelId = $value;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $value): void
    {
        $this->orderId = $value;
    }

    public function getOrderVersionId(): ?string
    {
        return $this->orderVersionId;
    }

    public function setOrderVersionId(?string $value): void
    {
        $this->orderVersionId = $value;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $value): void
    {
        $this->enabled = $value;
    }

    public function getValidUntil(): ?\DateTimeInterface
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeInterface $value): void
    {
        $this->validUntil = $value;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $value): void
    {
        $this->customer = $value;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $value): void
    {
        $this->product = $value;
    }

    public function getSalesChannel(): ?SalesChannelEntity
    {
        return $this->salesChannel;
    }

    public function setSalesChannel(?SalesChannelEntity $value): void
    {
        $this->salesChannel = $value;
    }

    public function getOrder(): ?OrderEntity
    {
        return $this->order;
    }

    public function setOrder(?OrderEntity $value): void
    {
        $this->order = $value;
    }
}
