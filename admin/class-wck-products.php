<?php

// Reference for Products Variables: https://stackoverflow.com/questions/47518280/create-programmatically-a-woocommerce-product-variation-with-new-attribute-value

/**
 * The admin-specific functionality of the plugin.
 *
 * Sync products to WooCommerce from GestãoClick API
 *
 * @package    Wooclick
 * @subpackage Wooclick/admin
 * @author     Oswaldo Cavalcante <contato@oswaldocavalcante.com>
 */

include_once 'class-wck-gc-api.php';

class WCK_Products extends WCK_GC_Api {

    private $api_endpoint;
    private $api_headers;

    public function __construct() {
        parent::__construct();

        $this->api_endpoint = parent::get_endpoint_products();
        $this->api_headers =  parent::get_headers();

        add_filter( 'wooclick_import_products', array( $this, 'import' ) );
    }

    public function fetch_api() {
        $products = [];
        $proxima_pagina = 1;

        do {
            $body = wp_remote_retrieve_body( 
                wp_remote_get( $this->api_endpoint . '?pagina=' . $proxima_pagina, $this->api_headers )
            );

            $body_array = json_decode($body, true);
            $proxima_pagina = $body_array['meta']['proxima_pagina'];

            $products = array_merge( $products, $body_array['data'] );

        } while ( $proxima_pagina != null );

        update_option( 'wooclick-products', $products );
    }

    public function import( $products_codes ) {
        if (!class_exists('WC_Product')) {
            include_once WC_ABSPATH . 'includes/abstracts/abstract-wc-product.php';
        }

        $products =             get_option( 'wooclick-products' );
        $products_blacklist =   get_option( 'wck-settings-blacklist-products' );
        $categories_blacklist = get_option( 'wck-settings-blacklist-categories' );
        $selectedProducts =     array();

        if( $categories_blacklist ) {
            $filteredCategories = array_filter($products, function ($item) use ($categories_blacklist) {
                return (!in_array($item['nome_grupo'], $categories_blacklist));
            });
            $products = $filteredCategories;
        }

        if( $products_blacklist ) {
            $filteredProducts = array_filter($products, function ($item) use ($products_blacklist) {
                return (!in_array($item['codigo_barra'], $products_blacklist));
            });
            $products = $filteredProducts;
        }

        if( is_array($products_codes) ) {
            $selectedProducts = array_filter($products, function ($item) use ($products_codes) {
                return (in_array($item['codigo_barra'], $products_codes));
            });
        } elseif( $products_codes == 'all' ) {
            $selectedProducts = $products;
        }

        foreach ($selectedProducts as $product) {
            // Check if the product has variations
            if ($product['possui_variacao'] == '1') {

                // Saving the product variable
                $product_variable = $this->save_product_variable($product);

                // Saving the product variable attributes
                $attributes = [];
                $attributes[] = $this->save_product_variable_attributes($product['variacoes']);

                // Adding the attributes to the created product variable
                $product_variable->set_attributes($attributes);
                $product_variable->save();

                // Saving the product variable variations
                $this->save_product_variable_variations($product_variable->get_id(), $product['variacoes']);

            } else {
                $this->save_product_simple($product);
            }
        }

        $import_notice = sprintf('%d produtos importados com sucesso.', count($selectedProducts));
        set_transient('wooclick_import_notice', $import_notice, 30); // Ajuste o tempo conforme necessário
    }

    private function get_category_ids( $category_name ) {
        $category_ids = array();
        $category_object = get_term_by('slug', sanitize_title($category_name), 'product_cat');

        if ($category_object != false) {
            $category_ids[] = $category_object->term_id;
        }

        return $category_ids;
    }

    private function save_product_simple( $product ) {
        $category_ids = $this->get_category_ids($product['nome_grupo']);

        $product_props = array(
            'sku' =>            $product['codigo_barra'],
            'name' =>           $product['nome'],
            'regular_price' =>  $product['valor_venda'],
            'sale_price' =>     $product['valor_venda'],
            'description' =>    $product['descricao'],
            'stock_quantity' => $product['estoque'],
            'date_created' =>   $product['cadastrado_em'],
            'date_modified' =>  $product['modificado_em'],
            'description' =>    $product['descricao'],
            'weight' =>         $product['peso'],
            'length' =>         $product['comprimento'],
            'width' =>          $product['largura'],
            'height' =>         $product['altura'],
            'category_ids' =>   $category_ids,
            'manage_stock' =>   'true',
            'backorders' =>     'no',
        );

        $product_exists = wc_get_product_id_by_sku($product_props['sku']);
        $product_simple = null;

        if ($product_exists) {
            $product_simple = wc_get_product($product_exists);
        } else {
            $product_simple = new WC_Product_Simple();
            $product_simple->add_meta_data( 'wooclick_gc_product_id', (int) $product['id'], true );
        }

        $product_simple->set_props($product_props);
        $product_simple->save();
    }

