<?php
/**
 * Plugin Name: WooComPort
 * Description: ابزار فارسی استخراج و انتقال محصولات از صفحات عمومی به ووکامرس.
 * Version: 1.6.1
 * Author: Abbas Akbarabadi
 * Author URI: https://github.com/arsooy
 * Text Domain: woo-com-port
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'WOOCOMPORT_VERSION', '1.6.1' );

add_action( 'admin_menu', function () {
    add_menu_page( 'WooComPort', 'WooComPort', 'manage_woocommerce', 'woo-com-port-single', 'woocomport_render_single_page', 'dashicons-products', 56 );
    add_submenu_page( 'woo-com-port-single', 'ورود تکی', 'ورود تکی', 'manage_woocommerce', 'woo-com-port-single', 'woocomport_render_single_page' );
    add_submenu_page( 'woo-com-port-single', 'ورود گروهی', 'ورود گروهی', 'manage_woocommerce', 'woo-com-port-category', 'woocomport_render_page' );
    add_submenu_page( 'woo-com-port-single', 'گزارشات', 'گزارشات', 'manage_woocommerce', 'woo-com-port-reports', 'woocomport_render_reports_page' );
} );

function woocomport_fetch_page( $url ) {
    $url = esc_url_raw( trim( (string) $url ) );
    if ( ! $url || ! wp_http_validate_url( $url ) ) return new WP_Error( 'invalid_url', 'نشانی واردشده معتبر یا عمومی نیست.' );
    $response = wp_safe_remote_get( $url, array(
        'timeout' => 20, 'redirection' => 3, 'limit_response_size' => 3 * MB_IN_BYTES,
        'headers' => array( 'User-Agent' => 'Mozilla/5.0 (compatible; WooComPort/1.1; WordPress)' ),
    ) );
    if ( is_wp_error( $response ) ) return $response;
    $code = wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) return new WP_Error( 'http_error', 'وب‌سایت مبدا پاسخ نامعتبر داد (HTTP ' . absint( $code ) . ').' );
    return wp_remote_retrieve_body( $response );
}

function woocomport_normalize_number( $value ) {
    $value = strtr( (string) $value, array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.','٬'=>'','،'=>'' ) );
    $value = preg_replace( '/[^0-9.]/', '', $value );
    return $value;
}

function woocomport_extract_product( $html, $url ) {
    $out = array( 'name'=>'', 'short_description'=>'', 'description'=>'', 'price'=>'', 'sku'=>'', 'image'=>'', 'url'=>$url, 'availability'=>'', 'gallery'=>array(), 'attributes'=>array(), 'variations'=>array() );
    if ( ! class_exists( 'DOMDocument' ) ) return $out;
    $previous = libxml_use_internal_errors( true );
    $dom = new DOMDocument();
    $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
    $xp = new DOMXPath( $dom );
    $meta = array();
    foreach ( $xp->query( '//meta[@property or @name]' ) as $node ) {
        $key = strtolower( $node->getAttribute( 'property' ) ?: $node->getAttribute( 'name' ) );
        $meta[$key] = trim( $node->getAttribute( 'content' ) );
    }
    $out['name'] = ''; // Product heading is authoritative; SEO titles are only a last resort.
    $out['short_description'] = $meta['og:description'] ?? ( $meta['description'] ?? '' );
    $out['image'] = $meta['og:image'] ?? ( $meta['twitter:image'] ?? '' );
    $out['price'] = $meta['product:price:amount'] ?? ( $meta['product:price'] ?? ( $meta['og:price:amount'] ?? '' ) );
    foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $script ) {
        $json = json_decode( $script->textContent, true );
        if ( ! is_array( $json ) ) continue;
        $nodes = isset( $json['@graph'] ) && is_array( $json['@graph'] ) ? $json['@graph'] : ( isset( $json[0] ) ? $json : array( $json ) );
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) continue;
            $type = $node['@type'] ?? '';
            if ( is_array( $type ) ) $type = implode( ' ', $type );
            if ( stripos( (string) $type, 'Product' ) === false ) continue;
            $out['name'] = $out['name'] ?: ( $node['name'] ?? '' );
            $out['short_description'] = $out['short_description'] ?: ( $node['description'] ?? '' );
            $out['sku'] = $node['sku'] ?? '';
            if ( empty( $out['image'] ) && ! empty( $node['image'] ) ) {
                $im = $node['image'];
                if ( is_array( $im ) ) $im = isset( $im[0] ) ? $im[0] : ( $im['url'] ?? '' );
                if ( is_array( $im ) ) $im = $im['url'] ?? '';
                $out['image'] = $im;
            }
            if ( ! empty( $node['offers'] ) ) {
                $offer = isset( $node['offers'][0] ) ? $node['offers'][0] : $node['offers'];
                if ( is_array( $offer ) ) {
                    $out['price'] = $out['price'] ?: ( $offer['price'] ?? ( $offer['lowPrice'] ?? '' ) );
                    $out['availability'] = $offer['availability'] ?? '';
                }
            }
            break;
        }
    }
    // Prefer the visible product H1 over SEO/meta titles.
    $h1 = $xp->query( '//h1' );
    if ( $h1->length ) {
        foreach ( $h1 as $heading ) {
            $heading_text = trim( preg_replace( '/\\s+/u', ' ', $heading->textContent ) );
            if ( $heading_text !== '' ) { $out['name'] = $heading_text; break; }
        }
    }
    // Collect gallery images from common WooCommerce and storefront gallery containers.
    $gallery_nodes = $xp->query('//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"product-gallery") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"product-images") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"gallery") or contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"gallery")]//img | //img[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"wp-post-image")]');
    foreach ( $gallery_nodes as $img ) {
        $src = $img->getAttribute('data-large_image') ?: ( $img->getAttribute('data-src') ?: ( $img->getAttribute('data-lazy-src') ?: $img->getAttribute('src') ) );
        if ( ! $src && $img->getAttribute('srcset') ) { $srcset = explode(',', $img->getAttribute('srcset')); $last = trim(end($srcset)); $src = preg_split('/\\s+/', $last)[0] ?? ''; }
        $src = esc_url_raw( WP_Http::make_absolute_url( $src, $url ) );
        if ( $src && wp_http_validate_url($src) ) $out['gallery'][$src] = $src;
    }
    if ( $out['image'] ) $out['gallery'][$out['image']] = $out['image'];
    // Extract visible product attributes from WooCommerce tables and definition lists.
    foreach ( $xp->query('//table[contains(@class,"woocommerce-product-attributes")]//tr | //table[contains(@class,"product-attributes")]//tr | //dl[contains(@class,"product")]/dt') as $row ) {
        if ( strtolower($row->nodeName) === 'tr' ) {
            $key = ''; $val = '';
            foreach ( $row->childNodes as $cell ) { if ( $cell->nodeType !== XML_ELEMENT_NODE ) continue; if ( in_array(strtolower($cell->nodeName), array('th','td'), true) ) { if ( !$key ) $key = trim($cell->textContent); else $val = trim($cell->textContent); } }
        } else {
            $key = trim($row->textContent); $val = '';
            $next = $row->nextSibling; while ( $next && $next->nodeType !== XML_ELEMENT_NODE ) $next = $next->nextSibling;
            if ( $next ) $val = trim($next->textContent);
        }
        $key = sanitize_text_field($key); $val = sanitize_text_field($val);
        if ( $key && $val ) $out['attributes'][$key] = array_values(array_filter(array_map('trim', preg_split('/[,،|]+/u', $val))));
    }
    // Parse selectable product options; these are used as variation attributes when available.
    $selects = $xp->query('//form[contains(@class,"variations_form")]//select | //select[contains(@name,"attribute")]');
    $variation_attributes = array();
    foreach ( $selects as $select ) {
        $label = '';
        $id = $select->getAttribute('id');
        if ( $id ) { $labels = $xp->query('//label[@for="' . addslashes($id) . '"]'); if ( $labels->length ) $label = trim($labels->item(0)->textContent); }
        $field = $select->getAttribute('name');
        $label = $label ?: preg_replace('/^attribute_(pa_)?/', '', $field);
        $options = array();
        foreach ( $xp->query('.//option', $select) as $option ) { $v = trim($option->getAttribute('value')); $t = trim($option->textContent); if ( $v !== '' && $t !== '' ) $options[$v] = $t; }
        if ( $options ) { $out['attributes'][$label] = array_values($options); $variation_attributes[$field] = $options; }
    }
    // WooCommerce embeds full variation records as JSON in data-product_variations.
    foreach ( $xp->query('//form[contains(@class,"variations_form")][@data-product_variations]') as $form ) {
        $records = json_decode(html_entity_decode($form->getAttribute('data-product_variations'), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if ( is_array($records) ) foreach ( $records as $record ) {
            if ( ! is_array($record) || empty($record['display_price']) ) continue;
            $attrs = array();
            foreach ( (array)($record['attributes'] ?? array()) as $key => $value ) {
                $label = preg_replace('/^attribute_(pa_)?/', '', $key);
                $attrs[$label] = isset($variation_attributes[$key][$value]) ? $variation_attributes[$key][$value] : $value;
            }
            $out['variations'][] = array('name'=>'', 'sku'=>(string)($record['sku'] ?? ''), 'price'=>woocomport_normalize_number($record['display_price']), 'attributes'=>$attrs);
        }
    }
    // If structured offers provide several named prices but no explicit option controls, model them as named variants.
    if ( count($out['variations']) > 1 ) {
        $named = array();
        foreach ( $out['variations'] as $index => $variant ) {
            $variant_label = trim((string)($variant['name'] ?? ''));
            if ( $variant_label !== '' ) $named[$index] = $variant_label;
        }
        if ( count(array_unique($named)) > 1 ) {
            $out['attributes']['مدل'] = array_values(array_unique($named));
            foreach ( $out['variations'] as $index => $variant ) {
                if ( empty($variant['attributes']) && ! empty($named[$index]) ) $out['variations'][$index]['attributes'] = array('مدل' => $named[$index]);
            }
        }
    }
    // Extract the actual product body separately from SEO/meta summaries.
    $long_candidates = $xp->query(
        '//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"description") or contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"description") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"product-content") or contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"product-content") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"product-detail") or contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"product-detail")]'
    );
    $long_html = '';
    foreach ( $long_candidates as $candidate ) {
        $class_id = strtolower( $candidate->getAttribute('class') . ' ' . $candidate->getAttribute('id') );
        if ( preg_match('/short|summary|excerpt|breadcrumb|related|comment|review-list/u', $class_id) ) continue;
        $text = trim( preg_replace('/\\s+/u', ' ', $candidate->textContent ) );
        if ( mb_strlen( $text, 'UTF-8' ) < 100 ) continue;
        $inner = '';
        foreach ( $candidate->childNodes as $child ) $inner .= $dom->saveHTML( $child );
        if ( strlen( $inner ) > strlen( $long_html ) ) $long_html = $inner;
    }
    if ( $long_html ) $out['description'] = $long_html;
    // If the source has no dedicated short field, retain its concise meta/structured summary.
    if ( ! $out['short_description'] && $out['description'] ) {
        $plain = trim( wp_strip_all_tags( $out['description'] ) );
        $out['short_description'] = mb_substr( $plain, 0, 450, 'UTF-8' );
    }
    if ( ! $out['name'] ) { $n = $xp->query( '//h1' ); if ( $n->length ) $out['name'] = trim( $n->item(0)->textContent ); }
    if ( ! $out['name'] ) { $n = $xp->query( '//title' ); if ( $n->length ) $out['name'] = trim( $n->item(0)->textContent ); }
    $out['name'] = sanitize_text_field( html_entity_decode( wp_strip_all_tags( $out['name'] ) ) );
    $out['short_description'] = wp_kses_post( html_entity_decode( (string) $out['short_description'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    $out['description'] = wp_kses_post( html_entity_decode( (string) $out['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    $out['price'] = woocomport_normalize_number( $out['price'] );
    $out['sku'] = sanitize_text_field( (string) $out['sku'] );
    $out['image'] = esc_url_raw( (string) $out['image'] );
    $out['gallery'] = array_values( array_unique( array_filter( $out['gallery'] ) ) );
    libxml_clear_errors(); libxml_use_internal_errors( $previous );
    return $out;
}

function woocomport_find_product_links( $html, $base_url ) {
    $found = array();
    if ( ! class_exists( 'DOMDocument' ) ) return $found;
    $previous = libxml_use_internal_errors( true );
    $dom = new DOMDocument(); $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html ); $xp = new DOMXPath( $dom );
    $parts = wp_parse_url( $base_url ); $host = strtolower( $parts['host'] ?? '' );
    foreach ( $xp->query( '//a[@href]' ) as $a ) {
        $href = trim( $a->getAttribute( 'href' ) );
        if ( ! $href || strpos( $href, '#' ) === 0 || stripos( $href, 'javascript:' ) === 0 || stripos( $href, 'mailto:' ) === 0 ) continue;
        $url = esc_url_raw( WP_Http::make_absolute_url( $href, $base_url ) );
        if ( ! $url || ! wp_http_validate_url( $url ) || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) continue;
        $path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        if ( preg_match( '~/(category|tag|page|cart|checkout|my-account|wp-content|wp-admin)(/|$)~', $path ) || strlen( $path ) < 3 ) continue;
        if ( preg_match( '~\.(jpg|jpeg|png|webp|gif|pdf|zip)$~i', $path ) ) continue;
        $found[$url] = trim( sanitize_text_field( $a->textContent ) );
    }
    libxml_clear_errors(); libxml_use_internal_errors( $previous );
    return array_slice( $found, 0, 20, true );
}


/**
 * Identify listing-card product links and child category links only.
 * Generic page links and pagination are deliberately ignored.
 */
