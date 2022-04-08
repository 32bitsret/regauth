<?php
 
/**
 
 * @package regauth
 
 */
 
/*
 
Plugin Name: RegAuth
 
Plugin URI: https://github.com/32bitsret/regauth
 
Description: This is a quick and rough plugin to solve an immediate need for the client
 
Version: 0.1
 
Author: Retnan Daser
 
Author URI: https://github.com/32bitsret/
 
License: GPLv2 or later
 
Text Domain: alewahouse
 
*/

register_activation_hook( __FILE__, 'regauth_create_db' );

function regauth_create_db() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
    $version = get_option( 'regauth_version', '1.0' );
	
    $table_name = 'wp_regauth_codes';
	$sql1 = "CREATE TABLE $table_name (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `code` varchar(191) NOT NULL,
        `active` tinyint(1) NOT NULL DEFAULT '0',
        `created_at` timestamp NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY code (code)
    ) $charset_collate;";

    $table_name = 'wp_regauth_users';
    $sql2 = "CREATE TABLE $table_name (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `user_id` bigint(20) unsigned NOT NULL,
        `email` varchar(191) NULL,
        `username` varchar(191) NULL,
        `regauth_code_id` int unsigned NOT NULL,
        `created_at` timestamp NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY user_id (user_id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql1 );
    dbDelta( $sql2 );
}


add_action( 'register_form', 'regauth_add_registration_fields' );
 
function regauth_add_registration_fields() {
    // Get and set any values already sent
    $regauth_code = ( isset( $_POST['regauth_code'] ) ) ? $_POST['regauth_code'] : '';
    ?>
    <p>
        <label for="regauth_code"><?php _e( 'Activation Code', 'regauth_activationcode' ) ?><br />
        <input type="text" name="regauth_code" id="regauth_code" class="input" value="<?php echo esc_attr( stripslashes( $regauth_code ) ); ?>"/></label>
    </p>
    <?php
}


function regauth_check_fields( $errors, $sanitized_user_login, $user_email ) {
    global $wpdb;
    if(!isset($_POST['regauth_code'])){
        $errors->add( 'regauth_code_error', __( 'ERROR: The activation code is required', 'regauth_requiredfield' ) );
        return $errors;
    }

    $code = $wpdb->get_row("SELECT * FROM wp_regauth_codes WHERE code = '". addslashes($_POST['regauth_code'])."' LIMIT 0,1");
    if(!$code){
        $errors->add( 'regauth_code_error', __( 'ERROR: Please enter a valid activation code', 'regauth_code404' ) );
        return $errors;
    }

    if($code->active){
        $errors->add( 'regauth_code_error', __( 'ERROR:Activation code already in use by another user', 'regauth_codeinuse' ) );
        return $errors;
    }

    return $errors;
}

function regauth_registration_save( $user_id ) {
    global $wpdb;
    if ( isset( $_POST['regauth_code'] ) ){
        $userdata = get_user_by('id', $user_id );
        $code = $wpdb->get_row("SELECT * FROM wp_regauth_codes WHERE code = '". addslashes($_POST['regauth_code'])."' LIMIT 0,1");
        $dbData = array();
        $dbData['active'] = true;
        $wpdb->update('wp_regauth_codes', $dbData, array('id' => $code->id));
        $username = null;
        $email = null;
        if(isset($userdata->user_login)){
            $username = $userdata->user_login;
        }
        if(isset($userdata->user_email)){
            $email = $userdata->user_email;
        }
        try {
            $wpdb->insert('wp_regauth_users', array('email' => $email, 'username' => $username, 'regauth_code_id' => $code->id, 'user_id' => $user_id, 'created_at' => date('Y-m-d H:m:s') )); 
        } catch (Exception $e) {
            // quite
        }
    }
}



add_action( 'user_register', 'regauth_registration_save', 10, 1 );
 
add_filter( 'registration_errors', 'regauth_check_fields', 10, 3 );

add_action('um_submit_form_errors_hook_','regauth_validate_regauth_code', 999, 1);

add_filter( 'um_predefined_fields_hook', 'regauth_predefined_fields', 10, 1 );

function regauth_predefined_fields($predefined_fields){
    $predefined_fields['regauth_code'] = array(
        'title' => __('Activation Code','regauth_activationcode'),
        'metakey' => 'regauth_code',
        'type' => 'text',
        'label' => __('Activation Code','regauth_activationcode'),
        'required' => 1,
        'public' => 1,
        'editable' => 0,
        // 'validate' => 'unique_username',
        'min_chars' => 1,
        'max_chars' => 191
    );
    return $predefined_fields;
}

function regauth_validate_regauth_code( $args ) {
    global $wpdb;
    $regauth_code = null;
    $mode = $args['mode'];
    if ( $mode != 'register' ) {
        return;
    }
    $fields = unserialize( $args['custom_fields'] );
    if ( isset( $fields ) && is_array( $fields ) ) {
		foreach ( $fields as $key => $array ) {
            if($key == "regauth_code"){
                $regauth_code = trim($args[ $key ]);
                // die($regauth_code);
                break;
            }
        }
    }
    if ( $regauth_code ){

        if(!isset($regauth_code)){
            UM()->form()->add_error( 'regauth_code', __( 'ERROR: The activation code is required', 'regauth_requiredfield' ) );
            return;
        }

        $code = $wpdb->get_row("SELECT * FROM wp_regauth_codes WHERE code = '". addslashes($regauth_code)."' LIMIT 0,1");
        if(!$code){
            UM()->form()->add_error( 'regauth_code', __( 'ERROR: Please enter a valid activation code', 'regauth_code404' ) );
            return;
        }

        if($code->active){
            UM()->form()->add_error( 'regauth_code', __( 'ERROR:Activation code already in use by another user', 'regauth_codeinuse' ) );
            return;
        }
    }
}


add_action( 'um_registration_complete', 'regauth_registration_complete', 10, 2 );

function regauth_registration_complete( $user_id, $args ) {
    global $wpdb;
    $regauth_code = null;
    $mode = $args['mode'];
    if ( $mode != 'register' ) {
        return;
    }
    $fields = unserialize( $args['custom_fields'] );
    if ( isset( $fields ) && is_array( $fields ) ) {
		foreach ( $fields as $key => $array ) {
            if($key == "regauth_code"){
                $regauth_code = trim($args[ $key ]);
                break;
            }
        }
    }
    if ( isset( $regauth_code ) ){
        $userdata = get_user_by('id', $user_id );
        $code = $wpdb->get_row("SELECT * FROM wp_regauth_codes WHERE code = '". addslashes($regauth_code)."' LIMIT 0,1");
        $dbData = array();
        $dbData['active'] = true;
        $wpdb->update('wp_regauth_codes', $dbData, array('id' => $code->id));
        $username = null;
        $email = null;
        if(isset($userdata->user_login)){
            $username = $userdata->user_login;
        }
        if(isset($userdata->user_email)){
            $email = $userdata->user_email;
        }
        $wpdb->insert('wp_regauth_users', array('email' => $email, 'username' => $username, 'regauth_code_id' => $code->id, 'user_id' => $user_id, 'created_at' => date('Y-m-d H:m:s') )); 
    }
}