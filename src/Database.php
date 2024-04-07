<?php
namespace Qkly;
use \PDO;
use \PDOException;

class Database
{
    private static $pdo;

    static function connect($dbName = null)
    {
        try {
            $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';port=' . $_ENV['DB_PORT'];
            if ($dbName !== null) {
                $dsn .= ';dbname=' . $dbName;
            }
            self::$pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return self::$pdo;
        } catch (PDOException $e) {
            die("Could not connect to the database: " . $e->getMessage());
        }
    }

    public static function switch($dbName)
    {
        self::connect($dbName);
    }

    public static function createDatabase($dbName)
    {
        try {
            $sql = "CREATE DATABASE IF NOT EXISTS `$dbName`";
            self::$pdo->exec($sql);
            self::connect($dbName);
        } catch (PDOException $e) {
            die("Could not create database $dbName: " . $e->getMessage());
        }
    }

    static function postMigration()
    {
        self::connect();
        $stmt = self::$pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$_ENV['DB_DATABASE']]);
        if ($stmt->rowCount() == 0) {
            self::createDatabase($_ENV['DB_DATABASE']);
            if (CLI) {
                echo "Database " . $_ENV['DB_DATABASE'] . " created.\n";
            }
        }
        self::connect($_ENV['DB_DATABASE']);
        $stmt = self::$pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute(['migrations']);
        $tableExists = $stmt->rowCount() > 0;
        if (!$tableExists) {
            $schema = [];
            $schema['migrations']['id']['type'] = "INT";
            $schema['migrations']['id']['auto_increment'] = true;
            $schema['migrations']['id']['nullable'] = false;

            $schema['migrations']['migration']['type'] = "TEXT";
            $schema['migrations']['migration']['nullable'] = true;


            $schema['migrations']['created_at']['type'] = "TIMESTAMP";
            $schema['migrations']['created_at']['nullable'] = true;

            $schema['migrations']['batch']['type'] = "INT";
            $schemaJson = json_encode($schema, JSON_PRETTY_PRINT);
            self::migrateSchema($schemaJson);
        }
    }

    static function migrate($fresh = false)
    {
        self::postMigration();
        if ($fresh == true) {
            self::dropAllTables();
            self::postMigration();
        }
        $migrations_dir = APP_DIR . 'migrations' . DS;
        $appliedMigrations = self::$pdo->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
        $allMigrations = scandir($migrations_dir);
        $pendingMigrations = array_diff($allMigrations, $appliedMigrations);
        $pendingMigrations = array_filter($pendingMigrations, function ($file) {
            return strpos($file, '.json') !== false;
        });
        natsort($pendingMigrations);
        if (empty($pendingMigrations)) {
            if (CLI) {
                echo "Your database is already up to date.\n";
            }
            return true;
        }
        $batch = (int) self::$pdo->query("SELECT MAX(batch) FROM migrations")->fetchColumn() + 1;

        foreach ($pendingMigrations as $migration) {
            $migrationPath = $migrations_dir . $migration;
            if (file_exists($migrationPath)) {
                if (CLI) {
                    echo "Applying migration: $migration\n";
                }
                $schemaJson = file_get_contents($migrationPath);
                self::migrateSchema($schemaJson);
                $stmt = self::$pdo->prepare("INSERT INTO migrations (migration, batch, created_at) VALUES (?, ?, ?)");
                $stmt->execute([$migration, $batch, date("Y-m-d H:i:s")]);
                if (CLI) {
                    echo "Applied migration: $migration\n";
                }
            }
        }
    }

    static function migrateSchema($schemaJson)
    {
        $schema = json_decode($schemaJson, true);
        foreach ($schema as $tableName => $columns) {
            // Check if table exists
            $stmt = self::$pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            $tableExists = $stmt->rowCount() > 0;
            if (!$tableExists) {
                $createTableSQL = "CREATE TABLE `$tableName` (";
                $columnDefinitions = [];
                $fkConstraints = [];
                foreach ($columns as $columnName => $columnProps) {
                    $columnDefinitions[] = self::getColumnDefinition($columnName, $columnProps);
                    if (isset($columnProps['relation'])) {
                        list($referencedTable, $referencedColumn) = explode('(', rtrim($columnProps['relation'], ')'));
                        $fkConstraint = "ALTER TABLE `$tableName` ADD CONSTRAINT `fk_$tableName" . "_$columnName` FOREIGN KEY (`$columnName`) REFERENCES `$referencedTable` (`$referencedColumn`)";
                        $fkConstraints[] = $fkConstraint;
                    }
                }
                $createTableSQL .= implode(", ", $columnDefinitions);
                $createTableSQL .= ", PRIMARY KEY (`id`))";
                self::$pdo->exec($createTableSQL);
                foreach ($fkConstraints as $fkConstraint) {
                    self::$pdo->exec($fkConstraint);
                }
            } else {
                foreach ($columns as $columnName => $columnProps) {
                    $stmt = self::$pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE ?");
                    $stmt->execute([$columnName]);
                    $columnExists = $stmt->rowCount() > 0;
                    if (!$columnExists) {
                        $alterTableSQL = "ALTER TABLE `$tableName` ADD `$columnName` {$columnProps['type']}";
                        if (isset($columnProps['nullable']) && !$columnProps['nullable']) {
                            $alterTableSQL .= " NOT NULL";
                        }
                        if (isset($columnProps['auto_increment']) && $columnProps['auto_increment']) {
                            $alterTableSQL .= " AUTO_INCREMENT";
                        }
                        if (isset($columnProps['comment'])) {
                            $alterTableSQL .= " COMMENT '" . addslashes($columnProps['comment']) . "'";
                        }
                        self::$pdo->exec($alterTableSQL);
                    } else {
                        $existingColumn = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($existingColumn['Type'] !== strtolower($columnProps['type'])) {
                            $alterTableSQL = "ALTER TABLE `$tableName` MODIFY `$columnName` ";
                            $alterTableSQL .= self::getColumnDefinition($columnName, $columnProps);
                            self::$pdo->exec($alterTableSQL);
                        }
                    }
                }
            }
        }
    }

    static function getColumnDefinition($columnName, $columnProps)
    {
        if (isset($columnProps['type']) && strtoupper($columnProps['type']) === 'ENUM') {
            if (!isset($columnProps['values']) || !is_array($columnProps['values'])) {
                die("ENUM type for $columnName requires 'values' array.");
            }
            $enumValues = array_map(function ($val) {
                return "'" . addslashes($val) . "'";
            }, $columnProps['values']);
            $columnDef = "`$columnName` ENUM(" . implode(", ", $enumValues) . ")";
        } else {
            $columnDef = "`$columnName` {$columnProps['type']}";
        }

        // $columnDef = "`$columnName` {$columnProps['type']}";
        if (isset($columnProps['nullable']) && !$columnProps['nullable']) {
            $columnDef .= " NOT NULL";
        } else {
            $columnDef .= " NULL";
        }

        if (isset($columnProps['default'])) {
            // Handle default value for ENUM differently if needed
            if (strtoupper($columnProps['type']) === 'ENUM') {
                $columnDef .= " DEFAULT '" . addslashes($columnProps['default']) . "'";
            } else {
                if (is_numeric($columnProps['default']) || strtoupper($columnProps['default']) === 'NULL') {
                    $columnDef .= " DEFAULT " . $columnProps['default'];
                } else {
                    $columnDef .= " DEFAULT '" . addslashes($columnProps['default']) . "'";
                }
            }
        }

        if (isset($columnProps['auto_increment']) && $columnProps['auto_increment']) {
            $columnDef .= " AUTO_INCREMENT";
        }

        if (isset($columnProps['comment'])) {
            $columnDef .= " COMMENT '" . addslashes($columnProps['comment']) . "'";
        }
        return $columnDef;
    }

    private static function dropAllTables()
    {
        self::$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;"); // Disable foreign key checks to avoid constraint violations

        $stmt = self::$pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            self::$pdo->exec("DROP TABLE IF EXISTS `$table`");
        }

        self::$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); // Re-enable foreign key checks
    }

    public static function closeConnection()
    {
        self::$pdo = null; // Closing the PDO connection
    }

    public static function makeMigration($table_name)
    {
        $directory = APP_DIR . "migrations" . DS;
        if (!is_dir($directory)) {
            mkdir($directory);
        }
        $count = count(glob($directory . "*")) + 1;
        $date = date("d_m_y");
        $file = "{$count}_{$date}_{$table_name}.json";
        $schema = [];
        $schema[$table_name]['id']['type'] = "INT";
        $schema[$table_name]['id']['auto_increment'] = true;
        $schema[$table_name]['id']['nullable'] = false;
        $schema[$table_name]['id']['comment'] = "Primary key for record";

        $schema[$table_name]['created_at']['type'] = "TIMESTAMP";
        $schema[$table_name]['created_at']['nullable'] = true;
        $schema[$table_name]['created_at']['comment'] = "When the record was created";

        $schema[$table_name]['updated_at']['type'] = "TIMESTAMP";
        $schema[$table_name]['updated_at']['nullable'] = true;
        $schema[$table_name]['updated_at']['comment'] = "When the record was updated";

        $migration_file = fopen($directory . $file, "w");
        fwrite($migration_file, json_encode($schema, JSON_PRETTY_PRINT));
        fclose($migration_file);
    }
    public function __destruct()
    {
        self::closeConnection();
    }
}