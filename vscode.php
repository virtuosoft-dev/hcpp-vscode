<?php
/**
 * Extend the HestiaCP Pluginable object with our VSCode object for
 * allocating VSCode Server instances per user account.
 * 
 * @license GPL-3.0
 * @link https://github.com/virtuosoft-dev/hcpp-vscode
 * 
 */

if ( ! class_exists( 'VSCode' ) ) {
    class VSCode {

        /**
         * Build the Let's Encrypt SSL certificate for the user.
         * @param string $user The user account to generate the certificate for
         * @return void
         */
        public function build_le_cert( $user ) {
            global $hcpp;
            $domain = $this->get_base_domain();

            // Check if the LE certificate already exists.
            $cert_file = "/home/$user/conf/web/vscode-$user.$domain/ssl/vscode-$user.$domain.pem";
            if ( file_exists( $cert_file ) ) {
                    // Get the file modification time
                    $file_mod_time = filemtime($cert_file);
                    // Get the current time
                    $current_time = time();
                    // Calculate the age of the file in days
                    $file_age_days = ($current_time - $file_mod_time) / (60 * 60 * 24);

                    // Check if the file is less than 90 days old
                    if ($file_age_days < 90) {
                        return; // The certificate is still valid, no need to renew
                    }
                    unlink( $cert_file );
            }
            $ip = array_key_first( $hcpp->run( "list-user-ips $user json" ) );

            // Get the admin user email address
            $email = trim( $hcpp->run( 'list-user admin json' )['admin']['CONTACT'] );

            // Swap out nginx.conf and nginx.ssl.conf files to use the LE webroot
            $ssl_conf = "/home/$user/conf/web/vscode-$user.$domain/nginx.ssl.conf";
            $conf = "/home/$user/conf/web/vscode-$user.$domain/nginx.conf";
            $ssl_sav = "/home/$user/conf/web/vscode-$user.$domain/nginx.ssl.sav";
            $sav = "/home/$user/conf/web/vscode-$user.$domain/nginx.sav";
            rename( $ssl_conf, $ssl_sav );
            rename( $conf, $sav );

            // Turn off force SSL
            $force_ssl_conf = "/home/$user/conf/web/vscode-$user.$domain/nginx.forcessl.conf";
            $force_ssl_sav = "/home/$user/conf/web/vscode-$user.$domain/nginx.forcessl.sav";
            rename( $force_ssl_conf, $force_ssl_sav );

            // Create empty nginx.ssl.conf file
            touch( $ssl_conf );
            
            // Create nginx.conf that serves up le-webroot folder
            @mkdir( "/home/$user/conf/web/vscode-$user.$domain/le-webroot", 0755, true );
            @mkdir( "/home/$user/conf/web/vscode-$user.$domain/ssl", 0755, true );
            $content = 'server {
                listen      %ip%:80;
                server_name vscode-%user%.%domain% ;
                location / {
                    root /home/%user%/conf/web/vscode-%user%.%domain%/le-webroot;
                    index index.html index.htm;
                }
            }';
            file_put_contents( $conf, str_replace( 
                ['%ip%', '%user%', '%domain%'],
                [$ip, $user, $domain],
                $content
            ) );

            // Restart nginx to serve le-webroot folder
            $cmd = '/usr/sbin/service nginx restart 2>&1';
            $hcpp->log( 'Restart nginx to serve le-webroot: ' . shell_exec($cmd) );
            
            // Use certbot to generate the LE certificate
            $cmd = "certbot certonly --webroot -w /home/$user/conf/web/vscode-$user.$domain/le-webroot -d vscode-$user.$domain --email $email --agree-tos --non-interactive";
            $cmd = $hcpp->do_action( 'vscode_build_le_cert', $cmd );
            exec( $cmd, $output, $return_var );
            if ( $return_var !== 0 ) {
                $hcpp->log("Failed to generate LE certificate: " . implode("\n", $output));
            } else {

                // Link to the LE certificate and key
                $hcpp->log("Successfully generated LE certificate: " . implode("\n", $output));
                $cert_file = "/etc/letsencrypt/live/vscode-$user.$domain/fullchain.pem";
                $key_file = "/etc/letsencrypt/live/vscode-$user.$domain/privkey.pem";
                $cert_link = "/home/$user/conf/web/vscode-$user.$domain/ssl/vscode-$user.$domain.pem";
                $key_link = "/home/$user/conf/web/vscode-$user.$domain/ssl/vscode-$user.$domain.key";
                @symlink( $cert_file, $cert_link );
                @symlink( $key_file, $key_link );
            }

            // Restore the original nginx.conf and nginx.ssl.conf and force_ssl files
            if ( file_exists( $ssl_conf ) ) unlink( $ssl_conf );
            rename( $ssl_sav, $ssl_conf );
            if ( file_exists( $conf ) ) unlink( $conf );
            rename( $sav, $conf );
            if ( file_exists( $force_ssl_conf ) ) unlink( $force_ssl_conf );
            rename( $force_ssl_sav, $force_ssl_conf );

            // Restart nginx to serve vscode- folder
            $cmd = '/usr/sbin/service nginx restart 2>&1';
            $hcpp->log( 'Restart nginx to serve -vscode: ' . shell_exec($cmd) );
        }

        /**
         * Constructor, listen for add, update, or remove users.
         */
        public function __construct() {
            global $hcpp;
            $hcpp->vscode = $this;
            $hcpp->add_action( 'dev_pw_generate_website_cert', [ $this, 'dev_pw_generate_website_cert' ] );
            $hcpp->add_action( 'hcpp_plugin_disabled', [ $this, 'hcpp_plugin_disabled' ] );
            $hcpp->add_action( 'priv_log_user_logout', [ $this, 'priv_log_user_logout' ] );
            //$hcpp->add_action( 'priv_log_user_login', [ $this, 'priv_log_user_login' ] );
            $hcpp->add_action( 'priv_update_sys_rrd', [ $this, 'priv_update_sys_rrd' ] ); // Every 5 minutes
            $hcpp->add_action( 'hcpp_invoke_plugin', [ $this, 'hcpp_invoke_plugin' ] );
            $hcpp->add_action( 'hcpp_render_body', [ $this, 'hcpp_render_body' ] );
        }

        /**
         * Intercept the certificate generation and copy over ssl certs for the vscode domain.
         * @param string $cmd The command to generate the website certificate
         * @return string The modified command
         */
        public function dev_pw_generate_website_cert( $cmd ) {
            if ( strpos( $cmd, '/vscode-' ) !== false && strpos( $cmd, '/dev_pw_ssl && ') !== false ) {
                
                // Omit the v-delete-web-domain-ssl, v-add-web-domain-ssl, and v-add-web-domain-ssl-force cmds.
                global $hcpp;
                $path = $hcpp->delLeftMost( $cmd, '/usr/local/hestia/bin/v-add-web-domain-ssl' );
                $path = '/home' . $hcpp->delLeftMost( $path, '/home' );
                $path = $hcpp->delRightMost( $path, '/dev_pw_ssl &&' );
                $cmd = $hcpp->delRightMost( $cmd, '/usr/local/hestia/bin/v-delete-web-domain-ssl ' );
                $cmd .= " mkdir -p $path/ssl ; cp -r $path/dev_pw_ssl/* $path/ssl ";
                $cmd = $hcpp->do_action( 'vscode_generate_website_cert', $cmd );
            }
            return $cmd;
        }

        /**
         * Invoke a plugin action.
         * @param array $args The arguments passed to the invoke-plugin hook
         * @return array The modified arguments
         */
        public function hcpp_invoke_plugin( $args ) {
            $action = $args[0];
            if ( $action === 'vscode_get_token' ) {
                $user = $args[1];
                echo file_get_contents( "/home/$user/.openvscode-server/data/token" );
            }
            if ( $action === 'vscode_startup' ) {
                $user = $args[1];
                $this->startup( $user );
            }
            return $args;
        }
                
        /**
         * Stop all VSCode servers for all users on plugin disabled.
         */
        public function hcpp_plugin_disabled( $plugin ) {
            global $hcpp;
            if ( $plugin !== 'vscode' ) return $plugin;

            // Stop VSCode for each valid user
            $users = $this->get_bash_users();
            foreach( $users as $user ) {
                $this->stop( $user );
            }

            // Remove service link and reload nginx
            $cmd = '(rm -f /etc/nginx/conf.d/domains/vscode-* ; sleep 3 ; service nginx restart) > /dev/null 2>&1 &';
            $cmd = $hcpp->do_action( 'vscode_nginx_restart', $cmd );
            shell_exec( $cmd );
            return $plugin;
        }

        /**
         * Shutdown the VSCode server for the given user on logout.
         */
        function priv_log_user_logout( $args ) {
            $user = $args[0];
            $this->stop( $user );
            return $args;
        }

        /**
         * Start the VSCode server for the given logged in user.
         */
        // function priv_log_user_login( $args ) {
        //     global $hcpp;
        //     $user = $args[0];
        //     $state = $args[2];
        //     if ( $state !== 'success' ) {
        //         $hcpp->log( "VSCode: Failed login for $user" );
        //         return $args;
        //     }

        //     // Check if user has bash shell access
        //     $shell = $hcpp->run( "list-user $user json" )[$user]['SHELL'];
        //     if ( $shell !== 'bash' ) {
        //         $hcpp->log( "VSCode: $user does not have bash shell access" );
        //         return $args;
        //     }

        //     // Startup VSCode for the user
        //     $this->startup( $user );
        //     return $args;
        // }

        /**
         * Shutdown the VSCode server for inactive users. Runs every 5 minutes.
         */
        function priv_update_sys_rrd( $args ) {
            global $hcpp;
            $users = $this->get_bash_users();
            foreach( $users as $user ) {
                if ( file_exists( "/home/$user/.openvscode-server/data/token" ) ) {
                    $file = $this->get_most_recently_modified_file( "/home/$user/.openvscode-server/data" );
                    if ( $file && $file['age_in_minutes'] > 15 ) {
                        $hcpp->log( "Stopping VSCode for $user due to inactivity" );
                        $this->stop( $user );
                    }
                }
            }
            return $args;
        }

        /**
         * Get the base domain; cache it for future use.
         * @return string The base domain
         */ 
        public function get_base_domain() {
            global $hcpp;

            // Get the domain.
            if ( ! property_exists( $hcpp, 'domain' ) ) {
                $hcpp->domain = trim( shell_exec( 'hostname -d' ) );
            }
            return $hcpp->domain;
        }

        /** 
         * Get list of all bash shell users except the admin user.
         * @return array The list of bash shell users
         */
        public function get_bash_users() {
            global $hcpp;

            // Gather list of all users
            $cmd = "/usr/local/hestia/bin/v-list-users json";
            $result = shell_exec( $cmd );
            try {
                $result = json_decode( $result, true, 512, JSON_THROW_ON_ERROR );
            } catch (Exception $e) {
                $hcpp->log( "vscode->get_bash_users failed decoding JSON" );
                $hcpp->log( $e );
            }

            // Stop VSCode for each valid user
            $users = [];
            foreach( $result as $key=> $value ) {
                if ( $key === 'admin') continue;
                if ( $value['SHELL'] !== 'bash' ) continue;
                $users[] = $key;
            }
            return $users;
        }

        /**
         * Get the most recently modified file and its age in minutes
         * within the given base path, limited to specific file extensions.
         *
         * @param string $base_path The base path to search for files
         * @return array|null An array containing the file path and its age in minutes, or null if no files are found
         */
        public function get_most_recently_modified_file( $base_path ) {
            if ( !is_dir( $base_path ) ) {
                return null;
            }

            $most_recent_file = null;
            $most_recent_time = 0;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base_path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $file_mtime = $file->getMTime();
                    if ($file_mtime > $most_recent_time) {
                        $most_recent_time = $file_mtime;
                        $most_recent_file = $file->getPathname();
                    }
                }
            }

            if ($most_recent_file) {
                $current_time = time();
                $age_in_minutes = ($current_time - $most_recent_time) / 60;
                return [
                    'file' => $most_recent_file,
                    'age_in_minutes' => round($age_in_minutes)
                ];
            }

            return null;
        }

        /**
         * Start VSCode for the user.
         * @param string $user The user account to start VSCode for
         * @return void
         */
        public function startup( $user ) {
            global $hcpp;

            // Check for existing instance of VSCode's "server-main.js" for the user.
            $cmd = "ps axo user:20,pid,args | grep \"/opt/vscode/node /opt/vscode/out/server-main.js\" | grep $user | grep -v grep | awk '{print $2}'";
            $pid = trim( shell_exec( $cmd ) );
            
            // Start the vscode server for the given user if not already running.
            if ( $pid ) {
                $hcpp->log( "VSCode server $pid, already running for $user" );
                touch( "/home/$user/.openvscode-server/data/token" ); // Keep idle server alive
                return;
            }
            $hcpp->log( "Setting up VSCode for $user" );
            $domain = $this->get_base_domain();

            // Get user account first IP address.
            $ip = array_key_first( $hcpp->run( "list-user-ips $user json" ) );

            // Get a port for the VSCode service.
            $port = $hcpp->allocate_port( 'vscode', $user );

            // Create the configuration folder.
            if ( ! is_dir( "/home/$user/conf/web/vscode-$user.$domain" ) ) {
                mkdir( "/home/$user/conf/web/vscode-$user.$domain" );
            }

            // Create the nginx.conf file.
            $conf = "/home/$user/conf/web/vscode-$user.$domain/nginx.conf";
            $content = file_get_contents( __DIR__ . '/conf-web/nginx.conf' );
            $content = str_replace( 
                ['%ip%', '%user%', '%domain%', '%port%'],
                [$ip, $user, $domain, $port],
                $content
            );
            file_put_contents( $conf, $content );

            // Create the nginx.ssl.conf file.
            $ssl_conf = "/home/$user/conf/web/vscode-$user.$domain/nginx.ssl.conf";
            $content = file_get_contents( __DIR__ . '/conf-web/nginx.ssl.conf' );
            $content = str_replace( 
                ['%ip%', '%user%', '%domain%', '%port%'],
                [$ip, $user, $domain, $port],
                $content
            );
            file_put_contents( $ssl_conf, $content );

            // Generate website cert if it doesn't exist for Devstia Personal Web edition.
            if ( property_exists( $hcpp, 'dev_pw' ) ) {

                // Always regenerate the cert to ensure it's up to date.
                $hcpp->dev_pw->generate_website_cert( $user, ["vscode-$user.$domain"] );
            }else{

                // Force SSL on non-Devstia Personal Web edition.
                $force_ssl_conf = "/home/$user/conf/web/vscode-$user.$domain/nginx.forcessl.conf";
                $content = "return 301 https://\$host\$request_uri;";
                file_put_contents( $force_ssl_conf, $content );

                // Support LE SSL certs for non-Devstia Personal Web edition.
                $this->build_le_cert( $user );
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

            // Reload nginx
            $cmd = '(service nginx restart) > /dev/null 2>&1 &';
            $cmd = $hcpp->do_action( 'vscode_nginx_restart', $cmd );
            shell_exec( $cmd );
        }

        /**
         * Stop the VSCode server for the given user.
         * @param string $user The user account to stop VSCode for
         * @return void
         */
        public function stop( $user ) {
            global $hcpp;

            // Kill all instances of VSCode's /opt/vscode/node interpreter for the user (maybe multiple, orphans)
            do {
                $cmd = "ps axo user:20,pid,args | grep \"/opt/vscode/node\" | grep $user | grep -v grep | awk '{print $2}'";
                $pid = trim( shell_exec( $cmd ) );
                if ( $pid ) {
                    shell_exec( "kill $pid" );
                    $hcpp->log( "Killed node vscode process $pid" );
                }
            } while ( $pid );
                                    
            // Clean up the nginx configuration files.
            $domain = $this->get_base_domain();
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$domain.conf";
            if ( is_link( $link ) ) {
                unlink( $link );
            }
            $link = "/etc/nginx/conf.d/domains/vscode-$user.$domain.ssl.conf";
            if ( is_link( $link ) ) {
                unlink( $link );
            }

            // Remove the token
            if ( file_exists( "/home/$user/.openvscode-server/data/token" ) ) {
                shell_exec( "rm -f /home/$user/.openvscode-server/data/token" );
            }
        }

        /**
         * Update the VSCode Server access token
         * @param string $user The user account to update the token for
         * @return void
         */ 
        public function update_token( $user ) {
            global $hcpp;
            $token = $hcpp->nodeapp->random_chars( 32 );
            $cmd = "echo \"$token\" > \/home\/$user\/.openvscode-server\/data\/token && ";
            $cmd .= "chown $user:$user \/home\/$user\/.openvscode-server\/data\/token && ";
            $cmd .= "chmod 600 \/home\/$user\/.openvscode-server\/data\/token";
            $cmd = $hcpp->do_action( 'vscode_update_token', $cmd );
            $hcpp->log( shell_exec( $cmd ) );
        }

        /**
         * Add VSCode Server icon to our web domain list and button to domain edit pages.
         * @param array $args The arguments passed to the render_body hook
         * @return array The modified arguments
         */
        public function hcpp_render_body( $args ) {
            global $hcpp;

            // Only for bash shell user
            $user = trim( $args['user'], "'" );
            $shell = $hcpp->run( "list-user $user json" )[$user]['SHELL'];
            if ( $shell !== 'bash' ) return $args;
            if ( $args['page'] == 'list_web' ) {

                // Only start up VSCode for the user if not already running
                // when they view the web domain list page.
                if ( !isset( $_GET['quickstart'] ) ) {
                    $hcpp->run( "invoke-plugin vscode_startup $user" );
                }
                $args = $this->render_list_web( $args );
            }
            if ( $args['page'] == 'edit_web' ) {
                $args = $this->render_edit_web( $args );
            }
            return $args;
        }

        /**
         * Add VSCode Server button to our web domain edit page.
         * @param array $args The arguments passed to the render_body hook
         * @return array The modified arguments
         */
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

        /**
         * Add VSCode Server icon to our web domain list page.
         * @param array $args The arguments passed to the render_body hook
         * @return array The modified arguments
         */
        public function render_list_web( $args ) {
            global $hcpp;
            $hcpp->log("vscode render_list_web");
            $user = trim( $args['user'], "'");
            $content = $args['content'];
            $hostname =  $hcpp->delLeftMost( $hcpp->getLeftMost( $_SERVER['HTTP_HOST'], ':' ), '.' );
            $token = trim( $hcpp->run( "invoke-plugin vscode_get_token $user" ) );

            // Create blue script icon before pencil/edit icon
            $div = '<li class="units-table-row-action shortcut-enter" data-key-action="href">';
            $code = '<li class="units-table-row-action" data-key-action="href">
                        <a class="units-table-row-action-link" href="https://vscode-%user%.%hostname%/?tkn=%token%&folder=%folder%" rel="noopener" target="_blank" title="Open VSCode Editor">
                            <i class="fas fa-file-code icon-blue vscode"></i>
                            <span class="u-hide-desktop">VSCode</span>
                        </a>
                    </li>';
            $new = '';

            // Inject the script icon for each domain
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
