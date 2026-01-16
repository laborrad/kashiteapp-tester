<?php
/**
 * Plugin Name: KASHITE App API
 * Description: KASHITE アプリ向け REST API 一式 (v1.0.0)
 * Author: LABORRAD
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KASHITEAPP_API_VERSION', '1.0.0' );
define( 'KASHITEAPP_REGULATION_VERSION', '1.0.0' );
define( 'KASHITEAPP_ENQUIRY_TTL', 10 * 60 ); // 10分

/**
 * 共通レスポンスヘルパ
 */
function kashiteapp_response( $result, $message = 'OK', $code = 'success', $status = 200 ) {
    $body = [
        'code'    => $code,
        'message' => $message,
        'data'    => [
            'status' => $status,
            'result' => $result,
        ],
    ];
    return new WP_REST_Response( $body, $status );
}

/**
 * エラーレスポンスヘルパ
 */
function kashiteapp_error_response( $code, $message, $status = 400, $extra = [] ) {
    $body = [
        'code'    => $code,
        'message' => $message,
        'data'    => array_merge(
            [
                'status' => $status,
            ],
            $extra
        ),
    ];
    return new WP_REST_Response( $body, $status );
}

/**
 * WOOF の settings から tax_keys を取るヘルパ
 *
 * - パターンA: $settings['tax'] = [ 'pa_city' => ..., ... ]
 * - パターンB: $settings['tax_keys'] = [ 'pa_city', ... ]
 * 両方あればマージしてユニーク。
 */
function kashiteapp_get_woof_tax_keys() {
    $settings = get_option( 'woof_settings' );
    if ( ! is_array( $settings ) ) {
        return [];
    }

    // パターンA: tax 配下のキー
	$keys = [];
	
	if (!empty($settings['tax']) && is_array($settings['tax'])) {
	  $keys = array_keys($settings['tax']);
	}
	if (!empty($settings['tax_keys']) && is_array($settings['tax_keys'])) {
	  $keys = array_merge($keys, $settings['tax_keys']);
	}
	
    $keys = array_keys( $settings['tax'] );

    // パターンB: tax_keys 配下の配列
    if ( ! empty( $settings['tax_keys'] ) && is_array( $settings['tax_keys'] ) ) {
        $keys = array_merge( $keys, $settings['tax_keys'] );
    }

    // 文字列だけ抽出してユニーク化
    $keys = array_values(
        array_unique(
            array_filter(
                $keys,
                'is_string'
            )
        )
    );

    return $keys;
}

/**
 * この taxonomy が WOOF 上で有効かどうか
 *
 * - 明示的に 0 / '' になっている → false
 * - 明示的に 1 → true
 * - それ以外 → とりあえず true（WOOF 側に任せる）
 */
function kashiteapp_is_woof_tax_enabled( $taxonomy ) {
    $settings = get_option('woof_settings');
    if ( !is_array($settings) || empty($settings['tax']) || !is_array($settings['tax']) ) return false;

    if ( !array_key_exists($taxonomy, $settings['tax']) ) return false;

    $v = (string)$settings['tax'][$taxonomy];
    if ( $v === '' || $v === '0' ) return false;
    if ( $v === '1' ) return true;

    return true; // それ以外はtrue
}


function kashiteapp_is_woof_term_visible( $taxonomy, $term_id ) {
    $settings = get_option( 'woof_settings' );
    if ( ! is_array( $settings ) ) {
        return true; // 設定が無いなら全表示
    }

    // 除外設定が無いtaxonomy → 全表示
    if ( empty( $settings['excluded_terms'][ $taxonomy ] ) ) {
        return true;
    }

    $excluded = $settings['excluded_terms'][ $taxonomy ];

    // slug列 "slug-a,slug-b" → 配列化
    $slugs = array_filter( array_map( 'trim', explode( ',', $excluded ) ) );
    if ( empty( $slugs ) ) {
        return true;
    }

    // term の slug を取得
    $term = get_term( $term_id, $taxonomy );
    if ( ! $term || is_wp_error( $term ) ) {
        return true;
    }

    return ! in_array( $term->slug, $slugs, true );
}

function kashiteapp_get_woof_term_settings( $taxonomy, $term_id ) {
    $settings = get_option( 'woof_settings' );
    if ( ! is_array( $settings ) ) {
        return null;
    }

    // 除外されているかどうかだけ返す
    $visible = kashiteapp_is_woof_term_visible( $taxonomy, $term_id );

    return [
        'taxonomy' => $taxonomy,
        'term_id'  => $term_id,
        'visible'  => $visible,
    ];
}

/**
 * /filters 用：taxonomy から { key, taxonomy, label, items } を組み立てる
 */
function kashiteapp_build_filter_block( $taxonomy ) {
    // pa_city → city みたいな短縮キー
    $short_key = ( strpos( $taxonomy, 'pa_' ) === 0 )
        ? substr( $taxonomy, 3 )
        : $taxonomy;

    // ラベルは WooCommerce の属性ラベルを頼れるだけ頼る
    $label = $short_key;
    if ( function_exists( 'wc_attribute_label' ) ) {
        $maybe = wc_attribute_label( $taxonomy );
        if ( ! empty( $maybe ) ) {
            $label = $maybe;
        }
    }

    return [
        'key'      => $short_key,
        'taxonomy' => $taxonomy,
        'label'    => $label,
        'items'    => kashiteapp_get_terms_simple( $taxonomy ),
    ];
}

/**
 * taxonomy term 一覧 → [ [key, label], ... ]
 */
function kashiteapp_get_terms_simple( $taxonomy ) {
    $terms = get_terms(
        [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]
    );

    if ( is_wp_error( $terms ) ) {
        return [];
    }

    $items = [];
    foreach ( $terms as $term ) {
        // WOOF 設定で非表示ならスキップ
        if ( ! kashiteapp_is_woof_term_visible( $taxonomy, $term->term_id ) ) {
            continue;
        }

        $items[] = [
            'key'   => $term->slug,
            'label' => $term->name,
            // 中身を見たいので、そのまま載せる
            'woof'  => kashiteapp_get_woof_term_settings( $taxonomy, $term->term_id ),
        ];
    }
    return $items;
}

/**
 * /filters
 */
function kashiteapp_api_filters( WP_REST_Request $request ) {
    $tax_keys = kashiteapp_get_woof_tax_keys();
    $result   = [];

    foreach ( $tax_keys as $tax ) {
        if ( $tax === 'product_cat' ) {
            continue;
        }

        if ( ! kashiteapp_is_woof_tax_enabled( $tax ) ) {
            continue;
        }

        // "city": { key, taxonomy, label, items: [...] }
        $block = kashiteapp_build_filter_block( $tax );
        $result[ $block['key'] ] = $block;
    }

    return kashiteapp_response( $result, 'OK' );
}

/**
 * /ping
 */
function kashiteapp_api_ping( WP_REST_Request $request ) {
    $result = [
        'time'                => current_time( 'mysql' ),
        'api_version'         => KASHITEAPP_API_VERSION,
        'regulation_version'  => KASHITEAPP_REGULATION_VERSION,
    ];
    return kashiteapp_response( $result, 'pong' );
}

/**
 * カテゴリ画像（サムネイル）を取得
 */
function kashiteapp_get_term_thumbnail( $term_id, $size = 'medium' ) {
    $thumb_id = get_term_meta( $term_id, 'thumbnail_id', true );
    if ( ! $thumb_id ) {
        return null;
    }

    $src = wp_get_attachment_image_url( $thumb_id, $size );
    if ( ! $src ) {
        return null;
    }

    return [
        'id'  => (int) $thumb_id,
        'src' => $src,
        'alt' => get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
    ];
}

/**
 * /option_space_use
 *
 * product_cat の親カテゴリ "space_use" の子供一覧
 */
function kashiteapp_api_option_space_use( WP_REST_Request $request ) {
    $parent = get_term_by( 'slug', 'space_use', 'product_cat' );
    if ( ! $parent ) {
        return kashiteapp_response( [], 'OK' );
    }

    $terms = get_terms(
        [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'parent'     => $parent->term_id,
        ]
    );
    if ( is_wp_error( $terms ) ) {
        return kashiteapp_response( [], 'OK' );
    }

    $items = [];
    foreach ( $terms as $term ) {
        // WOOF 設定で product_cat のこの term が非表示なら除外
        if ( ! kashiteapp_is_woof_term_visible( 'product_cat', $term->term_id ) ) {
            continue;
        }

        // 利用目的の可視フラグ: use_ から始まるものだけ true
        $visible_for_app = ( strpos( $term->slug, 'use_' ) === 0 );

        $items[] = [
            'key'       => $term->slug,
            'label'     => $term->name,
            'url'       => get_term_link( $term ),
            'thumbnail' => kashiteapp_get_term_thumbnail( $term->term_id ),
            'visible'   => $visible_for_app,
            'woof'      => kashiteapp_get_woof_term_settings( 'product_cat', $term->term_id ),
        ];
    }

    return kashiteapp_response( $items, 'OK' );
}

/**
 * /option_space_type
 *
 * product_cat の親カテゴリ "space_type" の子供一覧
 */
function kashiteapp_api_option_space_type( WP_REST_Request $request ) {
    $parent = get_term_by( 'slug', 'space_type', 'product_cat' );
    if ( ! $parent ) {
        return kashiteapp_response( [], 'OK' );
    }

    $terms = get_terms(
        [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'parent'     => $parent->term_id,
        ]
    );
    if ( is_wp_error( $terms ) ) {
        return kashiteapp_response( [], 'OK' );
    }

    $items = [];
    foreach ( $terms as $term ) {
        // WOOF 設定で product_cat のこの term が非表示なら除外
        if ( ! kashiteapp_is_woof_term_visible( 'product_cat', $term->term_id ) ) {
            continue;
        }

        $items[] = [
            'key'       => $term->slug,
            'label'     => $term->name,
            'url'       => get_term_link( $term ),
            'thumbnail' => kashiteapp_get_term_thumbnail( $term->term_id ),
            'visible'   => true,
            'woof'      => kashiteapp_get_woof_term_settings( 'product_cat', $term->term_id ),
        ];
    }

    return kashiteapp_response( $items, 'OK' );
}

/**
 * /option_space_area
 *
 * product_cat の親カテゴリ "space_area" の子供（エリア）と、
 * さらにその子供（市町村など）までまとめて返す。
 */
