<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261012200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cart a quote request was made from (tmonier/sylius-quote-request-plugin).';
    }

    public function up(Schema $schema): void
    {
        $quote = $schema->getTable('tmonier_quote_request');
        $quote->addColumn('source_order_id', Types::INTEGER, ['notnull' => false]);
        $quote->addIndex(['source_order_id'], 'IDX_61926DE3CEBF5BEA');
        $quote->addForeignKeyConstraint('sylius_order', ['source_order_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_61926DE3CEBF5BEA');
    }

    public function down(Schema $schema): void
    {
        $quote = $schema->getTable('tmonier_quote_request');
        $quote->removeForeignKey('FK_61926DE3CEBF5BEA');
        $quote->dropIndex('IDX_61926DE3CEBF5BEA');
        $quote->dropColumn('source_order_id');
    }
}
