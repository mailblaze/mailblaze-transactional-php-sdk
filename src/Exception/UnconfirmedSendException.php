<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Exception;

/**
 * The API may already have accepted the send, but the SDK could not read a confirmation.
 *
 * This extends TransportException so a 0.1.0 `catch (TransportException)` still sees it.
 * That handler must already check the list before sending again. It is not an ApiException:
 * a 2xx response whose body cannot be read is not a confirmed rejection.
 *
 * reason() is "response", including when reading the body timed out.
 */
final class UnconfirmedSendException extends TransportException
{
    /**
     * reason() is always TransportException::REASON_RESPONSE: the request reached the API, which
     * answered with HTTP 2xx, but the body could not be read or confirmed.
     */
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        #[\SensitiveParameter]
        ?string $secret = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $errorCode, $errorMessage, $secret, $previous);
        $this->setReason(self::REASON_RESPONSE);
    }
}
