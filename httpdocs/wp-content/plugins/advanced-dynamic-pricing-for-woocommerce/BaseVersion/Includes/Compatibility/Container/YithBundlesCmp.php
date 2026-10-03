<?php

namespace ADP\BaseVersion\Includes\Compatibility\Container;

use ADP\BaseVersion\Includes\CartProcessor\CartProcessor;
use ADP\BaseVersion\Includes\Context;
use ADP\BaseVersion\Includes\Core\Cart\CartItem\Type\Container\ContainerCartItem;
use ADP\BaseVersion\Includes\Core\Cart\CartItem\Type\Container\ContainerPartCartItem;
use ADP\BaseVersion\Includes\Core\Cart\CartItem\Type\Container\ContainerPriceTypeEnum;
use ADP\BaseVersion\Includes\WC\WcCartItemFacade;
use WC_Product_Yith_Bundle;
use YITH_WC_Bundled_Item;

defined('ABSPATH') or exit;

/**
 * Plugin Name: YITH WooCommerce Product Bundles
 * Author: YITH
 *
 * @see https://wordpress.org/plugins/yith-woocommerce-product-bundles/
 */
class YithBundlesCmp extends AbstractContainerCompatibility
{
    /**
     * @var Context
     */
    private $context;

    /**
     * @var YITH_WCPB_Frontend_Premium
     */
    private $yithWcpbFrontendPremium = null;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function prepareHooks(): void
    {
        add_filter( 'yith_wcpb_woocommerce_get_price_html', [$this, 'fixBundlePriceHtml'], 999, 2 );

        if($this->context->getOption('suppress_other_pricing_plugins')) {
            add_filter('yith_wcpb_ajax_update_price_enabled', function ($enabled) {
                return false;
            }, 999);
        }

        add_filter(
            'yith_wcpb_ajax_get_bundle_total_price',
            [$this, 'fixBundleTotalPriceHtml'],
            999,
            3
        );
    }

    /**
     * @var YITH_WC_Bundled_Item $product
     * */
    public function fixBundleTotalPriceHtml($price_html, $price, $product)
    {
        $regularPrice = (float) $product->get_per_item_price_tot_max();
        $price        = (float) $price;

        if ($regularPrice <= $price) {
            return wc_price($price);
        }

        return wc_format_sale_price(
            wc_price($regularPrice),
            wc_price($price)
        );
    }

    /**
     * @var WC_Product_Yith_Bundle $productBundle
     * */
    public function fixBundlePriceHtml($price_html, $productBundle)
    {
        $pricedIndividually = false; //'yes' === $productBundle->get_meta( '_yith_wcpb_per_item_pricing' );
        if (!($productBundle instanceof \WC_Product_Yith_Bundle)) {
            return $price_html;
        }
        if ($pricedIndividually) {
            $regular_price = $productBundle->get_price();
            $sale_price = $productBundle->get_sale_price();

            if ($productBundle->is_on_sale()) {
                $price_html = wc_format_sale_price($regular_price, $sale_price);
            }
        }

       return apply_filters( 'woocommerce_get_price_html', $price_html, $productBundle );
    }


    protected function getContext(): Context
    {
        return $this->context;
    }

    /**
     * @param WcCartItemFacade $facade
     *
     * @return bool
     */
    public function isFacadeAPartOfContainer(WcCartItemFacade $facade): bool
    {
        $trdPartyData = $facade->getThirdPartyData();

        return isset($trdPartyData['bundled_by']);
    }

    /**
     * @param WcCartItemFacade $facade
     *
     * @return bool
     */
    public function isContainerFacade(WcCartItemFacade $facade): bool
    {
        $trdPartyData = $facade->getThirdPartyData();

        return isset($trdPartyData['yith_parent']) && isset($trdPartyData['bundled_items']);
    }

    public function isActive(): bool
    {
        return defined('YITH_WCPB_VERSION');
    }

    public function isContainerProduct(\WC_Product $wcProduct): bool
    {
        return $wcProduct instanceof \WC_Product_Yith_Bundle;
    }

    public function isFacadeAPartOfContainerFacade(
        WcCartItemFacade $partOfContainerFacade,
        WcCartItemFacade $bundle
    ): bool {
        $thirdPartyData = $bundle->getThirdPartyData();

        return in_array($partOfContainerFacade->getKey(), $thirdPartyData['bundled_items'] ?? [], true);
    }

    public function getListOfPartsOfContainerFromContainerProduct(\WC_Product $product): array
    {
        if (!($product instanceof \WC_Product_Yith_Bundle)) {
            return [];
        }

        $pricedIndividually = false; //'yes' === $product->get_meta( '_yith_wcpb_per_item_pricing' );
        return array_map(
            function ($bundleItem) use ($product, $pricedIndividually) {
                /** @var \YITH_WC_Bundled_Item $bundleItem */
                $bundledProduct = $bundleItem->get_product();

                $price = floatval($bundledProduct->get_price('edit'));

                if ($bundleItem->apply_discount){
                    $discount = (float) $bundleItem->discount;
                    $amount = round($price * $discount / 100, wc_get_price_decimals());
                    $price  = round($price - $amount, wc_get_price_decimals());
                }

                return ContainerPartProduct::of(
                    $product,
                    $bundledProduct,
                    (float)$price,
                    (float)$bundleItem->get_quantity(),
                    $pricedIndividually
                );
            },
            $product->get_bundled_items()
        );
    }

