<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Subscriber;

use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementCollection;
use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementDefinition;
use ExtensionMesh\Shopware\Core\Content\Entitlement\EntitlementEntity;
use ExtensionMesh\Shopware\Service\EntitlementInvariantValidator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final class EntitlementWriteValidatorSubscriber implements EventSubscriberInterface
{
    private const IDENTITY_FIELDS = ['customer_id', 'product_id', 'sales_channel_id', 'order_id'];

    public function __construct(
        /** @var EntityRepository<EntitlementCollection> */
        private readonly EntityRepository $entitlements,
        private readonly EntitlementInvariantValidator $validator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'validate'];
    }

    public function validate(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommands() as $command) {
            if (
                $command->getEntityName() !== EntitlementDefinition::ENTITY_NAME
                || (!$command instanceof InsertCommand && !$command instanceof UpdateCommand)
            ) {
                continue;
            }
            $payload = $command->getPayload();
            if ($command instanceof UpdateCommand && !\array_intersect(self::IDENTITY_FIELDS, \array_keys($payload))) {
                continue;
            }

            $existing = $command instanceof UpdateCommand
                ? $this->existing($command->getDecodedPrimaryKey()['id'] ?? null, $event)
                : null;
            $customerId = $this->id($payload, 'customer_id', $existing?->getCustomerId());
            $productId = $this->id($payload, 'product_id', $existing?->getProductId());
            $salesChannelId = $this->id($payload, 'sales_channel_id', $existing?->getSalesChannelId());
            $orderId = $this->id($payload, 'order_id', $existing?->getOrderId());
            $violation = $this->validator->violation(
                $customerId ?? '',
                $productId ?? '',
                $salesChannelId ?? '',
                $orderId,
                $event->getContext()
            );
            if ($violation === null) {
                continue;
            }

            $violations = new ConstraintViolationList([new ConstraintViolation(
                $violation,
                null,
                [],
                null,
                '/productId',
                $productId,
                null,
                'EXTENSION_MESH__INVALID_ENTITLEMENT'
            )]);
            $event->getExceptions()->add(new WriteConstraintViolationException($violations, $command->getPath()));
        }
    }

    private function existing(?string $id, PreWriteValidationEvent $event): ?EntitlementEntity
    {
        if ($id === null || !Uuid::isValid($id)) {
            return null;
        }
        $entity = $this->entitlements->search(new Criteria([$id]), $event->getContext())->first();

        return $entity instanceof EntitlementEntity ? $entity : null;
    }

    /** @param array<string, mixed> $payload */
    private function id(array $payload, string $field, ?string $fallback): ?string
    {
        if (!\array_key_exists($field, $payload)) {
            return $fallback;
        }
        $value = $payload[$field];
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            return '';
        }
        if (Uuid::isValid($value)) {
            return $value;
        }

        return \strlen($value) === 16 ? Uuid::fromBytesToHex($value) : '';
    }
}
