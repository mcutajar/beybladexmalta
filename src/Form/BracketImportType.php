<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\BracketImportData;
use App\Repository\SeasonRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
 * The season is a `ChoiceType` over slugs rather than an `EntityType`, because
 * the list now holds one option that is not a season: **No season — unranked
 * tournament**, at the top. That is option 4A of the proposal, and its cost was
 * accepted with eyes open — a select whose options are *n* seasons and one
 * not-a-season is easy to mis-tap on a phone at an event, and the mis-tap is
 * silent. The preview screen is the guard, which is why the notice and the
 * exact `--unranked` ledger line it shows are not optional.
 *
 * No new control and nothing that needs JavaScript: the form stays what it is,
 * fields and one button.
 *
 * @extends AbstractType<BracketImportData>
 */
final class BracketImportType extends AbstractType
{
    public function __construct(
        private readonly SeasonRepository $seasons,
    ) {
    }

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
            ->add('season', ChoiceType::class, [
                'choices' => $this->scopes(),
                'placeholder' => 'Select Season Context',
            ]);
    }

    /**
     * The option list: not-a-season first, then every season the league has.
     *
     * @return array<string, string> label => submitted value
     */
    private function scopes(): array
    {
        $scopes = ['No season — unranked tournament' => BracketImportData::UNRANKED];

        foreach ($this->seasons->ordered() as $season) {
            $scopes[$season->getName()] = $season->getSlug();
        }

        return $scopes;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BracketImportData::class,
        ]);
    }
}
