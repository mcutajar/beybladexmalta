<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\PerformanceResultValue;
use App\Dto\ScoreEntryData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ScoreEntryData> */
final class ScoreEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach (PerformanceResultValue::cases() as $value) {
            $choices[$value->label()] = $value->value;
        }

        $builder->add('result', ChoiceType::class, ['choices' => $choices]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ScoreEntryData::class]);
    }
}
