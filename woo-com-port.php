<?php
/**
 * Plugin Name: WooComPort
 * Description: Import product details from public product pages into WooCommerce drafts.
 * Version: 1.0.0
 * Author: Abbas Akbarabadi
 * Author URI: https://github.com/arsooy
 * Text Domain: woo-com-port
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOOCOMPORT_VERSION', '1.0.0' );

add_action( 'admin_menu', function () {
	add_submenu_page(
		'woocommerce',
		__( 'WooComPort', 'woo-com-port' ),
		__( 'WooComPort', 'woo-com-port' ),
		'manage_woocommerce',
		'woo-com-port',
		'woocomport_render_page'
	);
} );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) || class_exists( 'WooCommerce' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' .
		esc_html__( 'WooComPort requires WooCommerce to be active before products can be saved.', 'woo-com-port' ) .
		'</p></div>';
} );

/**
 * Fetch a public page while rejecting unsafe/private network destinations.
 */
function woocomport_fetch_page( $url ) {
	$url = esc_url_raw( trim( (string) $url ) );
	if ( ! $url || ! wp_http_validate_url( $url ) ) {
		return new WP_Error( 'invalid_url', __( 'Please enter a valid public URL.', 'woo-com-port' ) );
	}

	$response = wp_safe_remote_get( $url, array(
		'timeout'             => 20,
		'redirection'         => 3,
		'limit_response_size' => 3 * MB_IN_BYTES,
		'headers'             => array( 'User-Agent' => 'WooComPort/1.0 (+WordPress product importer)' ),
	) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'http_error', sprintf( __( 'The source website returned HTTP %d.', 'woo-com-port' ), $code ) );
	}
	return wp_remote_retrieve_body( $response );
}

/**
 * Extract common product fields from Open Graph, meta tags and Product JSON-LD.
 * This is a best-effort generic parser; sites with custom markup may need manual edits.
 */
function woocomport_extract_product( $html, $source_url ) {
	$data = array(
		'name'        => '',
		'description' => '',
		'price'       => '',
		'sku'         => '',
		'image'       => '',
		'product_url' => $source_url,
	);

	if ( ! class_exists( 'DOMDocument' ) ) {
		return $data;
	}

	$previous = libxml_use_internal_errors( true );
	$dom      = new DOMDocument();
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	$xpath    = new DOMXPath( $dom );

	$meta = array();
	foreach ( $xpath->query( '//meta[@property or @name]' ) as $node ) {
		$key = strtolower( $node->getAttribute( 'property' ) ?: $node->getAttribute( 'name' ) );
		$meta[ $key ] = trim( $node->getAttribute( 'content' ) );
	}

	$data['name']        = $meta['og:title'] ?? ( $meta['twitter:title'] ?? '' );
	$data['description'] = $meta['og:description'] ?? ( $meta['description'] ?? '' );
	$data['image']       = $meta['og:image'] ?? ( $meta['twitter:image'] ?? '' );
	$data['price']       = $meta['product:price:amount'] ?? ( $meta['og:price:amount'] ?? '' );

	foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $script ) {
		$json = json_decode( $script->textContent, true );
		if ( ! is_array( $json ) ) {
			continue;
		}
		$nodes = isset( $json['@graph'] ) && is_array( $json['@graph'] ) ? $json['@graph'] : array( $json );
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = $node['@type'] ?? '';
			if ( is_array( $type ) ) {
				$type = implode( ' ', $type );
			}
			if ( stripos( (string) $type, 'Product' ) === false ) {
				continue;
			}
			$data['name']        = $data['name'] ?: ( $node['name'] ?? '' );
			$data['description'] = $data['description'] ?: ( $node['description'] ?? '' );
			$data['sku']         = $node['sku'] ?? '';
			if ( empty( $data['image'] ) && ! empty( $node['image'] ) ) {
				$image = $node['image'];
				$data['image'] = is_array( $image ) ? ( $image[0] ?? '' ) : $image;
				if ( is_array( $data['image'] ) ) {
					$data['image'] = $data['image']['url'] ?? '';
				}
			}
			if ( empty( $data['price'] ) && ! empty( $node['offers'] ) ) {
				$offers = isset( $node['offers'][0] ) ? $node['offers'][0] : $node['offers'];
				$data['price'] = is_array( $offers ) ? ( $offers['price'] ?? '' ) : '';
			}
			break;
		}
	}

	if ( empty( $data['name'] ) ) {
		$title = $xpath->query( '//title' );
		$data['name'] = $title->length ? trim( $title->item( 0 )->textContent ) : '';
	}
	if ( empty( $data['description'] ) ) {
		$description = $xpath->query( '//meta[@name="description"]/@content' );
		$data['description'] = $description->length ? trim( $description->item( 0 )->nodeValue ) : '';
	}

	$data['name']        = sanitize_text_field( html_entity_decode( wp_strip_all_tags( $data['name'] ) ) );
	$data['description'] = sanitize_textarea_field( html_entity_decode( wp_strip_all_tags( $data['description'] ) ) );
	$data['price']       = preg_replace( '/[^0-9.]/', '', (string) $data['price'] );
	$data['sku']         = sanitize_text_field( (string) $data['sku'] );
	$data['image']       = esc_url_raw( (string) $data['image'] );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	return $data;
}