function woocomport_parse_listing( $html, $base_url ) {
    $result = array( 'products' => array(), 'categories' => array() );
    if ( ! class_exists( 'DOMDocument' ) ) return $result;
    $previous = libxml_use_internal_errors( true );
    $dom = new DOMDocument();
    $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
    $xp = new DOMXPath( $dom );
    $host = strtolower( (string) wp_parse_url( $base_url, PHP_URL_HOST ) );
    $resolve = function( $href ) use ( $base_url, $host ) {
        $url = esc_url_raw( WP_Http::make_absolute_url( trim( (string) $href ), $base_url ) );
        if ( ! $url || ! wp_http_validate_url( $url ) || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) return '';
        $path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        if ( preg_match( '~\\.(jpg|jpeg|png|webp|gif|pdf|zip)$~i', $path ) ) return '';
        return $url;
    };
    $product_nodes = $xp->query(
        '//*[contains(concat(" ", normalize-space(@class), " "), " products ")]/*[contains(concat(" ", normalize-space(@class), " "), " product ")] |
         //li[contains(concat(" ", normalize-space(@class), " "), " product ") and not(contains(concat(" ", normalize-space(@class), " "), " product-category "))]'
    );
    foreach ( $product_nodes as $node ) {
        foreach ( $xp->query( './/a[@href]', $node ) as $a ) {
            $url = $resolve( $a->getAttribute( 'href' ) );
            if ( $url ) { $result['products'][$url] = trim( sanitize_text_field( $a->textContent ) ); break; }
        }
    }
    $category_nodes = $xp->query(
        '//*[contains(concat(" ", normalize-space(@class), " "), " product-category ")]//a[@href] |
         //a[contains(concat(" ", normalize-space(@class), " "), " product-category ")][@href]'
    );
    foreach ( $category_nodes as $a ) {
        $url = $resolve( $a->getAttribute( 'href' ) );
        if ( $url && $url !== $base_url ) $result['categories'][$url] = trim( sanitize_text_field( $a->textContent ) );
    }
    // Support Technosun-style stores: product URLs use /p/ and category URLs use /c/.
    // Restrict matching to these explicit route patterns so ordinary links and pagination are ignored.
    foreach ( $xp->query( '//a[@href]' ) as $a ) {
        $url = $resolve( $a->getAttribute( 'href' ) );
        if ( ! $url ) continue;
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( preg_match( '~^/p/[0-9]+(?:/|$)~i', $path ) ) {
            $label = trim( sanitize_text_field( $a->textContent ) );
            if ( ! isset( $result['products'][$url] ) ) $result['products'][$url] = $label;
        } elseif ( preg_match( '~^/c/[^/]+/?$~i', $path ) && untrailingslashit( $url ) !== untrailingslashit( $base_url ) ) {
            $label = trim( sanitize_text_field( $a->textContent ) );
            if ( $label && ! isset( $result['categories'][$url] ) ) $result['categories'][$url] = $label;
        }
    }
    // Some WooCommerce themes put the category class on the card, not its anchor.
    foreach ( $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " product-category ")]//a[@href]' ) as $a ) {
        $url = $resolve( $a->getAttribute( 'href' ) );
        if ( $url && $url !== $base_url ) $result['categories'][$url] = trim( sanitize_text_field( $a->textContent ) );
    }
    libxml_clear_errors(); libxml_use_internal_errors( $previous );
    return $result;
}

