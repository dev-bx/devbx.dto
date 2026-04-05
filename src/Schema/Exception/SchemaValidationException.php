<?php

namespace DevBX\DTO\Schema\Exception;

class SchemaValidationException extends \RuntimeException
{
    /** @var string[] */
    private array $unknownKeys;

    private string $path;

    /**
     * @param string[] $unknownKeys
     */
    public function __construct(array $unknownKeys, string $path)
    {
        $this->unknownKeys = $unknownKeys;
        $this->path = $path;

        parent::__construct(
            sprintf(
                'Unknown keys in schema at "%s": [%s]',
                $path,
                implode(', ', $unknownKeys)
            )
        );
    }

    /** @return string[] */
    public function getUnknownKeys(): array
    {
        return $this->unknownKeys;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
