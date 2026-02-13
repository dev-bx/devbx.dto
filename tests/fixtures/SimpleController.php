<?php

namespace Tests\Local\Lib\DTO\Fixtures;

use Local\Lib\DTO\Http\AbstractController;
use Local\Lib\DTO\BaseDTO;
use Local\Lib\DTO\Attributes\Mapping\Query;
use Local\Lib\DTO\Attributes\Mapping\Body;
use Local\Lib\DTO\Attributes\Validation\Min;

// 1. Наш защищенный Readonly DTO со всеми фичами
readonly class UpdateUserRequestDTO extends BaseDTO
{
    public function __construct(
        // Берем ID строго из GET-параметров (например, ?user_id=5)
        #[Query('user_id')]
        #[Min(1)]
        public int $id,

        // Берем email строго из тела POST-запроса
        #[Body]
        public string $email,

        // Если атрибута нет, контроллер будет искать в Body, затем в Query
        public ?string $status = null
    ) {}
}

// 2. Тестовый (Simple) Контроллер
class SimpleController extends AbstractController
{
    public function handleRequest(array $getParams, array $postParams): UpdateUserRequestDTO
    {
        // Вызываем абстрактный метод резолва
        /** @var UpdateUserRequestDTO $dto */
        $dto = $this->resolveDto(UpdateUserRequestDTO::class, $getParams, $postParams);

        // Автоматически запускаем декларативную валидацию (Идея 1)
        $validation = $dto->validate();
        if (!$validation->isSuccess()) {
            // В реальном приложении здесь будет выброс исключения ValidationException
            throw new \RuntimeException("Validation failed: " . $validation->getErrors()[0]->getMessage());
        }

        return $dto;
    }
}