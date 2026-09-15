<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

use Illuminate\Http\Client\ConnectionException as IlluminateConnectionException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

class ConnectionException extends PloreaException
{
    /**
     * Wrap a transport failure, redacting the API key from the chain first.
     */
    public static function from(IlluminateConnectionException $exception): self
    {
        $message = "Could not connect to the Plorea API: {$exception->getMessage()}";
        $code = (int) $exception->getCode();

        // If any exception in the chain still holds the key after redaction,
        // the chain does not leave this method. A lost stack trace is cheaper
        // than a leaked credential.
        return self::redactCredentials($exception)
            ? new self($message, $code, $exception)
            : new self($message, $code);
    }

    /**
     * Illuminate wraps Guzzle's transfer exception, which keeps the outbound
     * request — and with it the Authorization header — reachable through
     * getPrevious(). Strip the header from every request in the chain so
     * dumping or serializing the exception cannot leak the key.
     *
     * The response path is redacted separately, in RequestException.
     *
     * @return bool Whether the whole chain is now free of the credential.
     */
    private static function redactCredentials(Throwable $exception): bool
    {
        $redacted = true;
        $depth = 0;
        $current = $exception;

        // Guzzle's own chains are two or three deep; the bound stops a
        // pathological cycle from hanging the caller.
        while ($current instanceof Throwable && $depth < 10) {
            if ($current instanceof RequestExceptionInterface || $current instanceof NetworkExceptionInterface) {
                $redacted = self::redactRequestOn($current) && $redacted;
            }

            $current = $current->getPrevious();
            $depth++;
        }

        return $redacted;
    }

    /**
     * @return bool Whether the exception is now free of the credential.
     */
    private static function redactRequestOn(RequestExceptionInterface|NetworkExceptionInterface $exception): bool
    {
        $request = $exception->getRequest();

        if (! $request->hasHeader('Authorization')) {
            return true;
        }

        $property = self::requestPropertyOn($exception);

        if (! $property instanceof ReflectionProperty || $property->isReadOnly()) {
            return false;
        }

        try {
            $property->setValue($exception, $request->withHeader('Authorization', '[redacted]'));
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Both Guzzle exceptions that carry a request hold it in a private
     * `request` property, which is the only way to write a redacted copy
     * back — PSR-7 requests are immutable and there is no setter.
     */
    private static function requestPropertyOn(object $exception): ?ReflectionProperty
    {
        $class = new ReflectionClass($exception);

        while ($class instanceof ReflectionClass) {
            if ($class->hasProperty('request')) {
                $property = $class->getProperty('request');

                if (! $property->isStatic()) {
                    return $property;
                }
            }

            $parent = $class->getParentClass();
            $class = $parent === false ? null : $parent;
        }

        return null;
    }
}
