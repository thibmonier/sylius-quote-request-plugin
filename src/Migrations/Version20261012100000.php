<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optimistic lock version of the quote requests (tmonier/sylius-quote-request-plugin).';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('tmonier_quote_request')->addColumn('version', Types::INTEGER, ['default' => 1]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('tmonier_quote_request')->dropColumn('version');
    }
}
