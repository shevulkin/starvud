<?php /** @var string $content */ ?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? cfg('app_name')) ?></title>
<meta name="description" content="<?= e($meta_description ?? Settings::get('seo_description', '')) ?>">
<?php if (Settings::bool('seo_noindex')): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<meta property="og:title" content="<?= e($page_title ?? cfg('app_name')) ?>">
<meta property="og:description" content="<?= e($meta_description ?? Settings::get('seo_description', '')) ?>">
<meta property="og:type" content="<?= !empty($jsonld_product) ? 'product' : 'website' ?>">
<meta property="og:site_name" content="<?= e(cfg('app_name')) ?>">
<meta property="og:locale" content="uk_UA">
<meta property="og:url" content="<?= e(current_url()) ?>">
<meta property="og:image" content="<?= e(asset_abs($og_image ?? (!empty($p) && !empty($jsonld_product) ? Catalog::photo($p) : 'img/og-default.jpg'))) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#1D6B64">
<link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" sizes="64x64">
<link rel="apple-touch-icon" href="<?= e(asset('img/avatar.png')) ?>">
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<?php /* Шрифти — свої файли з assets/fonts: CSP пускає шрифти лише з нашого
         домену, а Google Fonts додав би стороннього адресата кожного візиту.
         Кирилиця — наперед, щоб заголовок не блимав (шрифт змінний: один файл на всі накреслення). */ ?>
<link rel="preload" href="<?= e(asset('fonts/Onest-var-cyr.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset_v('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_v('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_v('css/starvud.css')) ?>">
<link rel="canonical" href="<?= e(current_url()) ?>">
<?php
echo JsonLd::tag(JsonLd::organization());
echo JsonLd::tag(JsonLd::website());
foreach (($jsonld ?? []) as $block) echo JsonLd::tag($block);
?>
</head>
<body class="sv<?= !empty($body_class) ? ' ' . e($body_class) : '' ?>">
<a class="sv-skip" href="#main">До вмісту</a>
<?php if (Settings::bool('sale_banner_active')): ?>
<div class="sale-banner"><?= e(Settings::get('sale_banner_text', '')) ?> · −<?= e(Settings::get('sale_banner_percent', '0')) ?>%</div>
<?php endif; ?>
<?= View::partial('partials/header') ?>
<?php if ($msg = flash('success')): ?><div class="flash"><div class="flash-success" role="status"><?= e($msg) ?></div></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="flash"><div class="flash-error" role="alert"><?= e($msg) ?></div></div><?php endif; ?>
<main id="main"><?= $content ?></main>
<?= View::partial('partials/footer') ?>
<?= View::partial('partials/auth_modal') ?>
<div class="cart-toast sv-toast" id="cartToast" role="status" aria-live="polite">
  <div class="sv-toast-ic" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
  <div class="cart-toast-body">
    <div class="cart-toast-text">Товар додано в кошик</div>
    <div class="cart-toast-actions">
      <button type="button" class="btn btn-line btn-xs" id="cartToastContinue">Продовжити покупки</button>
      <a href="<?= e(url('/checkout')) ?>" class="btn btn-gold btn-xs">Оформити</a>
    </div>
  </div>
  <button type="button" class="cart-toast-close" id="cartToastClose" aria-label="Закрити">×</button>
</div>
<button class="to-top" id="toTop" aria-label="Догори">↑</button>
<script src="<?= e(asset_v('js/app.js')) ?>" defer></script>
<?php if (EditMode::active()) echo View::partial('partials/edit_bar'); ?>
<?php if (Pos::active()) echo View::partial('partials/pos_bar'); ?>
</body>
</html>
