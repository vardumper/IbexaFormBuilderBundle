<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FormSubmissionFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contentId', IntegerType::class, [
                'required' => false,
                'label' => 'Form Content ID',
                'attr' => ['placeholder' => 'All forms'],
            ])
            ->add('dateFrom', DateType::class, [
                'required' => false,
                'label' => 'Date from',
                'widget' => 'single_text',
            ])
            ->add('dateTo', DateType::class, [
                'required' => false,
                'label' => 'Date to',
                'widget' => 'single_text',
            ])
            ->add('filter', SubmitType::class, ['label' => 'Filter']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET',
            'csrf_protection' => false,
        ]);
    }
}
