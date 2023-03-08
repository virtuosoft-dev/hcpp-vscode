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
            $hcpp->add_action( 'render_page', [ $this, 'render_page' ] );
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
            $hcpp->allocate_port( "vscode_port", $user, "vscode-$user.$hostname" );

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

            // Start the VSCode Server instance
            if ( trim( shell_exec( "rununser -l $user -c \"cd \/opt\/vscode;pm2 pid vscode-$user.$hostname\"" ) ) === '' ) {

                // Create unique token
                $token = $hcpp->nodeapp->random_chars( 32 );
                $cmd = "echo \"$token\" > \/home\/$user\/.openvscode-server\/data\/token && ";
                $cmd .= "chown $user:$user \/home\/$user\/.openvscode-server\/data\/token && ";
                $cmd .= "chmod 600 \/home\/$user\/.openvscode-server\/data\/token && ";
                $cmd .= "runuser -l $user -c \"cd \/opt\/vscode;pm2 start vscode.config.js\"";
                shell_exec( $cmd );
            }
        }

        // Delete the NGINX configuration reference and server when the user is deleted.
        public function priv_delete_user( $args ) {
            global $hcpp;
            $user = $args[0];
            $hostname = trim( $hcpp->delLeftMost( shell_exec( 'hostname -f' ), '.' ) );
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$hostname.conf";
            if ( is_link( $link ) ) {
                unlink( $link );
            }

            // Delete the VSCode Server instance
            $cmd = "runuser -l $user -c \"cd \/opt\/vscode;pm2 delete vscode-$user.$hostname\"";
            shell_exec( $cmd );
            return $args;
        }

        // Configure VSCode for the given domain.
        public function configure( $user, $domain ) {

            // TODO: write .vscode/settings.json and .vscode/launch.json

        }

        // Add VSCode Server icon for each listed domain.
        public function render_page( $args ) {
            if ( !$args['page'] == 'list_web' ) return $args;
            $content = $args['content'];

            // TODO: inject icon into list, use the /usr/local/hestia/plugins/vscode/code-192.png or
            // <i class="fa fa-file-code-o" aria-hidden="true"></i>
            //
            // before
            // <div class="actions-panel__col actions-panel__view" key-action="href">
            //     <a href="http://test1.openmy.info/" rel="noopener" target="_blank">
            //         <i class="fas fa-external-link-square-alt status-icon lightblue status-icon dim"></i>
            //     </a>
            // </div>
            // 
            // inject
            // <div class="actions-panel__col actions-panel__code" key-action="href">
            //     <a href="http://vscode-$user.$hostname/?tkn=$token&folder=$folder" rel="noopener" target="_blank" title="Open VSCode Editor">
            //         <i class="fas fa-file-code status-icon blue status-icon dim"></i>
            //     </a>
            // </div>

            return $args;
        }
    }
    new VSCode();
}