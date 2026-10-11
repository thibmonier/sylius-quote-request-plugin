<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261011100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the quote request tables (tmonier/sylius-quote-request-plugin).';
    }

    public function up(Schema $schema): void
    {
        $quote = $schema->createTable('tmonier_quote_request');
        $quote->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $quote->addColumn('channel_id', Types::INTEGER);
        $quote->addColumn('customer_id', Types::INTEGER, ['notnull' => false]);
        $quote->addColumn('order_id', Types::INTEGER, ['notnull' => false]);
        $quote->addColumn('number', Types::STRING, ['length' => 32, 'notnull' => false]);
        $quote->addColumn('status', Types::STRING, ['length' => 32]);
        $quote->addColumn('token', Types::STRING, ['length' => 64]);
        $quote->addColumn('locale_code', Types::STRING, ['length' => 12]);
        $quote->addColumn('currency_code', Types::STRING, ['length' => 3]);
        $quote->addColumn('email', Types::STRING, ['length' => 255]);
        $quote->addColumn('company', Types::STRING, ['length' => 255, 'notnull' => false]);
        $quote->addColumn('first_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $quote->addColumn('last_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $quote->addColumn('phone_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $quote->addColumn('vat_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $quote->addColumn('discount_amount', Types::INTEGER, ['notnull' => false]);
        $quote->addColumn('discount_percentage', Types::FLOAT, ['notnull' => false]);
        $quote->addColumn('valid_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $quote->addColumn('internal_note', Types::TEXT, ['notnull' => false]);
        $quote->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $quote->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $quote->addColumn('proposed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $quote->addColumn('accepted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $quote->addColumn('ordered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $quote->setPrimaryKey(['id']);
        $quote->addUniqueIndex(['number'], 'UNIQ_61926DE396901F54');
        $quote->addUniqueIndex(['token'], 'UNIQ_61926DE35F37A13B');
        $quote->addIndex(['channel_id'], 'IDX_61926DE372F5A1AA');
        $quote->addIndex(['customer_id'], 'IDX_61926DE39395C3F3');
        $quote->addIndex(['order_id'], 'IDX_61926DE38D9F6D38');
        $quote->addIndex(['status', 'valid_until'], 'tmonier_quote_request_status_idx');
        $quote->addIndex(['created_at'], 'tmonier_quote_request_created_at_idx');
        $quote->addForeignKeyConstraint('sylius_channel', ['channel_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_61926DE372F5A1AA');
        $quote->addForeignKeyConstraint('sylius_customer', ['customer_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_61926DE39395C3F3');
        $quote->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_61926DE38D9F6D38');

        $line = $schema->createTable('tmonier_quote_request_line');
        $line->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $line->addColumn('quote_request_id', Types::INTEGER);
        $line->addColumn('variant_id', Types::INTEGER, ['notnull' => false]);
        $line->addColumn('product_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $line->addColumn('variant_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $line->addColumn('variant_code', Types::STRING, ['length' => 255, 'notnull' => false]);
        $line->addColumn('quantity', Types::INTEGER);
        $line->addColumn('requested_quantity', Types::INTEGER, ['notnull' => false]);
        $line->addColumn('catalog_unit_price', Types::INTEGER);
        $line->addColumn('unit_price', Types::INTEGER, ['notnull' => false]);
        $line->setPrimaryKey(['id']);
        $line->addIndex(['quote_request_id'], 'IDX_A8786044F229C21E');
        $line->addIndex(['variant_id'], 'IDX_A87860443B69A9AF');
        $line->addForeignKeyConstraint('tmonier_quote_request', ['quote_request_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_A8786044F229C21E');
        $line->addForeignKeyConstraint('sylius_product_variant', ['variant_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_A87860443B69A9AF');

        $message = $schema->createTable('tmonier_quote_request_message');
        $message->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $message->addColumn('quote_request_id', Types::INTEGER);
        $message->addColumn('author_type', Types::STRING, ['length' => 16]);
        $message->addColumn('author_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $message->addColumn('body', Types::TEXT);
        $message->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $message->setPrimaryKey(['id']);
        $message->addIndex(['quote_request_id'], 'IDX_D1373710F229C21E');
        $message->addForeignKeyConstraint('tmonier_quote_request', ['quote_request_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_D1373710F229C21E');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_quote_request_message');
        $schema->dropTable('tmonier_quote_request_line');
        $schema->dropTable('tmonier_quote_request');
    }
}
