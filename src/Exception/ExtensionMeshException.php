<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Exception;

final class ExtensionMeshException extends \RuntimeException
{
    public static function invalidRegistryUrl(string $reason): self
    {
        return new self('Invalid registry URL: ' . $reason);
    }

    public static function networkTargetRejected(string $host): self
    {
        return new self(\sprintf('Registry network target "%s" is not allowed.', $host));
    }

    public static function registryUnavailable(string $message): self
    {
        return new self('Registry could not be loaded: ' . $message);
    }

    public static function invalidRegistry(string $message): self
    {
        return new self('Invalid registry document: ' . $message);
    }

    public static function sourceNotFound(string $id): self
    {
        return new self(\sprintf('Registry source "%s" was not found.', $id));
    }

    public static function extensionNotFound(string $name): self
    {
        return new self(\sprintf('Extension "%s" was not found in this registry.', $name));
    }

    public static function artifactRejected(string $message): self
    {
        return new self('Extension artifact was rejected: ' . $message);
    }

    public static function invalidCredential(string $message): self
    {
        return new self('Invalid registry credential: ' . $message);
    }

    public static function accessDenied(string $message): self
    {
        return new self('Registry access denied: ' . $message);
    }

    public static function invalidEntitlement(string $message): self
    {
        return new self('Invalid entitlement: ' . $message);
    }

    public static function entitlementNotFound(string $id): self
    {
        return new self(\sprintf('Entitlement "%s" was not found.', $id));
    }

    public static function invalidRepository(string $message): self
    {
        return new self('Invalid repository connection: ' . $message);
    }

    public static function repositoryUnavailable(string $message): self
    {
        return new self('Repository could not be synchronized: ' . $message);
    }

    public static function repositoryNotFound(string $id): self
    {
        return new self(\sprintf('Repository connection "%s" was not found.', $id));
    }

}
