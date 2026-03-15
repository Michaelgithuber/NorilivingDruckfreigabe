<?php declare(strict_types=1);

namespace NorilivingDruckfreigabe\Storefront\Subscriber;

use Shopware\Core\Framework\Struct\Struct;

class DruckfreigabeExtension extends Struct
{
    /** @var string[] */
    protected array $orderNumbers;

    public function __construct(array $orderNumbers)
    {
        $this->orderNumbers = $orderNumbers;
    }

    public function getOrderNumbers(): array
    {
        return $this->orderNumbers;
    }

    public function hasOrder(string $orderNumber): bool
    {
        return in_array($orderNumber, $this->orderNumbers, true);
    }
}
