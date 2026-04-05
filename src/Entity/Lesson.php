<?php

namespace App\Entity;

use App\Repository\LessonRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LessonRepository::class)]
class Lesson
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lessons')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Урок должен быть привязан к курсу.')]
    private ?Course $course = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Укажите название урока.')]
    #[Assert\Length(
        max: 255,
        maxMessage: 'Название урока не должно быть длиннее {{ limit }} символов.',
    )]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Укажите контент урока.')]
    private ?string $lesson_content = null;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\NotNull(message: 'Укажите номер урока.')]
    #[Assert\Range(
        min: 1,
        max: 10000,
        notInRangeMessage: 'Номер урока должен быть в диапазоне от {{ min }} до {{ max }}.',
    )]
    private ?int $lesson_num = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCourse(): ?Course
    {
        return $this->course;
    }

    public function setCourse(?Course $course): static
    {
        $this->course = $course;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLessonContent(): ?string
    {
        return $this->lesson_content;
    }

    public function setLessonContent(string $lesson_content): static
    {
        $this->lesson_content = $lesson_content;

        return $this;
    }

    public function getLessonNum(): ?int
    {
        return $this->lesson_num;
    }

    public function setLessonNum(int $lesson_num): static
    {
        $this->lesson_num = $lesson_num;

        return $this;
    }
}
