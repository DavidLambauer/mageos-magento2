<?php
/**
 * Copyright 2017 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Setup\Declaration\Schema\Db\MySQL;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\Declaration\Schema\Db\DbSchemaReaderInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\DefinitionAggregator;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraint;

/**
 * @inheritdoc
 */
class DbSchemaReader implements DbSchemaReaderInterface
{
    /**
     * Table type in information_schema.TABLES which allows to identify only tables and ignore views
     */
    public const MYSQL_TABLE_TYPE = 'BASE TABLE';

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var DefinitionAggregator
     */
    private $definitionAggregator;

    /**
     * Constructor.
     *
     * @param ResourceConnection $resourceConnection
     * @param DefinitionAggregator $definitionAggregator
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        DefinitionAggregator $definitionAggregator
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->definitionAggregator = $definitionAggregator;
    }

    /**
     * @inheritdoc
     */
    public function getTableOptions($tableName, $resource)
    {
        $adapter = $this->resourceConnection->getConnection($resource);

        // SQLite doesn't use collation/charset - return minimal options
        if ($adapter instanceof \Magento\Framework\DB\Adapter\Pdo\Sqlite) {
            return [
                'engine' => 'sqlite',
                'charset' => '',
                'collation' => '',
                'comment' => '',
            ];
        }

        // MySQL/MariaDB
        $dbName = $this->resourceConnection->getSchemaName($resource);
        $collationNameColumn = 'charset_applicability.collation_name';

        /* In case of mariadb>=11.4 check if column FULL_COLLATION_NAME is exist */
        if ($adapter->tableColumnExists(
            'COLLATION_CHARACTER_SET_APPLICABILITY',
            'FULL_COLLATION_NAME',
            'information_schema'
        )) {
            $collationNameColumn = 'charset_applicability.full_collation_name';
        }

        $stmt = $adapter->select()
            ->from(
                ['i_tables' => 'information_schema.TABLES'],
                [
                    'engine' => 'ENGINE',
                    'comment' => 'TABLE_COMMENT',
                    'collation' => 'TABLE_COLLATION'
                ]
            )
            ->joinInner(
                ['charset_applicability' => 'information_schema.COLLATION_CHARACTER_SET_APPLICABILITY'],
                'i_tables.table_collation = '.$collationNameColumn,
                [
                    'charset' => 'charset_applicability.CHARACTER_SET_NAME'
                ]
            )
            ->where('TABLE_SCHEMA = ?', $dbName)
            ->where('TABLE_NAME = ?', $tableName);

        return $adapter->fetchRow($stmt);
    }

    /**
     * Prepare and fetch query: Describe {table_name}.
     *
     * @param  string $tableName
     * @param  string $resource
     * @return array
     */
    public function readColumns($tableName, $resource)
    {
        $columns = [];
        $adapter = $this->resourceConnection->getConnection($resource);

        // SQLite uses describeTable() which is already implemented
        if ($adapter instanceof \Magento\Framework\DB\Adapter\Pdo\Sqlite) {
            $columnsData = $adapter->describeTable($tableName);

            // Map SQLite types to Magento column definition types
            $typeMap = [
                'integer' => 'int',
                'text' => 'varchar',
                'real' => 'decimal',
                'blob' => 'blob',
            ];

            foreach ($columnsData as $columnData) {
                $sqliteType = strtolower($columnData['DATA_TYPE']);
                $magentoType = $typeMap[$sqliteType] ?? 'varchar';

                $column = [
                    'name' => $columnData['COLUMN_NAME'],
                    'default' => $columnData['DEFAULT'],
                    'type' => $magentoType,  // Use Magento-compatible type name
                    'nullable' => $columnData['NULLABLE'],
                    'definition' => $magentoType,
                    'extra' => $columnData['IDENTITY'] ? 'auto_increment' : '',
                    'comment' => '',
                    'charset' => '',
                    'collation' => '',
                ];

                $processedColumn = $this->definitionAggregator->fromDefinition($column);
                $columns[$processedColumn['name']] = $processedColumn;
            }

            return $columns;
        }

        // MySQL/MariaDB
        $dbName = $this->resourceConnection->getSchemaName($resource);
        $stmt = $adapter->select()
            ->from(
                'information_schema.COLUMNS',
                [
                    'name' => 'COLUMN_NAME',
                    'default' => 'COLUMN_DEFAULT',
                    'type' => 'DATA_TYPE',
                    'nullable' => new Expression('IF(IS_NULLABLE="YES", true, false)'),
                    'definition' => 'COLUMN_TYPE',
                    'extra' => 'EXTRA',
                    'comment' => new Expression('IF(COLUMN_COMMENT="", NULL, COLUMN_COMMENT)'),
                    'charset' => 'CHARACTER_SET_NAME',
                    'collation' => 'COLLATION_NAME'
                ]
            )
            ->where('TABLE_SCHEMA = ?', $dbName)
            ->where('TABLE_NAME = ?', $tableName)
            ->order('ORDINAL_POSITION ASC');

        $columnsDefinition = $adapter->fetchAssoc($stmt);

        foreach ($columnsDefinition as $columnDefinition) {
            $column = $this->definitionAggregator->fromDefinition($columnDefinition);
            $columns[$column['name']] = $column;
        }

        return $columns;
    }

