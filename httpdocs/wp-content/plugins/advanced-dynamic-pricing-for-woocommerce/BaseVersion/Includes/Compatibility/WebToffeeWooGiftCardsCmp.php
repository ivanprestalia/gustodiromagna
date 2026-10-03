<?php

namespace ADP\BaseVersion\Includes\Compatibility;

use ADP\BaseVersion\Includes\Context;

defined('ABSPATH') or exit;

/**
 * Plugin Name: WebToffee WooCommerce Gift Cards
 * Author: WebToffee
 *
 * @see https://www.webtoffee.com/product/woocommerce-gift-cards/
 */
class WebToffeeWooGiftCardsCmp
{
    /**
     * @var Context
     */
    protected $context;

    /**
     * @var \Wt_Gc_Store_Credit_Apply|null
     */
    protected $instance;

    public function __construct()
    {
        $this->context = adp_context();
        $this->loadRequirements();
    }

    public function withContext(Context $context)
    {
        $this->context = $context;
    }

    public function loadRequirements()
    {
        if ( ! did_action('plugins_loaded')) {
            _doing_it_wrong(
                __FUNCTION__,
                sprintf(
                /* translators: Message about the load order*/
                    esc_html__(
                        '%1$s should not be called earlier the %2$s action.',
                        'advanced-dynamic-pricing-for-woocommerce'
                    ),
                    'loadRequirements',
                    'plugins_loaded'
                ),
                esc_html(WC_ADP_VERSION)
            );
        }

        $this->instance = class_exists("\Wt_Gc_Store_Credit_Apply") ?
            \Wt_Gc_Store_Credit_Apply::get_instance( "Advanced Dynamic Pricing and Discount Rules for WooCommerce", WC_ADP_VERSION) : null;
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return ($this->instance instanceof \Wt_Gc_Store_Credit_Apply);
    }

    public function isApplyBeforeTax()
    {
        return $this->instance->apply_before_tax();
    }

    public function addActionToMoveAction()
    {
        if ( did_action('wp_loaded') ) {
            $this->moveAfterCalculateTotalsAction();
        } else {
            add_action('wp_loaded', [$this, 'moveAfterCalculateTotalsAction'], 21);
        }
    }

    public function moveAfterCalculateTotalsAction()
    {
        if (false === has_action(
                'woocommerce_after_calculate_totals',
                [$this->instance, 'after_calculate_totals']
            )) {
            return;
        }
        if (!$this->isApplyBeforeTax()) {
            $priority = $this->instance->get_calculate_totals_hook_priority( 1000, 'woocommerce_after_calculate_totals' );
            remove_action(
                'woocommerce_after_calculate_totals',
                [$this->instance, 'after_calculate_totals'],
                $priority
            );
            add_action(
                'woocommerce_after_calculate_totals',
                [$this->instance, 'after_calculate_totals'],
                PHP_INT_MAX
            );
        }
    }
}
