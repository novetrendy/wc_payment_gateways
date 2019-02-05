<?php
/*
 * Plugin Name: Platební brány
 * Plugin URI: https://webstudionovetrendy.eu
 * Description: Přidá další hotovostní platební brány do eshopu
 * Author: WebstudioNoveTrendy
 * Author URI: https://webstudionovetrendy.eu
 * Version: 1.0.0
 */

add_action( 'plugins_loaded', 'init_cp_obycejny_balik_dobirka_gateway_class' );
function init_cp_obycejny_balik_dobirka_gateway_class() {

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'cp_obycejny_balik_dobirka_add_gateway_class' );
function cp_obycejny_balik_dobirka_add_gateway_class( $methods ) {
    $methods[] = 'WC_cp_obycejny_balik_dobirka_Gateway'; // your class name is here
    return $methods;
}

/**
 * Česká pošta obyčejný balík dobírka.
 *
 * Provides a Cash on Delivery Payment Gateway.
 *
 * @class       WC_cp_obycejny_balik_dobirka_Gateway
 * @extends     WC_Payment_Gateway
 * @version     2.1.0
 * @package     WooCommerce/Classes/Payment
 */
class WC_cp_obycejny_balik_dobirka_Gateway extends WC_Payment_Gateway {

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        // Setup general properties.
        $this->setup_properties();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Get settings.
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions' );
        $this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
        $this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );

        // Customer Emails.
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
    }

    /**
     * Setup general properties for the gateway.
     */
    protected function setup_properties() {
        $this->id                 = 'cp_obycejny_balik_dobirka';
        $this->icon               = apply_filters( 'woocommerce_cod_icon', '' );
        $this->method_title       = __( 'ČP obyčejný balík dobírka', 'woocommerce' );
        $this->method_description = __( 'Have your customers pay with cash (or by other means) upon delivery.', 'woocommerce' );
        $this->has_fields         = false;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {

        $options    = array();
        $data_store = WC_Data_Store::load( 'shipping-zone' );
        $raw_zones  = $data_store->get_zones();

        foreach ( $raw_zones as $raw_zone ) {
            $zones[] = new WC_Shipping_Zone( $raw_zone );
        }

        $zones[] = new WC_Shipping_Zone( 0 );

        foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

            $options[ $method->get_method_title() ] = array();

            // Translators: %1$s shipping method name.
            $options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $method->get_method_title() );

            foreach ( $zones as $zone ) {

                $shipping_method_instances = $zone->get_shipping_methods();

                foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {

                    if ( $shipping_method_instance->id !== $method->id ) {
                        continue;
                    }

                    $option_id = $shipping_method_instance->get_rate_id();

                    // Translators: %1$s shipping method title, %2$s shipping method id.
                    $option_instance_title = sprintf( __( '%1$s (#%2$s)', 'woocommerce' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );

                    // Translators: %1$s zone name, %2$s shipping method instance name.
                    $option_title = sprintf( __( '%1$s &ndash; %2$s', 'woocommerce' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'woocommerce' ), $option_instance_title );

                    $options[ $method->get_method_title() ][ $option_id ] = $option_title;
                }
            }
        }

        $this->form_fields = array(
            'enabled'            => array(
                'title'       => __( 'Enable/Disable', 'woocommerce' ),
                'label'       => __( 'Enable cash on delivery', 'woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title'              => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
                'default'     => __( 'Cash on delivery', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'description'        => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'instructions'       => array(
                'title'       => __( 'Instructions', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'enable_for_methods' => array(
                'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 400px;',
                'default'           => '',
                'description'       => __( 'If COD is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce' ),
                'options'           => $options,
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Select shipping methods', 'woocommerce' ),
                ),
            ),
            'enable_for_virtual' => array(
                'title'   => __( 'Accept for virtual orders', 'woocommerce' ),
                'label'   => __( 'Accept COD if the order is virtual', 'woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
        );
    }

    /**
     * Check If The Gateway Is Available For Use.
     *
     * @return bool
     */
    public function is_available() {
        $order          = null;
        $needs_shipping = false;

        // Test if shipping is needed first.
        if ( WC()->cart && WC()->cart->needs_shipping() ) {
            $needs_shipping = true;
        } elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );

            // Test if order needs shipping.
            if ( 0 < count( $order->get_items() ) ) {
                foreach ( $order->get_items() as $item ) {
                    $_product = $item->get_product();
                    if ( $_product && $_product->needs_shipping() ) {
                        $needs_shipping = true;
                        break;
                    }
                }
            }
        }

        $needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

        // Virtual order, with virtual disabled.
        if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
            return false;
        }

        // Only apply if all packages are being shipped via chosen method, or order is virtual.
        if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
            $order_shipping_items            = is_object( $order ) ? $order->get_shipping_methods() : false;
            $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

            if ( $order_shipping_items ) {
                $canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids( $order_shipping_items );
            } else {
                $canonical_rate_ids = $this->get_canonical_package_rate_ids( $chosen_shipping_methods_session );
            }

            if ( ! count( $this->get_matching_rates( $canonical_rate_ids ) ) ) {
                return false;
            }
        }

        return parent::is_available();
    }

    /**
     * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
     *
     * @since  3.4.0
     *
     * @param  array $order_shipping_items  Array of WC_Order_Item_Shipping objects.
     * @return array $canonical_rate_ids    Rate IDs in a canonical format.
     */
    private function get_canonical_order_shipping_item_rate_ids( $order_shipping_items ) {

        $canonical_rate_ids = array();

        foreach ( $order_shipping_items as $order_shipping_item ) {
            $canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
        }

        return $canonical_rate_ids;
    }

    /**
     * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
     *
     * @since  3.4.0
     *
     * @param  array $chosen_package_rate_ids Rate IDs as generated by shipping methods. Can be anything if a shipping method doesn't honor WC conventions.
     * @return array $canonical_rate_ids  Rate IDs in a canonical format.
     */
    private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

        $shipping_packages  = WC()->shipping->get_packages();
        $canonical_rate_ids = array();

        if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
            foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
                if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
                    $chosen_rate          = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
                    $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                }
            }
        }

        return $canonical_rate_ids;
    }

    /**
     * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
     *
     * @since  3.4.0
     *
     * @param array $rate_ids Rate ids to check.
     * @return boolean
     */
    private function get_matching_rates( $rate_ids ) {
        // First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
        return array_unique( array_merge( array_intersect( $this->enable_for_methods, $rate_ids ), array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) ) ) );
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( $order->get_total() > 0 ) {
            // Mark as processing or on-hold (payment won't be taken until delivery).
            $order->update_status( apply_filters( 'woocommerce_cp_obycejny_balik_dobirka_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ), __( 'Payment to be made upon delivery.', 'woocommerce' ) );
        } else {
            $order->payment_complete();
        }

        // Remove cart.
        WC()->cart->empty_cart();

        // Return thankyou redirect.
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page() {
        if ( $this->instructions ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
        }
    }

    /**
     * Change payment complete order status to completed for COD orders.
     *
     * @since  3.1.0
     * @param  string         $status Current order status.
     * @param  int            $order_id Order ID.
     * @param  WC_Order|false $order Order object.
     * @return string
     */
    public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
        if ( $order && 'cp_obycejny_balik_dobirka' === $order->get_payment_method() ) {
            $status = 'completed';
        }
        return $status;
    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order Order object.
     * @param bool     $sent_to_admin  Sent to admin.
     * @param bool     $plain_text Email format: plain text or HTML.
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
        }
    }
}
}


