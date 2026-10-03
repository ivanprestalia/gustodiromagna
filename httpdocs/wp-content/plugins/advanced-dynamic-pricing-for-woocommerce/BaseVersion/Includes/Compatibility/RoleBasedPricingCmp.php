<?php

namespace ADP\BaseVersion\Includes\Compatibility;

defined('ABSPATH') or exit;

/**
 * Plugin Name: Role Based Pricing for WooCommerce
 * Author: Addify
 *
 * @see https://woocommerce.com/products/role-based-pricing-for-woocommerce/
 */
class RoleBasedPricingCmp
{
    /**
     * @return bool
     */
    public function isActive()
    {
        return class_exists('AF_C_S_P_Price');
    }

    public function applyCompatibility()
    {
        $af_price = new \AF_C_S_P_Price();
        add_filter('adp_product_get_price', function ($price, $product, $variation, $qty, $trdPartyData, $facade) use($af_price) {
            $role_based_price = $af_price->get_price_of_product($product);
		    $customPrice = !is_bool($role_based_price) ? floatval($role_based_price) : $role_based_price;
            if (!empty($customPrice)) {
                return $customPrice;
            }
            return $price;
        }, 10, 6);
    }
}
