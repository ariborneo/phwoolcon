<?php
namespace Phwoolcon;

use Phalcon\Db as PhalconDb;
use Phalcon\Mvc\Model as PhalconModel;
use Phwoolcon\Db\Adapter\Pdo\Mysql;

/**
 * Class Model
 * @package Phwoolcon
 *
 * @method PhalconModel\Message[] getMessages(string $filter = null)
 * @method Mysql|PhalconDb\Adapter\Pdo getWriteConnection()
 */
abstract class Model extends PhalconModel
{
    protected static $_distributedOptions = [
        'node_id' => '001',
        'start_time' => 1362931200,
    ];
    protected static $_dataColumns = [];
    protected static $_defaultValues = [];
    protected $_additionalData = [];
    protected $_jsonFields = [];
    protected $_table;
    protected $_pk = 'id';
    protected $_isNew = true;
    protected $_useDistributedId = true;
    protected $_integerColumnTypes = [
        PhalconDb\Column::TYPE_INTEGER => true,
        PhalconDb\Column::TYPE_BIGINTEGER => true,
    ];

    public function __call($method, $arguments)
    {
        if (($prefix = substr($method, 0, 3)) == 'get') {
            $property = Text::uncamelize(substr($method, 3));
            if ((null !== $result = $this->getData($property)) || $this->checkDataColumn($property)) {
                return $result;
            }
        } elseif ($prefix == 'set') {
            $property = Text::uncamelize(substr($method, 3));
            return $this->setData($property, fnGet($arguments, 0));
        }
        // @codeCoverageIgnoreStart
        return parent::__call($method, $arguments);
        // @codeCoverageIgnoreEnd
    }

    protected function _preSave(PhalconModel\MetaDataInterface $metaData, $exists, $identityField)
    {
        // Fix phalcon bug: attributeField . " is required" on empty values, it should detect null values instead
        $emptyFields = [];
        foreach ($this->defaultValues() as $k => $v) {
            if (!$v) {
                $emptyFields[$k] = $this->$k;
                $this->$k = 1;
            }
        }
        $result = parent::_preSave($metaData, $exists, $identityField);
        foreach ($emptyFields as $k => $v) {
            $this->$k = $v;
        }
        return $result;
    }

    public function addData(array $data)
    {
        $this->assign($data);
        return $this;
    }

    public function afterFetch()
    {
        $this->_isNew = false;
        foreach ($this->_jsonFields as $field) {
            isset($this->$field) && is_string($data = $this->$field) and $this->$field = json_decode($data, true);
        }
    }

    protected function afterSave()
    {
        $this->_isNew = false;
        foreach ($this->_jsonFields as $field) {
            isset($this->$field) && is_string($data = $this->$field) and $this->$field = json_decode($data, true);
        }
    }

    public function checkDataColumn($column = null)
    {
        if (!isset(static::$_dataColumns[$key = get_class($this)])) {
            static::$_dataColumns[$key] = $this->getModelsMetaData()->getDataTypes($this) ?: [];
        }
        return $column === null ? static::$_dataColumns[$key] : isset(static::$_dataColumns[$key][$column]);
    }

    public function clearData()
    {
        foreach ($this->checkDataColumn() as $attribute => $type) {
            $this->$attribute = null;
        }
        $this->_additionalData = [];
        return $this;
    }

    protected function defaultValues()
    {
        if (!isset(static::$_defaultValues[$key = get_class($this)])) {
            $defaultValues = $this->getModelsMetaData()->getDefaultValues($this) ?: [];
            foreach ($defaultValues as $k => $v) {
                if ($v === null) {
                    unset($defaultValues[$k]);
                }
            }
            static::$_defaultValues[$key] = $defaultValues;
        }
        return static::$_defaultValues[$key];
    }

