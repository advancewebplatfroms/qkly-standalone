<?php
namespace Qkly;

class Api
{

    protected static $db;
    protected static $table;
    protected static $id;
    protected static $params;

    public static function dispatch($segments, $params, $id = null)
    {
        if (isset($segments[2])) {
            self::$table = $segments[2];
        } else {
            http_response_code(404);
            echo 404;
            exit();
        }
        self::$db = Database::connect($_ENV['DB_DATABASE']);
        self::$params = $params;
        self::$id = $id;

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                self::fetch();
                break;
            case 'POST':
                self::insert();
                break;
            case 'PUT':
                // $this->update();
                break;
            case 'DELETE':
                // $this->delete();
                break;
        }
    }

    private static function fetch()
    {
        $sql = "SELECT " . (isset(self::$params['columns']) ? self::$params['columns'] : "*");
        $sql .= " FROM " . self::$table;

        if (isset(self::$params['join'])) {
            foreach (self::$params['join'] as $join) {
                $join_column = $join;
                $sql .= " INNER JOIN " . $join . " ON " . $join . ".id = " . self::$table . "." . $join_column;
            }
        }

        $conditions = [];
        $filters = [];

        if (self::$id != null) {
            $column = "id";
            $bindKey = "pk";
            $value = self::$id;
            $conditions[] = "{$column} = :{$bindKey}";
            $filters[$bindKey] = "{$value}";
        }

        if (isset(self::$params['filter'])) {
            foreach (self::$params['filter'] as $key => $filter) {
                $f = explode(",", $filter, 2);
                $column = $f[0];

                $break = explode(",", $f[1], 2);
                $matchType = $break[0];
                $value = $break[1];
                $bindKey = $column . $key;
                switch ($matchType) {
                    case 'cs':
                        $conditions[] = "{$column} LIKE :{$bindKey}";
                        $filters[$bindKey] = "%{$value}%";
                        break;
                    case 'sw':
                        $conditions[] = "{$column} LIKE :{$bindKey}";
                        $filters[$bindKey] = "{$value}%";
                        break;
                    case 'ew':
                        $conditions[] = "{$column} LIKE :{$bindKey}";
                        $filters[$bindKey] = "%{$value}";
                        break;
                    case 'eq':
                        $conditions[] = "{$column} = :{$bindKey}";
                        $filters[$bindKey] = "{$value}";
                        break;
                    case 'lt':
                        $conditions[] = "{$column} < :{$bindKey}";
                        $filters[$bindKey] = "{$value}";
                        break;
                    case 'le':
                        $conditions[] = "{$column} <= :{$bindKey}";
                        $filters[$bindKey] = "{$value}";
                        break;
                    case 'ge':
                        $conditions[] = "{$column} >= :{$bindKey}";
                        $filters[$bindKey] = "{$value}";
                        break;
                    case 'gt':
                        $conditions[] = "{$column} > :{$bindKey}";
                        $filters[$bindKey] = "{$value}";
                        break;
                    case 'bt':
                        $conditions[] = "({$column} BETWEEN :min_{$bindKey} AND :max_{$bindKey})";
                        $break_values = explode(",", $value);
                        $filters["min_{$bindKey}"] = $break_values[0];
                        $filters["max_{$bindKey}"] = $break_values[1];
                        break;
                    case 'in':
                        $conditions[] = "{$column} IN ({$bindKey})";
                        $filters[$bindKey] = "{$value}";
                        break;
                }
            }
        }

        if (sizeof($conditions) > 0) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $stmt = self::$db->prepare($sql);
        if (sizeof($filters) > 0) {
            $stmt->execute($filters);
        } else {
            $stmt->execute();
        }
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('records' => $records));
    }

    private static function insert()
    {
        $body = file_get_contents('php://input');
        if (!empty($body)) {
            $body = json_decode($body, true); // Decode JSON body into an associative array
        } else {
            $body = $_POST;
        }

        if (!empty($_FILES)) {
            $filesData = self::processFiles();  // Process uploaded files
            if (!is_array(reset($body))) {
                $body = array_merge($body, $filesData);
            } else {
                $body = array_map(function ($item) use ($filesData) {
                    return array_merge($item, $filesData);  // Merge file data with body data for each record
                }, $body);
            }
        }

        if (!is_array(reset($body))) {
            $body = [$body];
        }


        $firstRecord = reset($body);
        $columns = array_keys($firstRecord);
        $columnsStr = '`' . implode('`, `', $columns) . '`';

        $placeholdersArray = [];
        $values = [];
        foreach ($body as $record) {
            $placeholdersArray[] = '(' . implode(',', array_fill(0, count($record), '?')) . ')';
            foreach ($record as $value) {
                $values[] = $value;  // Flatten the values array
            }
        }
        $placeholdersStr = implode(',', $placeholdersArray);

        $sql = "INSERT INTO " . self::$table . " ($columnsStr) VALUES $placeholdersStr";
        try {
            $stmt = self::$db->prepare($sql);
            $stmt->execute($values);
            return [
                "status" => "success",
                "message" => "Records inserted successfully.",
                "insertedIds" => range(self::$db->lastInsertId(), self::$db->lastInsertId() + $stmt->rowCount() - 1)
            ];
        } catch (\PDOException $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('error' => $e->getMessage()));
        }
    }

    private static function processFiles()
    {
        $filesData = [];
        foreach ($_FILES as $key => $file) {
            $filesData[$key] = Storage::create($file);
        }
        return $filesData;
    }
}