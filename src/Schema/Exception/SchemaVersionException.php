<?php

namespace DevBX\DTO\Schema\Exception;

class SchemaVersionException extends \RuntimeException
{
    public function __construct(string $expected, string $actual)
    {
        parent::__construct(
            sprintf('Schema version mismatch: expected major version "%s", got "%s"', $expected, $actual)
        );
    }
}
