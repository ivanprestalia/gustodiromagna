<?php

namespace ADP\BaseVersion\Includes\PriceDisplay\PriceFormatters;

use ADP\BaseVersion\Includes\Context;
use ADP\BaseVersion\Includes\PriceDisplay\ProcessedProductSimple;
use ADP\BaseVersion\Includes\PriceDisplay\ProcessedVariableProduct;
use ADP\BaseVersion\Includes\WC\PriceFunctions;
use ADP\BaseVersion\Includes\PriceDisplay\ProductPriceDisplay;

defined('ABSPATH') or exit;

class MessageFormatter
{
    /**
     * @var Context
     */
    protected $context;

    /**
     * @var Formatter
     */
    protected $formatter;

    /**
     * @var PriceFunctions
     */
    protected $priceFunctions;

    /**
     * @param null $deprecated
     */
    public function __construct($template)
    {
        $this->context   = adp_context();
        $this->formatter = new Formatter();
        $this->formatter->setTemplate($template);
        $this->priceFunctions = new PriceFunctions();
    }

    public function getHtml($processedProduct)
    {
        if(!$processedProduct) {
            return $this->formatter->applyReplacements([]);
        }

        $inclTax = adp_context()->getIsPricesIncludeTax();

        $priceProducts = [];

        if($processedProduct instanceof ProcessedProductSimple) {
            $priceProducts = [$processedProduct];
        } else if($processedProduct instanceof ProcessedVariableProduct) {
            $priceProducts = array_filter([
                $processedProduct->getLowestPriceProduct(),
                $processedProduct->getHighestPriceProduct(),
            ]);
        }

        $discounts = array_values(array_filter(array_map(function($priceProduct) use($inclTax) {
            $amount = (float)array_sum(array_map(function ($amounts) {
                return array_sum($amounts);
            }, $priceProduct->getDiscounts()));

            if(!$amount) {
                return;
            }

            return $this->priceFunctions->format(
                    $inclTax ?
                        $this->priceFunctions->getPriceIncludingTax($priceProduct->getProduct(), ['price' => $amount]) :
                        $this->priceFunctions->getPriceExcludingTax($priceProduct->getProduct(), ['price' => $amount])
            );
        }, $priceProducts)));

        $discounts = array_unique($discounts);

        if(!$discounts) {
            $discountAmount = $this->priceFunctions->format(0);
        } else if(count($discounts) == 1) {
            $discountAmount = $discounts[0];
        } else if(count($discounts) == 2) {
            $discountAmount = $this->priceFunctions->formatRange($discounts[0], $discounts[1]);
        }

        $replacements = [
            'discounted_price' => $processedProduct->getPriceHtml(false),
            'discount_amount' => $discountAmount
        ];

        $replacements = apply_filters("adp_message_formatter_replacements", $replacements, $processedProduct, $this);

        return $this->formatter->applyReplacements($replacements);
    }
}
