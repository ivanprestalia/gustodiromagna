<?php

namespace ADP\BaseVersion\Includes\Core\Rule\Structures;

defined('ABSPATH') or exit;

class Filter extends SimpleFilter
{
    /**
     * @var bool
     */
    protected $excludeWcOnSale;

    /**
     * @var bool
     */
    protected $excludeAlreadyAffected;

    /**
     * @var bool
     */
    protected $excludeBackorder;

    /**
     * @var bool
     */
    protected $excludeMatchedPreviousFilters;


    /**
     * @var Filter
     */
    protected $excludeFilters;

    /**
     * @var int
     */
    protected $collectedQtyInCart;

    public function __construct()
    {
        parent::__construct();
        $this->excludeWcOnSale = false;
        $this->excludeAlreadyAffected = false;
        $this->excludeBackorder = false;
        $this->excludeMatchedPreviousFilters = false;
        $this->collectedQtyInCart = 0;
        $this->excludeFilters = [];
    }

    /**
     * @param bool $excludeWcOnSale
     */
    public function setExcludeWcOnSale($excludeWcOnSale)
    {
        $this->excludeWcOnSale = boolval($excludeWcOnSale);
    }

    /**
     * @return bool
     */
    public function isExcludeWcOnSale()
    {
        return $this->excludeWcOnSale;
    }

    /**
     * @param bool $excludeAlreadyAffected
     */
    public function setExcludeAlreadyAffected($excludeAlreadyAffected)
    {
        $this->excludeAlreadyAffected = boolval($excludeAlreadyAffected);
    }

    /**
     * @return bool
     */
    public function isExcludeAlreadyAffected()
    {
        return $this->excludeAlreadyAffected;
    }


    /**
     * @param SimpleFilter $filter
     */
    public function addExcludeFilter($filter)
    {
        if ($filter instanceof SimpleFilter) {
            $this->excludeFilters[] = $filter;
        }
    }

    /**
     * @param array<int,SimpleFilter> $filters
     */
    public function setExcludeFilters($filters)
    {
        $this->excludeFilters = array();

        foreach ($filters as $filter) {
            $this->addExcludeFilter($filter);
        }
    }

    /**
     * @return array<int,SimpleFilter>
     */
    public function getExcludeFilters()
    {
        return $this->excludeFilters;
    }

    /**
     * @return bool
     */
    public function isExcludeBackorder()
    {
        return $this->excludeBackorder;
    }

    /**
     * @param bool $excludeBackorder
     */
    public function setExcludeBackorder($excludeBackorder)
    {
        $this->excludeBackorder = boolval($excludeBackorder);
    }

    /**
     * @return bool
     */
    public function isExcludeMatchedPreviousFilters()
    {
        return $this->excludeMatchedPreviousFilters;
    }

    /**
     * @param bool $excludeMatchedPreviousFilters
     */
    public function setExcludeMatchedPreviousFilters($excludeMatchedPreviousFilters)
    {
        $this->excludeMatchedPreviousFilters = $excludeMatchedPreviousFilters;
    }

    /**
     * @param int $collectedQty
     */
    public function setCollectedQtyInCart($collectedQty)
    {
        $this->collectedQtyInCart = $collectedQty;
    }

    /**
     * @return int
     */
    public function getCollectedQtyInCart()
    {
        return $this->collectedQtyInCart;
    }
}
