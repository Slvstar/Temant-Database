<?php declare(strict_types=1);

namespace Temant\DatabaseManager\Enums {
    /**
     * An enumeration representing join types.
     */
    enum JoinTypesEnum: string
    {
        case INNER = 'INNER';
        case RIGHT = 'RIGHT';
        case LEFT = 'LEFT';
    }
}