/**
 * Find likely product links on a category/listing page (maximum 20).
 */
function woocomport_find_product_links( $html, $base_url ) {
	$links = array();
	if ( ! class_exists( 'DOMDocument' ) ) {
		return $links;
	}
	$previous = libxml_use_internal_errors( true );
	$dom      = new DOMDocument();
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	$xpath = new DOMXPath( $dom );
	$host  = wp_parse_url( $base_url, PHP_URL_HOST );

	foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
		$href = trim( $anchor->getAttribute( 'href' ) );
		if ( ! $href || strpos( $href, '#' ) === 0 || stripos( $href, 'javascript:' ) === 0 ) {
			continue;
		}
		if ( wp_parse_url( $href, PHP_URL_SCHEME ) ) {
			$resolved = $href;
		} else {
			$base_parts = wp_parse_url( $base_url );
			$origin     = ( $base_parts['scheme'] ?? 'https' ) . '://' . ( $base_parts['host'] ?? '' );
			if ( 0 === strpos( $href, '//' ) ) {
				$resolved = ( $base_parts['scheme'] ?? 'https' ) . ':' . $href;
			} elseif ( 0 === strpos( $href, '/' ) ) {
				$resolved = $origin . $href;
			} else {
				$base_path = $base_parts['path'] ?? '/';
				$directory = trailingslashit( dirname( $base_path ) );
				$resolved  = $origin . $directory . $href;
			}
		}
		$url = esc_url_raw( (string) $resolved );
		if ( ! wp_http_validate_url( $url ) || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) $host ) ) {
			continue;
		}
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( preg_match( '~/(category|tag|page|cart|checkout|my-account)(/|$)~', $path ) ) {
			continue;
		}
		if ( strlen( $path ) < 3 ) {
			continue;
		}
		$links[ $url ] = sanitize_text_field( trim( $anchor->textContent ) );
		if ( count( $links ) >= 20 ) {
			break;
		}
	}
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	return $links;
}

