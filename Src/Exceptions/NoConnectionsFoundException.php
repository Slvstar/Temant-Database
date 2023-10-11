<?php declare(strict_types=1);

namespace Temant\DatabaseManager\Exceptions;

class NoConnectionsFoundException extends \Exception
{
    public function __construct()
    {
        parent::__construct("No connections found!");
    }
}