function kashiteapp_api_option_space_area( WP_REST_Request $request ) {
    // 親カテゴリ "space_area" を取得
    $parent = get_term_by( 'slug', 'space_area', 'product_cat' );
    if ( ! $parent ) {
        return kashiteapp_response( [], 'OK' );
    }

    // 第1階層: エリア（例: 茨城県）
    $areas = get_terms(
        [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'parent'     => $parent->term_id,
        ]
    );
    if ( is_wp_error( $areas ) || empty( $areas ) ) {
        return kashiteapp_response( [], 'OK' );
    }

    $items = [];

    foreach ( $areas as $area ) {
        // 親エリア自体が WOOF で隠されていたらスキップ
        if ( ! kashiteapp_is_woof_term_visible( 'product_cat', $area->term_id ) ) {
            continue;
        }

        $area_thumb = kashiteapp_get_term_thumbnail( $area->term_id );

        $children_terms = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'parent'     => $area->term_id,
            ]
        );
        $children = [];

        if ( ! is_wp_error( $children_terms ) && ! empty( $children_terms ) ) {
            foreach ( $children_terms as $child ) {
                if ( ! kashiteapp_is_woof_term_visible( 'product_cat', $child->term_id ) ) {
                    continue;
                }

                $child_thumb = kashiteapp_get_term_thumbnail( $child->term_id );

                $children[] = [
                    'key'       => $child->slug,
                    'label'     => $child->name,
                    'url'       => get_term_link( $child ),
                    'thumbnail' => $child_thumb,
                    'visible'   => true,
                    'woof'      => kashiteapp_get_woof_term_settings( 'product_cat', $child->term_id ),
                ];
            }
        }

        $items[] = [
            'key'       => $area->slug,
            'label'     => $area->name,
            'url'       => get_term_link( $area ),
            'thumbnail' => $area_thumb,
            'children'  => $children,
            'visible'   => true,
            'woof'      => kashiteapp_get_woof_term_settings( 'product_cat', $area->term_id ),
        ];
    }

    return kashiteapp_response( $items, 'OK' );
}

/**
 * /price_range
 *
 * _price の MIN / MAX をざっくり取得
 */
function kashiteapp_api_price_range( WP_REST_Request $request ) {
    global $wpdb;

    $row = $wpdb->get_row(
        "SELECT 
            MIN( CAST(meta_value AS DECIMAL(10,2)) ) AS min_price,
            MAX( CAST(meta_value AS DECIMAL(10,2)) ) AS max_price
         FROM {$wpdb->postmeta}
         WHERE meta_key = '_price'
           AND meta_value IS NOT NULL
           AND meta_value != ''"
    );

    if ( ! $row || $row->min_price === null || $row->max_price === null ) {
        $result = [
            'theoretical_min' => 0,
            'theoretical_max' => 0,
            'real_min'        => 0,
            'real_max'        => 0,
        ];
        return kashiteapp_response( $result, 'OK' );
    }

    $min = (int) $row->min_price;
    $max = (int) $row->max_price;

    $result = [
        'theoretical_min' => $min,
        'theoretical_max' => $max,
        'real_min'        => $min,
        'real_max'        => $max,
    ];

    return kashiteapp_response( $result, 'OK' );
}

/**
 * /search_url
 *
 * フロントの /shop/ 用 URL を生成するだけ
 */
function kashiteapp_api_search_url( WP_REST_Request $request ) {
    $params = [
        'keyword'        => $request->get_param( 'keyword' ),
        'space_type'     => $request->get_param( 'space_type' ),
        'space_use'      => $request->get_param( 'space_use' ),
        'space_area'     => $request->get_param( 'space_area' ),
        'city'           => $request->get_param( 'city' ),
        'indoor_outdoor' => $request->get_param( 'indoor_outdoor' ),
        'station'        => $request->get_param( 'station' ),
        'parking'        => $request->get_param( 'parking' ),
        'number_people'  => $request->get_param( 'number_people' ),
        'purpose_use'    => $request->get_param( 'purpose_use' ),
        'eating_drink'   => $request->get_param( 'eating_drink' ),
        'facility'       => $request->get_param( 'facility' ),
        'payment'        => $request->get_param( 'payment' ),
        'price_min'      => $request->get_param( 'price_min' ),
        'price_max'      => $request->get_param( 'price_max' ),
    ];

    $product_cat = [];

    if ( ! empty( $params['space_type'] ) ) {
        $product_cat = array_merge(
            $product_cat,
            array_filter( array_map( 'trim', explode( ',', $params['space_type'] ) ) )
        );
    }
    if ( ! empty( $params['space_use'] ) ) {
        $product_cat = array_merge(
            $product_cat,
            array_filter( array_map( 'trim', explode( ',', $params['space_use'] ) ) )
        );
    }
    if ( ! empty( $params['space_area'] ) ) {
        $product_cat = array_merge(
            $product_cat,
            array_filter( array_map( 'trim', explode( ',', $params['space_area'] ) ) )
        );
    }
    if ( ! empty( $params['purpose_use'] ) ) {
        $product_cat = array_merge(
            $product_cat,
            array_filter( array_map( 'trim', explode( ',', $params['purpose_use'] ) ) )
        );
    }
    $product_cat = array_unique( $product_cat );

    $query = [
        'swoof' => 1,
    ];

    if ( ! empty( $params['keyword'] ) ) {
        $query['s'] = $params['keyword'];
    }
    if ( ! empty( $product_cat ) ) {
        $query['product_cat'] = implode( ',', $product_cat );
    }

    if ( ! empty( $params['city'] ) ) {
        $query['pa_city'] = $params['city'];
    }
    if ( ! empty( $params['indoor_outdoor'] ) ) {
        $query['pa_indoor-outdoor'] = $params['indoor_outdoor'];
    }
    if ( ! empty( $params['station'] ) ) {
        $query['pa_station'] = $params['station'];
    }
    if ( ! empty( $params['parking'] ) ) {
        $query['pa_parking'] = $params['parking'];
    }
    if ( ! empty( $params['number_people'] ) ) {
        $query['pa_number-people'] = $params['number_people'];
    }
    if ( ! empty( $params['eating_drink'] ) ) {
        $query['pa_eating-drink'] = $params['eating_drink'];
    }
    if ( ! empty( $params['facility'] ) ) {
        $query['pa_facility'] = $params['facility'];
    }
    if ( ! empty( $params['payment'] ) ) {
        $query['pa_payment'] = $params['payment'];
    }

    if ( ! empty( $params['price_min'] ) ) {
        $query['min_price'] = $params['price_min'];
    }
    if ( ! empty( $params['price_max'] ) ) {
        $query['max_price'] = $params['price_max'];
    }

    $base_url = site_url( '/shop/' );
    $url      = add_query_arg( $query, $base_url );

    $result = [
        'url'   => $url,
        'input' => $params,
    ];

    return kashiteapp_response( $result, 'OK' );
}

/**
 * /search_results
 *
 * search_url で生成した URL をパースし、商品一覧を JSON で返す
 */
function kashiteapp_api_search_results( WP_REST_Request $request ) {
    $url = $request->get_param( 'url' );
    if ( empty( $url ) ) {
        return kashiteapp_error_response( 'invalid_params', 'url is required', 400 );
    }

    $parsed = wp_parse_url( $url );
    $qargs  = [];
    if ( ! empty( $parsed['query'] ) ) {
        parse_str( $parsed['query'], $qargs );
    }

    $tax_query = [];

    // product_cat
    if ( ! empty( $qargs['product_cat'] ) ) {
        $slugs = array_filter( array_map( 'trim', explode( ',', $qargs['product_cat'] ) ) );
        if ( $slugs ) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $slugs,
            ];
        }
    }

    // 属性系
    $attr_taxonomies = [
        'pa_city',
        'pa_indoor-outdoor',
        'pa_station',
        'pa_parking',
        'pa_number-people',
        'pa_eating-drink',
        'pa_facility',
        'pa_payment',
    ];

    foreach ( $attr_taxonomies as $tax ) {
        if ( ! empty( $qargs[ $tax ] ) ) {
            $values = array_filter( array_map( 'trim', explode( ',', $qargs[ $tax ] ) ) );
            if ( $values ) {
                $tax_query[] = [
                    'taxonomy' => $tax,
                    'field'    => 'slug',
                    'terms'    => $values,
                ];
            }
        }
    }

    if ( count( $tax_query ) > 1 ) {
        $tax_query['relation'] = 'AND';
    }

    $meta_query = [];

    // min_price / max_price
    $min_price = isset( $qargs['min_price'] ) ? (int) $qargs['min_price'] : 0;
    $max_price = isset( $qargs['max_price'] ) ? (int) $qargs['max_price'] : 0;

    if ( $min_price > 0 || $max_price > 0 ) {
        if ( $min_price > 0 && $max_price > 0 ) {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => [ $min_price, $max_price ],
                'type'    => 'NUMERIC',
                'compare' => 'BETWEEN',
            ];
        } elseif ( $min_price > 0 ) {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => $min_price,
                'type'    => 'NUMERIC',
                'compare' => '>=',
            ];
        } elseif ( $max_price > 0 ) {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => $max_price,
                'type'    => 'NUMERIC',
                'compare' => '<=',
            ];
        }
    }

    $args = [
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => 100,
        'ignore_sticky_posts' => true,
    ];

    if ( ! empty( $qargs['s'] ) ) {
        $args['s'] = sanitize_text_field( $qargs['s'] );
    }

    if ( $tax_query ) {
        $args['tax_query'] = $tax_query;
    }
    if ( $meta_query ) {
        $args['meta_query'] = $meta_query;
    }

    $query = new WP_Query( $args );

    $items = [];

    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $product_id = get_the_ID();
            $product    = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $regular_price = $product->get_regular_price();
            $sale_price    = $product->get_sale_price();
            $price         = $product->get_price();
            $price_html    = $product->get_price_html();
            $thumbnail_id  = $product->get_image_id();
            $thumbnail     = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '';

            $calendar_id   = get_post_meta( $product_id, 'dopbsp_woocommerce_calendar', true );
            $calendar_id   = $calendar_id ? (int) $calendar_id : 0;
            $has_calendar  = $calendar_id > 0;
            $calendar_url  = $has_calendar ? add_query_arg(
                [
                    'action' => 'pinpoint_calendar',
                    'id'     => $calendar_id,
                ],
                admin_url( 'admin-ajax.php' )
            ) : null;

            $items[] = [
                'id'           => $product_id,
                'title'        => get_the_title( $product_id ),
                'permalink'    => get_permalink( $product_id ),
                'is_on_sale'   => $product->is_on_sale(),
                'regular_price'=> $regular_price ? (int) $regular_price : 0,
                'sale_price'   => $sale_price ? (int) $sale_price : 0,
                'price'        => $price ? (int) $price : 0,
                'price_html'   => $price_html,
                'thumbnail'    => $thumbnail,
                'has_calendar' => $has_calendar,
                'calendar_id'  => $calendar_id,
                'calendar_url' => $calendar_url,
            ];
        }
        wp_reset_postdata();
    }

    $result = [
        'count' => count( $items ),
        'items' => $items,
    ];

    return kashiteapp_response( $result, 'OK' );
}

/**
 * /product/{id}/calendar
 *
 * 商品 → Pinpoint カレンダーID
 */
function kashiteapp_api_product_calendar( WP_REST_Request $request ) {
    $product_id = (int) $request['product_id'];

    if ( ! $product_id || get_post_type( $product_id ) !== 'product' ) {
        return kashiteapp_error_response( 'not_found', 'Product not found', 404 );
    }

    $calendar_id  = get_post_meta( $product_id, 'dopbsp_woocommerce_calendar', true );
    $calendar_id  = $calendar_id ? (int) $calendar_id : 0;
    $has_calendar = $calendar_id > 0;

    $result = [
        'product_id'   => $product_id,
        'calendar_id'  => $has_calendar ? $calendar_id : null,
        'has_calendar' => $has_calendar,
    ];

    $msg = $has_calendar ? 'Success' : 'No calendar linked';

    return kashiteapp_response( $result, $msg );
}

