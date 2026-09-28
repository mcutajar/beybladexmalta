<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\PerformanceMatchData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<PerformanceMatchData> */
final class PerformanceMatchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('opponent', TextType::class, ['required' => false, 'empty_data' => '', 'attr' => ['placeholder' => 'Optional opponent']])
            ->add('finalScore', TextType::class, ['required' => false, 'empty_data' => '', 'attr' => ['placeholder' => 'e.g. 7–5']])
            ->add('round', TextType::class, ['required' => false, 'empty_data' => '', 'attr' => ['placeholder' => 'e.g. Swiss 3 or Top 8']])
            ->add('notes', TextareaType::class, ['required' => false, 'empty_data' => '', 'attr' => ['rows' => 3, 'placeholder' => 'Optional notes']])
            ->add('playedAt', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PerformanceMatchData::class]);
    }
}
