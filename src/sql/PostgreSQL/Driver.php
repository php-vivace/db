<?php
/**
 * Created by PhpStorm.
 * User: macbookpro
 * Date: 15.02.2018
 * Time: 18:52
 */

namespace vivace\db\sql\PostgreSQL;


use vivace\db\Exception;
use vivace\db\sql\Result;
use vivace\db\sql\Statement;

/**
 * Class Driver
 *
 * @package vivace\db\sql\PostgreSQL
 *
 * @example
 * $pdo = new \PDO(<dsn>, <user>m <pass>);
 * $driver = new \vivace\db\sql\PostgreSQL\Driver($pdo);
 * $storage = new \vivace\db\sql\Storage($driver, 'user');
 * // Now storage is ready for use...
 *
 */
final class Driver extends \vivace\db\sql\Driver
{

    const TPL_SCHEMA =
        /** @lang PostgreSQL */
        "SELECT  
            t.*,
             CASE 
                WHEN t.innerType = 'smallint' THEN 'int'
                WHEN t.innerType = 'integer' THEN 'int'
                WHEN t.innerType = 'bigint' THEN 'int'
                WHEN t.innerType = 'smallserial' THEN 'int'
                WHEN t.innerType = 'serial' THEN 'int'
                WHEN t.innerType = 'bigserial' THEN 'int'
                WHEN t.innerType = 'datetime' THEN 'timestamp'
                WHEN t.innerType = 'timestamp without time zone' THEN 'timestamp'
                WHEN t.innerType = 'timestamp with time zone' THEN 'timestamp'
                WHEN t.innerType = 'decimal' THEN 'float'
                WHEN t.innerType = 'numeric' THEN 'float'
                WHEN t.innerType = 'real' THEN 'float'
                WHEN t.innerType = 'double precision' THEN 'float'
                WHEN t.innerType = 'boolean' THEN 'bool'
                WHEN t.innerType = 'bytea' THEN 'resource'
                ELSE 'string'
            END AS \"type\"
            FROM (
             SELECT 
                f.attnum AS position,  
                f.attname AS name,  
                CASE WHEN f.attnotnull THEN 0 ELSE 1 END AS nullable,  
                pg_catalog.format_type(f.atttypid,NULL) AS innerType,
                CASE WHEN p.contype = 'p' THEN 1 ELSE 0  END AS \"primary\",  
                CASE WHEN p.contype = 'u' THEN 1 ELSE 0  END AS \"unique\",  
                CASE WHEN f.atthasdef = 't' THEN d.adsrc END AS \"default\",
                CASE WHEN g.relkind = 'S' THEN 1 ELSE 0 END AS \"autoincrement\"
            FROM pg_attribute f  
                JOIN pg_class c ON c.oid = f.attrelid  
                JOIN pg_type t ON t.oid = f.atttypid  
                LEFT JOIN pg_attrdef d ON d.adrelid = c.oid AND d.adnum = f.attnum  
                LEFT JOIN pg_namespace n ON n.oid = c.relnamespace  
                LEFT JOIN pg_constraint p ON p.conrelid = c.oid AND f.attnum = ANY (p.conkey)  
                LEFT JOIN pg_class AS g ON p.confrelid = g.oid  
            WHERE c.relkind = 'r'::char  
                AND n.nspname=:schema AND c.relname=:table
                AND f.attnum > 0 ORDER BY position
            ) t";

    const VERSION = '1';
    const OP_LITERAL = 1;
    const OP_IDENTIFIER = 2;
    const OP_VALUE = 3;

    /**
     * @var \PDO
     */
    protected $pdo;
    protected $schemas = [];

    protected $stored = false;