add_action( 'plugins_loaded', 'init_cp_obchodni_balik_dobirka_gateway_class' );
function init_cp_obchodni_balik_dobirka_gateway_class() {



/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'cp_obchodni_balik_dobirka_add_gateway_class' );
function cp_obchodni_balik_dobirka_add_gateway_class( $methods ) {
    $methods[] = 'WC_cp_obchodni_balik_dobirka_Gateway'; // your class name is here
    return $methods;
}

/**
 * Česká pošta obchodní balík dobírka
 */
class WC_cp_obchodni_balik_dobirka_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->setup_properties();
        $this->init_form_fields();
        $this->init_settings();
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions' );
        $this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
        $this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
    }

    protected function setup_properties() {
        $this->id                 = 'cp_obchodni_balik_dobirka';
        $this->icon               = apply_filters( 'woocommerce_cod_icon', '' );
        $this->method_title       = __( 'ČP obchodní balík dobírka', 'woocommerce' );
        $this->method_description = __( 'Have your customers pay with cash (or by other means) upon delivery.', 'woocommerce' );
        $this->has_fields         = false;
    }

    public function init_form_fields() {

        $options    = array();
        $data_store = WC_Data_Store::load( 'shipping-zone' );
        $raw_zones  = $data_store->get_zones();

        foreach ( $raw_zones as $raw_zone ) {
            $zones[] = new WC_Shipping_Zone( $raw_zone );
        }

        $zones[] = new WC_Shipping_Zone( 0 );

        foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

            $options[ $method->get_method_title() ] = array();

            $options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $method->get_method_title() );

            foreach ( $zones as $zone ) {

                $shipping_method_instances = $zone->get_shipping_methods();

                foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {

                    if ( $shipping_method_instance->id !== $method->id ) {
                        continue;
                    }

                    $option_id = $shipping_method_instance->get_rate_id();

                    // Translators: %1$s shipping method title, %2$s shipping method id.
                    $option_instance_title = sprintf( __( '%1$s (#%2$s)', 'woocommerce' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );

                    // Translators: %1$s zone name, %2$s shipping method instance name.
                    $option_title = sprintf( __( '%1$s &ndash; %2$s', 'woocommerce' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'woocommerce' ), $option_instance_title );

                    $options[ $method->get_method_title() ][ $option_id ] = $option_title;
                }
            }
        }

        $this->form_fields = array(
            'enabled'            => array(
                'title'       => __( 'Enable/Disable', 'woocommerce' ),
                'label'       => __( 'Enable cash on delivery', 'woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title'              => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
                'default'     => __( 'Cash on delivery', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'description'        => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'instructions'       => array(
                'title'       => __( 'Instructions', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'enable_for_methods' => array(
                'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 400px;',
                'default'           => '',
                'description'       => __( 'If COD is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce' ),
                'options'           => $options,
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Select shipping methods', 'woocommerce' ),
                ),
            ),
            'enable_for_virtual' => array(
                'title'   => __( 'Accept for virtual orders', 'woocommerce' ),
                'label'   => __( 'Accept COD if the order is virtual', 'woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
        );
    }

    public function is_available() {
        $order          = null;
        $needs_shipping = false;

        if ( WC()->cart && WC()->cart->needs_shipping() ) {
            $needs_shipping = true;
        } elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );

            if ( 0 < count( $order->get_items() ) ) {
                foreach ( $order->get_items() as $item ) {
                    $_product = $item->get_product();
                    if ( $_product && $_product->needs_shipping() ) {
                        $needs_shipping = true;
                        break;
                    }
                }
            }
        }

        $needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

       if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
            return false;
        }

        if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
            $order_shipping_items            = is_object( $order ) ? $order->get_shipping_methods() : false;
            $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

            if ( $order_shipping_items ) {
                $canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids( $order_shipping_items );
            } else {
                $canonical_rate_ids = $this->get_canonical_package_rate_ids( $chosen_shipping_methods_session );
            }

            if ( ! count( $this->get_matching_rates( $canonical_rate_ids ) ) ) {
                return false;
            }
        }

        return parent::is_available();
    }

    private function get_canonical_order_shipping_item_rate_ids( $order_shipping_items ) {

        $canonical_rate_ids = array();

        foreach ( $order_shipping_items as $order_shipping_item ) {
            $canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
        }

        return $canonical_rate_ids;
    }

    private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

        $shipping_packages  = WC()->shipping->get_packages();
        $canonical_rate_ids = array();

        if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
            foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
                if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
                    $chosen_rate          = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
                    $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                }
            }
        }

        return $canonical_rate_ids;
    }

    private function get_matching_rates( $rate_ids ) {
        return array_unique( array_merge( array_intersect( $this->enable_for_methods, $rate_ids ), array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) ) ) );
    }


    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( $order->get_total() > 0 ) {
            $order->update_status( apply_filters( 'woocommerce_cp_obchodni_balik_dobirka_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ), __( 'Payment to be made upon delivery.', 'woocommerce' ) );
        } else {
            $order->payment_complete();
        }

        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }


    public function thankyou_page() {
        if ( $this->instructions ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
        }
    }


    public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
        if ( $order && 'cp_obchodni_balik_dobirka' === $order->get_payment_method() ) {
            $status = 'completed';
        }
        return $status;
    }

    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
        }
    }
}
}


