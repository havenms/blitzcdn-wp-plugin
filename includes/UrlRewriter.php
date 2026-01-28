<?php

namespace BlitzCDN;

class UrlRewriter {

    private $serve_from_cdn;

    private function resolve_named_size($attachment_id, $size, $wp_meta = null) {
        if (is_string($size) && $size !== '') {
            return $size;
        }

        if (!is_array($size) || empty($size)) {
            return '';
        }

        $wp_meta = is_array($wp_meta) ? $wp_meta : \wp_get_attachment_metadata($attachment_id);
        $w = isset($size[0]) ? (int) $size[0] : 0;
        $h = isset($size[1]) ? (int) $size[1] : 0;

        if (is_array($wp_meta) && !empty($wp_meta['sizes']) && is_array($wp_meta['sizes']) && ($w > 0 || $h > 0)) {
            foreach ($wp_meta['sizes'] as $name => $info) {
                $iw = isset($info['width']) ? (int) $info['width'] : 0;
                $ih = isset($info['height']) ? (int) $info['height'] : 0;

                if ($w > 0 && $h > 0 && $iw === $w && $ih === $h) {
                    return $name;
                }

                if ($w > 0 && $h === 0 && $iw === $w) {
                    return $name;
                }

                if ($h > 0 && $w === 0 && $ih === $h) {
                    return $name;
                }
            }

            $best_name = '';
            $best_score = null;
            foreach ($wp_meta['sizes'] as $name => $info) {
                $iw = isset($info['width']) ? (int) $info['width'] : 0;
                $ih = isset($info['height']) ? (int) $info['height'] : 0;

                if ($iw <= 0 && $ih <= 0) {
                    continue;
                }

                $score = 0;
                if ($w > 0) {
                    $score += abs($iw - $w);
                }
                if ($h > 0) {
                    $score += abs($ih - $h);
                }

                if ($best_score === null || $score < $best_score) {
                    $best_score = $score;
                    $best_name = $name;
                }
            }

            if ($best_name !== '') {
                return $best_name;
            }
        }

        $intermediate = \image_get_intermediate_size($attachment_id, $size);
        if (!is_array($intermediate) || empty($intermediate['file'])) {
            return '';
        }

        if (!is_array($wp_meta) || empty($wp_meta['sizes']) || !is_array($wp_meta['sizes'])) {
            return '';
        }

        foreach ($wp_meta['sizes'] as $name => $info) {
            if (!empty($info['file']) && $info['file'] === $intermediate['file']) {
                return $name;
            }
        }

        return '';
    }

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
                $named_size = $this->resolve_named_size($attachment_id, $size);
                if ($named_size !== '' && isset($sizes_meta[$named_size]['url'])) {
                    $image[0] = $sizes_meta[$named_size]['url'];
                }
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
            if (is_array($sizes_meta)) {
                $meta = wp_get_attachment_metadata($id);
                $named_size = $this->resolve_named_size($id, $size, $meta);

                if ($named_size !== '' && isset($sizes_meta[$named_size]['url'])) {
                    $cdn_url = $sizes_meta[$named_size]['url'];

                    $width = 0;
                    $height = 0;
                    if (is_array($meta) && isset($meta['sizes'][$named_size])) {
                        $width = $meta['sizes'][$named_size]['width'];
                        $height = $meta['sizes'][$named_size]['height'];
                    }

                    return [$cdn_url, $width, $height, true];
                }
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
