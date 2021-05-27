<?php

/**
 * Order section of the plugin
 *
 * @link            
 *
 * @package  Wt_Import_Export_For_Woo 
 */
if (!defined('ABSPATH')) {
    exit;
}

class Wt_Import_Export_For_Woo_Order {

    public $module_id = '';
    public static $module_id_static = '';
    public $module_base = 'order';
    public $module_name = 'Order Import Export for WooCommerce';
    public $min_base_version= '1.0.0'; /* Minimum `Import export plugin` required to run this add on plugin */

    private $importer = null;
    private $exporter = null;
    private $all_meta_keys = array();
    private $exclude_hidden_meta_columns = array();
    private $found_meta = array();
    private $found_hidden_meta = array();
    private $selected_column_names = null;

    public function __construct()
    {      
        /**
        *   Checking the minimum required version of `Import export plugin` plugin available
        */
        if(!Wt_Import_Export_For_Woo_Common_Helper::check_base_version($this->module_base, $this->module_name, $this->min_base_version))
        {
            return;
        }
        if(!function_exists('is_plugin_active'))
        {
            include_once(ABSPATH.'wp-admin/includes/plugin.php');
        }
        if(!is_plugin_active('woocommerce/woocommerce.php'))
        {
            return;
        }
       
        $this->module_id = Wt_Import_Export_For_Woo::get_module_id($this->module_base);
        self::$module_id_static = $this->module_id;
                
        add_filter('wt_iew_exporter_post_types', array($this, 'wt_iew_exporter_post_types'), 10, 1);
        add_filter('wt_iew_importer_post_types', array($this, 'wt_iew_exporter_post_types'), 10, 1);

        add_filter('wt_iew_exporter_alter_filter_fields', array($this, 'exporter_alter_filter_fields'), 10, 3);
        
        add_filter('wt_iew_exporter_alter_mapping_fields', array($this, 'exporter_alter_mapping_fields'), 10, 3);        
        add_filter('wt_iew_importer_alter_mapping_fields', array($this, 'get_importer_post_columns'), 10, 3);  
        
        add_filter('wt_iew_exporter_alter_advanced_fields', array($this, 'exporter_alter_advanced_fields'), 10, 3);
        add_filter('wt_iew_importer_alter_advanced_fields', array($this, 'importer_alter_advanced_fields'), 10, 3);
        
        add_filter('wt_iew_exporter_alter_meta_mapping_fields', array($this, 'exporter_alter_meta_mapping_fields'), 10, 3);
        add_filter('wt_iew_importer_alter_meta_mapping_fields', array($this, 'importer_alter_meta_mapping_fields'), 10, 3);

        add_filter('wt_iew_exporter_alter_mapping_enabled_fields', array($this, 'exporter_alter_mapping_enabled_fields'), 10, 3);
        add_filter('wt_iew_importer_alter_mapping_enabled_fields', array($this, 'exporter_alter_mapping_enabled_fields'), 10, 3);

        add_filter('wt_iew_exporter_do_export', array($this, 'exporter_do_export'), 10, 7);
        add_filter('wt_iew_importer_do_import', array($this, 'importer_do_import'), 10, 8);

        add_filter('wt_iew_importer_steps', array($this, 'importer_steps'), 10, 2);
    }

    /**
    *   Altering advanced step description
    */
    public function importer_steps($steps, $base)
    {
        if($this->module_base==$base)
        {
            $steps['advanced']['description']=__('Use advanced options from below to decide updates to existing orders, batch import count or schedule an import. You can also save the template file for future imports.');
        }
        return $steps;
    }
    
