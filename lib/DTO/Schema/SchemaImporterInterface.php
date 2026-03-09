<?php

namespace DevBX\DTO\Schema;

/**
 * Интерфейс генератора PHP кода на основе языково-независимой схемы.
 */
interface SchemaImporterInterface
{
    /**
     * Генерирует PHP-код класса на основе провалидированной схемы.
     * @param array $schemaData Валидный массив схемы.
     * @return string Готовый PHP-код.
     */
    public function generateCode(array $schemaData): string;
}