<?php declare(strict_types=1);

namespace Temant\DatabaseManager {
    use Exception;
    use mysqli_result;
    use mysqli_stmt;
    use stdClass;
    use Temant\DatabaseManager\Enums\DirectionsEnum;
    use Temant\DatabaseManager\Enums\JoinTypesEnum;

    class QueryBuilder extends DatabaseConnection
    {
        /**
         * Indicates whether a transaction is currently in progress.
         *
         * @var bool
         */
        protected bool $transactionInProgress = false;

        /**
         * The last executed SQL query with placeholders.
         *
         * @var string|null
         */
        protected ?string $lastQuery = null;

        /**
         * The SQL query being constructed.
         *
         * @var string
         */
        private string $_query;

        /**
         * An array of clauses.
         *
         * @var array
         */
        private array $clauses = [];

        /**
         * An array of bound parameters for prepared statements.
         *
         * @var array
         */
        private $bindParams = [''];

        /**
         * The count of rows affected by the last database operation.
         *
         * @var int
         */
        private int $count = 0;

        /**
         * The return type for query results ('array', 'object', or 'json').
         *
         * @var string
         */
        private string $returnType = 'array';

        /**
         * The key used for mapping rows when return type is 'object'.
         *
         * @var ?string
         */
        private ?string $mapKey = null;

        /**
         * The timestamp when the query execution started for profiling.
         *
         * @var float
         */
        private float $traceStartQ;

        /**
         * Indicates whether query tracing is enabled.
         *
         * @var bool
         */
        private bool $traceEnabled = true;

        /**
         * An array containing query execution tracing information.
         *
         * @var array
         */
        protected array $trace = [];

        /**
         * Set the last executed SQL query with parameter values.
         *
         * @param ?string $lastQuery The SQL query with placeholders.
         * @param ?array $parameters An array of parameter values to replace placeholders.
         * @return ?QueryBuilder This instance for method chaining.
         */
        private function setLastQuery(?string $lastQuery, ?array $parameters = null): ?self
        {
            if (!$lastQuery)
                return null;

            // Remove the first parameter since it's not used.
            unset($parameters[0]);

            // Replace placeholders with actual parameter values.
            $this->lastQuery = preg_replace_callback(
                '/\?/',
                function () use (&$parameters): string {
                    return sprintf("'%s'", array_shift($parameters));
                },
                $lastQuery
            );

            return $this;
        }

        /**
         * Get the last executed SQL query.
         *
         * @return string|null The last executed SQL query as a string or null if not available.
         */
        public function getLastQuery(): ?string
        {
            return $this->lastQuery;
        }

        /**
         * Reset states after an execution
         *
         * @return QueryBuilder Returns the current instance.
         */
        private function resetQuery(): self
        {
            if ($this->traceEnabled)
                $this->trace[] = $this->addTrace();
            $this->returnType = 'array';
            $this->_query = '';
            $this->mapKey = null;
            $this->bindParams = [''];

            $this->clauses = [];
            return $this;
        }

        /**
         * Build the SQL query.
         *
         * @param mixed $numRows Array to define SQL limit or only $count.
         * @param ?array $tableData Data for updating the database.
         *
         * @return mysqli_stmt Returns the prepared statement.
         * @throws Exception
         */
        private function buildQuery(mixed $numRows = null, ?array $tableData = null): mysqli_stmt
        {
            $this->buildJoinQuery();
            $this->buildInsertQuery($tableData);
            $this->buildWhereOrHavingQuery('WHERE', $this->clauses['WHERE']);

            $this->buildGroupByQuery();
            $this->buildWhereOrHavingQuery('HAVING', $this->clauses['HAVING']);
            $this->buildOrderByQuery();
            $this->buildLimitQuery($numRows);

            $this->setLastQuery($this->_query, $this->bindParams);
            $stmt = $this->prepareQuery();

            if (count($this->bindParams) > 1) {
                $this->prepareBindParams($stmt, $this->bindParams);
            }

            return $stmt;
        }

