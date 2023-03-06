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

        // Allocate port for VSCode Server when using a PHP-FPM xdbg template
        public function priv_change_web_domain_backend_tpl( $data ) {
            global $hcpp;
            $user = $data[0];
            $domain = $data[1];
            $tpl = $data[2];
            if ( strpos( $tpl, 'xdbg' ) === false ) return $data;
                
            
            // 19:16:51.99 [
            //     "homestead",
            //     "test1.openmy.info",
            //     "PHP-8_2xdbg"
            // ]
            return $data;
        }
    }
    new VSCode();
}