<?php
namespace ADP\BaseVersion\Includes\Compatibility;

use ADP\BaseVersion\Includes\Context;
use ADP\BaseVersion\Includes\Context\Currency;
use ADP\BaseVersion\Includes\Context\CurrencyController;

defined('ABSPATH') or exit;

/**
 * Plugin Name: Payment Gateway Currency for WooCommerce
 * Author: WPFactory
 *
 * @see
 */
class WcPgCurrencyCmp {


    /**
     * @var Context
     */
    protected $context;

    /**
     * @var \Alg_WC_PGBC|null
     */
    protected $algwcpgbc;

    public function __construct($deprecated = null)
    {
        $this->loadRequirements();
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return class_exists( 'Alg_WC_PGBC_Convert' );
    }

    public function loadRequirements()
    {
        if ( ! did_action('plugins_loaded')) {
            /* translators: Message about the load order*/
            _doing_it_wrong(__FUNCTION__, sprintf(esc_html__('%1$s should not be called earlier the %2$s action.',
                'advanced-dynamic-pricing-for-woocommerce'), 'load_requirements', 'plugins_loaded'), esc_html(WC_ADP_VERSION));
        }

        if($this->isActive()) {
            if ( function_exists('\alg_wc_pgbc') ) {
                $this->algwcpgbc = \alg_wc_pgbc();
            } else {
                $this->algwcpgbc = null;
            }
        }
    }

    public function prepareHooks()
    {
        add_filter( 'alg_wc_pgbc_do_convert_shipping_package_rate', "__return_false");
        add_action('wp_footer', function(){
            if($this->context->is($this->context::WC_CHECKOUT_PAGE)) {
                echo "
            <script>
                let isRefreshPage = false;
                jQuery('form.checkout').on('change','input[name^=\"payment_method\"]',function() {
                    var val = jQuery( this ).val();
                    if (val) {
                        isRefreshPage = true;
                    }
                });

                jQuery('body').on('updated_checkout', function(){
                if (isRefreshPage) {
                    location.reload();
                }
            });
            </script>";
            }
        });
    }

    protected function getCurrency($code)
    {
        $shopCurrency = get_option('woocommerce_currency');
        $gateway = $this->algwcpgbc->core->convert->get_current_gateway();

        if ($code === $shopCurrency) {
            $rate = 1;
        } else {
            $rate = $gateway
                ? (float) $this->algwcpgbc->core->convert->rates->get_gateway_rate($gateway)
                : 1;
        }

        return new Currency(
            $code,
            get_woocommerce_currency_symbol($code),
            $rate
        );
    }


    protected function getDefaultCurrency()
    {
        $defaultCurrency = get_option('woocommerce_currency');
        return $this->getCurrency($defaultCurrency);
    }

    protected function getCurrentCurrency()
    {

        $gateway =  $this->algwcpgbc->core->convert->get_current_gateway();
        $currency = $this->algwcpgbc->core->convert->get_gateway_currency($gateway);
        if($currency) {
            return $this->getCurrency($currency);
        } else {
            $defaultCurrency = get_option('woocommerce_currency');
            return $this->getCurrency($defaultCurrency);
        }
    }

    public function modifyContext(Context $context)
    {
        $this->context = $context;

        if(!is_null($this->algwcpgbc)) {
            $this->context->currencyController = new CurrencyController($this->context, $this->getDefaultCurrency());
            $this->context->currencyController->setCurrentCurrency($this->getCurrentCurrency());
        }

        $wmc_settings = null;
        if (class_exists('WOOMULTI_CURRENCY_F_Data')) {
            $wmc_settings = \WOOMULTI_CURRENCY_F_Data::get_ins();
        } else if(class_exists('WOOMULTI_CURRENCY_Data')) {
            $wmc_settings = \WOOMULTI_CURRENCY_Data::get_ins();
        }
        if (!empty($wmc_settings) ) {
            $current_adp_currency = $this->getCurrentCurrency()->getCode();

            if ($wmc_settings->get_current_currency() !== $current_adp_currency) {
                $wmc_settings->set_current_currency($current_adp_currency);
            }
        }
    }
}
