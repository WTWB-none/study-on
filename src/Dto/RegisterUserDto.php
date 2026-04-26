<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterUserDto
{
    #[Assert\NotBlank(message: 'Email не должен быть пустым.')]
    #[Assert\Email(message: 'Некорректный email.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Пароль не должен быть пустым.')]
    #[Assert\Length(min: 6, minMessage: 'Пароль должен быть не короче {{ limit }} символов.')]
    public string $password = '';
}
