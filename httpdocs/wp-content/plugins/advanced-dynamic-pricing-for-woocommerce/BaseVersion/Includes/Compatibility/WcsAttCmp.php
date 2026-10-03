<?php

namespace ADP\BaseVersion\Includes\Compatibility;

use ADP\BaseVersion\Includes\Context;

defined('ABSPATH') or exit;

/**
 * Offer your existing products on subscription, with this powerful add-on for WooCommerce Subscriptions.
 *
 * Plugin Name: WooCommerce All Products For Subscriptions
 * Author: WooCommerce
 *
 * @see https://woocommerce.com/products/all-products-for-woocommerce-subscriptions/
 */
class WcsAttCmp
{
    /**
     * @var Context
     */
    protected $context;

    /**
     * @var null|\WCS_ATT
     */
    protected $wcsAtt;

    /**
     * @param null $deprecated
     */
    public function __construct($deprecated = null)
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
            /* translators: Message about the load order*/
            _doing_it_wrong(__FUNCTION__, sprintf(esc_html__('%1$s should not be called earlier the %2$s action.',
                'advanced-dynamic-pricing-for-woocommerce'), 'loadRequirements', 'plugins_loaded'), esc_html(WC_ADP_VERSION));
        }

        $this->wcsAtt = class_exists("\WCS_ATT") ? \WCS_ATT::instance() : null;

        if ($this->isActive() && class_exists('\WCS_ATT_Display_Cart')) {
            remove_filter('woocommerce_cart_item_price', array('\WCS_ATT_Display_Cart', 'show_cart_item_subscription_options'), 1000);
            add_filter('woocommerce_cart_item_price', array('\WCS_ATT_Display_Cart', 'show_cart_item_subscription_options'), 10001, 3);
        }

        if ($this->isActive() && isset($this->wcsAtt->product_data) ) {
            ADP_WCS_ATT_Product_Data_Wrapper::init();
            $this->wcsAtt->product_data = new ADP_WCS_ATT_Product_Data_Wrapper();
        }
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return ! is_null($this->wcsAtt) && ($this->wcsAtt instanceof \WCS_ATT);
    }
}

if (!class_exists('ADP_WCS_ATT_Product_Data_Wrapper')) {
    class ADP_WCS_ATT_Product_Data_Wrapper {
        private static $key = '_adp_wcsatt_';
        public static function init() {
            self::add_hooks();
        }

        private static function add_hooks() {
            add_action( 'add_post_metadata', array( __CLASS__, 'ignore_adp_wcsatt_runtime_meta' ), 10, 3 );
            add_action( 'update_post_metadata', array( __CLASS__, 'ignore_adp_wcsatt_runtime_meta' ), 10, 3 );
        }

        public function get($product, $key, $default = null) {
            if (method_exists($product, 'get_meta')) {
                $data = $product->get_meta(self::$key, true);
                if( is_array($data) AND isset($data[$key]) ) {
                    return $data[$key];
                }
            }
            return $default;
        }

        public function set($product, $key, $value) {
            $data = $product->get_meta(self::$key, true);
            if( empty($data) )
                $data = array();
            $data[$key] = $value;
            if (method_exists($product, 'update_meta_data')) {
                $product->add_meta_data(self::$key, $data);
            }
        }

        public function delete($product, $key) {
            $data = $product->get_meta(self::$key, true);
            if( empty($data) )
                $data = array();
            unset( $data[$key] );
            if (method_exists($product, 'update_meta_data')) {
                $product->add_meta_data(self::$key, $data);
            }
            return false;
        }

        public static function ignore_adp_wcsatt_runtime_meta( $check, $object_id, $meta_key ) {
            if ( self::$key !== $meta_key ) {
                return $check;
            }
            return 0;
        }
    }
}
