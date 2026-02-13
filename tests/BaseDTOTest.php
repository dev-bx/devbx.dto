<?php

namespace Tests\Local\Lib\DTO;

use Local\Lib\DTO\BaseDTO;
use Local\Lib\DTO\Attributes\Cast;
use PHPUnit\Framework\TestCase;
use Local\Lib\DTO\Dev\DTOGenerator;

// --- ТЕСТОВЫЕ DTO (Fixtures) ---
class TestAddressDTO extends BaseDTO
{
    public string $city;
    public string $street;
}

class TestUserDTO extends BaseDTO
{
    public int $id;
    public string $name;
    public ?string $email; // Без дефолта, для проверки uninitialized
    public bool $isActive;

    public ?TestAddressDTO $address = null;

    #[Cast(TestAddressDTO::class)]
    public array $historyAddresses = [];
}

// DTO для проверки Union Types и Strict Mode
class TestComplexDTO extends BaseDTO
{
    // Union Type (PHP 8.0+)
    public string|int $identifier;

    // Nullable int
    public ?int $age;
}

enum TestStatus: string {
    case Active = 'active';
    case Pending = 'pending';
}
class TestEnumDTO extends BaseDTO {
    public TestStatus $status;
    public ?TestStatus $nullableStatus = null;
}

// ----------------------------------

class BaseDTOTest extends TestCase
{
    /**
     * Тест базовой гидратации и нормализации ключей
     */
    public function testHydrationFromSnakeCase(): void
    {
        $data = [
            'ID' => '100',           // String -> Int conversion
            'NAME' => 'Ruslan',
            'IS_ACTIVE' => 'Y',      // String 'Y' -> Bool conversion
            'UNKNOWN_FIELD' => 123   // Should be ignored
        ];

        $dto = TestUserDTO::fromArray($data);

        $this->assertSame(100, $dto->id);
        $this->assertSame('Ruslan', $dto->name);
        $this->assertTrue($dto->isActive);

        // Проверяем Lazy Initialization: поле не трогали, оно uninitialized
        $rp = new \ReflectionProperty($dto, 'email');
        $this->assertFalse($rp->isInitialized($dto), 'Поле Email должно остаться неинициализированным');
    }

    /**
     * Тест Strict Mode (Нюанс: предотвращение неявного приведения типов)
     */
    public function testStrictModePreventsCoercion(): void
    {
        $data = [
            'ID' => '100', // Строка, а ожидается int
            'NAME' => 'Ruslan',
            'IS_ACTIVE' => 'Y' // Строка 'Y', а ожидается bool
        ];

        // Включаем Strict Mode = true
        $dto = TestUserDTO::fromArray($data, true);

        // 1. ID: Строка '100' не совместима с int в строгом режиме -> поле не инициализируется
        $rpId = new \ReflectionProperty($dto, 'id');
        $this->assertFalse($rpId->isInitialized($dto), 'В строгом режиме string "100" не должно попасть в int поле');

        // 2. IS_ACTIVE: 'Y' не bool -> поле не инициализируется
        $rpActive = new \ReflectionProperty($dto, 'isActive');
        $this->assertFalse($rpActive->isInitialized($dto));

        // 3. NAME: Строка в строку -> ок
        $this->assertSame('Ruslan', $dto->name);
    }

    /**
     * Тест Union Types (Нюанс: поддержка сложных типов)
     */
    public function testUnionTypesPassThrough(): void
    {
        // 1. Проверяем String
        $dtoString = TestComplexDTO::fromArray(['IDENTIFIER' => 'string_id']);
        $this->assertSame('string_id', $dtoString->identifier);

        // 2. Проверяем Int
        $dtoInt = TestComplexDTO::fromArray(['IDENTIFIER' => 12345]);
        $this->assertSame(12345, $dtoInt->identifier);

        // 3. Проверяем валидацию Union Type при несовместимости (Array)
        // Массив не подходит ни под string, ни под int
        $dtoInvalid = TestComplexDTO::fromArray(['IDENTIFIER' => []]);
        $rp = new \ReflectionProperty($dtoInvalid, 'identifier');
        $this->assertFalse($rp->isInitialized($dtoInvalid));
    }

    /**
     * Тест явной передачи NULL vs Отсутствие значения
     */
    public function testNullableExplicitAssignment(): void
    {
        // Кейс 1: Передали NULL явно
        $dto = TestUserDTO::fromArray(['EMAIL' => null]);

        // Свойство должно быть ИНИЦИАЛИЗИРОВАНО, и значение равно NULL
        $rp = new \ReflectionProperty($dto, 'email');
        $this->assertTrue($rp->isInitialized($dto));
        $this->assertNull($dto->email);

        // Кейс 2: Не передали ничего
        $dtoEmpty = TestUserDTO::fromArray([]);
        $this->assertFalse($rp->isInitialized($dtoEmpty));
    }

