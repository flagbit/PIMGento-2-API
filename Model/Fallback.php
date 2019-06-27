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
    protected $fallbackRoute = [
        'test' => [
            'fallback' => null,
            'perm' => true
        ]
    ];

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
            throw new InvalidArgumentException('Fallback should not be equal to column name');
        }

        if (!empty($fallback) && !isset($this->fallbackRoute[$fallback])) {
            throw new InvalidArgumentException('No column with name ' . $fallback . ' registered');
        }

        $column = [
            'fallback' => $fallback,
            'perm' => $perma
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
            throw new InvalidArgumentException('No column with name ' . $from . ' registered');
        }

        if (!isset($this->fallbackRoute[$to])) {
            throw new InvalidArgumentException('No column with name ' . $to . ' registered');
        }

        $this->fallbackRoute[$from]['fallback'] = $to;
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
                $this->fallbackRoute[$column]['perm'] && is_string($this->fallbackRoute[$column]['fallback'])
                || !($exists = $connection->tableColumnExists($table, $column))
            )
        ) {
            if (isset($exists) && !$exists) {
                $this->fallbackRoute[$column]['perm'] = true;
            }

            $column =  $this->fallbackRoute[$column]['fallback'];
            $try++;
            if ($try > 100) {
                throw new \Exception('Fallbacks exceeded 100 Failure!');
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
                $this->fallbackRoute[$column]['perm'] && is_string($this->fallbackRoute[$column]['fallback'])
                || !($exists = isset($row[$column]))
                || empty($row[$column])
            )
        ) {
            if (isset($exists) && !$exists) {
                $this->fallbackRoute[$column]['perm'] = true;
            }

            $column =  $this->fallbackRoute[$column]['fallback'];
            $try++;
            if ($try > 100) {
                throw new \Exception('Fallbacks exceeded 100 Failure!');
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
     * @param $column
     * @return array
     */
    public function getFallbackColumnRoute($connection, $table, $column)
    {
        $try = 0;
        $route = [];

        while (isset($this->fallbackRoute[$column])) {
            if ($this->fallbackRoute[$column]['perm'] && is_string($this->fallbackRoute[$column]['fallback'])) {
                $column = $this->fallbackRoute[$column]['fallback'];
                continue;
            }

            if (!$connection->tableColumnExists($table, $column)) {
                $this->fallbackRoute[$column]['perm'] = true;
                continue;
            }

            $route[] = $column;

            if (is_string($this->fallbackRoute[$column]['fallback'])) {
                $column = $this->fallbackRoute[$column]['fallback'];
                continue;
            }

            break;

            $try++;
            if ($try > 100) {
                throw new \Exception('Fallbacks exceeded 100 Failure!');
            }
        }

        if (count($route) === 0) {
            $route[] = $column;
        }

        return $route;
    }

    /**
     * Get SQL Case when query for fallback route
     *
     * @param $fallbackRoute
     * @return string
     */
    public function getSqlCase($fallbackRoute)
    {
        if (is_array($fallbackRoute) && ($routeCount = count($fallbackRoute)) > 0) {
            $sqlCase = $fallbackRoute[0];
            if ($routeCount > 1) {
                $sqlCase = ' (CASE ';
                for ($i = 0; $i < $routeCount - 1; $i++) {
                    $sqlCase .= ' WHEN TRIM(`' . $fallbackRoute[$i] . '`) > \'\' THEN `' . $fallbackRoute[$i] . '` ';
                }

                $sqlCase .= ' ELSE `' . $fallbackRoute[$routeCount - 1] . '` END) ';
            }

            return $sqlCase;
        }

        return [];
    }
}
