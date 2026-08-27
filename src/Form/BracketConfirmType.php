<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\BracketConfirmData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The confirm bar, and the two hidden facts underneath it.
 *
 * The knockout winner is a choice over the bladers this bracket produced
 * rather than a text field: the winner is already detected from the last match
 * of the cut, and the only reason to touch it is that a bracket was finished
 * out of order. Typing a name here would be a second way to invent one.
 *
 * The decisions and the reordering live on the same page but outside this
 * form's namespace, because there are as many of them as the bracket has
 * unreadable names. They are read straight off the request; there is nothing
 * to bind them to.
 *
 * @extends AbstractType<BracketConfirmData>
 */
final class BracketConfirmType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add('slug', HiddenType::class, ['empty_data' => ''])
            ->add('knockoutWinner', ChoiceType::class, [
                'choices' => $options['bladers'],
                'required' => false,
                'placeholder' => 'Nobody — no knockout bonus',
            ])
            ->add('passphrase', PasswordType::class, [
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'Enter Admin Passphrase',
                    'autocomplete' => 'off',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => BracketConfirmData::class,
                'bladers' => [],
            ])
            ->setAllowedTypes('bladers', 'array');
    }
}
