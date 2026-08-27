<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\BracketImportData;
use App\Entity\Season;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The four fields in front of a fetch.
 *
 * No passphrase, deliberately. Fetching a bracket writes nothing — it is a
 * public page read over HTTP — and the preview it produces is the screen the
 * passphrase gates, so asking for it twice would be asking for it before there
 * was anything to authorise.
 *
 * The URL is a plain text field rather than a `UrlType`, because
 * `challonge.com/nppk0890` is how the link is pasted nine times out of ten and
 * `ChallongeUrl` already puts the scheme back.
 *
 * @extends AbstractType<BracketImportData>
 */
final class BracketImportType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add('challongeUrl', TextType::class, [
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'challonge.com/nppk0890',
                    'autocomplete' => 'off',
                    'inputmode' => 'url',
                ],
            ])
            ->add('title', TextType::class, [
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'e.g., Gamesplus 16-08',
                ],
            ])
            ->add('date', TextType::class, [
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'YYYY-MM-DD',
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('season', EntityType::class, [
                'class' => Season::class,
                'choice_label' => 'name',
                'choice_value' => 'slug',
                'placeholder' => 'Select Season Context',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BracketImportData::class,
        ]);
    }
}
