<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\PerformanceTrackerData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<PerformanceTrackerData> */
final class PerformanceTrackerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tournamentName', TextType::class, [
                'empty_data' => '',
                'attr' => ['placeholder' => 'Gamesplus weekly tournament'],
            ])
            ->add('heldOn', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('playerName', TextType::class, [
                'empty_data' => '',
                'attr' => ['placeholder' => 'Your name'],
            ])
            ->add('location', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'attr' => ['placeholder' => 'Optional venue or event'],
            ])
            ->add('expectedMatches', IntegerType::class, [
                'attr' => ['min' => 1, 'max' => 99, 'inputmode' => 'numeric'],
            ])
            ->add('blades', CollectionType::class, [
                'entry_type' => PerformanceBladeType::class,
                'allow_add' => false,
                'allow_delete' => false,
                'by_reference' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PerformanceTrackerData::class]);
    }
}
