<?php

namespace Sushi;

use Closure;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

trait Sushi
{
    public static function sushiCache()
    {
        $cacheDirectory = realpath(config('sushi.cache-path', storage_path('framework/cache')));
        return property_exists(static::class, 'rows') && is_writable($cacheDirectory);
    }

    protected static $sushiConnection;
    protected static $sushiTrainCacheTable = 'sushi_cache_records';

    public function getRows()
    {
        return $this->rows;
    }

    public function getSchema()
    {
        return $this->schema ?? [];
    }

    protected static function sushiCacheReferencePath()
    {
        return (new \ReflectionClass(static::class))->getFileName();
    }

    protected static function sushiCachePath()
    {
        if (is_string(static::sushiCache())) {
            $cacheFileName = static::sushiCache() . '.sqlite';
        } else {
            $cacheFileName = config('sushi.cache-prefix', 'sushi') . '-' . Str::kebab(str_replace('\\', '', static::class)) . '.sqlite';
        }
        $cacheDirectory = realpath(config('sushi.cache-path', storage_path('framework/cache')));
        return "{$cacheDirectory}/{$cacheFileName}";
    }

    protected function sushiShouldCache()
    {
        return property_exists(static::class, 'rows');
    }

    public static function resolveConnection($connection = null)
    {
        return static::$sushiConnection;
    }

    public static function spoiledSushi()
    {
        if (is_string(static::sushiCache()) || static::sushiCache() === false) {
            $connection = static::resolveConnection();
            $instance = (new static);
            if ($connection) {
                $schemaBuilder = $connection->getSchemaBuilder();
                if ($schemaBuilder->hasTable(self::$sushiTrainCacheTable)) {
                    $r = $connection->table(self::$sushiTrainCacheTable)->where('table', '=', $instance->getTable())->first();
                    if ($r) {
                        $connection->table(self::$sushiTrainCacheTable)->where('table', '=', $instance->getTable())->update(['timestamp' => 0]);
                    }
                }
            }
        } elseif (static::sushiCache()) {
            $instance = (new static);
            if (file_exists(static::sushiCachePath())) {
                touch(static::sushiCacheReferencePath(), filemtime(static::sushiCachePath()) + 1);
            }
        } else {
            assert(false, "impossible!");
        }
    }

    public static function freshSushi()
    {
        static::spoiledSushi();
        static::bootSushi();
    }

    protected static function checkCache($dataPath)
    {
        $needMigrate = false;

        if (is_string(static::sushiCache())) {
            $table = (new static)->getTable();
            $connection = static::resolveConnection();
            $schemaBuilder = $connection->getSchemaBuilder();

            if ($schemaBuilder->hasTable(self::$sushiTrainCacheTable)) {
                $r = $connection->table(self::$sushiTrainCacheTable)->where('table', '=', $table)->first();
                if ($r) {
                    if (filemtime($dataPath) > $r->timestamp) {
                        $needMigrate = true;
                    }
                } else {
                    $connection->table(self::$sushiTrainCacheTable)->insert([
                        ['table' => $table, 'timestamp' => 0]
                    ]);
                    $needMigrate = true;
                }
            } else {
                $schemaBuilder->create(self::$sushiTrainCacheTable, function ($table) {
                    $table->string('table');
                    $table->integer('timestamp');
                });
                $connection->table(self::$sushiTrainCacheTable)->insert([
                    ['table' => $table, 'timestamp' => 0]
                ]);
                $needMigrate = true;
            }
        } else {
            $cachePath = static::sushiCachePath();
            $needMigrate = filemtime($dataPath) > filemtime($cachePath);
        }
        return !$needMigrate;
    }

    protected static function touchCache()
    {
        $table = (new static)->getTable();
        $connection = static::resolveConnection();
        $schemaBuilder = static::resolveConnection()->getSchemaBuilder();

        if (is_string(static::sushiCache())) {
            if ($schemaBuilder->hasTable(self::$sushiTrainCacheTable)) {
                $r = $connection->table(self::$sushiTrainCacheTable)->where('table', '=', $table)->first();
                if (!$r) {
                    error_log('');
                }
                $connection->table(self::$sushiTrainCacheTable)->where('table', '=', $table)->update(['timestamp' => time()]);
            } else {
                $schemaBuilder->create('sushi_train_cache', function ($table) {
                    $table->string('table');
                    $table->integer('timestamp');
                });
                $connection->table(self::$sushiTrainCacheTable)->insert([
                    ['table' => $table, 'timestamp' => time()]
                ]);
            }
        } elseif (static::sushiCache()) {
            touch(static::sushiCachePath());
        }
    }

