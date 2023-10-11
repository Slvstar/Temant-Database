<?php declare(strict_types=1);

namespace Temant\DatabaseManager {
    use mysqli;
    use Temant\DatabaseManager\Exceptions\{
        ConnectionNotFoundException,
        NoConnectionsFoundException,
        ConnectionExistsException,
    };

    /**
     * Represents a database connection manager for handling multiple database connections.
     *
     * This class allows you to manage and switch between different database connections using MySQLi.
     * It provides methods for adding, switching, disconnecting, and accessing database connections.
     */
    class DatabaseConnection
    {
        /**
         * The name of the current active database connection.
         *
         * @var string
         */
        protected string $currentConnection = 'default';

        /**
         * An associative array to store database connections, with connection names as keys and
         * corresponding MySQLi instances as values.
         *
         * @var array
         */
        private array $connections = [];

        /**
         * Constructor for the DatabaseConnection class.
         *
         * @param mysqli $mysqli The MySQLi database connection to be added as the default connection.
         */
        public function __construct(mysqli $mysqli)
        {
            $this->addConnection($this->currentConnection, $mysqli);
        }

        /**
         * Adds a new database connection to the manager.
         *
         * @param string $connectionName The name of the connection.
         * @param mysqli $mysqli The MySQLi database connection to be added.
         * @return DatabaseConnection instance for method chaining.
         * @throws ConnectionExistsException If the specified connection does not exist.
         */
        public function addConnection(string $connectionName, mysqli $mysqli): self
        {
            if ($this->hasConnection($connectionName))
                throw new ConnectionExistsException($connectionName);

            $this->connections[$connectionName] = $mysqli;
            return $this;
        }

        /**
         * Checks if a database connection with the given name exists.
         *
         * @param string $connectionName The name of the connection to check.
         * @return bool True if the connection exists, false otherwise.
         */
        public function hasConnection(string $connectionName): bool
        {
            return isset($this->connections[$connectionName]);
        }

        /**
         * Sets the current active database connection.
         *
         * @param string $connectionName The name of the connection to set as active.
         * @return DatabaseConnection instance for method chaining.
         * @throws ConnectionNotFoundException If the specified connection does not exist.
         */
        public function setConnection(string $connectionName): self
        {
            if (!$this->hasConnection($connectionName))
                throw new ConnectionNotFoundException($connectionName);

            $this->currentConnection = $connectionName;
            return $this;
        }

        /**
         * Sets the current active database connection.
         *
         * @return DatabaseConnection instance for method chaining.
         * @throws ConnectionNotFoundException If the specified connection does not exist.
         */
        public function setDefaultConnection(): self
        {
            $this->currentConnection = 'default';
            return $this;
        }

        /**
         * The name of the current active database connection.
         * 
         * @return string
         */
        public function getCurrentConnection(): string
        {
            return $this->currentConnection;
        }

        /**
         * Disconnects the specified database connection.
         *
         * @param string $connectionName The name of the connection to disconnect.
         * @throws ConnectionNotFoundException If the specified connection does not exist.
         */
        public function disconnect(string $connectionName = 'default'): void
        {
            if ($this->hasConnection($connectionName))
                throw new ConnectionNotFoundException($connectionName);

            $this->connections[$connectionName]->close();
            unset($this->connections[$connectionName]);
        }

        /**
         * Disconnects all database connections managed by this instance.
         */
        public function disconnectAll(): void
        {
            foreach (array_keys($this->connections) as $connection)
                $this->disconnect($connection);
        }

        /**
         * Retrieves the MySQLi database connection for the current active connection.
         *
         * @return mysqli The MySQLi database connection.
         * @throws NoConnectionsFoundException If no connections are found when attempting to retrieve the current connection.
         */
        protected function mysqli(): mysqli
        {
            if (!$this->hasConnection($this->currentConnection))
                throw new NoConnectionsFoundException();

            return $this->connections[$this->currentConnection];
        }

        /**
         * Pings the MySQLi database connection.
         *
         * @return bool True if the connection is active.
         */
        public function ping(): bool
        {
            return $this->mysqli()->ping();
        }
    }
}