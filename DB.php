<?php
/**
 * All rights reserved.
 * 数据库操作DB类
 * @Author libo
 * @Date 2018/6/27
 */
Class DB {
    protected static $db = NULL;

    protected $link = null;

    protected $tableName = '';

    protected static $links = [];

    /**
     * 最后一条执行的SQL
     * @var $sql
     */
    protected $sql;

    private function __construct() {
    }

    public static function link() {
        if (self::$db == NULL) {
            self::$db = new DB();
        }
        return self::$db;
    }

    public function checkLink() {
        $this->link = self::connectDB();
        if (!$this->link) {
            echo "数据库连接失败";exit;
        }
    }

    private static function connectDB($DBName='', $DBConfig = []) {
        $config = Yaf_Registry::get('config');

        $host = empty($DBConfig['host']) ? $config['db']['host'] : $DBConfig['host'];
        $port = empty($DBConfig['port']) ?  $config['db']['port']: $DBConfig['port'];
        $username = empty($DBConfig['username']) ?  $config['db']['username']: $DBConfig['username'];
        $password = empty($DBConfig['password']) ?  $config['db']['password']: $DBConfig['password'];
        $database = empty($DBConfig['database']) ?  $config['db']['database']: $DBConfig['database'];
        $charset = empty($DBConfig['charset']) ?  $config['db']['charset']: $DBConfig['charset'];

        $db_key = md5(implode('-', array($host, $port, $username, $database, $charset)));;

        $mysqli = mysqli_init();
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 4);
        if ($mysqli->real_connect($host, $username, $password, $database, $port)) {
            $mysqli->set_charset($charset);
            self::$links[$db_key] = $mysqli;
            return self::$links[$db_key];
        } else {
            return false;
        }
    }

    private function _sendQuery($sql, $data = [], &$result = []) {
        $this->checkLink();
        $this->setSql($sql, $data);
        $query = $this->link->query($this->sql);
        if (strtoupper(substr(ltrim($this->sql), 0, 6)) !== "SELECT") {
            $result['affected_num'] = $this->link->affected_rows;
            $result['insert_id'] = $this->link->insert_id;
        }
        return $query;
    }

    public function getRows($sql, $data = []) {
        $arr = [];
        $query = $this->_sendQuery($sql, $data);
        while ($row = $query->fetch_assoc()) {
            $arr[] = $row;
        }
        return $arr;
    }

    public function getRow($sql, $data = []) {
        $query = $this->_sendQuery($sql, $data);
        return $query->fetch_assoc();
    }

    public function insertData($data) {
        if (empty($data)) {
            echo "插入数据不能为空";exit;
        }

        if (is_array($data)) {
            $keyArr = $valueArr = $dataArr = [];
            foreach ($data as $key => $value) {
                $keyArr[] = "`".$key."`";
                $valueArr[] = "?";
                $dataArr[] = $value;
            }
            $keyStr = implode(',', $keyArr);
            $valueStr = implode(',', $valueArr);
        } else {
            echo '插入数据必须为数组';exit;
        }

        $sql = "INSERT INTO `" . $this->getTableName() . "`($keyStr) VALUES ({$valueStr})";
        $this->_sendQuery($sql,  $dataArr, $result);
        if (is_int($result['insert_id']) && $result['insert_id'] > 0) {
            return $result['insert_id'];
        }
        return false;
    }
    public function getDataOne($where, $fields = '*')
    {
        $query = $this->getData($where, $fields);
        return $query->fetch_assoc();
    }

    public function getDataAll($where, $fields = '*', $page = 0, $page_size = 10)
    {
        $query = $this->getData($where, $fields, $page, $page_size);
        $data = [];
        while ($row = $query->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    }

    public function getData($where, $fields = '*', $page = 0, $page_size = 10)
    {
        $whereStr = '';
        if (is_string($where)) {
            $tmp_where = strtolower($where);
            if (!strpos($tmp_where, "=") && !strpos($tmp_where, 'in') && !strpos($tmp_where, 'like')) {
                echo 'where条件错误';exit;
            }
            $whereStr = $where;
        } elseif (is_array($where)) {
            $tmp = $whereArr = array();//条件，对应key=value
            foreach ($where as $key => $value) {
                $key = trim($key);
                $keyArr = explode(' ', $key);
                if (count($keyArr) > 2) {
                    continue;
                }

                $condition = isset($keyArr[1]) ? $keyArr[1] : '=';

                if (is_array($value)) {
                    $tmp[] = "`" . $keyArr[0] . "` in ? ";
                } else {
                    $tmp[] = "`" . $keyArr[0] . "` {$condition} ? ";
                }

                $whereArr[] = $value;
            }
            $whereStr = implode(' AND ', $tmp);
        } else {
            echo 'update中where条件错误';exit;
        }
        $sql = "SELECT ".$fields." FROM `".$this->getTableName()."` WHERE $whereStr";
        if ($page >= 1) {
            $page = ($page - 1) * $page_size;
            $sql .= "LIMIT $page, $page_size";
        }
        return $this->_sendQuery($sql, $whereArr);
    }

    public function update($update_value, $where, &$result = array()) {
        if (!is_array($update_value)) {
            echo 'update中update_value传参错误';exit;
        }
        $whereStr = '';
        $whereArr = array();
        if (is_string($where)) {
            $tmp_where = strtolower($where);
            if (!strpos($tmp_where, "=") && !strpos($tmp_where, 'in') && !strpos($tmp_where, 'like')) {
                echo 'update中where条件错误';exit;
            }
            $whereStr = $where;
        } elseif (is_array($where)) {
            $tmp = $whereArr = array();//条件，对应key=value
            foreach ($where as $key => $value) {
                if (is_array($value)) {
                    $tmp[] = "`" . $key . "` in ? ";
                } else {
                    $tmp[] = "`" . $key . "` = ? ";
                }
                $whereArr[] = $value;
            }
            $whereStr = implode(' AND ', $tmp);
        } else {
            echo 'update中where条件错误';exit;
        }
        $upArr = array();
        foreach ($update_value as $key => $value) {
            if ($key{0} === "#") {// 用于特殊操作。有注入漏洞
                $upArr[] = " `" . substr($key, 1) . "` = {$value} ";
                unset($update_value[$key]);
            } else {
                $upArr[] = ' `' . $key . '` = ? ';
            }
        }
        $sql = "UPDATE `" . $this->getTableName() . "` SET " . implode(',', $upArr) . " WHERE {$whereStr}";
        $this->_sendQuery($sql, array_merge(array_values($update_value), $whereArr), $result);
        if (is_int($result['affected_num']) && $result['affected_num'] >= 0) {
            return true;
        }
        return false;
    }

    public function insert($sql, $data = []) {
        return $this->_sendQuery($sql, $data);
    }

    public function delete($sql, $data = []) {
        return $this->_sendQuery($sql, $data);
    }

    protected function setSql($sql, $data = '') {
        $this->sql = $sqlShow = '';
        if (strpos($sql, '?') && is_array($data) && count($data) > 0) {
            $sqlArr = explode('?', $sql);
            $last = array_pop($sqlArr);
            foreach ($sqlArr as $k => $v) {
                if (!empty($v) && isset($data[$k])) {
                    if (!is_array($data[$k])) {
                        $value = "'" . $this->escape_string($data[$k]) . "'";
                    } else {
                        $valueArr = array();
                        foreach ($data[$k] as $val) {
                            $valueArr[] = "'" . $this->escape_string($val) . "'";
                        }
                        $value = '(' . implode(', ', $valueArr) . ')';
                    }
                    $sqlShow .= $v . $value;
                } else {
                   echo  '传参不符合拼接规范，无法正确翻译sql语句! [sql] ';
                }
            }
            $sqlShow .= $last;
        } else {
            $sqlShow = $sql;
        }
        $this->sql = $sqlShow;
    }

    /**
     * 过滤数据
     * @author libo
     * @date   2018-06-27 16:07:13
     * @param $string
     * @return mixed
     */
    public function escape_string($string) {
        $this->checkLink();
        if (!$this->link) {
            return $this->_error(90311, "数据库连接失败");
        }
        return $this->link->real_escape_string($string);
    }

    /**
     * 获取最后执行的一条SQL
     * @author libo
     * @date   2018-06-27 16:50:20
     * @return mixed
     */
    public function getLastSQL() {
        return $this->sql;
    }

    public function setTableName($tableName) {
        $this->tableName = $tableName;
    }

    public function getTableName() {
        return $this->tableName;
    }
}