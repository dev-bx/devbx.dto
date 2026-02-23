<?php

namespace Tests\Local\Lib\DTO;

use PHPUnit\Framework\TestCase;
use Local\Lib\DTO\BaseDTO;
use Local\Lib\DTO\Attributes\Validation\Max;
use Local\Lib\DTO\Attributes\Validation\Email;
use Local\Lib\DTO\Attributes\Validation\Regex;
use Local\Lib\DTO\Attributes\Validation\InArray;

// --- ТЕСТОВАЯ ФИКСТУРА ---
class UserRegistrationDTO extends BaseDTO
{
    #[Email]
    public string $email;

    #[Max(10)]
    public string $username; // Макс 10 символов

    #[Regex('/^\+7\d{10}$/', 'Phone must be in format +7XXXXXXXXXX')]
    public string $phone;

    #[InArray(['admin', 'editor', 'viewer'])]
    public string $role;

    #[Max(3)]
    public array $tags; // Не больше 3 тегов
}

// --- ТЕСТЫ ---
class StandardValidatorsTest extends TestCase
{
    public function testValidationSuccess()
    {
        $dto = UserRegistrationDTO::fromArray([
            'email' => 'valid@test.ru',
            'username' => 'JohnDoe',
            'phone' => '+79991234567',
            'role' => 'editor',
            'tags' => ['php', 'vue']
        ]);

        $result = $dto->validate();
        $this->assertTrue($result->isSuccess(), 'Valid DTO should pass validation');
    }

    public function testValidationFailsWithCorrectCodes()
    {
        $dto = UserRegistrationDTO::fromArray([
            'email' => 'invalid-email', // Провал Email
            'username' => 'WayTooLongUsernameHere', // Провал Max length
            'phone' => '89991234567', // Провал Regex (без +7)
            'role' => 'superadmin', // Провал InArray
            'tags' => ['t1', 't2', 't3', 't4'] // Провал Max items
        ]);

        $result = $dto->validate();

        $this->assertFalse($result->isSuccess());
        $errors = $result->getErrors();
        $this->assertCount(5, $errors);

        // Формируем ассоциативный массив для удобства проверки {propName => code}
        $errorCodes = [];
        foreach ($errors as $error) {
            $errorCodes[$error->getCode()] = $error->getMessage();
        }

        $this->assertArrayHasKey('email.VALIDATION_EMAIL', $errorCodes);
        $this->assertArrayHasKey('username.VALIDATION_MAX_LENGTH', $errorCodes);
        $this->assertArrayHasKey('phone.VALIDATION_REGEX', $errorCodes);
        $this->assertEquals('Phone must be in format +7XXXXXXXXXX', $errorCodes['phone.VALIDATION_REGEX']);
        $this->assertArrayHasKey('role.VALIDATION_IN_ARRAY', $errorCodes);
        $this->assertArrayHasKey('tags.VALIDATION_MAX_ITEMS', $errorCodes);
    }
}