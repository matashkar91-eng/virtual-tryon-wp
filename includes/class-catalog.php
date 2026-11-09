<?php
namespace VTO;
defined('ABSPATH') || exit;

final class Catalog {
    /** A standalone artwork library is the default; WooCommerce is optional. */
    public static function all(): array {
        $settings = Settings::get();
        $products = [];
        if ($settings['catalog_source'] === 'woocommerce' && function_exists('wc_get_product')) {
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
        if ($settings['catalog_source'] !== 'designs') { return $products; }
        foreach ((array) $settings['manual_products'] as $item) {
            if (!is_array($item) || (array_key_exists('active', $item) && empty($item['active']))) { continue; }
            $id = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : '';
            if (!preg_match('/^[a-z0-9_-]{1,80}$/D', $id)) { continue; }
            $url = wp_get_attachment_image_url(absint($item['image_id'] ?? 0), 'full');
            if (!$url || empty($item['name']) || empty($item['id'])) { continue; }
            $products[] = [
                'id' => 'custom:' . $id,
                'name' => sanitize_text_field($item['name']),
                'image' => $url, 'image_id' => absint($item['image_id']),
                'category' => 'scarf',
                'reference_type' => 'artwork',
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
