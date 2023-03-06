<?php
/**
 * Plugin Name: VSCode
 * Plugin URI: https://github.com/steveorevo/hestiacp-vscode
 * Description: VSCode is a plugin for HestiaCP instance that installs a multitenant VSCode server.
 */

// Register the install and uninstall scripts
global $hcpp;
require_once( dirname(__FILE__) . '/vscode.php' );

$hcpp->register_install_script( dirname(__FILE__) . '/install' );
$hcpp->register_uninstall_script( dirname(__FILE__) . '/uninstall' );
