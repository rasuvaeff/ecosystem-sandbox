<?php

declare(strict_types=1);

namespace App\Application\Marketing\GetAttribution;

use Rasuvaeff\Yii3Utm\ChannelResolver;
use Rasuvaeff\Yii3Utm\UtmAttributionRepository;

/**
 * Where the buyer of an order came from, as yii3-utm-db journalled it at
 * checkout; the channel is derived on read, never stored.
 */
final readonly class GetAttributionHandler
{
    public function __construct(
        private UtmAttributionRepository $attributions,
        private ChannelResolver $channels,
    ) {}

    /**
     * @return list<array{channel: string, source: ?string, medium: ?string, campaign: ?string, clickIds: object, referrerHost: ?string, landingPage: ?string}>
     */
    public function handle(GetAttributionQuery $query): array
    {
        $rows = [];
        foreach ($this->attributions->findByEntity($query->orderId->value) as $record) {
            $touchpoint = $record->attribution()->touchpoint;
            $rows[] = [
                'channel' => $this->channels->resolve($touchpoint)->value,
                'source' => $touchpoint->utm->source,
                'medium' => $touchpoint->utm->medium,
                'campaign' => $touchpoint->utm->campaign,
                // An object even when empty: no click ids is `{}`, not `[]`.
                'clickIds' => (object) $touchpoint->clickIds->toArray(),
                'referrerHost' => $touchpoint->referrer?->host,
                'landingPage' => $touchpoint->landingPage,
            ];
        }

        return $rows;
    }
}