        /**
         * This helper method takes care of prepared statements' "bind_result method
         * , when the number of variables to pass is unknown.
         *
         * @param mysqli_stmt $stmt Equal to the prepared statement object.
         *
         * @return mixed The results of the SQL fetch.
         */
        private function dynamicBindResults(mysqli_stmt $stmt): mixed
        {
            $results = [];
            $meta = $stmt->result_metadata();

            if (!$meta && $stmt->sqlstate) {
                return [];
            }

            $row = [];
            $parameters = [];

            if ($meta instanceof mysqli_result) {
                while ($field = $meta->fetch_field()) {
                    if (isset($field->name)) {
                        $row[$field->name] = null;
                        $parameters[] = &$row[$field->name];
                    }
                }
            }

            $stmt->bind_result(...$parameters);
            $this->count = 0;

            while ($stmt->fetch()) {
                $result = $this->returnType === 'object' ? new stdClass() : [];

                foreach ($row as $key => $val) {
                    $this->returnType === 'object'
                        ? $result->$key = $val
                        : $result[$key] = $val;
                }

                $this->count++;

                ($this->mapKey)
                    ? $results[$row[$this->mapKey]] = (count($row) > 2) ? $result : end($result)
                    : array_push($results, $result);
            }

            $stmt->close();

            return $this->returnType === 'json'
                ? json_encode($results)
                : $results;
        }

        /**
         * Method attempts to prepare the SQL query
         * and throws an error if there was a problem.
         *
         * @return mysqli_stmt
         * @throws Exception
         */
        private function prepareQuery(): mysqli_stmt
        {
            if ($stmt = $this->mysqli()->prepare($this->_query)) {
                if ($this->traceEnabled)
                    $this->traceStartQ = microtime(true);
                return $stmt;
            }
            $this->resetQuery();
            throw new Exception("Error: {$this->mysqli()->error} - Query: {$this->_query}", $this->mysqli()->errno);
        }

        /**
         * Bind parameters by reference for a MySQLi prepared statement.
         *
         * @param mysqli_stmt $stmt The MySQLi prepared statement to which parameters will be bound.
         * @param array $parameters An array of parameters to be bound by reference.
         * @return bool True on success, false on failure.
         */
        private function prepareBindParams(mysqli_stmt $stmt, array &$parameters): bool
        {
            return $stmt->bind_param(...array_values($parameters));
        }

        /**
         * Get information about the caller of the current method for query tracing.
         *
         * @return ?array Information about the caller.
         */
        private function addTrace(): ?array
        {
            $dd = debug_backtrace();
            while ($caller = next($dd))
                if (isset($caller["file"]) && $caller["file"] !== __FILE__)
                    return [
                        'Query' => $this->getLastQuery(),
                        'Clauses' => $this->clauses,
                        'Execution' => (microtime(true) - $this->traceStartQ),
                        'File' => basename($caller['file']),
                        'Line' => $caller['line'] ?? null,
                        'Class' => $caller['class'] ?? null,
                        'Function' => $caller['function'],
                        'Args' => isset($caller['args']) ? $caller['args'] : null
                    ];
            return null;
        }

        /**
         * Build the ORDER BY clause for the SQL query.
         * 
         * @return void
         */
        private function buildOrderByQuery(): void
        {
            if (isset($this->clauses['ORDER_BY']))
                $this->_query .= sprintf(" ORDER BY %s", implode(', ', array_map(fn($p, $v): string =>
                    str_replace(' ', '', strtolower($p)) === 'rand()' ? 'rand()' : "$p $v", array_keys($this->clauses['ORDER_BY']), $this->clauses['ORDER_BY'])));
        }

        /**
         * A convenient SELECT * function.
         *
         * @param string $tableName The name of the database table to work with.
         * @param mixed $numRows Array($offset, $count) or int($count)
         * @param string|array $columns Desired columns.
         *
         * @return mixed Contains the returned rows from the select query.
         */
        protected function doSelect(string $tableName, mixed $numRows = null, string|array $columns = '*'): mixed
        {
            $column = is_array($columns) ? implode(', ', $columns) : $columns;
            $this->_query = "SELECT $column FROM `$tableName`";
            $stmt = $this->buildQuery($numRows);
            $stmt->execute();
            $res = $this->dynamicBindResults($stmt);
            $this->resetQuery();
            return $res;
        }

