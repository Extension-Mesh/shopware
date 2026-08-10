<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Core\Content\RepositoryCredential;

use ExtensionMesh\Shopware\Core\Content\RepositoryConnection\RepositoryConnectionCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class RepositoryCredentialEntity extends Entity
{
    use EntityIdTrait;

    protected string $provider;
    protected string $apiBaseUrl;
    protected string $credentialCiphertext;
    protected string $credentialFingerprint;
    protected ?RepositoryConnectionCollection $connections = null;

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $value): void
    {
        $this->provider = $value;
    }

    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    public function setApiBaseUrl(string $value): void
    {
        $this->apiBaseUrl = $value;
    }

    public function getCredentialCiphertext(): string
    {
        return $this->credentialCiphertext;
    }

    public function setCredentialCiphertext(string $value): void
    {
        $this->credentialCiphertext = $value;
    }

    public function getCredentialFingerprint(): string
    {
        return $this->credentialFingerprint;
    }

    public function setCredentialFingerprint(string $value): void
    {
        $this->credentialFingerprint = $value;
    }

    public function getConnections(): ?RepositoryConnectionCollection
    {
        return $this->connections;
    }

    public function setConnections(RepositoryConnectionCollection $value): void
    {
        $this->connections = $value;
    }
}
