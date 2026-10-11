<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $sales = $event->getMenu()->getChild('sales');
        if (null === $sales) {
            return;
        }

        $sales
            ->addChild('tmonier_quote_requests', ['route' => 'tmonier_quote_request_admin_quote_request_index'])
            ->setLabel('tmonier_quote_request.ui.quote_requests')
            ->setLabelAttribute('icon', 'tabler:file-dollar')
        ;
    }
}