    /**
     * Fetch all indexes from table.
     *
     * @param  string $tableName
     * @param  string $resource
     * @return array
     */
    public function readIndexes($tableName, $resource)
    {
        $indexes = [];
        $adapter = $this->resourceConnection->getConnection($resource);

        // SQLite uses PRAGMA index_list and index_info
        if ($adapter instanceof \Magento\Framework\DB\Adapter\Pdo\Sqlite) {
            $indexData = $adapter->getIndexList($tableName);

            foreach ($indexData as $index) {
                // Skip PRIMARY and UNIQUE - those are handled by readConstraints()
                if ($index['INDEX_TYPE'] === 'primary' || $index['INDEX_TYPE'] === 'unique') {
                    continue;
                }

                $processedIndex = [
                    'name' => $index['KEY_NAME'],
                    'columns' => $index['COLUMNS_LIST'],
                    'type' => $index['INDEX_TYPE'],  // Should be 'index' only now
                ];

                $indexes[$index['KEY_NAME']] = $this->definitionAggregator->fromDefinition($processedIndex);
            }

            return $indexes;
        }

        // MySQL/MariaDB
        $condition = sprintf('`Non_unique` = 1');
        $sql = sprintf('SHOW INDEXES FROM `%s` WHERE %s', $tableName, $condition);
        $stmt = $adapter->query($sql);

        // Use FETCH_NUM so we are not dependent on the CASE attribute of the PDO connection
        $indexesDefinition = $stmt->fetchAll(\Zend_Db::FETCH_ASSOC);

        foreach ($indexesDefinition as $indexDefinition) {
            $indexDefinition['type'] = 'index';
            $index = $this->definitionAggregator->fromDefinition($indexDefinition);

            if (!isset($indexes[$index['name']])) {
                $indexes[$index['name']] = [];
            }

            $indexes[$index['name']] = array_replace_recursive($indexes[$index['name']], $index);
        }

        return $indexes;
    }

    /**
     * Read references (foreign keys) from Magento tables.
     *
     * As MySQL has bug and do not show foreign keys during DESCRIBE and other directives required
     * to take it from "SHOW CREATE TABLE ..." command.
     *
     * @inheritdoc
     */
    public function readReferences($tableName, $resource)
    {
        $adapter = $this->resourceConnection->getConnection($resource);

        // SQLite uses getForeignKeys()
        if ($adapter instanceof \Magento\Framework\DB\Adapter\Pdo\Sqlite) {
            $foreignKeys = $adapter->getForeignKeys($tableName);
            $definition = [
                'type' => 'reference',
                'foreign_keys' => []
            ];

            foreach ($foreignKeys as $fkData) {
                $definition['foreign_keys'][] = [
                    'name' => $fkData['FK_NAME'],
                    'column' => $fkData['COLUMN_NAME'],
                    'referenceTable' => $fkData['REF_TABLE_NAME'],
                    'referenceColumn' => $fkData['REF_COLUMN_NAME'],
                    'onDelete' => $fkData['ON_DELETE'],
                ];
            }

            return $this->definitionAggregator->fromDefinition($definition);
        }

        // MySQL/MariaDB
        $createTableSql = $this->getCreateTableSql($tableName, $resource);
        $createTableSql['type'] = 'reference';
        return $this->definitionAggregator->fromDefinition($createTableSql);
    }

