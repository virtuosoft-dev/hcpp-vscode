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

 if ( ! class_exists( 'VSCode' ) ) {
    class VSCode {

        /**
         * Constructor, listen for add, update, or remove users.
         */
        public function __construct() {
            global $hcpp;
            $hcpp->webdav = $this;
            $hcpp->add_action( 'cg_pws_generate_website_cert', [ $this, 'cg_pws_generate_website_cert' ] );
            $hcpp->add_action( 'post_change_user_shell', [ $this, 'post_change_user_shell' ] );
            $hcpp->add_action( 'hcpp_invoke_plugin', [ $this, 'hcpp_invoke_plugin' ] );
            $hcpp->add_action( 'post_delete_user', [ $this, 'post_delete_user' ] );
            $hcpp->add_action( 'priv_delete_user', [ $this, 'priv_delete_user' ] );
            $hcpp->add_action( 'post_add_user', [ $this, 'post_add_user' ] );
            $hcpp->add_action( 'hcpp_rebooted', [ $this, 'hcpp_rebooted' ] );
            $hcpp->add_action( 'hcpp_plugin_disabled', [ $this, 'hcpp_plugin_disabled' ] );
            $hcpp->add_action( 'hcpp_plugin_enabled', [ $this, 'hcpp_plugin_enabled' ] );
            $hcpp->add_action( 'hcpp_render_body', [ $this, 'hcpp_render_body' ] );
        }

        // Stop services on plugin disabled.
        public function hcpp_plugin_disabled() {

            // Gather list of all users
            $cmd = "/usr/local/hestia/bin/v-list-users json";
            $result = shell_exec( $cmd );
            try {
                $result = json_decode( $result, true, 512, JSON_THROW_ON_ERROR );
            } catch (Exception $e) {
                var_dump( $e );
                return;
            }
            
            // Remove VSCode for each valid user
            foreach( $result as $key=> $value ) {
                if ( $key === 'admin') continue;
                if ( $value['SHELL'] !== 'bash' ) continue;
                unlink( "/home/$key/.openvscode-server/data/token" );
            }
            $this->stop();
        }

        // Start services on plugin enabled.
        public function hcpp_plugin_enabled() {
            $this->start();
        }

        // Intercept the certificate generation and copy over ssl certs for the vscode domain.
        public function cg_pws_generate_website_cert( $cmd ) {
            if ( strpos( $cmd, '/vscode-' ) !== false && strpos( $cmd, '/cg_pws_ssl && ') !== false ) {
                
                // Omit the v-delete-web-domain-ssl, v-add-web-domain-ssl, and v-add-web-domain-ssl-force cmds.
                global $hcpp;
                $path = $hcpp->delLeftMost( $cmd, '/usr/local/hestia/bin/v-add-web-domain-ssl' );
                $path = '/home' . $hcpp->delLeftMost( $path, '/home' );
                $path = $hcpp->delRightMost( $path, '/cg_pws_ssl &&' );
                $cmd = $hcpp->delRightMost( $cmd, '/usr/local/hestia/bin/v-delete-web-domain-ssl ' );
                $cmd .= " mkdir -p $path/ssl ; cp -r $path/cg_pws_ssl/* $path/ssl ";
                $cmd = $hcpp->do_action( 'vscode_generate_website_cert', $cmd );
            }
            return $cmd;
        }

        // Setup VSCode for all users on reboot.
        public function hcpp_rebooted() {
            $this->start();
        }

        // Respond to invoke-plugin vscode_restart and vscode_get_token requests.
        public function hcpp_invoke_plugin( $args ) {
            if ( $args[0] === 'vscode_restart' ) {
                $this->restart();
            }
            if ( $args[0] === 'vscode_get_token' ) {
                $user = $args[1];
                echo file_get_contents( "/home/$user/.openvscode-server/data/token" );
            }
            return $args;
        }

        // Get the base domain; cache it for future use.
        public function get_base_domain() {
            global $hcpp;

            // Get the domain.
            if ( ! property_exists( $hcpp, 'domain' ) ) {
                $hcpp->domain = trim( shell_exec( 'hostname -d' ) );
            }
            return $hcpp->domain;
        }

        // Restart VSCode services when user added.
        public function post_add_user( $args ) {
            global $hcpp;
            $hcpp->log( $hcpp->run( 'invoke-plugin vscode_restart' ) );
            return $args;
        }

        // Restart VSCode services when shell changes.
        public function post_change_user_shell( $args ) {
            global $hcpp;
            $hcpp->log( $hcpp->run( 'invoke-plugin vscode_restart' ) );
            return $args;
        }

        // Restart VSCode services.
        public function restart() {
            $this->stop();
            $this->start();
        }

        // Start all VSCode services.
        public function start() {
            
            // Gather list of all users
            $cmd = "/usr/local/hestia/bin/v-list-users json";
            $result = shell_exec( $cmd );
            try {
                $result = json_decode( $result, true, 512, JSON_THROW_ON_ERROR );
            } catch (Exception $e) {
                var_dump( $e );
                return;
            }
            
            // Setup VSCode for each valid user
            foreach( $result as $key=> $value ) {
                if ( $key === 'admin') continue;
                if ( $value['SHELL'] !== 'bash' ) continue;
                $this->setup( $key );
            }

            // Reload nginx
            global $hcpp;
            $cmd = '(service nginx restart) > /dev/null 2>&1 &';
            $cmd = $hcpp->do_action( 'vscode_nginx_restart', $cmd );
            shell_exec( $cmd );
        }

        // Stop all VSCode services.
        public function stop() {
            
           // Find all node vscode processes
           $cmd = 'ps ax | grep "/opt/vscode/node /opt/vscode/out/server-main.js" | grep -v grep';
           exec($cmd, $processes);

           // Loop through each process and extract the process ID (PID)
           foreach ($processes as $process) {
               $pid = preg_replace('/^\s*(\d+).*$/', '$1', $process);

               // Kill the process
               $kill = "kill $pid";
               exec($kill, $output, $returnValue);

               global $hcpp;
               $hcpp->log( "Killed node vscode process $pid" );
           }

            // Remove service link and reload nginx
            global $hcpp;
            $cmd = '(rm -f /etc/nginx/conf.d/domains/vscode-* ; service nginx restart) > /dev/null 2>&1 &';
            $cmd = $hcpp->do_action( 'vscode_nginx_restart', $cmd );
            shell_exec( $cmd );
        }

        // Setup VSCode for user.
        public function setup( $user ) {
            global $hcpp;
            $hcpp->log( "Setting up VSCode for $user" );
            $domain = $this->get_base_domain();

            // Get user account first IP address.
            $ip = array_key_first(
                json_decode( shell_exec( '/usr/local/hestia/bin/v-list-user-ips ' . $user . ' json' ), true ) 
            );

            // Get a port for the VSCode service.
            $port = $hcpp->allocate_port( 'vscode', $user );

            // Create the configuration folder.
            if ( ! is_dir( "/home/$user/conf/web/vscode-$user.$domain" ) ) {
                mkdir( "/home/$user/conf/web/vscode-$user.$domain" );
            }

            // Create the password file.
            $pw_hash = trim( shell_exec( "grep '^$user:' /etc/shadow" ) );
            file_put_contents( "/home/$user/conf/web/vscode-$user.$domain/.htpasswd", $pw_hash );

            // Create the nginx.conf file.
            $conf = "/home/$user/conf/web/vscode-$user.$domain/nginx.conf";
            $content = file_get_contents( __DIR__ . '/conf-web/nginx.conf' );
            $content = str_replace( 
                ['%ip%', '%user%', '%domain%', '%port%'],
                [$ip, $user, $domain, $port],
                $content
            );

            // Uncomment basic auth for non-Personal Web Server edition.
            if ( !property_exists( $hcpp, 'cg_pws' ) ) {
                $content = str_replace( "#auth_basic", "auth_basic", $content );
            }
            file_put_contents( $conf, $content );

            // Create the nginx.ssl.conf file.
            $ssl_conf = "/home/$user/conf/web/vscode-$user.$domain/nginx.ssl.conf";
            $content = file_get_contents( __DIR__ . '/conf-web/nginx.ssl.conf' );
            $content = str_replace( 
                ['%ip%', '%user%', '%domain%', '%port%'],
                [$ip, $user, $domain, $port],
                $content
            );

            // Uncomment basic auth on SSL for non-Personal Web Server edition.
            if ( !property_exists( $hcpp, 'cg_pws' ) ) {
                $content = str_replace( "#auth_basic", "auth_basic", $content );
            }
            file_put_contents( $ssl_conf, $content );

            // Generate website cert if it doesn't exist for Personal Web Server edition.
            if ( property_exists( $hcpp, 'cg_pws' ) ) {

                // Always regenerate the cert to ensure it's up to date.
                $hcpp->cg_pws->generate_website_cert( $user, ["vscode-$user.$domain"] );
            }else{

                // Force SSL on non-Personal Web Server edition.
                $force_ssl_conf = "/home/$user/conf/web/vscode-$user.$domain/nginx.forcessl.conf";
                $content = "return 301 https://\$host\$request_uri;";
                file_put_contents( $force_ssl_conf, $content );

                // TODO: support for LE
            }

            // Create the nginx.conf configuration symbolic links.
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$domain.conf";
            if ( ! is_link( $link ) ) {
                symlink( $conf, $link );
            }

            // Create the nginx.ssl.conf configuration symbolic links.
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$domain.ssl.conf";
            if ( ! is_link( $link ) ) {
                symlink( $ssl_conf, $link );
            }

            // Create VSCode token if it doesn't already exist.
            $this->update_token( $user );

            // Start the VSCode service manually (outside of PM2).
            $cmd = 'runuser -l ' . $user . ' -c "';
            $cmd .= "(/opt/vscode/node /opt/vscode/out/server-main.js --port $port) > /dev/null 2>&1 &";
            $cmd .= '"';
            $cmd = $hcpp->do_action( 'vscode_nodejs_cmd', $cmd );
            shell_exec( $cmd );
        }

        // Delete the NGINX configuration reference and server when the user is deleted.
        public function priv_delete_user( $args ) {
            global $hcpp;
            $user = $args[0];
            $domain = $this->get_base_domain();
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$domain.conf";
            if ( is_link( $link ) ) {
                unlink( $link );
            }
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$domain.ssl.conf";
            if ( is_link( $link ) ) {
                unlink( $link );
            }

            // Delete user port
            $hcpp->delete_port( 'vscode', $user );
            return $args;
        }

        // Restart the VSCode service when a user is deleted.
        public function post_delete_user( $args ) {
            global $hcpp;
            $hcpp->log( $hcpp->run( 'invoke-plugin vscode_restart' ) );
            return $args;
        }

        // Update the VSCode Server access token
        public function update_token( $user ) {
            global $hcpp;
            $token = $hcpp->nodeapp->random_chars( 32 );
            $cmd = "echo \"$token\" > \/home\/$user\/.openvscode-server\/data\/token && ";
            $cmd .= "chown $user:$user \/home\/$user\/.openvscode-server\/data\/token && ";
            $cmd .= "chmod 600 \/home\/$user\/.openvscode-server\/data\/token";
            $cmd = $hcpp->do_action( 'vscode_update_token', $cmd );
            $hcpp->log( shell_exec( $cmd ) );
        }

        // Add VSCode Server icon to our web domain list and button to domain edit pages.
        public function hcpp_render_body( $args ) {
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
