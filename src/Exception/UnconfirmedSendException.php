<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Exception;

/**
 * The API may already have accepted the send, but the SDK could not read a confirmation.
 *
 * This extends TransportException so a 0.1.0 `catch (TransportException)` still sees it.
 * That handler must already check the list before sending again. It is not an ApiException:
 * a 2xx response whose body cannot be read is not a confirmed rejection.
 */
final class UnconfirmedSendException extends TransportException {}