    /**
     * Mysql constructor.
     *
     * @param \PDO $pdo
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }


    protected static function literal(array &$stack, $value)
    {
        $stack[] = [self::OP_LITERAL, $value];
    }

    protected static function identifier(array &$stack, $value)
    {
        $stack[] = [self::OP_IDENTIFIER, $value];
    }

    protected static function value(array &$stack, $value)
    {
        $stack[] = [self::OP_VALUE, $value];
    }

    protected static function condition(array &$stack, $value)
    {
        if (!isset($value[0])) {
            $sep = false;
            $isMulti = count($value) > 1;
            $isMulti && self::literal($stack, '(');
            foreach ($value as $key => $val) {
                if ($sep) {
                    self::literal($stack, ' AND ');
                } else {
                    $sep = true;
                }
                self::identifier($stack, $key);
                self::literal($stack, ' = ');
                self::value($stack, $val);
            }
            $isMulti && self::literal($stack, ')');
        } else {
            $stack[] = $value;
        }
    }

    protected static function columns(array &$stack, Statement\Columns $statement)
    {
        if (strpos($statement->sourceName, '.') !== false) {
            [$schema, $table] = array_map(function ($val) {
                return "'$val'";
            }, explode('.', $statement->sourceName));
        } else {
            [$schema, $table] = ['current_schema()', "'$statement->sourceName'"];
        }
        self::literal($stack, strtr(self::TPL_SCHEMA, [
            ':schema' => $schema,
            ':table' => $table
        ]));
    }

    protected static function join(array &$stack, Statement\Join $statement)
    {
        switch ($statement->type) {
            case Statement\Join::LEFT:
                self::literal($stack, ' LEFT JOIN ');
                break;
            case Statement\Join::RIGHT:
                self::literal($stack, ' RIGHT JOIN ');
                break;
            case Statement\Join::INNER:
                self::literal($stack, ' INNER JOIN ');
                break;
        }
        self::identifier($stack, $statement->source);
        self::literal($stack, ' ON ');
        self::condition($stack, $statement->on);
    }

    protected function count(array &$stack, Statement\Count $statement)
    {
        if ($statement->limit || $statement->offset || $statement->order || $statement->join) {
            self::literal($stack, 'SELECT COUNT(*) FROM (');
            $sub = new Statement\Select($statement->source);
            $sub->order = $statement->order;
            $sub->where = $statement->where;
            $sub->limit = $statement->limit;
            $sub->offset = $statement->offset;
            $schema = $this->schema($statement->source);
            if ($pk = $schema->getPrimary() ?? $schema->getUnique()) {
                foreach ($pk as $field) {
                    $sub->projection[] = $field->getName();
                }
            } else {
                $sub->projection = $schema->getNames();
            }
            self::select($stack, $sub);
            self::literal($stack, ') count_sub');
        } else {
            self::literal($stack, 'SELECT COUNT(*) FROM ');
            self::identifier($stack, $statement->source);
            if ($statement->where) {
                self::literal($stack, ' WHERE ');
                self::condition($stack, $statement->where);
            }
        }
    }

    protected function insert(array &$stack, Statement\Insert $statement)
    {
        self::literal($stack, 'INSERT INTO ');
        self::identifier($stack, $statement->source);
        self::literal($stack, ' (');
        foreach ($statement->columns as $i => $column) {
            if ($i) self::literal($stack, ', ');
            self::identifier($stack, $column);
        }
        self::literal($stack, ') VALUES ');
        foreach ($statement->values as $i => $values) {
            if ($i) self::literal($stack, ', ');
            self::literal($stack, ' (');
            foreach ($values as $j => $value) {
                if ($j) self::literal($stack, ', ');
                if ($value instanceof Statement\Expression\DefaultValue) {
                    self::literal($stack, ' DEFAULT');
                } else {
                    self::value($stack, $value);
                }
            }
            self::literal($stack, ')');
        }

        if ($statement->update) {
            $schema = $this->schema($statement->source);
            self::literal($stack, ' ON CONFLICT (');
            $pk = $schema->getPrimary() ?? $schema->getUnique();
            foreach ($pk as $i => $field) {
                if ($i) self::literal($stack, ', ');
                self::identifier($stack, $field->getName());
            }
            self::literal($stack, ') DO UPDATE SET ');
            foreach ($statement->columns as $i => $column) {
                if ($i) self::literal($stack, ', ');
                self::identifier($stack, $column);
                self::literal($stack, ' = EXCLUDED.');
                self::identifier($stack, $column);
            }
        }
    }

    protected function delete(array &$stack, Statement\Delete $statement)
    {
        if ($statement->offset || $statement->order || $statement->limit) {


            self::literal($stack, 'DELETE FROM ');
            self::identifier($stack, $statement->source);
            self::literal($stack, ' WHERE ');
            self::literal($stack, '(');

            $sub = new Statement\Select($statement->source);
            $sub->order = $statement->order;
            $sub->where = $statement->where;
            $sub->limit = $statement->limit;
            $sub->offset = $statement->offset;

            $schema = $this->schema($statement->source);
            $columns = [];
            if ($keys = $schema->getPrimary() ?? $schema->getUnique()) {
                foreach ($keys as $field) {
                    $columns[] = $field->getName();
                    $sub->projection[] = $field->getName();
                }
            } else {
                $sub->projection[] = $columns = $schema->getNames();
            }

            foreach ($columns as $i => $column) {
                if ($i > 0)
                    self::literal($stack, ', ');
                self::identifier($stack, $column);
            }
            self::literal($stack, ') IN (');
            self::select($stack, $sub);
            self::literal($stack, ')');
        } else {
            self::literal($stack, 'DELETE FROM ');
            self::identifier($stack, $statement->source);
            if ($statement->where) {
                self::literal($stack, ' WHERE ');
                self::condition($stack, $statement->where);
            }
        }
    }

    protected static function select(array &$stack, Statement\Select $statement)
    {
        self::literal($stack, 'SELECT ');

        if (!$statement->projection) {
            self::literal($stack, '*');
        } else {
            $coma = false;
            foreach ($statement->projection as $key => $val) {
                if ($coma) {
                    self::literal($stack, ', ');
                } else {
                    $coma = true;
                }
                if (is_int($key)) {
                    if ($val === '*') {
                        self::identifier($stack, $statement->source);
                        self::literal($stack, '.*');
                    } else {
                        self::identifier($stack, $val);
                    }
                } else {
                    if (is_string($val)) {
                        if ($val === '*') {
                            self::identifier($stack, $val);
                            self::literal($stack, '.*');
                        } else {
                            self::identifier($stack, $key);
                            self::literal($stack, ' ');
                            self::identifier($stack, $val);
                        }
                    } elseif (is_array($val)) {
                        foreach ($val as $k => $v) {
                            if (is_int($k)) {
                                self::identifier($stack, "$key.$v");
                            } else {
                                self::identifier($stack, "$key.$k");
                                self::literal($stack, ' ');
                                self::identifier($stack, $v);
                            }
                        }
                    }
                }
            }
        }

        self::literal($stack, ' FROM ');
        self::identifier($stack, $statement->source);

        if ($statement->join) {
            foreach ($statement->join as $join) {
                self::join($stack, $join);
            }
        }

        if ($statement->where) {
            self::literal($stack, ' WHERE ');
            self::condition($stack, $statement->where);
        }

        if ($statement->order) {
            self::literal($stack, ' ORDER BY ');
            $coma = false;
            foreach ($statement->order as $column => $direction) {
                if ($coma) {
                    self::literal($stack, ', ');
                }
                self::identifier($stack, $column);
                if ($direction === -1) {
                    self::literal($stack, ' DESC');
                }
            }
        }

        if ($statement->limit) {
            self::literal($stack, ' LIMIT ');
            self::value($stack, (int)$statement->limit);

        }
        if ($statement->offset) {
            self::literal($stack, ' OFFSET ');
            self::value($stack, (int)$statement->offset);
        }
    }

    protected function update(array &$stack = [], Statement\Update $statement)
    {
        self::literal($stack, 'UPDATE ');
        self::identifier($stack, $statement->source);
        self::literal($stack, ' SET ');


        $coma = false;
        foreach ($statement->set as $key => $value) {
            if ($coma) {
                self::literal($stack, ',');
            } else {
                $coma = true;
            }

            self::identifier($stack, $key);
            self::literal($stack, ' = ');
            self::value($stack, $value);
        }

        if ($statement->limit || $statement->offset || $statement->order) {
            self::literal($stack, ' FROM (');
            $schema = $this->schema($statement->source);
            $pk = $schema->getPrimary() ?? $schema;
            $ref = $statement->source . '_X0';
            $select = new Statement\Select($statement->source);

            foreach ($pk as $field) {
                $select->projection[] = $field->getName();
            }
            $select->limit = $statement->limit;
            $select->offset = $statement->offset;
            $select->where = $statement->where;
            $select->order = $statement->order;
            self::select($stack, $select);
            self::literal($stack, " FOR UPDATE) AS \"$ref\" WHERE ");

            $coma = false;
            foreach ($pk as $field) {
                if ($coma) {
                    self::literal($stack, ' AND ');
                } else {
                    $coma = true;
                }
                self::identifier($stack, "$statement->source.{$field->getName()}");
                self::literal($stack, ' = ');
                self::identifier($stack, "$ref.{$field->getName()}");
            }
        } elseif ($statement->where) {
            self::literal($stack, ' WHERE ');
            self::condition($stack, $statement->where);
        }
    }

    /**
     * @param \vivace\db\sql\Statement\Statement|array $statement
     * @param array $params
     *
     * @return array
     * @throws \Exception
     */
    public function build($statement): array
    {
        $stack = [];
        $sql = [];
        $values = [];
        $ph = 0;

        do {
            if (is_array($statement)) {
                $kind = $statement[0];
            } elseif (is_object($statement)) {
                $kind = get_class($statement);
            } else {
                throw new Exception("Not expected statement type", Exception::BUILDING);
            }
            switch ($kind) {
                case self::OP_LITERAL:
                    $sql[] = $statement[1];
                    break;
                case self::OP_IDENTIFIER:
                    $sql[] = '"' . str_replace('.', '"."', $statement[1]) . '"';
                    break;
                case self::OP_VALUE:
                    $s = '';
                    foreach ((array)$statement[1] as $i => $arg) {
                        $id = sprintf("X%x", $ph++);
                        $values[$id] = $arg;
                        if ($i) {
                            $s .= ',';
                        }
                        $s .= ':' . $id;
                    }
                    $sql[] = $s;
                    break;
                case '=':
                case '>':
                case '<':
                case '>=':
                case '<=':
                case '!=':
                    self::identifier($stack, $statement[1]);
                    self::literal($stack, ' ' . $statement[0] . ' ');
                    self::value($stack, $statement[2]);
                    break;
                case 'and':
                case 'or':
                    $expressions = array_slice($statement, 2);
                    self::literal($stack, '(');
                    self::condition($stack, $statement[1]);
                    $word = $statement[0] === 'and' ? 'AND' : 'OR';
                    foreach ($expressions as $expression) {
                        self::literal($stack, " $word ");
                        self::condition($stack, $expression);
                    }
                    self::literal($stack, ')');
                    break;
                case 'in':
                    if (is_array($statement[1])) {
                        self::literal($stack, '(');
                        foreach ($statement[1] as $i => $c) {
                            if ($i) self::literal($stack, ',');
                            self::identifier($stack, $c);
                        }
                        self::literal($stack, ') IN(');
                        foreach ($statement[2] as $i => $val) {
                            if ($i)
                                self::literal($stack, ', (');
                            else
                                self::literal($stack, '(');
                            foreach ($val as $j => $v) {
                                if ($j) self::literal($stack, ',');
                                self::value($stack, $v);
                            }
                            self::literal($stack, ')');
                        }
                        self::literal($stack, ')');

                    } else {
                        self::identifier($stack, $statement[1]);
                        self::literal($stack, ' IN(');
                        self::value($stack, $statement[2]);
                        self::literal($stack, ')');
                    }
                    break;
                case 'between':
                    self::identifier($stack, $statement[1]);
                    self::literal($stack, ' BETWEEN ');
                    self::value($stack, $statement[2]);
                    self::literal($stack, ' AND ');
                    self::value($stack, $statement[3]);
                    break;
                case Statement\Select::class:
                    /** @var \vivace\db\sql\Statement\Select $statement */
                    self::select($stack, $statement);
                    break;

                case Statement\Columns::class:
                    /** @var \vivace\db\sql\Statement\Columns $statement */
                    self::columns($stack, $statement);
                    break;
                case Statement\Update::class:
                    /** @var \vivace\db\sql\Statement\Update $statement */
                    $this->update($stack, $statement);
                    break;
                case Statement\Count::class:
                    /** @var \vivace\db\sql\Statement\Count $statement */
                    $this->count($stack, $statement);
                    break;
                case Statement\Delete::class:
                    /** @var \vivace\db\sql\Statement\Delete $statement */
                    $this->delete($stack, $statement);
                    break;
                case Statement\Insert::class:
                    /** @var \vivace\db\sql\Statement\Insert $statement */
                    $this->insert($stack, $statement);
                    break;
                default:
                    throw new Exception("Not supported statement " . $kind, Exception::BUILDING);
            }
        } while ($statement = array_pop($stack));

        $sql = implode('', array_reverse($sql));
        return [$sql, $values];
    }