add_action( 'plugins_loaded', 'init_ppl_dobirka_gateway_class' );
function init_ppl_dobirka_gateway_class() {



/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'ppl_dobirka_add_gateway_class' );
function ppl_dobirka_add_gateway_class( $methods ) {
    $methods[] = 'WC_ppl_dobirka_Gateway'; // your class name is here
    return $methods;
}

/**
 * PPL dobírka.
 */
class WC_ppl_dobirka_Gateway extends WC_Payment_Gateway {

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        // Setup general properties.
        $this->setup_properties();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Get settings.
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions' );
        $this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
        $this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );

        // Customer Emails.
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
    }

    /**
     * Setup general properties for the gateway.
     */
    protected function setup_properties() {
        $this->id                 = 'ppl_dobirka';
        $this->icon               = apply_filters( 'woocommerce_cod_icon', '' );
        $this->method_title       = __( 'PPL dobírka', 'woocommerce' );
        $this->method_description = __( 'Have your customers pay with cash (or by other means) upon delivery.', 'woocommerce' );
        $this->has_fields         = false;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {

        $options    = array();
        $data_store = WC_Data_Store::load( 'shipping-zone' );
        $raw_zones  = $data_store->get_zones();

        foreach ( $raw_zones as $raw_zone ) {
            $zones[] = new WC_Shipping_Zone( $raw_zone );
        }

        $zones[] = new WC_Shipping_Zone( 0 );

        foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

            $options[ $method->get_method_title() ] = array();

            // Translators: %1$s shipping method name.
            $options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $method->get_method_title() );

            foreach ( $zones as $zone ) {

                $shipping_method_instances = $zone->get_shipping_methods();

                foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {

                    if ( $shipping_method_instance->id !== $method->id ) {
                        continue;
                    }

                    $option_id = $shipping_method_instance->get_rate_id();

                    // Translators: %1$s shipping method title, %2$s shipping method id.
                    $option_instance_title = sprintf( __( '%1$s (#%2$s)', 'woocommerce' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );

                    // Translators: %1$s zone name, %2$s shipping method instance name.
                    $option_title = sprintf( __( '%1$s &ndash; %2$s', 'woocommerce' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'woocommerce' ), $option_instance_title );

                    $options[ $method->get_method_title() ][ $option_id ] = $option_title;
                }
            }
        }

        $this->form_fields = array(
            'enabled'            => array(
                'title'       => __( 'Enable/Disable', 'woocommerce' ),
                'label'       => __( 'Enable cash on delivery', 'woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title'              => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
                'default'     => __( 'Cash on delivery', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'description'        => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'instructions'       => array(
                'title'       => __( 'Instructions', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'enable_for_methods' => array(
                'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 400px;',
                'default'           => '',
                'description'       => __( 'If COD is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce' ),
                'options'           => $options,
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Select shipping methods', 'woocommerce' ),
                ),
            ),
            'enable_for_virtual' => array(
                'title'   => __( 'Accept for virtual orders', 'woocommerce' ),
                'label'   => __( 'Accept COD if the order is virtual', 'woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
        );
    }

    public function is_available() {
        $order          = null;
        $needs_shipping = false;

        // Test if shipping is needed first.
        if ( WC()->cart && WC()->cart->needs_shipping() ) {
            $needs_shipping = true;
        } elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );

            // Test if order needs shipping.
            if ( 0 < count( $order->get_items() ) ) {
                foreach ( $order->get_items() as $item ) {
                    $_product = $item->get_product();
                    if ( $_product && $_product->needs_shipping() ) {
                        $needs_shipping = true;
                        break;
                    }
                }
            }
        }

        $needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

        // Virtual order, with virtual disabled.
        if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
            return false;
        }

        // Only apply if all packages are being shipped via chosen method, or order is virtual.
        if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
            $order_shipping_items            = is_object( $order ) ? $order->get_shipping_methods() : false;
            $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

            if ( $order_shipping_items ) {
                $canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids( $order_shipping_items );
            } else {
                $canonical_rate_ids = $this->get_canonical_package_rate_ids( $chosen_shipping_methods_session );
            }

            if ( ! count( $this->get_matching_rates( $canonical_rate_ids ) ) ) {
                return false;
            }
        }

        return parent::is_available();
    }

    private function get_canonical_order_shipping_item_rate_ids( $order_shipping_items ) {

        $canonical_rate_ids = array();

        foreach ( $order_shipping_items as $order_shipping_item ) {
            $canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
        }

        return $canonical_rate_ids;
    }

    private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

        $shipping_packages  = WC()->shipping->get_packages();
        $canonical_rate_ids = array();

        if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
            foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
                if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
                    $chosen_rate          = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
                    $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                }
            }
        }

        return $canonical_rate_ids;
    }

    private function get_matching_rates( $rate_ids ) {
        // First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
        return array_unique( array_merge( array_intersect( $this->enable_for_methods, $rate_ids ), array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) ) ) );
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( $order->get_total() > 0 ) {
            // Mark as processing or on-hold (payment won't be taken until delivery).
            $order->update_status( apply_filters( 'woocommerce_ppl_dobirka_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ), __( 'Payment to be made upon delivery.', 'woocommerce' ) );
        } else {
            $order->payment_complete();
        }

        // Remove cart.
        WC()->cart->empty_cart();

        // Return thankyou redirect.
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    public function thankyou_page() {
        if ( $this->instructions ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
        }
    }


    public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
        if ( $order && 'ppl_dobirka' === $order->get_payment_method() ) {
            $status = 'completed';
        }
        return $status;
    }


    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
        }
    }
}
}

