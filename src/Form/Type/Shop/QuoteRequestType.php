<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteRequestInterface;

final class QuoteRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('company', TextType::class, [
                'label' => 'tmonier_quote_request.form.company',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'sylius.form.customer.first_name',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'sylius.form.customer.last_name',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'sylius.form.customer.email',
                'disabled' => !$options['email_editable'],
                'constraints' => [new NotBlank(), new Email(), new Length(max: 255)],
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'sylius.form.customer.phone_number',
                'required' => false,
                'constraints' => [new Length(max: 64)],
            ])
            ->add('vatNumber', TextType::class, [
                'label' => 'tmonier_quote_request.form.vat_number',
                'help' => 'tmonier_quote_request.form.vat_number_help',
                'required' => false,
                'constraints' => [new Length(max: 64)],
            ])
            ->add('lines', CollectionType::class, [
                'label' => false,
                'entry_type' => QuoteRequestLineQuantityType::class,
                'entry_options' => ['label' => false],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'tmonier_quote_request.form.message',
                'mapped' => false,
                'required' => false,
                'attr' => ['rows' => 5],
                'constraints' => [new Length(max: QuoteMessageInterface::MAX_LENGTH)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuoteRequestInterface::class,
            'email_editable' => true,
            'csrf_token_id' => 'tmonier_quote_request_new',
        ]);
        $resolver->setAllowedTypes('email_editable', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_quote_request';
    }
}
