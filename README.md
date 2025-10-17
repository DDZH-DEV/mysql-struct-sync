# mysql-struct-sync
可用于帮助开发人员比较两个数据库之间的差异(表,列,约束,事件,函数,存储过程,触发器,视图),并生成更新语句。
Can be used to synchronize database structures, compare differences(table,column,constraints,events,functions,procedures,triggers,views) between databases and generating queries.

### Features
- [X] Handle Create Tables
- [X] Handle Alter Tables
- [X] Handle Drop table Queries
- [X] Handle constraints (PK,FK,index, ... etc)
- [X] Handle events
- [X] Handle functions
- [X] Handle procedures
- [X] Handle triggers
- [X] Handle views

### Installation 安装方式

to install this library using composer:
```sh
composer require 9raxdev/mysql-struct-sync
```

### New Features in V2
This library has been significantly updated to support a more robust and efficient workflow for comparing database schemas, especially for database version management systems.

*   **Offline Snapshot Comparison**: Instead of comparing two live databases, you can now compare a live database against a pre-generated "structure snapshot". This eliminates the need for temporary databases and makes comparisons much faster.
*   **Atomic `ALTER` Statements**: The library now generates a single, atomic `ALTER TABLE` statement for all modifications to a single table. This prevents errors related to operation order (e.g., dropping a primary key before modifying an auto-increment column) and ensures safer migrations.

### New API Methods
*   `MysqlStructSync::fetchStructureArray(array $db_conf)`: A static method to fetch the schema of a database as a PHP array (a "snapshot").
*   `$compare->getGroupedDiffSql()`: Returns an array of SQL statements, with all `ALTER` queries for the same table grouped into a single query. This is the **recommended** way to get the difference script.

### Attention 注意
1.无法识别rename字段,更改数据库字段名称在代码中的体现为:先删除命名前的字段,再增加命名后的字段。The rename field is not recognized. Changing the database field name in the code is as follows: first delete the field before the name, and then add the named field.

2.```advanceDiff()```必须基于```baseDiff()```前提下,因为储存过程,触发器,函数等特性都依赖数据表。```advanceDiff()``` must be based on ```baseDiff()```, because procedures, triggers, functions, etc. depend on the tables.

### Demo
#### Standard Usage: Comparing Two Live Databases
```php
<?php
include __DIR__.'/MysqlStructSync.php';

$source_db_config=['host'=>'127.0.0.1','username'=>'root','passwd'=>'root','dbname'=>'test_old','port'=>3306];
$target_db_config=['host'=>'127.0.0.1','username'=>'root','passwd'=>'root','dbname'=>'test_new','port'=>3306];

// Compare source_db_config against target_db_config
$compare=new \linge\MysqlStructSync($source_db_config, $target_db_config);

$compare->removeAutoIncrement();

$compare->baseDiff(); // Compare tables, columns, constraints
$compare->advanceDiff(); // Compare views, triggers, events, etc.

// Get the atomic SQL script (Recommended)
$diff_sql_array = $compare->getGroupedDiffSql();
$diff_sql_script = implode("\n", $diff_sql_array);
print_r($diff_sql_script);
```

#### Advanced Usage: Comparing a Live Database to a Snapshot
This is the recommended approach for building a database schema versioning system.
```php
<?php
include __DIR__.'/MysqlStructSync.php';

$production_db_config = ['host'=>'...'];
$development_db_config = ['host'=>'...'];

// Step 1: In your development/build process, create a structure snapshot from the target schema.
$target_structure_snapshot = \linge\MysqlStructSync::fetchStructureArray($development_db_config);
// Serialize and store this snapshot string (e.g., in your versions table in the database).
$snapshot_string = json_encode($target_structure_snapshot);


// Step 2: In your deployment/migration process, compare the live database against the loaded snapshot.
$loaded_snapshot = json_decode($snapshot_string, true);
$compare = new \linge\MysqlStructSync($production_db_config, $loaded_snapshot);

$compare->removeAutoIncrement();
$compare->baseDiff();
// Note: advanceDiff() does not currently support snapshot comparison.

// Get the atomic SQL script to upgrade the production_db
$diff_sql_array = $compare->getGroupedDiffSql();
$diff_sql_script = implode("\n", $diff_sql_array);
echo $diff_sql_script;
```

### Future Improvements
- [ ] Add support for column ordering (`AFTER` clause).
- [ ] Add support for advanced objects (views, triggers) in snapshot comparison mode.
- [ ] Replace `die()` with exceptions for better error handling in library mode.

---
![manuallySelectUpdates](./manuallySelectUpdates.png)