<?php

namespace ADP\BaseVersion\Includes\Helpers;

defined('ABSPATH') or exit;

class Serializable
{
    public function toArray() {
        $reflect = new \ReflectionClass($this);
        $props   = $reflect->getProperties();

        $obj = [];
        foreach ( $props as $prop ) {
            $prop->setAccessible(true);
            $value = $prop->getValue($this);

            $obj[$prop->getName()] = $value;
        }

        return $obj;
    }

    public static function fromArray($data)
    {
        $reflect = new \ReflectionClass(static::class);
        $props = $reflect->getProperties();

        $new = $reflect->newInstanceWithoutConstructor();
        
        foreach ($props as $prop) {
            $prop->setAccessible(true);

            if (isset($data[$prop->getName()])) {
                $value = $data[$prop->getName()];

                $prop->setValue($new, $value);
            }
        }

        return $new;
    }
}