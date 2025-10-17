<?php
/**
 * MysqlSync.php
 *
 * @Author  : 9rax.dev@gmail.com
 * @DateTime: 2019/3/3 2:00
 */
namespace DDZH;

class MysqlStructSync
{
    /**
     * Execute sql status statistics
     *
     * @var array
     */
    private $execute_sql_stat = array();
    private $diff_sql = array();
    private $self_database_struct;
    private $refer_database_struct;
    private $self_connection;
    private $self_db;
    private $refer_connection;
    private $refer_db;
    private $remove_auto_increment = false;
    private $backup_prefix = 'backup_';
    /**
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/8/30 19:31
     * @var array
     */
    static $advance = [
        'VIEW' => ["SELECT TABLE_NAME as Name FROM information_schema.VIEWS WHERE TABLE_SCHEMA='#'", 'Create View'],
        'TRIGGER' => ["SELECT TRIGGER_NAME as Name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='#'", 'SQL Original Statement'],
        'EVENT' => ["SELECT EVENT_NAME  as Name FROM information_schema.EVENTS WHERE EVENT_SCHEMA='#'", 'Create Event'],
        'FUNCTION' => ["SHOW FUNCTION STATUS  WHERE Db='#'", 'Create Function'],
        'PROCEDURE' => ["show PROCEDURE STATUS WHERE Db='#'", 'Create Procedure']
    ];

    /**
     * MysqlStructSync constructor.
     *
     * @param array $self_db_conf
     * @param array $refer_config Can be a DB config array or a pre-fetched structure array
     */
    public function __construct(array $self_db_conf, array $refer_config)
    {
        @$this->self_connection = new \Mysqli($self_db_conf['host'], $self_db_conf['username'], $self_db_conf['passwd'], $self_db_conf['dbname'], isset($self_db_conf['port']) ? $self_db_conf['port'] : 3306);

        if ($this->self_connection->connect_errno) {
            die("Database connection failed:" . $this->self_connection->connect_error);
        }
        $this->self_db = $self_db_conf['dbname'];

        // (新逻辑) 判断 refer_config 是连接还是结构
        if (isset($refer_config['host'])) { // It's a connection config
            @$this->refer_connection = new \Mysqli($refer_config['host'], $refer_config['username'], $refer_config['passwd'], $refer_config['dbname'], isset($refer_config['port']) ? $refer_config['port'] : 3306);

            if ($this->refer_connection->connect_errno) {
                die("Database connection failed:" . $this->refer_connection->connect_error);
            }
            $this->refer_db = $refer_config['dbname'];
        } else { // It's a structure array
            $this->refer_database_struct = $refer_config;
            $this->refer_db = 'pre-fetched structure'; // Set a placeholder name
        }

        if (isset($_POST['MysqlStructSync']) && $_POST['MysqlStructSync']) {
            $this->diff_sql = $_POST['MysqlStructSync'];
            $this->dump($this->execute());
            die;
        }
    }

    /**
     * (新增) 静态方法，用于获取单个数据库的结构数组
     *
     * @param array $db_conf
     * @return array
     */
    public static function fetchStructureArray(array $db_conf)
    {
        $instance = new self($db_conf, []); // The second param is a dummy empty structure
        $instance->refer_connection = null; // Prevent any accidental usage
        return $instance->getStructure($instance->self_connection);
    }

    /**
     * remove_auto_increment
     *
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/12 19:56
     */
    function removeAutoIncrement()
    {
        $this->remove_auto_increment = true;
    }

    /**
     * (新增) 设置用于忽略的备份表前缀
     *
     * @param string $prefix
     * @return $this
     */
    public function setBackupPrefix(string $prefix)
    {
        $this->backup_prefix = $prefix;
        return $this;
    }

