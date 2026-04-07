<?php

namespace Tests\DevBX\DTO;

use PHPUnit\Framework\TestCase;
use DevBX\DTO\Schema\DTOSchemaManager;
use DevBX\DTO\Schema\SchemaExporter;
use DevBX\DTO\Schema\SchemaImporter;
use DevBX\DTO\Schema\SchemaValidator;
use DevBX\DTO\Schema\Model\SchemaDefinition;
use DevBX\DTO\Schema\Exception\SchemaVersionException;
use DevBX\DTO\Schema\Exception\SchemaValidationException;

class DTOSchemaManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/devbx_dto_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // ─── Export ───

    public function testExportProducesValidSchema(): void
    {
        $fixtureDir = __DIR__ . '/fixtures/export';
        $outFile = $this->tempDir . '/schema.json';

        // ExportTestDTO is in Tests\DevBX\DTO\Fixtures namespace
        // Loaded via autoloader or require

        $schema = DTOSchemaManager::export(
            directory: $fixtureDir,
            baseNamespace: 'Tests\\DevBX\\DTO\\Fixtures\\Export',
            outputPath: $outFile,
            packageName: 'test-export'
        );

        $this->assertFileExists($outFile);
        $this->assertInstanceOf(SchemaDefinition::class, $schema);
        $this->assertSame('devbx-dto/1.0', $schema->schema);
        $this->assertSame('test-export', $schema->package->name);

        // Verify ExportTestDTO was exported
        $this->assertArrayHasKey('ExportTestDTO', $schema->types);

        $type = $schema->types['ExportTestDTO'];
        $this->assertSame('ExportTestDTO', $type->name);

        // Check description from PHPDoc
        $this->assertNotNull($type->description);
        $this->assertStringContainsString('тестирования экспортера', $type->description);

        // Check properties
        $this->assertArrayHasKey('id', $type->properties);
        $this->assertSame('int', $type->properties['id']->type);

        $this->assertArrayHasKey('status', $type->properties);
        $this->assertTrue($type->properties['status']->nullable);
        $this->assertSame('active', $type->properties['status']->default);

        $this->assertArrayHasKey('roles', $type->properties);
        $this->assertSame('array', $type->properties['roles']->type);

        // Verify JSON is parseable
        $json = file_get_contents($outFile);
        $this->assertNotFalse($json);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertSame('devbx-dto/1.0', $data['$schema']);
    }

    public function testExportedPropertyDescription(): void
    {
        $fixtureDir = __DIR__ . '/fixtures/export';
        $outFile = $this->tempDir . '/schema.json';

        // Loaded via autoloader or require

        $schema = DTOSchemaManager::export(
            directory: $fixtureDir,
            baseNamespace: 'Tests\\DevBX\\DTO\\Fixtures\\Export',
            outputPath: $outFile,
            packageName: 'test-export'
        );

        $idProp = $schema->types['ExportTestDTO']->properties['id'];
        $this->assertNotNull($idProp->description);
        $this->assertStringContainsString('Внутренний ID', $idProp->description);
    }

    // ─── Import (PHP generation) ───

    public function testImportPhpGeneratesFiles(): void
    {
        $schemaPath = __DIR__ . '/fixtures/schema_valid.json';

        $files = DTOSchemaManager::importPhp(
            schemaPath: $schemaPath,
            targetDir: $this->tempDir
        );

        $this->assertNotEmpty($files);

        // UserDTO.php should be generated
        $userFile = $this->tempDir . DIRECTORY_SEPARATOR . 'UserDTO.php';
        $this->assertFileExists($userFile);

        $code = file_get_contents($userFile);
        $this->assertNotFalse($code);

        // Namespace from codeGen.php.namespace
        $this->assertStringContainsString('namespace Tests\\DevBX\\DTO\\Generated;', $code);

        // Class with description in PHPDoc
        $this->assertStringContainsString('Test user DTO', $code);
        $this->assertStringContainsString('class UserDTO extends BaseDTO', $code);

        // Properties
        $this->assertStringContainsString('public int $id;', $code);
        $this->assertStringContainsString('public ?bool $isActive = true;', $code);
        $this->assertStringContainsString('public array $tags = [];', $code);

        // MapFrom attribute
        $this->assertStringContainsString("#[MapFrom('email_address')]", $code);

        // @method tags
        $this->assertStringContainsString('@method int getId()', $code);
        $this->assertStringContainsString('@method self setId(int $value)', $code);

        // @var for untyped array
        $this->assertStringContainsString('@var array<int|string, mixed>', $code);
    }

    // ─── Validation ───

    public function testValidateAcceptsValidSchema(): void
    {
        $schemaPath = __DIR__ . '/fixtures/schema_valid.json';

        $schema = DTOSchemaManager::validate($schemaPath);
        $this->assertInstanceOf(SchemaDefinition::class, $schema);
        $this->assertArrayHasKey('UserDTO', $schema->types);
    }

    public function testValidateRejectsWrongVersion(): void
    {
        $badSchema = $this->tempDir . '/bad_version.json';
        file_put_contents($badSchema, json_encode([
            '$schema' => 'unknown/2.0',
            'package' => ['name' => 'test', 'version' => '1.0.0'],
        ]));

        $this->expectException(SchemaVersionException::class);
        DTOSchemaManager::validate($badSchema);
    }

    public function testValidateStrictRejectsUnknownKeys(): void
    {
        $badSchema = $this->tempDir . '/unknown_keys.json';
        file_put_contents($badSchema, json_encode([
            '$schema' => 'devbx-dto/1.0',
            'package' => ['name' => 'test', 'version' => '1.0.0'],
            'unknownField' => true,
        ]));

        $this->expectException(SchemaValidationException::class);
        DTOSchemaManager::validate($badSchema, strict: true);
    }

    public function testValidateNonStrictAllowsUnknownKeys(): void
    {
        $schema = $this->tempDir . '/extra_keys.json';
        file_put_contents($schema, json_encode([
            '$schema' => 'devbx-dto/1.0',
            'package' => ['name' => 'test', 'version' => '1.0.0'],
            'unknownField' => true,
        ]));

        $result = DTOSchemaManager::validate($schema, strict: false);
        $this->assertInstanceOf(SchemaDefinition::class, $result);
    }

    // ─── Roundtrip: export → import ───

    public function testRoundtripExportImport(): void
    {
        $fixtureDir = __DIR__ . '/fixtures/export';
        $schemaFile = $this->tempDir . '/roundtrip.json';
        $phpDir = $this->tempDir . '/php';

        // Loaded via autoloader or require

        // Export
        $schema = DTOSchemaManager::export(
            directory: $fixtureDir,
            baseNamespace: 'Tests\\DevBX\\DTO\\Fixtures\\Export',
            outputPath: $schemaFile,
            packageName: 'roundtrip-test'
        );

        $this->assertFileExists($schemaFile);

        // Import back
        $files = DTOSchemaManager::importPhp(
            schemaPath: $schemaFile,
            targetDir: $phpDir
        );

        $this->assertNotEmpty($files);

        // ExportTestDTO should be regenerated
        $dtoFile = $phpDir . DIRECTORY_SEPARATOR . 'ExportTestDTO.php';
        $this->assertFileExists($dtoFile);

        $code = file_get_contents($dtoFile);
        $this->assertNotFalse($code);

        // Verify key elements survived the roundtrip
        $this->assertStringContainsString('class ExportTestDTO extends BaseDTO', $code);
        $this->assertStringContainsString('public int $id;', $code);
        $this->assertStringContainsString("public ?string \$status = 'active';", $code);
        $this->assertStringContainsString('public array $roles = [];', $code);
    }

    // ─── SchemaImporter ───

    public function testImporterFromJson(): void
    {
        $json = json_encode([
            '$schema' => 'devbx-dto/1.0',
            'package' => ['name' => 'inline', 'version' => '0.1.0'],
            'types' => [
                'SimpleDTO' => [
                    'properties' => [
                        'value' => ['type' => 'string'],
                    ],
                ],
            ],
        ]);

        $importer = new SchemaImporter();
        $schema = $importer->fromJson($json);

        $this->assertSame('inline', $schema->package->name);
        $this->assertArrayHasKey('SimpleDTO', $schema->types);
        $this->assertArrayHasKey('value', $schema->types['SimpleDTO']->properties);
    }

    public function testImporterRejectsMissingFile(): void
    {
        $importer = new SchemaImporter();
        $this->expectException(\InvalidArgumentException::class);
        $importer->fromFile('/nonexistent/path.json');
    }
}