    public function generateDistributedId()
    {
        $prefix = (time() - static::$_distributedOptions['start_time']) . substr(microtime(), 2, 3);
        $id = $prefix . static::$_distributedOptions['node_id'] . mt_rand(100, 999);
        return $this->setId($id);
    }

    public function getAdditionalData($key = null)
    {
        return $key === null ? $this->_additionalData : fnGet($this->_additionalData, $key);
    }

    public function getData($key = null)
    {
        return $key === null ?
            ($this->_additionalData ? array_merge($this->toArray(), $this->_additionalData) : $this->toArray()) :
            (isset($this->$key) ? $this->$key : null);
    }

    public function getId()
    {
        return isset($this->{$this->_pk}) ? $this->{$this->_pk} : null;
    }

    public function getStringMessages()
    {
        if (!$this->getMessages()) {
            return '';
        }
        $messages = [];
        foreach ($this->getMessages() as $message) {
            $messages[] = $message->getMessage();
        }
        return implode('; ', $messages);
    }

    /**
     * Runs once, only when the model instance is created at the first time
     */
    public function initialize()
    {
        $this->_table and $this->setSource($this->_table);
        $this->keepSnapshots(true);
    }

    public function isNew()
    {
        return $this->_isNew;
    }

    /**
     * Runs every time, when a model object is created
     */
    protected function onConstruct()
    {
        $this->clearData();
    }

    protected function prepareSave()
    {
        // Serialize JSON fields
        foreach ($this->_jsonFields as $field) {
            isset($this->$field) && is_array($data = $this->$field) and $this->$field = json_encode($data);
        }

        // Process created_at and updated_at fields
        $now = time();
        $columns = $this->checkDataColumn();
        if (!$this->getData($property = 'created_at') && isset($columns[$property])) {
            $convert = empty($this->_integerColumnTypes[$columns[$property]]);
            $this->setData($property, $convert ? date(DateTime::MYSQL_DATETIME, $now) : $now);
        }
        if (isset($columns[$property = 'updated_at'])) {
            $convert = empty($this->_integerColumnTypes[$columns[$property]]);
            $this->setData($property, $convert ? date(DateTime::MYSQL_DATETIME, $now) : $now);
        }

        // Generate distributed ID if not specified
        if ($this->_useDistributedId && !$this->getId()) {
            $this->generateDistributedId();
        }

        // Process default values on null fields
        foreach ($this->defaultValues() as $k => $v) {
            if ($this->$k === null) {
                $this->$k = $v;
            }
        }
    }

    public function reset()
    {
        parent::reset();
        $this->clearData();
        $this->_isNew = true;
        return $this;
    }

    public function setData($key, $value = null)
    {
        if (is_array($key)) {
            return $this->clearData()
                ->addData($key);
        }
        $this->$key = $value;
        $this->checkDataColumn($key) or $this->_additionalData[$key] = $value;
        return $this;
    }

    public function setId($id)
    {
        $this->{$this->_pk} = $id;
        return $this;
    }

    public static function setup(array $options)
    {
        PhalconModel::setup($options);
        isset($options['distributed']) and static::$_distributedOptions = $options['distributed'];
    }

    /**
     * @param      $sql
     * @param null $bind
     * @return bool
     */
    public function sqlExecute($sql, $bind = null)
    {
        $sql = $this->translatePhalconBindIntoPDO($sql, $bind);
        return $this->getWriteConnection()->prepare($sql)->execute($bind);
    }

    /**
     * @param      $sql
     * @param null $bind
     * @return array
     */
    public function sqlFetchAll($sql, $bind = null)
    {
        $sql = $this->translatePhalconBindIntoPDO($sql, $bind);
        return $this->getReadConnection()->fetchAll($sql, PhalconDb::FETCH_ASSOC, $bind);
    }

    /**
     * @param      $sql
     * @param null $bind
     * @return array
     */
    public function sqlFetchOne($sql, $bind = null)
    {
        $sql = $this->translatePhalconBindIntoPDO($sql, $bind);
        return $this->getReadConnection()->fetchOne($sql, PhalconDb::FETCH_ASSOC, $bind);
    }

