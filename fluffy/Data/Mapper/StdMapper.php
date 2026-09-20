<?php

namespace Fluffy\Data\Mapper;

use DateTime;
use DateTimeZone;
use ReflectionClass;

class StdMapper implements IMapper
{
    public function map(string $type, object $stdObject, $instance = null)
    {
        if ($type === 'DateTime') {
            return new DateTime($stdObject->date, new DateTimeZone($stdObject->timezone));
        }
        $instance = $instance ?? new $type;
        foreach ($stdObject as $key => $value) {
            if (property_exists($instance, $key)) {
                if (is_object($value)) {
                    $reflection = new ReflectionClass($type);
                    $property = $reflection->getProperty($key);
                    $propertyType = $property->getType();
                    if ($propertyType != null) {
                        $typeName = $propertyType->getName();
                        if ($propertyType->isBuiltin()) {
                            // A JSON object landing on a scalar/array property: a MAP, not a nested
                            // model ({"12": 3} into `array $Previous`). Recursing here treated the
                            // array as an object to fill and died in property_exists().
                            $instance->$key = $typeName === 'array' ? (array) $value : $value;
                        } else {
                            $instance->$key = $this->map($typeName, $value, $instance->$key);
                        }
                    } else {
                        $instance->$key = $value;
                    }
                } else {
                    $instance->$key = $value;
                }
            }
        }
        return $instance;
    }

    private array $reflectionMap = [];
    private array $reflectionProperties = [];

    public function mapAssoc(string $type, array $arr, $instance = null)
    {
        $instance = $instance ?? new $type;
        $reflection = $this->reflectionMap[$type] ?? ($this->reflectionMap[$type] = new ReflectionClass($type));
        foreach ($arr as $key => $value) {
            if (property_exists($instance, $key)) {
                $property = $reflection->getProperty($key);
                $propertyType = $property->getType();
                if ($propertyType === null || $propertyType?->isBuiltin()) {
                    $instance->$key = $value;
                } else if ($propertyType->getName() == 'DateTime') {
                    $instance->$key = new DateTime($value, new DateTimeZone('UTC'));
                } else {
                    $instance->$key = $this->map($propertyType->getName(), $value);
                }
            }
        }
        return $instance;
    }
}