/** Return true only when the source positively identifies the product as in stock. */
function woocomport_is_in_stock( $html, $item = array() ) {
    $availability = strtolower( (string) ( $item['availability'] ?? '' ) );
    if ( $availability ) {
        if ( strpos( $availability, 'outofstock' ) !== false || strpos( $availability, 'soldout' ) !== false ) return false;
        if ( strpos( $availability, 'instock' ) !== false || strpos( $availability, 'limitedavailability' ) !== false || strpos( $availability, 'preorder' ) !== false ) return true;
    }
    $lower = strtolower( (string) $html );
    if ( preg_match( '/\\b(outofstock|out-of-stock|stockout|sold-out|soldout)\\b/u', $lower ) || preg_match( '/ناموجود|اتمام\\s*موجودی|تمام\\s*شد/u', $html ) ) return false;
    if ( preg_match( '/\b(outofstock|out-of-stock|stockout|sold-out|soldout)\b/u', $lower ) || preg_match( '/ناموجود|اتمام\s*موجودی|تمام\s*شد/u', $html ) ) return false;
    if ( preg_match( '/\b(instock|in-stock|available-on-backorder)\b/u', $lower ) || preg_match( '/class="[^"]*\binstock\b/u', $lower ) ) return true;
    // Explicit Persian storefront signals. A visible add-to-cart control is positive stock evidence.
    if ( preg_match( '/افزودن\s*به\s*سبد\s*خرید|افزودن\s*به\s*سبد|موجود\s*در\s*انبار|موجود\s*است/u', $html ) ) {
        if ( ! preg_match( '/ناموجود|اتمام\s*موجودی|تمام\s*شد/u', $html ) ) return true;
    }
    if ( preg_match( '/<button[^>]*(?:add-to-cart|add_to_cart)[^>]*>.*?<\/button>/is', $html ) && ! preg_match( '/<button[^>]*disabled[^>]*(?:add-to-cart|add_to_cart)/is', $html ) ) return true;
    return false;
}

