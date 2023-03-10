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
            $hcpp->add_action( 'priv_unsuspend_domain', [ $this, 'priv_unsuspend_domain' ] );
            $hcpp->add_action( 'priv_add_web_domain', [ $this, 'priv_add_web_domain' ] );
            $hcpp->add_action( 'priv_delete_user', [ $this, 'priv_delete_user' ] );
            $hcpp->add_action( 'invoke_plugin', [ $this, 'invoke_plugin' ] );
            $hcpp->add_action( 'render_page', [ $this, 'render_page' ] );
            $hcpp->add_action( 'vscode_client_script', [ $this, 'vscode_client_script' ] );
            if ( isset( $_GET['client'] ) && $_GET['client'] == 'vscode' ) {
                echo $hcpp->do_action( 'vscode_client_script', '' );
            }
        }

        // Trigger setup and configuration when domain is created.
        public function priv_add_web_domain( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $this->setup( $user );           
            $this->configure( $user, $domain );
            return $args;
        }

        // On domain unsuspend, re-run setup and configuration.
        public function priv_unsuspend_domain( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $args[1];
            $this->setup( $user );           
            $this->configure( $user, $domain );
            return $args;
        }

        // Return requests for the VSCode Server token for the given user.
        public function invoke_plugin( $args ) {
            if ( $args[0] !== 'vscode_get_token' ) return $args;
            $user = $args[1];
            echo file_get_contents( "/home/$user/.openvscode-server/data/token" );
            return $args;
        }

        // Setup the VSCode Server instance for the user.
        public function setup( $user ) {
            global $hcpp;
            $hcpp->log( 'vscode->setup(' . $user . ')' );
            $hostname = trim( $hcpp->delLeftMost( shell_exec( 'hostname -f' ), '.' ) );
            $conf = "/home/$user/conf/web/vscode-$user.$hostname/nginx.conf";

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

            // Create the NGINX configuration symbolic link.
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$hostname.conf";
            if ( ! is_link( $link ) ) {
                symlink( "/home/$user/conf/web/vscode-$user.$hostname/nginx.conf", $link );
            }

            // Start the VSCode Server instance
            $cmd = "pm2=$(which pm2);runuser -l $user -c \"cd /opt/vscode;\$pm2 pid vscode-$user.$hostname\"";
            $hcpp->log( $cmd );
            if ( trim( shell_exec( $cmd ) ) === '' ) {
                $cmd = "pm2=$(which pm2);runuser -l $user -c \"cd /opt/vscode;\$pm2 start vscode.config.js\"";
                $hcpp->log( $cmd );
                $hcpp->log( shell_exec( $cmd ) );
            }else{
                $this->update_token( $user );
            }
        }

        // Update the VSCode Server access token; this invokes watch's restart.
        public function update_token( $user ) {
                global $hcpp;
                $token = $hcpp->nodeapp->random_chars( 32 );
                $cmd = "echo \"$token\" > \/home\/$user\/.openvscode-server\/data\/token && ";
                $cmd .= "chown $user:$user \/home\/$user\/.openvscode-server\/data\/token && ";
                $cmd .= "chmod 600 \/home\/$user\/.openvscode-server\/data\/token";
                shell_exec( $cmd );
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

        // Add VSCode Server icon to our web domain list and domain edit pages.
        public function render_page( $args ) {
            if ( $args['page'] == 'list_web' ) {
                $args = $this->render_list_web( $args );
            }
            if ( $args['page'] == 'edit_web' ) {
                $args = $this->render_edit_web( $args );
            }
            return $args;
       }

       // Add VSCode Server icon to our web domain edit page.
       public function render_edit_web( $args ) {
            global $hcpp;
            $user = trim( $args['user'], "'" );
            $hostname = trim( $hcpp->delLeftMost( shell_exec( 'hostname -f' ), '.' ) );
            $token = trim( $hcpp->run( "invoke-plugin vscode_get_token $user" ) );
            $domain = $_GET['domain'];
            $content = $args['content'];

            // Create blue code icon button to appear before Quick Installer button
            $code = '<a href="https://vscode-' . $user . '.' . $hostname . '/?tkn=' . $token . '&folder=';
            $code .= '/home/' . $user . '/web/' . $domain . '" target="_blank" class="ui-button cancel" ';
            $code .= 'dir="ltr"><i class="fas fa-file-code status-icon blue">';
            $code .= '</i> Open VSCode Editor</a>';

            // Inject the button into the page's toolbar buttonstrip
            $quick = '"fas fa-magic status-icon blue';
            $before = $hcpp->getLeftMost( $content, $quick );
            $after = $quick . $hcpp->delLeftMost( $content, $quick );
            $after = '<a href' . $hcpp->getRightMost( $before, '<a href' ) . $after;
            $before = $hcpp->delRightMost( $before, '<a href' );
            $content = $before . $code . $after;
            $args['content'] = $content;
            return $args;
       }

       // Add VSCode Server icon to our web domain list page.
       public function render_list_web( $args ) {
            global $hcpp;
            $user = trim( $args['user'], "'");
            $hostname = trim( $hcpp->delLeftMost( shell_exec( 'hostname -f' ), '.' ) );
            $token = trim( $hcpp->run( "invoke-plugin vscode_get_token $user" ) );
            $content = $args['content'];

            // Create blue code icon before pencil/edit icon
            $div = '<div class="actions-panel__col actions-panel__edit shortcut-enter" key-action="href">';
            $code = '<div class="actions-panel__col actions-panel__code" key-action="href">
            <a href="https://vscode-' . $user . '.' . $hostname . '/?tkn=' . $token .'&folder=%folder%" rel="noopener" target="_blank" title="Open VSCode Editor">
                <i class="fas fa-file-code status-icon blue status-icon dim"></i>
            </a></div>&nbsp;';
            $new = '';

            // Inject the code icon for each domain
            while( false !== strpos( $content, $div ) ) {
                $new .= $hcpp->getLeftMost( $content, $div );
                $domain = $hcpp->getRightMost( $new, 'sort-name="' );
                $domain = $hcpp->getLeftMost( $domain, '"' );
                $folder = "/home/$user/web/$domain";
                $content = $hcpp->delLeftMost( $content, $div );
                $new .= str_replace( '%folder%', $folder, $code ) . $div . $hcpp->getLeftMost( $content, '</div>' ) . "</div>";
                $content = $hcpp->delLeftMost( $content, '</div>' );
            }
            $new .= $content;
            $args['content'] = $new;
            return $args;
       }

       // Inject our custom VSCode client script
       public function vscode_client_script( $content ) {
            return "alert('Hello from vscode_client_script!');";
       }
    }
    new VSCode();
}