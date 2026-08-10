<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RepositoryConnection;

use ExtensionMesh\Shopware\Core\Content\RepositoryCredential\RepositoryCredentialEntity;
use ExtensionMesh\Shopware\Core\Content\RepositoryRelease\RepositoryReleaseCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class RepositoryConnectionEntity extends Entity
{
    use EntityIdTrait;

    protected string $provider;
    protected string $repository;
    protected string $apiBaseUrl;
    protected ?string $webUrl = null;
    protected ?string $defaultBranch = null;
    protected bool $repositoryPrivate;
    protected ?string $credentialId = null;
    protected ?string $productId = null;
    protected ?string $productVersionId = null;
    protected ?string $technicalName = null;
    protected ?string $configPath = null;
    protected ?string $onboardingMode = null;
    protected string $onboardingStatus;
    protected ?string $onboardingStage = null;
    protected bool $enabled;
    protected ?\DateTimeInterface $lastSyncedAt = null;
    protected ?string $lastError = null;
    protected ?RepositoryCredentialEntity $credential = null;
    protected ?ProductEntity $product = null;
    protected ?RepositoryReleaseCollection $releases = null;

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $value): void
    {
        $this->provider = $value;
    }

    public function getRepository(): string
    {
        return $this->repository;
    }

    public function setRepository(string $value): void
    {
        $this->repository = $value;
    }

    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    public function setApiBaseUrl(string $value): void
    {
        $this->apiBaseUrl = $value;
    }

    public function getWebUrl(): ?string
    {
        return $this->webUrl;
    }

    public function setWebUrl(?string $value): void
    {
        $this->webUrl = $value;
    }

    public function getDefaultBranch(): ?string
    {
        return $this->defaultBranch;
    }

    public function setDefaultBranch(?string $value): void
    {
        $this->defaultBranch = $value;
    }

    public function isRepositoryPrivate(): bool
    {
        return $this->repositoryPrivate;
    }

    public function setRepositoryPrivate(bool $value): void
    {
        $this->repositoryPrivate = $value;
    }

    public function getCredentialId(): ?string
    {
        return $this->credentialId;
    }

    public function setCredentialId(?string $value): void
    {
        $this->credentialId = $value;
    }

    public function getCredential(): ?RepositoryCredentialEntity
    {
        return $this->credential;
    }

    public function setCredential(?RepositoryCredentialEntity $value): void
    {
        $this->credential = $value;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function setProductId(?string $value): void
    {
        $this->productId = $value;
    }

    public function getProductVersionId(): ?string
    {
        return $this->productVersionId;
    }

    public function setProductVersionId(?string $value): void
    {
        $this->productVersionId = $value;
    }

    public function getTechnicalName(): ?string
    {
        return $this->technicalName;
    }

    public function setTechnicalName(?string $value): void
    {
        $this->technicalName = $value;
    }

    public function getConfigPath(): ?string
    {
        return $this->configPath;
    }

    public function setConfigPath(?string $value): void
    {
        $this->configPath = $value;
    }

    public function getOnboardingMode(): ?string
    {
        return $this->onboardingMode;
    }

    public function setOnboardingMode(?string $value): void
    {
        $this->onboardingMode = $value;
    }

    public function getOnboardingStatus(): string
    {
        return $this->onboardingStatus;
    }

    public function setOnboardingStatus(string $value): void
    {
        $this->onboardingStatus = $value;
    }

    public function getOnboardingStage(): ?string
    {
        return $this->onboardingStage;
    }

    public function setOnboardingStage(?string $value): void
    {
        $this->onboardingStage = $value;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $value): void
    {
        $this->enabled = $value;
    }

    public function getLastSyncedAt(): ?\DateTimeInterface
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeInterface $value): void
    {
        $this->lastSyncedAt = $value;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $value): void
    {
        $this->lastError = $value;
    }

    public function getProduct(): ?ProductEntity
    {
        return $this->product;
    }

    public function setProduct(?ProductEntity $value): void
    {
        $this->product = $value;
    }

    public function getReleases(): ?RepositoryReleaseCollection
    {
        return $this->releases;
    }

    public function setReleases(RepositoryReleaseCollection $value): void
    {
        $this->releases = $value;
    }
}
