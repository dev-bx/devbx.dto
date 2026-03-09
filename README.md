[![PHPUnit Tests](https://github.com/dev-bx/devbx.dto/actions/workflows/tests.yml/badge.svg)](https://github.com/dev-bx/devbx.dto/actions)

# DevBX Data Transfer Objects (DTO) Library

Мощная, строго типизированная и независимая от фреймворков библиотека DTO для PHP 8.2+.

Обеспечивает безопасную передачу данных между слоями приложения с поддержкой иммутабельности, декларативной валидации, маскирования данных, авто-кастинга типов и удобного маппинга HTTP-запросов.

## 🚀 Установка

Установите пакет с помощью Composer:

```bash
composer require devbx/dto

```

## ✨ Ключевые возможности

* **100% Строгая типизация:** Автоматическое приведение вложенных DTO, коллекций, `Enums`, скалярных типов и `DateTime`.
* **Иммутабельность:** Полная поддержка `readonly` свойств и Constructor Property Promotion.
* **Contextual HTTP Mapping:** Роутинг данных из `Query` (GET) и `Body` (POST/JSON) прямиком в свойства DTO для контроллеров.
* **Безопасность (Security):** Исключение или маскирование чувствительных данных (паролей, токенов) при экспорте "из коробки" (`#[Hidden]`, `#[Masked]`).
* **Декларативная валидация:** Встроенные атрибуты (`#[Min]`, `#[Max]`, `#[Email]`, `#[Regex]`, `#[InArray]`) для проверки бизнес-правил.
* **Smart Mapping & Formatting:** Явный алиасинг ключей (`#[MapFrom]`, `#[MapTo]`) и экспорт в `camelCase`, `snake_case` или `UPPER_SNAKE_CASE`.
* **Lifecycle Hooks:** Хуки жизненного цикла (`#[PostHydrate]`, `#[PreExport]`) для нормализации данных.
* **Strict Mode:** Защита от "мусорных" данных во входящих массивах (`#[Strict]`).
* **Dev Tools:** Встроенные инструменты для генерации классов "на лету" из сырых массивов и экспорта/импорта языково-независимых JSON-схем.

---

## 📦 Быстрый старт (Quick Start)

Создайте свой первый DTO, используя современные возможности PHP:

```php
use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Validation\Min;
use DevBX\DTO\Attributes\Validation\Email;

class UserDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,

        #[Min(3, 'Имя пользователя должно быть не короче 3 символов')]
        public readonly string $username,

        #[Email('Некорректный формат email')]
        public readonly ?string $email = null
    ) {}
}

// 1. Гидратация (из массива или JSON)
$dto = UserDTO::fromArray([
    'id' => 1,
    'username' => 'admin',
    'email' => 'admin@example.com'
]);

// 2. Валидация
$validation = $dto->validate();
if (!$validation->isSuccess()) {
    throw new \RuntimeException($validation->getErrors()[0]->getMessage());
}

// 3. Экспорт (по умолчанию возвращает camelCase ключи, можно передать FORMAT_SNAKE)
$array = $dto->toArray(BaseDTO::FORMAT_SNAKE);
// Ожидаемый результат: ['id' => 1, 'username' => 'admin', 'email' => 'admin@example.com']

```

---

## 📖 Подробное руководство

### 1. HTTP Mapping для Контроллеров (Contextual Mapping)

Библиотека идеально подходит для обработки HTTP-запросов. Вы можете указать, откуда именно брать данные: из GET-параметров или из тела запроса.

```php
use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Mapping\Query;
use DevBX\DTO\Attributes\Mapping\Body;
use DevBX\DTO\Http\AbstractController;

class UpdateUserRequest extends BaseDTO
{
    public function __construct(
        #[Query('user_id')]
        public readonly int $id, // Берется строго из GET (?user_id=123)

        #[Body]
        public readonly string $email // Берется строго из тела запроса (POST/JSON)
    ) {}
}

// Пример использования в вашем контроллере:
class UserController extends AbstractController
{
    public function update(array $queryParams, array $bodyParams)
    {
        /** @var UpdateUserRequest $dto */
        $dto = $this->resolveDto(UpdateUserRequest::class, $queryParams, $bodyParams);

        // $dto->id и $dto->email корректно заполнены, конфликты ключей исключены
    }
}

```

### 2. Безопасность и Маскирование данных

Защитите чувствительные данные от случайного попадания в логи, ответы API или фронтенд.

```php
use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Behavior\Masked;
use DevBX\DTO\Attributes\Behavior\Hidden;

class AuthResponseDTO extends BaseDTO
{
    public string $login;

    #[Masked]
    public string $password; // В toArray() станет '********'

    #[Masked('*** REDACTED ***')]
    public string $apiToken; // Кастомная маска

    #[Hidden]
    public string $internalSecret; // Вообще не попадет в toArray() и jsonSerialize()
}

```

### 3. Работа с коллекциями и вложенными DTO

Поддержка сложных структур данных "из коробки" с помощью типизированных коллекций (`BaseCollection`) и атрибута `#[Cast]`.

```php
use DevBX\DTO\BaseDTO;
use DevBX\DTO\BaseCollection;
use DevBX\DTO\Attributes\Cast;
use DevBX\DTO\Attributes\CollectionType;

class TagDTO extends BaseDTO {
    public string $name;
}

// Вариант 1: Строго типизированная коллекция
#[CollectionType(TagDTO::class)]
class TagCollection extends BaseCollection {}

class PostDTO extends BaseDTO
{
    public string $title;

    // Использование отдельного класса коллекции
    public TagCollection $tags;

    // Вариант 2: Использование обычного массива с кастингом в DTO
    #[Cast(TagDTO::class)]
    public array $categories = [];
}

```

### 4. Вычисляемые свойства (Computed Properties)

Нужно добавить производные данные в результирующий массив? Используйте `#[Computed]`.

```php
use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Mapping\Computed;
use DevBX\DTO\Attributes\Mapping\MapTo;

class ProductDTO extends BaseDTO
{
    public float $price;
    public float $taxRate = 0.2;

    #[Computed]
    #[MapTo('price_with_tax')]
    public function calculateTotal(): float
    {
        return $this->price * (1 + $this->taxRate);
    }
}

```

### 5. Алиасинг ключей (MapFrom / MapTo)

Полезно при интеграции со сторонними API, которые возвращают ключи с опечатками или специфичными префиксами.

```php
use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Mapping\MapFrom;
use DevBX\DTO\Attributes\Mapping\MapTo;

class IntegrationDTO extends BaseDTO
{
    #[MapFrom('@odata.count')]
    #[MapTo('totalItems')]
    public int $count;
}

```

### 6. Хуки жизненного цикла

Инкапсулируйте логику нормализации данных прямо внутри DTO.

```php
use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Lifecycle\PostHydrate;
use DevBX\DTO\Attributes\Lifecycle\PreExport;

class SearchDTO extends BaseDTO
{
    public string $query;
    public ?string $exportTime = null;

    #[PostHydrate]
    protected function normalize(): void
    {
        // Вызовется автоматически сразу после fromArray()
        $this->query = mb_strtolower(trim($this->query));
    }

    #[PreExport]
    protected function setTimestamp(): void
    {
        // Вызовется перед toArray()
        $this->exportTime = date('Y-m-d H:i:s');
    }
}

```

### 7. Strict Mode (Защита от неизвестных полей)

Включите строгий режим, чтобы предотвратить создание DTO, если во входящих данных присутствуют неизвестные свойства.

```php
use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Behavior\Strict;

#[Strict]
class StrictUserDTO extends BaseDTO
{
    public string $name;
}

// Выбросит UnmappedPropertiesException, так как поля 'is_admin' не существует в DTO
StrictUserDTO::fromArray(['name' => 'John', 'is_admin' => true]);

```

---

## 🛠 Инструменты разработчика (Dev Tools)

### Генерация кода "на лету" (DTOGenerator)

Устали писать DTO руками для огромных ответов от внешних API? Передайте массив данных в `DTOGenerator`, и он сам проанализирует типы, вложенности и создаст готовый PHP-код классов:

```php
use DevBX\DTO\Dev\DTOGenerator;

$apiResponse = [
    'id' => 123,
    'status' => 'active',
    'items' => [
        ['product_id' => 1, 'price' => 10.50]
    ]
];

// Сгенерирует корневой DTO и дочерний DTO для items с расстановкой атрибутов #[Cast]
$code = DTOGenerator::generate('OrderDTO', 'App\\DTO', $apiResponse);
echo $code;

```

### Импорт и экспорт JSON-схем (DTOSchemaManager)

Библиотека поддерживает конвертацию DTO в языково-независимые JSON-схемы (полезно для OpenAPI/Swagger, TypeScript или межсервисного взаимодействия) и обратно в PHP-код.

```php
use DevBX\DTO\Schema\DTOSchemaManager;
use DevBX\DTO\Schema\SchemaExporter;
use DevBX\DTO\Schema\SchemaImporter;
use DevBX\DTO\Schema\SchemaValidator;

$manager = new DTOSchemaManager(new SchemaValidator(), new SchemaExporter(), new SchemaImporter());

// 1. Экспорт PHP-класса в JSON-файл
$manager->exportToFile(UserDTO::class, '/path/to/schema.json');

// 2. Генерация PHP-кода на основе JSON-файла
$manager->importFromFile('/path/to/schema.json', '/path/to/output/dir');

```
