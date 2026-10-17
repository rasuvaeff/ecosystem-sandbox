<?php

declare(strict_types=1);

use App\Endpoint\Web\Ui\UiPage;
use Rasuvaeff\Yii3Seo\SeoInjection;
use Yiisoft\Html\Html;
use Yiisoft\View\WebView;

/**
 * @var WebView $this
 * @var SeoInjection $seo
 * @var string $content
 */

$page = $this->getParameter('page');
\assert($page instanceof UiPage);

$this->beginPage();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::encode($seo->getTitle()) ?></title>
    <?php $this->head() ?>
    <?= $seo->getJsonLdHtml() ?>
    <link rel="stylesheet" href="styles.css">
</head>
<body data-title-key="<?= Html::encode($page->titleKey()) ?>">
<?php $this->beginBody() ?>
<?= $content ?>
<script src="common.js"></script>
<script src="<?= Html::encode($page->script()) ?>"></script>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage(true);
