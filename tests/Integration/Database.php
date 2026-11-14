<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Exception;
use TinyBlocks\DockerContainer\MySQLDockerContainer;
use TinyBlocks\EnvironmentVariable\EnvironmentVariable;
use TinyBlocks\Time\Duration;

final readonly class Database
{
    private const int PORT = 3306;

    private function __construct(
        private string $host,
        private string $network,
        private string $database,
        private string $password,
        private string $username
    ) {
    }

    public static function instance(): Database
    {
        $template = 'outbox-test-db-%s';
        $network = EnvironmentVariable::from(name: 'TEST_NETWORK')->toString();
        $database = EnvironmentVariable::from(name: 'DATABASE_NAME')->toString();
        $username = EnvironmentVariable::from(name: 'DATABASE_USER')->toString();
        $password = EnvironmentVariable::from(name: 'DATABASE_PASSWORD')->toString();

        return new Database(
            host: sprintf($template, gethostname()),
            network: $network,
            database: $database,
            password: $password,
            username: $username
        );
    }

    public function start(): void
    {
        try {
            $this->connection()->executeQuery('SELECT 1');
        } catch (Exception) {
            MySQLDockerContainer::from(image: 'mysql:8.4')
                ->withName(name: $this->host)
                ->withNetwork(name: $this->network)
                ->withTimezone(timezone: 'UTC')
                ->withUsername(user: $this->username)
                ->withPassword(password: $this->password)
                ->withDatabase(database: $this->database)
                ->withRootPassword(rootPassword: 'root')
                ->withGrantedHosts()
                ->withEphemeralStorage()
                ->withReadinessTimeout(timeout: Duration::fromSeconds(seconds: 60))
                ->runIfNotExists();
        }
    }

    public function connection(): Connection
    {
        return DriverManager::getConnection(params: [
            'host'     => $this->host,
            'port'     => self::PORT,
            'user'     => $this->username,
            'driver'   => 'pdo_mysql',
            'dbname'   => $this->database,
            'password' => $this->password
        ]);
    }
}
