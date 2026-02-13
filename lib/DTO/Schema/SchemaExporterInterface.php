<?php

namespace Local\Lib\DTO\Schema;

/**
 * Интерфейс экспортера PHP-классов в языково-независимую схему.
 */
interface SchemaExporterInterface
{
    /**
     * Анализирует класс DTO и возвращает массив схемы.
     * @param class-string $className Полное имя класса (FQCN)
     * @return array Валидный массив схемы.
     */
    public function export(string $className): array;
}