<?php

declare(strict_types=1);

namespace App\Application\Payments\GetReceipt;

final readonly class ReceiptView implements \JsonSerializable
{
    public function __construct(
        public string $fileName,
        public int $size,
        public ?string $mimeType,
        public ?string $url,
    ) {}

    /**
     * @return array{fileName: string, size: int, mimeType: ?string, url: ?string}
     */
    public function jsonSerialize(): array
    {
        return ['fileName' => $this->fileName, 'size' => $this->size, 'mimeType' => $this->mimeType, 'url' => $this->url];
    }
}
