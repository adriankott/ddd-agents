<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Support;

use DDD\Domain\Base\Entities\ValueObject;
use ReflectionObject;
use ReflectionProperty;

/**
 * A strict comparison of value objects: `assertEquals()` compares objects loosely (`null` equals `0`, `'1'` equals
 * `1`), so each value object is compared as its class and the values of its own public fields, with `assertSame()`.
 * Lists and maps are compared element by element, in order; any other value as it is.
 */
trait AgentVoiceValueObjectAssertionsTrait
{
    protected static function assertSameValueObjects(mixed $expected, mixed $actual, string $message = ''): void
    {
        static::assertSame(static::describeValueObjects($expected), static::describeValueObjects($actual), $message);
    }

    /** The class and the fields of every value object in `$value`, recursively; other values unchanged */
    protected static function describeValueObjects(mixed $value): mixed
    {
        if (is_array($value)) {
            $describedValues = [];
            foreach ($value as $key => $element) {
                $describedValues[$key] = static::describeValueObjects($element);
            }
            return $describedValues;
        }
        if (!$value instanceof ValueObject) {
            return $value;
        }
        $describedFields = ['class' => $value::class];
        foreach ((new ReflectionObject($value))->getProperties(ReflectionProperty::IS_PUBLIC) as $publicProperty) {
            if ($publicProperty->isStatic() || !is_subclass_of($publicProperty->getDeclaringClass()->getName(), ValueObject::class)) {
                continue;
            }
            $describedFields[$publicProperty->getName()] = $publicProperty->isInitialized($value)
                ? static::describeValueObjects($publicProperty->getValue($value))
                : '(not initialized)';
        }
        return $describedFields;
    }
}
