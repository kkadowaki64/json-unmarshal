<?php

namespace JSON;

use Exception;
use JSON\Attributes\JSON;
use ReflectionClass;
use ReflectionException;
use ReflectionType;
use ReflectionUnionType;

/**
 * Class Unmarshal.
 */
class Unmarshal
{
    /**
     * @param object $class
     * @param array  $data
     *
     * @throws Exception
     */
    public static function decode(object &$class, array $data): void
    {
        $reflectionClass = new ReflectionClass($class);
        foreach ($reflectionClass->getProperties() as $property) {
            $attributes = $property->getAttributes(JSON::class);
            foreach ($attributes as $attribute) {
                $jsonAttribute = $attribute->newInstance();
                if (! $property->hasType()) {
                    continue;
                }
                $propertyType = $property->getType();

                $targetValue = self::getValueFromData(
                    $data,
                    $jsonAttribute->field,
                    $class->{$property->name} ?? null,
                );

                $targetType = match ($propertyType::class) {
                    \ReflectionNamedType::class => $propertyType->getName(),
                    \ReflectionUnionType::class => self::detectType($targetValue, $propertyType),
                    default => null,
                };
                if (is_null($targetType)) {
                    throw new Exception(sprintf('unexpected class `%s`', $propertyType::class));
                }

                /** @var string $targetType */
                switch ($targetType) {
                    case 'string':
                        $class->{$property->name} =
                            is_null($targetValue)
                            ? $propertyType->allowsNull() ? null : ''
                            : (string) $targetValue;
                        break;
                    case 'int':
                        $class->{$property->name} =
                            is_null($targetValue)
                                ? $propertyType->allowsNull() ? null : 0
                                : (int) $targetValue;
                        break;
                    case 'bool':
                        $class->{$property->name} =
                            is_null($targetValue)
                                ? $propertyType->allowsNull() ? null : false
                                : (bool) $targetValue;
                        break;
                    case 'float':
                        $class->{$property->name} =
                            is_null($targetValue)
                                ? $propertyType->allowsNull() ? null : 0.0
                                : (float) $targetValue;
                        break;
                    case 'null':
                        $class->{$property->name} = null;
                        break;
                    case 'array':
                        self::decodeArray(
                            $class,
                            $property->name,
                            $data,
                            $property->name,
                            $jsonAttribute->type,
                            $propertyType->allowsNull()
                        );
                        break;
                    default:
                        self::decodeNonScalar(
                            $class,
                            $property->name,
                            $propertyType->getName(),
                            $data,
                            $jsonAttribute->field,
                            $propertyType->allowsNull()
                        );
                }
            }
        }
    }

    /**
     * @param object      $class
     * @param string      $propertyName
     * @param array       $data
     * @param string      $lookupFieldName
     * @param string|null $type
     * @param bool        $isNullable
     *
     * @throws ReflectionException
     * @throws Exception
     *
     * @psalm-suppress ArgumentTypeCoercion
     */
    private static function decodeArray(
        object &$class,
        string $propertyName,
        array $data,
        string $lookupFieldName,
        ?string $type,
        bool $isNullable,
    ): void {
        if (is_null($type) || empty($type)) {
            throw new Exception('no type specified for array unmarshalling');
        }

        $items = self::getValueFromData(
            $data,
            $lookupFieldName,
            $class->{$propertyName} ?? null,
        );
        if (is_null($items) && $isNullable) {
            $class->{$propertyName} = null;
            return;
        }

        $class->{$propertyName} = [];
        foreach ($items as $item) {
            try {
                $object = new ReflectionClass($type);
            } catch (ReflectionException $exception) {
                throw $exception;
            }

            if ($object->isInstantiable()) {
                $unmarshalItem = $object->newInstance();
                self::decode($unmarshalItem, $item);
                $class->{$propertyName}[] = $unmarshalItem;
            }
        }
    }

    /**
     * @param array  $data
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    private static function getValueFromData(array $data, string $key, mixed $default): mixed
    {
        if (str_contains($key, '.')) {
            $keys = explode('.', $key, 2);

            return self::getValueFromData($data[$keys[0]], $keys[1], $default);
        }

        return $data[$key] ?? $default;
    }

    /**
     * @param object $class
     * @param string $propertyName
     * @param string $type
     * @param array  $data
     * @param string $lookupFieldName
     * @param bool   $isNullable
     *
     * @throws ReflectionException
     *
     * @psalm-suppress ArgumentTypeCoercion
     */
    private static function decodeNonScalar(
        object &$class,
        string $propertyName,
        string $type,
        array $data,
        string $lookupFieldName,
        bool $isNullable
    ): void {
        if (!isset($data[$lookupFieldName]) && $isNullable) {
            $class->{$propertyName} = null;

            return;
        }

        // instantiated property
        if (isset($class->{$propertyName})) {
            self::decode($class->{$propertyName}, $data[$lookupFieldName]);

            return;
        }

        // not instantiated
        try {
            $object = new ReflectionClass($type);
        } catch (ReflectionException) {
            return;
        }

        if ($object->isInstantiable()) {
            $class->{$propertyName} = $object->newInstance();
            $value = self::getValueFromData($data, $lookupFieldName, null);
            if ($value) {
                self::decode($class->{$propertyName}, $data[$lookupFieldName]);
            }
        }
    }

    /**
     * @param mixed $target
     * @param ReflectionUnionType $unionType
     * @return string
     *
     * @throws Exception
     */
    private static function detectType(mixed $target, ReflectionUnionType $unionType): string {
        $candidates = array_map(fn($t) => $t->getName(), $unionType->getTypes());

        $result = match (gettype($target)) {
            'integer' => in_array('int', $candidates)
                ? 'int'
                : new Exception("message" /* TODO */),
            'string'  => in_array('string', $candidates)
                ? 'string'
                : new Exception("message" /* TODO */),
            'boolean' => in_array('bool', $candidates)
                ? 'bool'
                : new Exception("message" /* TODO */),
            'double'  => in_array('float', $candidates)
                ? 'float'
                : new Exception("message" /* TODO */),
            'array'   => in_array('array', $candidates)
                ? 'array'
                : new Exception("message" /* TODO */),
            'NULL'    => in_array('null', $candidates)
                ? 'null'
                : new Exception("message" /* TODO */),
            'object', 'resource', 'resource (closed)' => new Exception("unexpected input" /* TODO */),
            'unknown type'                            => new Exception("unknown type" /* TODO */),
        };

        if ($result instanceof \Exception) {
            throw $result;
        }
        return $result;
    }
}