    public function importer_do_import($import_data, $base, $step, $form_data, $selected_template_data, $method_import, $batch_offset, $is_last_batch) {        
        if ($this->module_base != $base) {
            return $import_data;
        }             
        
        if(0 == $batch_offset){                        
            $memory = size_format(wt_let_to_num(ini_get('memory_limit')));
            $wp_memory = size_format(wt_let_to_num(WP_MEMORY_LIMIT));                      
            Wt_Import_Export_For_Woo_Logwriter::write_log($this->module_base, 'import', '---[ New import started at '.date('Y-m-d H:i:s').' ] PHP Memory: ' . $memory . ', WP Memory: ' . $wp_memory);
        }
                
        include plugin_dir_path(__FILE__) . 'import/import.php';
        $import = new Wt_Import_Export_For_Woo_Order_Import($this);
        
        $response = $import->prepare_data_to_import($import_data,$form_data,$batch_offset,$is_last_batch);
        
        if($is_last_batch){
            Wt_Import_Export_For_Woo_Logwriter::write_log($this->module_base, 'import', '---[ Import ended at '.date('Y-m-d H:i:s').']---');
        }
        
        return $response;
    }

    public function exporter_do_export($export_data, $base, $step, $form_data, $selected_template_data, $method_export, $batch_offset) {
        if ($this->module_base != $base) {
            return $export_data;
        }
        
        switch ($method_export) {
            case 'quick':
                $this->set_export_columns_for_quick_export($form_data);
                break;

            case 'template':
            case 'new':
                $this->set_selected_column_names($form_data);
                break;
            
            default:
                break;
        }

        include plugin_dir_path(__FILE__) . 'export/export.php';
        $export = new Wt_Import_Export_For_Woo_Order_Export($this);
                      
        $data_row = $export->prepare_data_to_export($form_data, $batch_offset);
        
        $header_row = $export->prepare_header(); 

        $export_data = array(
            'head_data' => $header_row,
            'body_data' => $data_row['data'],
            'total' => $data_row['total'],
        ); 

        return $export_data;
    }

    /**
     * Adding current post type to export list
     *
     */
    public function wt_iew_exporter_post_types($arr) {
        $arr['order'] = __('Order');
        return $arr;
    }

    /*
     * Setting default export columns for quick export
     */

    public function set_export_columns_for_quick_export($form_data) {

        $post_columns = self::get_order_post_columns();

        $this->selected_column_names = array_combine(array_keys($post_columns), array_keys($post_columns));

        if (isset($form_data['method_export_form_data']['mapping_enabled_fields']) && !empty($form_data['method_export_form_data']['mapping_enabled_fields'])) {
            foreach ($form_data['method_export_form_data']['mapping_enabled_fields'] as $value) {
                $additional_quick_export_fields[$value] = array('fields' => array());
            }

            $export_additional_columns = $this->exporter_alter_meta_mapping_fields($additional_quick_export_fields, $this->module_base, array());
            foreach ($export_additional_columns as $value) {
                $this->selected_column_names = array_merge($this->selected_column_names, $value['fields']);
            }
        }
    }

    public static function get_order_statuses() {
        $statuses = wc_get_order_statuses();
        return apply_filters('wt_iew_allowed_order_statuses',  $statuses);
    }

    public static function get_order_sort_columns() {
        $sort_columns = array('post_parent', 'ID', 'post_author', 'post_date', 'post_title', 'post_name', 'post_modified', 'menu_order', 'post_modified_gmt', 'rand', 'comment_count');
        return apply_filters('wt_iew_allowed_order_sort_columns', array_combine($sort_columns, $sort_columns));
    }

    public static function get_order_post_columns() {
        return include plugin_dir_path(__FILE__) . 'data/data-order-post-columns.php';
    }
    
    public function get_importer_post_columns($fields, $base, $step_page_form_data) {
        if ($base != $this->module_base) {
            return $fields;
        }
        $colunm = include plugin_dir_path(__FILE__) . 'data/data/data-wf-reserved-fields-pair.php';
//        $colunm = array_map(function($vl){ return array('title'=>$vl, 'description'=>$vl); }, $arr); 
        return $colunm;
    }

