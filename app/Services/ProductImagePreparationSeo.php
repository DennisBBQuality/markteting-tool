<?php

namespace App\Services;

use Illuminate\Support\Str;

/** The saved variant, never a product name or an appliance detected in pixels, selects the method. */
class ProductImagePreparationSeo
{
    public static function method(array $context, array $result): ?string
    {
        if (($result['status'] ?? '') !== 'bereid' || ! in_array($context['product_type'] ?? 'meat', ['meat', 'fish'], true)) {
            return null;
        }
        $style = (string) ($result['style_id'] ?? '');
        if (preg_match('/^keuken_(pan|oven|airfryer)_/', $style, $match)) {
            return $match[1];
        }
        if (preg_match('/^(bbq_buiten_|serveerbeeld_)/', $style) || in_array($style, ['vis_buiten_bbq', 'vis_serveermoment'], true)) {
            return 'bbq';
        }

        // Older generic kitchen images have no known method; do not guess from their position.
        return null;
    }

    public static function phrase(string $method): string
    {
        return $method === 'bbq' ? 'bereid op de BBQ' : 'bereid in de '.$method;
    }

    public static function complete(array $fields, array $context, array $result): array
    {
        $method = self::method($context, $result);
        if ($method === null) {
            return $fields;
        }
        $base = substr($fields['filename'], 0, -5);
        if ($fields['filename'] !== '' && ! preg_match('/(?:^|-)'.preg_quote($method, '/').'(?:-|$)/i', $base)) {
            $fields['filename'] = rtrim(Str::limit($base, 179 - strlen($method), ''), '-').'-'.$method.'.webp';
        }
        $appliance = $method === 'bbq' ? '(?:bbq|barbecue)' : preg_quote($method, '/');
        $preposition = $method === 'bbq' ? 'op' : 'in';
        $pattern = '/\b(?:bereid|gebakken|gegaard|gegrild|geroosterd|gestoofd|gerookt)\s+'.$preposition.'\s+(?:de|een)\s+'.$appliance.'\b/iu';
        foreach (['alt', 'title', 'caption', 'description'] as $field) {
            if (! preg_match($pattern, $fields[$field])) {
                $suffix = in_array($field, ['alt', 'title'], true)
                    ? ' – '.self::phrase($method)
                    : '. Serveersuggestie: '.self::phrase($method).'.';
                $fields[$field] = rtrim($fields[$field], " .\t\n\r\0\x0B").$suffix;
            }
        }

        return $fields;
    }
}
