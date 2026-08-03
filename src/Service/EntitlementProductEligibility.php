<?php declare(strict_types=1);

namespace ExtensionMesh\Shopware\Service;

use Shopware\Core\Framework\Context;

interface EntitlementProductEligibility
{
    /** @return list<string> */
    public function eligibleProductIds(Context $context): array;
}