    public function exporter_alter_mapping_enabled_fields($mapping_enabled_fields, $base, $form_data_mapping_enabled_fields) {
        if ($base == $this->module_base) {
            $mapping_enabled_fields = array();
            $mapping_enabled_fields['meta'] = array(__('Custom meta'), 1);
            $mapping_enabled_fields['hidden_meta'] = array(__('Hidden meta'), 0);
        }
        return $mapping_enabled_fields;
    }

    public function exporter_alter_meta_mapping_fields($fields, $base, $step_page_form_data) {
        if ($base != $this->module_base) {
            return $fields;
        }

        foreach ($fields as $key => $value) {
            switch ($key) {
                
                case 'meta':
                    $meta_attributes = array();
                    $found_meta = $this->wt_get_found_meta();
                    foreach ($found_meta as $meta) {
                        $fields[$key]['fields']['meta:' . $meta] = 'meta:' . $meta;
                    }

                    break;               

                case 'hidden_meta':
                    $found_hidden_meta = $this->wt_get_found_hidden_meta();
                    foreach ($found_hidden_meta as $hidden_meta) {
                        $fields[$key]['fields']['meta:' . $hidden_meta] = 'meta:' . $hidden_meta;
                    }
                    break;
                default:
                    break;
            }
        }

        return $fields;
    }
    
    
    public function importer_alter_meta_mapping_fields($fields, $base, $step_page_form_data) {
        if ($base != $this->module_base) {
            return $fields;
        }
        $fields=$this->exporter_alter_meta_mapping_fields($fields, $base, $step_page_form_data);
        $out=array();
        foreach ($fields as $key => $value) 
        {
            $value['fields']=array_map(function($vl){ return array('title'=>$vl, 'description'=>$vl); }, $value['fields']);
            $out[$key]=$value;
        }
        return $out;
    }
    
    public function wt_get_found_meta() {   

        if (!empty($this->found_meta)) {
            return $this->found_meta;
        }

        // Loop products and load meta data
        $found_meta = array();
        // Some of the values may not be usable (e.g. arrays of arrays) but the worse
        // that can happen is we get an empty column.

        $all_meta_keys = $this->wt_get_all_meta_keys();

        $csv_columns = self::get_order_post_columns();

        $exclude_hidden_meta_columns = $this->wt_get_exclude_hidden_meta_columns();

        foreach ($all_meta_keys as $meta) {

            if (!$meta || (substr((string) $meta, 0, 1) == '_') || in_array($meta, $exclude_hidden_meta_columns) || in_array($meta, array_keys($csv_columns)) || in_array('meta:' . $meta, array_keys($csv_columns)))
                continue;

            $found_meta[] = $meta;
        }
        
        $found_meta = array_diff($found_meta, array_keys($csv_columns));
        $this->found_meta = $found_meta;
        return $this->found_meta;
    }

    public function wt_get_found_hidden_meta() {

        if (!empty($this->found_hidden_meta)) {
            return $this->found_hidden_meta;
        }

        // Loop products and load meta data
        $found_hidden_meta = array();
        // Some of the values may not be usable (e.g. arrays of arrays) but the worse
        // that can happen is we get an empty column.

        $all_meta_keys = $this->wt_get_all_meta_keys();
        $csv_columns = self::get_order_post_columns();
        $exclude_hidden_meta_columns = $this->wt_get_exclude_hidden_meta_columns();
        foreach ($all_meta_keys as $meta) {

            if (!$meta || (substr((string) $meta, 0, 1) != '_') || ((substr((string) $meta, 0, 1) == '_') && in_array(substr((string) $meta,1), array_keys($csv_columns)) ) || in_array($meta, $exclude_hidden_meta_columns) || in_array($meta, array_keys($csv_columns)) || in_array('meta:' . $meta, array_keys($csv_columns)))
                continue;

            $found_hidden_meta[] = $meta;
        }

        $found_hidden_meta = array_diff($found_hidden_meta, array_keys($csv_columns));

        $this->found_hidden_meta = $found_hidden_meta;
        return $this->found_hidden_meta;
    }

