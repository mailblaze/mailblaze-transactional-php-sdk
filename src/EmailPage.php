<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use MailBlaze\Transactional\Exception\ApiException;
use MailBlaze\Transactional\Http\Values;

final class EmailPage
{
    /**
     * @param list<EmailSummary> $records
     */
    private function __construct(
        private readonly ?int $count,
        private readonly ?int $totalPages,
        private readonly ?int $currentPage,
        private readonly ?int $nextPage,
        private readonly ?int $prevPage,
        private readonly array $records,
        private readonly string $code,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $data = Values::object($payload['data'] ?? null, 'data');
        $records = $data['records'] ?? null;
        if (!is_array($records) || !array_is_list($records)) {
            throw new ApiException('The Mail Blaze API returned an unexpected records payload');
        }

        $emails = [];
        foreach ($records as $record) {
            $emails[] = EmailSummary::fromArray(Values::object($record, 'record'));
        }

        return new self(
            Values::nullableInt($data['count'] ?? null, 'count'),
            Values::nullableInt($data['total_pages'] ?? null, 'total_pages'),
            Values::nullableInt($data['current_page'] ?? null, 'current_page'),
            Values::nullableInt($data['next_page'] ?? null, 'next_page'),
            Values::nullableInt($data['prev_page'] ?? null, 'prev_page'),
            $emails,
            Values::string($payload['code'] ?? null, 'code'),
        );
    }

    public function count(): ?int
    {
        return $this->count;
    }

    public function totalPages(): ?int
    {
        return $this->totalPages;
    }

    public function currentPage(): ?int
    {
        return $this->currentPage;
    }

    public function nextPage(): ?int
    {
        return $this->nextPage;
    }

    public function prevPage(): ?int
    {
        return $this->prevPage;
    }

    /**
     * @return list<EmailSummary>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function code(): string
    {
        return $this->code;
    }
}