/** Crawl only WooCommerce product cards and nested product-category cards. */
function woocomport_crawl_categories( $root_url, $max_pages = 30, $max_products = 200 ) {
    $queue = array( $root_url );
    $visited = array();
    $products = array();
    while ( $queue && count( $visited ) < $max_pages && count( $products ) < $max_products ) {
        $category_url = array_shift( $queue );
        if ( isset( $visited[$category_url] ) ) continue;
        $visited[$category_url] = true;
        $html = woocomport_fetch_page( $category_url );
        if ( is_wp_error( $html ) ) continue;
        $parsed = woocomport_parse_listing( $html, $category_url );
        foreach ( $parsed['categories'] as $child_url => $label ) {
            if ( ! isset( $visited[$child_url] ) && ! in_array( $child_url, $queue, true ) && count( $visited ) + count( $queue ) < $max_pages ) $queue[] = $child_url;
        }
        foreach ( $parsed['products'] as $product_url => $label ) {
            if ( isset( $products[$product_url] ) || count( $products ) >= $max_products ) continue;
            $product_html = woocomport_fetch_page( $product_url );
            if ( is_wp_error( $product_html ) ) continue;
            $item = woocomport_extract_product( $product_html, $product_url );
            // Reject category/ordinary pages: a real product needs structured Product data or product-page markup.
            $product_path = (string) wp_parse_url( $product_url, PHP_URL_PATH );
            $is_technosun_product = (bool) preg_match( '~^/p/[0-9]+(?:/|$)~i', $product_path );
            $is_product = ( ! empty( $item['name'] ) && ( $is_technosun_product || stripos( $product_html, '"@type":"Product"' ) !== false || stripos( $product_html, '"@type": "Product"' ) !== false || preg_match( '/class="[^"]*\bproduct\b/u', $product_html ) || preg_match( '/woocommerce-product-gallery|single_add_to_cart_button|product_meta/u', $product_html ) ) );
            if ( ! $is_product || ! woocomport_is_in_stock( $product_html, $item ) ) continue;
            $item['problem'] = '';
            $products[$product_url] = $item;
        }
    }
    return array_values( $products );
}

