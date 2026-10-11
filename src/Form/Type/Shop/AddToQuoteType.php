<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Tmonier\SyliusQuoteRequestPlugin\Basket\SessionQuoteBasket;

/**
 * "Add to quote request" form of the product page: the variants are the enabled ones of the
 * product sold in the channel (option `variants`).
 */
final class AddToQuoteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<ProductVariantInterface> $variants */
        $variants = $options['variants'];

        $builder
            ->add('variant', ChoiceType::class, [
                'label' => 'sylius.ui.variant',
                'choices' => $variants,
                'choice_value' => static fn (?ProductVariantInterface $variant): string => is_scalar($variant?->getId()) ? (string) $variant->getId() : '',
                'choice_label' => static fn (ProductVariantInterface $variant): string => (string) ($variant->getName() ?? $variant->getDescriptor()),
                'data' => $variants[0] ?? null,
                'constraints' => [new NotBlank()],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'sylius.ui.quantity',
                'data' => 1,
                'attr' => ['min' => 1, 'max' => SessionQuoteBasket::MAX_QUANTITY],
                'constraints' => [new NotBlank(), new Range(min: 1, max: SessionQuoteBasket::MAX_QUANTITY)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('variants');
        $resolver->setAllowedTypes('variants', 'array');
        $resolver->setDefaults(['csrf_token_id' => 'tmonier_quote_request_add']);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_quote_request_add';
    }
}