    /**
     * Retrieve Create table SQL, from SHOW CREATE TABLE query.
     *
     * @param  string $tableName
     * @param  string $resource
     * @return array
     */
    public function getCreateTableSql($tableName, $resource)
    {
        $adapter = $this->resourceConnection->getConnection($resource);
        $sql = sprintf('SHOW CREATE TABLE `%s`', $tableName);
        $stmt = $adapter->query($sql);
        return $stmt->fetch(\Zend_Db::FETCH_ASSOC);
    }

    /**
     * Reading DB constraints.
     *
     * Primary and unique constraints are always non_unique=0.
     *
     * @inheritdoc
     */
    public function readConstraints($tableName, $resource)
    {
        $constraints = [];
        $adapter = $this->resourceConnection->getConnection($resource);

        // SQLite - read constraints from index list (PRIMARY KEY, UNIQUE)
        if ($adapter instanceof \Magento\Framework\DB\Adapter\Pdo\Sqlite) {
            $indexData = $adapter->getIndexList($tableName);

            foreach ($indexData as $index) {
                // Only process PRIMARY and UNIQUE constraints
                if ($index['INDEX_TYPE'] === 'primary' || $index['INDEX_TYPE'] === 'unique') {
                    // Format columns as array for multi-column constraints
                    $columns = is_array($index['COLUMNS_LIST'])
                        ? $index['COLUMNS_LIST']
                        : explode(',', $index['COLUMNS_LIST']);

                    // Process each column in the constraint
                    foreach ($columns as $columnName) {
                        $columnName = trim($columnName);

                        // Match MySQL's SHOW INDEXES format (exact case sensitivity)
                        $constraintDef = [
                            'Key_name' => $index['KEY_NAME'],  // MySQL uses Key_name (K cap, rest lower)
                            'Column_name' => $columnName,       // MySQL uses Column_name (C cap, rest lower)
                            'type' => Constraint::TYPE,
                        ];

                        $constraint = $this->definitionAggregator->fromDefinition($constraintDef);

                        if (!isset($constraints[$constraint['name']])) {
                            $constraints[$constraint['name']] = [];
                        }

                        $constraints[$constraint['name']] = array_replace_recursive(
                            $constraints[$constraint['name']],
                            $constraint
                        );
                    }
                }
            }

            return $constraints;
        }

        // MySQL/MariaDB
        $condition = sprintf('`Non_unique` = 0');
        $sql = sprintf('SHOW INDEXES FROM `%s` WHERE %s', $tableName, $condition);
        $stmt = $adapter->query($sql);

        // Use FETCH_NUM so we are not dependent on the CASE attribute of the PDO connection
        $constraintsDefinition = $stmt->fetchAll(\Zend_Db::FETCH_ASSOC);

        foreach ($constraintsDefinition as $constraintDefinition) {
            $constraintDefinition['type'] = Constraint::TYPE;
            $constraint = $this->definitionAggregator->fromDefinition($constraintDefinition);

            if (!isset($constraints[$constraint['name']])) {
                $constraints[$constraint['name']] = [];
            }

            $constraints[$constraint['name']] = array_replace_recursive($constraints[$constraint['name']], $constraint);
        }

        return $constraints;
    }

    /**
     * Return names of all tables from shard.
     *
     * @param  string $resource Shard name.
     * @return array
     */
    public function readTables($resource)
    {
        $adapter = $this->resourceConnection->getConnection($resource);

        // SQLite uses sqlite_master instead of information_schema
        if ($adapter instanceof \Magento\Framework\DB\Adapter\Pdo\Sqlite) {
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
            return $adapter->fetchCol($sql);
        }

        // MySQL/MariaDB
        $dbName = $this->resourceConnection->getSchemaName($resource);
        $stmt = $adapter->select()
            ->from(
                ['information_schema.TABLES'],
                ['TABLE_NAME']
            )
            ->where('TABLE_SCHEMA = ?', $dbName)
            ->where('TABLE_TYPE = ?', self::MYSQL_TABLE_TYPE);
        return $adapter->fetchCol($stmt);
    }
}