    /**
     * @param      $sql
     * @param null $bind
     * @return mixed
     */
    public function sqlFetchColumn($sql, $bind = null)
    {
        $row = $this->sqlFetchOne($sql, $bind);
        return $row ? reset($row) : $row;
    }

    protected function translatePhalconBindIntoPDO($sql, &$bind = null)
    {
        if (is_array($bind)) {
            foreach ($bind as $key => $val) {
                $replace = [":{$key}:" => ":{$key}"];
                if (strstr($sql, ($from = "{{$key}:array}")) !== false) {
                    if (is_array($val)) {
                        $to = [];
                        foreach (array_values($val) as $vKey => $realVal) {
                            $bind[$to[] = ":{$key}_{$vKey}"] = $realVal;
                        }
                        $replace[$from] = implode(', ', $to);
                        unset($bind[$key]);
                    }
                }
                $sql = strtr($sql, $replace);
            }
        }
        return $sql;
    }

    public function validation()
    {
        return !$this->validationHasFailed();
    }

    /**
     * @param array | string | int $conditions
     * @param array                $bind
     * @param string               $order
     * @param string               $columns
     * @return $this|false
     */
    public static function findFirstSimple($conditions, $bind = [], $order = null, $columns = null)
    {
        $params = static::buildParams($conditions, $bind, $order, $columns);
        return static::findFirst($params);
    }

    /**
     * @param            $conditions
     * @param array      $bind
     * @param string     $order
     * @param string     $columns
     * @param string|int $limit
     * @return \Phalcon\Mvc\Model\Resultset\Simple|\Phalcon\Mvc\Model\ResultsetInterface
     */
    public static function findSimple($conditions = [], $bind = [], $order = null, $columns = null, $limit = null)
    {
        $params = static::buildParams($conditions, $bind, $order, $columns, $limit);
        return static::find($params);
    }

    /**
     * @param array $conditions
     * @param array $bind
     * @return mixed
     */
    public static function countSimple($conditions = [], $bind = [])
    {
        $params = static::buildParams($conditions, $bind);
        return static::count($params);
    }

    /**
     * @param $conditions
     * @param array $bind
     * @param string $orderBy
     * @param string $columns
     * @param string|int $limit
     * @return array
     */
    public static function buildParams($conditions = [], $bind = [], $orderBy = null, $columns = null, $limit = null)
    {
        $params = [];
        // @codeCoverageIgnoreStart
        if (empty($conditions)) {
            return $params;
        }
        // @codeCoverageIgnoreEnd
        if (empty($bind)) {
            if (is_array($conditions)) {
                $params['conditions'] = "";
                $params['bind'] = [];
                foreach ($conditions as $key => $value) {
                    if (!is_array($value)) {
                        $operator = '=';
                        $realValue = $value;
                    } else {
                        $operator = reset($value);
                        $realValue = next($value);
                    }
                    $params['conditions'] .= ($params['conditions'] == "" ? "" : " AND ") .
                        " {$key} {$operator} :{$key}: ";
                    $params['bind'][$key] = $realValue;
                }
            } else {
                $params['conditions'] = $conditions;
            }
        } else {
            $params['conditions'] = $conditions;
            $params['bind'] = $bind;
        }
        if (is_string($orderBy)) {
            $params['order'] = $orderBy;
        }
        if ($columns) {
            $params['columns'] = is_array($columns) ? explode(',', $columns) : $columns;
        }
        if (is_int($limit)) {
            $params['limit'] = $limit;
        } elseif (is_string($limit) && strpos($limit, ',')) {
            list($limit, $offset) = explode(',', $limit);
            $params['limit'] = (int)trim($limit);
            $params['offset'] = (int)trim($offset);
        }
        return $params;
    }
}
