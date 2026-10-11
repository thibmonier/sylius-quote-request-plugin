<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuoteRequestPlugin\Form\Type\Shop;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Tmonier\SyliusQuoteRequestPlugin\Entity\QuoteMessageInterface;

final class QuoteMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', TextareaType::class, [
            'label' => 'tmonier_quote_request.form.your_message',
            'attr' => ['rows' => 4],
            'constraints' => [new NotBlank(), new Length(max: QuoteMessageInterface::MAX_LENGTH)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'tmonier_quote_request_message']);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_quote_request_message';
    }
}
