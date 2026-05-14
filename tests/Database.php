<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Exception;
use Symfony\Component\Process\Process;
use TinyBlocks\DockerContainer\MySQLDockerContainer;
use TinyBlocks\EnvironmentVariable\EnvironmentVariable;

final readonly class Database
{
    private function __construct(
        private string $host,
        private string $port,
        private string $database,
        private string $username,
        private string $password
    ) {
    }

    public static function instance(): Database
    {
        $host = EnvironmentVariable::from(name: 'DATABASE_HOST')->toString();
        $port = EnvironmentVariable::from(name: 'TEST_DB_HOST_PORT')->toString();
        $database = EnvironmentVariable::from(name: 'DATABASE_NAME')->toString();
        $username = EnvironmentVariable::from(name: 'DATABASE_USER')->toString();
        $password = EnvironmentVariable::from(name: 'DATABASE_PASSWORD')->toString();

        return new Database(
            host: $host,
            port: $port,
            database: $database,
            username: $username,
            password: $password
        );
    }

    public function start(): void
    {
        try {
            $this->connection()->executeQuery('SELECT 1');
            return;
        } catch (Exception) {
        }

        new Process(['docker', 'rm', '-f', sprintf('tiny-blocks-reaper-%s', $this->host)])->run();
        new Process(['docker', 'rm', '-f', $this->host])->run();

        MySQLDockerContainer::from(image: 'mysql:8.4', name: $this->host)
            ->pullImage()
            ->withTimezone(timezone: 'UTC')
            ->withUsername(user: $this->username)
            ->withPassword(password: $this->password)
            ->withDatabase(database: $this->database)
            ->withPortMapping(portOnHost: (int)$this->port, portOnContainer: 3306)
            ->withRootPassword(rootPassword: 'root')
            ->withGrantedHosts()
            ->withoutAutoRemove()
            ->withReadinessTimeout(timeoutInSeconds: 60)
            ->run();
    }

    public function connection(): Connection
    {
        return DriverManager::getConnection(params: [
            'host'     => '127.0.0.1',
            'port'     => (int)$this->port,
            'user'     => $this->username,
            'driver'   => 'pdo_mysql',
            'dbname'   => $this->database,
            'password' => $this->password
        ]);
    }
}