    public static function bootSushi()
    {
        $instance = (new static);

        if (is_string(static::sushiCache())) {
            $cacheFileName = static::sushiCache() . '.sqlite';
        } else {
            $cacheFileName = config('sushi.cache-prefix', 'sushi') . '-' . Str::kebab(str_replace('\\', '', static::class)) . '.sqlite';
        }

        $cacheDirectory = realpath(config('sushi.cache-path', storage_path('framework/cache')));
        $cachePath = $cacheDirectory.'/'.$cacheFileName;
        $dataPath = $instance->sushiCacheReferencePath();

        if (!$instance->sushiShouldCache() || (!file_exists($cachePath) && !is_writable($cacheDirectory))) {
            static::setSqliteConnection(':memory:');
            $instance->migrate();
        } else {
            if (!file_exists($cachePath)) {
                file_put_contents($cachePath, '');
                touch($cachePath, filemtime($dataPath) - 1);
            }
            static::setSqliteConnection($cachePath);
            if (!static::checkCache($dataPath)) {
                $instance->migrate();
            }
        }
    }

    protected static function setSqliteConnection($database)
    {
        $config = [
            'driver' => 'sqlite',
            'database' => $database,
        ];

        static::$sushiConnection = app(ConnectionFactory::class)->make($config);

        $name = is_string(static::sushiCache()) ? static::sushiCache() : static::class;
        app('config')->set('database.connections.'. $name, $config);
    }

    public function migrate()
    {
        $rows = $this->getRows();
        $tableName = $this->getTable();

        if (count($rows)) {
            $this->createTable($tableName, $rows[0]);
        } else {
            $this->createTableWithNoData($tableName);
        }

        foreach (array_chunk($rows, $this->getSushiInsertChunkSize()) ?? [] as $inserts) {
            if (!empty($inserts)) {
                static::insert($inserts);
            }
        }
        static::touchCache();
    }

    public function createTable(string $tableName, $firstRow)
    {
        $this->createTableSafely($tableName, function ($table) use ($firstRow) {
            // Add the "id" column if it doesn't already exist in the rows.
            if ($this->incrementing && ! array_key_exists($this->primaryKey, $firstRow)) {
                $table->increments($this->primaryKey);
            }

            foreach ($firstRow as $column => $value) {
                switch (true) {
                    case is_int($value):
                        $type = 'integer';
                        break;
                    case is_numeric($value):
                        $type = 'float';
                        break;
                    case is_string($value):
                        $type = 'string';
                        break;
                    case is_object($value) && $value instanceof \DateTime:
                        $type = 'dateTime';
                        break;
                    default:
                        $type = 'string';
                }

                if ($column === $this->primaryKey && $type == 'integer') {
                    $table->increments($this->primaryKey);
                    continue;
                }

                $schema = $this->getSchema();

                $type = $schema[$column] ?? $type;

                $table->{$type}($column)->nullable();
            }

            if ($this->usesTimestamps() && (! in_array('updated_at', array_keys($firstRow)) || ! in_array('created_at', array_keys($firstRow)))) {
                $table->timestamps();
            }
        });
    }

    public function createTableWithNoData(string $tableName)
    {
        $this->createTableSafely($tableName, function ($table) {
            $schema = $this->getSchema();

            if ($this->incrementing && ! in_array($this->primaryKey, array_keys($schema))) {
                $table->increments($this->primaryKey);
            }

            foreach ($schema as $name => $type) {
                if ($name === $this->primaryKey && $type == 'integer') {
                    $table->increments($this->primaryKey);
                    continue;
                }

                $table->{$type}($name)->nullable();
            }

            if ($this->usesTimestamps() && (! in_array('updated_at', array_keys($schema)) || ! in_array('created_at', array_keys($schema)))) {
                $table->timestamps();
            }
        });
    }

    protected function createTableSafely(string $tableName, Closure $callback)
    {
        /** @var \Illuminate\Database\Schema\SQLiteBuilder $schemaBuilder */
        $connection = static::resolveConnection();
        $schemaBuilder = static::resolveConnection()->getSchemaBuilder();
        if ($schemaBuilder->hasTable($tableName)) {
            $schemaBuilder->drop($tableName);
        }

        try {
            $schemaBuilder->create($tableName, $callback);
        } catch (QueryException $e) {
            if (Str::contains($e->getMessage(), 'already exists (SQL: create table')) {
                // This error can happen in rare circumstances due to a race condition.
                // Concurrent requests may both see the necessary preconditions for
                // the table creation, but only one can actually succeed.
                return;
            }

            throw $e;
        }
    }

    public function usesTimestamps()
    {
        // Override the Laravel default value of $timestamps = true; Unless otherwise set.
        return (new \ReflectionClass($this))->getProperty('timestamps')->class === static::class
            ? parent::usesTimestamps()
            : false;
    }

    public function getSushiInsertChunkSize()
    {
        return $this->sushiInsertChunkSize ?? 100;
    }

    public function getConnectionName()
    {
        return is_string(static::sushiCache()) ? static::sushiCache() : static::class;
    }
}
