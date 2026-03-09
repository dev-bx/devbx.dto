<?php

namespace DevBX\DTO\Http;

use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Mapping\Query;
use DevBX\DTO\Attributes\Mapping\Body;
use ReflectionClass;
use ReflectionProperty;
use ReflectionParameter;
use InvalidArgumentException;

abstract class AbstractController
{
    /**
     * Создает и гидратирует DTO на основе независимых массивов данных запроса.
     * Не привязан к PSR-7, что позволяет легко использовать его в Bitrix или CLI.
     *
     * @template T of BaseDTO
     * @param class-string<T> $dtoClass
     * @param array $query Массив GET-параметров
     * @param array $body Массив параметров тела запроса (POST/JSON)
     * @return T
     * @throws InvalidArgumentException
     */
    protected function resolveDto(string $dtoClass, array $query, array $body): BaseDTO
    {
        if (!is_subclass_of($dtoClass, BaseDTO::class)) {
            throw new InvalidArgumentException("Class {$dtoClass} must extend BaseDTO");
        }

        $reflection = new ReflectionClass($dtoClass);

        // По умолчанию сливаем массивы (Body имеет приоритет над Query при совпадении ключей)
        $resolvedData = array_merge($query, $body);

        // 1. Анализируем публичные свойства
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $this->applyMapping($prop, $query, $body, $resolvedData);
        }

        // 2. Анализируем параметры конструктора (для поддержки Readonly DTO)
        $constructor = $reflection->getConstructor();
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $this->applyMapping($param, $query, $body, $resolvedData);
            }
        }

        // Передаем подготовленный "плоский" массив в наш обновленный BaseDTO
        return $dtoClass::fromArray($resolvedData);
    }

    /**
     * Извлекает данные согласно атрибутам #[Query] или #[Body] и фиксирует их под именем свойства.
     */
    private function applyMapping(ReflectionProperty|ReflectionParameter $reflector, array $query, array $body, array &$resolvedData): void
    {
        $name = $reflector->getName();

        $queryAttr = $reflector->getAttributes(Query::class);
        $bodyAttr = $reflector->getAttributes(Body::class);

        if (!empty($queryAttr)) {
            $key = $queryAttr[0]->newInstance()->key ?? $name;
            if (array_key_exists($key, $query)) {
                // Жестко прописываем под именем свойства, чтобы BaseDTO::fromArray() точно его нашел
                $resolvedData[$name] = $query[$key];
            }
        } elseif (!empty($bodyAttr)) {
            $key = $bodyAttr[0]->newInstance()->key ?? $name;
            if (array_key_exists($key, $body)) {
                $resolvedData[$name] = $body[$key];
            }
        }
    }
}