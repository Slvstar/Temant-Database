<?php declare(strict_types=1);

namespace Temant\DatabaseManager\Enums {
    /**
     * An enumeration representing ORDER BY directions.
     */
    enum DirectionsEnum: string
    {
        case ASC = 'ASC';
        case DESC = 'DESC';
    }
}