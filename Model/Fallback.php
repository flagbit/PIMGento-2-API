<?php
namespace Pimgento\Api\Model;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\AbstractModel;
use Zend\Code\Exception\InvalidArgumentException;

/**
 * Class Fallback
 * @package Pimgento\Api\Model
 */
class Fallback extends AbstractModel
{
    const MAXIMUM_FALLBACKS = 20;

    const KEY_FALLBACK = 'fallback';
    const KEY_PERMANENT = 'perm';

    const EXCEPTION_FALLBACK_EXCEEDED = 'Fallbacks exceeded %s Failure!';
    const EXCEPTION_NO_COLUMN_WITH_NAME = 'No column with name %s registered';
    const EXCEPTION_NAMES_SHOULD_NOT_EQUAL = 'Fallback should not be equal to column name';

    /**
     * [index: string]: Column name of the registered column. Must be indentical to the name in the database table
     *
     * [n][fallback: string]: Column name of the column to fall back to
     *
     * [n][perm: bool]: Wether fallback is permanent or temporary.
     * Permanent fallbacks are used everytime in the current object
     * while temporary fallbacks are calculated again
     *
     * @var array
     */
    protected $fallbackRoute = [];

    /**
     * Fallback constructor
     *
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
        if (isset($data['fallbackRoute'])) {
            $this->fallbackRoute = $data['fallbackRoute'];
        }
    }

    /**
     * register column to use in the fallback system
     * and potentially register also already the fallback for it
     *
     * $name: Column Name; must be identical to the column name in the table
     *
     * $fallback: To which column name should be falled back.
     * NULL for no fallback. Value NULL should only be used for values which are always present
     *
     * $perm: Define if fallback should be permanent for current object
     * means fallback system will always use this fallback. Even if there are values in the current column
     *
     * @param string $name
     * @param string|null $fallback
     * @param bool $perma
     */
    public function registerColumn($name, $fallback = null, $perma = false)
    {
        if ($name === $fallback) {
            $this->_exceptionNamesShouldNotBeEqual();
        }

        if (!empty($fallback) && !isset($this->fallbackRoute[$fallback])) {
            $this->_exceptionColumnWithNameAlreadyRegistered($fallback);
        }

        $column = [
            self::KEY_FALLBACK => $fallback,
            self::KEY_PERMANENT => $perma
        ];
        $this->fallbackRoute[$name] = $column;
    }

    /**
     * register a fallback from a registered column to another
     *
     * @param string $from
     * @param string $to
     */
    public function registerFallback($from, $to)
    {
        if (!isset($this->fallbackRoute[$from])) {
            $this->_exceptionColumnWithNameAlreadyRegistered($from);
        }

        if (!isset($this->fallbackRoute[$to])) {
            $this->_exceptionColumnWithNameAlreadyRegistered($to);
        }

        $this->fallbackRoute[$from][self::KEY_FALLBACK] = $to;
    }

    /**
     * fallback the column name
     * calling this may increases the speed of further fallbackValue calls
     *
     * @param AdapterInterface $connection
     * @param string $column
     * @param string $table
     * @return string
     * @throws \Exception
     */
    public function fallbackColumn($connection, $table, $column)
    {
        $try = 0;

        while (
            isset($this->fallbackRoute[$column]) &&
            (
                $this->fallbackRoute[$column][self::KEY_PERMANENT] && is_string($this->fallbackRoute[$column][self::KEY_FALLBACK])
                || !($exists = $connection->tableColumnExists($table, $column))
            )
        ) {
            if (isset($exists) && !$exists) {
                $this->fallbackRoute[$column][self::KEY_PERMANENT] = true;
            }

            $column =  $this->fallbackRoute[$column][self::KEY_FALLBACK];
            $try++;
            if ($try > self::MAXIMUM_FALLBACKS) {
                $this->_exceptionFallbackExceeded();
            }
        }

        return $column;
    }

