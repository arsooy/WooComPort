<?php
/**
 * Plugin Name: WooComPort
 * Description: ابزار فارسی استخراج و انتقال محصولات از صفحات عمومی به ووکامرس.
 * Version: 1.2.1
 * Author: Abbas Akbarabadi
 * Author URI: https://github.com/arsooy
 * Text Domain: woo-com-port
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'WOOCOMPORT_VERSION', '1.2.1' );

add_action( 'admin_menu', function () {
    add_submenu_page( 'woocommerce', 'WooComPort', 'WooComPort', 'manage_woocommerce', 'woo-com-port', 'woocomport_render_page' );
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
    $out = array( 'name'=>'', 'description'=>'', 'price'=>'', 'sku'=>'', 'image'=>'', 'url'=>$url, 'availability'=>'' );
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
    $out['name'] = $meta['og:title'] ?? ( $meta['twitter:title'] ?? '' );
    $out['description'] = $meta['og:description'] ?? ( $meta['description'] ?? '' );
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
            $out['description'] = $out['description'] ?: ( $node['description'] ?? '' );
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
    if ( ! $out['name'] ) { $n = $xp->query( '//h1' ); if ( $n->length ) $out['name'] = trim( $n->item(0)->textContent ); }
    if ( ! $out['name'] ) { $n = $xp->query( '//title' ); if ( $n->length ) $out['name'] = trim( $n->item(0)->textContent ); }
    $out['name'] = sanitize_text_field( html_entity_decode( wp_strip_all_tags( $out['name'] ) ) );
    $out['description'] = sanitize_textarea_field( html_entity_decode( wp_strip_all_tags( $out['description'] ) ) );
    $out['price'] = woocomport_normalize_number( $out['price'] );
    $out['sku'] = sanitize_text_field( (string) $out['sku'] );
    $out['image'] = esc_url_raw( (string) $out['image'] );
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
            $is_product = ( ! empty( $item['name'] ) && ( stripos( $product_html, '"@type":"Product"' ) !== false || stripos( $product_html, '"@type": "Product"' ) !== false || preg_match( '/class="[^"]*\bproduct\b/u', $product_html ) || preg_match( '/woocommerce-product-gallery|single_add_to_cart_button|product_meta/u', $product_html ) ) );
            if ( ! $is_product || ! woocomport_is_in_stock( $product_html, $item ) ) continue;
            $item['problem'] = '';
            $products[$product_url] = $item;
        }
    }
    return array_values( $products );
}

function woocomport_import_product( $item ) {
    if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'no_wc', 'ووکامرس فعال نیست.' );
    $product = new WC_Product_Simple();
    $product->set_name( $item['name'] );
    $product->set_status( 'draft' );
    $product->set_description( $item['description'] );
    if ( $item['price'] !== '' ) $product->set_regular_price( wc_format_decimal( $item['price'] ) );
    if ( ! empty( $item['sku'] ) && ! wc_get_product_id_by_sku( $item['sku'] ) ) $product->set_sku( $item['sku'] );
    $id = $product->save();
    if ( ! $id ) return new WP_Error( 'save_failed', 'ذخیره محصول در ووکامرس ناموفق بود.' );
    update_post_meta( $id, '_woocomport_source_url', esc_url_raw( $item['url'] ) );
    if ( ! empty( $item['image'] ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment = media_sideload_image( esc_url_raw( $item['image'] ), $id, $item['name'], 'id' );
        if ( ! is_wp_error( $attachment ) ) set_post_thumbnail( $id, $attachment );
        else update_post_meta( $id, '_woocomport_image_import_error', $attachment->get_error_message() );
    }
    return $id;
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
                $items = woocomport_crawl_categories( $source, 30, 200 );
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
        echo '<div class="wcport-stats"><div class="wcport-stat">کل محصولات پیدا‌شده<b>' . esc_html( $total ) . '</b></div><div class="wcport-stat">اطلاعات استخراج‌شده<b>' . esc_html( $ready ) . '</b></div><div class="wcport-stat">نیازمند بررسی<b>' . esc_html( $total - $ready ) . '</b></div><div class="wcport-stat">حداکثر انتقال در هر نوبت<b>۲۰</b></div></div>';
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
    echo '<hr><p><strong>WooComPort 1.2.1</strong> — طراحی شده توسط <a href="tel:09390332600">عباس اکبرآبادی</a></p></div>';
}