    /**
     * @param \vivace\db\sql\Statement\Read $query
     *
     * @return \vivace\db\sql\Fetcher
     * @throws \Exception
     */
    public function fetch(Statement\Read $query): \vivace\db\sql\Fetcher
    {
        [$sql, $params] = $this->build($query);
        return new Fetcher($this->pdo, $sql, $params);
    }

    /**
     * @param \vivace\db\sql\Statement\Modifier $query
     *
     * @return int
     * @throws \Exception
     */
    public function execute(Statement\Modifier $query): Result
    {

        [$sql, $params] = $this->build($query);
        if ($params) {
            $stmt = $this->pdo->prepare($sql);

            foreach ($params as $k => $v) {
                if (is_int($v)) {
                    $stmt->bindValue($k, $v, \PDO::PARAM_INT);
                } elseif (is_bool($v)) {
                    $stmt->bindValue($k, $v, \PDO::PARAM_BOOL);
                } else {
                    $stmt->bindValue($k, $v, \PDO::PARAM_STR);
                }
            }

            if (!$stmt->execute()) {
                [$sqlstate, $driverCode, $text] = $stmt->errorInfo();
                throw new \Exception("SQLSTATE[$sqlstate]: ($driverCode) $text", $sqlstate);
            }

            $affected = $stmt->rowCount();
        } else {
            $affected = $this->pdo->exec($sql);
        }

        return new class($this->pdo->lastInsertId(), $affected) implements Result
        {
            protected $insertedId;
            protected $affected;

            public function __construct($insertedId, $affected)
            {
                $this->insertedId = $insertedId ? (int)$insertedId : null;
                $this->affected = $affected ? (int)$affected : null;
            }

            public function getAffected(): ?int
            {
                return $this->affected;
            }

            public function getInsertedId(): ?int
            {
                return $this->insertedId;
            }
        };
    }
}
