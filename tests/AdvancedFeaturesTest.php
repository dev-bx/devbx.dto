<?php

namespace Tests\Local\Lib\DTO;

use PHPUnit\Framework\TestCase;
use Local\Lib\DTO\BaseDTO;
use Local\Lib\DTO\Validation\ValidationResult;
use Local\Lib\DTO\Validation\ValidationError;

// Имитация атрибутов и абстрактных классов, которые мы спроектировали
use Local\Lib\DTO\Attributes\Validation\Min;
use Local\Lib\DTO\Attributes\Mapping\Query;
use Local\Lib\DTO\Attributes\Mapping\Body;
use Local\Lib\DTO\Attributes\Mapping\MapFrom;
use Local\Lib\DTO\Attributes\Mapping\MapTo;
use Local\Lib\DTO\Http\AbstractController;

// --- ТЕСТОВЫЕ ФИКСТУРЫ (Fixtures) ---

// Фикстура для Идеи 1 (Валидация)
class Idea1ValidationDTO extends BaseDTO
{
    #[Min(18, 'User must be at least 18 years old.')]
    public int $age;

    #[Min(5)]
    public string $username;

    #[Min(2)]
    public array $roles;
}

// Фикстура для Идеи 2 (Immutable DTO & Constructors)
// Убрали readonly у класса, добавили readonly к свойствам в конструкторе
class Idea2ImmutableDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $status = 'active'
    ) {}
}

// Фикстура для Идеи 3 (Абстрактный Контроллер)
// Аналогично, используем readonly для свойств
class Idea3RequestDTO extends BaseDTO
{
    public function __construct(
        #[Query('user_id')]
        public readonly int $id,

        #[Body('user_email')]
        public readonly string $email
    ) {}
}

class TestController extends AbstractController
{
    public function handle(array $get, array $post): Idea3RequestDTO
    {
        /** @var Idea3RequestDTO $dto */
        $dto = $this->resolveDto(Idea3RequestDTO::class, $get, $post);
        return $dto;
    }
}

// Фикстура для Идеи 4 (Явный Алиасинг)
class Idea4MappingDTO extends BaseDTO
{
    #[MapFrom('@odata.count')]
    #[MapTo('total_items')]
    public int $count;

    #[MapFrom('X-Request-Id')]
    #[MapTo('request_id')]
    public string $requestId;
}


// --- ТЕСТЫ ---

class AdvancedFeaturesTest extends TestCase
{
    /**
     * Идея 1: Тестирование декларативной валидации
     */
    public function testDeclarativeValidation()
    {
        $dto = new Idea1ValidationDTO();

        // 1. Тест провала валидации (значения меньше минимума)
        $dto->age = 16;
        $dto->username = 'bob';
        $dto->roles = ['admin'];

        $result = $dto->validate();

        $this->assertFalse($result->isSuccess());
        $errors = $result->getErrors();
        $this->assertCount(3, $errors, 'Should have exactly 3 validation errors');

        // Проверяем кастомное сообщение
        $this->assertEquals('User must be at least 18 years old.', $errors[0]->getMessage());
        $this->assertEquals('age.VALIDATION_MIN', $errors[0]->getCode());
        $this->assertEquals('username.VALIDATION_MIN_LENGTH', $errors[1]->getCode());
        $this->assertEquals('roles.VALIDATION_MIN_ITEMS', $errors[2]->getCode());

        // 2. Тест успешной валидации
        $dto->age = 21;
        $dto->username = 'robert';
        $dto->roles = ['admin', 'manager'];

        $resultSuccess = $dto->validate();
        $this->assertTrue($resultSuccess->isSuccess());
    }

    /**
     * Идея 2: Тестирование иммутабельных объектов (Constructor Promotion)
     */
    public function testImmutableConstructorHydration()
    {
        $data = [
            'id' => 99,
            'status' => 'banned'
        ];

        $dto = Idea2ImmutableDTO::fromArray($data);

        $this->assertInstanceOf(Idea2ImmutableDTO::class, $dto);
        $this->assertEquals(99, $dto->id);
        $this->assertEquals('banned', $dto->status);

        // Проверка дефолтных значений конструктора
        $dtoDefault = Idea2ImmutableDTO::fromArray(['id' => 100]);
        $this->assertEquals(100, $dtoDefault->id);
        $this->assertEquals('active', $dtoDefault->status);
    }

    /**
     * Идея 3: Тестирование контекстного маппинга (AbstractController)
     */
    public function testContextualControllerMapping()
    {
        $controller = new TestController();

        $getParams = [
            'user_id' => 42,
            'user_email' => 'fake-get@test.com' // Должно игнорироваться, так как email берется из Body
        ];

        $postParams = [
            'user_id' => 999, // Должно игнорироваться, так как ID берется из Query
            'user_email' => 'real-post@test.com'
        ];

        $dto = $controller->handle($getParams, $postParams);

        $this->assertInstanceOf(Idea3RequestDTO::class, $dto);

        // Проверяем, что ID взялся из GET, а Email из POST, несмотря на конфликтующие ключи
        $this->assertEquals(42, $dto->id);
        $this->assertEquals('real-post@test.com', $dto->email);
    }

    /**
     * Идея 4: Тестирование явного маппинга ключей (MapFrom / MapTo)
     */
    public function testExplicitKeyAliasing()
    {
        // Тестируем MapFrom (Гидратация)
        $inputData = [
            '@odata.count' => 150,
            'X-Request-Id' => 'req_55aa22'
        ];

        $dto = Idea4MappingDTO::fromArray($inputData);

        $this->assertEquals(150, $dto->count);
        $this->assertEquals('req_55aa22', $dto->requestId);

        // Тестируем MapTo (Экспорт)
        $outputData = $dto->toArray();

        // Убеждаемся, что используются ключи из MapTo
        $this->assertArrayHasKey('total_items', $outputData);
        $this->assertArrayHasKey('request_id', $outputData);

        $this->assertEquals(150, $outputData['total_items']);
        $this->assertEquals('req_55aa22', $outputData['request_id']);

        // Убеждаемся, что старые имена свойств не экспортировались
        $this->assertArrayNotHasKey('count', $outputData);
        $this->assertArrayNotHasKey('requestId', $outputData);
    }
}