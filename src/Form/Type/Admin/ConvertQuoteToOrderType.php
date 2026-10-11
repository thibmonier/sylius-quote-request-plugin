<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Form\Type\Admin;

use Sylius\Bundle\AddressingBundle\Form\Type\AddressType;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Manual conversion of a quote into a placed order: address, shipping and payment methods chosen
 * by the merchant (options `shipping_methods` and `payment_methods`: the enabled ones of the channel).
 */
final class ConvertQuoteToOrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('address', AddressType::class, [
                'label' => 'sylius.ui.address',
                'add_build_address_form_subscriber' => false,
            ])
            ->add('shippingMethod', ChoiceType::class, [
                'label' => 'sylius.ui.shipping_method',
                'choices' => $options['shipping_methods'],
                'choice_value' => static fn (?ShippingMethodInterface $method): string => (string) $method?->getCode(),
                'choice_label' => static fn (ShippingMethodInterface $method): string => (string) $method->getName(),
                'required' => false,
                'placeholder' => 'tmonier_quote_request.form.no_shipping',
            ])
            ->add('paymentMethod', ChoiceType::class, [
                'label' => 'sylius.ui.payment_method',
                'choices' => $options['payment_methods'],
                'choice_value' => static fn (?PaymentMethodInterface $method): string => (string) $method?->getCode(),
                'choice_label' => static fn (PaymentMethodInterface $method): string => (string) $method->getName(),
                'constraints' => [new NotBlank()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['shipping_methods', 'payment_methods']);
        $resolver->setAllowedTypes('shipping_methods', 'array');
        $resolver->setAllowedTypes('payment_methods', 'array');
        $resolver->setDefaults(['csrf_token_id' => 'tmonier_quote_request_admin_convert']);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_quote_request_admin_convert';
    }
}