function woocomport_import_product( $item ) {
    if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'no_wc', 'ووکامرس فعال نیست.' );
    $has_variations = false;
    foreach ( (array)($item['variations'] ?? array()) as $candidate_variation ) { if ( ! empty($candidate_variation['attributes']) ) { $has_variations = true; break; } }
    $product = $has_variations ? new WC_Product_Variable() : new WC_Product_Simple();
    $product->set_name( $item['name'] );
    $product->set_status( 'draft' );
    $product->set_description( $item['description'] );
    $product->set_short_description( $item['short_description'] ?? '' );
    if ( $has_variations ) {
        $wc_attributes = array(); $position = 0;
        foreach ( $item['attributes'] as $label => $options ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( 0 ); $attribute->set_name( sanitize_text_field($label) );
            $attribute->set_options( array_values(array_unique(array_map('sanitize_text_field', (array)$options))) );
            $attribute->set_position( $position++ ); $attribute->set_visible( true ); $attribute->set_variation( true );
            $wc_attributes[] = $attribute;
        }
        $product->set_attributes( $wc_attributes );
    } else {
        foreach ( (array)($item['attributes'] ?? array()) as $label => $options ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(0); $attribute->set_name(sanitize_text_field($label));
            $attribute->set_options(array_values(array_unique(array_map('sanitize_text_field',(array)$options))));
            $attribute->set_position(count($wc_attributes ?? array())); $attribute->set_visible(true); $attribute->set_variation(false);
            $wc_attributes[] = $attribute;
        }
        if ( ! empty($wc_attributes) ) $product->set_attributes($wc_attributes);
    }
    if ( ! $has_variations && $item['price'] !== '' ) $product->set_regular_price( wc_format_decimal( $item['price'] ) );
    if ( ! empty( $item['sku'] ) && ! wc_get_product_id_by_sku( $item['sku'] ) ) $product->set_sku( $item['sku'] );
    $id = $product->save();
    if ( ! $id ) return new WP_Error( 'save_failed', 'ذخیره محصول در ووکامرس ناموفق بود.' );
    if ( $has_variations ) {
        foreach ( $item['variations'] as $variation_data ) {
            if ( empty($variation_data['price']) ) continue;
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($id);
            $variation->set_status('publish');
            $variation->set_regular_price(wc_format_decimal($variation_data['price']));
            if ( ! empty($variation_data['sku']) && ! wc_get_product_id_by_sku($variation_data['sku']) ) $variation->set_sku(sanitize_text_field($variation_data['sku']));
            $variation_attrs = array();
            foreach ( (array)($variation_data['attributes'] ?? array()) as $label => $value ) {
                $variation_attrs['attribute_' . sanitize_title($label)] = sanitize_title($value);
            }
            if ( $variation_attrs ) $variation->set_attributes($variation_attrs);
            $variation->save();
        }
        WC_Product_Variable::sync($id);
    }
    update_post_meta( $id, '_woocomport_source_url', esc_url_raw( $item['url'] ) );
    update_post_meta( $id, '_woocomport_imported_at', current_time( 'mysql' ) );
    update_post_meta( $id, '_woocomport_import_version', WOOCOMPORT_VERSION );
    update_post_meta( $id, '_woocomport_import_type', $has_variations ? 'variable' : 'simple' );
    update_post_meta( $id, '_woocomport_gallery_count', count( (array) ( $item['gallery'] ?? array() ) ) );
    update_post_meta( $id, '_woocomport_attributes_count', count( (array) ( $item['attributes'] ?? array() ) ) );
    update_post_meta( $id, '_woocomport_variations_count', count( (array) ( $item['variations'] ?? array() ) ) );
    if ( ! empty( $item['gallery'] ) || ! empty( $item['image'] ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $image_urls = array_values( array_unique( array_filter( array_merge( array( $item['image'] ?? '' ), (array) ( $item['gallery'] ?? array() ) ) ) ) );
        $attachment_ids = array();
        foreach ( $image_urls as $image_url ) {
            $attachment = media_sideload_image( esc_url_raw( $image_url ), $id, $item['name'], 'id' );
            if ( ! is_wp_error( $attachment ) ) $attachment_ids[] = absint( $attachment );
            elseif ( empty( $item['image_import_error'] ) ) $item['image_import_error'] = $attachment->get_error_message();
        }
        if ( ! empty( $attachment_ids ) ) {
            set_post_thumbnail( $id, $attachment_ids[0] );
            if ( count( $attachment_ids ) > 1 ) update_post_meta( $id, '_product_image_gallery', implode( ',', array_slice( $attachment_ids, 1 ) ) );
        } elseif ( ! empty( $item['image_import_error'] ) ) {
            update_post_meta( $id, '_woocomport_image_import_error', $item['image_import_error'] );
        }
    }
    return $id;
}


