<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin;

use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Tmonier\SyliusQuoteRequestPlugin\Basket\SessionQuoteBasket;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;

final class QuoteRequestLineAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('quantity', IntegerType::class, [
                'label' => 'sylius.ui.quantity',
                'attr' => ['min' => 1, 'max' => SessionQuoteBasket::MAX_QUANTITY],
                'constraints' => [new NotBlank(), new Range(min: 1, max: SessionQuoteBasket::MAX_QUANTITY)],
            ])
            ->add('unitPrice', MoneyType::class, [
                'label' => 'tmonier_quote_request.form.negotiated_unit_price',
                'currency' => $options['currency'],
                'required' => false,
                'help' => 'tmonier_quote_request.form.empty_for_catalog_price',
                'constraints' => [new Range(min: 0)],
            ])
            ->add('remove', CheckboxType::class, [
                'label' => 'sylius.ui.delete',
                'mapped' => false,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuoteRequestLineInterface::class,
            'currency' => 'USD',
        ]);
        $resolver->setAllowedTypes('currency', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_quote_request_admin_line';
    }
}