/**
 * Pinpoint カレンダー設定 (dopbsp_settings_calendar) を配列化
 */
function kashiteapp_get_calendar_settings( $calendar_id ) {
    global $wpdb;

    $table = $wpdb->prefix . 'dopbsp_settings_calendar';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT name, value FROM {$table} WHERE calendar_id = %d",
            $calendar_id
        ),
        ARRAY_A
    );

    if ( empty( $rows ) ) {
        return [ 'calendar_id' => (int) $calendar_id ];
    }

    $settings = [
        'calendar_id' => (int) $calendar_id,
    ];

    foreach ( $rows as $row ) {
        $name  = $row['name'];
        $value = $row['value'];

        // 型をざっくりよしなに
        if ( $value === 'true' ) {
            $value = true;
        } elseif ( $value === 'false' ) {
            $value = false;
        } elseif ( is_numeric( $value ) ) {
            // ここはとりあえず全部 float→int キャストで十分
            $value = (float) $value;
        }

        // hours_definitions だけは JSON として解釈
        if ( $name === 'hours_definitions' && is_string( $row['value'] ) ) {
            $decoded = json_decode( $row['value'], true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                $value = $decoded;
            }
        }

        $settings[ $name ] = $value;
    }

    return $settings;
}

/**
 * カレンダーに紐づいた rule 情報を取得
 *
 * - settings_calendar.name = 'rule' から rule ID を取る
 * - rules テーブルから min/max を読む
 */
function kashiteapp_get_calendar_rule( $calendar_id ) {
    global $wpdb;

    $settings_table = $wpdb->prefix . 'dopbsp_settings_calendar';
    $rules_table    = $wpdb->prefix . 'dopbsp_rules';

    // まず rule ID を取得
    $rule_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT value FROM {$settings_table}
             WHERE calendar_id = %d AND name = 'rule'
             LIMIT 1",
            $calendar_id
        )
    );

    if ( ! $rule_id ) {
        return null;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, name, time_lapse_min, time_lapse_max
             FROM {$rules_table}
             WHERE id = %d",
             (int) $rule_id
        ),
        ARRAY_A
    );

    if ( ! $row ) {
        return null;
    }

    return [
        'id'                 => (int) $row['id'],
        'name'               => $row['name'],
        'minimum_time_lapse' => (float) $row['time_lapse_min'],
        'maximum_time_lapse' => (float) $row['time_lapse_max'],
    ];
}

/**
 * /calendar/{id}
 *
 * Pinpoint(PBS) の admin-ajax.php?action=pbs_user_calendars_data を叩いて、
 * availability / schedule をベースに days / slots / settings / rule を返す。
 *
 * - start / end が無い → availability の最小〜最大日付
 * - start / end がある → その範囲で絞り込み
 * - slots は hours_definitions から作成し、rule.minimum_time_lapse を使って
 *   duration が未満のものを gap (available=0, price=0) 扱いにする。
 */
function kashiteapp_api_calendar( WP_REST_Request $request ) {
    global $wpdb;

    $calendar_id = (int) $request['calendar_id'];
    $start_param = $request->get_param( 'start' );
    $end_param   = $request->get_param( 'end' );

    if ( ! $calendar_id ) {
        return new WP_REST_Response(
            [
                'code'    => 'invalid_calendar_id',
                'message' => 'Invalid calendar_id',
                'data'    => [ 'status' => 400 ],
            ],
            400
        );
    }

    // ------------------------------
    // ① settings / rule を DB から取得
    // ------------------------------
    $settings_table = $wpdb->prefix . 'dopbsp_settings_calendar';
    $rules_table    = $wpdb->prefix . 'dopbsp_rules';

    $settings_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT name, value FROM {$settings_table} WHERE calendar_id = %d",
            $calendar_id
        ),
        ARRAY_A
    );

    $settings = [
        'calendar_id' => $calendar_id,
    ];

    if ( $settings_rows ) {
        foreach ( $settings_rows as $row ) {
            $name  = $row['name'];
            $value = $row['value'];

            // JSON っぽいなら decode
            if ( is_string( $value ) && $value !== '' ) {
                $first = substr( $value, 0, 1 );
                if ( $first === '[' || $first === '{' ) {
                    $decoded = json_decode( $value, true );
                    if ( json_last_error() === JSON_ERROR_NONE ) {
                        $value = $decoded;
                    }
                } elseif ( $value === 'true' ) {
                    $value = true;
                } elseif ( $value === 'false' ) {
                    $value = false;
                } elseif ( is_numeric( $value ) && (string) (int) $value === $value ) {
                    $value = (int) $value;
                }
            }

            $settings[ $name ] = $value;
        }
    }

    // rule 情報
    $rule     = null;
    $rule_id  = isset( $settings['rule'] ) ? (int) $settings['rule'] : 0;
    if ( $rule_id > 0 ) {
        $rule_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name, time_lapse_min, time_lapse_max FROM {$rules_table} WHERE id = %d",
                $rule_id
            ),
            ARRAY_A
        );

        if ( $rule_row ) {
            $rule = [
                'id'                => (int) $rule_row['id'],
                'name'              => $rule_row['name'],
                'minimum_time_lapse'=> (int) $rule_row['time_lapse_min'],
                'maximum_time_lapse'=> (int) $rule_row['time_lapse_max'],
            ];
        }
    }

    // 最低ブロック数（単位スロット何個分か）
    $min_lapse = 1;
    if ( $rule && ! empty( $rule['minimum_time_lapse'] ) ) {
        $min_lapse = max( 1, (int) $rule['minimum_time_lapse'] );
    }

    // ------------------------------
    // ② pbs_user_calendars_data → availability 取得
    // ------------------------------
    $ajax_url = admin_url( 'admin-ajax.php' );

    $pbs_response = wp_remote_post(
        $ajax_url,
        [
            'timeout' => 15,
            'body'    => [
                'action'      => 'pbs_user_calendars_data',
                'calendar_id' => $calendar_id,
            ],
        ]
    );

    if ( is_wp_error( $pbs_response ) ) {
        return new WP_REST_Response(
            [
                'code'    => 'pbs_request_failed',
                'message' => 'pbs_user_calendars_data request failed',
                'data'    => [ 'status' => 500 ],
            ],
            500
        );
    }

    $pbs_body = wp_remote_retrieve_body( $pbs_response );
    $pbs_json = json_decode( $pbs_body, true );

    if ( ! is_array( $pbs_json ) || ! isset( $pbs_json['availability'] ) ) {
        return new WP_REST_Response(
            [
                'code'    => 'pbs_invalid_response',
                'message' => 'pbs_user_calendars_data invalid response',
                'data'    => [ 'status' => 500 ],
            ],
            500
        );
    }

    $availability = $pbs_json['availability'];

    // availability 全体から最小/最大日付を算出
    $min_date = null;
    $max_date = null;

    foreach ( $availability as $row ) {
        if ( empty( $row['date_start'] ) ) {
            continue;
        }
        $d = substr( $row['date_start'], 0, 10 ); // 'Y-m-d'
        if ( $min_date === null || $d < $min_date ) {
            $min_date = $d;
        }
        if ( $max_date === null || $d > $max_date ) {
            $max_date = $d;
        }
    }

    if ( ! $min_date || ! $max_date ) {
        $result = [
            'calendar_id' => $calendar_id,
            'start'       => $start_param ?: '',
            'end'         => $end_param ?: '',
            'settings'    => $settings,
            'rule'        => $rule,
            'days'        => [],
            'slots'       => [],
        ];

        return new WP_REST_Response(
            [
                'code'    => 'success',
                'message' => 'OK',
                'data'    => [
                    'status' => 200,
                    'result' => $result,
                ],
            ],
            200
        );
    }

    // start / end 決定ロジック
    $start = $start_param ?: $min_date;
    $end   = $end_param   ?: $max_date;

    if ( $start > $end ) {
        $tmp   = $start;
        $start = $end;
        $end   = $tmp;
    }

    // ------------------------------
    // ③ days を availability から組み立て
    // ------------------------------
	$days = [];

	$defs = isset($settings['hours_definitions']) && is_array($settings['hours_definitions'])
		? $settings['hours_definitions']
		: [];

	$first_time = null;
	$last_time  = null;

	if (!empty($defs)) {
		$first_time = $defs[0]['value']; // 例: "10:00"
		$last_time  = $defs[count($defs) - 1]['value']; // 例: "21:00" or "24:00"
	}

	// availability に出てきた日を「営業日」として登録
	foreach ($availability as $row) {
		if (empty($row['date_start'])) {
			continue;
		}

		$d = substr($row['date_start'], 0, 10);

		if ($d < $start || $d > $end) {
			continue;
		}

		// hours_definitionsベースで日付を構築
		$days[$d] = [
//			'date_start' => $d . ' ' . ($first_time ? $first_time . ':00' : '00:00:00'),
//			'date_end'   => $d . ' ' . ($last_time  ? $last_time  . ':00' : '24:00:00'),
			'status'     => 'available',
		];
	}

    // ------------------------------
    // ④ dopbsp_calendar_schedule_get → slots を組み立て
    // ------------------------------
    $slots = [];

	// 日毎の空き状況カウンタ
	$day_slot_stats = []; // [ 'Y-m-d' => [ 'total' => n, 'booked' => m, 'available' => k ] ]
	
    $start_year = (int) substr( $start, 0, 4 );
    $end_year   = (int) substr( $end, 0, 4 );

    for ( $year = $start_year; $year <= $end_year; $year++ ) {
        $dopbsp_response = wp_remote_post(
            $ajax_url,
            [
                'timeout' => 15,
                'body'    => [
                    'action'                        => 'dopbsp_calendar_schedule_get',
                    'dopbsp_frontend_ajax_request' => 'true',
                    'id'                            => $calendar_id,
                    'year'                          => $year,
                    'firstYear'                     => '"false"',
                ],
            ]
        );

        if ( is_wp_error( $dopbsp_response ) ) {
            continue;
        }

        $dopbsp_body = wp_remote_retrieve_body( $dopbsp_response );
        $schedule    = json_decode( $dopbsp_body, true );

        if ( ! is_array( $schedule ) ) {
            continue;
        }

        foreach ( $schedule as $date_str => $json_string ) {
            if ( $date_str < $start || $date_str > $end ) {
                continue;
            }

            $day_obj = json_decode( $json_string, true );
            if ( ! is_array( $day_obj ) ) {
                continue;
            }

            $hours = isset( $day_obj['hours'] ) && is_array( $day_obj['hours'] )
                ? $day_obj['hours']
                : [];

            $defs = isset( $day_obj['hours_definitions'] ) && is_array( $day_obj['hours_definitions'] )
                ? $day_obj['hours_definitions']
                : [];

            if ( empty( $defs ) ) {
                continue;
            }

            $count_defs = count( $defs );
            //$all_booked = ! empty( $hours );
            $all_booked = true;

            // この日の unit_minutes を求める（最小差分）
            $unit_minutes = null;
            for ( $i = 0; $i < $count_defs - 1; $i++ ) {
                $t1 = strtotime( $date_str . ' ' . $defs[ $i ]['value'] );
                $t2 = strtotime( $date_str . ' ' . $defs[ $i + 1 ]['value'] );
                if ( $t1 === false || $t2 === false ) {
                    continue;
                }
                $diff = (int) ( ( $t2 - $t1 ) / 60 );
                if ( $diff <= 0 ) {
                    continue;
                }
                if ( $unit_minutes === null || $diff < $unit_minutes ) {
                    $unit_minutes = $diff;
                }
            }

            if ( $unit_minutes === null || $unit_minutes <= 0 ) {
                $unit_minutes = 60; // 保険
            }

            // ルールに基づく「1ブロック分の時間」
            $block_minutes = $unit_minutes * $min_lapse;
            if ( $block_minutes <= 0 ) {
                $block_minutes = $unit_minutes;
            }

            for ( $i = 0; $i < $count_defs - 1; $i++ ) {
                $start_time = $defs[ $i ]['value'];
                $end_time   = $defs[ $i + 1 ]['value'];

                $t1 = strtotime( $date_str . ' ' . $start_time );
                $t2 = strtotime( $date_str . ' ' . $end_time );
                if ( $t1 === false || $t2 === false ) {
                    continue;
                }

                $duration = (int) ( ( $t2 - $t1 ) / 60 ); // 分

                $hour_data = isset( $hours[ $start_time ] ) ? $hours[ $start_time ] : null;
				if ( !is_array($hour_data) ) { $all_booked = false; continue; }

                $status    = isset( $hour_data['status'] ) ? $hour_data['status'] : '';
                $price     = isset( $hour_data['price'] ) ? (int) $hour_data['price'] : 0;
                $available = isset( $hour_data['available'] ) ? (int) $hour_data['available'] : 0;
                $promo     = isset( $hour_data['promo'] ) ? (int) $hour_data['promo'] : 0;

                // duration がブロック長に満たない → gap として潰す
                if ( $duration < $block_minutes ) {
                    $status    = 'gap';
                    $price     = 0;
                    $available = 0;
                    $promo     = 0;
                } else {
                    // 通常スロット (gap でないもの) だけ all_booked 判定に利用
                    if ( $status !== 'booked' ) {
                        $all_booked = false;
                    }
                }
				
				// ▼▼ 日毎の統計（gap は数えない）▼▼
				if ( $status === 'gap' ) { /* 判定対象外 */ }
				else if ( $status !== 'booked' && (int)$available > 0 ) {
					$all_booked = false;
				}
				
				if ( $status !== 'gap' ) {
					if ( ! isset( $day_slot_stats[ $date_str ] ) ) {
						$day_slot_stats[ $date_str ] = [
							'total'     => 0,
							'booked'    => 0,
							'available' => 0,
						];
					}

					$day_slot_stats[ $date_str ]['total']++;

					// 「埋まり」の判定：status=booked or available<=0
					if ( $status === 'booked' || $available <= 0 ) {
						$day_slot_stats[ $date_str ]['booked']++;
					} else {
						$day_slot_stats[ $date_str ]['available']++;
					}
				}
				// ▲▲ ここまで追加 ▲▲

                $slots[] = [
                    'date'       => $date_str,
                    'time_start' => $start_time,
                    'time_end'   => $end_time,
                    'status'     => $status,
                    'price'      => $price,
                    'available'  => $available,
                    'promo'      => $promo,
                    'duration'   => $duration,
                ];
            }

            // days に存在しない & 全スロット booked なら
            // 「営業日だが満席」の day レコードを追加
            if ( $all_booked && ! isset( $days[ $date_str ] ) ) {
                $days[ $date_str ] = [
//                    'date_start' => $date_str . ' 00:00:00',
//                    'date_end'   => $date_str . ' 23:59:59',
                    'status'     => 'booked',
                ];
            }
        }
    }

	// ④ 〜 ループ終了後、日毎の "◯ / △ / ×" を決定
	foreach ( $day_slot_stats as $date_str => $st ) {
		if ( empty( $st['total'] ) ) {
			// スロットがない日 → マーク付けない（必要なら '-' などを付ける）
			continue;
		}

		if ( $st['booked'] === $st['total'] ) {
			$mark = '×'; // 全部埋まり
		} elseif ( $st['booked'] === 0 ) {
			$mark = '◯'; // 全部空き
		} else {
			$mark = '△'; // 一部埋まり
		}

		if ( ! isset( $days[ $date_str ] ) ) {
			// 念のため、days に存在しない日にも骨だけ作っておく
			$days[ $date_str ] = [
//				'date_start' => $date_str . ' 00:00:00',
//				'date_end'   => $date_str . ' 23:59:59',
				'status'     => 'available',
			];
		}

		$days[ $date_str ]['mark'] = $mark;
	}
	
    // ------------------------------
    // ⑤ レスポンス返却
    // ------------------------------
    $result = [
        'calendar_id' => $calendar_id,
        'start'       => $start,
        'end'         => $end,
        'settings'    => $settings,
        'rule'        => $rule,
        'days'        => $days,
        'slots'       => $slots,
    ];

    return new WP_REST_Response(
        [
            'code'    => 'success',
            'message' => 'OK',
            'data'    => [
                'status' => 200,
                'result' => $result,
            ],
        ],
        200
    );
}

