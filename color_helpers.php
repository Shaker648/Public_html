<?php
/*
 * color_helpers.php — the dot colour for a car colour name (dashboard cards).
 *
 * Works with the English name from the colours list ("White", "Dark Grey"…),
 * the Arabic one ("أبيض") and hand-typed colours: an exact match first, then a
 * colour word inside the name ("Pearl White Metallic" → white). Unknown → slate.
 */
function car_color_hex(string $name): string
{
    static $exact = [
        'white' => '#f8fafc', 'pearl white' => '#f1f5f9', 'black' => '#111827', 'silver' => '#cbd5e1',
        'grey' => '#6b7280', 'gray' => '#6b7280', 'dark grey' => '#374151', 'dark gray' => '#374151', 'light grey' => '#9ca3af',
        'red' => '#dc2626', 'dark red' => '#7f1d1d', 'blue' => '#2563eb', 'dark blue' => '#1e3a8a', 'navy' => '#1e3a8a', 'light blue' => '#60a5fa',
        'green' => '#16a34a', 'dark green' => '#14532d', 'gold' => '#d4af37', 'beige' => '#e0d5c0', 'brown' => '#78350f',
        'orange' => '#ea580c', 'yellow' => '#eab308', 'purple' => '#7c3aed', 'bronze' => '#a97142', 'champagne' => '#e6d7b8',
        'أبيض' => '#f8fafc', 'ابيض' => '#f8fafc', 'أسود' => '#111827', 'اسود' => '#111827', 'فضي' => '#cbd5e1', 'رمادي' => '#6b7280',
        'أحمر' => '#dc2626', 'احمر' => '#dc2626', 'أزرق' => '#2563eb', 'ازرق' => '#2563eb', 'كحلي' => '#1e3a8a', 'أخضر' => '#16a34a', 'اخضر' => '#16a34a',
        'ذهبي' => '#d4af37', 'بيج' => '#e0d5c0', 'بني' => '#78350f', 'برتقالي' => '#ea580c', 'أصفر' => '#eab308', 'اصفر' => '#eab308',
        'بنفسجي' => '#7c3aed', 'برونزي' => '#a97142', 'شامبين' => '#e6d7b8', 'نبيتي' => '#7f1d1d', 'لبني' => '#93c5fd',
    ];
    $n = mb_strtolower(trim($name));
    if ($n === '') return '#64748b';
    if (isset($exact[$n])) return $exact[$n];
    foreach (['white', 'black', 'silver', 'grey', 'gray', 'red', 'blue', 'navy', 'green', 'gold', 'beige', 'brown', 'orange', 'yellow', 'purple', 'bronze', 'champagne',
              'أبيض', 'أسود', 'فضي', 'رمادي', 'أحمر', 'أزرق', 'كحلي', 'أخضر', 'ذهبي', 'بيج', 'بني', 'برتقالي', 'أصفر', 'بنفسجي', 'شامبين', 'نبيتي'] as $w) {
        if (mb_strpos($n, $w) !== false) return $exact[$w];
    }
    return '#64748b';
}