    private function save_product_variable( $product ) {
        $category_ids = $this->get_category_ids($product['nome_grupo']);

        $product_props = array(
            'sku' =>            $product['codigo_barra'],
            'name' =>           $product['nome'],
            'regular_price' =>  $product['valor_venda'],
            'sale_price' =>     $product['valor_venda'],
            'price' =>          $product['valor_venda'],
            'description' =>    $product['descricao'],
            'stock_quantity' => $product['estoque'],
            'date_created' =>   $product['cadastrado_em'],
            'date_modified' =>  $product['modificado_em'],
            'description' =>    $product['descricao'],
            'weight' =>         $product['peso'],
            'length' =>         $product['comprimento'],
            'width' =>          $product['largura'],
            'height' =>         $product['altura'],
            'category_ids' =>   $category_ids,
            'manage_stock' =>   'true',
            'backorders' =>     'no',
        );

        $product_exists = wc_get_product_id_by_sku($product['codigo_barra']);
        $product_variable = null;

        if($product_exists) {
            $product_variable = wc_get_product($product_exists);
        } else {
            $product_variable = new WC_Product_Variable();
            $product_variable->add_meta_data( 'wooclick_gc_product_id', (int) $product['id'], true );
        }

        $product_variable->set_props($product_props);
        $product_variable->save();

        return $product_variable;
    }

    private function save_product_variable_attributes( $variations ) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_id(0);
        $attribute->set_name('modelo');
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $options = array();
        foreach( $variations as $variation ) {
            array_push( $options, $variation['variacao']['nome'] );
        }
        $attribute->set_options($options);

