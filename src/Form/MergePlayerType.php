<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\MergePlayerData;
use App\Entity\Player;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<MergePlayerData> */
final class MergePlayerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choice = ['class' => Player::class, 'choice_label' => 'name', 'placeholder' => 'Choose a blader'];
        $builder
            ->add('from', EntityType::class, $choice)
            ->add('into', EntityType::class, $choice)
            ->add('confirm', CheckboxType::class, ['required' => false])
            ->add('passphrase', PasswordType::class, ['empty_data' => '']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MergePlayerData::class]);
    }
}