        /**
         * Update query. Be sure to first call the "where" method.
         *
         * @param string $tableName The name of the database table to work with.
         * @param array $tableData Array of data to update the desired row.
         * @param ?int $numRows Limit on the number of rows that can be updated.
         *
         * @return bool
         */
        protected function doUpdate(string $tableName, array $tableData, ?int $numRows = null): bool
        {
            $this->_query = "UPDATE " . $tableName;

            $stmt = $this->buildQuery($numRows, $tableData);
            $status = $stmt->execute();
            $this->resetQuery();
            $this->count = intval($stmt->affected_rows);
            return $status;
        }

        /**
         * Delete query. Call the "where" method first.
         *
         * @param string $tableName The name of the database table to work with.
         * @param int|array $numRows Array($offset, $count) or int $count
         *
         * @return bool Indicates success. 0 or 1.
         */
        protected function doDelete(string $tableName, mixed $numRows = null): bool
        {
            $this->_query = isset($this->clauses['JOIN']) && count($this->clauses['JOIN'])
                ? "DELETE " . preg_replace('/.* (.*)/', '$1', $tableName) . " FROM " . $tableName
                : "DELETE FROM " . $tableName;
            $stmt = $this->buildQuery($numRows);
            $stmt->execute();
            $this->count = intval($stmt->affected_rows);
            $this->resetQuery();
            return ($stmt->affected_rows > -1);
        }

        /**
         * Specify an ORDER BY statement for the SQL query.
         *
         * @param string $fieldName The name of the database field to order by.
         * @param DirectionsEnum $direction The order direction (ASC or DESC).
         *
         * @return QueryBuilder
         */
        protected function doOrderBy(string $fieldName, DirectionsEnum $direction = DirectionsEnum::ASC): self
        {
            $this->clauses['ORDER_BY'][$fieldName] = $direction->value;
            return $this;
        }

        /**
         * Specify a GROUP BY statement for the SQL query.
         *
         * @param string $fieldName The name of the database field to group by.
         *
         * @return QueryBuilder
         */
        protected function doGroupBy(string $fieldName): self
        {
            $this->clauses['GROUP_BY'][] = $fieldName;
            return $this;
        }

        /**
         * Insert a new row into the database table.
         *
         * @param string $tableName The name of the table.
         * @param array $insertData Associative array containing data for inserting into the DB.
         *
         * @return int The inserted row's ID if applicable.
         */
        protected function doInsert(string $tableName, array $insertData): int
        {
            return $this->executeInsertOrReplace($tableName, $insertData, 'INSERT');
        }

        /**
         * Replace a row in the database table.
         *
         * @param string $tableName The name of the table.
         * @param array $insertData Associative array containing data for replacing into the DB.
         *
         * @return int The inserted row's ID if applicable.
         */
        protected function doReplace(string $tableName, array $insertData): int
        {
            return $this->executeInsertOrReplace($tableName, $insertData, 'REPLACE');
        }

        /**
         * Build the GROUP BY clause for the SQL query.
         *
         * @return void
         */
        private function buildGroupByQuery(): void
        {
            if (isset($this->clauses['GROUP_BY']))
                $this->_query .= " GROUP BY " . implode(', ', $this->clauses['GROUP_BY']);
        }

        /**
         * Build the LIMIT clause for the SQL query.
         *
         * @param mixed $numRows array($offset, $count) or only int($count)
         *
         * @return void
         */
        private function buildLimitQuery(mixed $numRows): void
        {
            if (isset($numRows))
                $this->_query .= " LIMIT " . (is_array($numRows) ? implode(', ', array_map('intval', $numRows)) : $numRows);
        }

