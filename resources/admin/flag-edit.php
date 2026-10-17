<?php

declare(strict_types=1);

use Rasuvaeff\Yii3FeatureFlags\Flag;
use Rasuvaeff\Yii3FeatureFlagsUi\Form\FlagForm;
use Yiisoft\Html\Html;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var FlagForm $form
 * @var Flag|null $flag
 * @var bool $isNew
 * @var bool $isWritable
 * @var string $updateUrl
 * @var string|null $deleteUrl
 * @var string $listUrl
 * @var string|null $error
 * @var Csrf|null $csrf
 *
 * The package's edit view with one change: `$csrf` is only defined when a CSRF
 * middleware injects it, and the original read it unguarded — every edit page
 * was a 500 in an application without CSRF. yii3-settings-ui guards it the
 * same way. Drop this copy once yii3-feature-flags-ui ships the fix.
 */

$this->setTitle($isNew ? 'New flag' : 'Edit: ' . $form->name);

$nameIsEditable = $isNew;
$disabled = !$isWritable;
$inputAttrs = $disabled ? ['class' => 'form-control', 'disabled' => true] : ['class' => 'form-control'];
?>

<div class="container-fluid">
    <h1 class="h3 mb-3"><?= $isNew ? 'New flag' : 'Edit flag' ?></h1>

    <ul class="list-unstyled mb-4">
        <?php if (!$isNew && $flag !== null): ?>
            <li>Name: <code><?= Html::encode($flag->name) ?></code></li>
        <?php endif ?>
    </ul>

    <?php if (!$isWritable): ?>
        <div class="alert alert-warning">Provider is read-only — fields are disabled.</div>
    <?php endif ?>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= Html::encode($error) ?></div>
    <?php endif ?>

    <form method="post" action="<?= Html::encode($updateUrl) ?>">
        <?php if (($csrf ?? null) !== null): ?>
            <?= $csrf->hiddenInput() ?>
        <?php endif ?>

        <div class="mb-3">
            <label class="form-label">Name</label>
            <?= Html::textInput(
                'Flag[name]',
                $form->name,
                $nameIsEditable
                    ? $inputAttrs
                    : array_merge($inputAttrs, ['disabled' => true]),
            ) ?>
            <div class="form-text text-muted">Lowercase letters, digits, dots, hyphens and underscores; must start with a letter.</div>
        </div>

        <div class="form-check mb-3">
            <?= Html::checkbox(
                'Flag[enabled]',
                '1',
                $disabled
                    ? ['class' => 'form-check-input', 'checked' => $form->enabled, 'disabled' => true]
                    : ['class' => 'form-check-input', 'checked' => $form->enabled],
            ) ?>
            <label class="form-check-label">Enabled</label>
        </div>

        <div class="mb-3">
            <label class="form-label">Rollout (%)</label>
            <?= Html::textInput('Flag[rollout]', $form->rollout, $inputAttrs) ?>
            <div class="form-text text-muted">0..100. Without user/tenant context, 100 means on for everyone.</div>
        </div>

        <div class="mb-3">
            <label class="form-label">Salt</label>
            <?= Html::textInput('Flag[salt]', $form->salt, $inputAttrs) ?>
            <div class="form-text text-muted">Blank falls back to the flag name. <strong>Warning:</strong> changing the salt re-randomizes the cohort.</div>
        </div>

        <div class="form-check mb-3">
            <?= Html::checkbox(
                'Flag[killSwitch]',
                '1',
                $disabled
                    ? ['class' => 'form-check-input', 'checked' => $form->killSwitch, 'disabled' => true]
                    : ['class' => 'form-check-input', 'checked' => $form->killSwitch],
            ) ?>
            <label class="form-check-label">Kill switch</label>
            <div class="form-text text-danger">Overrides rollout, targeting and forced values. Use for emergency shutdown.</div>
        </div>

        <div class="mb-3">
            <label class="form-label">Environments (JSON array)</label>
            <?= Html::textarea('Flag[environments]', Html::encode($form->environments), array_merge(['rows' => 3], $inputAttrs)) ?>
            <div class="form-text text-muted">Empty array or blank means all environments, e.g. <code>["prod", "staging"]</code>.</div>
        </div>

        <div class="d-flex gap-2 align-items-center">
            <?php if ($isWritable): ?>
                <button type="submit" class="btn btn-primary">Save</button>
                <?php if (!$isNew && $deleteUrl !== null): ?>
                    <button type="submit"
                        class="btn btn-outline-danger"
                        formaction="<?= Html::encode($deleteUrl) ?>"
                        onclick="return confirm('Delete flag?')">Delete</button>
                <?php endif ?>
            <?php endif ?>
            <a href="<?= Html::encode($listUrl) ?>" class="btn btn-link">Cancel</a>
        </div>
    </form>
</div>
