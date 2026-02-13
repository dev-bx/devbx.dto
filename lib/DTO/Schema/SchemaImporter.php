<?php

namespace Local\Lib\DTO\Schema;

class SchemaImporter implements SchemaImporterInterface
{
    /**
     * @param string $baseNamespace Базовый неймспейс проекта, к которому будут прибавляться модули.
     */
    public function __construct(
        private string $baseNamespace = 'Local\Lib\DTO'
    ) {}

    /**
     * Генерирует PHP-код класса на основе провалидированной схемы.
     */
    public function generateCode(array $schemaData): string
    {
        $className = $schemaData['name'];
        $module = $schemaData['module'];
        $namespace = $this->baseNamespace . '\\' . $module;

        $extends = $schemaData['extends'] ?? 'BaseDTO';

        $needsCastImport = false;
        $propertiesCode = [];

        foreach ($schemaData['properties'] as $propName => $propDef) {
            $propertiesCode[] = $this->generatePropertyCode($propName, $propDef, $needsCastImport);
        }

        $usesCode = $this->generateUsesCode($schemaData, $needsCastImport);
        $classDocCode = $this->generateDocBlock($schemaData['description'] ?? null);

        $code = "<?php\n\n";
        $code .= "namespace {$namespace};\n\n";

        if (!empty($usesCode)) {
            $code .= $usesCode . "\n\n";
        }

        if ($classDocCode) {
            $code .= $classDocCode . "\n";
        }

        $code .= "class {$className}";
        if ($extends) {
            $code .= " extends {$extends}";
        }
        $code .= "\n{\n";

        $code .= implode("\n", $propertiesCode);

        $code .= "}\n";

        return $code;
    }

    /**
     * Генерирует код для конкретного свойства.
     */
    private function generatePropertyCode(string $propName, array $propDef, bool &$needsCastImport): string
    {
        $lines = [];

        // 1. DocBlock
        if (!empty($propDef['description'])) {
            $lines[] = $this->generateDocBlock($propDef['description'], 4);
        }

        // 2. Attributes (Cast для массивов объектов)
        $isCollection = in_array('collection', $propDef['type'], true);
        $isArray = in_array('array', $propDef['type'], true);

        if ($isArray && !empty($propDef['items'])) {
            $lines[] = "    #[Cast({$propDef['items']}::class)]";
            $needsCastImport = true;
        }

        // 3. Types
        $phpTypes = [];
        foreach ($propDef['type'] as $agnosticType) {
            $phpTypes[] = $this->mapAgnosticTypeToPhp($agnosticType, $propDef['items'] ?? null);
        }

        $isNullable = $propDef['isNullable'] ?? false;
        if ($isNullable && !in_array('mixed', $phpTypes, true)) {
            if (count($phpTypes) === 1) {
                $typeStr = '?' . $phpTypes[0];
            } else {
                $phpTypes[] = 'null';
                $typeStr = implode('|', $phpTypes);
            }
        } else {
            $typeStr = implode('|', $phpTypes);
        }

        // 4. Default Value
        $defaultStr = '';
        if (array_key_exists('default', $propDef)) {
            $defaultStr = $this->generateDefaultValueCode($propDef, $phpTypes[0]);
        }

        // 5. Assembling the property
        $lines[] = "    public {$typeStr} \${$propName}{$defaultStr};";

        return implode("\n", $lines) . "\n";
    }

    /**
     * Конвертирует абстрактные типы схемы в PHP типы.
     */
    private function mapAgnosticTypeToPhp(string $agnosticType, ?string $itemsType): string
    {
        return match ($agnosticType) {
            'integer' => 'int',
            'number' => 'float',
            'boolean' => 'bool',
            'collection' => $itemsType ? $itemsType . 'Collection' : 'BaseCollection',
            'any' => 'mixed',
            default => $agnosticType // string, array, или имена классов/Enums
        };
    }

    /**
     * Формирует строковое представление значения по умолчанию.
     */
    private function generateDefaultValueCode(array $propDef, string $primaryPhpType): string
    {
        $val = $propDef['default'];
        $isEnum = $propDef['isEnum'] ?? false;
        $isCollection = in_array('collection', $propDef['type'], true);
        $isNullable = $propDef['isNullable'] ?? false;

        if ($val === null) {
            // Если тип не позволяет null, мы не имеем права писать "= null"
            // (это вызовет Fatal Error в PHP для строгих типов вроде int).
            // Оставляем свойство неинициализированным (uninitialized).
            return $isNullable ? ' = null' : '';
        }

        if ($isEnum) {
            // Для Enum в схеме хранится имя константы (например, "Active")
            return " = {$primaryPhpType}::{$val}";
        }

        if ($isCollection) {
            // Если коллекция по умолчанию пустая, инициализируем инстанс
            return " = new {$primaryPhpType}()";
        }

        if (is_string($val)) {
            return " = '" . addslashes($val) . "'";
        }

        if (is_bool($val)) {
            return $val ? ' = true' : ' = false';
        }

        if (is_array($val)) {
            return ' = []';
        }

        return " = {$val}";
    }

    /**
     * Генерирует блок `use` на основе импортов из схемы.
     */
    private function generateUsesCode(array $schemaData, bool $needsCastImport): string
    {
        $uses = [];
        $currentModule = $schemaData['module'];

        // Всегда импортируем базовые классы, если они находятся не в текущем модуле
        if ($schemaData['extends'] === 'BaseDTO' && $currentModule !== '') {
            $uses[] = "use {$this->baseNamespace}\\BaseDTO;";
        }

        if ($needsCastImport) {
            $uses[] = "use Local\\Lib\\DTO\\Attributes\\Cast;";
        }

        if (!empty($schemaData['imports'])) {
            foreach ($schemaData['imports'] as $import) {
                // Импортируем только если класс лежит в другом модуле
                if ($import['module'] !== $currentModule) {
                    $fqcn = $this->baseNamespace . '\\' . $import['module'] . '\\' . $import['name'];
                    $uses[] = "use {$fqcn};";
                }
            }
        }

        if (empty($uses)) {
            return '';
        }

        $uses = array_unique($uses);
        sort($uses); // Сортируем импорты по алфавиту для красоты кода

        return implode("\n", $uses);
    }

    /**
     * Оборачивает текст описания в PHPDoc.
     */
    private function generateDocBlock(?string $description, int $indent = 0): ?string
    {
        if (empty($description)) {
            return null;
        }

        $spaces = str_repeat(' ', $indent);
        $lines = explode("\n", $description);

        $doc = $spaces . "/**\n";
        foreach ($lines as $line) {
            $doc .= $spaces . " * " . trim($line) . "\n";
        }
        $doc .= $spaces . " */";

        return $doc;
    }
}