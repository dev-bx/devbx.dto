<?php

namespace Local\Lib\DTO\Schema;

/**
 * Интерфейс валидатора языково-независимых схем DTO.
 */
interface SchemaValidatorInterface
{
    /**
     * Валидирует структуру массива схемы.
     * @param array $schemaData Декодированный массив схемы (из JSON).
     * @throws \InvalidArgumentException Если схема содержит ошибки.
     */
    public function validate(array $schemaData): void;
}