<?php
/**
 * Намальовані іграшки — декор вітрини: полиці, порожні стани, заглушка фото.
 * Кубики тут не випадкові: це ті самі три кубики, з яких складено логотип.
 *
 * @var string $kind ball|cubes|car|bear|pyramid|duck
 * @var int    $size сторона квадрата, px
 */
$kind = $kind ?? 'bear';
$size = (int)($size ?? 160);
?>
<span class="toy<?= !empty($class) ? ' ' . e($class) : '' ?>" style="width:<?= $size ?>px;height:<?= $size ?>px" aria-hidden="true">
<svg width="100%" height="100%" viewBox="0 0 160 160" overflow="visible" focusable="false">
<ellipse cx="80" cy="152" rx="50" ry="6" fill="#1F2544" opacity="0.14"/>
<?php if ($kind === 'ball'): ?>
<circle cx="80" cy="84" r="60" fill="#E0482B"/>
<path d="M22 70 Q80 38 138 70" fill="none" stroke="#FFC93C" stroke-width="16"/>
<path d="M24 106 Q80 136 136 106" fill="none" stroke="#9CC8FF" stroke-width="16"/>
<path d="M80 24 Q60 84 80 144" fill="none" stroke="#FFF7EC" stroke-width="10"/>
<path d="M134 110 A60 60 0 0 1 56 139 Q116 130 134 110Z" fill="#1F2544" opacity="0.16"/>
<circle cx="80" cy="84" r="60" fill="none" stroke="#1F2544" stroke-width="5"/>
<ellipse cx="54" cy="52" rx="14" ry="8" fill="#fff" opacity="0.7" transform="rotate(-35 54 52)"/>
<circle cx="72" cy="42" r="3.5" fill="#fff" opacity="0.8"/>
<?php elseif ($kind === 'cubes'): ?>
<path d="M20 86 L30 76 L78 76 L68 86Z" fill="#FFE59A" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<path d="M68 86 L78 76 L78 124 L68 134Z" fill="#D9A92E" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<rect x="20" y="86" width="48" height="48" fill="#FFD35C" stroke="#1F2544" stroke-width="3.5"/>
<text x="44" y="120" text-anchor="middle" font-family="Unbounded, sans-serif" font-weight="800" font-size="26" fill="#1F2544">А</text>
<path d="M80 86 L90 76 L138 76 L128 86Z" fill="#CFE3FF" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<path d="M128 86 L138 76 L138 124 L128 134Z" fill="#6E9FDB" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<rect x="80" y="86" width="48" height="48" fill="#9CC8FF" stroke="#1F2544" stroke-width="3.5"/>
<text x="104" y="120" text-anchor="middle" font-family="Unbounded, sans-serif" font-weight="800" font-size="26" fill="#1F2544">Б</text>
<g transform="rotate(-7 74 60)">
<path d="M50 38 L60 28 L108 28 L98 38Z" fill="#FF8F73" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<path d="M98 38 L108 28 L108 76 L98 86Z" fill="#B53A21" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<rect x="50" y="38" width="48" height="48" fill="#E0482B" stroke="#1F2544" stroke-width="3.5"/>
<text x="74" y="72" text-anchor="middle" font-family="Unbounded, sans-serif" font-weight="800" font-size="26" fill="#FFF7EC">В</text>
</g>
<?php elseif ($kind === 'car'): ?>
<path d="M12 110 L12 90 Q12 76 28 74 L44 72 L60 46 Q64 40 72 40 L104 40 Q112 40 116 48 L130 72 L138 74 Q150 78 150 92 L150 110 Z" fill="#E0482B" stroke="#1F2544" stroke-width="4.5" stroke-linejoin="round"/>
<path d="M66 48 L82 48 L82 70 L52 70 Z" fill="#CFE3FF" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<path d="M90 48 L106 48 L120 70 L90 70 Z" fill="#CFE3FF" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<path d="M86 72 L86 104 M94 82 L102 82" stroke="#1F2544" stroke-width="3" stroke-linecap="round"/>
<path d="M16 92 L148 92" stroke="#FFC93C" stroke-width="6"/>
<circle cx="143" cy="84" r="5" fill="#FFF4C2" stroke="#1F2544" stroke-width="2.5"/>
<circle cx="44" cy="112" r="18" fill="#1F2544"/><circle cx="44" cy="112" r="8" fill="#C9CCE0" stroke="#FFF7EC" stroke-width="2"/>
<circle cx="118" cy="112" r="18" fill="#1F2544"/><circle cx="118" cy="112" r="8" fill="#C9CCE0" stroke="#FFF7EC" stroke-width="2"/>
<?php elseif ($kind === 'pyramid'): ?>
<rect x="18" y="134" width="124" height="12" rx="4" fill="#C98B5B" stroke="#1F2544" stroke-width="3.5"/>
<rect x="75" y="30" width="10" height="106" rx="3" fill="#E0B08A" stroke="#1F2544" stroke-width="3"/>
<rect x="28" y="112" width="104" height="24" rx="12" fill="#9CC8FF" stroke="#1F2544" stroke-width="3.5"/>
<rect x="36" y="90" width="88" height="24" rx="12" fill="#8ED9B5" stroke="#1F2544" stroke-width="3.5"/>
<rect x="44" y="68" width="72" height="24" rx="12" fill="#FFC93C" stroke="#1F2544" stroke-width="3.5"/>
<rect x="52" y="46" width="56" height="24" rx="12" fill="#FFB59E" stroke="#1F2544" stroke-width="3.5"/>
<circle cx="80" cy="34" r="14" fill="#E0482B" stroke="#1F2544" stroke-width="3.5"/>
<circle cx="75" cy="29" r="3.5" fill="#fff" opacity="0.8"/>
<?php elseif ($kind === 'duck'): ?>
<path d="M8 140 Q24 132 40 140 T72 140 T104 140 T136 140 T160 138" fill="none" stroke="#9CC8FF" stroke-width="5" stroke-linecap="round"/>
<path d="M20 100 Q20 134 66 136 L108 136 Q142 134 140 104 Q138 92 126 94 Q108 98 98 90 L38 90 Q20 90 20 100Z" fill="#FFC93C" stroke="#1F2544" stroke-width="4.5" stroke-linejoin="round"/>
<path d="M48 106 Q70 92 90 108 Q70 124 48 106Z" fill="#FFD35C" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<circle cx="96" cy="58" r="30" fill="#FFC93C" stroke="#1F2544" stroke-width="4.5"/>
<path d="M120 60 Q142 56 146 66 Q138 78 118 72Z" fill="#E0482B" stroke="#1F2544" stroke-width="3.5" stroke-linejoin="round"/>
<circle cx="104" cy="50" r="5" fill="#1F2544"/><circle cx="105.5" cy="48.5" r="1.7" fill="#fff"/>
<ellipse cx="96" cy="66" rx="6" ry="3.5" fill="#E0482B" opacity="0.35"/>
<?php else: /* bear */ ?>
<ellipse cx="46" cy="118" rx="12" ry="18" fill="#C98B5B" stroke="#1F2544" stroke-width="4" transform="rotate(30 46 118)"/>
<ellipse cx="114" cy="118" rx="12" ry="18" fill="#C98B5B" stroke="#1F2544" stroke-width="4" transform="rotate(-30 114 118)"/>
<ellipse cx="80" cy="118" rx="38" ry="32" fill="#C98B5B" stroke="#1F2544" stroke-width="4"/>
<ellipse cx="80" cy="122" rx="22" ry="18" fill="#F3D9B8" stroke="#8A5A36" stroke-width="2" stroke-dasharray="4 4"/>
<ellipse cx="54" cy="144" rx="15" ry="10" fill="#C98B5B" stroke="#1F2544" stroke-width="4"/>
<ellipse cx="106" cy="144" rx="15" ry="10" fill="#C98B5B" stroke="#1F2544" stroke-width="4"/>
<circle cx="50" cy="36" r="14" fill="#C98B5B" stroke="#1F2544" stroke-width="4"/><circle cx="110" cy="36" r="14" fill="#C98B5B" stroke="#1F2544" stroke-width="4"/>
<circle cx="50" cy="36" r="7" fill="#F3D9B8"/><circle cx="110" cy="36" r="7" fill="#F3D9B8"/>
<circle cx="80" cy="62" r="34" fill="#C98B5B" stroke="#1F2544" stroke-width="4"/>
<ellipse cx="80" cy="74" rx="15" ry="11" fill="#F3D9B8" stroke="#1F2544" stroke-width="3"/>
<ellipse cx="80" cy="69" rx="6" ry="4" fill="#1F2544"/>
<path d="M80 73 L80 78 M74 80 Q80 84 86 80" fill="none" stroke="#1F2544" stroke-width="2.5" stroke-linecap="round"/>
<circle cx="67" cy="56" r="4.5" fill="#1F2544"/><circle cx="93" cy="56" r="4.5" fill="#1F2544"/>
<ellipse cx="58" cy="68" rx="6" ry="3.5" fill="#E0482B" opacity="0.35"/><ellipse cx="102" cy="68" rx="6" ry="3.5" fill="#E0482B" opacity="0.35"/>
<path d="M80 96 L64 88 L64 104 Z M80 96 L96 88 L96 104 Z" fill="#E0482B" stroke="#1F2544" stroke-width="3" stroke-linejoin="round"/>
<circle cx="80" cy="96" r="5" fill="#FFC93C" stroke="#1F2544" stroke-width="2.5"/>
<?php endif; ?>
</svg>
</span>
