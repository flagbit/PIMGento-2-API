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
     * [n][fallback]: Column name of the column to fall back to
     *
     * [n][perm]: Wether fallback is permanent or temporary.
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
     * Use fallback when value is NULL.
     * If set to false, fallback will only be used when the column is missing completely
     *
     * @var bool
     */
    protected $useFallbackOnNull = true;

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
     * null for no fallback. Should only be used for values which are always present
     *
     * $perm: Define if fallback should be permanent for current object
     * means fallback system will always use this fallback. Even if there are values in the current column
     *
     * @param $name
     * @param null $fallback
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
     * @param $from
     * @param $to
     */
    public function registerFallback($from, $to)
    {
        if (isset($this->fallbackRoute[$from])) {
            $this->fallbackRoute[$from]['fallback'] = $to;
        } else {
            throw new InvalidArgumentException('No column with name ' . $from . ' registered');
        }
    }

    /**
     * fallback the column name
     * calling this may increases the speed of further fallbackValue calls
     *
     * @param $connection
     * @param $column
     * @param $table
     * @return mixed
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
            if(isset($exists) && !$exists) {
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
     * @param $row
     * @param $column
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
            )
        ) {
            if(isset($exists) && !$exists) {
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
}