    /**
     * Compare database structure
     *
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/12 19:54
     */
    function baseDiff()
    {
        $this->self_database_struct = $this->getStructure($this->self_connection);
        // (新逻辑) 如果目标结构已预加载，则跳过数据库查询
        if (!$this->refer_database_struct) {
            $this->refer_database_struct = $this->getStructure($this->refer_connection);
        }

        $res = [];
        $res['ADD_TABLE'] = array_diff($this->refer_database_struct['tables'] ?? [], $this->self_database_struct['tables'] ?? []);
        $res['DROP_TABLE'] = array_diff($this->self_database_struct['tables'] ?? [], $this->refer_database_struct['tables'] ?? []);

        $self_cols = $this->self_database_struct['columns'];
        $refer_cols = $this->refer_database_struct['columns'];

        // (新逻辑-修复) 查找要检查列差异的公共表
        $common_tables = array_intersect(
            $this->self_database_struct['tables'] ?? [],
            $this->refer_database_struct['tables'] ?? []
        );

        foreach ($common_tables as $table) {
            $self_table_cols = $self_cols[$table] ?? [];
            $refer_table_cols = $refer_cols[$table] ?? [];

            // 通过遍历目标结构来查找新增和修改的列
            foreach ($refer_table_cols as $field => $def) {
                if (!isset($self_table_cols[$field])) {
                    // 列存在于目标但不存在于源 -> ADD
                    $res['ADD_FIELD'][$table][$field] = $def;
                } elseif ($self_table_cols[$field] !== $def) {
                    // 列存在于两者中，但定义不同 -> MODIFY
                    $res['MODIFY_FIELD'][$table][$field] = $def;
                }
            }

            // 通过遍历源结构来查找删除的列
            foreach ($self_table_cols as $field => $def) {
                if (!isset($refer_table_cols[$field])) {
                    // 列存在于源但不存在于目标 -> DROP
                    $res['DROP_FIELD'][$table][$field] = $def;
                }
            }
        }

        $res['DROP_CONSTRAINT'] = self::array_diff_assoc_recursive($this->self_database_struct['constraints'], $this->refer_database_struct['constraints'], $res['DROP_TABLE']);
        $res['ADD_CONSTRAINT'] = self::array_diff_assoc_recursive($this->refer_database_struct['constraints'], $this->self_database_struct['constraints'], $res['ADD_TABLE']);

        foreach (array_filter($res) as $type => $data) {
            $this->getExecuteSql($data, $type);
        }
    }
    /**
     * array_intersect_assoc
     * @return mixed
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/8/30 19:27
     */
    static function _array_intersect_assoc() {
        $args = func_get_args();
        $res = $args[0];
        for ($i=1;$i<count($args);$i++) {
            if (!is_array($args[$i])) {continue;}
            foreach ($res as $key => $data) {
                if ( (!array_key_exists($key, $args[$i])) || ( (isset($args[$i][$key])) && ($args[$i][$key] !== $res[$key]) ) ) {
                    unset($res[$key]);
                }
            }
        }
        return $res;
    }

    /**
     * advanceDiff
     *
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/12 19:54
     */
    function advanceDiff()
    {
        $arr = [];
        $diff = [];
        foreach (self::$advance as $type => $list_sql) {
            foreach (['self', 'refer'] as $who) {
                // (新逻辑) 如果是预加载的结构，则跳过 refer 数据库
                if ($who === 'refer' && !$this->refer_connection) {
                    // 假设预加载的结构不包含高级对象，或者需要另一种方式来处理
                    // 目前，我们将跳过，以避免错误
                    continue;
                }
                $conn = $who . '_connection';
                $db = $who . '_db';
                $sql = str_replace('#', $this->$db, $list_sql[0]);
                $connect = $this->$conn->query($sql);
                $res = $connect->fetch_all(MYSQLI_ASSOC);
                if($res){
                    foreach ($res as $row) {
                        $show_create_conn = $this->$conn->query('SHOW CREATE ' . $type . ' ' . $row['Name']);
                        $arr[$type][$who][$row['Name']] = preg_replace('/DEFINER=[^\s]*/', '', $show_create_conn->fetch_assoc()[$list_sql[1]]);
                    }
                }
                $diff['ADD_' . $type] = self::array_diff_assoc_recursive(isset($arr[$type]['refer'])?$arr[$type]['refer']:[], isset($arr[$type]['self'])?$arr[$type]['self']:[]);
                $diff['DROP_' . $type] = self::array_diff_assoc_recursive(isset($arr[$type]['self'])?$arr[$type]['self']:[], isset($arr[$type]['refer'])?$arr[$type]['refer']:[]);
            }
        }
        foreach (array_filter($diff) as $type => $data) {
            $this->getExecuteSql($data, $type);
        }
    }
    /**
     * getResult
     *
     * @return array
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/12 19:54
     */
    function getDiffSql()
    {
        return $this->diff_sql;
    }

    /**
     * (新增) 获取按表分组并合并 ALTER 语句的差异 SQL
     * @return array
     */
    public function getGroupedDiffSql()
    {
        $alterationsByTable = [];
        $otherStatements = [];
        $finalSql = [];

        foreach ($this->diff_sql as $type => $sqls) {
            foreach ($sqls as $sql) {
                $trimmedSql = rtrim(trim($sql), ';');
                if (empty($trimmedSql)) continue;

                if (preg_match('/^ALTER TABLE `(.*?)` (.*)/i', $trimmedSql, $matches)) {
                    $tableName = $matches[1];
                    $alteration = $matches[2];
                    $alterationsByTable[$tableName][] = $alteration;
                } else {
                    $otherStatements[] = $trimmedSql;
                }
            }
        }

        foreach ($alterationsByTable as $tableName => $alterations) {
            $finalSql[] = "ALTER TABLE `{$tableName}` " . implode(', ', $alterations) . ';';
        }

        foreach ($otherStatements as $statement) {
            $finalSql[] = $statement . ';';
        }

        return $finalSql;
    }

