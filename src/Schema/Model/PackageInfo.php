<?php

namespace DevBX\DTO\Schema\Model;

class PackageInfo
{
    public readonly string $name;
    public readonly string $version;

    /** @var array<string, array<string, string>> Language-specific code generation hints */
    public readonly array $codeGen;

    /**
     * @param array<string, array<string, string>> $codeGen
     */
    public function __construct(string $name, string $version, array $codeGen = [])
    {
        $this->name = $name;
        $this->version = $version;
        $this->codeGen = $codeGen;
    }

    public function getPhpNamespace(): ?string
    {
        return $this->codeGen['php']['namespace'] ?? null;
    }

    public function getTypeScriptModule(): ?string
    {
        return $this->codeGen['typescript']['module'] ?? null;
    }

    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'version' => $this->version,
        ];

        if (!empty($this->codeGen)) $data['codeGen'] = $this->codeGen;

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            version: $data['version'],
            codeGen: $data['codeGen'] ?? []
        );
    }
}
