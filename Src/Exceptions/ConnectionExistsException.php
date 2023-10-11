<?php declare(strict_types=1);

namespace Temant\DatabaseManager\Exceptions;

class ConnectionExistsException extends \Exception
{
    public function __construct(string $connectionName)
    {
        parent::__construct("Connection $connectionName already exists!");
    }
}