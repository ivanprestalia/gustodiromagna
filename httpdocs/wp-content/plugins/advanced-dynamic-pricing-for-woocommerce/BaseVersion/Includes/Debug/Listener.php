<?php

namespace ADP\BaseVersion\Includes\Debug;

use ADP\BaseVersion\Includes\Context;

defined('ABSPATH') or exit;

class Listener
{
    /**
     * @var Context
     */
    protected $context;

    /**
     * @var array
     */
    protected $totals;

    /**
     * @var Listener|null
     */
    protected $subscriber;

    /**
     * @param null $deprecated
     */
    public function __construct($deprecated = null)
    {
        $this->context = adp_context();
        $this->totals = array();
        $this->subscriber = null;
    }

    public function withContext(Context $context)
    {
        $this->context = $context;

        if ($this->subscriber !== null) {
            $this->subscriber->withContext($context);
        }
    }

    /**
     * Subscribe debug listener. All matching methods will be called on event.
     *
     * @param Listener $subscriber
     *
     * @return $this
     */
    public function subscribe(Listener $subscriber)
    {
        $subscriber->withContext($this->context);
        $this->subscriber = $subscriber;

        return $this;
    }

    public function calcProcessStarted()
    {
        $this->dispatch(__FUNCTION__, func_get_args());
    }

    /**
     * @param bool $result
     */
    public function processResult($result)
    {
        $this->dispatch(__FUNCTION__, func_get_args());
    }

    /**
     * @param \ADP\BaseVersion\Includes\Core\RuleProcessor\RuleProcessor $proc
     */
    public function ruleCalculated($proc)
    {
        $this->dispatch(__FUNCTION__, func_get_args());
    }

    public function __call($name, $arguments)
    {
        return $this->dispatch($name, $arguments);
    }

    /**
     * @return array
     */
    public function getTotals()
    {
        if ($this->subscriber === null) {
            return $this->totals;
        }

        return $this->subscriber->getTotals();
    }

    /**
     * @param string $method
     * @param array $arguments
     *
     * @return mixed
     */
    protected function dispatch($method, array $arguments = array())
    {
        if ($this->subscriber !== null && method_exists($this->subscriber, $method)) {
            return call_user_func_array(array($this->subscriber, $method), $arguments);
        }

        return null;
    }
}