/**
 * /debug_meta/{id}
 *
 * get_post_meta をそのまま吐くデバッグ用
 */
function kashiteapp_api_debug_meta( WP_REST_Request $request ) {
    $post_id = (int) $request['post_id'];
    if ( ! $post_id ) {
        return kashiteapp_error_response( 'invalid_params', 'post_id is required', 400 );
    }

    $meta = get_post_meta( $post_id );
    return kashiteapp_response( $meta, 'OK' );
}

/**
 * /test/run
 *
 * 簡易セルフテスト（主要エンドポイントを一通り叩く）
 */
function kashiteapp_api_test_run( WP_REST_Request $request ) {
    $tests  = [];
    $all_ok = true;

    // 共通ヘルパ
    $run_test = function( $id, $label, $route ) use ( &$tests, &$all_ok ) {
        $req = new WP_REST_Request( 'GET', $route );
        $res = rest_do_request( $req );
        $ok  = ( 200 === $res->get_status() );

        $all_ok  = $all_ok && $ok;
        $tests[] = [
            'id'      => $id,
            'label'   => $label,
            'ok'      => $ok,
            'message' => $ok ? "{$id} OK" : "{$id} NG",
            'url'     => rest_url( ltrim( $route, '/' ) ),
            'detail'  => [
                'ok'   => $ok,
                'code' => $res->get_status(),
                'json' => $res->get_data(),
            ],
        ];
    };

    // ping
    $run_test(
        'ping',
        'サーバ疎通 (ping)',
        '/kashiteapp/v1/ping'
    );

    // filters
    $run_test(
        'filters',
        '検索フィルタ (filters)',
        '/kashiteapp/v1/filters'
    );

    // price_range
    $run_test(
        'price_range',
        '料金レンジ (price_range)',
        '/kashiteapp/v1/price_range'
    );

    // option_space_type
    $run_test(
        'option_space_type',
        '会場タイプ (option_space_type)',
        '/kashiteapp/v1/option_space_type'
    );

    // option_space_use
    $run_test(
        'option_space_use',
        '利用目的 (option_space_use)',
        '/kashiteapp/v1/option_space_use'
    );

    // option_space_area
    $run_test(
        'option_space_area',
        'エリア (option_space_area)',
        '/kashiteapp/v1/option_space_area'
    );

    // search_url（パラメータ無しで 200 が返るかだけを見る）
    $run_test(
        'search_url',
        '検索URL生成 (search_url, no params)',
        '/kashiteapp/v1/search_url'
    );

    // debug/woof（WOOF設定のざっくり確認）
    $run_test(
        'debug_woof',
        'WOOF 設定確認 (debug/woof)',
        '/kashiteapp/v1/debug/woof'
    );

    $result = [
        'all_ok' => $all_ok,
        'tests'  => $tests,
    ];

    $msg = $all_ok ? 'ALL GREEN' : 'SOME FAILED';

    return kashiteapp_response( $result, $msg );
}

function kashiteapp_get_wc_tabs_html( $product_id ) {
    $product = wc_get_product( $product_id );
    if ( ! $product ) return [];

    // WooCommerce のタブ一覧を取得
    $tabs = apply_filters( 'woocommerce_product_tabs', [], $product );

    $result = [];

    foreach ( $tabs as $key => $tab ) {
        // callback でHTMLを生成する
        if ( ! isset( $tab['callback'] ) || ! is_callable( $tab['callback'] ) ) continue;

        ob_start();
        call_user_func( $tab['callback'], $key, $tab );  // HTML を出力する
        $html = ob_get_clean();

        $result[] = [
            'id'      => $key,
            'title'   => $tab['title'],
            'content' => $html,
        ];
    }

    return $result;
}

/**
 * 商品詳細取得 API
 *
 * GET /wp-json/kashiteapp/v1/product/{product_id}
 */
