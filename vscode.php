<?php
/**
 * Extend the HestiaCP Pluginable object with our VSCode object for
 * allocating VSCode Server instances per user account.
 * 
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/virtuosoft-dev/hcpp-vscode
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
            $hcpp->add_action( 'hcpp_new_domain_ready', [ $this, 'hcpp_new_domain_ready' ] );
            $hcpp->add_action( 'priv_delete_user', [ $this, 'priv_delete_user' ] );
            $hcpp->add_action( 'hcpp_invoke_plugin', [ $this, 'hcpp_invoke_plugin' ] );
            $hcpp->add_action( 'hcpp_render_page', [ $this, 'hcpp_render_page' ] );
        }

        // Trigger setup and configuration when domain is created.
        public function hcpp_new_domain_ready( $args ) {
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
        public function hcpp_invoke_plugin( $args ) {
            if ( $args[0] !== 'vscode_get_token' ) return $args;
            $user = $args[1];
            echo file_get_contents( "/home/$user/.openvscode-server/data/token" );
            return $args;
        }

        // Setup the VSCode Server instance for the user.
        public function setup( $user ) {
            global $hcpp;
            $hostname =  $hcpp->delLeftMost( $hcpp->getLeftMost( $_SERVER['HTTP_HOST'], ':' ), '.' );

            // Create the configuration folder
            if ( ! is_dir( "/home/$user/conf/web/vscode-$user.$hostname" ) ) {
                mkdir( "/home/$user/conf/web/vscode-$user.$hostname" );

                // Run pm2 first time
                $cmd = "runuser -s /bin/bash -l $user -c \"cd /home/$user && export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh ; pm2 status\"";
                $hcpp->log( $cmd );
                $hcpp->log( shell_exec( $cmd ) );
            }

            // Get user account first IP address.
            $ip = array_key_first(
                json_decode( shell_exec( '/usr/local/hestia/bin/v-list-user-ips ' . $user . ' json' ), true ) 
            );

            // Create the nginx.conf file.
            $conf = "/home/$user/conf/web/vscode-$user.$hostname/nginx.conf";
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
            $cmd = "runuser -s /bin/bash -l $user -c \"cd /opt/vscode && export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh ; pm2 pid vscode-$user.$hostname\"";
            if ( trim( shell_exec( $cmd ) ) === '' ) {
                $cmd = "runuser -s /bin/bash -l $user -c \"cd /opt/vscode && export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh ; pm2 start vscode.config.js\"";
                $hcpp->log( shell_exec( $cmd ) );
            // }else{
            //     $this->update_token( $user );
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
            $cmd = "runuser -s /bin/bash -l $user -c \"cd \/opt\/vscode && export NVM_DIR=/opt/nvm && source /opt/nvm/nvm.sh ;pm2 delete vscode-$user.$hostname;pm2 save --force\"";
            shell_exec( $cmd );
            return $args;
        }

        // Configure VSCode for the given domain.
        public function configure( $user, $domain ) {

            // TODO: write .vscode/settings.json and .vscode/launch.json
            // 
            // {
            //     // Use IntelliSense to learn about possible attributes.
            //     // Hover to view descriptions of existing attributes.
            //     // For more information, visit: https://go.microsoft.com/fwlink/?linkid=830387
            //     "version": "0.2.0",
            //     "configurations": [
            //         {
            //             "name": "Listen for Xdebug",
            //             "type": "php",
            //             "request": "launch",
            //             "hostname": "127.0.0.1",
            //             "port": 12003,
            //             "pathMappings": {
            //                 "/home/farmer/web/test3.openmy.info/public_html/": "${workspaceFolder}/"
            //             }
            //         }
            //     ]
            // }

            // {
            //     // Use IntelliSense to learn about possible attributes.
            //     // Hover to view descriptions of existing attributes.
            //     // For more information, visit: https://go.microsoft.com/fwlink/?linkid=830387
            //     "version": "0.2.0",
            //     "configurations": [
            //         {
            //             "type": "node",
            //             "request": "attach",
            //             "name": "Debug NodeJS",
            //             "port": 53001
            //         }
            //     ]
            // }




        }

        // Add VSCode Server icon to our web domain list and button to domain edit pages.
        public function hcpp_render_page( $args ) {
            if ( $args['page'] == 'list_web' ) {
                $args = $this->render_list_web( $args );
            }
            if ( $args['page'] == 'edit_web' ) {
                $args = $this->render_edit_web( $args );
            }
            return $args;
        }

        // Add VSCode Server button to our web domain edit page.
        public function render_edit_web( $args ) {

                global $hcpp;
                $user = trim( $args['user'], "'" );
                $hostname = trim( $hcpp->delLeftMost( shell_exec( 'hostname -f' ), '.' ) );
                $token = trim( $hcpp->run( "invoke-plugin vscode_get_token $user" ) );
                $domain = $_GET['domain'];
                $content = $args['content'];

                // Create blue code icon button to appear before Quick Installer button
                $code = '<a href="https://vscode-' . $user . '.' . $hostname . '/?tkn=' . $token . '&folder=';
                $code .= '/home/' . $user . '/web/' . $domain . '" target="_blank" class="button button-secondary ui-button cancel" ';
                $code .= 'dir="ltr"><i class="fas fa-file-code status-icon blue" style="color: #0092FF;">';
                $code .= '</i> VSCode Editor</a>';

                // Inject the button into the page's toolbar buttonstrip
                $quick = '"fas fa-magic status-icon blue'; // HestiaCP 1.6.X
                if ( strpos( $content, $quick ) === false ) {
                    $quick = '"fas fa-magic icon-blue'; // HestiaCP 1.7.X
                }
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
            $hcpp->log("vscode render_list_web");
            $user = trim( $args['user'], "'");
            $content = $args['content'];
            $hostname =  $hcpp->delLeftMost( $hcpp->getLeftMost( $_SERVER['HTTP_HOST'], ':' ), '.' );
            $token = trim( $hcpp->run( "invoke-plugin vscode_get_token $user" ) );

            // Create white envelope icon before pencil/edit icon
            $div = '<li class="units-table-row-action shortcut-enter" data-key-action="href">';
            $code = '<li class="units-table-row-action" data-key-action="href">
                        <a class="units-table-row-action-link" href="https://vscode-%user%.%hostname%/?tkn=%token%&folder=%folder%" rel="noopener" target="_blank" title="Open VSCode Editor">
                            <i class="fas fa-file-code icon-blue vscode"></i>
                            <span class="u-hide-desktop">VSCode</span>
                        </a>
                    </li>';
            $new = '';

            // Inject the envelope icon for each domain
            while( false !== strpos( $content, $div ) ) {
                $new .= $hcpp->getLeftMost( $content, $div );
                $content = $hcpp->delLeftMost( $content, $div );
                $domain = $hcpp->getLeftMost( $hcpp->delLeftMost( $content, '?domain=' ), '&' );
                $folder = "/home/$user/web/$domain";
                $new .= str_replace( 
                    ['%user%', '%hostname%', '%token%', '%folder%'],
                    [$user, $hostname, $token, $folder],
                    $code 
                );
                $new .= $div;
            }
            $new .= $content;
            $args['content'] = $new;
            return $args;
        }
    }
    new VSCode();
}