    /**
     * getExecuteSql
     *
     * @param $arr
     * @param $type
     *
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/11 22:35
     */
    function getExecuteSql($arr, $type)
    {
        foreach ($arr as $table => $rows) {
            $sql = '';
            if (in_array($type, ['ADD_TABLE', 'DROP_TABLE'])) {
                $sql = $type == 'ADD_TABLE' ? $this->refer_database_struct['show_create'][$rows] : "DROP TABLE IF EXISTS {$rows}";
                $this->diff_sql[$type][] = rtrim($sql, ',');
                continue;
            }
            if (in_array($type, ['ADD_VIEW', 'DROP_VIEW', 'ADD_TRIGGER', 'DROP_TRIGGER', 'ADD_EVENT', 'DROP_EVENT', 'ADD_FUNCTION', 'DROP_FUNCTION', 'ADD_PROCEDURE', 'DROP_PROCEDURE'])) {
                $sql = strpos($type, 'ADD') !== false ? $rows : str_replace('_', '', $rows) . ' ' . $table;
                $this->diff_sql[$type][] = $sql;
                continue;
            }
            foreach ($rows as $key => $val) {
                switch ($type) {
                    case 'MODIFY_FIELD':
                        $sql = "ALTER TABLE `{$table}` MODIFY {$val}";
                        break;
                    case 'DROP_FIELD':
                        $sql = "ALTER TABLE `{$table}` DROP {$key}";
                        break;
                    case 'ADD_FIELD':
                        $sql = "ALTER TABLE `{$table}` ADD {$val}";
                        break;
                    case 'ADD_CONSTRAINT':
                        $sql = self::getConstraintQuery($val, $table)['add'];
                        break;
                    case 'DROP_CONSTRAINT':
                        $sql = self::getConstraintQuery($val, $table)['drop'];
                        break;
                }
                $this->diff_sql[$type][] = rtrim($sql, ',');
            }
        }
    }

    /**
     * @var array $patterns
     *  Here is the reference  "https://github.com/ibraheem-ghazi/dbdiff/blob/master/src/SqlDumper.class.php"
     */
    static $patterns = [
        'primary' => '(^[^`]\s*PRIMARY KEY .*[,]?$)',
        'key' => '(^[^`]\s*KEY\s+(`.*`) .*[,]?$)',
        'constraint' => '(^[^`]\s*CONSTRAINT\s+(`.*`) .*[,]?$)',
    ];

    /**
     * getConstraintQuery
     * Here is the reference  "https://github.com/ibraheem-ghazi/dbdiff/blob/master/src/SqlDumper.class.php"
     * @param $constraint
     * @param $table
     *
     * @return array
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/11 22:35
     */
    static function getConstraintQuery($constraint, $table)
    {
        foreach (static::$patterns as $key => $pattern) {
            if (preg_match("/" . str_replace('^[^`]', '', $pattern) . "$/m", $constraint, $matches)) {
                switch ($key) {
                    case 'primary':
                        return ['drop' => 'ALTER TABLE `' . $table . '` DROP PRIMARY KEY;', 'add' => 'ALTER TABLE `' . $table . '` ADD ' . rtrim($constraint, ',')];
                    case 'key':
                        return ['drop' => "ALTER TABLE `{$table}` DROP KEY $matches[2];", 'add' => 'ALTER TABLE `' . $table . '` ADD ' . rtrim($constraint, ',')];
                    case 'constraint':
                        return ['drop' => "ALTER TABLE `{$table}` DROP CONSTRAINT $matches[2];", 'add' => 'ALTER TABLE `' . $table . '` CONSTRAINT ' . rtrim($constraint, ',')];
                }
                break;
            }
        }
    }

