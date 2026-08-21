<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ImportTournamentData;
use App\Entity\Season;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ImportTournamentData>
 */
final class ImportTournamentType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder->add('title', TextType::class, [
            'attr' => [
                'placeholder' => 'e.g., Stage 1 Ranked',
            ],
        ])
        ->add('date', TextType::class, [
            'attr' => [
                'placeholder' => 'YYYY-MM-DD',
            ],
        ])
        ->add('season', EntityType::class, [
            'class' => Season::class,
            'choice_label' => 'name',
            'choice_value' => 'slug',
            'placeholder' => 'Select Season Context',
        ])
        ->add('challongeUrl', UrlType::class, [
            'required' => false,
            'attr' => [
                'placeholder' => 'Challonge Link (Optional)',
            ],
        ])
        ->add('knockoutWinner', TextType::class, [
            'required' => false,
            'attr' => [
                'placeholder' => 'Knockout Winner Name (Optional)',
                'autocomplete' => 'off',
            ],
        ])
        ->add('playerList', TextareaType::class, [
            'attr' => [
                'placeholder' => "Blader1\nBlader2\nBlader3\nBlader4\nBlader5\nBlader6\nBlader7\nBlader8\nBlader9\nBlader10",
                'rows' => 11,
                'class' => 'font-mono',
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
            'data_class' => ImportTournamentData::class,
        ]);
    }
}
