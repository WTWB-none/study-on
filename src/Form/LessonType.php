<?php

namespace App\Form;

use App\Entity\Course;
use App\Entity\Lesson;
use App\Repository\CourseRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LessonType extends AbstractType
{
    public function __construct(
        private readonly CourseRepository $courseRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name')
            ->add('lesson_content', TextareaType::class)
            ->add('lesson_num')
            ->add('course', HiddenType::class)
        ;

        $builder->get('course')->addModelTransformer(new CallbackTransformer(
            fn (?Course $course): string => (string) ($course?->getId() ?? ''),
            fn (?string $courseId): ?Course => $courseId ? $this->courseRepository->find($courseId) : null,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Lesson::class,
        ]);
    }
}