    /**
     * Тест Кэширования Рефлексии (Нюанс: Performance)
     * Мы проверяем приватное статическое свойство, чтобы убедиться, что кэш работает.
     */
    public function testReflectionCacheIsWorking(): void
    {
        // Сбрасываем состояние (если тесты запускаются в одном процессе)
        // Для доступа к приватному статическому свойству используем Reflection
        $reflectionClass = new \ReflectionClass(BaseDTO::class);
        $staticProp = $reflectionClass->getProperty('reflectionCache');
        $staticProp->setAccessible(true);
        $staticProp->setValue(null, []); // CORRECT

        // Первое создание объекта
        TestUserDTO::fromArray(['ID' => 1]);

        // Проверяем, что кэш заполнился
        $cache = $staticProp->getValue();
        $this->assertArrayHasKey(TestUserDTO::class, $cache, 'Кэш должен содержать данные по TestUserDTO');
        $this->assertNotEmpty($cache[TestUserDTO::class], 'Кэш свойств не должен быть пустым');

        // Проверяем, что в кэше лежат ReflectionProperty
        $firstProp = reset($cache[TestUserDTO::class]);
        $this->assertInstanceOf(\ReflectionProperty::class, $firstProp);
    }

    /**
     * Тест рекурсивной гидратации вложенного DTO
     */
    public function testNestedDtoHydration(): void
    {
        $data = [
            'ID' => 1,
            'NAME' => 'Test',
            'IS_ACTIVE' => true,
            'ADDRESS' => [
                'CITY' => 'Kazan',
                'STREET' => 'Baumana'
            ]
        ];

        $dto = TestUserDTO::fromArray($data);

        $this->assertInstanceOf(TestAddressDTO::class, $dto->address);
        $this->assertSame('Kazan', $dto->address->city);
    }

    /**
     * Тест гидратации массива DTO через атрибут #[Cast]
     */
    public function testArrayOfDtosHydration(): void
    {
        $data = [
            'ID' => 1,
            'NAME' => 'Test',
            'IS_ACTIVE' => true,
            'HISTORY_ADDRESSES' => [
                ['CITY' => 'Moscow', 'STREET' => 'Lenina'],
                ['CITY' => 'Ufa', 'STREET' => 'Mira']
            ]
        ];

        $dto = TestUserDTO::fromArray($data);

        $this->assertCount(2, $dto->historyAddresses);
        $this->assertInstanceOf(TestAddressDTO::class, $dto->historyAddresses[0]);
        $this->assertSame('Moscow', $dto->historyAddresses[0]->city);
    }