/** Shared admin page header and navigation. */
function woocomport_admin_header( $active ) {
    echo '<div class="wrap woocomport-wrap" dir="rtl"><style>
    .woocomport-wrap{max-width:1200px}.wcport-head{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;margin:18px 0}.wcport-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.wcport-stat{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px}.wcport-stat b{display:block;font-size:24px;margin-top:7px;color:#1d4e89}.wcport-btn{background:#2271b1!important;color:#fff!important;border:0!important;padding:7px 16px!important;height:auto!important}.wcport-empty{background:#fff;padding:24px;border:1px dashed #c3c4c7;border-radius:10px;text-align:center}.wcport-table{width:100%;border-collapse:collapse;background:#fff}.wcport-table th,.wcport-table td{padding:11px;border-bottom:1px solid #e2e4e7;text-align:right;vertical-align:top}.wcport-table th{background:#f6f7f7}.wcport-muted{color:#646970;font-size:12px;overflow-wrap:anywhere}@media(max-width:800px){.wcport-stats{grid-template-columns:repeat(2,1fr)}.wcport-table{display:block;overflow:auto}}
    </style><h1>WooComPort</h1><nav class="nav-tab-wrapper">';
    $tabs = array( 'single'=>array('ورود تکی','admin.php?page=woo-com-port-single'), 'category'=>array('ورود گروهی','admin.php?page=woo-com-port-category'), 'reports'=>array('گزارشات','admin.php?page=woo-com-port-reports') );
    foreach ( $tabs as $key => $tab ) echo '<a class="nav-tab ' . ( $active === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url($tab[1]) ) . '">' . esc_html($tab[0]) . '</a>';
    echo '</nav>';
}
function woocomport_render_single_page() {
    if ( ! current_user_can('manage_woocommerce') ) wp_die('دسترسی غیرمجاز');
    $notice = ''; $error = ''; $item = null; $url = '';
    if ( isset($_POST['woocomport_single_action']) ) {
        check_admin_referer('woocomport_single','woocomport_single_nonce');
        $action = sanitize_key(wp_unslash($_POST['woocomport_single_action']));
        $url = isset($_POST['product_url']) ? esc_url_raw(wp_unslash($_POST['product_url'])) : '';
        if ( ! wp_http_validate_url($url) ) $error = 'آدرس محصول معتبر نیست.';
        else {
            $html = woocomport_fetch_page($url);
            if ( is_wp_error($html) ) $error = $html->get_error_message();
            else {
                $item = woocomport_extract_product($html,$url);
                if ( empty($item['name']) ) $error = 'نام محصول از صفحه پیدا نشد؛ لطفاً لینک مستقیم صفحه محصول را وارد کن.';
                elseif ( 'import' === $action ) {
                    if ( ! woocomport_is_in_stock($html,$item) ) $error = 'موجودی محصول در سایت مبدا قابل تأیید نیست؛ برای جلوگیری از انتقال اشتباه، ذخیره انجام نشد.';
                    else {
                        $saved = woocomport_import_product($item);
                        if ( is_wp_error($saved) ) $error = $saved->get_error_message();
                        else { $notice = 'محصول با موفقیت به‌صورت پیش‌نویس منتقل شد. شناسه محصول: ' . absint($saved); $item = null; }
                    }
                } elseif ( 'preview' === $action && ! woocomport_is_in_stock($html,$item) ) {
                    $error = 'اطلاعات استخراج شد، اما موجودی در مبدا قابل تأیید نیست. قبل از انتقال، موجودی را بررسی کن.';
                }
            }
        }
    }
    woocomport_admin_header('single');
    echo '<div class="wcport-head"><h2>ورود تکی محصول</h2><p>لینک مستقیم یک محصول از سایت مبدا را وارد کن. ابتدا اطلاعات استخراج‌شده نمایش داده می‌شود و فقط با زدن دکمه انتقال، محصول به‌صورت پیش‌نویس ذخیره خواهد شد.</p>';
    if($notice) echo '<div class="notice notice-success"><p>'.esc_html($notice).'</p></div>';
    if($error) echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';
    echo '<form method="post">'; wp_nonce_field('woocomport_single','woocomport_single_nonce');
    echo '<p><input class="large-text" type="url" name="product_url" required dir="ltr" placeholder="https://example.com/product/..." value="'.esc_attr($url).'"></p><button class="button button-primary wcport-btn" name="woocomport_single_action" value="preview">استخراج و نمایش اطلاعات</button>';
    if($item) echo ' <button class="button button-primary wcport-btn" name="woocomport_single_action" value="import">انتقال محصول به سایت</button>';
    echo '</form></div>';
    if($item) {
        echo '<div class="wcport-head">';
        if(!empty($item['image'])) echo '<img src="'.esc_url($item['image']).'" style="max-width:150px;max-height:150px;object-fit:contain;float:left;margin:0 15px 10px 0">';
        echo '<h2>'.esc_html($item['name']).'</h2><p>قیمت: '.($item['price']!==''?esc_html(number_format_i18n((float)$item['price'])):'نامشخص').'</p>';
        echo '<p>کد محصول: '.esc_html($item['sku']?:'ثبت نشده').' | تصاویر گالری: '.count((array)$item['gallery']).' | ویژگی‌ها: '.count((array)$item['attributes']).' | حالت‌های متغیر: '.count((array)$item['variations']).'</p>';
        echo '<h3>توضیح کوتاه</h3>'.wp_kses_post($item['short_description']).'<h3>توضیحات کامل</h3>'.wp_kses_post($item['description']);
        if(!empty($item['attributes'])) { echo '<h3>ویژگی‌ها</h3><ul>'; foreach($item['attributes'] as $label=>$values) echo '<li><strong>'.esc_html($label).':</strong> '.esc_html(implode('،',(array)$values)).'</li>'; echo '</ul>'; }
        if(!empty($item['variations'])) { echo '<h3>قیمت‌ها و حالت‌ها</h3><ul>'; foreach($item['variations'] as $v) echo '<li>'.esc_html(wp_json_encode($v,JSON_UNESCAPED_UNICODE)).'</li>'; echo '</ul>'; }
        if(count((array)$item['gallery'])>1) { echo '<h3>گالری تصاویر</h3>'; foreach($item['gallery'] as $img) echo '<img src="'.esc_url($img).'" style="width:85px;height:85px;object-fit:contain;margin:5px;border:1px solid #ddd">'; }
        echo '<div style="clear:both"></div></div>';
    }
    echo '<hr><p><strong>WooComPort '.esc_html(WOOCOMPORT_VERSION).'</strong> — طراحی شده توسط <a href="tel:09390332600">عباس اکبرآبادی</a></p></div>';
}
function woocomport_render_reports_page() {
    if ( ! current_user_can('manage_woocommerce') ) wp_die('دسترسی غیرمجاز');
    $ids = get_posts(array('post_type'=>'product','post_status'=>array('publish','draft','pending','private','future'),'numberposts'=>-1,'fields'=>'ids','meta_key'=>'_woocomport_imported_at','orderby'=>'date','order'=>'DESC'));
    $total = count($ids); $drafts=0; $published=0; $variables=0; $simple=0; $gallery=0; $attribute_total=0; $variation_total=0;
    foreach($ids as $id) {
        $status=get_post_status($id); if($status==='publish') $published++; else $drafts++;
        $type=get_post_meta($id,'_woocomport_import_type',true); if($type==='variable') $variables++; else $simple++;
        $gallery += (int)get_post_meta($id,'_woocomport_gallery_count',true);
        $attribute_total += (int)get_post_meta($id,'_woocomport_attributes_count',true);
        $variation_total += (int)get_post_meta($id,'_woocomport_variations_count',true);
    }
    woocomport_admin_header('reports');
    echo '<div class="wcport-head"><h2>گزارش جامع انتقال محصولات</h2><p>فهرست محصولاتی که توسط WooComPort وارد فروشگاه شده‌اند، همراه با تاریخ انتقال و جزئیات ثبت‌شده.</p>';
    echo '<div class="wcport-stats"><div class="wcport-stat">کل محصولات واردشده<b>'.number_format_i18n($total).'</b></div><div class="wcport-stat">پیش‌نویس‌ها<b>'.number_format_i18n($drafts).'</b></div><div class="wcport-stat">منتشرشده‌ها<b>'.number_format_i18n($published).'</b></div><div class="wcport-stat">محصولات متغیر<b>'.number_format_i18n($variables).'</b></div><div class="wcport-stat">محصولات ساده<b>'.number_format_i18n($simple).'</b></div><div class="wcport-stat">تصاویر گالری<b>'.number_format_i18n($gallery).'</b></div><div class="wcport-stat">ویژگی‌های ثبت‌شده<b>'.number_format_i18n($attribute_total).'</b></div><div class="wcport-stat">حالت‌های متغیر<b>'.number_format_i18n($variation_total).'</b></div></div></div>';
    if(!$ids) echo '<div class="wcport-empty">هنوز محصولی با این نسخه از WooComPort وارد نشده است. گزارش انتقال‌های جدید از این پس در این صفحه ثبت می‌شود.</div>';
    else {
        echo '<table class="wcport-table"><thead><tr><th>محصول</th><th>تاریخ ورود</th><th>نوع</th><th>قیمت</th><th>ویژگی‌ها</th><th>گالری</th><th>وضعیت</th><th>منبع</th></tr></thead><tbody>';
        foreach($ids as $id) {
            $p=wc_get_product($id); if(!$p) continue;
            $date=get_post_meta($id,'_woocomport_imported_at',true); if(!$date) $date=get_post_field('post_date',$id);
            $type=$p->is_type('variable')?'متغیر':'ساده';
            $price=$p->is_type('variable')?$p->get_price_html():$p->get_price();
            $status=get_post_status($id); $status_label=$status==='publish'?'منتشرشده':($status==='draft'?'پیش‌نویس':$status);
            $source=get_post_meta($id,'_woocomport_source_url',true);
            echo '<tr><td><strong><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html($p->get_name()).'</a></strong><div class="wcport-muted">شناسه: '.absint($id).' | SKU: '.esc_html($p->get_sku()?:'—').'</div></td>';
            echo '<td>'.esc_html(mysql2date('Y/m/d H:i',$date)).'</td><td>'.$type.'</td><td>'.wp_kses_post($price?:'—').'</td>';
            echo '<td>'.absint(get_post_meta($id,'_woocomport_attributes_count',true)).'</td><td>'.absint(get_post_meta($id,'_woocomport_gallery_count',true)).'</td><td>'.esc_html($status_label).'</td>';
            echo '<td>'.($source?'<a href="'.esc_url($source).'" target="_blank" rel="noopener">مشاهده مبدا</a>':'—').'</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<hr><p><strong>WooComPort '.esc_html(WOOCOMPORT_VERSION).'</strong> — طراحی شده توسط <a href="tel:09390332600">عباس اکبرآبادی</a></p></div>';
}

function woocomport_render_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'دسترسی غیرمجاز' );
    $notice = ''; $error = ''; $items = array(); $source = '';
    if ( isset( $_POST['woocomport_action'] ) ) {
        check_admin_referer( 'woocomport_action', 'woocomport_nonce' );
        $action = sanitize_key( wp_unslash( $_POST['woocomport_action'] ) );
        $source = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
        if ( 'scan' === $action ) {
            if ( ! wp_http_validate_url( $source ) ) $error = 'آدرس دسته‌بندی معتبر نیست.';
            else {
                $items = woocomport_crawl_categories( $source, 30, 20 );
                if ( ! $items ) $error = 'محصول موجودی پیدا نشد. این اسکن فقط کارت‌های محصول و زیرشاخه‌های مشخص‌شده را بررسی می‌کند؛ صفحات عادی نمایش داده نمی‌شوند و مواردی که موجودی‌شان قابل تأیید نباشد کنار گذاشته می‌شوند.';
            }
        } elseif ( 'import' === $action ) {
            $urls = isset( $_POST['selected_urls'] ) ? array_slice( array_map( 'esc_url_raw', (array) wp_unslash( $_POST['selected_urls'] ) ), 0, 20 ) : array();
            $success = 0; $failed = 0;
            foreach ( $urls as $url ) {
                $page = woocomport_fetch_page( $url );
                if ( is_wp_error( $page ) ) { $failed++; continue; }
                $item = woocomport_extract_product( $page, $url );
                if ( empty( $item['name'] ) || ! woocomport_is_in_stock( $page, $item ) ) { $failed++; continue; }
                $result = woocomport_import_product( $item );
                if ( is_wp_error( $result ) ) $failed++; else $success++;
            }
            $notice = sprintf( 'انتقال تمام شد؛ %d محصول با موفقیت به‌صورت پیش‌نویس ذخیره شد و %d مورد ناموفق بود.', $success, $failed );
            if ( $failed ) $error = 'برای موارد ناموفق، ساختار صفحه یا دسترسی تصویر/محصول باید بررسی شود.';
        }
    }
    $total = count( $items ); $ready = 0; foreach ( $items as $it ) if ( empty( $it['problem'] ) && ! empty( $it['name'] ) ) $ready++;
    echo '<div class="wrap woocomport-wrap" dir="rtl"><style>
    .woocomport-wrap{max-width:1200px}.wcport-head{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;margin:18px 0}.wcport-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.wcport-stat{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px}.wcport-stat b{display:block;font-size:24px;margin-top:7px;color:#1d4e89}.wcport-item{display:grid;grid-template-columns:24px 82px minmax(180px,1fr) 130px 115px 130px;align-items:center;gap:14px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px;margin:10px 0}.wcport-item img{width:78px;height:78px;object-fit:contain;background:#f6f7f7;border-radius:8px}.wcport-item .muted{color:#646970;font-size:12px;overflow-wrap:anywhere}.wcport-price{font-weight:700}.wcport-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:14px 0}.wcport-btn{background:#2271b1!important;color:#fff!important;border:0!important;padding:7px 16px!important;height:auto!important}.wcport-error{color:#b32d2e}.wcport-empty{background:#fff;padding:24px;border:1px dashed #c3c4c7;border-radius:10px;text-align:center}@media(max-width:900px){.wcport-stats{grid-template-columns:repeat(2,1fr)}.wcport-item{grid-template-columns:24px 64px 1fr;gap:9px}.wcport-item img{width:60px;height:60px}.wcport-extra{grid-column:3}.wcport-item .button{grid-column:3;justify-self:start}}
    </style>';
    echo '<div class="wcport-head"><h1>WooComPort | انتقال محصولات به فروشگاه</h1><p>آدرس دسته‌بندی اصلی را وارد کن. فقط کارت‌های محصول موجود و زیرشاخه‌های دسته‌بندی به‌صورت خودکار بررسی می‌شوند؛ صفحات عادی و محصولات ناموجود نمایش داده نمی‌شوند.</p>';
    if ( $notice ) echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
    if ( $error ) echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
    echo '<form method="post">'; wp_nonce_field( 'woocomport_action', 'woocomport_nonce' );
    echo '<p><input type="url" name="source_url" class="large-text" required dir="ltr" placeholder="https://example.com/category/" value="' . esc_attr( $source ) . '"></p><p><button class="button button-primary wcport-btn" name="woocomport_action" value="scan">اسکن و استخراج محصولات</button></p></form></div>';
    if ( $items ) {
        echo '<div class="wcport-stats"><div class="wcport-stat">کل محصولات پیدا‌شده<b>' . esc_html( $total ) . '</b></div><div class="wcport-stat">اطلاعات استخراج‌شده<b>' . esc_html( $ready ) . '</b></div><div class="wcport-stat">نیازمند بررسی<b>' . esc_html( $total - $ready ) . '</b></div><div class="wcport-stat">حداکثر محصولات در هر اسکن<b>۲۰</b></div></div>';
        echo '<form method="post">'; wp_nonce_field( 'woocomport_action', 'woocomport_nonce' ); echo '<input type="hidden" name="woocomport_action" value="import">';
        echo '<div class="wcport-toolbar"><label><input type="checkbox" id="wcport-all"> انتخاب همه موارد قابل انتقال</label><span id="wcport-count">۰ محصول انتخاب شده</span><button type="submit" class="button button-primary wcport-btn">انتقال محصولات انتخاب‌شده به سایتم</button></div>';
        foreach ( $items as $i => $item ) {
            $bad = ! empty( $item['problem'] ) || empty( $item['name'] );
            echo '<div class="wcport-item">';
            echo '<input type="checkbox" class="wcport-check" name="selected_urls[]" value="' . esc_url( $item['url'] ) . '" ' . disabled( $bad, true, false ) . '>';
            if ( ! empty( $item['image'] ) ) echo '<img src="' . esc_url( $item['image'] ) . '" alt="" loading="lazy">'; else echo '<div style="width:78px;height:78px;background:#f0f0f1;border-radius:8px;display:grid;place-items:center;color:#777">بدون تصویر</div>';
            echo '<div><strong>' . esc_html( $item['name'] ) . '</strong><div class="muted" dir="ltr">' . esc_html( $item['url'] ) . '</div>';
            if ( $bad ) echo '<div class="wcport-error">' . esc_html( $item['problem'] ?? 'اطلاعات محصول کامل نیست' ) . '</div>';
            echo '</div><div class="wcport-extra"><span class="muted">قیمت</span><div class="wcport-price">' . ( $item['price'] !== '' ? esc_html( number_format_i18n( (float) $item['price'] ) ) . ' ' : 'نامشخص' ) . '</div></div>';
            echo '<div class="wcport-extra"><span class="muted">وضعیت مبدا</span><div>' . ( ! empty( $item['availability'] ) && stripos( $item['availability'], 'OutOfStock' ) !== false ? 'ناموجود' : ( ! empty( $item['availability'] ) ? 'موجود' : 'بررسی‌نشده' ) ) . '</div></div>';
            echo '<div class="wcport-extra"><a class="button" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener">مشاهده محصول</a></div></div>';
        }
        echo '<div class="wcport-toolbar"><button type="submit" class="button button-primary wcport-btn">انتقال محصولات انتخاب‌شده به سایتم</button></div></form>';
        echo '<script>(function(){var all=document.getElementById("wcport-all"),checks=[].slice.call(document.querySelectorAll(".wcport-check")),count=document.getElementById("wcport-count");function update(){var n=checks.filter(function(c){return c.checked;}).length;count.textContent=n+" محصول انتخاب شده";if(n>20){count.style.color="#b32d2e";count.textContent="حداکثر ۲۰ محصول؛ "+n+" مورد انتخاب شده";}else{count.style.color="";}}if(all){all.addEventListener("change",function(){checks.forEach(function(c){if(!c.disabled)c.checked=all.checked;});update();});}checks.forEach(function(c){c.addEventListener("change",function(){if(checks.filter(function(x){return x.checked;}).length>20){c.checked=false;alert("در هر نوبت حداکثر ۲۰ محصول انتخاب کن.");}update();});});update();})();</script>';
    } else {
        echo '<div class="wcport-empty">هنوز محصولی نمایش داده نشده است. ابتدا آدرس صفحه دسته‌بندی را وارد و روی «اسکن و استخراج محصولات» بزن.</div>';
    }
    echo '<hr><p><strong>WooComPort '.esc_html(WOOCOMPORT_VERSION).'</strong> — طراحی شده توسط <a href="tel:09390332600">عباس اکبرآبادی</a></p></div>';
}