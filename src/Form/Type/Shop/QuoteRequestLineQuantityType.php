<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Tmonier\SyliusQuoteRequestPlugin\Basket\SessionQuoteBasket;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestLineInterface;

final class QuoteRequestLineQuantityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('quantity', IntegerType::class, [
            'label' => 'tmonier_quote_request.form.desired_quantity',
            'attr' => ['min' => 0, 'max' => SessionQuoteBasket::MAX_QUANTITY],
            'help' => 'tmonier_quote_request.form.zero_to_remove',
            'constraints' => [
                new NotBlank(),
                new Range(min: 0, max: SessionQuoteBasket::MAX_QUANTITY),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuoteRequestLineInterface::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_quote_request_line_quantity';
    }
}
