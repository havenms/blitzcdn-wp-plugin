<?php

namespace BlitzCDN;

class UrlRewriter {

    private $serve_from_cdn;

    public function __construct() {
        $settings = get_option('blitzcdn_settings', []);
        $this->serve_from_cdn = $settings['serve_from_cdn'] ?? false;

        // Always enable filters if serve_from_cdn is enabled
        // This ensures URLs are rewritten even if content wasn't migrated properly
        if ($this->serve_from_cdn) {
            add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10, 2);
            add_filter('wp_get_attachment_image_src', [$this, 'filter_image_src'], 10, 4);
            add_filter('wp_calculate_image_srcset', [$this, 'filter_srcset'], 10, 5);
            add_filter('image_downsize', [$this, 'filter_image_downsize'], 10, 3);
        }
    }

    /**
     * Filter the full size attachment URL.
     */
    public function filter_attachment_url($url, $post_id) {
        $cdn_url = get_post_meta($post_id, '_blitzcdn_cdn_url', true);
        if ($cdn_url) {
            return $cdn_url;
        }
        return $url;
    }

    /**
     * Filter the image src attributes.
     */
    public function filter_image_src($image, $attachment_id, $size, $icon) {
        if (!$image) {
            return $image;
        }

        // $image is [url, width, height, is_intermediate]
        
        if ($size === 'full') {
            $cdn_url = get_post_meta($attachment_id, '_blitzcdn_cdn_url', true);
            if ($cdn_url) {
                $image[0] = $cdn_url;
            }
        } else {
            $sizes_meta = get_post_meta($attachment_id, '_blitzcdn_sizes', true);
            if (is_array($sizes_meta)) {
                // $size can be a string or array [w, h]. If array, WP tries to find the best match.
                // Here we assume standard named sizes or we need to map dimensions.
                // For simplicity, if $size is a string and exists in our meta, use it.
                if (is_string($size) && isset($sizes_meta[$size])) {
                    $image[0] = $sizes_meta[$size]['url'];
                } 
                // If $size is array, we might need to find the matching size in metadata.
                // This is complex. For now, let's handle named sizes.
            }
        }

        return $image;
    }

    /**
     * Filter image downsize.
     * This allows us to bypass local file checks and return the CDN URL directly.
     */
    public function filter_image_downsize($downsize, $id, $size) {
        // If $downsize is true (array), WP uses it. If false, WP continues.
        
        if ($size === 'full') {
            $cdn_url = get_post_meta($id, '_blitzcdn_cdn_url', true);
            if ($cdn_url) {
                // We need width and height.
                $meta = wp_get_attachment_metadata($id);
                $width = (is_array($meta) && isset($meta['width'])) ? $meta['width'] : 0;
                $height = (is_array($meta) && isset($meta['height'])) ? $meta['height'] : 0;
                return [$cdn_url, $width, $height, false];
            }
        } else {
            $sizes_meta = get_post_meta($id, '_blitzcdn_sizes', true);
            if (is_array($sizes_meta) && is_string($size) && isset($sizes_meta[$size])) {
                $cdn_url = $sizes_meta[$size]['url'];
                
                // Get dimensions from WP metadata
                $meta = wp_get_attachment_metadata($id);
                $width = 0;
                $height = 0;
                if (is_array($meta) && isset($meta['sizes'][$size])) {
                    $width = $meta['sizes'][$size]['width'];
                    $height = $meta['sizes'][$size]['height'];
                }

                return [$cdn_url, $width, $height, true];
            }
        }

        return $downsize;
    }

    /**
     * Filter srcset.
     */
    public function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        $sizes_meta = get_post_meta($attachment_id, '_blitzcdn_sizes', true);
        $cdn_url = get_post_meta($attachment_id, '_blitzcdn_cdn_url', true);
        
        if (!is_array($sources)) {
            return $sources;
        }

        // Get full-size image dimensions from metadata
        $full_width = isset($image_meta['width']) ? $image_meta['width'] : 0;

        foreach ($sources as $width => $source) {
            // Check if this is the full-size image
            if ($full_width > 0 && $width == $full_width && $cdn_url) {
                $sources[$width]['url'] = $cdn_url;
                continue;
            }

            // Handle intermediate sizes
            if (is_array($sizes_meta) && isset($image_meta['sizes']) && is_array($image_meta['sizes'])) {
                foreach ($image_meta['sizes'] as $name => $size_info) {
                    if (isset($size_info['width']) && $size_info['width'] == $width) {
                        // Found the size name
                        if (isset($sizes_meta[$name]['url'])) {
                            $sources[$width]['url'] = $sizes_meta[$name]['url'];
                        }
                        break;
                    }
                }
            }
        }

        return $sources;
    }
}
