<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\ExtensionMeshProduct;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

final class ExtensionMeshProductEntity extends Entity
{
    protected string $productId;
    protected string $productVersionId;
    protected bool $enabled;
    protected ?ProductEntity $product = null;

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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $value): void
    {
        $this->enabled = $value;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $value): void
    {
        $this->product = $value;
    }
}
