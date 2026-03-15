<?php declare(strict_types=1);

namespace NorilivingDruckfreigabe\Storefront\Subscriber;

use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AccountOrderSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AccountOrderPageLoadedEvent::class => 'onAccountOrderPageLoaded',
        ];
    }

    public function onAccountOrderPageLoaded(AccountOrderPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $orders = $page->getOrders();

        $ordersWithDruckfreigabe = [];

        foreach ($orders as $order) {
            $orderNumber = $order->getOrderNumber();
            $xmlPath = $_SERVER['DOCUMENT_ROOT'] . '/media/som/' . $orderNumber . '_XML.xml';

            if (file_exists($xmlPath)) {
                $ordersWithDruckfreigabe[] = $orderNumber;
            }
        }

        $page->addExtension('druckfreigabe', new DruckfreigabeExtension($ordersWithDruckfreigabe));
    }
}