add_action( 'plugins_loaded', 'init_sk_dobirka_gateway_class' );
function init_sk_dobirka_gateway_class() {
add_filter( 'woocommerce_payment_gateways', 'sk_dobirka_add_gateway_class' );
function sk_dobirka_add_gateway_class( $methods ) {
    $methods[] = 'WC_sk_dobirka_Gateway'; // your class name is here
    return $methods;
}
class WC_sk_dobirka_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->setup_properties();
        $this->init_form_fields();
        $this->init_settings();
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions' );
        $this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
        $this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
    }
    protected function setup_properties() {
        $this->id                 = 'sk_dobirka';
        $this->icon               = apply_filters( 'woocommerce_cod_icon', '' );
        $this->method_title       = __( 'Dobírka na Slovensko', 'woocommerce' );
        $this->method_description = __( 'Have your customers pay with cash (or by other means) upon delivery.', 'woocommerce' );
        $this->has_fields         = false;
    }
    public function init_form_fields() {

        $options    = array();
        $data_store = WC_Data_Store::load( 'shipping-zone' );
        $raw_zones  = $data_store->get_zones();

        foreach ( $raw_zones as $raw_zone ) {
            $zones[] = new WC_Shipping_Zone( $raw_zone );
        }

        $zones[] = new WC_Shipping_Zone( 0 );

        foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

            $options[ $method->get_method_title() ] = array();
            $options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $method->get_method_title() );
            foreach ( $zones as $zone ) {
                $shipping_method_instances = $zone->get_shipping_methods();
                foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {
                    if ( $shipping_method_instance->id !== $method->id ) {
                        continue;
                    }
                    $option_id = $shipping_method_instance->get_rate_id();
                    $option_instance_title = sprintf( __( '%1$s (#%2$s)', 'woocommerce' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );
                    $option_title = sprintf( __( '%1$s &ndash; %2$s', 'woocommerce' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'woocommerce' ), $option_instance_title );
                    $options[ $method->get_method_title() ][ $option_id ] = $option_title;
                }
            }
        }
        $this->form_fields = array(
            'enabled'            => array(
                'title'       => __( 'Enable/Disable', 'woocommerce' ),
                'label'       => __( 'Enable cash on delivery', 'woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title'              => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
                'default'     => __( 'Cash on delivery', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'description'        => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'instructions'       => array(
                'title'       => __( 'Instructions', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'enable_for_methods' => array(
                'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 400px;',
                'default'           => '',
                'description'       => __( 'If COD is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce' ),
                'options'           => $options,
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Select shipping methods', 'woocommerce' ),
                ),
            ),
            'enable_for_virtual' => array(
                'title'   => __( 'Accept for virtual orders', 'woocommerce' ),
                'label'   => __( 'Accept COD if the order is virtual', 'woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
        );
    }
    public function is_available() {
        $order          = null;
        $needs_shipping = false;
        if ( WC()->cart && WC()->cart->needs_shipping() ) {
            $needs_shipping = true;
        } elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );
            if ( 0 < count( $order->get_items() ) ) {
                foreach ( $order->get_items() as $item ) {
                    $_product = $item->get_product();
                    if ( $_product && $_product->needs_shipping() ) {
                        $needs_shipping = true;
                        break;
                    }
                }
            }
        }

        $needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );
        if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
            return false;
        }
        if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
            $order_shipping_items            = is_object( $order ) ? $order->get_shipping_methods() : false;
            $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

            if ( $order_shipping_items ) {
                $canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids( $order_shipping_items );
            } else {
                $canonical_rate_ids = $this->get_canonical_package_rate_ids( $chosen_shipping_methods_session );
            }

            if ( ! count( $this->get_matching_rates( $canonical_rate_ids ) ) ) {
                return false;
            }
        }

        return parent::is_available();
    }
    private function get_canonical_order_shipping_item_rate_ids( $order_shipping_items ) {

        $canonical_rate_ids = array();

        foreach ( $order_shipping_items as $order_shipping_item ) {
            $canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
        }
        return $canonical_rate_ids;
    }
    private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

        $shipping_packages  = WC()->shipping->get_packages();
        $canonical_rate_ids = array();

        if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
            foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
                if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
                    $chosen_rate          = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
                    $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                }
            }
        }

        return $canonical_rate_ids;
    }
    private function get_matching_rates( $rate_ids ) {
        return array_unique( array_merge( array_intersect( $this->enable_for_methods, $rate_ids ), array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) ) ) );
    }
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order->get_total() > 0 ) {
            $order->update_status( apply_filters( 'woocommerce_sk_dobirka_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ), __( 'Payment to be made upon delivery.', 'woocommerce' ) );
        } else {
            $order->payment_complete();
        }
        WC()->cart->empty_cart();
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }
    public function thankyou_page() {
        if ( $this->instructions ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
        }
    }
    public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
        if ( $order && 'sk_dobirka' === $order->get_payment_method() ) {
            $status = 'completed';
        }
        return $status;
    }
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
        }
    }
}
}

