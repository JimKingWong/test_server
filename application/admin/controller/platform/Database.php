<?php
namespace app\admin\controller\platform;

use app\common\controller\Backend;
use think\Db;
use think\Config;

/**
 * 数据库管理：建表、字段增删改，记录结构变动
 *
 * @icon fa fa-database
 */
class Database extends Backend
{
    /**
     * 表名字符校验
     * @var string
     */
    protected $tableRule = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * 字段名字符校验
     * @var string
     */
    protected $fieldRule = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * 字段类型白名单
     * @var array
     */
    protected $typeList = ['tinyint', 'smallint', 'int', 'bigint', 'varchar', 'char', 'text', 'mediumtext', 'longtext', 'decimal', 'float', 'double', 'date', 'datetime', 'timestamp', 'time'];

    /**
     * 变动记录表
     * @var string
     */
    protected $logTable = 'ga_table_change_log';

    public function _initialize()
    {
        parent::_initialize();
        $this->ensureLogTable();
    }

    /**
     * 确保变动记录表存在
     */
    protected function ensureLogTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->logTable}` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `table_name` varchar(100) NOT NULL DEFAULT '' COMMENT '数据表名',
            `action` varchar(50) NOT NULL DEFAULT '' COMMENT '操作类型',
            `sql_text` text COMMENT '执行的SQL',
            `operator` varchar(50) NOT NULL DEFAULT '' COMMENT '操作人',
            `operator_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '操作人ID',
            `create_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '操作时间',
            PRIMARY KEY (`id`),
            KEY `idx_table` (`table_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据库表结构变动记录'";
        Db::execute($sql);
    }

    /**
     * 记录变动
     * @param string $tableName
     * @param string $action
     * @param string $sql
     */
    protected function logChange($tableName, $action, $sql)
    {
        $operator = '';
        if ($this->auth) {
            $admin = Db::name('admin')->where('id', $this->auth->id)->find();
            $operator = $admin ? $admin['username'] : '';
        }
        Db::name('table_change_log')->insert([
            'table_name'  => $tableName,
            'action'      => $action,
            'sql_text'    => $sql,
            'operator'    => $operator,
            'operator_id' => $this->auth ? (int)$this->auth->id : 0,
            'create_time' => time(),
        ]);
    }

    /**
     * 成功响应（携带新 token，支持连续操作）
     * @param string $msg
     */
    protected function ok($msg)
    {
        $this->success($msg, null, ['__token__' => $this->request->token()]);
    }

    /**
     * 数据表列表
     */
    public function index()
    {
        $dbName = Config::get('database.database');
        $tables = Db::query("SELECT TABLE_NAME, ENGINE, TABLE_ROWS, TABLE_COLLATION, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME", [$dbName]);
        // 字段数
        $cols = Db::query("SELECT TABLE_NAME, COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? GROUP BY TABLE_NAME", [$dbName]);
        $colMap = [];
        foreach ($cols as $c) {
            $colMap[$c['TABLE_NAME']] = $c['c'];
        }
        $list = [];
        foreach ($tables as $t) {
            $name = $t['TABLE_NAME'];
            $list[] = [
                'name'      => $name,
                'engine'    => $t['ENGINE'],
                'rows'      => $t['TABLE_ROWS'],
                'collation' => $t['TABLE_COLLATION'],
                'comment'   => $t['TABLE_COMMENT'],
                'fields'    => isset($colMap[$name]) ? $colMap[$name] : 0,
                'is_log'    => $name == $this->logTable ? 1 : 0,
            ];
        }
        if ($this->request->isAjax()) {
            return json(['total' => count($list), 'rows' => $list]);
        }
        $this->view->assign('list', $list);
        return $this->view->fetch();
    }

    /**
     * 创建数据表
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $this->token();
            $table = strtolower($this->request->post('table', '', 'trim'));
            $comment = $this->request->post('comment', '', 'trim');
            $fields = $this->request->post('fields/a', []);
            if (!preg_match($this->tableRule, $table)) {
                $this->error(__('非法表名，只允许字母、数字、下划线且不能以数字开头'));
            }
            if (!$fields) {
                $this->error(__('请至少添加一个字段'));
            }
            // 表是否存在
            $exists = Db::query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [Config::get('database.database'), $table]);
            if ($exists) {
                $this->error(__('数据表已存在'));
            }
            $cols = [];
            $primary = '';
            foreach ($fields as $f) {
                $name = strtolower(trim(isset($f['name']) ? $f['name'] : ''));
                $type = strtolower(trim(isset($f['type']) ? $f['type'] : ''));
                $length = trim(isset($f['length']) ? $f['length'] : '');
                $default = isset($f['default']) ? trim($f['default']) : '';
                $notnull = isset($f['notnull']) ? (int)$f['notnull'] : 0;
                $pk = isset($f['pk']) ? (int)$f['pk'] : 0;
                $fieldComment = trim(isset($f['comment']) ? $f['comment'] : '');
                if ($name === '' || !preg_match($this->fieldRule, $name)) {
                    $this->error(__('字段名') . " {$name} " . __('非法，只允许字母、数字、下划线'));
                }
                if (!in_array($type, $this->typeList)) {
                    $this->error(__('字段') . " {$name} " . __('类型不在白名单') . ': ' . implode(', ', $this->typeList));
                }
                $col = "`{$name}` {$type}";
                if ($length !== '') {
                    if (!preg_match('/^[0-9]+(,[0-9]+)?$/', $length)) {
                        $this->error(__('字段') . " {$name} " . __('长度格式错误，如 11 或 10,2'));
                    }
                    $col .= "({$length})";
                }
                $col .= $notnull ? ' NOT NULL' : ' NULL';
                if ($default !== '') {
                    $col .= " DEFAULT '" . addslashes($default) . "'";
                } elseif (!$notnull && $type !== 'text' && $type !== 'mediumtext' && $type !== 'longtext') {
                    $col .= " DEFAULT NULL";
                }
                if ($fieldComment !== '') {
                    $col .= " COMMENT '" . addslashes($fieldComment) . "'";
                }
                $cols[] = $col;
                if ($pk) {
                    $primary = $name;
                }
            }
            $sql = "CREATE TABLE `{$table}` (\n  " . implode(",\n  ", $cols);
            if ($primary) {
                $sql .= ",\n  PRIMARY KEY (`{$primary}`)";
            }
            $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='" . addslashes($comment) . "'";
            try {
                Db::execute($sql);
            } catch (\Exception $e) {
                $this->error(__('创建失败') . '：' . $e->getMessage());
            }
            $this->logChange($table, 'CREATE_TABLE', $sql);
            $this->ok(__('数据表创建成功'));
        }
        $this->view->assign('typeList', $this->typeList);
        return $this->view->fetch();
    }

    /**
     * 字段管理
     * @param string $table 表名
     */
    public function field($table = null)
    {
        $table = strtolower($table ?: $this->request->get('table', ''));
        if (!preg_match($this->tableRule, $table)) {
            $this->error(__('非法表名'));
        }
        $exists = Db::query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [Config::get('database.database'), $table]);
        if (!$exists) {
            $this->error(__('数据表不存在'));
        }
        if ($this->request->isPost()) {
            $this->token();
            $action = $this->request->post('action', '', 'trim');
            $name = strtolower($this->request->post('name', '', 'trim'));
            if (!preg_match($this->fieldRule, $name)) {
                $this->error(__('非法字段名'));
            }
            // 删除字段：仅需字段名
            if ($action == 'del') {
                try {
                    $sql = "ALTER TABLE `{$table}` DROP COLUMN `{$name}`";
                    Db::execute($sql);
                    $this->logChange($table, 'DROP_FIELD', $sql);
                } catch (\Exception $e) {
                    $this->error(__('操作失败') . '：' . $e->getMessage());
                }
                $this->ok(__('操作成功'));
            }
            $type = strtolower($this->request->post('type', '', 'trim'));
            $length = trim($this->request->post('length', ''));
            $default = $this->request->post('default', '', 'trim');
            $notnull = (int)$this->request->post('notnull', 0);
            $pk = (int)$this->request->post('pk', 0);
            $comment = trim($this->request->post('comment', ''));
            if (!in_array($type, $this->typeList)) {
                $this->error(__('字段类型不在白名单'));
            }
            $def = "`{$name}` {$type}";
            if ($length !== '') {
                if (!preg_match('/^[0-9]+(,[0-9]+)?$/', $length)) {
                    $this->error(__('长度格式错误'));
                }
                $def .= "({$length})";
            }
            $def .= $notnull ? ' NOT NULL' : ' NULL';
            if ($default !== '') {
                $def .= " DEFAULT '" . addslashes($default) . "'";
            } elseif (!$notnull && !in_array($type, ['text', 'mediumtext', 'longtext'])) {
                $def .= " DEFAULT NULL";
            }
            if ($comment !== '') {
                $def .= " COMMENT '" . addslashes($comment) . "'";
            }
            try {
                if ($action == 'add') {
                    $sql = "ALTER TABLE `{$table}` ADD COLUMN {$def}";
                    Db::execute($sql);
                    $this->logChange($table, 'ADD_FIELD', $sql);
                } elseif ($action == 'edit') {
                    $oldName = strtolower($this->request->post('oldname', '', 'trim'));
                    if (!preg_match($this->fieldRule, $oldName)) {
                        $this->error(__('非法字段名'));
                    }
                    $sql = "ALTER TABLE `{$table}` CHANGE COLUMN `{$oldName}` {$def}";
                    Db::execute($sql);
                    if ($pk) {
                        // 仅当旧字段原本不是主键（即新提升为主键）时才添加，避免重复主键
                        $oldCol = Db::query("SELECT COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?", [Config::get('database.database'), $table, $oldName]);
                        $oldIsPk = $oldCol && $oldCol[0]['COLUMN_KEY'] === 'PRI';
                        if (!$oldIsPk) {
                            Db::execute("ALTER TABLE `{$table}` ADD PRIMARY KEY (`{$name}`)");
                        }
                    }
                    $this->logChange($table, 'EDIT_FIELD', $sql);
                } else {
                    $this->error(__('未知操作'));
                }
            } catch (\Exception $e) {
                $this->error(__('操作失败') . '：' . $e->getMessage());
            }
            $this->ok(__('操作成功'));
        }
        $fields = Db::query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, COLUMN_COMMENT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [Config::get('database.database'), $table]);
        if ($this->request->isAjax()) {
            return json(['code' => 1, 'data' => ['list' => $fields]]);
        }
        $this->view->assign('table', $table);
        $this->view->assign('fields', $fields);
        $this->view->assign('typeList', $this->typeList);
        return $this->view->fetch();
    }

    /**
     * 删除数据表
     * @param string $ids 兼容父类签名，实际使用 POST 的 table 参数
     */
    public function del($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid request'));
        }
        $this->token();
        $table = strtolower($this->request->post('table', '', 'trim'));
        if (!preg_match($this->tableRule, $table)) {
            $this->error(__('非法表名'));
        }
        if ($table == $this->logTable) {
            $this->error(__('该表为变动记录表，不能删除'));
        }
        $sql = "DROP TABLE `{$table}`";
        try {
            Db::execute($sql);
        } catch (\Exception $e) {
            $this->error(__('删除失败') . '：' . $e->getMessage());
        }
        $this->logChange($table, 'DROP_TABLE', $sql);
        $this->ok(__('数据表已删除'));
    }

    /**
     * 变动记录列表
     */
    public function changelog()
    {
        $filterTable = $this->request->get('table', '', 'trim');
        if ($filterTable && !preg_match($this->tableRule, $filterTable)) {
            $filterTable = '';
        }
        if ($this->request->isAjax()) {
            $page = (int)$this->request->get('page', 1);
            $limit = (int)$this->request->get('limit', 20);
            $query = Db::name('table_change_log');
            if ($filterTable) {
                $query->where('table_name', $filterTable);
            }
            $total = $query->count();
            $list = $query->order('id DESC')->page($page, $limit)->select();
            foreach ($list as &$v) {
                $v['create_time_text'] = date('Y-m-d H:i:s', $v['create_time']);
            }
            unset($v);
            return json(['total' => $total, 'rows' => $list]);
        }
        $page = max(1, (int)$this->request->get('page', 1));
        $limit = 20;
        $query = Db::name('table_change_log');
        if ($filterTable) {
            $query->where('table_name', $filterTable);
        }
        $total = $query->count();
        $totalPage = max(1, ceil($total / $limit));
        $list = $query->order('id DESC')->page($page, $limit)->select();
        foreach ($list as &$v) {
            $v['create_time_text'] = date('Y-m-d H:i:s', $v['create_time']);
        }
        unset($v);
        $this->view->assign('list', $list);
        $this->view->assign('page', $page);
        $this->view->assign('totalPage', $totalPage);
        $this->view->assign('filterTable', $filterTable);
        return $this->view->fetch();
    }
}
