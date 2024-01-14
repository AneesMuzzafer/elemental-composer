<?php

namespace Core\Model;

use Core\Database\Database;
use Core\Exception\ModelNotFoundException;
use Core\Main\Application;
use PDO;
use ReflectionClass;

class Model
{
    private Database $db;
    protected string $tableName;
    protected string $primaryKey = "id";

    protected array $data = [];

    public function __construct()
    {
        $this->tableName = $this->getTableNameFromClass();
        $this->db = Application::getInstance()->make(Database::class);
    }

    public function setData(array $data, $id = null)
    {
        if (!is_null($id)) {
            $this->data = array_merge($data, [$this->primaryKey => $id]);
        }

        foreach ($data as $column => $value) {
            $this->data[$column] = $value;
        }
    }

    public static function create(array $data)
    {
        $model = new static();
        foreach ($data as $column => $value) {
            $model->data[$column] = $value;
        }

        return $model->save();
    }

    public static function find($id)
    {
        $model = new static();
        return $model->getFromPrimaryKey($id);
    }

    public function getFromPrimaryKey($id)
    {
        $sql = "SELECT * FROM $this->tableName WHERE $this->primaryKey = :id;";
        $statement = $this->db->prepare($sql);

        $statement->bindValue(':id', $id);
        $statement->execute();

        $data = $statement->fetch(PDO::FETCH_ASSOC);

        if (empty($data)) {
            return null;
        }

        $this->setData($data);
        return $this;
    }

    public function save()
    {
        $sql = $this->getSQL();

        $statement = $this->db->prepare($sql);

        foreach ($this->data as $column => $value) {
            $statement->bindValue(':' . $column, $value);
        }

        $statement->execute();

        $lastInsertId = $this->db->lastInsertId();

        $this->setData($this->data, $lastInsertId);

        return $this;
    }

    public function getSQL()
    {
        if (!isset($this->data['id'])) {
            $columns = implode(', ', array_keys($this->data));
            $values = implode(', ', array_map(fn ($val, $index) => ':' . $index, $this->data, array_keys($this->data)));

            $sql = "INSERT INTO $this->tableName ($columns) VALUES ($values);";
            return $sql;
        } else {
            $setStmt = implode(', ', array_map(fn ($column) => "$column = :$column", array_keys($this->data)));

            $sql = "UPDATE $this->tableName SET $setStmt WHERE $this->primaryKey = :id;";

            return $sql;
        }
    }

    public static function update($id, array $data)
    {
        if (is_null($id)) {
            throw new \InvalidArgumentException("'id' cannot be null.");
        }

        $model = static::find($id);

        if (is_null($model)) {
            throw new ModelNotFoundException("No model could be found with the given 'id'.");
        }

        foreach($data as $key => $value) {
            $model->data[$key] = $value;
        }

        return $model->save();
    }

    public function destroy()
    {
        if (!isset($this->data['id'])) {
            throw new \InvalidArgumentException("Delete operation requires an 'id' to be set in the model data.");
        }

        $id = $this->data['id'];

        $sql = "DELETE FROM $this->tableName WHERE $this->primaryKey = :id;";
        $statement = $this->db->prepare($sql);

        $statement->bindValue(':id', $id);
        $statement->execute();

        $this->data = [];

        return $id;
    }

    public static function delete($id)
    {
        if (is_null($id)) {
            throw new \InvalidArgumentException("'id' cannot be null.");
        }

        $model = static::find($id);

        if (is_null($model)) {
            throw new ModelNotFoundException("No model could be found with the given 'id'.");
        }
        $model->data[$model->primaryKey] = $id;
        return $model->destroy();
    }

    public static function where(array $conditions)
    {
        $model = new static();

        $validOperators = ['=', '!=', '<', '>', '<=', '>=', "LIKE", "IS NULL", "IS NOT NULL"];

        $whereConditions = [];
        foreach ($conditions as $column => $condition) {
            list($operator, $value) = is_array($condition) ? $condition : ['=', $condition];

            $operator = in_array($operator, $validOperators) ? $operator : '=';

            $whereConditions[] = "$column $operator :$column";
        }

        $whereClause = implode(' AND ', $whereConditions);

        $sql = "SELECT * FROM {$model->tableName} WHERE $whereClause;";
        $statement = $model->db->prepare($sql);

        foreach ($conditions as $column => $condition) {
            list($operator, $value) = is_array($condition) ? $condition : ['=', $condition];
            $statement->bindValue(':' . $column, $value);
        }

        $statement->execute();

        $data = $statement->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            $model->setData($data);
            return $model;
        } else {
            return null;
        }
    }

    public static function all($fetchMethod = PDO::FETCH_ASSOC)
    {
        $model = new static();

        $sql = "SELECT * FROM $model->tableName";
        $statement = $model->db->prepare($sql);
        $statement->execute();

        $data = $statement->fetchAll($fetchMethod);

        return $data;
    }

    public static function allWhere(array $conditions = [], array $options = [], $fetchMethod = PDO::FETCH_ASSOC)
    {
        $model = new static();

        $validOperators = ['=', '!=', '<', '>', '<=', '>=', "LIKE", "IS NULL", "IS NOT NULL"];

        $whereConditions = [];
        foreach ($conditions as $column => $condition) {
            list($operator, $value) = is_array($condition) ? $condition : ['=', $condition];

            $operator = in_array($operator, $validOperators) ? $operator : '=';

            $whereConditions[] = "$column $operator :$column";
        }

        $whereClause = "";
        if (count($conditions) > 0) {
            $whereClause = "WHERE " . implode(' AND ', $whereConditions);
        }

        $orderBy = isset($options['orderBy']) ? 'ORDER BY ' . $options['orderBy'] . ' ' . ($options['sortDir'] ?? 'ASC') : '';
        $limit = isset($options['limit']) ? 'LIMIT ' . $options['limit'] : '';
        $offset = isset($options['offset']) ? 'OFFSET ' . $options['offset'] : '';

        $sql = "SELECT * FROM $model->tableName $whereClause $orderBy $limit $offset;";

        $statement = $model->db->prepare($sql);

        foreach ($conditions as $column => $condition) {
            list($operator, $value) = is_array($condition) ? $condition : ['=', $condition];
            $statement->bindValue(':' . $column, $value);
        }

        $statement->execute();

        $data = $statement->fetchAll($fetchMethod);

        return $data;
    }

    public function data()
    {
        return $this->data;
    }

    public function __get($name)
    {
        if (isset($this->data[$name])) {
            return $this->data[$name];
        }

        return null;
    }

    public function __set(string $name, mixed $value)
    {
        $this->data[$name] = $value;
    }

    public function getTableNameFromClass()
    {
        $reflect = new ReflectionClass($this);
        $className = $reflect->getShortName();

        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
        $pluralizedSnakeCase = $this->pluralize($snakeCase);

        return $pluralizedSnakeCase;
    }

    private function pluralize($singular)
    {
        $lastChar = substr($singular, -1);
        $secondLastChar = substr($singular, -2, 1);

        if ($lastChar === 'y' && !in_array($secondLastChar, ['a', 'e', 'i', 'o', 'u'])) {
            return substr($singular, 0, -1) . 'ies';
        } elseif ($lastChar === 's' || $lastChar === 'x' || $lastChar === 'z') {
            return $singular . 'es';
        } else {
            return $singular . 's';
        }
    }
}
