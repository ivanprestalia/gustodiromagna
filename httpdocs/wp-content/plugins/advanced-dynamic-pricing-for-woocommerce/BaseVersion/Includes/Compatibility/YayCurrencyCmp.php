<?php

namespace ADP\BaseVersion\Includes\Compatibility;

use ADP\BaseVersion\Includes\Context;
use ADP\BaseVersion\Includes\Context\Currency;
use ADP\BaseVersion\Includes\Context\CurrencyController;
use Yay_Currency\Helpers\YayCurrencyHelper;

defined('ABSPATH') or exit;

/**
 * Plugin Name: YayCurrency
 * Author: YayCommerce
 *
 * @see https://wordpress.org/plugins/yaycurrency
 */
class YayCurrencyCmp
{

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var array
     */
    private $convertedCurrency = array();

    /**
     * @var array
     */
    private $applyCurrency = array();

    public function __construct($deprecated = null)
    {
        $this->loadRequirements();
    }

    public function loadRequirements()
    {
        if (! did_action('plugins_loaded')) {
            _doing_it_wrong(
                __FUNCTION__, sprintf(
                    /* translators: Message about the load order*/
                esc_html__(
                        '%1$s should not be called earlier the %2$s action.',
                        'advanced-dynamic-pricing-for-woocommerce'
                    ), 'load_requirements', 'plugins_loaded'
                ), esc_html(WC_ADP_VERSION)
            );
        }

        if(!$this->isActive()) {
            return;
        }

        $this->convertedCurrency = YayCurrencyHelper::converted_currency();

        if(method_exists('\Yay_Currency\Helpers\YayCurrencyHelper', 'detect_current_currency')) {
            $this->applyCurrency     = YayCurrencyHelper::detect_current_currency();
        } else if(method_exists('\Yay_Currency\Helpers\YayCurrencyHelper', 'get_apply_currency')) {
            $this->applyCurrency = YayCurrencyHelper::get_apply_currency($this->convertedCurrency);
        }
    }

    public function isActive()
    {
        return function_exists('Yay_Currency\\plugin_init');
    }

    public function prepareHooks()
    {
        if ($this->isActive()) {
            $yayCurrency = \Yay_Currency\Engine\FEPages\WooCommerceCurrency::get_instance();
            remove_filter('woocommerce_package_rates', array($yayCurrency, 'change_shipping_cost'), 10, 2);

            add_action('wp', function () {
                if (is_cart()) {
                    $yayCurrency = \Yay_Currency\Engine\FEPages\WooCommerceCurrency::get_instance();

                    $hooks = \Yay_Currency\Helpers\YayCurrencyHelper::get_product_price_hooks();
                    $price_priority = \Yay_Currency\Helpers\SupportHelper::get_filters_priority();

                    foreach ($hooks as $hook) {
                        remove_filter($hook, [$yayCurrency, 'custom_raw_price'], $price_priority, 2);
                    }
                }
            }, 999);
        }
    }

    public function getCurrencyData($currencyCode)
    {
        if(method_exists('\Yay_Currency\Helpers\YayCurrencyHelper', 'filtered_by_currency_code')) {
            $applyCurrency = YayCurrencyHelper::filtered_by_currency_code($currencyCode, $this->convertedCurrency);
        } else if(method_exists('\Yay_Currency\Helpers\YayCurrencyHelper', 'get_currency_by_currency_code')) {
            $applyCurrency = YayCurrencyHelper::get_currency_by_currency_code($currencyCode, $this->convertedCurrency);
        }
        return $applyCurrency;
    }

    protected function getDefaultCurrency()
    {
        $defaultCurrency = get_option('woocommerce_currency');
        return $this->getCurrency($defaultCurrency);
    }

    protected function getCurrency($code)
    {
        $applyCurrency = $this->getCurrencyData($code);
        $rate = YayCurrencyHelper::get_rate_fee($applyCurrency);
        return new Currency($code, get_woocommerce_currency_symbol($code), $rate);
    }

    protected function getCurrentCurrency()
    {
        $currentCurrency = $this->applyCurrency['currency'];
        return $this->getCurrency($currentCurrency);
    }

    public function modifyContext(Context $context)
    {
        $this->context = $context;
        $this->context->currencyController = new CurrencyController($this->context, $this->getDefaultCurrency());

        $is_checkout = $this->isCheckoutPage();
        $is_checkout_different_currency = (int) get_option( 'yay_currency_checkout_different_currency', 0 );

        if (!$is_checkout || $is_checkout_different_currency === 1) {
            $this->context->currencyController->setCurrentCurrency($this->getCurrentCurrency());
        }
    }

    protected function isCheckoutPage() {
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }

        if (isset($_GET['wc-ajax']) && $_GET['wc-ajax'] === 'update_order_review') {
            return true;
        }

        $checkout_page_id = wc_get_page_id('checkout');
        if ($checkout_page_id && strpos($_SERVER['REQUEST_URI'], get_page_uri($checkout_page_id)) !== false) {
            return true;
        }

        return false;
    }
}
