<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RepositoryRelease;

use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class RepositoryReleaseEntity extends Entity
{
    use EntityIdTrait;

    protected string $connectionId;
    protected string $providerReleaseId;
    protected string $providerAssetId;
    protected string $tag;
    protected string $assetName;
    protected string $version;
    protected ?string $releaseNotes = null;
    protected string $sha256;
    protected string $mediaId;
    protected string $productDownloadId;
    protected string $productDownloadVersionId;
    protected \DateTimeInterface $releasedAt;
    protected ?RepositoryConnectionEntity $connection = null;
    protected ?MediaEntity $media = null;
    protected ?ProductDownloadEntity $productDownload = null;

    public function getConnectionId(): string
    {
        return $this->connectionId;
    }

    public function setConnectionId(string $value): void
    {
        $this->connectionId = $value;
    }

    public function getProviderReleaseId(): string
    {
        return $this->providerReleaseId;
    }

    public function setProviderReleaseId(string $value): void
    {
        $this->providerReleaseId = $value;
    }

    public function getProviderAssetId(): string
    {
        return $this->providerAssetId;
    }

    public function setProviderAssetId(string $value): void
    {
        $this->providerAssetId = $value;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function setTag(string $value): void
    {
        $this->tag = $value;
    }

    public function getAssetName(): string
    {
        return $this->assetName;
    }

    public function setAssetName(string $value): void
    {
        $this->assetName = $value;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $value): void
    {
        $this->version = $value;
    }

    public function getReleaseNotes(): ?string
    {
        return $this->releaseNotes;
    }

    public function setReleaseNotes(?string $value): void
    {
        $this->releaseNotes = $value;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function setSha256(string $value): void
    {
        $this->sha256 = $value;
    }

    public function getMediaId(): string
    {
        return $this->mediaId;
    }

    public function setMediaId(string $value): void
    {
        $this->mediaId = $value;
    }

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

    public function getReleasedAt(): \DateTimeInterface
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(\DateTimeInterface $value): void
    {
        $this->releasedAt = $value;
    }

    public function getConnection(): ?RepositoryConnectionEntity
    {
        return $this->connection;
    }

    public function setConnection(?RepositoryConnectionEntity $value): void
    {
        $this->connection = $value;
    }

    public function getMedia(): ?MediaEntity
    {
        return $this->media;
    }

    public function setMedia(?MediaEntity $value): void
    {
        $this->media = $value;
    }

    public function getProductDownload(): ?ProductDownloadEntity
    {
        return $this->productDownload;
    }

    public function setProductDownload(?ProductDownloadEntity $value): void
    {
        $this->productDownload = $value;
    }
}
