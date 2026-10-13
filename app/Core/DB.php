<?php
declare(strict_types=1);

namespace Vobl\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * 数据访问层
 * ============================================================================
 * 防 SQL 注入的强制约定（本类就是为了让「写错」变得困难）：
 *
 *   1. 所有带变量的查询一律走占位符 + bind，本类只提供这一个入口。
 *      外部无法直接拿到 PDO 实例去拼字符串。
 *   2. 连接参数强制 ATTR_EMULATE_PREPARES = false。
 *      关闭模拟预处理后，SQL 语句与数据是分两次发给 MySQL 的，
 *      参数值永远不会被当成 SQL 语法解析 —— 这是防注入的根本。
 *   3. 表名、列名、ORDER BY 字段这类「无法用占位符」的位置，
 *      必须经过 identifier() 白名单校验，绝不允许来自用户输入的原样拼接。
 *   4. 没有提供任何「把变量插进 SQL 字符串」的辅助方法。
 *      DDL 用的 exec() 单独命名，且只应出现在建表脚本里。
 * ============================================================================
 */
final class DB
{
    private static ?DB $instance = null;
    private PDO $pdo;
    private string $driver;

    private function __construct(array $cfg)
    {
        $this->driver = $cfg['driver'] ?? 'mysql';

        if ($this->driver === 'sqlite') {
            // 仅用于本地自测
            $path = $cfg['database'] ?? ':memory:';
            $this->pdo = new PDO('sqlite:' . $path);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'] ?? '127.0.0.1',
                (int) ($cfg['port'] ?? 3306),
                $cfg['database'] ?? '',
                $cfg['charset'] ?? 'utf8mb4'
            );
            $this->pdo = new PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . ($cfg['charset'] ?? 'utf8mb4')
                    . " COLLATE utf8mb4_unicode_ci",
            ]);
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        // ★ 关键安全开关：关闭模拟预处理
        if (!empty($cfg['strict_prepare'])) {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
    }

    public static function init(array $cfg): void
    {
        self::$instance = new self($cfg);
    }

    public static function conn(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('数据库尚未初始化，请先调用 DB::init()');
        }
        return self::$instance;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    // ---------------------------------------------------------------- 查询

    /**
     * 准备并执行一条语句。params 支持位置占位符（?）与命名占位符（:name）。
     * 这是本类唯一允许携带变量的查询入口。
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('SQL 准备失败：' . substr($sql, 0, 120));
        }
        foreach ($params as $key => $val) {
            $name = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($val)  => PDO::PARAM_INT,
                is_bool($val) => PDO::PARAM_BOOL,
                is_null($val) => PDO::PARAM_NULL,
                default       => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $val, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** 取全部行 */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** 取一行，无结果返回 null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** 取单个标量值 */
    public function val(string $sql, array $params = [], $default = null)
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    public function count(string $sql, array $params = []): int
    {
        return (int) $this->val($sql, $params, 0);
    }

    // ---------------------------------------------------------------- 写入

    /**
     * 插入。表名与列名必须来自代码常量或 identifier() 校验过的白名单。
     * @return int 自增主键
     */
    public function insert(string $table, array $data): int
    {
        $table = $this->identifier($table);
        $cols = [];
        $marks = [];
        $params = [];
        foreach ($data as $col => $val) {
            $cols[] = $this->identifier($col);
            $marks[] = '?';
            $params[] = $val;
        }
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $marks) . ')';
        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 更新。$where 与 $whereParams 同样走占位符。
     * @return int 影响行数
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $table = $this->identifier($table);
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = $this->identifier($col) . ' = ?';
            $params[] = $val;
        }
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return $this->query($sql, array_merge($params, $whereParams))->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $table = $this->identifier($table);
        return $this->query('DELETE FROM ' . $table . ' WHERE ' . $where, $params)->rowCount();
    }

    // ------------------------------------------------------------ 标识符校验

    /**
     * 校验表名 / 列名是否安全。
     * 占位符不能用于标识符位置，所以只能靠白名单正则：
     * 只允许字母、数字、下划线，且必须以字母或下划线开头。
     * 任何带反引号、空格、点号、括号、注释符的输入都会被拒绝。
     */
    public function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException("非法的数据库标识符：{$name}");
        }
        return '`' . $name . '`';
    }

    /**
     * 校验 ORDER BY 的字段与方向。
     * ORDER BY 后面同样不能绑参数，必须走这个白名单。
     */
    public function orderBy(?string $column, array $allowed, string $default, string $dir = 'DESC'): string
    {
        $col = in_array($column, $allowed, true) ? $column : $default;
        $direction = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
        return $this->identifier($col) . ' ' . $direction;
    }

    // ---------------------------------------------------------------- 事务

    /**
     * 在事务中执行。抛异常自动回滚。
     * 凡是「清空+重建」「扣款+记账」这类多步写操作，必须包在这里面。
     */
    public function tx(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 原子递增。用于余额、计数这类并发敏感的更新。
     * 用 SQL 表达式在同一语句内完成，避免「读-改-写」的竞态。
     */
    public function increment(string $table, string $column, int $delta, string $where, array $params = []): int
    {
        $table  = $this->identifier($table);
        $column = $this->identifier($column);
        $sign   = $delta >= 0 ? '+' : '-';
        $abs    = abs($delta);
        $sql = "UPDATE {$table} SET {$column} = {$column} {$sign} ? WHERE {$where}";
        return $this->query($sql, array_merge([$abs], $params))->rowCount();
    }

    /** 仅用于 DDL（建表、加索引）。命名上刻意与业务查询区分开。 */
    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /** 底层 PDO，仅在极少数需要特殊能力的场景使用（如 lastInsertId） */
    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