        return $attribute;
    }

    private function save_product_variable_variations( $product_variable_id, $variations ) {
        foreach ($variations as $variation_data) {

            $sku = $variation_data['variacao']['codigo'];
            $variation_id_exists = wc_get_product_id_by_sku($sku);
            $variation = null;

            if ($variation_id_exists) {
                $variation = wc_get_product($variation_id_exists);
            } else {
                $variation = new WC_Product_Variation();
                $variation->set_sku($variation_data['variacao']['codigo']);
                $variation->add_meta_data( 'wooclick_gc_variation_id', (int) $variation_data['variacao']['id'], true );
            }
            
            $variation->set_parent_id($product_variable_id);
            $variation->set_status('publish');
            $variation->set_price($variation_data['variacao']['valores'][0]['valor_venda']);
            $variation->set_regular_price($variation_data['variacao']['valores'][0]['valor_venda']);
            $variation->set_stock_status();
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($variation_data['variacao']['estoque']);
            $attributes = array(
                'modelo' => $variation_data['variacao']['nome']
            );
            $variation->set_attributes($attributes);
            $variation->save();

            $product = wc_get_product($product_variable_id);
            $product->save();
        }
    }

    public function display() {
        $this->fetch_api();
        require_once 'partials/wooclick-admin-display-products.php';
    }

    // private function save_product_variable($product) {

    //     $category_ids = $this->get_category_ids($product['nome_grupo']);

    //     $product_props = array(
    //         'sku' =>            $product['codigo_barra'],
    //         'name' =>           $product['nome'],
    //         'regular_price' =>  $product['valor_venda'],
    //         'sale_price' =>     $product['valor_venda'],
    //         'price' =>          $product['valor_venda'],
    //         'description' =>    $product['descricao'],
    //         'stock_quantity' => $product['estoque'],
    //         'date_created' =>   $product['cadastrado_em'],
    //         'date_modified' =>  $product['modificado_em'],
    //         'description' =>    $product['descricao'],
    //         'weight' =>         $product['peso'],
    //         'length' =>         $product['comprimento'],
    //         'width' =>          $product['largura'],
    //         'height' =>         $product['altura'],
    //         'category_ids' =>   $category_ids,
    //         'manage_stock' =>   'true',
    //         'backorders' =>     'notify',
    //     );

    //     $product_exists = wc_get_product_id_by_sku($product['codigo_barra']);
    //     $product_variable = null;

    //     if($product_exists) {
    //         $product_variable = wc_get_product($product_exists);
    //         $product_variable->set_props($product_props);
            
    //     } else {
    //         $product_variable = new WC_Product_Variable();
    //         $product_variable->set_props($product_props);
    //     }
        
    //     $product_variable->save();
    //     $this->add_product_variations( $product_variable, $product['variacoes'] );
    // }

    // private function add_product_variations( $product_variable, $variations ) {
    //     foreach ($variations as $variation_data) {
            
    //         $attribute_name = 'variation';
    //         $term_name = $variation_data['variacao']['nome'];

    //         $variation_post = array(
    //             'post_title'  => $product_variable->get_name(),
    //             'post_name'   => 'product-'. $product_variable->get_id() .'-variation',
    //             'post_status' => 'publish',
    //             'post_parent' => $product_variable->get_id(),
    //             'post_type'   => 'product_variation',
    //             'guid'        => $product_variable->get_permalink()
    //         );

    //         // Creating the product variation
    //         $variation_id = wp_insert_post( $variation_post );

    //         // Setando o Parent ID
    //         wp_set_object_terms( $variation_id, $product_variable->get_id(), 'product_variation' );

    //         // Create product variation attribute
    //         $this->add_product_variation_attribute( $product_variable->get_id(), $variation_id, $attribute_name, $term_name );

    //         ### Associating variation to the product
    //         $sku = $variation_data['variacao']['codigo'];
    //         $variation_id_exists = wc_get_product_id_by_sku($sku);
    //         $variation = null;

    //         if ($variation_id_exists) {
    //             $variation = wc_get_product($variation_id_exists);
    //         } else {
    //             $variation = new WC_Product_Variation( $variation_id );
    //             $variation->set_parent_id($product_variable->get_id());
    //             $variation->set_sku($variation_data['variacao']['codigo']);
    //         }

    //         $variation->set_parent_id($product_variable->get_id());
    //         $variation->set_price($variation_data['variacao']['valores'][0]['valor_venda']);
    //         $variation->set_regular_price($variation_data['variacao']['valores'][0]['valor_venda']);
    //         $variation->set_sale_price($variation_data['variacao']['valores'][0]['valor_venda']);
    //         $variation->set_stock_quantity($variation_data['variacao']['estoque']);
    //         $variation->set_manage_stock(true);
    //         $variation->set_attributes( array(
    //             'variation' => $variation_data['variacao']['nome'],
    //         ) );
    //         $variation_id = $variation->save();

    //         $existing_variations = $product_variable->get_children();
    //         $existing_variations[] = $variation_id;
    //         $product_variable->set_children($existing_variations);
    //     }
    // }

    // private function add_product_variation_attribute( $product_id, $variation_id, $attribute_name, $term_name) {
            
    //     ### 1 - Creating taxonomy for variation attribute

    //     $taxonomy = 'pa_'. sanitize_title( $attribute_name ); // The attribute taxonomy
    //     clean_taxonomy_cache( $taxonomy );

    //     // If attribute doesn't exists we create it 
    //     $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);
    //     if (!$attribute_id) {
    //         $attribute_args = array(
    //             'name' => $attribute_name,
    //             'slug' => sanitize_title($attribute_name),
    //             'type' => 'select',
    //         );
    //         $attribute_id = wc_create_attribute($attribute_args);
    //     }

    //     if (!taxonomy_exists( $taxonomy )) {
    //         register_taxonomy(
    //             $taxonomy,
    //            'product_variation',
    //             array(
    //                 'hierarchical' => false,
    //                 'label' => ucfirst( $attribute_name ),
    //                 'query_var' => true,
    //                 'rewrite' => array( 'slug' => sanitize_title($attribute_name) ), // The base slug
    //             ),
    //         );
    //     } 


    //     ### 2 - Creating terms for the product attribute

    //     if( ! term_exists( $term_name, $taxonomy ) ) {
    //         wp_insert_term( $term_name, $taxonomy ); // Create the term
    //     }



    //     ### 3 -Associating attribute and its terms to the parent product

    //     $term = get_term_by('name', $term_name, $taxonomy ); // Get the term slug

    //     // Get the post Terms names from the parent variable product.
    //     $post_term_names = wp_get_post_terms( $product_id, $taxonomy, array('fields' => 'names') );

    //     // Check if the post term exist and if not we set it in the parent variable product.
    //     if( ! in_array( $term_name, $post_term_names ) ) {
    //         $wp_set_post_terms = wp_set_post_terms( $product_id, $term_name, $taxonomy, true );
    //     }
    //     // Set/save the attribute data in the product variation
    //     $update_post_meta = update_post_meta( $variation_id, 'attribute_'.$taxonomy, $term->slug );
    // }
}