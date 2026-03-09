<?php

namespace DevBX\DTO\Schema;

use RuntimeException;
use JsonException;

/**
 * Фасад для управления экспортом и импортом DTO схем.
 * Связывает воедино Validator, Exporter и Importer.
 */
class DTOSchemaManager
{
    public function __construct(
        private SchemaValidatorInterface $validator,
        private SchemaExporterInterface $exporter,
        private SchemaImporterInterface $importer
    ) {}

    /**
     * Экспортирует PHP-класс в JSON-файл схемы.
     * * @param class-string $className Полное имя класса (например, 'DevBX\DTO\Models\UserDTO')
     * @param string $filePath Путь для сохранения JSON файла (например, '/path/to/schema/user.json')
     * @return bool True в случае успеха
     * @throws RuntimeException|JsonException
     */
    public function exportToFile(string $className, string $filePath): bool
    {
        // 1. Извлекаем данные схемы через Reflection
        $schemaData = $this->exporter->export($className);

        // 2. Кодируем в JSON с сохранением юникода и красивым форматированием
        $json = json_encode(
            $schemaData,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // 3. Сохраняем файл
        $result = file_put_contents($filePath, $json);

        if ($result === false) {
            throw new RuntimeException("Failed to write schema to {$filePath}");
        }

        return true;
    }

    /**
     * Импортирует JSON-файл схемы и генерирует PHP-класс DTO.
     * * @param string $filePath Путь к JSON файлу схемы
     * @param string $outputDirectory Директория для сохранения сгенерированного PHP-класса
     * @return bool True в случае успеха
     * @throws RuntimeException|JsonException|\InvalidArgumentException
     */
    public function importFromFile(string $filePath, string $outputDirectory): bool
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Schema file not found: {$filePath}");
        }

        // 1. Читаем и декодируем JSON
        $json = file_get_contents($filePath);
        $schemaData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        // 2. Строгая валидация структуры схемы
        $this->validator->validate($schemaData);

        // 3. Генерация PHP-кода
        $phpCode = $this->importer->generateCode($schemaData);

        // 4. Подготовка директории и имени файла (приводим к нижнему регистру согласно стандартам проекта)
        $className = $schemaData['name'];
        $outputDirectory = rtrim($outputDirectory, '/\\');

        if (!is_dir($outputDirectory)) {
            if (!mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
                throw new RuntimeException("Failed to create output directory: {$outputDirectory}");
            }
        }

        $outPath = $outputDirectory . DIRECTORY_SEPARATOR . strtolower($className) . '.php';

        // 5. Сохранение PHP-файла
        $result = file_put_contents($outPath, $phpCode);

        if ($result === false) {
            throw new RuntimeException("Failed to write PHP class to {$outPath}");
        }

        return true;
    }
}