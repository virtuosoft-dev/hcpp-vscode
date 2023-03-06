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
         * Constructor,...
         */
        public function __construct() {
            global $hcpp;
            $hcpp->vscode = $this;
        }
    }
    new VSCode();
}