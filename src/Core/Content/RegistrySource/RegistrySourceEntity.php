<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RegistrySource;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class RegistrySourceEntity extends Entity
{
    use EntityIdTrait;

    protected string $url;
    protected string $normalizedUrl;
    protected ?string $label = null;
    protected bool $enabled;
    protected ?string $credentialCiphertext = null;
    protected ?string $credentialFingerprint = null;
    protected ?string $cachedRegistry = null;
    protected ?\DateTimeInterface $lastRefreshedAt = null;
    protected ?string $lastError = null;

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getNormalizedUrl(): string
    {
        return $this->normalizedUrl;
    }

    public function setNormalizedUrl(string $normalizedUrl): void
    {
        $this->normalizedUrl = $normalizedUrl;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getCredentialCiphertext(): ?string
    {
        return $this->credentialCiphertext;
    }

    public function setCredentialCiphertext(?string $value): void
    {
        $this->credentialCiphertext = $value;
    }

    public function getCredentialFingerprint(): ?string
    {
        return $this->credentialFingerprint;
    }

    public function setCredentialFingerprint(?string $value): void
    {
        $this->credentialFingerprint = $value;
    }

    public function getCachedRegistry(): ?string
    {
        return $this->cachedRegistry;
    }

    public function setCachedRegistry(?string $value): void
    {
        $this->cachedRegistry = $value;
    }

    public function getLastRefreshedAt(): ?\DateTimeInterface
    {
        return $this->lastRefreshedAt;
    }

    public function setLastRefreshedAt(?\DateTimeInterface $value): void
    {
        $this->lastRefreshedAt = $value;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $value): void
    {
        $this->lastError = $value;
    }
}
