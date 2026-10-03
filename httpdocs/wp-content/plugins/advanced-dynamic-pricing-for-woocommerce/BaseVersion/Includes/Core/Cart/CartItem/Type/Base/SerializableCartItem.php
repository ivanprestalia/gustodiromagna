<?php
namespace ADP\BaseVersion\Includes\Core\Cart\CartItem\Type\Base;

use WC_Product;

/** @phpstan-consistent-constructor */
class SerializableCartItem
{
    public function toArray() {
        $reflect = new \ReflectionClass($this);
        $props   = $reflect->getProperties();

        $obj = [];
        foreach ( $props as $prop ) {
            $prop->setAccessible(true);
            $value = $prop->getValue($this);

            if ( is_object($value) ) {
                if ( $value instanceof WC_Product) {
                    $obj[$prop->getName()] = [
                        'class' => 'WC_Product',
                        'id' => $value->get_id()
                    ];
                }
            } else {
                $obj[$prop->getName()] = $value;
            }
        }

        return $obj;
    }

    public static function fromArray($data)
    {
        /** @phpstan-ignore-next-line */
        $new = new static(new WC_Product(), 0.0, 0, "");
        $reflect = new \ReflectionClass($new);
        $props = $reflect->getProperties();

        foreach ($props as $prop) {
            $prop->setAccessible(true);

            if (isset($data[$prop->getName()])) {
                $value = $data[$prop->getName()];

                if (isset($value['class'], $value['id']) && $value['class'] === 'WC_Product') {
                    $prop->setValue($new, wc_get_product($value['id']));
                } else {
                    $prop->setValue($new, $data[$prop->getName()]);
                }
            }
        }

        return $new;
    }
}
