<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AccountMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $event->getMenu()
            ->addChild('tmonier_quote_requests', ['route' => 'tmonier_quote_request_shop_account_index'])
            ->setLabel('tmonier_quote_request.ui.my_quotes')
            ->setLabelAttribute('icon', 'tabler:file-dollar')
        ;
    }
}