function kashiteapp_api_product_detail( WP_REST_Request $request ) {
    $product_id = (int) $request->get_param( 'product_id' );

    if ( ! $product_id ) {
        return new WP_REST_Response(
            [
                'code'    => 'invalid_product_id',
                'message' => 'Invalid product_id',
                'data'    => [ 'status' => 400 ],
            ],
            400
        );
    }

    if ( ! function_exists( 'wc_get_product' ) ) {
        return new WP_REST_Response(
            [
                'code'    => 'woocommerce_not_loaded',
                'message' => 'WooCommerce is not available',
                'data'    => [ 'status' => 500 ],
            ],
            500
        );
    }

    $product = wc_get_product( $product_id );

       if ( ! $product ) {
        return new WP_REST_Response(
            [
                'code'    => 'product_not_found',
                'message' => 'Product not found',
                'data'    => [ 'status' => 404 ],
            ],
            404
        );
    }

    // 基本情報（表紙用）
    $result = [
        'id'             => $product->get_id(),
        'name'           => $product->get_name(),
        'slug'           => $product->get_slug(),
        'permalink'      => $product->get_permalink(),
        'sku'            => $product->get_sku(),
        'type'           => $product->get_type(),
        'price'          => (float) $product->get_price(),
        'regular_price'  => (float) $product->get_regular_price(),
        'sale_price'     => $product->get_sale_price() !== '' ? (float) $product->get_sale_price() : null,
        'stock_status'   => $product->get_stock_status(),
        'stock_quantity' => $product->get_stock_quantity(),
        'tax_status'     => $product->get_tax_status(),
        'tax_class'      => $product->get_tax_class(),
    ];

    // カレンダー情報（Pinpoint）
    $calendar_id = get_post_meta( $product_id, 'dopbsp_woocommerce_calendar', true );
    $calendar_id = $calendar_id ? (int) $calendar_id : null;

    $result['calendar'] = [
        'calendar_id'  => $calendar_id,
        'has_calendar' => ! empty( $calendar_id ),
    ];

    // 画像
    $images         = [];
    $attachment_ids = $product->get_gallery_image_ids();
    $thumb_id       = $product->get_image_id();

    if ( $thumb_id ) {
        array_unshift( $attachment_ids, $thumb_id );
        $attachment_ids = array_unique( $attachment_ids );
    }

    foreach ( $attachment_ids as $aid ) {
        $src = wp_get_attachment_image_url( $aid, 'medium' );
        if ( ! $src ) {
            continue;
        }
        $images[] = [
            'id'  => (int) $aid,
            'src' => $src,
            'alt' => get_post_meta( $aid, '_wp_attachment_image_alt', true ),
        ];
    }

    $result['images'] = $images;

    // カテゴリ
    $terms      = get_the_terms( $product_id, 'product_cat' ) ?: [];
    $categories = [];

    foreach ( $terms as $t ) {
        $categories[] = [
            'id'   => $t->term_id,
            'name' => $t->name,
            'slug' => $t->slug,
        ];
    }

    $result['categories'] = $categories;

    // 属性（pa_* + カスタム属性）
    $attrs = [];
    foreach ( $product->get_attributes() as $attr ) {
        /** @var WC_Product_Attribute $attr */
        $slug    = $attr->get_name(); // 'pa_city' など
        $options = [];

        if ( $attr->is_taxonomy() ) {
            $terms   = wc_get_product_terms( $product_id, $slug, [ 'fields' => 'names' ] );
            $options = $terms ?: [];
        } else {
            $options = $attr->get_options();
        }

        $attrs[] = [
            'name'    => wc_attribute_label( $slug ),
            'slug'    => $slug,
            'options' => $options,
        ];
    }

    $result['attributes'] = $attrs;

    // 生メタ（デバッグ用）
    $raw_meta = get_post_meta( $product_id );

    // yikes_woo_products_tabs → 素のタブ配列（旧仕様互換）
    $yikes_tabs = [];

    if ( isset( $raw_meta['yikes_woo_products_tabs'][0] ) ) {
        $tabs_raw = maybe_unserialize( $raw_meta['yikes_woo_products_tabs'][0] );

        if ( is_array( $tabs_raw ) ) {
            foreach ( $tabs_raw as $t ) {
                if ( ! is_array( $t ) ) {
                    continue;
                }
                $yikes_tabs[] = [
                    'title'   => isset( $t['title'] )   ? $t['title']   : '',
                    'id'      => isset( $t['id'] )      ? $t['id']      : '',
                    'content' => isset( $t['content'] ) ? $t['content'] : '',
                ];
            }
        }
    }

    $result['tabs_raw'] = $yikes_tabs;

    // 説明（本文・抜粋）
    $description_html       = wpautop( $product->get_description() );
    $short_description_html = wpautop( $product->get_short_description() );

    $result['description_html']       = $description_html;
    $result['short_description_html'] = $short_description_html;

    // 問い合わせ先情報（メール + フォーム定義）
    $post        = get_post( $product_id );
    $owner_email = $post ? get_the_author_meta( 'user_email', $post->post_author ) : null;
    $admin_email = get_option( 'admin_email' );

    $contact = [
        'admin_email' => $admin_email,
        'owner_email' => $owner_email,
        'form'        => [
            'fields' => [
                [ 'name' => 'name',    'label' => '名前',    'type' => 'text',     'required' => true ],
                [ 'name' => 'email',   'label' => 'メール',  'type' => 'email',    'required' => true ],
                [ 'name' => 'phone',   'label' => '電話',    'type' => 'text',     'required' => false ],
                [ 'name' => 'enquiry', 'label' => 'Enquiry','type' => 'textarea', 'required' => true ],
            ],
        ],
    ];

    $result['contact'] = $contact;

    // アプリ用 UI タブマスタ
    $ui_tabs = [];

    // (1) 概要タブ
    $overview_html = $description_html ?: $short_description_html;

    if ( ! empty( $overview_html ) ) {
        $ui_tabs[] = [
            'title'   => '概要',
            'id'      => 'overview',
            'content' => $overview_html,
        ];
    }

    // (2) YIKES タブ群（設備 / アクセス / 写真・ビデオ など）
    foreach ( $yikes_tabs as $t ) {
        $ui_tabs[] = [
            'title'   => isset( $t['title'] )   ? $t['title']   : '',
            'id'      => isset( $t['id'] )      ? $t['id']      : '',
            'content' => isset( $t['content'] ) ? $t['content'] : '',
        ];
    }

    // (3) 予約カレンダータブ
    if ( ! empty( $calendar_id ) ) {
        $ui_tabs[] = [
            'title'    => '予約カレンダー',
            'id'       => 'calendar',
            'content'  => '',
            'calendar' => $result['calendar'],
        ];
    }

    // (4) お問い合わせタブ
    if ( ! empty( $contact ) ) {
        $ui_tabs[] = [
            'title'   => 'お問い合わせ',
            'id'      => 'contact',
            'content' => '',
            'contact' => $contact,
        ];
    }

    $result['tabs']     = $ui_tabs;
    $result['raw_meta'] = $raw_meta;

    return new WP_REST_Response(
        [
            'code'    => 'success',
            'message' => 'OK',
            'data'    => [
                'status' => 200,
                'result' => $result,
            ],
        ],
        200
    );
}

//DEBUG
function kashiteapp_api_debug_woof( WP_REST_Request $request ) {
    $settings = get_option( 'woof_settings' );

    $tax = isset( $settings['tax'] ) && is_array( $settings['tax'] )
        ? array_keys( $settings['tax'] )
        : null;

    $result = [
        'raw_is_array'      => is_array( $settings ),
        'tax_keys'          => $tax,
        'product_cat'       => $settings['tax']['product_cat']       ?? null,
        'pa_purpose-use'    => $settings['tax']['pa_purpose-use']    ?? null,
        'pa_city'           => $settings['tax']['pa_city']           ?? null,
        'pa_indoor-outdoor' => $settings['tax']['pa_indoor-outdoor'] ?? null,
    ];

    return kashiteapp_response( $result, 'OK' );
}

// NEWS 一覧
function kashiteapp_api_news_list( WP_REST_Request $request ) {
    $page     = (int) $request->get_param('page')     ?: 1;
    $per_page = (int) $request->get_param('per_page') ?: 10;

    $q = new WP_Query([
        'post_type'      => 'post',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $items = [];
    if ( $q->have_posts() ) {
        while ( $q->have_posts() ) {
            $q->the_post();

            $thumb_id  = get_post_thumbnail_id();
            $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : null;

            $items[] = [
                'id'            => get_the_ID(),
                'title'         => get_the_title(),
                'excerpt'       => get_the_excerpt(),
                'date'          => get_the_date( 'c' ),
                'link'          => get_permalink(),
                'thumbnail_url' => $thumb_url,
            ];
        }
        wp_reset_postdata();
    }

    return kashiteapp_response( $items, 'OK' );
}

/**
 * calendar_id -> product_id 解決
 * dopbsp_woocommerce_calendar = {calendar_id} の publish 商品を1件返す
 */
function kashiteapp_find_product_id_by_calendar_id( $calendar_id ) {
    $q = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => 'dopbsp_woocommerce_calendar',
                'value'   => (string) (int) $calendar_id,
                'compare' => '=',
            ],
        ],
    ]);

    if ( ! $q->have_posts() ) return 0;
    return (int) $q->posts[0];
}

function kashiteapp_api_calendar_product( WP_REST_Request $request ) {
    $calendar_id = (int) $request['calendar_id'];
    if ( $calendar_id <= 0 ) {
        return kashiteapp_error_response('invalid_params', 'calendar_id is required', 400);
    }

    $product_id = kashiteapp_find_product_id_by_calendar_id( $calendar_id );
    if ( $product_id <= 0 ) {
        return kashiteapp_error_response('not_found', 'no product for calendar_id', 404);
    }

    $result = [
        'calendar_id' => $calendar_id,
        'product_id'  => $product_id,
        'permalink'   => get_permalink( $product_id ),
    ];
    return kashiteapp_response( $result, 'OK' );
}

/**
 * 指定日・時間帯の slot を抽出（サーバで価格確定）
 */
function kashiteapp_get_slots_for_range( $calendar_id, $date, $start_hour, $end_hour ) {
    // 既存の /calendar を内部呼び出し（start=end=date）
    $req = new WP_REST_Request('GET', "/kashiteapp/v1/calendar/{$calendar_id}");
    $req->set_param('start', $date);
    $req->set_param('end', $date);

    $res = rest_do_request( $req );
    if ( 200 !== $res->get_status() ) {
        return new WP_Error('calendar_fetch_failed', 'calendar api failed');
    }

    $body = $res->get_data();
    $slots = $body['data']['result']['slots'] ?? [];
    if ( ! is_array($slots) ) $slots = [];

    // 対象日の slot を time_start で引ける map にする
    $map = [];
    foreach ( $slots as $s ) {
        if ( ($s['date'] ?? '') !== $date ) continue;
        $ts = $s['time_start'] ?? '';
        if ( $ts === '' ) continue;
        $map[$ts] = $s;
    }

    // start_hour から end_hour まで連続で拾う（hours_definitions は 1時間刻み想定）
    // end_hour は「含まない」。例: 16:00-18:00 => 16:00,17:00 を拾う
    $picked = [];
    $t = $start_hour;

    // 安全弁（最大48ステップ）
    for ( $i=0; $i<48; $i++ ) {
        if ( $t === $end_hour ) break;
        if ( empty($map[$t]) ) {
            return new WP_Error('slot_missing', "slot not found: {$date} {$t}");
        }
        $picked[] = $map[$t];

        // 次時刻へ（slotのtime_endを信用）
        $t = $map[$t]['time_end'] ?? '';
        if ( $t === '' ) return new WP_Error('slot_invalid', 'slot time_end missing');
    }

    if ( empty($picked) ) {
        return new WP_Error('invalid_range', 'empty slot range');
    }

    // 最後が end_hour に到達してるか
    $last_end = $picked[count($picked)-1]['time_end'] ?? '';
    if ( $last_end !== $end_hour ) {
        return new WP_Error('range_not_contiguous', 'range not contiguous');
    }

    return $picked;
}

/**
 * admin-ajax 用 form を組み立て（価格はサーバ確定）
 */
