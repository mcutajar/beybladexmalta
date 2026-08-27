<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\BracketConfirmData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The confirm bar, and the bracket it belongs to.
 *
 * Two fields, because everything else on this screen is either derived from
 * the snapshot or answered per name. The decisions live on the same page but
 * outside this form's namespace — there are as many of them as the bracket has
 * unreadable names, so they are read straight off the request and there is
 * nothing to bind them to.
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
        $resolver->setDefaults([
            'data_class' => BracketConfirmData::class,
        ]);
    }
}
