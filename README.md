---

# Data Transfer Objects (DTO) Library

Мощная, строго типизированная и независимая от фреймворков библиотека DTO для PHP 8.1+.
Обеспечивает безопасную передачу данных между слоями приложения с поддержкой иммутабельности, декларативной валидации, маскирования данных и авто-кастинга типов.

## 🚀 Ключевые возможности

* **100% Строгая типизация:** Поддержка вложенных DTO, коллекций, `Enums` и `DateTime`.
* **Иммутабельность:** Полная поддержка `readonly` свойств через Constructor Property Promotion.
* **Безопасность (Security):** Исключение или маскирование чувствительных данных (паролей, токенов) при экспорте.
* **Lifecycle Hooks:** Хуки жизненного цикла (`#[PostHydrate]`, `#[PreExport]`) для нормализации данных.
* **Декларативная валидация:** Атрибуты (например, `#[Min]`) для проверки бизнес-правил.
* **Smart Mapping:** Явный алиасинг ключей (`#[MapFrom]`, `#[MapTo]`) и поддержка `camelCase` / `snake_case` "из коробки".
* **Contextual Controller Mapping:** Роутинг данных из `Query` и `Body` прямиком в DTO.
* **Strict Mode:** Защита от мусорных данных во входящих массивах.

---

## 📦 Быстрый старт (Quick Start)

Создайте свой первый DTO, используя современные возможности PHP 8.

```php
use Local\Lib\DTO\BaseDTO;
use Local\Lib\DTO\Attributes\Validation\Min;

class UserDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        
        #[Min(3)]
        public readonly string $username,
        
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
    throw new Exception($validation->getErrors()[0]->getMessage());
}

// 3. Экспорт (по умолчанию возвращает camelCase ключи, можно передать FORMAT_SNAKE)
$array = $dto->toArray(BaseDTO::FORMAT_SNAKE);

```

---

## 📖 Подробное руководство (Features)

### 1. Гидратация и Конструкторы (Immutability)

Библиотека автоматически анализирует параметры конструктора. Это позволяет создавать абсолютно неизменяемые объекты.

```php
readonly class ProductDTO extends BaseDTO
{
    public function __construct(
        public int $id,
        public string $title
    ) {}
}

```

### 2. Приведение типов (Casting) и Вложенность

Свойства автоматически приводятся к нужным типам. Библиотека "из коробки" понимает объекты `DateTime`, `Enum`, другие `BaseDTO` и Коллекции.

```php
class OrderDTO extends BaseDTO
{
    public StatusEnum $status; // Автоматически найдет нужный case в Enum
    public DateTime $createdAt; // Распарсит строку даты
    public UserDTO $customer;   // Рекурсивно гидратирует вложенный DTO
    
    #[CollectionType(ItemDTO::class)]
    public BaseCollection $items; // Создаст коллекцию типизированных объектов
}

```

### 3. Алиасинг ключей (Mapping)

Если API стороннего сервиса возвращает ключи с префиксами или опечатками, используйте `#[MapFrom]` и `#[MapTo]`.

```php
use Local\Lib\DTO\Attributes\Mapping\MapFrom;
use Local\Lib\DTO\Attributes\Mapping\MapTo;

class IntegrationDTO extends BaseDTO
{
    #[MapFrom('@odata.count')]
    #[MapTo('total_items')]
    public int $count;
    
    #[MapFrom('X-Request-Id')]
    public string $requestId;
}

```

*Входящий массив может содержать `@odata.count`, но при вызове `$dto->toArray()` ключ станет `total_items`.*

### 4. Вычисляемые свойства (Computed Properties)

Для производных данных, которые должны попасть в итоговый массив/JSON (например, для фронтенда на Vue3), используйте `#[Computed]`.

```php
use Local\Lib\DTO\Attributes\Mapping\Computed;

class ProfileDTO extends BaseDTO
{
    public string $firstName;
    public string $lastName;

    #[Computed]
    public function getFullName(): string
    {
        return "{$this->firstName} {$this->lastName}";
    }
}
// Результат toArray(): ['firstName' => '...', 'lastName' => '...', 'fullName' => '...']

```

### 5. Безопасность и Маскирование (Security)

Защитите чувствительные данные от попадания в логи или клиентские ответы.

```php
use Local\Lib\DTO\Attributes\Behavior\Masked;
use Local\Lib\DTO\Attributes\Behavior\Hidden;

class AuthResponseDTO extends BaseDTO
{
    public string $login;

    #[Masked] 
    public string $password; // В toArray() станет '********'

    #[Masked('*** REDACTED ***')]
    public string $apiKey;   // Кастомная маска

    #[Hidden]
    public string $internalDbId; // Вообще не попадет в toArray() и toJson()
}

```

### 6. Strict Mode (Защита от мусора)

Включите строгий режим, чтобы DTO выбрасывал `UnmappedPropertiesException`, если во входящем массиве есть неизвестные ключи.

```php
use Local\Lib\DTO\Attributes\Behavior\Strict;

#[Strict]
class StrictUserDTO extends BaseDTO
{
    public string $name;
}
// StrictUserDTO::fromArray(['name' => 'John', 'is_admin' => true]) -> Exception!

```

### 7. Lifecycle Hooks (Хуки)

Инкапсулируйте логику нормализации внутри DTO.

```php
use Local\Lib\DTO\Attributes\Lifecycle\PostHydrate;
use Local\Lib\DTO\Attributes\Lifecycle\PreExport;

class SearchDTO extends BaseDTO
{
    public string $query;

    #[PostHydrate]
    protected function normalize(): void
    {
        // Вызовется автоматически сразу после fromArray()
        $this->query = strtolower(trim($this->query));
    }
}

```

### 8. Contextual HTTP Mapping

Идеально для контроллеров. Маршрутизируйте данные из разных частей HTTP-запроса (GET vs POST) прямо в свойства DTO.

```php
use Local\Lib\DTO\Attributes\Mapping\Query;
use Local\Lib\DTO\Attributes\Mapping\Body;

class UpdateUserRequest extends BaseDTO
{
    public function __construct(
        #[Query('user_id')]
        public readonly int $id, // Возьмется строго из GET

        #[Body]
        public readonly string $email // Возьмется строго из POST (тела запроса)
    ) {}
}

// В вашем контроллере, наследуемом от AbstractController:
// $dto = $this->resolveDto(UpdateUserRequest::class, $request->query(), $request->body());

```

---

## 🛠 Экспорт схем (Dev Tools)

Библиотека содержит инструменты для генерации JSON-схем, которые можно использовать для валидации на клиенте или документирования API (Swagger/OpenAPI).

```php
use Local\Lib\DTO\Dev\DTOGenerator;
use Local\Lib\DTO\Schema\DTOSchemaManager;

// Генерация схемы по классу DTO
$manager = new DTOSchemaManager(new SchemaExporter(), new SchemaImporter(), new SchemaValidator());
$manager->exportTo('path/to/schema.json', UserDTO::class);

```

---

*Developed with strict architectural standards (Design-Time vs Run-Time separation, SOLID).*