<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261011200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Volume discounts on quote request lines (tmonier/sylius-quote-request-plugin).';
    }

    public function up(Schema $schema): void
    {
        $line = $schema->getTable('tmonier_quote_request_line');
        $line->addColumn('discount_type', Types::STRING, ['length' => 16, 'notnull' => false]);
        $line->addColumn('discount_tiers', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $line = $schema->getTable('tmonier_quote_request_line');
        $line->dropColumn('discount_type');
        $line->dropColumn('discount_tiers');
    }
}