    /**
     * fallback the value of a column
     *
     * @param array $row
     * @param string $column
     * @return mixed
     * @throws \Exception
     */
    public function fallbackValue($row, $column)
    {
        $try = 0;

        while (
            isset($this->fallbackRoute[$column]) &&
            (
                $this->fallbackRoute[$column][self::KEY_PERMANENT] && is_string($this->fallbackRoute[$column][self::KEY_FALLBACK])
                || !($exists = isset($row[$column]))
                || empty($row[$column])
            )
        ) {
            if (isset($exists) && !$exists) {
                $this->fallbackRoute[$column][self::KEY_PERMANENT] = true;
            }

            $column =  $this->fallbackRoute[$column][self::KEY_FALLBACK];
            $try++;
            if ($try > self::MAXIMUM_FALLBACKS) {
                $this->_exceptionFallbackExceeded();
            }
        }

        if (!isset($row[$column])) {
            return null;
        }

        return $row[$column];
    }

    /**
     * Get array of fallbacks
     * used for fallbacks in sql statements
     *
     * @param AdapterInterface $connection
     * @param $table
     * @param $column
     * @return array
     * @throws \Exception
     */
    public function getFallbackColumnRoute($connection, $table, $column)
    {
        $try = 0;
        $route = [];

        while (isset($this->fallbackRoute[$column])) {
            if ($this->fallbackRoute[$column][self::KEY_PERMANENT] && is_string($this->fallbackRoute[$column][self::KEY_FALLBACK])) {
                $column = $this->fallbackRoute[$column][self::KEY_FALLBACK];
                continue;
            }

            if (!$connection->tableColumnExists($table, $column)) {
                $this->fallbackRoute[$column][self::KEY_PERMANENT] = true;
                continue;
            }

            $route[] = $column;

            if (is_string($this->fallbackRoute[$column][self::KEY_FALLBACK])) {
                $column = $this->fallbackRoute[$column][self::KEY_FALLBACK];
                continue;
            }

            $try++;
            if ($try > self::MAXIMUM_FALLBACKS) {
                $this->_exceptionFallbackExceeded();
            }

            break;
        }

        if (count($route) === 0) {
            $route[] = $column;
        }

        return $route;
    }

    /**
     * Get SQL Case when query for fallback route
     *
     * @param mixed $fallbackRoute
     * @param string|null $tableName
     * @return string
     */
    public function getSqlCase($fallbackRoute, $tableName = null)
    {
        $prefix = $tableName ? sprintf('`%s`.', $tableName) : '';

        if (is_array($fallbackRoute) && ($routeCount = count($fallbackRoute)) > 0) {
            $sqlCase = sprintf($prefix . '`%s`', $fallbackRoute[0]);
            if ($routeCount > 1) {
                $sqlCase = ' (CASE ';
                for ($i = 0; $i < $routeCount - 1; $i++) {
                    $sqlCase .= ' WHEN TRIM(' . $prefix . '`' . $fallbackRoute[$i] . '`) > \'\' THEN ' . $prefix . '`' . $fallbackRoute[$i] . '` ';
                }

                $sqlCase .= ' ELSE ' . $prefix . '`' . $fallbackRoute[$routeCount - 1] . '` END) ';
            }

            return $sqlCase;
        }

        return sprintf($prefix . '`%s`', $fallbackRoute);
    }

    /**
     * @throws \Exception
     */
    protected function _exceptionFallbackExceeded()
    {
        throw new \Exception(sprintf(self::EXCEPTION_FALLBACK_EXCEEDED, self::MAXIMUM_FALLBACKS));
    }

    /**
     * @param $column
     * @throws InvalidArgumentException
     */
    protected function _exceptionColumnWithNameAlreadyRegistered($column)
    {
        throw new InvalidArgumentException(sprintf(self::EXCEPTION_NO_COLUMN_WITH_NAME, $column));
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function _exceptionNamesShouldNotBeEqual()
    {
        throw new InvalidArgumentException(self::EXCEPTION_NAMES_SHOULD_NOT_EQUAL);
    }
}