function woocomport_render_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You are not allowed to access this page.', 'woo-com-port' ) );
	}

	$message = '';
	$error   = '';
	$preview = null;
	$links   = array();
	$source  = '';

	if ( isset( $_POST['woocomport_action'] ) ) {
		check_admin_referer( 'woocomport_action', 'woocomport_nonce' );
		$action = sanitize_key( wp_unslash( $_POST['woocomport_action'] ) );
		$source = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
		$html   = woocomport_fetch_page( $source );

		if ( is_wp_error( $html ) ) {
			$error = $html->get_error_message();
		} elseif ( 'scan' === $action ) {
			$links = woocomport_find_product_links( $html, $source );
			if ( empty( $links ) ) {
				$error = __( 'No likely product links were found. You can still try a direct product URL.', 'woo-com-port' );
			}
		} elseif ( 'preview' === $action ) {
			$preview = woocomport_extract_product( $html, $source );
			if ( empty( $preview['name'] ) ) {
				$error = __( 'Product details could not be detected. Try another URL or enter details manually after preview.', 'woo-com-port' );
			}
		} elseif ( 'save' === $action ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				$error = __( 'Activate WooCommerce before saving products.', 'woo-com-port' );
			} else {
				$name = isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['product_name'] ) ) : '';
				$description = isset( $_POST['product_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['product_description'] ) ) : '';
				$price = isset( $_POST['product_price'] ) ? wc_format_decimal( wp_unslash( $_POST['product_price'] ) ) : '';
				$sku = isset( $_POST['product_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['product_sku'] ) ) : '';
				$image = isset( $_POST['product_image'] ) ? esc_url_raw( wp_unslash( $_POST['product_image'] ) ) : '';
				if ( '' === $name ) {
					$error = __( 'Product name is required.', 'woo-com-port' );
				} else {
					$product = new WC_Product_Simple();
					$product->set_name( $name );
					$product->set_status( 'draft' );
					$product->set_description( $description );
					if ( '' !== $price ) {
						$product->set_regular_price( $price );
					}
					if ( '' !== $sku && ! wc_get_product_id_by_sku( $sku ) ) {
						$product->set_sku( $sku );
					}
					$product_id = $product->save();
					if ( $product_id ) {
						update_post_meta( $product_id, '_woocomport_source_url', $source );
						if ( $image ) {
							update_post_meta( $product_id, '_woocomport_source_image', $image );
						}
						$message = sprintf( __( 'Product saved as draft. Product ID: %d', 'woo-com-port' ), $product_id );
					} else {
						$error = __( 'The product could not be saved.', 'woo-com-port' );
					}
				}
			}
		}
	}

	echo '<div class="wrap"><h1>WooComPort</h1>';
	echo '<p>' . esc_html__( 'Import products from a public product URL or scan a category page. Review and edit the extracted fields before saving as a WooCommerce draft.', 'woo-com-port' ) . '</p>';
	if ( $message ) {
		echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
	}
	if ( $error ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
	}

	echo '<h2>' . esc_html__( '1. Source URL', 'woo-com-port' ) . '</h2>';
	echo '<form method="post">';
	wp_nonce_field( 'woocomport_action', 'woocomport_nonce' );
	echo '<p><input type="url" name="source_url" class="large-text" required placeholder="https://example.com/product/..." value="' . esc_attr( $source ) . '"></p>';
	echo '<p><button class="button button-primary" name="woocomport_action" value="preview">' . esc_html__( 'Fetch product preview', 'woo-com-port' ) . '</button> ';
	echo '<button class="button" name="woocomport_action" value="scan">' . esc_html__( 'Scan category page (up to 20 links)', 'woo-com-port' ) . '</button></p></form>';

	if ( $links ) {
		echo '<h2>' . esc_html__( '2. Links found', 'woo-com-port' ) . '</h2><ol>';
		foreach ( $links as $url => $label ) {
			echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ?: $url ) . '</a> — <code>' . esc_html( $url ) . '</code></li>';
		}
		echo '</ol><p>' . esc_html__( 'Copy a product URL above into the source field to preview it individually.', 'woo-com-port' ) . '</p>';
	}

	if ( is_array( $preview ) ) {
		echo '<h2>' . esc_html__( '3. Review and edit', 'woo-com-port' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'woocomport_action', 'woocomport_nonce' );
		echo '<input type="hidden" name="source_url" value="' . esc_attr( $source ) . '">';
		echo '<input type="hidden" name="woocomport_action" value="save">';
		echo '<table class="form-table"><tbody>';
		$fields = array(
			'product_name' => array( __( 'Product name', 'woo-com-port' ), $preview['name'], 'text' ),
			'product_description' => array( __( 'Description', 'woo-com-port' ), $preview['description'], 'textarea' ),
			'product_price' => array( __( 'Regular price', 'woo-com-port' ), $preview['price'], 'text' ),
			'product_sku' => array( __( 'SKU', 'woo-com-port' ), $preview['sku'], 'text' ),
			'product_image' => array( __( 'Image URL (reference only)', 'woo-com-port' ), $preview['image'], 'url' ),
		);
		foreach ( $fields as $key => $field ) {
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th><td>';
			if ( 'textarea' === $field[2] ) {
				echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="5" class="large-text">' . esc_textarea( $field[1] ) . '</textarea>';
			} else {
				echo '<input id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $field[2] ) . '" class="regular-text" value="' . esc_attr( $field[1] ) . '">';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table><p><button class="button button-primary" type="submit">' . esc_html__( 'Save as WooCommerce draft', 'woo-com-port' ) . '</button></p></form>';
	}
	echo '<hr><p><strong>WooComPort 1.0.0</strong> — ' . esc_html__( 'Designed by Abbas Akbarabadi', 'woo-com-port' ) . ' — <a href="tel:09390332600">09390332600</a></p>';
	echo '</div>';
}
