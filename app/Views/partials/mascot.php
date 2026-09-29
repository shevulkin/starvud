<?php
/**
 * Зірочка — персонаж «Старвуд-М».
 *
 * Малюнок живе в розмітці, а не окремим файлом: пози відрізняються кількома
 * деталями (лупа, ростомір, печатка), і вісім SVG-файлів тримали б вісім
 * копій того самого тіла. До того ж inline-SVG анімується без скриптів і не
 * коштує окремого запиту.
 *
 * @var string      $pose  wave|lens|measure|idea|strong|stamp|shrug|sleep
 * @var int         $size  сторона квадрата, px
 * @var bool|null   $still не гойдатись (у списках, де персонажів кілька)
 * @var string|null $class додатковий клас обгортки
 */
$pose = $pose ?? 'wave';
$size = (int)($size ?? 160);
$bob = !empty($still) ? '0' : '-5';
$awake = $pose !== 'sleep';
?>
<span class="mascot<?= !empty($class) ? ' ' . e($class) : '' ?>" style="width:<?= $size ?>px;height:<?= $size ?>px" aria-hidden="true">
<svg width="100%" height="100%" viewBox="-20 -24 200 190" overflow="visible" focusable="false">
<ellipse cx="80" cy="152" rx="46" ry="6" fill="#1F2544" opacity="0.14"/>
<?php if ($pose === 'measure'): ?>
<g transform="rotate(-62 19 68)"><rect x="19" y="58" width="96" height="18" rx="3" fill="#FFD35C" stroke="#1F2544" stroke-width="3.5"/><path d="M31 58v8M43 58v5M55 58v8M67 58v5M79 58v8M91 58v5M103 58v8" stroke="#1F2544" stroke-width="2.5" stroke-linecap="round"/></g>
<?php endif; ?>
<g>
<animateTransform attributeName="transform" type="translate" values="0 0;0 <?= $bob ?>;0 0" dur="2.8s" repeatCount="indefinite"/>
<path d="M80 24 L98.2 62.9 L140.9 68.2 L109.5 97.6 L117.6 139.8 L80 119 L42.4 139.8 L50.5 97.6 L19.1 68.2 L61.8 62.9Z" fill="#E0482B" stroke="#1F2544" stroke-width="6" stroke-linejoin="round"/>
<path d="M76 36 L66 58 M30 70 L52 72" stroke="#FF8F73" stroke-width="5" stroke-linecap="round" fill="none"/>
<path d="M110 104 L114 128" stroke="#B53A21" stroke-width="5" stroke-linecap="round" fill="none"/>
<ellipse cx="57" cy="99" rx="8" ry="4.5" fill="#FFB59E"/><ellipse cx="103" cy="99" rx="8" ry="4.5" fill="#FFB59E"/>
<?php if ($awake): ?>
<ellipse cx="68" cy="85" rx="6.5" ry="8.5" fill="#1F2544"/><ellipse cx="92" cy="85" rx="6.5" ry="8.5" fill="#1F2544"/>
<circle cx="70.5" cy="81.5" r="2.4" fill="#fff"/><circle cx="94.5" cy="81.5" r="2.4" fill="#fff"/>
<path d="M71 99 Q80 108 89 99" fill="none" stroke="#1F2544" stroke-width="4" stroke-linecap="round"/>
<?php else: ?>
<path d="M61 86 Q68 92 75 86 M85 86 Q92 92 99 86" fill="none" stroke="#1F2544" stroke-width="4" stroke-linecap="round"/>
<ellipse cx="80" cy="102" rx="4" ry="3" fill="#1F2544"/>
<path d="M58 50 L102 50 L118 4 Z" fill="#9CC8FF" stroke="#1F2544" stroke-width="4" stroke-linejoin="round"/>
<path d="M60 49 Q80 58 100 49" fill="none" stroke="#FFF7EC" stroke-width="6" stroke-linecap="round"/>
<circle cx="118" cy="4" r="8" fill="#FFF7EC" stroke="#1F2544" stroke-width="3"/>
<text x="128" y="36" font-family="Unbounded, sans-serif" font-weight="800" font-size="20" fill="#FFC93C">z</text>
<text x="144" y="18" font-family="Unbounded, sans-serif" font-weight="800" font-size="14" fill="#FFC93C">z</text>
<?php endif; ?>
<?php if ($pose === 'lens'): ?>
<path d="M137 66 L147 54" stroke="#C98B5B" stroke-width="8" stroke-linecap="round"/>
<circle cx="155" cy="40" r="17" fill="#CFE3FF" fill-opacity="0.75" stroke="#1F2544" stroke-width="5"/>
<path d="M146 34 Q150 28 157 28" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round"/>
<?php elseif ($pose === 'idea'): ?>
<circle cx="80" cy="-4" r="13" fill="#FFD35C" stroke="#1F2544" stroke-width="3.5"/>
<rect x="73" y="8" width="14" height="8" rx="2" fill="#C9CCE0" stroke="#1F2544" stroke-width="3"/>
<path d="M76 -6 Q80 0 84 -6" fill="none" stroke="#1F2544" stroke-width="2.5" stroke-linecap="round"/>
<path d="M58 -14 L52 -18 M102 -14 L108 -18 M80 -24 L80 -30 M62 4 L55 6 M98 4 L105 6" stroke="#FFC93C" stroke-width="4" stroke-linecap="round"/>
<?php elseif ($pose === 'strong'): ?>
<path d="M141 68 Q162 84 180 70" fill="none" stroke="#C98B5B" stroke-width="5" stroke-linecap="round"/>
<path d="M56 70 L68 74 M104 70 L92 74" stroke="#1F2544" stroke-width="4" stroke-linecap="round"/>
<path d="M126 36 Q132 44 126 50 Q120 44 126 36Z" fill="#9CC8FF" stroke="#1F2544" stroke-width="2.5"/>
<?php elseif ($pose === 'stamp'): ?>
<rect x="136" y="26" width="16" height="28" rx="7" fill="#C98B5B" stroke="#1F2544" stroke-width="3.5"/>
<rect x="128" y="52" width="32" height="12" rx="3" fill="#1F2544"/>
<path d="M126 70 L162 70" stroke="#E0482B" stroke-width="4" stroke-linecap="round" stroke-dasharray="2 6"/>
<?php elseif ($pose === 'shrug'): ?>
<text x="-4" y="46" font-family="Unbounded, sans-serif" font-weight="800" font-size="30" fill="#1F2544">?</text>
<text x="146" y="36" font-family="Unbounded, sans-serif" font-weight="800" font-size="24" fill="#E0482B">?</text>
<?php elseif ($pose === 'wave'): ?>
<path d="M4 48 Q-2 58 2 68 M-8 40 Q-16 56 -10 72" fill="none" stroke="#1F2544" stroke-width="3.5" stroke-linecap="round"/>
<path d="M150 40 L156 30 M160 52 L170 48" stroke="#FFC93C" stroke-width="4" stroke-linecap="round"/>
<?php endif; ?>
</g>
</svg>
</span>
