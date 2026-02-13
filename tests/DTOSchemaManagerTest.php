<?php

namespace Tests\Local\Lib\DTO;

use PHPUnit\Framework\TestCase;
use Local\Lib\DTO\Schema\SchemaValidator;
use Local\Lib\DTO\Schema\SchemaExporter;
use Local\Lib\DTO\Schema\SchemaImporter;
use Local\Lib\DTO\Schema\DTOSchemaManager;
// Импорт класса фикстуры
use Tests\Local\Lib\DTO\Fixtures\ExportTestDTO;

class DTOSchemaManagerTest extends TestCase
{
    private DTOSchemaManager $manager;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Ручное подключение класса фикстуры, так как он находится вне зоны видимости основного autoloader
        $fixtureFile = __DIR__ . '/fixtures/ExportTestDTO.php';
        if (file_exists($fixtureFile)) {
            require_once $fixtureFile;
        }

        $validator = new \Local\Lib\DTO\Schema\SchemaValidator();
        $exporter = new \Local\Lib\DTO\Schema\SchemaExporter();
        $importer = new \Local\Lib\DTO\Schema\SchemaImporter('Tests\Local\Lib\DTO\Generated');

        $this->manager = new \Local\Lib\DTO\Schema\DTOSchemaManager($validator, $exporter, $importer);

        // Подготовка временной директории для генерации файлов
        $this->tempDir = __DIR__ . '/temp_schema_test';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) unlink($file);
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testExportToFile(): void
    {
        $outFile = $this->tempDir . '/export_test.json';

        // Теперь класс точно существует благодаря require_once в setUp
        $result = $this->manager->exportToFile(ExportTestDTO::class, $outFile);

        $this->assertTrue($result);
        $this->assertFileExists($outFile);

        $json = file_get_contents($outFile);
        $data = json_decode($json, true);

        $this->assertSame('ExportTestDTO', $data['name']);
        $this->assertSame('Fixtures', $data['module']);
    }

    /**
     * Тестирование импорта (JSON -> PHP Code)
     */
    public function testImportFromFile(): void
    {
        $fixturePath = __DIR__ . '/fixtures/schema_valid.json';

        // Импортируем
        $result = $this->manager->importFromFile($fixturePath, $this->tempDir);

        $this->assertTrue($result);

        // Проверяем, что файл создан (имя в нижнем регистре по конвенции)
        $expectedPhpFile = $this->tempDir . '/generateduserdto.php';
        $this->assertFileExists($expectedPhpFile);

        $phpCode = file_get_contents($expectedPhpFile);

        // Проверка генерации кода (без предсказаний, строго 1 в 1)
        $this->assertStringContainsString('namespace Tests\Local\Lib\DTO\Generated\Models;', $phpCode);
        $this->assertStringContainsString('class GeneratedUserDTO extends BaseDTO', $phpCode);

        // Теперь генератор корректно оставляет $id без дефолтного null, так как isNullable = false
        $this->assertStringContainsString('public int $id;', $phpCode);

        // А вот isActive имеет isNullable = true и default = true
        $this->assertStringContainsString('public ?bool $isActive = true;', $phpCode);

        // Проверка генерации атрибутов и дефолтных массивов
        $this->assertStringContainsString('#[Cast(string::class)]', $phpCode);
        $this->assertStringContainsString('public array $tags = [];', $phpCode);
    }

    public function testValidatorRejectsInvalidSchema(): void
    {
        $invalidJsonPath = $this->tempDir . '/invalid.json';
        file_put_contents($invalidJsonPath, json_encode([
            "name" => "BadDTO",
            "properties" => []
        ]));

        $this->expectException(\InvalidArgumentException::class);
        // Сообщение должно совпадать с тем, что выводит SchemaValidator
        $this->expectExceptionMessage("Missing required key 'module'");

        $this->manager->importFromFile($invalidJsonPath, $this->tempDir);
    }
}