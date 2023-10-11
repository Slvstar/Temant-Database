<?php declare(strict_types=1);

namespace Temant\DatabaseManager\Exceptions;

class ConnectionNotFoundException extends \Exception
{
    public function __construct(string $connectionName)
    {
        parent::__construct("Connection $connectionName is not found!");
    }
}