    public function calculatePartOfContainerPrice(WcCartItemFacade $facade): float
    {
        $thirdPartyData = $facade->getThirdPartyData();
        $product = $facade->getProduct();
        $reflection = new \ReflectionClass($product);
        $property = $reflection->getProperty('data');
        $property->setAccessible(true);
        $price = $property->getValue($product)['price'];
        if(isset($thirdPartyData['discount'])){
            $discount = floatval($thirdPartyData['discount']);
            $amount = round($price * $discount / 100, wc_get_price_decimals());
            $price  = round($price - $amount, wc_get_price_decimals());
        }

        return floatval($price);
    }

    /**
     * @param WcCartItemFacade $facade
     * @param array<int, WcCartItemFacade> $children
     * @return float
     */
    public function calculateContainerPrice(WcCartItemFacade $facade, array $children): float
    {
        $product = $facade->getProduct();
        $pricedIndividually = false; //'yes' === $product->get_meta( '_yith_wcpb_per_item_pricing' );
        if (!($product instanceof \WC_Product_Yith_Bundle)) {
            return floatval($facade->getProduct()->get_price('edit'));
        }
        if ($pricedIndividually) {
            $price = 0.0;
            foreach ($product->get_bundled_items() as $bundledProduct) {
                if ($bundledProduct->apply_discount) {
                    $amount = round(floatval($bundledProduct->get_product()->get_price()) * $bundledProduct->discount / 100, wc_get_price_decimals());
                    $price  += round(floatval($bundledProduct->get_product()->get_price()) - $amount, wc_get_price_decimals());
                } else {
                    $price += floatval($bundledProduct->get_product()->get_price());
                }
            }
            return $price;

        }
        return floatval($facade->getProduct()->get_price('edit'));
    }

    /**
     * @param WcCartItemFacade $facade
     * @param array<int, WcCartItemFacade> $children
     * @return float
     */
    public function calculateContainerBasePrice(WcCartItemFacade $facade, array $children): float
    {
        $product = $facade->getProduct();
        $pricedIndividually = false; //'yes' === $product->get_meta( '_yith_wcpb_per_item_pricing' );
        if($pricedIndividually){
            return 0.0;
        }
        return floatval(CartProcessor::getProductPriceDependsOnPriceMode($facade->getProduct()));
    }

    public function getContainerPriceTypeByParentFacade(WcCartItemFacade $facade): ?ContainerPriceTypeEnum
    {
        $product = $facade->getProduct();
        $pricedIndividually = false; //'yes' === $product->get_meta( '_yith_wcpb_per_item_pricing' );
        if($pricedIndividually){
            return ContainerPriceTypeEnum::BASE_PLUS_SUM_OF_SUB_ITEMS();
        }
        return ContainerPriceTypeEnum::FIXED();
    }

    public function isPartOfContainerFacadePricedIndividually(WcCartItemFacade $facade): ?bool
    {
        $trdPartyData = $facade->getThirdPartyData();

        if (empty($trdPartyData['bundled_by'])) {
            return false;
        }
        $cartItem = WC()->cart->get_cart_item($trdPartyData['bundled_by']);

        if (!$cartItem || empty($cartItem['data'])) {
            return false;
        }
        $product = $cartItem['data'];
        $pricedIndividually = false; //'yes' === $product->get_meta( '_yith_wcpb_per_item_pricing' );
        if($pricedIndividually){
            return true;
        }
        return false;
    }

    public function adaptContainerCartItem(
        WcCartItemFacade $facade,
        array $children,
        int $pos
    ): ContainerCartItem {
        $containerItem = parent::adaptContainerCartItem($facade, $children, $pos);

        return $containerItem->setItems(
            array_map(
                function ($subContainerItem) use ($facade) {
                    /** @var ContainerPartCartItem $subContainerItem */
                    return $this->modifyPartOfContainerItemQty($subContainerItem, $facade);
                },
                array_map([$this, 'adaptContainerPartCartItem'], $children)
            )
        );
    }

    /**
     * @param ContainerPartCartItem $subContainerItem
     * @param WcCartItemFacade $parentFacade
     * @return ContainerPartCartItem
     */
    protected function modifyPartOfContainerItemQty(
        ContainerPartCartItem $subContainerItem,
        WcCartItemFacade $parentFacade
    ): ContainerPartCartItem {
        $subContainerItem->setQty($subContainerItem->getQty() / $parentFacade->getQty());

        return $subContainerItem;
    }

    public function overrideContainerReferenceForPartOfContainerFacadeAfterPossibleDuplicates(
        WcCartItemFacade $partOfContainerFacade,
        WcCartItemFacade $containerFacade
    ) {
        $partOfContainerFacade->setThirdPartyData('bundled_by', $containerFacade->getKey());

        $parentFacadeThirdPartyData = $containerFacade->getThirdPartyData();
        $bundledItems = $parentFacadeThirdPartyData['bundled_items'] ?? null;
        if ($bundledItems === null) {
            return;
        }

        $i = array_search($partOfContainerFacade->getOriginalKey(), $bundledItems);
        if ($i !== false) {
            $bundledItems = array_replace(
                $bundledItems,
                [$i => $partOfContainerFacade->getKey()]
            );

            $containerFacade->setThirdPartyData('bundled_items', $bundledItems);
        }
    }
}
