<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\BracketImportData;
use App\Dto\ImportTournamentData;
use App\Repository\SeasonRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The textarea path, unchanged since the league's first import.
 *
 * `empty_data` is on every non-nullable field on purpose. Symfony hands an
 * untouched text control back as null, and the DTO types these as `string` —
 * so an empty title or an empty placement list used to reach the property
 * accessor as null and 500 before any of the controller's own validation ran.
 * Since the list may now be short rather than exactly ten, the message that
 * says it names nobody has to be reachable.
 *
 * The season list carries the unranked option the bracket importer has, and
 * the controller refuses it by name. This path stays ranked-only: without a
 * snapshot it retains no match history, so an unranked placement list would
 * create a tournament with nothing in it worth keeping. Offering the option
 * and saying no is kinder than hiding it from one of two forms on one page —
 * and it is the whole of the work this importer gets, because #61 retires it.
 *
 * @extends AbstractType<ImportTournamentData>
 */
final class ImportTournamentType extends AbstractType
{
    public function __construct(
        private readonly SeasonRepository $seasons,
    ) {
    }

    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder->add('title', TextType::class, [
            'empty_data' => '',
            'attr' => [
                'placeholder' => 'e.g., Stage 1 Ranked',
            ],
        ])
        ->add('date', TextType::class, [
            'empty_data' => '',
            'attr' => [
                'placeholder' => 'YYYY-MM-DD',
            ],
        ])
        ->add('season', ChoiceType::class, [
            'choices' => $this->scopes(),
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
            'empty_data' => '',
            'attr' => [
                'placeholder' => "Blader1\nBlader2\nBlader3\nBlader4\nBlader5\nBlader6\nBlader7\nBlader8\nBlader9\nBlader10",
                'rows' => 11,
                'class' => 'font-mono',
            ],
        ])
        ->add('passphrase', PasswordType::class, [
            'empty_data' => '',
            'attr' => [
                'placeholder' => 'Enter Admin Passphrase',
            ],
        ]);
    }

    /**
     * The same option list the bracket importer shows, so the two forms on
     * this page cannot offer different answers to the same question.
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
            'data_class' => ImportTournamentData::class,
        ]);
    }
}