function kashiteapp_build_add_to_cart_form( $calendar_id, $product_id, $date, $start_hour, $end_hour, $no_items=1, $language='ja', $currency_code='JPY' ) {
    $picked = kashiteapp_get_slots_for_range( $calendar_id, $date, $start_hour, $end_hour );
    if ( is_wp_error($picked) ) return $picked;

    $price_total = 0;
    $days_hours_history = [];

    foreach ( $picked as $s ) {
        $status = $s['status'] ?? '';
        $available = (int) ($s['available'] ?? 0);
        $price = (int) ($s['price'] ?? 0);
        $ts = $s['time_start'];

		// “gap” や booked は弾く（必要なら緩めてもいい）
		if ( $status !== 'available' || $available <= 0 ) {
			return new WP_Error(
				'slot_unavailable',
				'slot unavailable',
				[
					'date'      => $date,
					'time'      => $ts,
					'status'    => $status,
					'available' => $available,
					'reason'    => ($status === 'booked' || $available <= 0) ? 'booked' : 'unavailable',
				]
			);
		}

        $price_total += $price;

        $days_hours_history[$ts] = [
            'available' => 1,
            'bind'      => 0,
            'price'     => $price,
            'promo'     => 0,
            'status'    => 'available',
        ];
    }

    $form = [
        'action'                        => 'dopbsp_woocommerce_add_to_cart',
        'dopbsp_frontend_ajax_request'  => 'true',
        'calendar_id'                   => (string) (int) $calendar_id,
        'language'                      => (string) $language,
        'currency_code'                 => (string) $currency_code,

        'cart_data[0][check_in]'        => (string) $date,
        'cart_data[0][check_out]'       => '',
        'cart_data[0][start_hour]'      => (string) $start_hour,
        'cart_data[0][end_hour]'        => (string) $end_hour,
        'cart_data[0][no_items]'        => (string) (int) $no_items,

        'cart_data[0][price]'           => (string) $price_total,
        'cart_data[0][price_total]'     => (string) $price_total,

        'cart_data[0][extras_price]'    => '0',
        'cart_data[0][discount_price]'  => '0',
        'cart_data[0][coupon_price]'    => '0',
        'cart_data[0][fees_price]'      => '0',
        'cart_data[0][deposit_price]'   => '0',
    ];

    // days_hours_history をフラットな form に落とす
    foreach ( $days_hours_history as $ts => $h ) {
        foreach ( $h as $k => $v ) {
            $form["cart_data[0][days_hours_history][{$ts}][{$k}]"] = (string) $v;
        }
    }

    $form['product_id'] = (string) (int) $product_id;

    return [
        'computed' => [
            'price_total' => $price_total,
            'slots'       => $picked,
        ],
        'form' => $form,
    ];
}

function kashiteapp_mail_safe_header_value($s){
    $s = (string)$s;
    return str_replace(["\r", "\n"], '', $s);
}

/**
 * POST /cart/payload
 * テスター用：admin-ajax に投げるべき form を返す
 */
function kashiteapp_api_cart_payload( WP_REST_Request $request ) {
    $calendar_id = (int) $request->get_param('calendar_id');
    $date        = (string) $request->get_param('date');
    $start_hour  = (string) $request->get_param('start_hour');
    $end_hour    = (string) $request->get_param('end_hour');

    if ( $calendar_id <= 0 || $date === '' || $start_hour === '' || $end_hour === '' ) {
        return kashiteapp_error_response('invalid_params', 'calendar_id,date,start_hour,end_hour are required', 400);
    }

    $product_id  = (int) $request->get_param('product_id');
    if ( $product_id <= 0 ) {
        $product_id = kashiteapp_find_product_id_by_calendar_id( $calendar_id );
        if ( $product_id <= 0 ) {
            return kashiteapp_error_response('not_found', 'product not found for calendar_id (provide product_id or fix mapping)', 404);
        }
    }

    $no_items     = (int) ($request->get_param('no_items') ?: 1);
    $language     = (string) ($request->get_param('language') ?: 'ja');
    $currency_code= (string) ($request->get_param('currency_code') ?: 'JPY');

    $built = kashiteapp_build_add_to_cart_form( $calendar_id, $product_id, $date, $start_hour, $end_hour, $no_items, $language, $currency_code );
    if ( is_wp_error($built) ) {
        return kashiteapp_error_response( $built->get_error_code(), $built->get_error_message(), 400 );
    }

    $result = [
        'ajax_url'      => admin_url('admin-ajax.php'),
        'method'        => 'POST',
        'content_type'  => 'application/x-www-form-urlencoded; charset=UTF-8',
        'calendar_id'   => $calendar_id,
        'product_id'    => $product_id,
        'computed'      => $built['computed'],
        'form'          => $built['form'],
    ];
    return kashiteapp_response( $result, 'OK' );
}

/**
 * GET /cart/webview
 * JSONで「WebViewが実行すべき命令」を返す
 */
function kashiteapp_api_cart_webview( WP_REST_Request $request ) {
    $calendar_id = (int) $request->get_param('calendar_id');
    $date        = (string) $request->get_param('date');
    $start_hour  = (string) $request->get_param('start_hour');
    $end_hour    = (string) $request->get_param('end_hour');

    if ( $calendar_id <= 0 || $date === '' || $start_hour === '' || $end_hour === '' ) {
        return kashiteapp_error_response('invalid_params', 'calendar_id,date,start_hour,end_hour are required', 400);
    }

    $product_id  = (int) $request->get_param('product_id');
    if ( $product_id <= 0 ) {
        $product_id = kashiteapp_find_product_id_by_calendar_id( $calendar_id );
        if ( $product_id <= 0 ) {
            return kashiteapp_error_response('not_found', 'product not found for calendar_id', 404);
        }
    }

    $no_items      = (int) ($request->get_param('no_items') ?: 1);
    $language      = (string) ($request->get_param('language') ?: 'ja');
    $currency_code = (string) ($request->get_param('currency_code') ?: 'JPY');

    $built = kashiteapp_build_add_to_cart_form(
        $calendar_id, $product_id, $date, $start_hour, $end_hour, $no_items, $language, $currency_code
    );

    // ★ 失敗も「命令JSON」として返す（200）
    if ( is_wp_error($built) ) {
        $detail = $built->get_error_data();
        $result = [
            'type'             => 'webview_cart',
            'ok'               => false,
            'calendar_id'      => $calendar_id,
            'product_id'       => $product_id,
            'success_redirect' => wc_get_cart_url() ?: site_url('/cart/'),
            'error' => [
                'code'    => $built->get_error_code(),
                'message' => $built->get_error_message(),
                'detail'  => is_array($detail) ? $detail : null,
            ],
        ];
        return kashiteapp_response( $result, 'OK' ); // status=200
    }

    // ★ 成功（命令JSON）
    $result = [
        'type'             => 'webview_cart',
        'ok'               => true,
        'ajax_url'         => admin_url('admin-ajax.php'),
        'method'           => 'POST',
        'headers'          => [
            'Content-Type'     => 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With' => 'XMLHttpRequest',
        ],
        'credentials'      => 'include',
        'calendar_id'      => $calendar_id,
        'product_id'       => $product_id,
        'computed'         => $built['computed'],
        'form'             => $built['form'],
        'success_redirect' => wc_get_cart_url() ?: site_url('/cart/'),
    ];

    return kashiteapp_response( $result, 'OK' );
}

function kashiteapp_api_cart_bridge( WP_REST_Request $request ) {
    $calendar_id = (int) $request->get_param('calendar_id');
    $date        = (string) $request->get_param('date');
    $start_hour  = (string) $request->get_param('start_hour');
    $end_hour    = (string) $request->get_param('end_hour');

    if ( $calendar_id <= 0 || $date === '' || $start_hour === '' || $end_hour === '' ) {
        return new WP_REST_Response('invalid params', 400, ['Content-Type'=>'text/plain; charset=UTF-8']);
    }

    $product_id  = (int) $request->get_param('product_id');
    if ( $product_id <= 0 ) {
        $product_id = kashiteapp_find_product_id_by_calendar_id( $calendar_id );
        if ( $product_id <= 0 ) {
            return new WP_REST_Response('product not found for calendar_id', 404, ['Content-Type'=>'text/plain; charset=UTF-8']);
        }
    }

    $no_items      = (int) ($request->get_param('no_items') ?: 1);
    $language      = (string) ($request->get_param('language') ?: 'ja');
    $currency_code = (string) ($request->get_param('currency_code') ?: 'JPY');

    $built = kashiteapp_build_add_to_cart_form(
        $calendar_id, $product_id, $date, $start_hour, $end_hour, $no_items, $language, $currency_code
    );

    $ajax_url = esc_url( admin_url('admin-ajax.php') );
    $cart_url = esc_url( wc_get_cart_url() ?: site_url('/cart/') );
    $back_url = esc_url( get_permalink($product_id) );

    // ★ 失敗：予約済み/取得不可をHTMLで表示（WebViewで分かる）
    if ( is_wp_error($built) ) {
        $code   = esc_html($built->get_error_code());
        $detail = $built->get_error_data();
        $d_date = esc_html(is_array($detail) && isset($detail['date']) ? $detail['date'] : $date);
        $d_time = esc_html(is_array($detail) && isset($detail['time']) ? $detail['time'] : '');
        $d_stat = esc_html(is_array($detail) && isset($detail['status']) ? $detail['status'] : '');
        $d_reason = esc_html(is_array($detail) && isset($detail['reason']) ? $detail['reason'] : '');

        $title = '予約できません';
        $msg   = ($code === 'slot_unavailable' && $d_reason === 'booked')
            ? 'その時間帯は予約済みです。別の時間を選んでください。'
            : '指定条件では予約できません。条件を見直してください。';

        $html = "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='font-family:system-ui;padding:16px;line-height:1.5'>
  <h3>{$title}</h3>
  <div>{$msg}</div>
  <hr>
  <div>date: {$d_date}</div>
  <div>time: {$d_time}</div>
  <div>status: {$d_stat}</div>
  <div>code: {$code}</div>
  <hr>
  <a href='{$back_url}'>予約可能な時間を探す</a><br>
  <a href='{$cart_url}'>カートを見る</a>
</body></html>";

        return new WP_REST_Response( $html, 200, ['Content-Type'=>'text/html; charset=UTF-8'] );
    }

    // ★ 成功：従来通り add_to_cart → cartへ
    $json_form = wp_json_encode( $built['form'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

    $html = "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head><body>
<script>(async()=>{const form={$json_form};const body=new URLSearchParams();for(const [k,v] of Object.entries(form)) body.append(k,v);
const res=await fetch('{$ajax_url}',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},credentials:'include',body});
if(res.ok){location.href='{$cart_url}';}else{document.body.innerText='add_to_cart failed\\n'+await res.text();}})();</script>
</body></html>";

    return new WP_REST_Response( $html, 200, ['Content-Type'=>'text/html; charset=UTF-8'] );
}

add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {
    // kashiteapp/v1/cart/bridge のときだけ横取り
	if (strpos($request->get_route(), '/kashiteapp/v1/cart/bridge') !== 0) return $served;

    // WP_REST_Response を想定
    if ($result instanceof WP_REST_Response) {
        // ヘッダ反映
        foreach ($result->get_headers() as $name => $value) {
            header("{$name}: {$value}");
        }
        status_header($result->get_status());

        // ★JSON化せずにそのまま出す
        echo (string) $result->get_data();
        return true; // 以降のREST出力（JSON）を止める
    }

    // 保険：想定外は通常処理
    return $served;
}, 10, 4);