    /**
     * array_diff_assoc_recursive
     *
     * @param       $array1
     * @param       $array2
     * @param array $exclude
     *
     * @return array
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/11 22:35
     */
    static function array_diff_assoc_recursive($array1, $array2, $exclude = [])
    {
        $ret = array();
        if($array1){
            foreach ($array1 as $k => $v) {
                if ($exclude && in_array($k, $exclude)) {
                    continue;
                }
                if (!isset($array2[$k])) $ret[$k] = $v;
                else if (is_array($v) && is_array($array2[$k])) $ret[$k] = self::array_diff_assoc_recursive($v, $array2[$k]);
                else if ($v != $array2[$k]) $ret[$k] = $v;
                else {
                    unset($array1[$k]);
                }
            }
        }
        return array_filter($ret);
    }
    /**
     * Manually call the execute sql   for manuallySelectUpdates
     *
     * @return array
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/4 11:12
     */
    function execute()
    {
        $this->execute_sql_stat = array();
        $diff_sqls = array_filter($this->diff_sql);
        if ($diff_sqls) {
            $add_tables=isset($diff_sqls['ADD_TABLE'])?$diff_sqls['ADD_TABLE']:null;
            if($add_tables){
                unset($diff_sqls['ADD_TABLE']);
                $this->executeAddTables($add_tables);
            }
            foreach ($diff_sqls as $type => $sqls) {
                foreach ($sqls as $sql) {
                    if ($this->self_connection->query($sql)) {
                        $this->execute_sql_stat['success'][$type][] = $sql;
                    } else {
                        $this->execute_sql_stat['error'][$type][] = $sql;
                    }
                }
            }
        }
        return $this->execute_sql_stat;
    }

    /**
     * executeAddTables
     * @param $add_tables_sqls
     *
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/12 23:38
     */
    private function executeAddTables($add_tables_sqls){
        $this->self_connection->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($add_tables_sqls as $key=>$sql){
            if ($this->self_connection->query($sql)) {
                $this->execute_sql_stat['success']['ADD_TABLE'][] = $sql;
                unset($add_tables_sqls[$key]);
            }else{
                $this->execute_sql_stat['error']['ADD_TABLE'][] = $sql;
            }
        }
        $this->self_connection->query('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Manually select the updated sql
     *
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/4 11:14
     */
    function manuallySelectUpdates()
    {
        if ($this->diff_sql) {
            $form = '<form action="" method="post" style="font-size: 12px;padding: 0 50px;">';
            foreach ($this->diff_sql as $type => $sqls) {
                $form .= '<h2>' . $type . '</h2>';
                foreach ($sqls as $sql) {
                    $form .= '<div style="height: auto;overflow-y: hidden;"><input type="checkbox" name="MysqlStructSync[' . $type . '][]" value="' . $sql . '" style="float: left;position: absolute;margin-left: -15px;margin-top: 20px;"><pre style="float: left;"><code class="language-sql line-numbers">' . $sql . '</code></pre></div>';
                }
            }
            $form .= '<input type="submit"></form>';
            $form .= '<link rel="stylesheet" href="https://cdn.staticfile.org/prism/9000.0.1/themes/prism.min.css"><script src="https://cdn.staticfile.org/prism/9000.0.1/prism.min.js"></script><script src="https://cdn.staticfile.org/prism/9000.0.1/components/prism-sql.min.js"></script>';
            die($form);
        }
    }

    /**
     * getStructure
     *
     * @param \Mysqli $resource
     *
     * @return array
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/11 13:06
     */
    private function getStructure($resource)
    {
        $stmt1 = $resource->query("SHOW TABLE STATUS WHERE Comment!='VIEW'");
        $alert_columns = [];
        $constraints = [];
        $show_create = [];
        $tables = [];
        $pattern = '/' . implode('|', self::$patterns) . '/m';
        foreach ($stmt1->fetch_all(MYSQLI_ASSOC) as $row) {
            // (新增) 忽略所有以 backup_ 为前缀的表
            if (strpos($row['Name'], $this->backup_prefix) === 0) {
                continue;
            }
            //获取建表语句
            $alert_columns_conn = $resource->query('SHOW CREATE TABLE ' . $row['Name']);
            $sql = $alert_columns_conn->fetch_assoc();
            preg_match_all('/^\s+[`]([^`]*)`.*?$/m', $sql['Create Table'], $key_value);
            $alert_columns[$row['Name']] = array_combine($key_value[1], array_map(function ($item) {
                return trim(rtrim($item, ','));
            }, $key_value[0]));
            //获取主键索引
            preg_match_all($pattern, $sql['Create Table'], $matches);
            $consrt = array_map(function ($item) {
                return trim(rtrim($item, ','));
            }, $matches[0]);
            $constraints[$row['Name']] = $consrt;
            $show_create[$row['Name']] = $this->remove_auto_increment?preg_replace('/AUTO_INCREMENT=[^\s]*/','',$sql['Create Table']):$sql['Create Table'];
            $tables[] = $row['Name'];
        }
        ksort($alert_columns);
        ksort($constraints);
        ksort($show_create);
        ksort($tables);
        return ['tables' => $tables, 'columns' => $alert_columns, 'show_create' => $show_create, 'constraints' => $constraints];
    }

    /**
     * dump
     *
     * @param $arr
     *
     * @Author  : 9rax.dev@gmail.com
     * @DateTime: 2019/6/10 12:12
     */
    private function dump($arr)
    {
        echo '<pre>' . print_r($arr, true) . '</pre>';
    }
}
