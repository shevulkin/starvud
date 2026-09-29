<?php
$ageLabel = Ages::label($p);
$phone1 = Content::title('contact_phone');
?>
<section class="sv-product">
  <div class="container sv-page">
    <nav class="sv-crumbs" aria-label="Хлібні крихти">
      <a href="<?= e(url('/')) ?>">Головна</a><span>/</span>
      <a href="<?= e(url('/shop')) ?>">Каталог</a><span>/</span>
      <?php if ($cat): ?><a href="<?= e(shop_url($cat['slug'])) ?>"><?= e($cat['name']) ?></a><span>/</span><?php endif; ?>
      <span aria-current="page"><?= e($p['name']) ?></span>
    </nav>
    <div class="product-page sv-pp" style="--tint:<?= e(Ages::tint($p)) ?>">
      <?php /* data-lightbox вмикає перегляд на весь екран; список фото модуль
               збирає сам із того, у чого є data-full. Мініатюри лишаються
               мініатюрами (data-lb-skip) — у них своя дія, підмінити головне
               фото; збільшує клік по великому. */ ?>
      <div class="product-gallery" data-lightbox>
        <?php
        $mainPhoto = $images[0]['path'];
        /* Товар із фасовками тримає мініатюри завжди, навіть коли зараз фото
           одне: у сусідньої фасовки їх може бути три, і місце під них має
           існувати до першого кліку — інакше блок доводилось би створювати
           з JS, а це вже друга розмітка галереї. */
        $thumbsBox = count($images) > 1 || $variants;
        ?>
        <?php /* Список фото для перегляду беруть мініатюри — вони і є повним
                 переліком. Головне фото лише відкриває перегляд на тому кадрі,
                 який зараз показано (data-lb-open), інакше перше фото
                 потрапило б у список двічі. Коли фото одне, мініатюр немає —
                 тоді список складає саме воно. */ ?>
        <div class="sv-photo">
          <img id="mainPhoto" src="<?= e(asset($mainPhoto)) ?>" alt="<?= e($p['name']) ?>"
               data-lb-open<?= count($images) > 1 ? '' : ' data-full="' . e(asset($mainPhoto)) . '"' ?>
               title="Натисніть, щоб роздивитись" fetchpriority="high">        </div>
        <?php if ($thumbsBox): ?>
          <?php /* Коли фото одне, блок лишається порожнім, а не просто схованим:
                   мініатюра повторювала б головне фото, і перегляд рахував би
                   один кадр за два. */ ?>
          <div class="thumbs" id="productThumbs"<?= count($images) > 1 ? '' : ' hidden' ?>>
            <?php if (count($images) > 1): ?>
              <?php foreach ($images as $i => $img): ?>
                <button type="button" class="thumb <?= $i === 0 ? 'active' : '' ?>" data-full="<?= e(asset($img['path'])) ?>" data-lb-skip
                        aria-label="Фото <?= $i + 1 ?> з <?= count($images) ?>">
                  <img src="<?= e(asset(Images::displayThumb($img['path']))) ?>" alt="<?= e($p['name']) ?> — фото <?= $i + 1 ?>" loading="lazy">
                </button>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div>
        <?php
        // Стан кнопки до першого кліку по варіанту. Далі його переставляє JS
        // разом із ціною й наявністю — див. updateAddButton() в app.js.
        $stockNow = 0; foreach ($availability as $av) $stockNow += (int)$av['qty'];
        $outOfStock = $stockNow <= 0 && !$p['made_to_order'];
        ?>
        <div class="sv-pp-chips">
          <?php if ($ageLabel !== ''): ?><a class="sv-chip-age" href="<?= e(shop_url(null, ['age' => array_key_last(array_filter(Ages::BANDS, fn($b) => (int)$p['age_min'] >= $b[0]))])) ?>" style="background:<?= e(Ages::tint($p)) ?>">Вік: <?= e($ageLabel) ?></a><?php endif; ?>
          <?php if (!empty($p['sku'])): ?><span class="sv-chip-plain">Артикул: <?= e($p['sku']) ?></span><?php endif; ?>
        </div>
        <h1 class="sv-pp-title"><?= e($p['name']) ?></h1>
        <div class="sv-pp-status">
          <?php if ($stockNow > 0): ?><span class="sv-in"><i></i>В наявності</span>
          <?php elseif ($p['made_to_order']): ?><span class="sv-in"><i></i>Під замовлення</span>
          <?php else: ?><span class="sv-out"><i></i>Скоро буде</span><?php endif; ?>
          <?php if ($product_reviews): ?><span aria-hidden="true">·</span><a href="#reviews"><?= count($product_reviews) ?> <?= plural(count($product_reviews), 'відгук', 'відгуки', 'відгуків') ?></a><?php endif; ?>
        </div>
        <?php if (!empty($p['short_desc'])): ?><p class="lead sv-pp-lead"><?= e($p['short_desc']) ?></p><?php endif; ?>
        <form method="post" action="<?= e(url('/cart/add')) ?>" class="add-cart-form" data-product-name="<?= e($p['name']) ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="back" value="/product/<?= e($p['slug']) ?>">

          <?php if ($variants): ?>
            <div id="variantPicker" data-first="<?= (int)$variants[0]['id'] ?>">
              <?php if ($variant_axes): ?>
                <?php foreach ($variant_axes as $ax): ?>
                  <label><?= e($ax['name']) ?></label>
                  <div class="variants" data-axis="<?= (int)$ax['id'] ?>">
                    <?php foreach ($ax['values'] as $val): ?>
                      <button type="button" class="chip opt" data-axis="<?= (int)$ax['id'] ?>" data-value="<?= e($val['value']) ?>">
                        <?php if (!empty($val['color'])): ?><i class="swatch" style="background:<?= e($val['color']) ?>"></i><?php endif; ?>
                        <?= e($val['value']) ?>
                      </button>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <label>Варіант</label>
                <div class="variants">
                  <?php foreach ($variants as $i => $v): ?>
                    <button type="button" class="chip opt-plain <?= $i === 0 ? 'active' : '' ?>" data-variant="<?= (int)$v['id'] ?>">
                      <?= e($v['name']) ?>
                    </button>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <input type="hidden" name="variant_id" id="variantId" value="<?= (int)$variants[0]['id'] ?>">
              <p class="dim" id="variantNote" style="margin:10px 0 0"></p>
            </div>
          <?php endif; ?>

          <div class="sv-buy">
            <span class="price" style="font-size:30px">
              <s id="priceOld" <?= $old_price === null ? 'hidden' : '' ?>><?= $old_price !== null ? e(price_fmt($old_price)) : '' ?></s>
              <span id="priceNow"><?= e(price_label($price, (bool)$p['made_to_order'])) ?></span>
              <?php /* Ціна за 100 г під сумою. «600 грн» за мед ні про що не каже,
                       поки невідомо, скільки його в банці; порівняння з полицею
                       покупець проводить усе одно — чесніше дати цифру самим.
                       Порожня вага ховає рядок повністю: краще без нього, ніж
                       із діленням на вигадане значення. */ ?>
              <span class="price-unit" id="priceUnit"<?= $per_100g === '' ? ' hidden' : '' ?>><?= e($per_100g) ?></span>
            </span>
            <div class="qty-box">
              <button type="button" onclick="var i=this.parentNode.querySelector('input');i.value=Math.max(1,+i.value-1)">−</button>
              <input type="number" name="qty" value="1" min="1"
                     <?= $p['made_to_order'] || $stockNow <= 0 ? '' : 'max="' . $stockNow . '"' ?>>
              <button type="button" onclick="var i=this.parentNode.querySelector('input'),m=+i.max||Infinity;i.value=Math.min(m,+i.value+1)">+</button>
            </div>
            <button class="btn btn-gold sv-add" type="submit" id="addToCart" <?= $outOfStock ? 'disabled' : '' ?>>
              <?= $outOfStock ? 'Немає в наявності' : 'Додати в кошик' ?></button>
          </div>
        </form>

        <?php /* Коротко про доставку й оплату — одразу під кнопкою, де людина
                 вирішує. Три рядки, а не картки: відповідь на «скільки й коли»
                 без того, щоб відсувати опис. Подробиці — на сторінці умов. */ ?>
        <ul class="sv-perks">
          <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>
            <span>Нова Пошта, 1–3 дні · <b>безкоштовно від <?= e(price_fmt((float)Settings::get('free_shipping_from', '2500'))) ?></b></span></li>
          <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/></svg>
            <span>Оплата при отриманні або на рахунок · <a href="<?= e(url('/delivery')) ?>">умови</a></span></li>
          <?php if ($phone1 !== ''): ?>
          <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-3 2a11 11 0 005 5l2-3 5 2v4a2 2 0 01-2 2A17 17 0 013 6a2 2 0 012-2z"/></svg>
            <span>Підкажемо з вибором: <a href="tel:<?= e(preg_replace('~[^\d+]~', '', $phone1)) ?>"><?= e($phone1) ?></a></span></li>
          <?php endif; ?>
          <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12a8 8 0 1 0 3-6.2"/><path d="M4 4v4h4"/></svg>
            <span>14 днів на обмін і повернення · <a href="<?= e(url('/returns')) ?>">як повернути</a></span></li>
        </ul>

        <?php
        /* Попередження про безпеку — до покупки, а не лише на коробці:
           техрегламент безпеки іграшок (як і директива ЄС 2009/48) вимагає,
           щоб при продажу онлайн покупець бачив їх до оформлення. */
        $warnings = [];
        if ((int)($p['age_min'] ?? 0) >= 36) {
            $warnings[] = 'Не підходить для дітей до 3 років.';
        }
        if (preg_match('~кульк|кульок|повітрян~ui', (string)$p['name'])) {
            $warnings[] = 'Діти до 8 років можуть задихнутися ненадутими або лопнутими кульками. '
                . 'Надувати й гратися лише під наглядом дорослих; залишки кульок одразу прибирайте.';
        }
        ?>
        <?php if ($warnings): ?>
          <div class="sv-warn" role="note">
            <b>Увага!</b>
            <?php foreach ($warnings as $w): ?><p><?= e($w) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php
        /* Оптова шкала. Показуємо саме ціну за штуку, а не самі відсотки:
           «−7%» треба перемножити в голові, «186 грн/шт» — ні, і рішення взяти
           більше приймається на місці. Знижка, про яку покупець дізнається аж
           у кошику, працює вдвічі гірше — тому вона стоїть тут, поруч із
           полем кількості, а не десь у описі.

           Ціна тут базова, без урахування магазину: на сторінці товару ще
           невідомо, з якої точки поїде замовлення. */
        $tiers = $price !== null ? Catalog::qtyTiers($p) : [];
        $capPct = Catalog::discountCap($p, $variants[0] ?? null);
        // Ті самі відлік і стеля, що в Cart::detailed. Інакше картка обіцяла б
        // одне, а кошик рахував інше — і винним виглядав би кошик.
        $tierBase = ($old_price !== null && (float)$old_price > 0) ? (float)$old_price : (float)$price;
        $tierOwn = $tierBase > 0 ? ($tierBase - (float)$price) / $tierBase * 100 : 0.0;
        ?>
        <?php if ($tiers): ?>
          <div class="qty-tiers-box" id="qtyTiers">
            <h3>Дешевше за кількість</h3>
            <table class="qty-tiers-list">
              <?php foreach ($tiers as $t):
                $pct = max(0.0, min((float)$t['percent'], $capPct - $tierOwn));
                if ($pct <= 0) continue;
                $each = round((float)$price - $tierBase * $pct / 100, 2);
              ?>
                <tr>
                  <td>від <?= (int)$t['min_qty'] ?> шт</td>
                  <td class="qty-tiers-each"><?= e(price_fmt($each)) ?>/шт</td>
                </tr>
              <?php endforeach; ?>
            </table>
            <?php if (Catalog::qtyScope($p) === 'product' && $variants): ?>
              <p class="dim">Рахуються всі фасовки разом — можна змішувати.</p>
            <?php elseif ($variants): ?>
              <p class="dim">Рахується кожна фасовка окремо.</p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php
        /* Торг.
         *
         * Стоїть одразу під оптовою шкалою — і це найважливіше в його
         * розташуванні. Шкала щойно відповіла на питання «а дешевше буде?»
         * готовими ярусами; людина, яка дочитала до кінця й не знайшла свого
         * випадку («мені треба сім, а поріг від десяти», «беру на весь
         * колектив, але за вашою ціною не складається»), у цю секунду або
         * пише в дірект, або закриває вкладку. Форма стоїть саме там, де
         * виникає це питання.
         *
         * Ціну просимо за штуку, але приймаємо й суму за партію: «маю 5 000 на
         * подарунки» — це те, як людина насправді думає, і змушувати її ділити
         * в голові означає втратити частину пропозицій на арифметиці.
         *
         * Підлогу («нижче за стільки не розглядаємо») не показуємо навмисно.
         * Назви ми число — і кожна наступна пропозиція була б рівно цим
         * числом; торгу не лишилось би, лишилась би ще одна знижка, яку
         * магазин роздає сам собі.
         */
        $offerState = $offer_states[$variants ? (int)$variants[0]['id'] : 0] ?? ['state' => 'none'];
        ?>
        <?php
        /*
         * Торг згорнутий, доки його не попросили.
         *
         * Розгорнута форма з полями «скільки штук», «ваша ціна» й «рахувати за
         * всю партію» стояла під кожним товаром — і під банкою за 150 грн теж.
         * Півекрана, віддані запрошенню поторгуватись, кажуть про товар більше,
         * ніж будь-який опис: що ціна умовна й що тут прийнято торгуватись.
         * Для магазину, який продає свій мед за свою ціну, це не та розмова.
         *
         * Тепер це один тихий рядок. Хто хотів поторгуватись — натисне; решта
         * побачить ціну й кнопку, а не базар.
         *
         * Розгорнутим блок лишається в одному випадку: розмова вже йде. Тоді
         * там не запрошення, а стан — «ваша пропозиція в продавця» чи
         * «продавець відповів», — і ховати його за клацанням означало б
         * заховати саме те, чого людина чекає. Мовчання після надісланої
         * пропозиції тривожить, а непомічена відповідь дорівнює відмові.
         */
        $offerBusy = ($offerState['state'] ?? 'none') !== 'none';
        ?>
        <?php if (!empty($offer_allowed)): ?>
          <details class="offer-box" id="offerBox"<?= $offerBusy ? ' open' : '' ?>>
            <summary class="offer-toggle">
              <?= $offerBusy ? 'Ваша пропозиція ціни' : 'Запропонувати свою ціну' ?>
            </summary>

            <?php if (!$auth_user): ?>
              <p class="dim" style="margin:0">
                Скажіть, скільки штук берете і за скільки готові — продавець відповість особисто
                <b><?= e(Offers::replyPromise()) ?></b>: погодиться, запропонує свої умови
                або пояснить, чому цього разу не вийде.
              </p>
              <p style="margin:12px 0 0">
                <a class="btn btn-line btn-sm" href="<?= e(url('/profile')) ?>"
                   onclick="var b=document.getElementById('loginBtn');if(b){b.click();return false}">Увійти, щоб запропонувати ціну</a>
              </p>
              <p class="dim" style="margin:10px 0 0;font-size:13px">
                Вхід потрібен для одного: щоб відповідь продавця мала куди прийти.
              </p>
            <?php else: ?>
              <?php /* Стан розмови й форма — два різні екрани одного блоку.
                       Показується завжди рівно один: пропонувати ціну, коли
                       твоя пропозиція вже лежить у продавця, немає сенсу. */ ?>
              <div id="offerState"<?= $offerState['state'] === 'none' ? ' hidden' : '' ?>>
                <p class="offer-state-line" id="offerStateText"></p>
                <div class="offer-state-actions" id="offerStateActions"></div>
              </div>

              <form method="post" action="<?= e(url('/bargain/new')) ?>" id="offerForm"
                    <?= $offerState['state'] === 'none' ? '' : 'hidden' ?>>
                <?= Csrf::field() ?>
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="variant_id" id="offerVariant" value="<?= $variants ? (int)$variants[0]['id'] : '' ?>">
                <input type="hidden" name="back" value="/product/<?= e($p['slug']) ?>">
                <?php /* Строк відповіді сказаний вголос до того, як людина
                         натиснула кнопку. Мовчання після відправленої
                         пропозиції тривожить саме тим, що незрозуміло, скільки
                         чекати, — а обіцянка знімає це питання ще до того, як
                         воно виникне. Те саме число будить продавця
                         нагадуванням (offers:remind), тож обіцянка не може
                         розійтися з дійсністю непоміченою. */ ?>
                <p class="dim" style="margin:0 0 14px">
                  Продавець відповість особисто <b><?= e(Offers::replyPromise()) ?></b>:
                  погодиться, запропонує свої умови або пояснить, чому цього разу не вийде.
                  Погоджена ціна закріплюється за вами на <?= (int)Offers::holdHours() ?> год.
                </p>
                <div class="offer-fields">
                  <label class="offer-field">
                    <span>Скільки штук</span>
                    <input type="number" name="qty" min="1" max="<?= (int)Cart::MAX_QTY ?>" value="1" required>
                  </label>
                  <label class="offer-field">
                    <span id="offerPriceLabel">Ваша ціна за штуку, грн</span>
                    <input type="text" name="price" inputmode="decimal" id="offerPrice"
                           placeholder="<?= $price !== null ? e(num_val(round((float)$price * 0.9))) : '' ?>" required>
                  </label>
                  <label class="offer-mode">
                    <input type="checkbox" name="mode" value="total" id="offerMode">
                    <span>рахувати за всю партію</span>
                  </label>
                </div>
                <label class="offer-field" style="margin-top:12px">
                  <span>Кілька слів (не обовʼязково)</span>
                  <input type="text" name="note" maxlength="500"
                         placeholder="напр.: беру щомісяця, або на подарунки колективу">
                </label>
                <p class="dim" style="margin:10px 0 0;font-size:13px">
                  Коментар читає жива людина, і він важить не менше за цифру:
                  «беру щомісяця» й «хочу дешевше» — це різні розмови.
                </p>
                <button class="btn btn-gold btn-sm" type="submit" style="margin-top:14px">Запропонувати ціну</button>
              </form>

              <?php /* Форми дій зі станом лежать поруч, поза блоком стану: у
                       них свої адреси, і збирати їх у JS означало б розкидати
                       CSRF-токен по скриптах. Показує їх той-таки JS. */ ?>
              <div hidden>
                <form method="post" action="<?= e(url('/bargain/accept')) ?>" id="offerAcceptForm">
                  <?= Csrf::field() ?><input type="hidden" name="offer_id" id="offerAcceptId">
                  <input type="hidden" name="back" value="/product/<?= e($p['slug']) ?>">
                </form>
                <form method="post" action="<?= e(url('/bargain/cancel')) ?>" id="offerCancelForm">
                  <?= Csrf::field() ?><input type="hidden" name="offer_id" id="offerCancelId">
                  <input type="hidden" name="back" value="/product/<?= e($p['slug']) ?>">
                </form>
                <form method="post" action="<?= e(url('/cart/add-offer')) ?>" id="offerCartForm">
                  <?= Csrf::field() ?><input type="hidden" name="offer_id" id="offerCartId">
                  <input type="hidden" name="back" value="/product/<?= e($p['slug']) ?>">
                </form>
              </div>
              <script>
                window.BOFU_OFFERS = <?= json_js($offer_states) ?>;
                window.BOFU_OFFER_FIRST = <?= $variants ? (int)$variants[0]['id'] : 0 ?>;
              </script>
            <?php endif; ?>
          </details>
        <?php endif; ?>

        <?php /* «Разом дешевше». Стоїть тут, а не в кошику: у кошику покупець
                 уже вирішив, що бере, і пропозиція читається як спроба
                 дописати щось у чек. Поруч із товаром вона відповідає на
                 питання, яке людина ставить собі сама, — «а що до цього
                 беруть».

                 Показуємо не відсоток, а обидві суми: «490 замість 545» не
                 треба перемножувати в голові, а різниця між ними і є вся
                 відповідь. */ ?>
        <?php foreach ($bundles ?? [] as $bd): ?>
          <div class="bundle-box">
            <h3>Разом дешевше · <?= e($bd['title']) ?></h3>
            <ul class="bundle-items">
              <?php foreach ($bd['expanded'] as $it): ?>
                <li<?= (int)$it['product']['id'] === (int)$p['id'] ? ' class="is-this"' : '' ?>>
                  <img src="<?= e(asset(Images::displayThumb($it['photo']))) ?>" alt="" loading="lazy">
                  <span>
                    <?= e($it['product']['name']) ?><?= $it['variant'] ? ', ' . e($it['variant']['name']) : '' ?>
                    <?php if ($it['qty'] > 1): ?> <b>× <?= (int)$it['qty'] ?></b><?php endif; ?>
                    <?php if ((int)$it['product']['id'] === (int)$p['id']): ?>
                      <i class="bundle-this">цей товар</i>
                    <?php endif; ?>
                  </span>
                  <em><?= e(price_fmt($it['price'] * $it['qty'])) ?></em>
                </li>
              <?php endforeach; ?>
            </ul>
            <div class="bundle-foot">
              <div class="bundle-price">
                <s><?= e(price_fmt($bd['sum'])) ?></s>
                <b><?= e(price_fmt($bd['total'])) ?></b>
                <span>вигода <?= e(price_fmt($bd['cut'])) ?></span>
              </div>
              <form method="post" action="<?= e(url('/cart/add-bundle')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="bundle_id" value="<?= (int)$bd['id'] ?>">
                <input type="hidden" name="back" value="/product/<?= e($p['slug']) ?>">
                <button class="btn btn-gold btn-sm" type="submit">Додати набір</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>

        <?php $lowStock = ($p['low_stock_threshold'] ?? null) !== null && $p['low_stock_threshold'] !== '' ? (int)$p['low_stock_threshold'] : null; ?>
        <?php $anyStock = false; foreach ($availability as $av) $anyStock = $anyStock || $av['qty'] > 0; ?>

        <?php /* Товар «під замовлення», якого зараз ніде немає, — це не відмова,
                 а строк виготовлення. Раніше блок починався з переліку точок, і
                 покупець читав спершу два рядки «немає», а вже потім дізнавався,
                 що товар буде. Тепер спершу відповідь, а вже під нею — деталі
                 по точках. Коли товар є в наявності, порядок звичайний. */ ?>
        <?php if ($p['made_to_order']): ?>
          <p class="mto-lead" id="madeToOrderLead"<?= $anyStock ? ' style="display:none"' : '' ?>><?= e(Catalog::madeToOrderNote($p)) ?></p>
        <?php endif; ?>

        <?php
        /*
         * Розкладка по точках згорнута — з тієї ж причини, що й торг.
         *
         * Головну відповідь («є / немає / буде під замовлення») людина вже
         * отримала кнопкою «До кошика» й рядком про виготовлення. Список точок
         * відповідає на друге питання — «а де саме забрати», — і його ставлять
         * лише ті, хто збирається їхати. Розгорнутий перелік магазинів під
         * кожним товаром відсуває характеристики й опис заради довідки, яка
         * більшості не потрібна.
         *
         * Виняток той самий за духом: коли товару немає НІДЕ, список лишається
         * розкритим. Тоді це вже не довідка, а пояснення, чому кнопка не
         * працює, і ховати його за клацанням означало б відповісти «немає» без
         * жодної підстави.
         */
        ?>
        <?php /* Точка одна — розкладка «де є» нічого не додає до «В наявності»
                 угорі. Блок лишається в розмітці прихованим: за ним app.js
                 перемикає наявність варіантів. */ ?>
        <details class="avail-box" id="availBox"<?= $anyStock ? '' : ' open' ?><?= count($availability) <= 1 ? ' hidden' : '' ?>>
          <summary class="avail-toggle" id="availToggle">Подивитися наявність у магазинах</summary>
        <div class="availability" id="availability">
          <?php if ($variants): ?><p class="dim" style="margin:0 0 8px">Наявність показана для обраного варіанта</p><?php endif; ?>
          <?php foreach ($availability as $av): $sid = (int)$av['store']['id']; ?>
            <span class="<?= $av['qty'] > 0 ? 'yes' : 'no' ?>" data-store="<?= $sid ?>"
                  data-stock="<?= e(json_encode($av['by_variant'], JSON_UNESCAPED_UNICODE)) ?>"
                  data-label="<?= e($av['store']['name'] . ($av['store']['city'] ? ' (' . $av['store']['city'] . ')' : '')) ?>">
              <span class="av-text"><?= e($av['store']['name'] . ($av['store']['city'] ? ' (' . $av['store']['city'] . ')' : '')) ?>:
              <?= $av['qty'] > 0 ? ($lowStock !== null && $av['qty'] <= $lowStock ? 'закінчується' : 'в наявності') : 'немає' ?>
              <?php if ($av['price'] !== null && $av['price'] != $price): ?> · ціна тут: <?= e(price_fmt($av['price'])) ?><?php endif; ?></span>
            </span>
          <?php endforeach; ?>
        </div>
        </details>

        <?php /* Немає ніде — єдине місце, де людині нема що робити з цією
                 сторінкою. Даємо їй причину не йти назовсім. */ ?>
        <div id="watchBox" style="margin-top:14px<?= $anyStock ? ';display:none' : '' ?>">
          <?php if ($watching): ?>
            <p class="dim" id="watchDone" style="margin:0">🔔 Ви в черзі — напишемо, щойно зʼявиться.
              Куди саме писати — <a href="<?= e(url('/profile')) ?>">у кабінеті</a>.</p>
          <?php else: ?>
            <form method="post" action="<?= e(url('/stock/watch')) ?>" style="margin:0">
              <?= Csrf::field() ?>
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="variant_id" id="watchVariant" value="<?= $variants ? (int)$variants[0]['id'] : '' ?>">
              <input type="hidden" name="back" value="<?= e(request_path()) ?>">
              <button class="btn btn-line btn-sm" type="submit">🔔 Повідомити, коли зʼявиться</button>
            </form>
          <?php endif; ?>
        </div>

        <?php $prodBrands = Catalog::brandsOf($p); ?>
        <?php if ($attrs || $prodBrands || $weight_fmt !== ''): ?>
          <h2 style="font-size:22px;margin-top:34px">Характеристики</h2>
          <table class="specs">
            <?php /* Бренд першим рядком: «чий це товар» — питання, яке виникає
                     раніше за вагу й обʼєм, надто коли позиція не наша.
                     Посиланням — щоб з нього можна було піти до решти товарів
                     цього виробника, як і з будь-якої іншої характеристики. */ ?>
            <?php if ($prodBrands): ?>
              <tr>
                <td><?= count($prodBrands) > 1 ? 'Бренди' : 'Бренд' ?></td>
                <td><?php foreach ($prodBrands as $i => $b): ?><?= $i ? ', ' : '' ?><a
                      class="brand-link" href="<?= e(url('/shop?brand=' . $b['slug'])) ?>"
                      title="Показати всі товари цього бренду"><?php
                        if (!empty($b['logo'])): ?><img src="<?= e(asset(Images::displayThumb($b['logo']))) ?>"
                             alt="" loading="lazy"><?php endif; ?><?= e($b['name']) ?></a><?php endforeach; ?></td>
              </tr>
            <?php endif; ?>
            <?php /* Вага одразу після бренду: «скільки тут меду» — друге питання
                     після «чий він», і воно вирішує, дорого це чи ні. Вагу вже
                     збирали для накладної Нової Пошти, покупцю її просто не
                     показували. Порожня — рядка немає. */ ?>
            <?php if ($weight_fmt !== ''): ?>
              <tr id="specWeightRow"><td>Вага</td><td id="specWeight"><?= e($weight_fmt) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($attrs as $a): ?>
              <tr>
                <td><?= e($a['name']) ?><?= !empty($a['unit']) ? ', ' . e($a['unit']) : '' ?></td>
                <td>
                  <?php if (!empty($a['color'])): ?><i class="swatch" style="background:<?= e($a['color']) ?>"></i> <?php endif; ?>
                  <?php if (!empty($a['filterable']) && !empty($a['attr_slug'])): ?>
                    <a href="<?= e(shop_url($cat['slug'] ?? null, [
                        'attr' => [$a['attr_slug'] => [$a['value']]],
                      ])) ?>" title="Показати всі товари з таким значенням"><?= e($a['value']) ?></a>
                  <?php else: ?><?= e($a['value']) ?><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>

        <?php if (!empty($p['description']) && $p['description'] !== ($p['short_desc'] ?? '')): ?>
          <h2 style="font-size:22px;margin-top:30px">Опис</h2>
          <div class="sv-desc"><?= rich_text((string)$p['description']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($product_reviews): ?>
      <section class="sv-pp-reviews" id="reviews">
        <h2 class="sv-h2" style="font-size:32px">Що кажуть покупці</h2>
        <div class="sv-rev-list">
          <?php foreach ($product_reviews as $r) echo View::partial('partials/review', ['r' => $r]); ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($thumbsBox): ?>
      <script>
        (function () {
          var box = document.getElementById('productThumbs'), main = document.getElementById('mainPhoto');
          box.addEventListener('click', function (e) {
            var t = e.target.closest('.thumb');
            if (!t) return;
            main.src = t.dataset.full;
            box.querySelectorAll('.thumb').forEach(function (b) { b.classList.toggle('active', b === t); });
          });
        })();
      </script>
    <?php endif; ?>

    <?php if ($variants): ?>
      <script>
        window.BOFU_VARIANTS = <?= json_js($variant_data) ?>;
        window.BOFU_MADE_TO_ORDER = <?= $p['made_to_order'] ? 'true' : 'false' ?>;
        window.BOFU_MTO_NOTE = <?= json_js(Catalog::madeToOrderShort($p)) ?>;
        window.BOFU_LOW_STOCK_THRESHOLD = <?= $lowStock !== null ? $lowStock : 'null' ?>;
      </script>
    <?php endif; ?>

    <?php /* Закріплена кнопка на телефоні: після опису й характеристик до
             кнопки «Додати в кошик» — кілька екранів гортання назад. Панель
             зʼявляється, лише коли основна кнопка пішла з екрана, і натискає
             саме її — тобто з тим самим варіантом і кількістю. */ ?>
    <?php if (!$outOfStock): ?>
      <div class="sv-sticky-buy" id="stickyBuy" aria-hidden="true">
        <div class="sv-sticky-info">
          <span class="sv-sticky-name"><?= e($p['name']) ?></span>
          <b id="stickyPrice"><?= e(price_label($price, (bool)$p['made_to_order'])) ?></b>
        </div>
        <button type="button" class="btn btn-gold" tabindex="-1"
                onclick="document.getElementById('addToCart').click()">У кошик</button>
      </div>
      <script>
        (function () {
          var bar = document.getElementById('stickyBuy'), btn = document.getElementById('addToCart');
          var now = document.getElementById('priceNow'), sp = document.getElementById('stickyPrice');
          if (!bar || !btn || !('IntersectionObserver' in window)) return;
          new IntersectionObserver(function (e) {
            var hide = e[0].isIntersecting || e[0].boundingClientRect.top > 0 || btn.disabled;
            bar.classList.toggle('is-on', !hide);
            if (now && sp) sp.textContent = now.textContent;
          }).observe(btn);
        })();
      </script>
    <?php endif; ?>

    <?php if ($related): ?>
      <div class="sv-sec-row" style="margin:72px 0 28px"><div><span class="sv-kicker">З тієї ж полиці</span><h2 class="sv-h2" style="font-size:32px">Схожі іграшки</h2></div></div>
      <div class="sv-grid">
        <?php foreach ($related as $prod): ?>
          <?= View::partial('partials/product_card', ['prod' => $prod]) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
