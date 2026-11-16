<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Exception;

use MailBlaze\Transactional\Http\Redactor;

/**
 * A safe copy of an exception thrown by the HTTP client, attached as TransportException::getPrevious().
 *
 * The original exception is not kept, because it can hold the request and its Authorization header.
 * This copy has the original class name, a message with the API key removed and capped at 500 bytes,
 * and no stack trace arguments.
 */
final class RedactedException extends \RuntimeException
{
    private readonly string $originalClass;

    /**
     * @internal
     */
    public function __construct(
        \Throwable $original,
        #[\SensitiveParameter]
        ?string $secret,
    ) {
        $this->originalClass = $original::class;
        $message = Redactor::redact($original->getMessage(), $secret);
        if (strlen($message) > 500) {
            $message = function_exists('mb_strcut') ? mb_strcut($message, 0, 500, 'UTF-8') : substr($message, 0, 500);
        }
        parent::__construct($this->originalClass . ': ' . $message);
        $trace = [];
        foreach ($this->getTrace() as $frame) {
            unset($frame['args']);
            $trace[] = $frame;
        }
        $property = new \ReflectionProperty(\Exception::class, 'trace');
        $property->setValue($this, $trace);
    }

    public function originalClass(): string
    {
        return $this->originalClass;
    }
}
