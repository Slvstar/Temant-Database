<?php declare(strict_types=1);

namespace Temant\DatabaseManager {
    use Temant\DatabaseManager\Enums\DirectionsEnum;
    use Temant\DatabaseManager\Enums\JoinTypesEnum;

    class DatabaseManager extends QueryBuilder
    {
        /**
         * The singleton instance of the DatabaseConnection class.
         *
         * @var DatabaseManager
         */
        private static DatabaseManager $_instance;

        public function __construct(\mysqli $mysqli)
        {
            parent::__construct($mysqli);
            self::$_instance = $this;
        }

        /**
         * Gets the singleton instance of the DatabaseConnection class.
         *
         * @return DatabaseManager The singleton instance.
         */
        public static function getInstance(): self
        {
            return self::$_instance;
        }

        /**
         * Get the queries tracing information.
         *
         * @return array Query tracing information.
         */
        public function getTrace(): array
        {
            return $this->trace;
        }

        /**
         * This methods returns the ID of the last inserted item
         *
         * @return int|string The last inserted item ID.
         */
        public function getInsertId(): int|string
        {
            return $this->mysqli()->insert_id;
        }

        /**
         * A convenient SELECT * function.
         *
         * @param string $tableName The name of the database table to work with.
         * @param mixed $numRows Array($offset, $count) or int($count)
         * @param string|array $columns Desired columns
         *
         * @return array|string Contains the returned rows from the select query.
         */
        public function select(string $tableName, mixed $numRows = null, string|array $columns = '*'): array|string
        {
            return parent::doSelect($tableName, $numRows, $columns);
        }

        /**
         * A convenient SELECT * function to select one record.
         *
         * @param string $tableName The name of the database table to work with.
         * @param string|array $columns Desired columns
         *
         * @return mixed Contains the returned rows from the select query.
         */
        public function selectOne(string $tableName, string|array $columns = '*'): mixed
        {
            return is_array($res = $this->select($tableName, 1, $columns)) && isset($res[0])
                ? $res[0]
                : $res;
        }

        /**
         * A convenient SELECT COLUMN function to select a single column value from one row
         *
         * @param string $tableName The name of the database table to work with.
         * @param string $column The desired column
         * @param int $limit Limit of rows to select. Use null for unlimited..1 by default
         *
         * @return mixed Contains the value of a returned column / array of values
         */
        public function selectValue(string $tableName, string $columnName, int $limit = 1): mixed
        {
            $result = array_map(fn($item): mixed =>
                $item[$columnName], $this->select($tableName, $limit, $columnName));
            return $limit === 1 ? $result[0] : $result;
        }

        /**
         * Update query. Be sure to first call the "where" method.
         *
         * @param string $tableName The name of the database table to work with.
         * @param array $tableData Array of data to update the desired row.
         * @param int $numRows Limit on the number of rows that can be updated.
         *
         * @return bool
         */
        public function update(string $tableName, $tableData, $numRows = null): bool
        {
            return parent::doUpdate($tableName, $tableData, $numRows);
        }

        /**
         * Delete query. Call the "where" method first.
         *
         * @param string $tableName The name of the database table to work with.
         * @param int|array $numRows Array to define SQL limit in format Array ($offset, $count)
         *                               or only $count
         *
         * @return bool Indicates success. 0 or 1.
         */
        public function delete(string $tableName, mixed $numRows = null): bool
        {
            return parent::doDelete($tableName, $numRows);
        }

        /**
         * Specify an ORDER BY statement for the SQL query.
         *
         * @param string $fieldName The name of the database field to order by.
         * @param DirectionsEnum $direction The order direction (ASC or DESC).
         * 
         * @return DatabaseManager
         */
        public function orderBy(string $fieldName, DirectionsEnum $direction = DirectionsEnum::ASC): self
        {
            return parent::doOrderBy($fieldName, $direction);
        }

        /**
         * Specify a GROUP BY statement for the SQL query.
         *
         * @param string $fieldName The name of the database field to group by.
         *
         * @return DatabaseManager
         */
        public function groupBy(string $fieldName): self
        {
            return parent::doGroupBy($fieldName);
        }

        /**
         * Insert a new row into the database table.
         *
         * @param string $tableName The name of the table.
         * @param array $insertData Associative array containing data for inserting into the DB.
         *
         * @return int The inserted row's ID if applicable.
         */
        public function insert(string $tableName, array $insertData): int
        {
            return parent::doInsert($tableName, $insertData);
        }

        /**
         * Replace a row in the database table.
         *
         * @param string $tableName The name of the table.
         * @param array $insertData Associative array containing data for replacing into the DB.
         *
         * @return int The inserted row's ID if applicable.
         */
        public function replace(string $tableName, array $insertData): int
        {
            return parent::doReplace($tableName, $insertData);
        }

        /**
         * This method allows you to specify multiple (method chaining optional) AND WHERE statements for SQL queries.
         *
         * @param string $whereProp The name of the database field.
         * @param mixed $whereValue The value of the database field.
         * @param string $operator Comparison operator. Default is =
         * @param string $condition Condition of where statement (OR, AND)
         *
         * @return DatabaseManager
         */
        public function where(string $whereProp, mixed $whereValue = 'DBNULL', string $operator = '=', string $condition = 'AND'): self
        {
            return parent::doWhere($whereProp, $whereValue, $operator, $condition);
        }

        /**
         * This method allows you to specify multiple (method chaining optional) OR WHERE statements for SQL queries.
         *
         * @param string $whereProp The name of the database field.
         * @param mixed $whereValue The value of the database field.
         * @param string $operator Comparison operator. Default is =
         * 
         * @return DatabaseManager
         */
        public function orWhere(string $whereProp, mixed $whereValue = 'DBNULL', string $operator = '='): self
        {
            return $this->where($whereProp, $whereValue, $operator, 'OR');
        }

        /**
         * This method allows you to specify multiple (method chaining optional) AND HAVING statements for SQL queries.
         *
         * @param string $fieldName The name of the database field.
         * @param mixed $fieldValue The value of the database field.
         * @param string $operator Comparison operator. Default is =
         *
         * @param string $condition
         *
         * @return DatabaseManager
         */
        public function having(string $fieldName, mixed $fieldValue = 'DBNULL', string $operator = '=', string $condition = 'AND'): static
        {
            return parent::doHaving($fieldName, $fieldValue, $operator, $condition);
        }

        /**
         * Add an OR HAVING statement to the query.
         *
         * @param string $fieldName The name of the database field.
         * @param mixed $fieldValue The value of the database field.
         * @param string $operator Comparison operator. Default is =.
         *
         * @return DatabaseManager
         */
        public function orHaving(string $fieldName, mixed $fieldValue = null, string $operator = null): self
        {
            return $this->having($fieldName, $fieldValue, $operator, 'OR');
        }

        /**
         * This method allows you to concatenate joins for the final SQL statement.
         *
         * @param string $joinTable The name of the table.
         * @param string $joinCondition the condition.
         * @param JoinTypesEnum $joinType
         * 
         * @return DatabaseManager
         */
        public function join(string $joinTable, string $joinCondition, JoinTypesEnum $joinType = JoinTypesEnum::INNER): self
        {
            return parent::doJoin($joinTable, $joinCondition, $joinType);
        }

        /**
         * A convenient function that returns TRUE if exists at least an element that
         * satisfy the where condition specified calling the "where" method before this one.
         *
         * @param string $tableName The name of the database table to work with.
         *
         * @return bool
         */
        public function has(string $tableName): bool
        {
            $this->selectOne($tableName, '1');
            return $this->count >= 1;
        }
    }
}