function kashiteapp_api_index( WP_REST_Request $request ) {
    $server = rest_get_server();
    $routes = $server->get_routes();

    $ns = '/kashiteapp/v1/';
    $my_routes = [];

    foreach ($routes as $route => $handlers) {
        if (strpos($route, $ns) !== 0) continue;

        $methods = [];
        foreach ($handlers as $h) {
            if (!empty($h['methods'])) {
                $methods = array_merge($methods, array_keys($h['methods']));
            }
        }
        $methods = array_values(array_unique($methods));

        $my_routes[] = [
            'route'   => $route,
            'methods' => $methods,
        ];
    }

    $base = rest_url('kashiteapp/v1/');

    $result = [
        'name'    => 'KASHITE App API',
        'version' => KASHITEAPP_API_VERSION,
        'base_url'=> $base,

        'const' => [
            'site_url'     => site_url('/'),
            'ajax_url'     => admin_url('admin-ajax.php'),
            'cart_url'     => function_exists('wc_get_cart_url') ? wc_get_cart_url() : site_url('/cart/'),
            'checkout_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : site_url('/checkout/'),
        ],

        'templates' => [
            'cart_bridge' => $base . 'cart/bridge?calendar_id={id}&date=YYYY-MM-DD&start_hour=HH:MM&end_hour=HH:MM',
            'cart_webview'=> $base . 'cart/webview?calendar_id={id}&date=YYYY-MM-DD&start_hour=HH:MM&end_hour=HH:MM',
        ],

        'routes' => $my_routes,
    ];

    return kashiteapp_response($result, 'OK');
}

function kashiteapp_api_meta(WP_REST_Request $request){
  $admin_email = get_option('admin_email');

  // WooCommerce Product Enquiry (woo-product-enquiry) の設定
  $mpe_tab_name      = get_option('mpe_tab_name');
  $mpe_tab_priority  = get_option('mpe_tab_priority');
  $mpe_email_to      = get_option('mpe_email_to');        // Enquiry Form Settings の To Email
  $mpe_email_subject = get_option('mpe_email_subject');   // Enquiry Form Settings の Email Subject

  // プラグイン側と同じフォールバック
  $resolved_to      = $mpe_email_to ? $mpe_email_to : $admin_email;
  $resolved_subject = $mpe_email_subject ? $mpe_email_subject : 'New enquery posted on %%product_name%%';

  $result = [
    'versions' => [
      'api_version'        => KASHITEAPP_API_VERSION,
      'regulation_version' => KASHITEAPP_REGULATION_VERSION,
    ],
    'site' => [
      'home'      => home_url('/'),
      'shop'      => site_url('/shop/'),
      'cart'      => function_exists('wc_get_cart_url') ? (wc_get_cart_url() ?: site_url('/cart/')) : site_url('/cart/'),
      'checkout'  => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : site_url('/checkout/'),
      'myaccount' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : site_url('/my-account/'),
    ],
    'webview_templates' => [
      'cart_bridge'  => rest_url('kashiteapp/v1/cart/bridge')
        . '?calendar_id={calendar_id}&date={Y-m-d}&start_hour={HH:MM}&end_hour={HH:MM}',
      'cart_webview' => rest_url('kashiteapp/v1/cart/webview')
        . '?calendar_id={calendar_id}&date={Y-m-d}&start_hour={HH:MM}&end_hour={HH:MM}',
    ],
    // enquiry（今回追加分）
    'enquiry' => [
      'endpoints' => [
        'preview' => rest_url('kashiteapp/v1/enquiry/preview'),
        'send'    => rest_url('kashiteapp/v1/enquiry'),
      ],
      'form_settings' => [
        'tab_name'     => ($mpe_tab_name !== '' && $mpe_tab_name !== null) ? (string)$mpe_tab_name : 'Enquiry',
        'tab_priority' => $mpe_tab_priority ? (int)$mpe_tab_priority : 40,

        'to_email_raw' => (string)$mpe_email_to,      // 入力された値
        'to_email'     => (string)$resolved_to,       // フォールバック込み
        'admin_email'  => (string)$admin_email,

        'subject_raw'  => (string)$mpe_email_subject,
        'subject'      => (string)$resolved_subject,
      ],
	  'ttl_seconds' => KASHITEAPP_ENQUIRY_TTL,
    ],
    'api' => [
      // health
      'ping' => rest_url('kashiteapp/v1/ping'),

      // filters
      'filters' => rest_url('kashiteapp/v1/filters'),

      // options
      'options' => [
        'space_use'  => rest_url('kashiteapp/v1/option_space_use'),
        'space_type' => rest_url('kashiteapp/v1/option_space_type'),
        'space_area' => rest_url('kashiteapp/v1/option_space_area'),
      ],

      // price
      'price_range' => rest_url('kashiteapp/v1/price_range'),

      // search
      'search' => [
        'url'     => rest_url('kashiteapp/v1/search_url'),
        'results' => rest_url('kashiteapp/v1/search_results'),
      ],

      // product
      'product' => [
        'detail'   => rest_url('kashiteapp/v1/product/{product_id}'),
        'calendar' => rest_url('kashiteapp/v1/product/{product_id}/calendar'),
      ],

      // calendar
      'calendar' => [
        'detail'   => rest_url('kashiteapp/v1/calendar/{calendar_id}') . '?start={Y-m-d}&end={Y-m-d}',
        'product'  => rest_url('kashiteapp/v1/calendar/{calendar_id}/product'),
      ],

      // news
      'news' => rest_url('kashiteapp/v1/news'),

		// enquiry
      'enquiry' => [
        'preview' => rest_url('kashiteapp/v1/enquiry/preview'),
        'send'    => rest_url('kashiteapp/v1/enquiry'),
      ],
    ],
  ];

  return kashiteapp_response($result, 'OK');
}

function kashiteapp_enquiry_hash_from_input( $in ) {
    $base = [
        'product_id' => (int)($in['product_id'] ?? 0),
        'name'       => (string)($in['name'] ?? ''),
        'email'      => (string)($in['email'] ?? ''),
        'phone'      => (string)($in['phone'] ?? ''),
        'enquiry'    => (string)($in['enquiry'] ?? ''),
        'issued_at'  => (int)($in['issued_at'] ?? 0),
    ];

    $json = wp_json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // wp_salt() は scheme が固定なので、確実に取れる auth を使って自前スコープを混ぜる
    $key = hash('sha256', wp_salt('auth') . '|kashiteapp_enquiry');

    return 'sha256:' . hash_hmac('sha256', $json, $key);
}

/**
 * enquiry input を JSON / form どちらでも受けて正規化
 */
function kashiteapp_get_enquiry_input( WP_REST_Request $req ) {
    // JSON が来ていれば優先
    $json = $req->get_json_params();
    $src  = is_array($json) && !empty($json) ? $json : $req->get_params();

    $product_id = isset($src['product_id']) ? (int)$src['product_id'] : 0;

    $name    = isset($src['name'])    ? (string)$src['name']    : '';
    $email   = isset($src['email'])   ? (string)$src['email']   : '';
    $phone   = isset($src['phone'])   ? (string)$src['phone']   : '';
    $enquiry = isset($src['enquiry']) ? (string)$src['enquiry'] : '';

    // trim
    $name    = trim($name);
    $email   = trim($email);
    $phone   = trim($phone);
    $enquiry = trim($enquiry);

	return [
		'product_id' => $product_id,
		'name'       => $name,
		'email'      => $email,
		'phone'      => $phone,
		'enquiry'    => $enquiry,
		'issued_at'  => isset($src['issued_at']) ? (int)$src['issued_at'] : 0,
		'payload_hash' => isset($src['payload_hash']) ? (string)$src['payload_hash'] : '',
	];
}

function kashiteapp_validate_enquiry_input( $in ) {
    if ( empty($in['product_id']) || get_post_type($in['product_id']) !== 'product' ) {
        return new WP_Error('invalid_product_id', 'product_id is invalid');
    }
    if ( $in['name'] === '' ) return new WP_Error('invalid_name', 'name is required');
    if ( $in['email'] === '' || !is_email($in['email']) ) return new WP_Error('invalid_email', 'email is invalid');
    if ( $in['enquiry'] === '' ) return new WP_Error('invalid_enquiry', 'enquiry is required');
    // phone は任意
    return true;
}

/**
 * Product Enquiry の送信先（管理者/オーナー）解決
 * - 基本：商品投稿の author email
 * - フォールバック：admin_email
 */
function kashiteapp_resolve_enquiry_to( $product_id ) {
    $admin_email = get_option('admin_email');

    $post = get_post($product_id);
    $owner_email = $post ? get_the_author_meta('user_email', $post->post_author) : '';

    // 現行実装の思想に寄せる（owner があれば owner）
    $to = $owner_email && is_email($owner_email) ? $owner_email : $admin_email;

    return [
        'admin_email' => (string)$admin_email,
        'owner_email' => (string)($owner_email ?: ''),
        'to'          => (string)$to,
    ];
}

/**
 * 件名：サイト側のルールに合わせたいならここを唯一の真にする
 */
function kashiteapp_build_enquiry_subject( $product_id ) {
    $product = wc_get_product($product_id);
    $name = $product ? $product->get_name() : 'product';
    // 例：店舗名っぽい prefix が必要ならここで組む
    return '[' . $name . '] ご予約お問い合わせ';
}

function kashiteapp_build_enquiry_body( $product_id, $in ) {
    $product = wc_get_product($product_id);
    $pname = $product ? $product->get_name() : '';
    $url   = get_permalink($product_id);

    $lines = [];
    $lines[] = "【KASHITE お問い合わせ】";
    $lines[] = "";
    $lines[] = "商品: {$pname}";
    $lines[] = "URL: {$url}";
    $lines[] = "product_id: {$product_id}";
    $lines[] = "";
    $lines[] = "お名前: {$in['name']}";
    $lines[] = "メール: {$in['email']}";
    $lines[] = "電話: {$in['phone']}";
    $lines[] = "";
    $lines[] = "---- お問い合わせ内容 ----";
    $lines[] = $in['enquiry'];
    $lines[] = "------------------------";
    $lines[] = "";
	$ts = !empty($in['issued_at']) ? (int)$in['issued_at'] : time();
	$lines[] = "送信日時: " . wp_date('Y-m-d H:i:s', $ts);

    return implode("\n", $lines);
}

function kashiteapp_build_enquiry_payload( $product_id, $in ) {
    $to_info = kashiteapp_resolve_enquiry_to($product_id);

    $subject = kashiteapp_build_enquiry_subject($product_id);
    $body    = kashiteapp_build_enquiry_body($product_id, $in);

    // To は owner 優先。なければ admin
    if (!empty($to_info['owner_email']) && is_email($to_info['owner_email'])) {
        $to = $to_info['owner_email'];
    } else {
        $to = $to_info['admin_email'];
    }
    // ★ CC は「必ず admin」
    $cc = $to_info['admin_email'];
	
	$reply_name  = kashiteapp_mail_safe_header_value($in['name']);
	$reply_email = kashiteapp_mail_safe_header_value($in['email']);
	$cc          = kashiteapp_mail_safe_header_value($cc);

	$headers = [
	  'Content-Type: text/plain; charset=UTF-8',
	  'Reply-To: ' . $reply_name . ' <' . $reply_email . '>',
	  'Cc: ' . $cc,
	];

    $payload = [
        'product_id' => (int)$product_id,
        'to'         => (string)$to,
        'cc'         => (string)$cc,     // ★追加
        'subject'    => (string)$subject,
        'body'       => (string)$body,
        'headers'    => $headers,
    ];

	$hash = kashiteapp_enquiry_hash_from_input($in);

    return [
        'payload'      => $payload,
        'payload_hash' => $hash,
        'to_info'      => $to_info,
    ];
}


