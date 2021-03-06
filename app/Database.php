<?php

namespace GitHubToMysql;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;

/**
 * The database schema class
 */
class Database {

    /**
     * The connection
     *
     * @var Connection
     */
    private static $connection = null;

    /**
     * Connect to the database or return the connection
     *
     * @param string $driver The driver (,pdo_mysql, pdo_sqlite, ..)
     * @return Connection The connection
     */
    public static function connect(string $driver = 'pdo_mysql'): Connection {
        if (self::$connection === null) {
            // DB
            $dbConfig = new Configuration();
            $dbConfig->setFilterSchemaAssetsExpression('/^github_/');
            self::$connection = DriverManager::getConnection([
                'dbname' => getenv('DB_NAME'),
                'user' => getenv('DB_USER') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'host' => getenv('DB_HOST') ?: 'localhost',
                'port' => getenv('DB_PORT') ?: 3306,
                'driver' => $driver,
                'charset' => 'utf8mb4',
            ], $dbConfig);
        }
        return self::$connection;
    }

    /**
     * Get the database schema
     *
     * @return Schema The schema with all tables and foreign keys
     */
    public static function getSchema(): Schema {

        $schema = new Schema();

        // Labels
        $labelsTable = $schema->createTable('github_labels');
        $labelsTable->addColumn('id', 'integer', ['unsigned' => true]);
        $labelsTable->addColumn('url', 'string');
        $labelsTable->addColumn('name', 'string');
        $labelsTable->addColumn('color', 'string');
        $labelsTable->setPrimaryKey(['id']);
        $labelsTable->addIndex(['name']);
        // Milestones
        $milestonesTable = $schema->createTable('github_milestones');
        $milestonesTable->addColumn('id', 'integer', ['unsigned' => true]);
        $milestonesTable->addColumn('title', 'string');
        $milestonesTable->addColumn('description', 'string');
        $milestonesTable->addColumn('url', 'string');
        $milestonesTable->addColumn('open', 'boolean');
        $milestonesTable->setPrimaryKey(['id']);
        $milestonesTable->addIndex(['title']);
        // Issues
        $issuesTable = $schema->createTable('github_issues');
        $issuesTable->addColumn('id', 'integer', ['unsigned' => true]);
        $issuesTable->addColumn('title', 'text');
        $issuesTable->addColumn('open', 'boolean');
        $issuesTable->addColumn('author', 'string');
        $issuesTable->addColumn('author_avatar_url', 'string', ['notnull' => false]);
        $issuesTable->addColumn('created_at', 'datetime');
        $issuesTable->addColumn('updated_at', 'datetime');
        $issuesTable->addColumn('closed_at', 'datetime', ['notnull' => false]);
        $issuesTable->addColumn('is_pull_request', 'boolean');
        $issuesTable->addColumn('milestone_id', 'integer', ['unsigned' => true, 'notnull' => false]);
        $issuesTable->setPrimaryKey(['id']);
        $issuesTable->addIndex(['author']);
        $issuesTable->addIndex(['open']);
        $issuesTable->addIndex(['created_at']);
        $issuesTable->addIndex(['updated_at']);
        $issuesTable->addIndex(['closed_at']);
        $issuesTable->addIndex(['is_pull_request']);
        $issuesTable->addForeignKeyConstraint('github_milestones', ['milestone_id'], ['id']);
        // Issue labels
        $issueLabelsTable = $schema->createTable('github_issue_labels');
        $issueLabelsTable->addColumn('issue_id', 'integer', ['unsigned' => true]);
        $issueLabelsTable->addColumn('label_id', 'integer', ['unsigned' => true]);
        $issueLabelsTable->setPrimaryKey(['issue_id', 'label_id']);
        $issueLabelsTable->addForeignKeyConstraint('github_issues', ['issue_id'], ['id'], [
            'onUpdate' => 'CASCADE',
            'onDelete' => 'CASCADE',
        ]);
        $issueLabelsTable->addForeignKeyConstraint('github_labels', ['label_id'], ['id'], [
            'onUpdate' => 'CASCADE',
            'onDelete' => 'CASCADE',
        ]);

        return $schema;
    }

    /**
     * Create the database using the schema
     *
     * @param boolean $force Execute the queries or not
     * @param \Closure $onRunning The callback to be called with db and query as string
     * @param \Closure $onUpToDate The callback to be called with db as only argument
     * @return void
     */
    public static function createSchema(
        bool $force,
        \Closure $onRunning = null,
        \Closure $onUpToDate = null
    ): void {
        $targetSchema = self::getSchema();
        $db = self::connect();
        $currentSchema = $db->getSchemaManager()->createSchema();

        $migrationQueries = $currentSchema->getMigrateToSql($targetSchema, $db->getDatabasePlatform());

        $db->transactional(function () use ($migrationQueries, $force, $db, $onUpToDate, $onRunning) {
            foreach ($migrationQueries as $query) {
                if ($onRunning !== null) {
                    $onRunning->call($db, $query);
                }

                if ($force) {
                    $db->exec($query);
                }
            }
            if (empty($migrationQueries) && $onUpToDate !== null) {
                $onUpToDate->call($db);
            }
        });
    }
}