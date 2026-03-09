<?php

namespace DevBX\DTO;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use ArrayIterator;
use ReflectionClass;
use DevBX\DTO\Attributes\CollectionType;

/**
 * Базовый класс для коллекций DTO.
 * Обеспечивает строгую типизацию и методы поиска/фильтрации.
 *
 * @template T of BaseDTO
 * @implements IteratorAggregate<int, T>
 * @implements ArrayAccess<int, T>
 * @phpstan-consistent-constructor
 */
class BaseCollection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<int, T> */
    protected array $items = [];

    /** @var array<string, string|null> Внутренний кэш разрешенных типов [ClassName => ItemClass] */
    protected static array $typeCache = [];

    /**
     * @param array<int, T> $items
     */
    public function __construct(array $items = [])
    {
        // Валидация типов при создании
        foreach ($items as $item) {
            $this->validateType($item);
        }
        $this->items = array_values($items);
    }

    /**
     * Проверяет, что элемент является наследником BaseDTO и соответствует типу коллекции.
     */
    protected function validateType(mixed $item): void
    {
        if (!($item instanceof BaseDTO)) {
            throw new \InvalidArgumentException(
                sprintf('Collection accepts only instances of BaseDTO. Got: %s', get_debug_type($item))
            );
        }

        $expectedClass = $this->getItemClass();
        if ($expectedClass !== null && !($item instanceof $expectedClass)) {
            throw new \InvalidArgumentException(
                sprintf('This collection accepts only instances of %s. Got: %s', $expectedClass, get_debug_type($item))
            );
        }
    }

    /**
     * Получает ожидаемый класс элементов коллекции на основе атрибута #[CollectionType].
     * Использует статическое кэширование для максимальной производительности.
     */
    protected function getItemClass(): ?string
    {
        $staticClass = static::class;

        if (!array_key_exists($staticClass, self::$typeCache)) {
            $reflection = new ReflectionClass($staticClass);
            $attributes = $reflection->getAttributes(CollectionType::class);

            if (!empty($attributes)) {
                self::$typeCache[$staticClass] = $attributes[0]->newInstance()->className;
            } else {
                self::$typeCache[$staticClass] = null;
            }
        }

        return self::$typeCache[$staticClass];
    }

    /**
     * Добавить существующий элемент в коллекцию.
     * Возвращает саму коллекцию для поддержки цепочек вызовов (Fluent Interface).
     *
     * @param BaseDTO $item
     * @return static
     */
    public function add(BaseDTO $item): static
    {
        $this->validateType($item);
        $this->items[] = $item;

        return $this;
    }

    /**
     * Создать новый элемент (опционально из массива), добавить его в коллекцию и вернуть созданный объект.
     *
     * @param array $data Опциональный массив данных для гидратации
     * @param bool $strict Строгий режим при гидратации из массива
     * @return T Возвращает созданный экземпляр элемента коллекции
     * @throws \RuntimeException Если класс элемента коллекции не определен атрибутом #[CollectionType]
     */
    public function create(array $data = [], bool $strict = false): BaseDTO
    {
        $className = $this->getItemClass();

        if ($className === null) {
            throw new \RuntimeException(sprintf('Cannot create item: CollectionType attribute is missing in %s', static::class));
        }

        if (empty($data)) {
            // Быстрая инициализация пустого объекта
            $item = new $className();
        } else {
            // Гидратация через схему DTO
            $item = $className::fromArray($data, $strict);
        }

        // Используем строгий метод add для помещения в коллекцию
        $this->add($item);

        // Возвращаем именно созданный элемент
        return $item;
    }

    /**
     * Создает DTO из переданного массива и добавляет его в коллекцию.
     * * @param array $data Массив данных для гидратации DTO
     * @param bool $strict Строгий режим маппинга свойств
     * @return static
     * @throws \RuntimeException Если класс элемента коллекции не определен атрибутом #[CollectionType]
     */
    public function addFromArray(array $data, bool $strict = false): static
    {
        $className = $this->getItemClass();

        if ($className === null) {
            throw new \RuntimeException(sprintf('Cannot hydrate array: CollectionType attribute is missing in %s', static::class));
        }

        $this->add($className::fromArray($data, $strict));

        return $this;
    }

    /**
     * Массовое добавление элементов (поддерживает как готовые DTO, так и сырые массивы).
     * * @param iterable $items Массив массивов или объектов DTO
     * @param bool $strict Строгий режим маппинга для массивов
     * @return static
     * @throws \InvalidArgumentException Если элемент не является массивом или DTO
     * @throws \RuntimeException Если передан массив, но тип коллекции не задан
     */
    public function addMany(iterable $items, bool $strict = false): static
    {
        $className = $this->getItemClass();

        foreach ($items as $item) {
            if ($item instanceof BaseDTO) {
                // Если это уже объект DTO, пропускаем через стандартную валидацию
                $this->add($item);
            } elseif (is_iterable($item)) {
                // Если это сырой массив или итерируемый объект (например, ReadOnlyArrayProxy), гидратируем его
                if ($className === null) {
                    throw new \RuntimeException(sprintf('Cannot hydrate array: CollectionType attribute is missing in %s', static::class));
                }

                // Приводим объект (IteratorAggregate/Traversable) к нативному массиву
                $itemArray = is_array($item) ? $item : iterator_to_array($item);

                $this->add($className::fromArray($itemArray, $strict));
            } else {
                throw new \InvalidArgumentException(
                    sprintf('Items must be arrays, iterable objects, or instances of BaseDTO. Got: %s', get_debug_type($item))
                );
            }
        }

        return $this;
    }

    // --- Поиск и Фильтрация ---

    /**
     * Найти первый элемент, удовлетворяющий условию.
     * @param callable(T, int|string): bool $callback
     * @return T|null
     */
    public function find(callable $callback): ?BaseDTO
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Найти все элементы, удовлетворяющие условию.
     * Возвращает НОВУЮ коллекцию.
     * @param callable(T, int|string): bool $callback
     * @return static
     */
    public function filter(callable $callback): static
    {
        $filtered = array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH);
        return new static(array_values($filtered));
    }

    /**
     * Отвергнуть элементы, удовлетворяющие условию (обратный filter).
     * @param callable(T): bool $callback
     * @return static
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn($item) => !$callback($item));
    }

    /**
     * Фильтрует коллекцию по значению свойства с поддержкой операторов сравнения.
     * Если передано 2 аргумента, подразумевается оператор '='.
     * * @param string $property Имя свойства
     * @param mixed $operator Оператор (или значение)
     * @param mixed $value Значение для сравнения
     * @return static
     */
    public function where(string $property, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->filter(function ($item) use ($property, $operator, $value) {
            $itemValue = $item->{$property} ?? null;

            return match ($operator) {
                '=' => $itemValue == $value,
                '===' => $itemValue === $value,
                '!=' => $itemValue != $value,
                '!==' => $itemValue !== $value,
                '>' => $itemValue > $value,
                '>=' => $itemValue >= $value,
                '<' => $itemValue < $value,
                '<=' => $itemValue <= $value,
                default => $itemValue == $value,
            };
        });
    }

    // --- Трансформация и Агрегация ---

    /**
     * Возвращает новую коллекцию, содержащую срез элементов.
     * Не модифицирует текущую коллекцию.
     *
     * @param int $offset Смещение (если отрицательное - отсчет с конца)
     * @param int|null $length Количество элементов (если null - до конца)
     * @return static<T>
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    /**
     * Возвращает новую коллекцию с заданным количеством элементов.
     * Если передано отрицательное число, берет элементы с конца коллекции.
     *
     * @param int $limit Количество элементов
     * @return static<T>
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    /**
     * Возвращает новую коллекцию, пропустив заданное количество элементов с начала.
     *
     * @param int $count Количество элементов для пропуска
     * @return static<T>
     */
    public function skip(int $count): static
    {
        return $this->slice($count);
    }

    /**
     * Проверяет, пуста ли коллекция.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Проверяет, содержит ли коллекция элементы.
     */
    public function isNotEmpty(): bool
    {
        return !empty($this->items);
    }

    /**
     * Итеративно уменьшает коллекцию к единственному значению с помощью callback-функции.
     * * @param callable(mixed, T): mixed $callback
     * @param mixed $initial
     * @return mixed
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Получить массив значений конкретного свойства.
     * Пример: $collection->column('id') -> [1, 2, 3]
     */
    public function column(string $propertyName): array
    {
        return array_map(function ($item) use ($propertyName) {
            return $item->{$propertyName} ?? null;
        }, $this->items);
    }

    /**
     * Получить список значений указанного свойства (опционально проиндексированный другим свойством).
     * * @param string $valueProperty Свойство для извлечения значений
     * @param string|null $keyProperty Свойство для ключей результирующего массива
     * @return array
     */
    public function pluck(string $valueProperty, ?string $keyProperty = null): array
    {
        $result = [];
        foreach ($this->items as $item) {
            $value = $item->{$valueProperty} ?? null;

            if ($keyProperty === null) {
                $result[] = $value;
            } else {
                $key = $item->{$keyProperty} ?? null;
                $result[(string)$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Возвращает ассоциативный массив, где ключами являются значения указанного свойства или результат callback.
     * При совпадении ключей последние элементы перезаписывают предыдущие.
     * * @param string|callable $keyBy Имя свойства или callback-функция
     * @return array<string|int, T>
     */
    public function keyBy(string|callable $keyBy): array
    {
        $result = [];
        $isCallable = is_callable($keyBy);

        foreach ($this->items as $item) {
            $key = $isCallable ? $keyBy($item) : ($item->{$keyBy} ?? null);
            $result[(string)$key] = $item;
        }
        return $result;
    }

    /**
     * Группирует элементы коллекции по указанному свойству или результату callback.
     * Возвращает ассоциативный массив, где значения — это коллекции (static).
     * * @param string|callable $groupBy Имя свойства или callback-функция
     * @return array<string|int, static>
     */
    public function groupBy(string|callable $groupBy): array
    {
        $groups = [];
        $isCallable = is_callable($groupBy);

        foreach ($this->items as $item) {
            $key = $isCallable ? $groupBy($item) : ($item->{$groupBy} ?? null);
            $stringKey = (string)$key;

            if (!isset($groups[$stringKey])) {
                $groups[$stringKey] = [];
            }
            $groups[$stringKey][] = $item;
        }

        $result = [];
        foreach ($groups as $key => $items) {
            $result[$key] = new static($items);
        }

        return $result;
    }

    /**
     * Применить функцию ко всем элементам и вернуть массив результатов.
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->items);
    }

    /**
     * Получить первый элемент коллекции.
     * @return T|null
     */
    public function first(): ?BaseDTO
    {
        return $this->items[0] ?? null;
    }

    /**
     * Получить последний элемент коллекции.
     * @return T|null
     */
    public function last(): ?BaseDTO
    {
        if (empty($this->items)) {
            return null;
        }
        return $this->items[count($this->items) - 1];
    }

    /**
     * Сортировка коллекции по свойству.
     * @param string $property Имя свойства DTO
     * @param bool $descending True для сортировки по убыванию
     * @return static Новая отсортированная коллекция
     */
    public function sortBy(string $property, bool $descending = false): static
    {
        $items = $this->items;

        usort($items, function ($a, $b) use ($property, $descending) {
            $valA = $a->{$property} ?? null;
            $valB = $b->{$property} ?? null;

            if ($valA == $valB) return 0;

            $result = ($valA < $valB) ? -1 : 1;
            return $descending ? -$result : $result;
        });

        return new static($items);
    }

    /**
     * Сортировка коллекции с использованием пользовательской callback-функции.
     * Возвращает новую отсортированную коллекцию.
     *
     * @param callable(T, T): int $callback Функция сравнения (должна возвращать целое число < 0, 0 или > 0)
     * @return static
     */
    public function sort(callable $callback): static
    {
        $items = $this->items;

        usort($items, $callback);

        return new static($items);
    }

    // --- Экспорт ---

    /**
     * Преобразование в массив массивов.
     */
    public function toArray(string $format = BaseDTO::FORMAT_CAMEL): array
    {
        return array_map(fn(BaseDTO $dto) => $dto->toArray($format), $this->items);
    }

    // --- Реализация интерфейсов PHP ---

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->add($value);
        } else {
            $this->validateType($value);
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