/**
 * 既存の Product Enquiry プラグインが作る post_type を推測して選ぶ
 * - 環境で違うので、候補を順に当てる
 * - 見つからなければ、kashiteapp_enquiry を作ってそこへ保存（最低限ログは残る）
 */
function kashiteapp_detect_enquiry_post_type() {
    $candidates = [
        'mpe_enquiry',
        'product_enquiry',
        'product_enquiries',
        'enquiry',
        'enquiries',
        'wc_product_enquiry',
    ];
    foreach ($candidates as $pt) {
        if ( post_type_exists($pt) ) return $pt;
    }
    return 'kashiteapp_enquiry';
}

/**
 * フォールバック用 post_type を登録（既存が無い場合だけ使われる）
 */
add_action('init', function(){
    if ( post_type_exists('kashiteapp_enquiry') ) return;

    register_post_type('kashiteapp_enquiry', [
        'label' => 'KASHITE Enquiry',
        'public' => false,
        'show_ui' => true,              // 管理画面で見える（運用上重要）
        'show_in_menu' => true,
        'supports' => ['title', 'editor'],
        'capability_type' => 'post',
    ]);
});

/**
 * Enquiry 投稿を作成（Woo Product Enquiry互換）
 */
function kashiteapp_create_enquiry_post( $product_id, $in, $payload_pack ) {

    // 互換のため固定
    //$pt = 'product_enquiry';
	$pt = kashiteapp_detect_enquiry_post_type();

    $title   = 'Enquiry on ' . get_the_title($product_id);
    $content = $payload_pack['payload']['body'] ?? '';

    $post_id = wp_insert_post([
        'post_type'    => $pt,
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $content, // ログとして残すのはアリ
    ], true);

    if ( is_wp_error($post_id) ) return $post_id;

    // ★本家互換meta（これが無いと一覧に出ない）
    update_post_meta($post_id, '_pe_product', (int)$product_id);
    update_post_meta($post_id, '_pe_name',    $in['name']);
    update_post_meta($post_id, '_pe_email',   $in['email']);
    update_post_meta($post_id, '_pe_phone',   $in['phone']);
    update_post_meta($post_id, '_pe_enquiry', $in['enquiry']);

    // 追加ログ（任意：名前空間推奨）
    update_post_meta($post_id, '_kashite_to',          $payload_pack['payload']['to'] ?? '');
    update_post_meta($post_id, '_kashite_subject',     $payload_pack['payload']['subject'] ?? '');
    update_post_meta($post_id, '_kashite_payload_hash',$payload_pack['payload_hash'] ?? '');

    return (int)$post_id;
}

function kashiteapp_api_enquiry_preview( WP_REST_Request $req ) {
    $in = kashiteapp_get_enquiry_input($req);
    $ok = kashiteapp_validate_enquiry_input($in);
    if ( is_wp_error($ok) ) {
        return kashiteapp_error_response($ok->get_error_code(), $ok->get_error_message(), 400);
    }
	
	$in['issued_at'] = time();
    
	$pack = kashiteapp_build_enquiry_payload($in['product_id'], $in);

    return kashiteapp_response([
		'issued_at'   => $in['issued_at'],
        'payload'      => $pack['payload'],
        'payload_hash' => $pack['payload_hash'],
        'input_echo'   => [
            'name'    => $in['name'],
            'email'   => $in['email'],
            'phone'   => $in['phone'],
            'enquiry' => $in['enquiry'],
        ],
        'to_info' => $pack['to_info'],
    ], 'OK');
}

function kashiteapp_api_enquiry_send( WP_REST_Request $req ) {
    $in = kashiteapp_get_enquiry_input($req);
    $ok = kashiteapp_validate_enquiry_input($in);
    if ( is_wp_error($ok) ) {
        return kashiteapp_error_response($ok->get_error_code(), $ok->get_error_message(), 400);
    }

	if ( empty($in['issued_at']) ) {
		return kashiteapp_error_response('issued_at_required', 'issued_at is required', 400);
	}
	if ( $in['payload_hash'] === '' ) {
		return kashiteapp_error_response('payload_hash_required', 'payload_hash is required', 400);
	}
	
	$now = time();
	$iat = (int)$in['issued_at'];
	if ( $iat > $now + 60 || ($now - $iat) > KASHITEAPP_ENQUIRY_TTL ) {
		return kashiteapp_error_response('expired', 'request expired', 400, [
			'now' => $now,
			'issued_at' => $iat,
			'ttl' => KASHITEAPP_ENQUIRY_TTL,
		]);
	}
	
    // payload 作成
    $pack = kashiteapp_build_enquiry_payload($in['product_id'], $in);

    // クライアントが payload_hash を送ってきたら一致チェック（任意だが運用上強い）
	if ( $in['payload_hash'] !== $pack['payload_hash'] ) {
		return kashiteapp_error_response('payload_hash_mismatch', 'payload_hash mismatch', 400, [
			'expected' => $pack['payload_hash'],
			'given'    => $in['payload_hash'],
		]);
	}	

    // ① 投稿作成（本家動作の維持）
    $post_id = kashiteapp_create_enquiry_post($in['product_id'], $in, $pack);
    if ( is_wp_error($post_id) ) {
        return kashiteapp_error_response($post_id->get_error_code(), $post_id->get_error_message(), 500);
    }

    // ② メール送信
    $p = $pack['payload'];
    $mail_ok = wp_mail($p['to'], $p['subject'], $p['body'], $p['headers']);

    $result = [
        'post' => [
            'post_id'   => (int)$post_id,
//			'post_type' => 'product_enquiry',
			'post_type' => kashiteapp_detect_enquiry_post_type(),
            'edit_link' => get_edit_post_link($post_id, 'raw'),
            'view_link' => get_permalink($post_id), // public=false の場合は使えないが、返して害はない
        ],
		'mail' => [
		  'ok' => (bool)$mail_ok,
		  'to' => $p['to'],
		  'cc' => $pack['to_info']['admin_email'] ?? null,
		],
        'payload_hash' => $pack['payload_hash'],
    ];

    // 投稿成功 + メール失敗は 200 で返す（運用で「管理画面に残ってる」を最優先）
    if ( !$mail_ok ) {
        return kashiteapp_response($result, 'POST CREATED but MAIL FAILED', 'partial_success', 200);
    }

    return kashiteapp_response($result, 'OK');
}


/**
 * REST ルート登録
 */
add_action( 'rest_api_init', function () {

    register_rest_route('kashiteapp/v1', '/', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'kashiteapp_api_index',
        'permission_callback' => '__return_true',
    ]);
	
	register_rest_route('kashiteapp/v1','/meta',[
	  'methods'=>WP_REST_Server::READABLE,
	  'callback'=>'kashiteapp_api_meta',
	  'permission_callback'=>'__return_true',
	]);
	
    register_rest_route(
        'kashiteapp/v1',
        '/ping',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_ping',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/filters',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_filters',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/option_space_use',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_option_space_use',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/option_space_type',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_option_space_type',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/option_space_area',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_option_space_area',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/price_range',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_price_range',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/search_url',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_search_url',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/search_results',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_search_results',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/product/(?P<product_id>\d+)/calendar',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_product_calendar',
            'permission_callback' => '__return_true',
        ]
    );

    // 強化版 calendar API（settings / rule 付き）
    register_rest_route(
        'kashiteapp/v1',
        '/calendar/(?P<calendar_id>\d+)',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_calendar',
            'permission_callback' => '__return_true',
            'args'                => [
                'calendar_id' => [
                    'required' => true,
                    'type'     => 'integer',
                ],
                'start' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'end' => [
                    'required' => false,
                    'type'     => 'string',
                ],
            ],
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/debug_meta/(?P<post_id>\d+)',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_debug_meta',
            'permission_callback' => '__return_true',
        ]
    );

    register_rest_route(
        'kashiteapp/v1',
        '/test/run',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_test_run',
            'permission_callback' => '__return_true',
        ]
    );

    // 商品詳細取得
    register_rest_route(
        'kashiteapp/v1',
        '/product/(?P<product_id>\d+)',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_product_detail',
            'permission_callback' => '__return_true',
            'args'                => [
                'product_id' => [
                    'required'          => true,
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && (int) $param > 0;
                    },
                ],
            ],
        ]
    );

    // DEBUG WOOF
    register_rest_route(
        'kashiteapp/v1',
        '/debug/woof',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_debug_woof',
            'permission_callback' => '__return_true',
        ]
    );

    // NEWS
    register_rest_route(
        'kashiteapp/v1',
        '/news',
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'kashiteapp_api_news_list',
            'permission_callback' => '__return_true',
        ]
    );
	
	// calendar_id -> product
	register_rest_route(
	  'kashiteapp/v1',
	  '/calendar/(?P<calendar_id>\d+)/product',
	  [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'kashiteapp_api_calendar_product',
		'permission_callback' => '__return_true',
	  ]
	);

	// cart/payload（POST）
	register_rest_route(
	  'kashiteapp/v1',
	  '/cart/payload',
	  [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'kashiteapp_api_cart_payload',
		'permission_callback' => '__return_true',
	  ]
	);

	// cart/webview（GET, JSON）
	register_rest_route(
	  'kashiteapp/v1',
	  '/cart/webview',
	  [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'kashiteapp_api_cart_webview',
		'permission_callback' => '__return_true',
	  ]
	);

	// cart/bridge（GET, HTML）
//	register_rest_route(
//	  'kashiteapp/v1',
//	  '/cart/bridge',
//	  [
//		'methods'             => WP_REST_Server::READABLE,
//		'callback'            => 'kashiteapp_api_cart_bridge',
//		'permission_callback' => '__return_true',
//	  ]
//	);
	register_rest_route('kashiteapp/v1','/cart/bridge',[
	  'methods'=>WP_REST_Server::READABLE,
	  'callback'=>'kashiteapp_api_cart_bridge',
	  'permission_callback'=>'__return_true',
	  'args'=>[
		'calendar_id'=>['required'=>true,'type'=>'integer'],
		'date'=>['required'=>true,'type'=>'string'],
		'start_hour'=>['required'=>true,'type'=>'string'],
		'end_hour'=>['required'=>true,'type'=>'string'],
	  ],
	]);
	
	// enquiry/preview（POST）
	register_rest_route('kashiteapp/v1', '/enquiry/preview', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'kashiteapp_api_enquiry_preview',
		'permission_callback' => '__return_true',
	]);

	// enquiry（POST）
	register_rest_route('kashiteapp/v1', '/enquiry', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'kashiteapp_api_enquiry_send',
		'permission_callback' => '__return_true',
	]);


	
} );


