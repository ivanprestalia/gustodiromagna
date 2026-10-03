<?php

namespace ADP\BaseVersion\Includes\Core\Cart;
use ADP\BaseVersion\Includes\Helpers\Serializable;

defined('ABSPATH') or exit;

class DisableAllWcCouponsCart extends Serializable implements CouponsAdjustment
{
    /**
     * @var integer
     */
    protected $ruleId;

    /**
     * @param int    $ruleId
     */
    public function __construct($ruleId)
    {
        $this->ruleId = $ruleId;
    }

    /**
     * @return int
     */
    public function getRuleId()
    {
        return $this->ruleId;
    }
}