    public function wt_get_exclude_hidden_meta_columns() {

        if (!empty($this->exclude_hidden_meta_columns)) {
            return $this->exclude_hidden_meta_columns;
        }

        $exclude_hidden_meta_columns = include( plugin_dir_path(__FILE__) . 'data/data-wf-exclude-hidden-meta-columns.php' );

        $this->exclude_hidden_meta_columns = $exclude_hidden_meta_columns;
        return $this->exclude_hidden_meta_columns;
    }

    public function wt_get_all_meta_keys() {

        if (!empty($this->all_meta_keys)) {
            return $this->all_meta_keys;
        }

        $all_meta_keys = self::get_all_metakeys('shop_order');

        $this->all_meta_keys = $all_meta_keys;
        return $this->all_meta_keys;
    }

    /**
     * Get a list of all the meta keys for a post type. This includes all public, private,
     * used, no-longer used etc. They will be sorted once fetched.
     */
    public static function get_all_metakeys($post_type = 'shop_order') {
        global $wpdb;
        $meta = $wpdb->get_col($wpdb->prepare(
                        "SELECT DISTINCT pm.meta_key
            FROM {$wpdb->postmeta} AS pm
            LEFT JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND p.post_status IN ( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' ) ORDER BY pm.meta_key", $post_type
        ));
        //sort($meta);
        return $meta;
    }

    public function set_selected_column_names($full_form_data) {
        if (is_null($this->selected_column_names)) {
            if (isset($full_form_data['mapping_form_data']['mapping_selected_fields']) && !empty($full_form_data['mapping_form_data']['mapping_selected_fields'])) {
                $this->selected_column_names = $full_form_data['mapping_form_data']['mapping_selected_fields'];
            }
            if (isset($full_form_data['meta_step_form_data']['mapping_selected_fields']) && !empty($full_form_data['meta_step_form_data']['mapping_selected_fields'])) {
                $export_additional_columns = $full_form_data['meta_step_form_data']['mapping_selected_fields'];
                foreach ($export_additional_columns as $value) {
                    $this->selected_column_names = array_merge($this->selected_column_names, $value);
                }
            }
        }

        return $full_form_data;
    }

    public function get_selected_column_names() {

        return $this->selected_column_names;
    }

    public function exporter_alter_mapping_fields($fields, $base, $mapping_form_data) {
        if ($base == $this->module_base) {
            $fields = self::get_order_post_columns();
        }
        return $fields;
    }

    public function exporter_alter_advanced_fields($fields, $base, $advanced_form_data) {
        if ($this->module_base != $base) {
            return $fields;
        }
        
        unset($fields['export_shortcode_tohtml']);
        
        $out = array();
        $out['exclude_already_exported'] = array(
            'label' => __("Exclude already exported"),
            'type' => 'radio',
            'radio_fields' => array(
                'Yes' => __('Yes'),
                'No' => __('No')
            ),
            'value' => 'No',
            'field_name' => 'exclude_already_exported',
            'help_text' => __("Option 'Yes' excludes the previously exported orders."),
        );
        
        $out['export_to_separate_columns'] = array(
            'label' => __("Export line items into separate columns"),
            'type' => 'radio',
            'radio_fields' => array(
                'Yes' => __('Yes'),
                'No' => __('No')
            ),
            'value' => 'No',
            'field_name' => 'export_to_separate_columns',
            'help_text' => __("Option 'Yes' exports the line items within the order into separate columns in the exported file."),
        );
        foreach ($fields as $fieldk => $fieldv) {
            $out[$fieldk] = $fieldv;
        }
        return $out;
    }
    
    public function importer_alter_advanced_fields($fields, $base, $advanced_form_data) {
        if ($this->module_base != $base) {
            return $fields;
        }
        $out = array();
        
        $out['skip_new'] = array(
            'label' => __("Update Only"),
            'type' => 'radio',
            'radio_fields' => array(
                '1' => __('Yes'),
                '0' => __('No')
            ),
            'value' => '0',
            'field_name' => 'skip_new',
            'help_text_conditional'=>array(
                array(
                    'help_text'=> __('The store is updated with the data from the input file only for matching/existing records from the file. If the post ID of the order being imported exists already(for any of the other post types like coupon, product, user, pages, media etc) skip the order from being inserted into the store.'),
                    'condition'=>array(
                        array('field'=>'wt_iew_skip_new', 'value'=>1)
                    )
                ),
                array(
                    'help_text'=> __('The entire data from the input file is processed for an update or insert as the case maybe.'),
                    'condition'=>array(
                        array('field'=>'wt_iew_skip_new', 'value'=>0)
                    )
                )
            ),
            'form_toggler'=>array(
                'type'=>'parent',
                'target'=>'wt_iew_skip_new',
            )
        ); 

        $out['found_action_merge'] = array(
            'label' => __("If order exists in the store"),
            'type' => 'radio',
            'radio_fields' => array(
//                'import' => __('Import as new item'),
                'skip' => __('Skip'),
                'update' => __('Update'),                
            ),
            'value' => 'skip',
            'field_name' => 'found_action',
            'help_text' => __('Orders are matched by their order IDs.'),
            'help_text_conditional'=>array(
                array(
                    'help_text'=> __('Retains the order in the store as is and skips the matching order from the input file.'),
                    'condition'=>array(
                        array('field'=>'wt_iew_found_action', 'value'=>'skip')
                    )
                ),
                array(
                    'help_text'=> __('Update order as per data from the input file'),
                    'condition'=>array(
                        array('field'=>'wt_iew_found_action', 'value'=>'update')
                    )
                )
            ),
            'form_toggler'=>array(
                'type'=>'parent',
                'target'=>'wt_iew_found_action'
            )
        );       
        
        $out['merge_empty_cells'] = array(
            'label' => __("Update even if empty values"),
            'type' => 'radio',
            'radio_fields' => array(
                '1' => __('Yes'),
                '0' => __('No')
            ),
            'value' => '0',
            'field_name' => 'merge_empty_cells',
            'help_text' => __('Updates the order data respectively even if some of the columns in the input file contains empty value.'),
            'form_toggler'=>array(
                'type'=>'child',
                'id'=>'wt_iew_found_action',
                'val'=>'update',
            )
        );
        
        $out['conflict_with_existing_post'] = array(
            'label' => __("If conflict with an existing Post ID"),
            'type' => 'radio',
            'radio_fields' => array(                
                'skip' => __('Skip item'),
                'import' => __('Import as new item'),
            ),
            'value' => 'skip',
            'field_name' => 'id_conflict',
            'help_text' => __('All the items within woocommerce/wordpress are treated as posts and assigned a unique ID as and when they are created in the store. The post ID uniquely identifies an item irrespective of the post type be it order/product/pages/attachments/revisions etc.'),
            'help_text_conditional'=>array(
                array(
                    'help_text'=> __('If the post ID of the order being imported exists already(for any of the other post types like coupon, product, user, pages, media etc) skip the order from being inserted into the store.'),
                    'condition'=>array(
                        array('field'=>'wt_iew_id_conflict', 'value'=>'skip')
                    )
                ),
                array(
                    'help_text'=> __('Insert the order into the store with a new order ID(next available post ID) different from the value in the input file.'),
                    'condition'=>array(
                        array('field'=>'wt_iew_id_conflict', 'value'=>'import')
                    )
                )
            ),
            'form_toggler'=>array(
                'type'=>'child',
                'id'=>'wt_iew_skip_new',
                'val'=>'0',
                'depth'=>0,
            )
        );

        
//        $out['merge'] = array(
//            'label' => __("Update order if exists"),
//            'type' => 'radio',
//            'radio_fields' => array(
//                '1' => __('Yes'),
//                '0' => __('No')
//            ),
//            'value' => '0',
//            'field_name' => 'merge',
//            'help_text' => __('Existing orders are identified by their IDs.'),
//            'form_toggler'=>array(
//                'type'=>'parent',
//                'target'=>'wt_iew_merge'
//            )
//        );  
//        
//        $out['skip_new'] = array(
//            'label' => __("Skip New Order"),
//            'type' => 'radio',
//            'radio_fields' => array(
//                '1' => __('Yes'),
//                '0' => __('No')
//            ),
//            'value' => '0',
//            'field_name' => 'skip_new',
//            'help_text' => __('While updating existing order, enable this to skip order which are not already present in the store.'),
//            'form_toggler'=>array(
//                'type'=>'child',
//                'id'=>'wt_iew_merge',
//                'val'=>'1',
//            )
//        );
//        
//        $out['found_action_merge'] = array(
//            'label' => __("Skip or import if Order not found"),
//            'type' => 'radio',
//            'radio_fields' => array(
//                'import' => __('Import as new item'),
//                'skip' => __('Skip item'),
//                
//            ),
//            'value' => 'import',
//            'field_name' => 'found_action',
//            'help_text' => __('Skip or import if Order not found.'),
//            'form_toggler'=>array(
//                'type'=>'',
//                'id'=>'wt_iew_merge',
//                'val'=>'1',
//                'target'=>'wt_iew_use_same_id'
//            )
//        );
//        
//        $out['found_action_import'] = array(
//            'label' => __("Skip or import"),
//            'type' => 'radio',
//            'radio_fields' => array(
//                'import' => __('Import as new item'),
//                'skip' => __('Skip item'),
//                
//            ),
//            'value' => 'import',
//            'field_name' => 'found_action',
//            'help_text' => __('Skip or import if found a non Order post type in given ID.'),
//            'form_toggler'=>array(
//                'type'=>'',
//                'id'=>'wt_iew_merge',
//                'val'=>'0',
//                'target'=>'wt_iew_use_same_id'
//            )
//        );
//        
//        $out['use_same_id'] = array(
//            'label' => __("Use the same ID for Order on import"),
//            'type' => 'radio',
//            'radio_fields' => array(
//                '1' => __('Yes'),
//                '0' => __('No')
//            ),
//            'value' => '0',
//            'field_name' => 'use_same_id',
//            'help_text' => __('Use the same ID for Order on import.'),
//            'form_toggler'=>array(
//                'type'=>'',
//                'id'=>'wt_iew_use_same_id',
//                'val'=>'import',
//            )
//        );
//        
//        $out['merge_empty_cells'] = array(
//            'label' => __("Merge empty cells"),
//            'type' => 'radio',
//            'radio_fields' => array(
//                '1' => __('Yes'),
//                '0' => __('No')
//            ),
//            'value' => '0',
//            'field_name' => 'merge_empty_cells',
//            'help_text' => __('Check to merge the empty cells in CSV, otherwise empty cells will be ignored.'),
//            'form_toggler'=>array(
//                'type'=>'child',
//                'id'=>'wt_iew_merge',
//                'val'=>'1',
//            )
//        );
//        

        
      /* Temparay commented */
//        $out['status_mail'] = array(
//            'label' => __("Email customer on order status change"),
//            'type' => 'radio',
//            'radio_fields' => array(
//                '1' => __('Yes'),
//                '0' => __('No')
//            ),
//            'value' => '0',
//            'field_name' => 'status_mail',
//            'help_text' => __('Select ‘Yes’ if an email is to be sent to the customer of the corresponding order when the status is changed during import.'),
//            'form_toggler'=>array(
//                'type'=>'child',
//                'id'=>'wt_iew_merge',
//                'val'=>'1',
//            )
//        );
        
        $out['create_user'] = array(
            'label' => __("Create user"),
            'type' => 'radio',
            'radio_fields' => array(
                '1' => __('Yes'),
                '0' => __('No')
            ),
            'value' => '0',
            'field_name' => 'create_user',
            'help_text' => __('Select ‘Yes’ if you want the application to create the customer associated with the order being imported if the customer does not exist in the store. Only the username(extracted from the email id), email id and address( shipping address) is added to the profile.'),
            'form_toggler'=>array(
                'type'=>'parent',
                'target'=>'notify_customer',
            )
        );
        
        $out['notify_customer'] = array(
            'label' => __("Notify the customer"),
            'type' => 'radio',
            'radio_fields' => array(
                '1' => __('Yes'),
                '0' => __('No')
            ),
            'value' => '0',
            'field_name' => 'notify_customer',
            'help_text' => __('Notify the customer by email when created successfully. Customer will have to use the forgot password link to access the account.'),
            'form_toggler'=>array(
                'type'=>'child',
                'id'=>'notify_customer',
                'val'=>'1',
            )
        );
 
        $out['ord_link_using_sku'] = array(
            'label' => __("Link products using SKU instead of Product ID"),
            'type' => 'radio',
            'radio_fields' => array(
                '1' => __('Yes'),
                '0' => __('No')
            ),
            'value' => '0',
            'field_name' => 'ord_link_using_sku',
            'help_text_conditional'=>array(
                array(
                    'help_text'=> __('Link the products associated with the imported orders by their SKU.'),
                    'condition'=>array(
                        array('field'=>'wt_iew_ord_link_using_sku', 'value'=>1)
                    )
                ),
                array(
                    'help_text'=> sprintf(__('Link the products associated with the imported orders by their Product ID. In case of a conflict with %sIDs of other existing post types%s the link cannot be established.'), '<b>', '</b>'),
                    'condition'=>array(
                        array('field'=>'wt_iew_ord_link_using_sku', 'value'=>0)
                    )
                )
            ),
        );
        
        
        $out['delete_existing'] = array(
            'label' => __("Delete non-matching orders from store"),
            'type' => 'radio',
            'radio_fields' => array(
                '1' => __('Yes'),
                '0' => __('No')
            ),
            'value' => '0',
            'field_name' => 'delete_existing',
            'help_text' => __('Select ‘Yes’ if you need to remove the orders from your store which are not present in the input file. For e.g, if you have an order #123 in your store and your import file has orders #234, #345; the order #123 is deleted from the store prior to importing orders #234, #345.'),
        );
                                
        
        foreach ($fields as $fieldk => $fieldv) {
            $out[$fieldk] = $fieldv;
        }
        return $out;
    }
    
    /**
     *  Customize the items in filter export page
     */
    public function exporter_alter_filter_fields($fields, $base, $filter_form_data) {

        if ($base == $this->module_base)
        {
            /* altering help text of default fields */
            $fields['offset']['help_text']=__('Specify the number of orders that should be skipped from the beginning. e.g. An offset of 10 skips the first 10 orders.');
            $fields['limit']['help_text']=__('The actual number of orders you want to export. e.g. A limit of 500 with an offset 10 will export orders from 11th to 510th position.');


            $fields['order_status'] = array(
                'label' => __('Order Statuses'),
                'placeholder' => __('All Order'),
                'field_name' => 'order_status',
                'sele_vals' => self::get_order_statuses(),
                'help_text' => __(' Filter orders by their status type. You can specify more than one type for export.'),
                'type' => 'multi_select',
                'css_class' => 'wc-enhanced-select',
                'validation_rule' => array('type'=>'text_arr')
            );
            $fields['products'] = array(
                'label' => __('Product'),
                'placeholder' => __('Search for a product&hellip;'),
                'field_name' => 'products',
                'sele_vals' => array(),
                'help_text' => __('Export orders containing specific products. Key-in the specific product names to export orders accordingly.'),
                'type' => 'multi_select',
                'css_class' => 'wc-product-search',
                'validation_rule' => array('type'=>'text_arr')
            );
            $fields['email'] = array(
                'label' => __('Email'),
                'placeholder' => __('Search for a Customer&hellip;'),
                'field_name' => 'email',
                'sele_vals' => array(),
                'help_text' => __('Input the customer email to export orders pertaining to only these customers.'),
                'type' => 'multi_select',
                'css_class' => 'wc-customer-search',
                'validation_rule' => array('type'=>'text_arr')
            );
            $fields['coupons'] = array(
                'label' => __('Coupons'),
                'placeholder' => __('Enter coupon codes separated by ,'),
                'field_name' => 'coupons',
                'sele_vals' => '',
                'help_text' => __('Enter coupons codes separated by comma to export orders on which these coupons have been applied.'),
                'type' => 'text',
                'css_class' => '',
            );

            $fields['date_from'] = array(
                'label' => __('Date From'),
                'placeholder' => __('Date'),
                'field_name' => 'date_from',
                'sele_vals' => '',
                'help_text' => __('Date on which the order was placed. Export orders placed on and after the specified date.'),
                'type' => 'text',
                'css_class' => 'wt_iew_datepicker',
//                'type' => 'field_html',
//                'field_html' => '<input type="text" name="date_from" class="wt_iew_datepicker" placeholder="'.__('From date').'" class="input-text" />',
            );

            $fields['date_to'] = array(
                'label' => __('Date To'),
                'placeholder' => __('Date'),
                'field_name' => 'date_to',
                'sele_vals' => '',
                'help_text' => __('Date on which the order was placed. Export orders placed upto the specified date.'),
                'type' => 'text',
                'css_class' => 'wt_iew_datepicker',
//                'type' => 'field_html',
//                'field_html' => '<input type="text" name="date_to" class="wt_iew_datepicker" placeholder="'.__('To date').'" class="input-text" />',
            );

//            $fields['sort_columns'] = array(
//                'label' => __('Sort Columns'),
//                'placeholder' => __('user_login'),
//                'field_name' => 'sort_columns',
//                'sele_vals' => self::get_order_sort_columns(),
//                'help_text' => __('Sort the exported data based on the selected columns in order specified. Defaulted to ascending order.'),
//                'type' => 'multi_select',
//                'css_class' => 'wc-enhanced-select',
//                'validation_rule' => array('type'=>'text_arr')
//            );
//
//            $fields['order_by'] = array(
//                'label' => __('Sort By'),
//                'placeholder' => __('ASC'),
//                'field_name' => 'order_by',
//                'sele_vals' => array('ASC' => 'ASC', 'DESC' => 'DESC'),
//                'help_text' => __('Defaulted to Ascending. Applicable to above selected columns in the order specified.'),
//                'type' => 'select',
//                'css_class' => '',
//            );
        }
        return $fields;
    }

    public static function wt_get_product_id_by_sku($sku) {
        global $wpdb;
        $post_exists_sku = $wpdb->get_var($wpdb->prepare("
	    		SELECT $wpdb->posts.ID
	    		FROM $wpdb->posts
	    		LEFT JOIN $wpdb->postmeta ON ( $wpdb->posts.ID = $wpdb->postmeta.post_id )
	    		WHERE $wpdb->posts.post_status IN ( 'publish', 'private', 'draft', 'pending', 'future' )
	    		AND $wpdb->postmeta.meta_key = '_sku' AND $wpdb->postmeta.meta_value = '%s'
	    		", $sku));
        if ($post_exists_sku) {
            return $post_exists_sku;
        }
        return false;
    }
    
    public function get_item_by_id($id) {
        $post['edit_url']=get_edit_post_link($id);
        $post['title'] = $id;
        return $post; 
    }
}

new Wt_Import_Export_For_Woo_Order();