        /**
         * Execute an INSERT or REPLACE operation on the database table.
         *
         * @param string $tableName The name of the table.
         * @param array $insertData Associative array containing data for inserting into the DB.
         * @param string $operation Type of operation (INSERT or REPLACE).
         *
         * @return int The inserted row's ID if applicable.
         */
        private function executeInsertOrReplace(string $tableName, array $insertData, string $operation): int
        {
            $this->_query = "$operation INTO $tableName";
            $stmt = $this->buildQuery(null, $insertData);
            $stmt->execute();
            $this->resetQuery();
            $this->count = intval($stmt->affected_rows);
            return intval($stmt->insert_id);
        }

        /**
         * Abstraction method that will build the part of the WHERE conditions
         *
         * @param string $operator
         * @param ?array $conditions
         */
        private function buildWhereOrHavingQuery(string $operator, ?array &$conditions): void
        {
            if (!isset($conditions) || empty($conditions)) {
                return;
            }

            //Prepare the where portion of the query
            $this->_query .= ' ' . $operator;

            foreach ($conditions as $cond) {
                list($concat, $varName, $operator, $val) = $cond;
                $this->_query .= " " . $concat . " " . $varName;

                switch (strtolower($operator)) {
                    case 'not in':
                    case 'in':
                        $comparison = ' ' . $operator . ' (';
                        if (is_object($val)) {
                            $comparison .= $this->buildPair("", $val);
                        } else {
                            foreach ($val as $v) {
                                $comparison .= ' ?,';
                                $this->bindParam($v);
                            }
                        }
                        $this->_query .= rtrim($comparison, ',') . ' ) ';
                        break;
                    case 'not between':
                    case 'between':
                        $this->_query .= " $operator ? AND ? ";
                        $this->bindParams($val);
                        break;
                    case 'not exists':
                    case 'exists':
                        $this->_query .= $operator . $this->buildPair("", $val);
                        break;
                    default:
                        if (is_array($val)) {
                            $this->bindParams($val);
                        } elseif ($val === null) {
                            $this->_query .= ' ' . $operator . " NULL";
                        } elseif ($val != 'DBNULL' || $val == '0') {
                            $this->_query .= $this->buildPair($operator, $val);
                        }
                }
            }
        }

