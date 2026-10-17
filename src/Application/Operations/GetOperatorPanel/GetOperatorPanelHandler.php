<?php

declare(strict_types=1);

namespace App\Application\Operations\GetOperatorPanel;

use Rasuvaeff\Yii3AuditLog\AuditChangeSet;
use Rasuvaeff\Yii3AuditLog\SensitiveValueMasker;
use Rasuvaeff\Yii3FeatureFlags\FeatureFlags;
use Rasuvaeff\Yii3FeatureFlags\FlagProvider;
use Rasuvaeff\Yii3MaintenanceMode\MaintenanceProvider;
use Rasuvaeff\Yii3Settings\SettingDefinition;
use Rasuvaeff\Yii3Settings\SettingsProvider;

/**
 * What an operator controls, read-only: feature flags with the reason each
 * evaluated the way it did, the maintenance switch, typed runtime settings and
 * the audit masker. Flags and settings are changed on the packages' admin
 * pages (/admin/flags, /admin/settings); maintenance stays a deployment
 * decision (environment variables).
 *
 * Never returned: the maintenance bypass hash, secret settings' values.
 */
final readonly class GetOperatorPanelHandler
{
    /**
     * @param array<string, array{type: string, default?: mixed, secret?: bool}> $settingDefinitions
     * @param list<string> $sensitiveKeys
     */
    public function __construct(
        private FeatureFlags $flags,
        private FlagProvider $flagStore,
        private MaintenanceProvider $maintenance,
        private SettingsProvider $settings,
        private SensitiveValueMasker $masker,
        private array $settingDefinitions,
        private array $sensitiveKeys,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $flags = [];
        // Every flag in the store, so one created on the admin page shows here too.
        foreach (array_keys($this->flagStore->getFlags()) as $name) {
            $result = $this->flags->evaluate(flag: $name);
            $flags[] = ['name' => $name, 'enabled' => $result->isEnabled(), 'reason' => $result->getReason()->value];
        }

        $settings = [];
        foreach ($this->settingDefinitions as $key => $config) {
            $definition = SettingDefinition::fromConfig($key, $config);
            $settings[] = [
                'key' => $key,
                'value' => $definition->isSecret() ? null : $this->settings->get($key),
                'secret' => $definition->isSecret(),
            ];
        }

        // A sample change set run through the real masker: no order field is
        // sensitive, so this is the honest way to show what masking does.
        $sample = AuditChangeSet::fromArrays(
            old: ['email' => 'old@example.com', 'api_key' => 'sk_live_old', 'status' => 'pending'],
            new: ['email' => 'new@example.com', 'api_key' => 'sk_live_new', 'status' => 'processing'],
        );

        $state = $this->maintenance->getState();

        return [
            'flags' => $flags,
            'maintenance' => ['enabled' => $state->enabled, 'retryAfterSeconds' => $state->retryAfter],
            'settings' => $settings,
            'auditMasking' => [
                'sensitiveKeys' => $this->sensitiveKeys,
                'input' => $this->changes($sample),
                'output' => $this->changes($this->masker->maskChangeSet($sample)),
            ],
        ];
    }

    /** @return list<array{field: string, old: mixed, new: mixed}> */
    private function changes(AuditChangeSet $changeSet): array
    {
        $changes = [];
        foreach ($changeSet->getChanges() as $change) {
            $changes[] = ['field' => $change->getField(), 'old' => $change->getOldValue(), 'new' => $change->getNewValue()];
        }

        return $changes;
    }
}
