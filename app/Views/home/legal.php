<?php /**
 * Правова сторінка: доставка, оплата, повернення, приватність, оферта.
 * Вузька колонка — це документ, який читають рядок за рядком.
 */
$tabs = ['delivery' => 'Доставка', 'payment' => 'Оплата', 'returns' => 'Обмін і повернення'];
?>
<div class="container sv-page sv-legal">
  <nav class="sv-crumbs" aria-label="Хлібні крихти">
    <a href="<?= e(url('/')) ?>">Головна</a><span>/</span><span aria-current="page"><?= e($heading) ?></span>
  </nav>
  <?php if (isset($tabs[$slug])): ?>
    <nav class="sv-tabs" aria-label="Умови покупки">
      <?php foreach ($tabs as $s => $t): ?><a href="<?= e(url('/' . $s)) ?>"<?= $s === $slug ? ' aria-current="page"' : '' ?>><?= e($t) ?></a><?php endforeach; ?>
    </nav>
  <?php endif; ?>
  <div class="sv-legal-head">
    <h1 class="sv-h2" style="font-size:clamp(32px,4vw,48px)"><?= e($heading) ?></h1>
  </div>

  <div class="sv-panel sv-legal-body">
    <?php if (trim($text) === ''): ?>
      <p class="lead">Текст цієї сторінки ще не заповнено. Напишіть нам — відповімо на будь-яке питання про умови.</p>
    <?php elseif (in_array($slug, ['delivery', 'payment', 'returns'], true)): ?>
      <div class="sv-desc"<?= edit_mark($block, 'body') ?>><?= rich_text($text) ?></div>
    <?php else: ?>
      <div class="legal-text"<?= edit_mark($block, 'body') ?>><?= e($text) ?></div>
    <?php endif; ?>
  </div>

  <?php if (!empty($faq)): ?>
    <section class="sv-faq" style="margin-top:48px"<?= edit_mark('faq') ?>>
      <h2 class="sv-h2" style="font-size:30px">Часті запитання</h2>
      <?php foreach ($faq as [$q, $a]): ?>
        <details class="sv-faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <div class="legal-entity sv-legal-entity">
    <?php if (trim($entity) !== ''): ?>
      <p class="legal-entity-name"<?= edit_mark('legal_entity', 'title') ?>>Продавець: <?= e($entity) ?></p>
      <?php if (trim($entity_details) !== ''): ?>
        <p class="dim" style="white-space:pre-line;margin-top:6px"<?= edit_mark('legal_entity', 'body') ?>><?= e($entity_details) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <p class="dim" style="margin:0"<?= edit_mark('legal_entity', 'title') ?>>Реквізити продавця не заповнені.
        Їх треба вказати в адмінці: Контент сайту → «Хто продавець (реквізити)».</p>
    <?php endif; ?>
    <?php $phone = Content::title('contact_phone'); $email = Content::title('contact_email'); ?>
    <?php if ($phone !== '' || $email !== ''): ?>
      <p class="dim" style="margin-top:10px">
        <?php if ($phone !== ''): ?><a href="tel:<?= e(preg_replace('~[^\d+]~', '', $phone)) ?>"><?= e($phone) ?></a><?php endif; ?>
        <?= $phone !== '' && $email !== '' ? ' · ' : '' ?>
        <?php if ($email !== ''): ?><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
</div>
