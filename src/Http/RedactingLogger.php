<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Http;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

final class RedactingLogger extends AbstractLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter]
        string $secret,
    ) {
        SecretBag::store($this, $secret);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $secret = SecretBag::get($this);
        $this->logger->log(
            $level,
            Redactor::redact((string) $message, $secret),
            Redactor::redactContext($context, $secret),
        );
    }
}