add_action( 'plugins_loaded', 'init_zdarma_dobirka_gateway_class' );
function init_zdarma_dobirka_gateway_class() {
add_filter( 'woocommerce_payment_gateways', 'zdarma_dobirka_add_gateway_class' );
function zdarma_dobirka_add_gateway_class( $methods ) {
    $methods[] = 'WC_zdarma_dobirka_Gateway';
    return $methods;
}
class WC_zdarma_dobirka_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->setup_properties();
        $this->init_form_fields();
        $this->init_settings();
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions' );
        $this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
        $this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
    }
    protected function setup_properties() {
        $this->id                 = 'zdarma_dobirka';
        $this->icon               = apply_filters( 'woocommerce_cod_icon', '' );
        $this->method_title       = __( 'Dobírka při dopravě zdarma', 'woocommerce' );
        $this->method_description = __( 'Have your customers pay with cash (or by other means) upon delivery.', 'woocommerce' );
        $this->has_fields         = false;
    }
    public function init_form_fields() {

        $options    = array();
        $data_store = WC_Data_Store::load( 'shipping-zone' );
        $raw_zones  = $data_store->get_zones();

        foreach ( $raw_zones as $raw_zone ) {
            $zones[] = new WC_Shipping_Zone( $raw_zone );
        }

        $zones[] = new WC_Shipping_Zone( 0 );

        foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

            $options[ $method->get_method_title() ] = array();
            $options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $method->get_method_title() );
            foreach ( $zones as $zone ) {
                $shipping_method_instances = $zone->get_shipping_methods();
                foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {
                    if ( $shipping_method_instance->id !== $method->id ) {
                        continue;
                    }
                    $option_id = $shipping_method_instance->get_rate_id();
                    $option_instance_title = sprintf( __( '%1$s (#%2$s)', 'woocommerce' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );
                    $option_title = sprintf( __( '%1$s &ndash; %2$s', 'woocommerce' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'woocommerce' ), $option_instance_title );
                    $options[ $method->get_method_title() ][ $option_id ] = $option_title;
                }
            }
        }
        $this->form_fields = array(
            'enabled'            => array(
                'title'       => __( 'Enable/Disable', 'woocommerce' ),
                'label'       => __( 'Enable cash on delivery', 'woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title'              => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
                'default'     => __( 'Cash on delivery', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'description'        => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'instructions'       => array(
                'title'       => __( 'Instructions', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
                'default'     => __( 'Pay with cash upon delivery.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'enable_for_methods' => array(
                'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 400px;',
                'default'           => '',
                'description'       => __( 'If COD is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce' ),
                'options'           => $options,
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Select shipping methods', 'woocommerce' ),
                ),
            ),
            'enable_for_virtual' => array(
                'title'   => __( 'Accept for virtual orders', 'woocommerce' ),
                'label'   => __( 'Accept COD if the order is virtual', 'woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
        );
    }
    public function is_available() {
        $order          = null;
        $needs_shipping = false;
        if ( WC()->cart && WC()->cart->needs_shipping() ) {
            $needs_shipping = true;
        } elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );
            if ( 0 < count( $order->get_items() ) ) {
                foreach ( $order->get_items() as $item ) {
                    $_product = $item->get_product();
                    if ( $_product && $_product->needs_shipping() ) {
                        $needs_shipping = true;
                        break;
                    }
                }
            }
        }

        $needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );
        if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
            return false;
        }
        if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
            $order_shipping_items            = is_object( $order ) ? $order->get_shipping_methods() : false;
            $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

            if ( $order_shipping_items ) {
                $canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids( $order_shipping_items );
            } else {
                $canonical_rate_ids = $this->get_canonical_package_rate_ids( $chosen_shipping_methods_session );
            }

            if ( ! count( $this->get_matching_rates( $canonical_rate_ids ) ) ) {
                return false;
            }
        }

        return parent::is_available();
    }
    private function get_canonical_order_shipping_item_rate_ids( $order_shipping_items ) {

        $canonical_rate_ids = array();

        foreach ( $order_shipping_items as $order_shipping_item ) {
            $canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
        }
        return $canonical_rate_ids;
    }
    private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

        $shipping_packages  = WC()->shipping->get_packages();
        $canonical_rate_ids = array();

        if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
            foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
                if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
                    $chosen_rate          = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
                    $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                }
            }
        }

        return $canonical_rate_ids;
    }
    private function get_matching_rates( $rate_ids ) {
        return array_unique( array_merge( array_intersect( $this->enable_for_methods, $rate_ids ), array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) ) ) );
    }
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order->get_total() > 0 ) {
            $order->update_status( apply_filters( 'woocommerce_zdarma_dobirka_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ), __( 'Payment to be made upon delivery.', 'woocommerce' ) );
        } else {
            $order->payment_complete();
        }
        WC()->cart->empty_cart();
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }
    public function thankyou_page() {
        if ( $this->instructions ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
        }
    }
    public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
        if ( $order && 'zdarma_dobirka' === $order->get_payment_method() ) {
            $status = 'completed';
        }
        return $status;
    }
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
        }
    }
}
}
