<?php
/**
 * Підписувач ПРРО ДПС: ключ касира в цій вкладці (див. assets/js/dps-sign.js).
 *
 * @var string $mode   'kasa' — сторінка каси (тримає ключ і забирає всі документи),
 *                     'order' — картка замовлення (стан чека, «підписати тут»)
 * @var int    $parent замовлення (для 'order')
 */
$mode = $mode ?? 'order';
$v = static fn(string $f) => e(asset_v('vendor/eusign/' . $f));
?>
<div class="dps-signer admin-card" data-dps-signer data-mode="<?= e($mode) ?>" data-parent="<?= (int)($parent ?? 0) ?>"
     data-lib="<?= $v('euscp.js') ?>" data-worker="<?= $v('euscp.worker.js') ?>"
     data-cas="<?= $v('CAs.json') ?>" data-certs="<?= $v('CACertificates.p7b') ?>"
     data-proxy="<?= e(url('/fiscal/ca-proxy')) ?>"<?= $mode === 'order' ? ' hidden' : '' ?>>
  <?php if ($mode === 'kasa'): ?>
    <h2 class="h-serif" style="margin:0 0 6px">Ключ касира</h2>
    <p class="dim" style="margin:0 0 14px;font-size:13.5px">
      Як Device Manager у Вчасно: поки ця вкладка відкрита й ключ зчитано, вона сама підписує
      чеки, відкриття зміни й Z-звіти. Ключ і пароль лишаються на цьому пристрої — на сайт
      іде лише готовий підпис.
    </p>
  <?php endif; ?>

  <div class="fiscal-runner" data-dps-status hidden></div>

  <div class="dps-key" data-dps-keyform<?= $mode === 'order' ? ' hidden' : '' ?>>
    <div class="form-grid" style="margin-top:12px">
      <div class="field">
        <label>Файл ключа</label>
        <input type="file" data-dps-file accept=".dat,.pfx,.pk8,.zs2,.jks,.p12">
        <p class="field-hint">Key-6.dat, .pfx, .jks, .zs2 — з флешки на комп’ютері або з пам’яті телефона.</p>
      </div>
      <div class="field">
        <label>Пароль до ключа</label>
        <input type="password" data-dps-pass autocomplete="off" autocapitalize="off" spellcheck="false">
      </div>
      <div class="field">
        <label>Хто видав ключ (ЦСК)</label>
        <select data-dps-ca><option value="">Визначити автоматично</option></select>
        <p class="field-hint">Автоматично — довше: бібліотека по черзі питає всі ЦСК.</p>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
      <button class="btn btn-gold btn-sm" type="button" data-dps-load>🔑 Зчитати ключ</button>
    </div>
    <details style="margin-top:12px">
      <summary class="dim" style="cursor:pointer;font-size:13px">Захищений носій (токен «Алмаз», «Кристал», SecureToken…)</summary>
      <p class="dim" style="font-size:12.5px;margin:8px 0">
        Лише на комп’ютері: потрібні розширення браузера й програма ІІТ «Користувач ЦСК» —
        якщо їх немає, кнопка нижче підкаже, що встановити. Пароль — у полі вище.
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn btn-line btn-sm" type="button" data-dps-token>Шукати носії</button>
        <select data-dps-media hidden style="max-width:280px"></select>
        <button class="btn btn-gold btn-sm" type="button" data-dps-token-read hidden>Зчитати з носія</button>
      </div>
    </details>
  </div>

  <div class="dps-owner" data-dps-owner hidden>
    <span>🔑 Ключ: <b data-dps-owner-name></b></span>
    <button class="btn btn-line btn-xs" type="button" data-dps-unload>Вивантажити</button>
  </div>

  <?php if ($mode === 'kasa'): ?>
    <ul class="dps-log" data-dps-log></ul>
  <?php endif; ?>
</div>
<script src="<?= e(asset_v('js/dps-sign.js')) ?>" defer></script>
