<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Exception;

/**
 * The client was configured with an invalid value, such as a malformed API key or base URI,
 * or the environment is missing a dependency the SDK needs.
 *
 * This is a programming or deployment error. It is thrown before any request is made and
 * must not be retried. It extends \InvalidArgumentException, so code written for 0.1.x that
 * caught \InvalidArgumentException for a bad timeout or retry setting still sees it. It is not
 * a MailBlazeException, which is reserved for failures of an API request.
 */
final class ConfigurationException extends \InvalidArgumentException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
        // The trace can hold the API key or other constructor arguments. Drop every argument.
        $trace = [];
        foreach ($this->getTrace() as $frame) {
            unset($frame['args']);
            $trace[] = $frame;
        }
        $property = new \ReflectionProperty(\Exception::class, 'trace');
        $property->setValue($this, $trace);
    }
}
