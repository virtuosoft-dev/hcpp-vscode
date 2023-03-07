<?php
/**
 * Extend the HestiaCP Pluginable object with our VSCode object for
 * allocating VSCode Server instances per user account.
 * 
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/steveorevo/hestiacp-vscode
 * 
 */

if ( ! class_exists( 'VSCode') ) {
    class VSCode {
        /**
         * Constructor, register our actions.
         */
        public function __construct() {
            global $hcpp;
            $hcpp->vscode = $this;
            $hcpp->add_action( 'priv_change_web_domain_backend_tpl', [ $this, 'priv_change_web_domain_backend_tpl' ] );
        }

        // Trigger setup and configuration when xdgb is selected.
        public function priv_change_web_domain_backend_tpl( $data ) {
            global $hcpp;
            $user = $data[0];
            $domain = $data[1];
            $tpl = $data[2];
            if ( strpos( $tpl, 'xdbg' ) === false ) return $data;
            $this->setup( $user );           
            $this->configure( $user, $domain );
            // 19:16:51.99 [
            //     "homestead",
            //     "test1.openmy.info",
            //     "PHP-8_2xdbg"
            // ]
            return $data;
        }

        // Setup the VSCode Server instance for the user.
        public function setup( $user ) {
            global $hcpp;
            $hostname = trim( $hcpp->delLeftMost( shell_exec( 'hostname -f' ), '.' ) );
            $conf = "/home/$user/conf/web/vscode-$user.$hostname/nginx.conf";
            if ( file_exists( $conf ) ) return;

            // Create the configuration folder
            if ( ! is_dir( "/home/$user/conf/web/vscode-$user.$hostname" ) ) {
                mkdir( "/home/$user/conf/web/vscode-$user.$hostname" );
            }

            // Get user account first IP address.
            $ip = array_key_first(
                json_decode( shell_exec( '/usr/local/hestia/bin/v-list-user-ips homestead json' ), true ) 
            );

            // Create the nginx.conf file.
            $content = file_get_contents( __DIR__ . '/conf-web/nginx.conf' );
            $content = str_replace( 
                ['%ip%', '%user%', '%hostname%'],
                [$ip, $user, $hostname],
                $content
            );
            file_put_contents( $conf, $content );

            // Create the nginx.conf_nodeapp file.
            $conf = "/home/$user/conf/web/vscode-$user.$hostname/nginx.conf_nodeapp";
            $content = file_get_contents( __DIR__ . '/conf-web/nginx.conf_nodeapp' );
            file_put_contents( $conf, $content );

            // Allocate a port for the VSCode Server instance.
            $hcpp->allocate_port( "vscode", $user, "vscode-$user.$hostname" );

            // Create the nginx.forcessl.conf_ports file.
            $conf = "/home/$user/conf/web/vscode-$user.$hostname/nginx.forcessl.conf_ports";
            $content = file_get_contents( __DIR__ . '/conf-web/nginx.forcessl.conf_ports' );
            $content = str_replace( 
                ['%user%', '%hostname%'],
                [$user, $hostname],
                $content
            );
            file_put_contents( $conf, $content );

            // Create the NGINX configuration reference.
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$hostname.conf";
            if ( ! is_link( $link ) ) {
                symlink( "/home/$user/conf/web/vscode-$user.$hostname/nginx.conf", $link );
            }
        }

        // Delete the NGINX configuration reference when the user is deleted.
        public function priv_delete_user( $args ) {
            global $hcpp;
            $user = $args[0];
            $hostname = trim( $hcpp->delLeftMost( shell_exec( 'hostname -f' ), '.' ) );
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$hostname.conf";
            if ( is_link( $link ) ) {
                unlink( $link );
            }
            return $args;
        }

        // Configure VSCode for the given domain.
        public function configure( $user, $domain ) {

        }
    }
    new VSCode();
}