    /**
     * Тест валидации (Required fields)
     */
    public function testValidationFailsOnMissingFields(): void
    {
        $data = ['ID' => 1, 'IS_ACTIVE' => 'Y']; // Нет NAME

        $dto = TestUserDTO::fromArray($data);
        $result = $dto->validate();

        $this->assertFalse($result->isSuccess());

        $errors = $result->getErrors();
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error->getMessage(), 'name')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Validation should fail for missing NAME');
    }

    /**
     * Тест экспорта в массив (toArray)
     */
    public function testToArraySnakeCase(): void
    {
        $dto = new TestUserDTO();
        $dto->id = 555;
        $dto->name = 'ExportTest';
        $dto->isActive = false;

        $address = new TestAddressDTO();
        $address->city = 'Innopolis';
        $address->street = 'Universitetskaya';
        $dto->address = $address;

        $array = $dto->toArray(BaseDTO::FORMAT_UPPER_SNAKE);

        $this->assertEquals(555, $array['ID']);
        $this->assertEquals('ExportTest', $array['NAME']);
        $this->assertEquals('Innopolis', $array['ADDRESS']['CITY']);
        // Проверяем, что email не попал в массив
        $this->assertArrayNotHasKey('EMAIL', $array);
    }

    /**
     * Тест ArrayAccess
     */
    public function testArrayAccess(): void
    {
        $dto = new TestUserDTO();
        $dto->name = 'ArrayAccess';
        $this->assertEquals('ArrayAccess', $dto['name']);

        $dto['name'] = 'Changed';
        $this->assertEquals('Changed', $dto->name);
    }

    /**
     * Тест динамической генерации класса (DTOGenerator) и его работы
     */
    public function testDtoGenerationAndExecution(): void
    {
        if (!class_exists(DTOGenerator::class)) {
            $this->markTestSkipped('DTOGenerator class not found');
        }

        // 1. Данные для генерации
        $className = 'GeneratedTestDTO';
        $namespace = 'Tests\\Avt\\Kazanwatch\\DTO\\Dynamic';
        $data = [
            'ID' => '999',         // ожидает int
            'IS_ACTIVE' => 'Y',    // ожидает bool
            'TITLE' => 'Test Gen'  // ожидает string
        ];

        // 2. Генерируем PHP код
        $code = DTOGenerator::generate($className, $namespace, $data);

        // 3. Убираем <?php для eval()
        $evalCode = str_replace('<?php', '', $code);

        // 4. Исполняем
        try {
            eval($evalCode);
        } catch (\ParseError $e) {
            $this->fail("Parse Error in generated code: " . $e->getMessage());
        }

        // 5. Проверяем класс

        /* @var $fullClass BaseDTO|string */

        $fullClass = $namespace . '\\' . $className;
        $this->assertTrue(class_exists($fullClass), 'Generated class should exist');

        // 6. Пробуем использовать
        $dto = $fullClass::fromArray($data);

        // DTOGenerator определяет '999' (numeric string) как int
        $this->assertSame(999, $dto->id);
        $this->assertTrue($dto->isActive);
        $this->assertSame('Test Gen', $dto->title);
    }

    /**
     * Тест рекурсивной валидации: ошибка должна всплывать из вложенного DTO
     * (Gap Analysis: Deep Validation Failure)
     */
    public function testNestedValidationFailure(): void
    {
        $dto = new TestUserDTO();
        $dto->id = 1;
        $dto->name = 'Valid User';
        $dto->isActive = true;

        // Создаем адрес, но НЕ заполняем обязательное поле city
        $invalidAddress = new TestAddressDTO();
        // $invalidAddress->city оставлен неинициализированным
        $invalidAddress->street = 'Baker Street';

        $dto->address = $invalidAddress;

        $result = $dto->validate();

        $this->assertFalse($result->isSuccess(), 'Валидация должна упасть из-за ошибки во вложенном DTO');

        $errors = $result->getErrors();
        $found = false;
        // Ожидаем код ошибки в формате "имяСвойства.КОД_ОШИБКИ_ВЛОЖЕННОГО"
        // В BaseDTO: "{$propName}." . $error->getCode()
        $expectedCode = 'address.REQUIRED_FIELD_city';

        foreach ($errors as $error) {
            if ($error->getCode() === $expectedCode) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Должна быть ошибка с кодом {$expectedCode}");
    }

    /**
     * Тест полного цикла ArrayAccess: isset и unset
     * (Gap Analysis: ArrayAccess Existence)
     */
    public function testArrayAccessIssetAndUnset(): void
    {
        $dto = new TestUserDTO();
        $dto->name = 'Test Name';

        // 1. Проверка isset для существующего и инициализированного
        $this->assertTrue(isset($dto['name']), 'isset должен вернуть true для заданного поля');

        // 2. Проверка isset для существующего, но неинициализированного
        // (email у нас ?string без дефолта)
        $this->assertFalse(isset($dto['email']), 'isset должен вернуть false для неинициализированного поля');

        // 3. Проверка unset
        unset($dto['name']);
        // Используем Reflection, чтобы проверить, что свойство стало uninitialized
        $rp = new \ReflectionProperty($dto, 'name');
        $this->assertFalse($rp->isInitialized($dto), 'После unset поле должно стать неинициализированным');
        $this->assertFalse(isset($dto['name']));
    }

    /**
     * Тест JSON сериализации
     * (Gap Analysis: JSON Serialization)
     */
    public function testJsonSerialization(): void
    {
        $dto = new TestUserDTO();
        $dto->id = 777;
        $dto->name = 'Bond';
        $dto->isActive = true;

        // json_encode вызовет jsonSerialize(), который вызывает toArray(FORMAT_CAMEL)
        $json = json_encode($dto);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);

        // Проверяем ключи (должны быть camelCase по умолчанию)
        $this->assertArrayHasKey('isActive', $decoded);
        $this->assertEquals(777, $decoded['id']);
        $this->assertEquals('Bond', $decoded['name']);
    }

    /**
     * Тест различных форматов экспорта (toArray)
     * (Gap Analysis: Different Export Formats)
     */
    public function testToArrayFormats(): void
    {
        $dto = new TestUserDTO();
        $dto->id = 1;
        $dto->isActive = true; // camel: isActive, snake: is_active

        // 1. Camel Case (Default)
        $camel = $dto->toArray(BaseDTO::FORMAT_CAMEL);
        $this->assertArrayHasKey('isActive', $camel);
        $this->assertArrayNotHasKey('is_active', $camel);

        // 2. Snake Case (Lower)
        $snake = $dto->toArray(BaseDTO::FORMAT_SNAKE);
        $this->assertArrayHasKey('is_active', $snake);
        $this->assertArrayNotHasKey('isActive', $snake);
        $this->assertArrayNotHasKey('IS_ACTIVE', $snake);
    }

    /**
     * Тест генератора на определение сложных типов (Nested Arrays)
     * (Gap Analysis: Generator Complex Types)
     */
    public function testGeneratorDetectsComplexTypes(): void
    {
        if (!class_exists(DTOGenerator::class)) {
            $this->markTestSkipped('DTOGenerator class not found');
        }

        $className = 'ComplexGenDTO';
        $namespace = 'Tests\Gen';
        $data = [
            'SIMPLE_LIST' => [1, 2, 3], // List of int
            'OBJECT_LIST' => [['ID' => 1], ['ID' => 2]], // List of arrays (DTOs)
            'NESTED_OBJ'  => ['SOME_KEY' => 'value'] // Associative array
        ];

        $code = DTOGenerator::generate($className, $namespace, $data);

        // Мы не исполняем код, а проверяем наличие сгенерированных комментариев-подсказок

        // 1. SIMPLE_LIST -> array // List of int
        $this->assertStringContainsString('public array $simpleList; // List of int', $code);

        // 2. OBJECT_LIST -> array // Consider using #[Cast(ItemDTO::class)]
        $this->assertStringContainsString('Consider using #[Cast(ItemDTO::class)]', $code);

        // 3. NESTED_OBJ -> array // Nested structure. Consider creating a separate DTO
        $this->assertStringContainsString('Nested structure', $code);
    }

    /**
     * Тест приоритета ключей (Shadowing).
     * Если во входящем массиве есть конфликтующие ключи (userId и USER_ID),
     * библиотека должна предсказуемо выбирать один из них (camelCase > snake_case).
     */
    public function testKeyPrecedence(): void
    {
        $data = [
            'name' => 'Correct (Camel)', // Приоритет 1 (прямое совпадение)
            'NAME' => 'Wrong (Upper)'    // Приоритет 3
        ];

        $dto = TestUserDTO::fromArray($data);

        // Убеждаемся, что система не взяла случайное значение
        $this->assertSame('Correct (Camel)', $dto->name);
    }

    /**
     * Тест-демонстрация (Safety Check): Неинициализированные Nullable свойства.
     * Важный нюанс PHP: `public ?string $email;` без дефолта != `public ?string $email = null;`.
     * Если данных нет, поле остается uninitialized, и чтение вызывает фатальную ошибку,
     * ДАЖЕ если validate() прошел успешно.
     */
    public function testUninitializedNullablePropertyThrowsError(): void
    {
        // Передаем массив с обязательными полями, но БЕЗ email
        $dto = TestUserDTO::fromArray([
            'ID' => 1,
            'NAME' => 'Test User',
            'IS_ACTIVE' => true
        ]);

        // 1. Валидация считает, что все ок (так как null разрешен типом для email)
        $this->assertTrue($dto->validate()->isSuccess(), 'Валидация должна проходить для nullable полей, если остальные поля заполнены');

        // 2. Но попытка чтения свойства крашит код.
        // Этот тест напоминает разработчику: "Всегда добавляй = null для nullable полей в DTO!"
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('must not be accessed before initialization');

        $val = $dto->email;
    }

    /**
     * Тест на точность генератора типов (Detection Accuracy).
     * Проверяем баг: строка "12.50" (цена) не должна превращаться в int.
     */
    public function testGeneratorFloatDetection(): void
    {
        if (!class_exists(DTOGenerator::class)) {
            $this->markTestSkipped('DTOGenerator class not found');
        }

        // Эмулируем данные цены
        $data = ['PRICE' => '12.50'];
        $code = DTOGenerator::generate('PriceDTO', 'Tests', $data);

        // Сейчас ваша логика detectType использует is_numeric(), который вернет true.
        // И код генератора предложит: public int $price;
        // Это приведет к потере копеек (12.50 -> 12).

        // Этот тест должен упасть, сигнализируя, что генератор нужно доработать
        $this->assertStringNotContainsString(
            'public int $price;',
            $code,
            'Ошибка: Генератор предлагает int для дробного числа "12.50". Возможна потеря данных.'
        );
    }

    public function testEnumHydration(): void
    {
        // 1. Успешная конвертация строки в Enum
        $dto = TestEnumDTO::fromArray(['STATUS' => 'active']);
        $this->assertInstanceOf(TestStatus::class, $dto->status);
        $this->assertSame(TestStatus::Active, $dto->status);

        // 2. Обработка некорректного значения
        // Передаем значение, которого нет в Enum ('invalid_val').
        // Ожидание: BaseDTO::tryFrom вернет null, isValueCompatible вернет false,
        // поле 'status' останется НЕ инициализированным.
        $dtoInvalid = TestEnumDTO::fromArray(['STATUS' => 'invalid_val']);

        $rp = new \ReflectionProperty($dtoInvalid, 'status');
        $this->assertFalse(
            $rp->isInitialized($dtoInvalid),
            'Поле Enum не должно инициализироваться, если передано невалидное значение'
        );

        // 3. Проверка валидации при ошибке
        // Так как поле не инициализировано, validate() должен вернуть ошибку "REQUIRED_FIELD"
        $result = $dtoInvalid->validate();
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('REQUIRED_FIELD_status', $result->getErrors()[0]->getCode());
    }
    /**
     * Тест гидратации Float (Gap Analysis: Float Hydration)
     * Проверяем, что строки "12.50" корректно превращаются в float, а не обрезаются до int.
     */
    public function testFloatHydration(): void
    {
        // Создаем анонимный класс DTO для теста "на лету"
        $dtoClass = new class extends BaseDTO {
            public float $price;
            public ?float $nullablePrice = null;
        };
        $className = get_class($dtoClass);

        $data = [
            'PRICE' => '12.50',       // String -> Float
            'NULLABLE_PRICE' => 99.99 // Float -> Float
        ];

        $dto = $className::fromArray($data);

        $this->assertSame(12.5, $dto->price);
        $this->assertSame(99.99, $dto->nullablePrice);
        $this->assertIsFloat($dto->price);
    }

    /**
     * Тест создания коллекции DTO из массива массивов.
     * (Gap Analysis: fromCollection)
     */
    public function testFromCollection(): void
    {
        $data = [
            ['ID' => 1, 'NAME' => 'User 1', 'IS_ACTIVE' => 'Y'],
            ['ID' => 2, 'NAME' => 'User 2', 'IS_ACTIVE' => 'N'],
            'INVALID_ITEM', // Должен быть пропущен (is_array check)
        ];

        $collection = TestUserDTO::fromCollection($data);

        $this->assertCount(2, $collection);
        $this->assertContainsOnlyInstancesOf(TestUserDTO::class, $collection);

        $this->assertSame(1, $collection[0]->id);
        $this->assertTrue($collection[0]->isActive);

        $this->assertSame(2, $collection[1]->id);
        $this->assertFalse($collection[1]->isActive);
    }

    /**
     * Тест создания DTO из JSON строки и обработки ошибок.
     * (Gap Analysis: fromJson & Exception handling)
     */
    public function testFromJson(): void
    {
        // 1. Успешный кейс
        $json = '{"ID": 50, "NAME": "Json User", "IS_ACTIVE": true}';
        $dto = TestUserDTO::fromJson($json);
        $this->assertSame(50, $dto->id);
        $this->assertSame('Json User', $dto->name);

        // 2. Ошибка парсинга JSON
        $this->expectException(\JsonException::class);
        TestUserDTO::fromJson('{invalid-json}');
    }

    /**
     * Тест валидации структуры JSON (должен быть объект или массив).
     * (Gap Analysis: fromJson validation)
     */
    public function testFromJsonThrowsOnScalar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON must contain an object or array structure');
        // Передаем валидный JSON, который является скаляром, а не объектом
        TestUserDTO::fromJson('"Just a string"');
    }

    /**
     * Тест методов фильтрации only() и except().
     * (Gap Analysis: Helper methods)
     */
    public function testOnlyAndExceptHelpers(): void
    {
        $dto = new TestUserDTO();
        $dto->id = 1;
        $dto->name = 'Filter Test';
        $dto->isActive = true;

        // 1. Тест only(): берем только id и name
        $only = $dto->only('id', 'name');
        $this->assertArrayHasKey('id', $only);
        $this->assertArrayHasKey('name', $only);
        $this->assertArrayNotHasKey('isActive', $only);

        // 2. Тест except(): исключаем id
        $except = $dto->except('id');
        $this->assertArrayNotHasKey('id', $except);
        $this->assertArrayHasKey('name', $except);
        $this->assertArrayHasKey('isActive', $except);
    }

    /**
     * Тест глубокого клонирования.
     * Важно убедиться, что вложенные объекты тоже клонируются, а не передаются по ссылке.
     * (Gap Analysis: __clone implementation)
     */
    public function testDeepClone(): void
    {
        $dto = new TestUserDTO();
        $dto->name = 'Original';

        $address = new TestAddressDTO();
        $address->city = 'Kazan';
        $dto->address = $address;

        // Клонируем
        $clone = clone $dto;

        // Изменяем клон
        $clone->name = 'Clone';
        $clone->address->city = 'Moscow';

        // Проверяем оригинал (не должен измениться)
        $this->assertSame('Original', $dto->name);
        $this->assertSame('Kazan', $dto->address->city, 'Вложенный объект address не был склонирован (Deep Clone failed)');

        // Проверяем, что это разные инстансы
        $this->assertNotSame($dto->address, $clone->address);
    }

    /**
     * Тест автоматической конвертации строк в DateTime объекты.
     * (Gap Analysis: processValue -> DateTime handling)
     */
    public function testDateTimeHydration(): void
    {
        // Создаем анонимный класс для теста, чтобы не засорять глобальные фикстуры
        $dtoClass = new class extends BaseDTO {
            public \DateTime $created;
            public ?\DateTimeImmutable $updated = null;
        };
        $className = get_class($dtoClass);

        $dateStr = '2023-10-05 12:00:00';

        $dto = $className::fromArray([
            'CREATED' => $dateStr,
            'UPDATED' => $dateStr
        ]);

        // 1. Проверяем DateTime
        $this->assertInstanceOf(\DateTime::class, $dto->created);
        $this->assertEquals($dateStr, $dto->created->format('Y-m-d H:i:s'));

        // 2. Проверяем DateTimeImmutable
        $this->assertInstanceOf(\DateTimeImmutable::class, $dto->updated);
        $this->assertEquals($dateStr, $dto->updated->format('Y-m-d H:i:s'));

        // 3. Проверяем, что некорректная дата остается строкой (и потенциально вызывает TypeError при strict=true,
        // но при strict=false просто передается, если бы тип был mixed/string.
        // Здесь у нас строгая типизация св-ва, поэтому BaseDTO должен попытаться создать DateTime,
        // а если упадет Exception внутри конструктора DateTime -> вернет value as is.
        // Так как свойство типизировано строго как DateTime, PHP выбросит TypeError при присвоении строки.
        // Это ожидаемое поведение (Fail fast), но проверим, что processValue пытается это сделать.
    }

    /**
     * Тест валидации массива вложенных DTO.
     * Проверяет, что ошибки корректно всплывают с указанием индекса элемента.
     * (Gap Analysis: validate -> array of objects logic)
     */
    public function testValidationFailsInArrayOfDtos(): void
    {
        $dto = new TestUserDTO();
        $dto->id = 1;
        $dto->name = 'Master User';
        $dto->isActive = true;

        // 1. Создаем валидный адрес
        $validAddr = new TestAddressDTO();
        $validAddr->city = 'Kazan';
        $validAddr->street = 'Baumana';

        // 2. Создаем НЕВАЛИДНЫЙ адрес (нет обязательного поля city)
        $invalidAddr = new TestAddressDTO();
        $invalidAddr->street = 'Pushkina';
        // city не задан -> ошибка

        // Присваиваем массив адресов
        $dto->historyAddresses = [$validAddr, $invalidAddr];

        // Запускаем валидацию
        $result = $dto->validate();

        $this->assertFalse($result->isSuccess(), 'Валидация должна упасть, так как один из элементов массива неверен');

        // Ищем ошибку с правильным путем: historyAddresses[1].REQUIRED_FIELD_city
        // [1] - потому что второй элемент массива
        $expectedCode = 'historyAddresses[1].REQUIRED_FIELD_city';

        $found = false;
        foreach ($result->getErrors() as $error) {
            if ($error->getCode() === $expectedCode) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Должна быть найдена ошибка с индексом массива: {$expectedCode}");
    }

    /**
     * Тест гидратации типов даты Bitrix (Date и DateTime).
     * Проверяет, что строки корректно превращаются в объекты Bitrix.
     * Использует Fallback-стратегию BaseDTO при отсутствии Context.
     * (Bitrix Integration: Type Hydration)
     */
    public function testBitrixDateTypesHydration(): void
    {
        // Проверка наличия классов Bitrix
        if (!class_exists(\Bitrix\Main\Type\Date::class)) {
            $this->markTestSkipped('Bitrix Main module classes not found.');
        }

        // Анонимный DTO с типами Bitrix
        $dtoClass = new class extends BaseDTO {
            public \Bitrix\Main\Type\Date $bitrixDate;
            public \Bitrix\Main\Type\DateTime $bitrixDateTime;
        };
        $className = get_class($dtoClass);

        // Используем формат Y-m-d H:i:s, который однозначно понимается \DateTime
        $dateStr = '2023-12-31';
        $dateTimeStr = '2023-12-31 23:59:59';

        $dto = $className::fromArray([
            'BITRIX_DATE' => $dateStr,
            'BITRIX_DATE_TIME' => $dateTimeStr
        ]);

        // 1. Проверка Bitrix Date
        // Если свойство не инициализировано, здесь упадет Error, как в вашем логе.
        // Теперь Fallback должен успешно создать объект.
        $this->assertTrue(isset($dto->bitrixDate), 'Свойство bitrixDate не было инициализировано');
        $this->assertInstanceOf(\Bitrix\Main\Type\Date::class, $dto->bitrixDate);
        $this->assertEquals($dateStr, $dto->bitrixDate->format('Y-m-d'));

        // 2. Проверка Bitrix DateTime
        $this->assertInstanceOf(\Bitrix\Main\Type\DateTime::class, $dto->bitrixDateTime);
        $this->assertEquals($dateTimeStr, $dto->bitrixDateTime->format('Y-m-d H:i:s'));
    }

    /**
     * Тест специфичной конвертации строковых булевых значений ('Y'/'N').
     */
    public function testYAndNBooleanConversion(): void
    {
        $dtoClass = new class extends BaseDTO {
            public bool $flagYes;
            public bool $flagNo;
        };
        $className = get_class($dtoClass);

        $dto = $className::fromArray([
            'FLAG_YES' => 'Y', // Строковое true
            'FLAG_NO' => 'N'   // Строковое false
        ]);

        $this->assertTrue($dto->flagYes, "'Y' должно конвертироваться в true");
        $this->assertFalse($dto->flagNo, "'N' должно конвертироваться в false");
    }

    /**
     * Тест структуры ошибок валидации.
     * Убеждаемся, что возвращается независимый ValidationResult, а ошибки — это ValidationError.
     */
    public function testValidationReturnsCustomObjects(): void
    {
        $dto = new TestUserDTO();
        // Не заполняем обязательные поля, чтобы вызвать ошибку

        $result = $dto->validate();

        // Проверяем типы новых объектов валидации
        $this->assertInstanceOf(\Local\Lib\DTO\Validation\ValidationResult::class, $result);
        $this->assertFalse($result->isSuccess());

        $errors = $result->getErrors();
        $this->assertNotEmpty($errors);

        $firstError = reset($errors);
        $this->assertInstanceOf(\Local\Lib\DTO\Validation\ValidationError::class, $firstError);

        // Проверяем, что код ошибки пробрасывается корректно
        // Для TestUserDTO ожидаем 'REQUIRED_FIELD_id'
        $this->assertStringContainsString('REQUIRED_FIELD', (string)$firstError->getCode());
    }

    /**
     * Тест использования кастомного StringHelper при экспорте в UPPER_SNAKE_CASE.
     * Проверяет корректность генерации ключей для сложных имен свойств.
     */
    public function testToArrayUsingCustomStringHelper(): void
    {
        $dto = new TestUserDTO();
        // historyAddresses -> HISTORY_ADDRESSES (тест сложного составного имени)
        $dto->historyAddresses = [];
        $dto->id = 1;
        $dto->name = 'Test';
        $dto->isActive = true;

        $arr = $dto->toArray(BaseDTO::FORMAT_UPPER_SNAKE);

        $this->assertArrayHasKey('HISTORY_ADDRESSES', $arr, 'StringHelper должен корректно переводить camelCase в UPPER_SNAKE_CASE');
        $this->assertArrayHasKey('IS_ACTIVE', $arr);
    }

    /**
     * Тест функционала коллекций: создание, поиск, фильтрация.
     */
    public function testCollectionFeatures(): void
    {
        // 1. Подготовка данных
        $user1 = new TestUserDTO(); $user1->id = 1; $user1->name = 'Alice'; $user1->isActive = true;
        $user2 = new TestUserDTO(); $user2->id = 2; $user2->name = 'Bob'; $user2->isActive = false;
        $user3 = new TestUserDTO(); $user3->id = 3; $user3->name = 'Charlie'; $user3->isActive = true;

        $collection = new \Local\Lib\DTO\BaseCollection([$user1, $user2, $user3]);

        // 2. Count & Access
        $this->assertCount(3, $collection);
        $this->assertSame('Alice', $collection[0]->name);

        // 3. Find (Найти одного)
        $bob = $collection->find(fn($u) => $u->name === 'Bob');
        $this->assertNotNull($bob);
        $this->assertSame(2, $bob->id);

        $notFound = $collection->find(fn($u) => $u->name === 'Zorro');
        $this->assertNull($notFound);

        // 4. Filter (Найти всех активных)
        $activeUsers = $collection->filter(fn($u) => $u->isActive);
        $this->assertCount(2, $activeUsers);
        $this->assertInstanceOf(\Local\Lib\DTO\BaseCollection::class, $activeUsers);
        // Проверяем сброс ключей или сохранение порядка
        $this->assertSame('Alice', $activeUsers[0]->name);
        $this->assertSame('Charlie', $activeUsers[1]->name);

        // 5. Column (Список ID)
        $ids = $collection->column('id');
        $this->assertEquals([1, 2, 3], $ids);

        // 6. SortBy
        $sorted = $collection->sortBy('name', true); // DESC
        $this->assertSame('Charlie', $sorted->first()->name);
        $this->assertSame('Alice', $sorted->last()->name);
    }

    /**
     * Тест строгой типизации коллекции.
     */
    public function testCollectionEnforcesTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Попытка добавить обычный массив вместо DTO
        new \Local\Lib\DTO\BaseCollection([['id' => 1]]);
    }

    /**
     * Тест гидратации кастомной коллекции (наследника BaseCollection).
     * Проверяет, что массив данных превращается в объект коллекции, заполненный DTO.
     */
    public function testCustomCollectionHydration(): void
    {
        // 1. Создаем класс коллекции на лету
        // (В реальном коде это будет отдельный класс)
        if (!class_exists('Tests\UserCollection')) {
            // Эмуляция класса через eval, т.к. PHP не поддерживает анонимные классы в качестве типов свойств так легко
            eval('
                namespace Tests;
                use Local\Lib\DTO\BaseCollection;
                class UserCollection extends BaseCollection {}
            ');
        }

        // 2. Создаем DTO, который использует эту коллекцию
        $dtoClass = new class extends BaseDTO {
            #[Cast(TestUserDTO::class)]
            public \Tests\UserCollection $users;
        };
        $className = get_class($dtoClass);

        // 3. Входящие данные (сырой массив)
        $data = [
            'USERS' => [
                ['ID' => 10, 'NAME' => 'Alice', 'IS_ACTIVE' => 'Y'],
                ['ID' => 20, 'NAME' => 'Bob',   'IS_ACTIVE' => 'N'],
            ]
        ];

        // 4. Гидратация
        $dto = $className::fromArray($data);

        // 5. Проверки
        // а) Тип свойства
        $this->assertInstanceOf(\Tests\UserCollection::class, $dto->users);
        $this->assertInstanceOf(\Local\Lib\DTO\BaseCollection::class, $dto->users);

        // б) Содержимое
        $this->assertCount(2, $dto->users);
        $this->assertInstanceOf(TestUserDTO::class, $dto->users[0]);
        $this->assertSame('Alice', $dto->users[0]->name);
        $this->assertSame(20, $dto->users[1]->id);
    }

    /**
     * Тест новых методов коллекции: where, pluck, keyBy, groupBy
     * (Gap Analysis: Advanced Collection Methods)
     */
    public function testCollectionAdvancedMethods(): void
    {
        $user1 = new TestUserDTO(); $user1->id = 1; $user1->name = 'Alice'; $user1->isActive = true;
        $user2 = new TestUserDTO(); $user2->id = 2; $user2->name = 'Bob'; $user2->isActive = false;
        $user3 = new TestUserDTO(); $user3->id = 3; $user3->name = 'Charlie'; $user3->isActive = true;
        $user4 = new TestUserDTO(); $user4->id = 4; $user4->name = 'Alice'; $user4->isActive = false;

        $collection = new \Local\Lib\DTO\BaseCollection([$user1, $user2, $user3, $user4]);

        // 1. Тест where
        $activeUsers = $collection->where('isActive', true);
        $this->assertCount(2, $activeUsers);
        $this->assertInstanceOf(\Local\Lib\DTO\BaseCollection::class, $activeUsers);
        $this->assertSame(1, $activeUsers->first()->id);

        $aliceUsers = $collection->where('name', '=', 'Alice');
        $this->assertCount(2, $aliceUsers);
        $this->assertSame(4, $aliceUsers->last()->id);

        $idGreater = $collection->where('id', '>', 2);
        $this->assertCount(2, $idGreater);
        $this->assertSame(3, $idGreater->first()->id);

        // 2. Тест pluck
        $names = $collection->pluck('name');
        $this->assertEquals(['Alice', 'Bob', 'Charlie', 'Alice'], $names);

        $namesById = $collection->pluck('name', 'id');
        $this->assertEquals([1 => 'Alice', 2 => 'Bob', 3 => 'Charlie', 4 => 'Alice'], $namesById);

        // 3. Тест keyBy
        $keyedByName = $collection->keyBy('name');
        // Ожидаем, что Alice будет перезаписана последней (id=4)
        $this->assertCount(3, $keyedByName);
        $this->assertSame(4, $keyedByName['Alice']->id);
        $this->assertSame(2, $keyedByName['Bob']->id);

        // 4. Тест groupBy
        $groupedByName = $collection->groupBy('name');
        $this->assertCount(3, $groupedByName);
        $this->assertInstanceOf(\Local\Lib\DTO\BaseCollection::class, $groupedByName['Alice']);
        $this->assertCount(2, $groupedByName['Alice']);
        $this->assertSame(1, $groupedByName['Alice'][0]->id);
        $this->assertSame(4, $groupedByName['Alice'][1]->id);
    }

    /**
     * Тест магических геттеров и сеттеров (__call).
     * (Gap Analysis: Magic Methods __call)
     */
    public function testMagicGettersAndSetters(): void
    {
        $dto = new TestUserDTO();

        // Тест сеттеров (поддержка Fluent Interface)
        $dto->setId(10)
            ->setName('Magic')
            ->setIsActive(true);

        // Тест геттеров
        $this->assertSame(10, $dto->getId());
        $this->assertSame('Magic', $dto->getName());
        $this->assertTrue($dto->getIsActive());

        // Тест процессинга типов через сеттер (string '99' -> int 99)
        // strict=false по умолчанию в __call
        $dto->setId('99');
        $this->assertSame(99, $dto->getId());

        // Ожидание исключения при обращении к несуществующему методу/свойству
        $this->expectException(\BadMethodCallException::class);
        $dto->setUnknownField(123);
    }

    /**
     * Тест дополнительных методов коллекции (add, isEmpty, reject, map, reduce).
     * (Gap Analysis: BaseCollection secondary methods)
     */
    public function testCollectionAdditionalMethods(): void
    {
        $collection = new \Local\Lib\DTO\BaseCollection();

        // Тест isEmpty / isNotEmpty
        $this->assertTrue($collection->isEmpty());
        $this->assertFalse($collection->isNotEmpty());

        $user1 = new TestUserDTO(); $user1->id = 1; $user1->name = 'Alice'; $user1->isActive = true;
        $user2 = new TestUserDTO(); $user2->id = 2; $user2->name = 'Bob'; $user2->isActive = false;

        // Тест add (возвращает static)
        $collection->add($user1)->add($user2);

        $this->assertFalse($collection->isEmpty());
        $this->assertTrue($collection->isNotEmpty());
        $this->assertCount(2, $collection);

        // Тест reject (обратный filter)
        $inactiveUsers = $collection->reject(fn($u) => $u->isActive);
        $this->assertCount(1, $inactiveUsers);
        $this->assertSame('Bob', $inactiveUsers->first()->name);

        // Тест map
        $names = $collection->map(fn($u) => $u->name);
        $this->assertEquals(['Alice', 'Bob'], $names);

        // Тест reduce (сумма ID)
        $sumOfIds = $collection->reduce(fn($carry, $u) => $carry + $u->id, 0);
        $this->assertSame(3, $sumOfIds);
    }

    /**
     * Тест генерации PHPDoc и IDE стабов.
     * (Gap Analysis: DTOGenerator documentation features)
     */
    public function testGeneratorDocsAndAnnotations(): void
    {
        if (!class_exists(DTOGenerator::class)) {
            $this->markTestSkipped('DTOGenerator class not found');
        }

        // 1. Тест генерации PHPDoc для конкретного класса
        $docs = DTOGenerator::generateDocsForClass(TestUserDTO::class);

        // Проверяем наличие корректных сигнатур в блоке комментариев
        $this->assertStringContainsString('@method int getId()', $docs);
        $this->assertStringContainsString('@method self setId(int $value)', $docs);
        $this->assertStringContainsString('@method bool getIsActive()', $docs);

        // 2. Тест генерации IDE аннотаций для неймспейса
        // Используем текущий неймспейс фикстур
        $annotations = DTOGenerator::generateIdeAnnotations('Tests\Local\Lib\DTO');

        // Проверяем, что сгенерирован правильный каркас файла
        $this->assertStringContainsString('namespace Tests\Local\Lib\DTO {', $annotations);
        $this->assertStringContainsString('class TestUserDTO extends \Local\Lib\DTO\BaseDTO {}', $annotations);

        // В режиме заглушки возвращаемый тип должен быть полным классом, а не self
        $this->assertStringContainsString('@method \Tests\Local\Lib\DTO\TestUserDTO setId(int $value)', $annotations);
    }

}