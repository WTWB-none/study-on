<?php

namespace App\Form;

use App\Entity\Course;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CourseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('symbolic_code')
            ->add('name')
            ->add('description')
            ->add('type', ChoiceType::class, [
                'label' => 'Тип курса',
                'placeholder' => 'Выберите тип курса',
                'required' => false,
                'choices' => [
                    'Бесплатный' => 'free',
                    'Аренда' => 'rent',
                    'Полный доступ' => 'buy',
                ],
            ])
            ->add('price', NumberType::class, [
                'label' => 'Стоимость',
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => [
                    'min' => '0',
                    'step' => '0.01',
                ],
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();

            if (!is_array($data) || ($data['type'] ?? null) !== 'free') {
                return;
            }

            $data['price'] = null;
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Course::class,
        ]);
    }
}
