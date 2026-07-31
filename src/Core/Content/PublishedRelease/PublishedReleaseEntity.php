<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\PublishedRelease;

use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class PublishedReleaseEntity extends Entity
{
    use EntityIdTrait;

    protected string $productDownloadId;
    protected string $productDownloadVersionId;
    protected string $productId;
    protected string $productVersionId;
    protected string $mediaId;
    protected string $fingerprint;
    protected ?string $technicalName = null;
    protected ?string $version = null;
    protected ?string $shopwareConstraint = null;
    /** @var array<string, mixed>|null */
    protected ?array $metadata = null;
    protected ?string $sha256 = null;
    protected ?string $validationError = null;
    protected ?ProductDownloadEntity $productDownload = null;
    protected ?ProductEntity $product = null;
    protected ?MediaEntity $media = null;

    public function getProductDownloadId(): string
    {
        return $this->productDownloadId;
    }

    public function setProductDownloadId(string $value): void
    {
        $this->productDownloadId = $value;
    }

    public function getProductDownloadVersionId(): string
    {
        return $this->productDownloadVersionId;
    }

    public function setProductDownloadVersionId(string $value): void
    {
        $this->productDownloadVersionId = $value;
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

    public function getMediaId(): string
    {
        return $this->mediaId;
    }

    public function setMediaId(string $value): void
    {
        $this->mediaId = $value;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $value): void
    {
        $this->fingerprint = $value;
    }

    public function getTechnicalName(): ?string
    {
        return $this->technicalName;
    }

    public function setTechnicalName(?string $value): void
    {
        $this->technicalName = $value;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $value): void
    {
        $this->version = $value;
    }

    public function getShopwareConstraint(): ?string
    {
        return $this->shopwareConstraint;
    }

    public function setShopwareConstraint(?string $value): void
    {
        $this->shopwareConstraint = $value;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }
    /** @param array<string, mixed>|null $value */
    public function setMetadata(?array $value): void
    {
        $this->metadata = $value;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function setSha256(?string $value): void
    {
        $this->sha256 = $value;
    }

    public function getValidationError(): ?string
    {
        return $this->validationError;
    }

    public function setValidationError(?string $value): void
    {
        $this->validationError = $value;
    }

    public function getProductDownload(): ?ProductDownloadEntity
    {
        return $this->productDownload;
    }

    public function setProductDownload(?ProductDownloadEntity $value): void
    {
        $this->productDownload = $value;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $value): void
    {
        $this->product = $value;
    }

    public function getMedia(): ?MediaEntity
    {
        return $this->media;
    }

    public function setMedia(?MediaEntity $value): void
    {
        $this->media = $value;
    }
}
