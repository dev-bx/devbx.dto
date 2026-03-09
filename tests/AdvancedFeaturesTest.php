<?php

namespace Tests\DevBX\DTO;

use PHPUnit\Framework\TestCase;
use DevBX\DTO\BaseDTO;
use DevBX\DTO\Validation\ValidationResult;
use DevBX\DTO\Validation\ValidationError;

// Имитация атрибутов и абстрактных классов, которые мы спроектировали
use DevBX\DTO\Attributes\Validation\Min;
use DevBX\DTO\Attributes\Mapping\Query;
use DevBX\DTO\Attributes\Mapping\Body;
use DevBX\DTO\Attributes\Mapping\MapFrom;
use DevBX\DTO\Attributes\Mapping\MapTo;
use DevBX\DTO\Http\AbstractController;
use DevBX\DTO\Attributes\Lifecycle\PostHydrate;
use DevBX\DTO\Attributes\Lifecycle\PreExport;
use DevBX\DTO\Attributes\Mapping\Computed;
use DevBX\DTO\Attributes\Behavior\Strict;
use DevBX\DTO\Exceptions\UnmappedPropertiesException;
use DevBX\DTO\Attributes\Behavior\Hidden;
use DevBX\DTO\Attributes\Behavior\Masked;

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

// Фикстура для Идеи 5 (Lifecycle Hooks)
class Idea5LifecycleDTO extends BaseDTO
{
    public string $email;
    public string $status;
    public ?string $exportTimestamp = null;

    #[PostHydrate]
    protected function normalizeData(): void
    {
        // Имитируем очистку данных после гидратации
        $this->email = strtolower(trim($this->email));

        if ($this->status === 'pending') {
            $this->status = 'processed_by_hook';
        }
    }

    #[PreExport]
    protected function prepareForExport(): void
    {
        // Имитируем добавление метки времени перед отдачей в массив
        $this->exportTimestamp = '2026-02-13 21:00:00';
    }
}

// Фикстура для Идеи 6 (Вычисляемые свойства)
class Idea6ComputedDTO extends BaseDTO
{
    public string $firstName = 'John';
    public string $lastName = 'Doe';
    public int $price = 100;

    // Стандартное поведение (уберет 'get' и сконвертирует в camelCase/snake_case)
    #[Computed]
    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    // Кастомный маппинг имени ключа
    #[Computed]
    #[MapTo('total_with_tax')]
    protected function calculateTotal(): float
    {
        return $this->price * 1.2;
    }

    // Метод без 'get'
    #[Computed]
    public function isExpensive(): bool
    {
        return $this->price > 50;
    }
}

// Фикстура для Идеи 7 (Strict Mode)
#[Strict]
class Idea7StrictDTO extends BaseDTO
{
    public int $id;
    public string $name;
}

// Фикстура для Идеи 8 (Data Masking & Hidden)
class Idea8SecurityDTO extends BaseDTO
{
    public string $username;

    #[Masked]
    public string $password;

    #[Masked('*** REDACTED ***')]
    public string $apiToken;

    #[Hidden]
    public string $internalSecret;
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

    /**
     * Идея 5: Тестирование хуков жизненного цикла (PostHydrate и PreExport)
     */
    public function testLifecycleHooks()
    {
        $inputData = [
            'email' => '   USer@ExAmple.COM   ',
            'status' => 'pending'
        ];

        // 1. Тестируем #[PostHydrate] (срабатывает внутри fromArray)
        $dto = Idea5LifecycleDTO::fromArray($inputData);

        // Проверяем, что метод normalizeData() отработал и изменил свойства
        $this->assertEquals('user@example.com', $dto->email, 'Email должен быть очищен и переведен в нижний регистр хуком');
        $this->assertEquals('processed_by_hook', $dto->status, 'Статус должен быть изменен хуком');
        $this->assertNull($dto->exportTimestamp, 'Поле exportTimestamp пока должно быть null');

        // 2. Тестируем #[PreExport] (срабатывает внутри toArray)
        $outputArray = $dto->toArray();

        // Проверяем, что метод prepareForExport() отработал перед генерацией массива
        $this->assertEquals('2026-02-13 21:00:00', $dto->exportTimestamp, 'Свойство должно заполниться внутри объекта');
        $this->assertArrayHasKey('exportTimestamp', $outputArray, 'Ключ должен появиться в экспортируемом массиве (camelCase по умолчанию)');
        $this->assertEquals('2026-02-13 21:00:00', $outputArray['exportTimestamp']);
    }

