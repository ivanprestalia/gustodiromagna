<?php

namespace ADP\BaseVersion\Includes\Core\Rule\CartCondition\Interfaces;

defined('ABSPATH') or exit;

interface PromotionMessageCondition
{
    const PROMOTION_MESSAGE_KEY = 'promotion_message';
    const SUBTOTAL_FROM_KEY = 'subtotal_from';

    /**
     * @param string|null $promotionMessage
     */
    public function setPromotionMessage($promotionMessage);

    /**
     * @return string|null
     */
    public function getPromotionMessage();

    /**
     * @param float|null $subtotalFrom
     */
    public function setSubtotalFrom($subtotalFrom);

    /**
     * @return string|float|null
     */
    public function getSubtotalFrom();

    /**
     * @param Cart $cart
     * 
     * @return bool
     */
    public function checkPromotionMessage($cart);
}
