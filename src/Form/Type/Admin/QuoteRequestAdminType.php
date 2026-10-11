<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin;

use Sylius\Bundle\AdminBundle\Form\Type\ProductVariantAutocompleteType;
use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Range;
use Tmonier\SyliusQuoteRequestPlugin\Basket\SessionQuoteBasket;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

/**
 * Merchant edition of a quote: lines (quantity, negotiated price, removal), one product to add,
 * global discount, validity, internal note and a message for the customer.
 */
final class QuoteRequestAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $currency */
        $currency = $options['currency'];

        $builder
            ->add('lines', CollectionType::class, [
                'label' => false,
                'entry_type' => QuoteRequestLineAdminType::class,
                'entry_options' => ['label' => false, 'currency' => $currency],
            ])
            ->add('newVariant', ProductVariantAutocompleteType::class, [
                'label' => 'tmonier_quote_request.form.add_product',
                'mapped' => false,
                'required' => false,
            ])
            ->add('newQuantity', IntegerType::class, [
                'label' => 'sylius.ui.quantity',
                'mapped' => false,
                'required' => false,
                'data' => 1,
                'attr' => ['min' => 1, 'max' => SessionQuoteBasket::MAX_QUANTITY],
                'constraints' => [new Range(min: 1, max: SessionQuoteBasket::MAX_QUANTITY)],
            ])
            ->add('discountAmount', MoneyType::class, [
                'label' => 'tmonier_quote_request.form.discount_amount',
                'currency' => $currency,
                'required' => false,
                'constraints' => [new Range(min: 0)],
            ])
            ->add('discountPercentage', NumberType::class, [
                'label' => 'tmonier_quote_request.form.discount_percentage',
                'help' => 'tmonier_quote_request.form.discount_help',
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'max' => 100, 'step' => '0.01'],
                'constraints' => [new Range(min: 0, max: 100)],
            ])
            ->add('validUntil', DateType::class, [
                'label' => 'tmonier_quote_request.form.valid_until',
                'help' => 'tmonier_quote_request.form.valid_until_help',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('internalNote', TextareaType::class, [
                'label' => 'tmonier_quote_request.form.internal_note',
                'help' => 'tmonier_quote_request.form.internal_note_help',
                'required' => false,
                'constraints' => [new Length(max: QuoteMessageInterface::MAX_LENGTH)],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'tmonier_quote_request.form.message_to_customer',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(max: QuoteMessageInterface::MAX_LENGTH)],
            ])
            ->add('save', SubmitType::class, ['label' => 'sylius.ui.save_changes'])
            ->add('propose', SubmitType::class, ['label' => 'tmonier_quote_request.ui.save_and_send_proposal'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuoteRequestInterface::class,
            'currency' => 'USD',
            'csrf_token_id' => 'tmonier_quote_request_admin_edit',
        ]);
        $resolver->setAllowedTypes('currency', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_quote_request_admin';
    }
}
