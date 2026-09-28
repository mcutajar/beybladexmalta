<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\PerformanceBladeData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<PerformanceBladeData> */
final class PerformanceBladeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'empty_data' => '',
                'attr' => ['placeholder' => 'Blade A'],
            ])
            ->add('blade', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'attr' => ['placeholder' => 'Phoenix Wing'],
            ])
            ->add('ratchet', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'attr' => ['placeholder' => '5-60'],
            ])
            ->add('bit', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'attr' => ['placeholder' => 'Point'],
            ])
            ->add('colour', ChoiceType::class, [
                'required' => false,
                'empty_data' => '',
                'placeholder' => 'No colour marker',
                'choices' => [
                    'Cyan' => 'cyan',
                    'Amber' => 'amber',
                    'Red' => 'red',
                    'Emerald' => 'emerald',
                    'Blue' => 'blue',
                    'Violet' => 'violet',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PerformanceBladeData::class]);
    }
}
