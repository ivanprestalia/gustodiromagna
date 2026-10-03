<?php
defined('ABSPATH') or exit;

?>
<div class="wdp-product-exclude wdp-column wdp-column-subfields">
    <div class="wdp-column" style="flex: 1">

        <div style="display: flex">
            <div>
                <label>
                    <span class="wdp-exclude-title">
                        <?php esc_html_e( 'Exclude', 'advanced-dynamic-pricing-for-woocommerce' ); ?>
                    </span>
                </label>
            </div>
            <div class="wdp-exclude-options">
                <div class="wdp-exclude-on-wc-sale-container">
                    <label>
                        <input type="checkbox" class="wdp-exclude-on-wc-sale" name="rule[{t}][{f}][product_exclude][on_wc_sale]" value="1" >
                        <span class="wdp-exclude-on-wc-sale-title">
                            <?php esc_html_e( 'on sale', 'advanced-dynamic-pricing-for-woocommerce' ); ?>
                        </span>
                    </label>
                </div>
                <div class="wdp-exclude-already-affected-container">
                    <label>
                        <input type="checkbox" class="wdp-exclude-already-affected" name="rule[{t}][{f}][product_exclude][already_affected]" value="1" >
                        <span>
                            <?php esc_html_e( 'modified by other pricing rules', 'advanced-dynamic-pricing-for-woocommerce' ); ?>
                        </span>
                    </label>
                </div>
            </div>

        </div>

        <div class="wdp-exlclude-filters"></div>
        <div class="wdp-exlclude-buttons">
            <button type="button" class="button add-exclude-filter">Add exclude filter</button>
        </div>

    </div>
</div>