        /**
         * This method allows you to specify multiple (method chaining optional) AND WHERE statements for SQL queries.
         *
         * @param string $whereProp The name of the database field.
         * @param mixed $whereValue The value of the database field.
         * @param string $operator Comparison operator. Default is =
         * @param string $condition Condition of where statement (OR, AND)
         *
         * @return QueryBuilder
         */
        protected function doWhere(string $whereProp, mixed $whereValue = 'DBNULL', string $operator = '=', string $condition = 'AND'): self
        {
            $this->clauses['WHERE'][] = [empty($this->clauses['WHERE']) ? '' : $condition, $whereProp, $operator, $whereValue];
            return $this;
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
         * @return QueryBuilder
         */
        protected function doHaving(string $fieldName, mixed $fieldValue = 'DBNULL', string $operator = '=', string $condition = 'AND'): self
        {
            $this->clauses['HAVING'][] = [empty($this->clauses['HAVING']) ? '' : $condition, $fieldName, $operator, $fieldValue];
            return $this;
        }

        /**
         * This method allows you to concatenate joins for the final SQL statement.
         *
         * @param string $joinTable The name of the table.
         * @param string $joinCondition the condition.
         * @param JoinTypesEnum $joinType
         * 
         * @return QueryBuilder
         */
        protected function doJoin(string $joinTable, string $joinCondition, JoinTypesEnum $joinType = JoinTypesEnum::INNER): self
        {
            $this->clauses['JOIN'][] = [$joinType->value, $joinTable, $joinCondition];
            return $this;
        }

        /**
         * Build JOIN clauses for the SQL query based on specified join types, tables, and conditions.
         */
        private function buildJoinQuery(): void
        {
            if (!empty($this->clauses['JOIN']))
                foreach ($this->clauses['JOIN'] as [$joinType, $joinTable, $joinCondition])
                    $this->_query .= " {$joinType} JOIN {$joinTable} ON {$joinCondition}";
        }

        /**
         * Abstraction method that will build an INSERT or UPDATE part of the query
         *
         * @param ?array $tableData
         */
        private function buildInsertQuery(?array $tableData = null): void
        {
            if ($tableData) {
                $isInsert = boolval(preg_match('/^[INSERT|REPLACE]/', $this->_query));
                $dataColumns = array_keys($tableData);

                if ($isInsert) {
                    if (isset($dataColumns[0]))
                        $this->_query .= ' (`' . implode('`, `', $dataColumns) . '`) ';
                    $this->_query .= ' VALUES (';
                } else {
                    $this->_query .= " SET ";
                }

                foreach ($dataColumns as $column) {
                    $value = $tableData[$column];

                    if (!$isInsert)
                        $this->_query .= (strpos($column, '.') === false)
                            ? "`$column` = "
                            : str_replace('.', '.`', $column) . "` = ";

                    // Simple value
                    if (!is_array($value)) {
                        $this->bindParam($value);
                        $this->_query .= '?, ';
                        continue;
                    }

                    // Function value
                    $key = key($value);
                    $val = $value[$key];
                    switch ($key) {
                        case '[I]':
                            $this->_query .= $column . $val . ", ";
                            break;
                        case '[F]':
                            $this->_query .= $val[0] . ", ";
                            if (!empty($val[1]))
                                $this->bindParams($val[1]);
                            break;
                        case '[N]':
                            if ($val == null)
                                $this->_query .= "!" . $column . ", ";
                            else
                                $this->_query .= "!" . $val . ", ";
                            break;
                        default:
                            throw new Exception("Wrong operation");
                    }
                }
                $this->_query = rtrim($this->_query, ', ');

                if ($isInsert)
                    $this->_query .= ')';
            }
        }

        /**
         * Helper function to add variables into bind parameters array in bulk
         *
         * @param array $values Variable with values
         */
        private function bindParams(array $values): void
        {
            foreach ($values as $value)
                $this->bindParam($value);
        }

        /**
         * Helper function to add variables into bind parameters array
         *
         * @param mixed $value
         */
        private function bindParam(mixed $value): void
        {
            $this->bindParams[0] .= $this->determineType($value);
            array_push($this->bindParams, $value);
        }

        /**
         * Helper function to add variables into bind parameters array and will return
         * its SQL part of the query according to operator in ' $operator ?'
         *
         * @param string $operator
         * @param mixed  $value Variable with values
         *
         * @return string
         */
        private function buildPair($operator, $value): string
        {
            $this->bindParam($value);
            return ' ' . $operator . ' ? ';
        }

        /**
         * Determine the parameter type for a prepared statement based on the input.
         *
         * @param mixed $item The input to determine the type.
         *
         * @return string The parameter type ('s', 'i', 'b', 'd') for binding.
         */
        private function determineType(mixed $item): string
        {
            if (is_null($item) || is_string($item))
                return 's';
            elseif (is_bool($item) || is_int($item))
                return 'i';
            elseif (is_resource($item) && get_resource_type($item) === 'stream')
                return 'b';
            elseif (is_float($item))
                return 'd';
            else
                return '';
        }

        /**
         * Execute raw SQL query.
         *
         * @param string $query User-provided query to execute.
         * @param ?array $bindParams Variables array to bind to the SQL statement.
         *
         * @return mixed Contains the returned rows from the query.
         */
        public function rawQuery(string $query, ?array $bindParams = null): mixed
        {
            $this->_query = $query;
            $stmt = $this->prepareQuery();

            if (is_array($bindParams)) {
                $params = [''];
                foreach ($bindParams as $val) {
                    $params[0] .= $this->determineType($val);
                    $params[] = $val;
                }
                $this->prepareBindParams($stmt, $params);
            }

            $stmt->execute();
            $this->count = intval($stmt->affected_rows);
            $this->setLastQuery($this->_query, $bindParams);
            $res = $this->dynamicBindResults($stmt);
            $this->resetQuery();

            return $res;
        }

        /**
         * Helper function to execute a raw SQL query and return only 1 row of results.
         *
         * @param string $query User-provided query to execute.
         * @param ?array $bindParams Variables array to bind to the SQL statement.
         *
         * @return mixed Contains the returned row from the query.
         */
        public function rawQueryOne(string $query, ?array $bindParams = null): mixed
        {
            $res = $this->rawQuery($query, $bindParams);
            return is_array($res) && isset($res[0]) ? $res[0] : null;
        }

        /**
         * Helper function to execute a raw SQL query and return only 1 column of results.
         *
         * @param string $query User-provided query to execute.
         * @param ?array $bindParams Variables array to bind to the SQL statement.
         *
         * @return mixed Contains the returned rows from the query.
         */
        public function rawQueryValue(string $query, ?array $bindParams = null): mixed
        {
            if (is_array($res = $this->asArray()->rawQuery($query, $bindParams)))
                return ($res && preg_match('/limit\s+1;?$/i', $query)) ? current($res[0]) ?? null : array_column($res, key($res[0]));
            else
                return null;
        }

        /**
         * Get the last MySQL error message.
         *
         * @return string|null The last MySQL error message as a string, or null if no error occurred.
         */
        public function getLastError(): ?string
        {
            return $this->mysqli()->error ?: null;
        }

        /**
         * Get the last MySQL error code.
         *
         * @return int|null The last MySQL error code as an integer, or null if no error occurred.
         */
        public function getLastErrno(): ?int
        {
            return $this->mysqli()->errno ?: null;
        }

        /**
         * Return result as an associative array with $idField field value used as a record key
         *
         * Array Returns an array($k => $v) if select(.."param1, param2"), array ($k => array ($v, $v)) otherwise
         *
         * @param string $idField field name to use for a mapped element key
         *
         * @return QueryBuilder
         */
        public function map($idField): self
        {
            $this->mapKey = $idField;
            return $this;
        }

        /**
         * Helper function to create dbObject with JSON return type
         *
         * @return QueryBuilder
         */
        public function asJson(): self
        {
            $this->returnType = 'json';
            return $this;
        }

        /**
         * Helper function to create dbObject with array return type
         * Added for consistency as that's default output type
         *
         * @return QueryBuilder
         */
        public function asArray(): self
        {
            $this->returnType = 'array';
            return $this;
        }

        /**
         * Helper function to create dbObject with object return type.
         *
         * @return QueryBuilder
         */
        public function asObject(): self
        {
            $this->returnType = 'object';
            return $this;
        }

        /**
         * Begin a database transaction.
         *
         * This method starts a database transaction by disabling autocommit mode.
         * It also registers a shutdown function to automatically roll back the
         * transaction if it's not committed.
         *
         * @throws \Exception If the transaction cannot be started.
         */
        public function startTransaction(): void
        {
            if ($this->mysqli()->autocommit(false)) {
                $this->transactionInProgress = true;
                register_shutdown_function([$this, 'transactionStatusCheck']);
            } else {
                throw new Exception('Failed to start the transaction.');
            }
        }

        /**
         * Commit the current database transaction.
         *
         * @throws \Exception If the commit operation fails.
         *
         * @return void
         */
        public function commit(): void
        {
            if ($this->mysqli()->commit()) {
                $this->transactionInProgress = false;
                $this->mysqli()->autocommit(true);
            } else {
                throw new Exception('Failed to commit the transaction.');
            }
        }

        /**
         * Roll back the current database transaction.
         *
         * @throws \Exception If the rollback operation fails.
         *
         * @return void
         */
        public function rollback(): void
        {
            if ($this->mysqli()->rollback()) {
                $this->transactionInProgress = false;
                $this->mysqli()->autocommit(true);
            } else {
                throw new Exception('Failed to roll back the transaction.');
            }
        }

        /**
         * Shutdown handler to automatically roll back uncommitted transactions.
         *
         * This method is registered as a shutdown function and checks if a
         * transaction is in progress. If so, it automatically rolls back the
         * transaction to maintain atomicity.
         *
         * @return void
         */
        private function transactionStatusCheck(): void
        {
            $this->transactionInProgress && $this->rollback();
        }
    }
}