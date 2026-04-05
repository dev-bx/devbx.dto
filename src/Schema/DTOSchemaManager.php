<?php

namespace DevBX\DTO\Schema;

use DevBX\DTO\Schema\Model\SchemaDefinition;
use DevBX\DTO\Schema\Generator\PhpGenerator;
use DevBX\DTO\Schema\Generator\TypeScriptGenerator;

class DTOSchemaManager
{
    /**
     * Exports PHP DTO classes from a directory to a JSON schema file.
     *
     * @param string $directory Directory containing PHP DTO classes
     * @param string $baseNamespace Base namespace of the DTO classes
     * @param string $outputPath Path to write the JSON schema file
     * @param string $packageName Package name
     * @param string $packageVersion Package version
     * @return SchemaDefinition The exported schema
     */
    public static function export(
        string $directory,
        string $baseNamespace,
        string $outputPath,
        string $packageName,
        string $packageVersion = '1.0.0'
    ): SchemaDefinition {
        $exporter = new SchemaExporter($baseNamespace);
        $schema = $exporter->export($directory, $packageName, $packageVersion);

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents($outputPath, $schema->toJson());
        if ($result === false) {
            throw new \RuntimeException("Failed to write schema to: {$outputPath}");
        }

        return $schema;
    }

    /**
     * Imports a JSON schema and generates PHP class files.
     *
     * @param string $schemaPath Path to the JSON schema file
     * @param string $targetDir Directory to write generated PHP files
     * @param bool $strict Strict mode for schema validation (default: true)
     * @return string[] List of generated file paths
     */
    public static function importPhp(
        string $schemaPath,
        string $targetDir,
        bool $strict = true
    ): array {
        $importer = new SchemaImporter();
        $schema = $importer->fromFile($schemaPath, $strict);

        $generator = new PhpGenerator($schema);
        return $generator->generate($targetDir);
    }

    /**
     * Imports a JSON schema and generates TypeScript files.
     *
     * @param string $schemaPath Path to the JSON schema file
     * @param string $targetDir Directory to write generated TypeScript files
     * @param bool $strict Strict mode for schema validation (default: true)
     * @return string[] List of generated file paths
     */
    public static function generateTypeScript(
        string $schemaPath,
        string $targetDir,
        bool $strict = true
    ): array {
        $importer = new SchemaImporter();
        $schema = $importer->fromFile($schemaPath, $strict);

        $generator = new TypeScriptGenerator($schema);
        return $generator->generate($targetDir);
    }

    /**
     * Validates a JSON schema file.
     *
     * @param string $schemaPath Path to the JSON schema file
     * @param bool $strict Strict mode (default: true)
     * @return SchemaDefinition The parsed schema if valid
     * @throws Exception\SchemaVersionException
     * @throws Exception\SchemaValidationException
     */
    public static function validate(string $schemaPath, bool $strict = true): SchemaDefinition
    {
        $importer = new SchemaImporter();
        return $importer->fromFile($schemaPath, $strict);
    }

    /**
     * Loads a schema from file into an in-memory model.
     *
     * @param string $schemaPath Path to the JSON schema file
     * @param bool $strict Strict mode (default: true)
     * @return SchemaDefinition
     */
    public static function load(string $schemaPath, bool $strict = true): SchemaDefinition
    {
        $importer = new SchemaImporter();
        return $importer->fromFile($schemaPath, $strict);
    }
}