    /**
     * Идея 6: Тестирование вычисляемых свойств (Computed Properties)
     */
    public function testComputedProperties()
    {
        $dto = new Idea6ComputedDTO();

        // Тестируем экспорт в формате snake_case
        $outputArray = $dto->toArray(BaseDTO::FORMAT_SNAKE);

        // Проверяем обычные свойства
        $this->assertEquals('John', $outputArray['first_name']);

        // Проверяем Computed с префиксом get
        $this->assertArrayHasKey('full_name', $outputArray, 'Должен быть сгенерирован ключ full_name');
        $this->assertEquals('John Doe', $outputArray['full_name']);

        // Проверяем Computed с MapTo
        $this->assertArrayHasKey('total_with_tax', $outputArray);
        $this->assertEquals(120.0, $outputArray['total_with_tax']);

        // Проверяем Computed без префикса get
        $this->assertArrayHasKey('is_expensive', $outputArray);
        $this->assertTrue($outputArray['is_expensive']);
    }

    /**
     * Идея 7: Тестирование Strict Mode (защита от несмаппленных данных)
     */
    public function testStrictModeThrowsExceptionOnUnmappedData()
    {
        // 1. Успешный кейс (нет лишних данных)
        $validData = [
            'id' => 1,
            'name' => 'Valid User'
        ];
        $dto = Idea7StrictDTO::fromArray($validData);
        $this->assertEquals(1, $dto->id);
        $this->assertEquals('Valid User', $dto->name);

        // 2. Ожидаем исключение при наличии лишних данных
        $invalidData = [
            'id' => 2,
            'name' => 'Invalid User',
            'is_admin' => true,    // Лишнее поле 1
            'token' => 'abc-123'   // Лишнее поле 2
        ];

        $this->expectException(UnmappedPropertiesException::class);
        $this->expectExceptionMessage('Strict Mode: Unmapped properties found in input data - is_admin, token');

        // Должно выбросить UnmappedPropertiesException
        Idea7StrictDTO::fromArray($invalidData);
    }

    /**
     * Идея 8: Тестирование безопасности сериализации (Hidden и Masked)
     */
    public function testSecurityAttributesDuringExport()
    {
        $dto = new Idea8SecurityDTO();
        $dto->username = 'admin';
        $dto->password = 'super_secret_password_123';
        $dto->apiToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...';
        $dto->internalSecret = 'db_password_root';

        // Экспортируем в массив
        $outputArray = $dto->toArray();

        // 1. Обычное свойство экспортируется как есть
        $this->assertArrayHasKey('username', $outputArray);
        $this->assertEquals('admin', $outputArray['username']);

        // 2. Свойство с #[Masked] заменяется на маску по умолчанию
        $this->assertArrayHasKey('password', $outputArray);
        $this->assertEquals('********', $outputArray['password']);
        $this->assertNotEquals('super_secret_password_123', $outputArray['password']);

        // 3. Свойство с #[Masked('...')] заменяется на кастомную маску
        $this->assertArrayHasKey('apiToken', $outputArray);
        $this->assertEquals('*** REDACTED ***', $outputArray['apiToken']);

        // 4. Свойство с #[Hidden] полностью отсутствует в массиве
        $this->assertArrayNotHasKey('internalSecret', $outputArray);
    }
}