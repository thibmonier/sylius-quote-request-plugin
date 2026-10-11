<?php

return [
    Tmonier\SyliusQuoteRequestPlugin\TmonierSyliusQuoteRequestPlugin::class => ['all' => true],
    // Lets tests run the Sylius graphs on the Winzou adapter (TEST_SYLIUS_STATE_MACHINE_ADAPTER).
    winzou\Bundle\StateMachineBundle\winzouStateMachineBundle::class => ['all' => true],
];
