<?php
/**
 * Панель режиму редагування. Підключається лише коли режим увімкнено, тож
 * покупець цього коду не отримує взагалі.
 *
 * Смужка внизу — постійна: у режимі, з якого не видно, що ти в ньому, люди
 * лишаються назавжди й потім не розуміють, чому сайт «якось дивно обведений».
 */
?>
<div class="ce-bar" id="ceBar">
  <span class="ce-bar-dot" aria-hidden="true"></span>
  <b class="ce-bar-title">Режим редагування</b>
  <?php /* Яку саме сторінку ви правите. Блоки з однаковими назвами є на кількох
           сторінках (галерея, курс, підвал), і без цього легко правити текст,
           дивлячись зовсім не на ту сторінку, де його потім шукатимеш. */ ?>
  <span class="ce-bar-page">
    <span class="ce-bar-page-name"><?= e(EditMode::pageName()) ?></span>
    <span class="ce-bar-path"><?= e(EditMode::path()) ?></span>
  </span>
  <?php /* Підказка йде за станом перемикача: зі схованими значками порада
           «шукайте олівець» відправляла б шукати те, чого зараз немає. */ ?>
  <span class="ce-bar-hint">
    <span class="ce-hint-on">Натисніть на блок зі значком <span class="ce-pin ce-pin-inline" aria-hidden="true">✎</span>, щоб змінити текст або фото</span>
    <span class="ce-hint-off">Натисніть на блок, щоб змінити текст або фото — значки сховані перемикачем</span>
  </span>
  <div class="ce-bar-tools">
    <label class="ce-bar-check">
      <input type="checkbox" id="ceHighlight" checked>
      <span>Показати всі блоки</span>
    </label>
    <div class="ce-bar-list">
      <button type="button" class="btn btn-line btn-xs" id="ceListBtn" aria-expanded="false">
        Блоки на сторінці (<span id="ceListCount">0</span>)
      </button>
      <div class="ce-bar-menu" id="ceListMenu" hidden></div>
    </div>
    <a class="btn btn-line btn-xs" href="<?= e(url('/admin/content')) ?>">Усі тексти списком</a>
    <form method="post" action="<?= e(url('/edit/off')) ?>"><?= Csrf::field() ?>
      <input type="hidden" name="back" value="<?= e(request_path()) ?>">
      <button class="btn btn-gold btn-xs" type="submit">Вийти з режиму</button>
    </form>
  </div>
</div>

<div class="ce-panel" id="cePanel" hidden aria-hidden="true">
  <div class="ce-panel-head">
    <div>
      <div class="ce-panel-label" id="cePanelLabel"></div>
      <div class="ce-panel-where dim" id="cePanelWhere"></div>
    </div>
    <button type="button" class="ce-panel-close" id="ceClose" aria-label="Закрити">×</button>
  </div>
  <div class="ce-panel-body" id="cePanelBody"></div>
  <div class="ce-panel-foot">
    <span class="ce-panel-note dim" id="cePanelNote"></span>
    <button type="button" class="btn btn-line btn-sm" id="ceCancel">Скасувати</button>
    <button type="button" class="btn btn-gold btn-sm" id="ceSave">Зберегти</button>
  </div>
</div>

<?php if (EditMode::canEditImages()): ?>
  <?= View::partial('partials/media_picker') ?>
<?php endif; ?>
<script>
window.BOFU_EDIT = {
  base: '<?= e(url('/')) ?>',
  csrf: '<?= e(Csrf::token()) ?>',
  canImages: <?= EditMode::canEditImages() ? 'true' : 'false' ?>
};
</script>
<script src="<?= e(asset_v('js/edit.js')) ?>" defer></script>
