<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusQuoteRequestPlugin\Expiration\QuoteRequestExpirerInterface;

#[AsCommand(name: 'tmonier:quote-request:expire', description: 'Marks the quote proposals past their validity date as expired.')]
final class ExpireQuoteRequestsCommand extends Command
{
    public function __construct(private readonly QuoteRequestExpirerInterface $expirer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the proposals that would expire without changing them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $numbers = $this->expirer->expire($dryRun);
        if ([] !== $numbers && $io->isVerbose()) {
            $io->listing($numbers);
        }

        $io->success(sprintf($dryRun ? '%d quote proposal(s) would expire.' : '%d quote proposal(s) expired.', count($numbers)));

        return Command::SUCCESS;
    }
}
