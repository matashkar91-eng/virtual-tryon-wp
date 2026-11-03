<?php
namespace VTO;
defined('ABSPATH') || exit;

final class Catalog {
    /** Only explicitly enabled, published, visible products are selectable. */
    public static function all(): array {
        $settings = Settings::get();
        $products = [];
        if (function_exists('wc_get_product')) {
            foreach ((array) $settings['product_ids'] as $id) {
                $product = wc_get_product(absint($id));
                if (!$product || $product->get_status() !== 'publish' || !$product->is_visible()) {
                    continue;
                }
                $image_id = $product->get_image_id();
                $url = wp_get_attachment_image_url($image_id, 'full');
                if (!$url) { continue; }
                $products[] = [
                    'id' => 'wc:' . $product->get_id(),
                    'name' => wp_strip_all_tags($product->get_name()),
                    'image' => $url, 'image_id' => $image_id,
                    'category' => $settings['garment_type'],
                ];
            }
        }
        foreach ((array) $settings['manual_products'] as $item) {
            $url = wp_get_attachment_image_url(absint($item['image_id'] ?? 0), 'full');
            if (!$url || empty($item['name']) || empty($item['id'])) { continue; }
            $products[] = [
                'id' => 'custom:' . sanitize_key($item['id']),
                'name' => sanitize_text_field($item['name']),
                'image' => $url, 'image_id' => absint($item['image_id']),
                'category' => $settings['garment_type'],
            ];
        }
        return $products;
    }

    public static function find(string $id): ?array {
        foreach (self::all() as $product) {
            if ($product['id'] === $id) { return $product; }
        }
        return null;
    }
}
