<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\RegisterPaymentData;
use App\Entity\Season;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RegisterPaymentType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder->add('season', EntityType::class, [
            'class' => Season::class,
            'choice_label' => 'name',
            'choice_value' => 'slug',
            'placeholder' => 'Select Target Season Context',
        ])
        ->add('playerName', TextType::class, [
            'attr' => [
                'placeholder' => 'Enter Blader Name',
                'autocomplete' => 'off',
            ],
        ])
        ->add('passphrase', PasswordType::class, [
            'attr' => [
                'placeholder' => 'Enter Admin Passphrase',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegisterPaymentData::class,
        ]);
    }
}
