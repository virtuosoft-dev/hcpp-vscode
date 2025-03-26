NOTE: This plugin is NOT compatible with pluginable 2.X; an update is required and pending.

# hcpp-vscode
A plugin for [hestiacp-pluginable](https://github.com/virtuosoft-dev/hestiacp-pluginable) that furnishes multi-tenant Open Visual Studio Code Servers preconfigured for step-by-step debugging (Xdebug) for users and their hosted domains. 

## Installation
HCPP-VSCode requires an Ubuntu or Debian based installation of [Hestia Control Panel](https://hestiacp.com) in addition to an installation of [HestiaCP-Pluginable](https://github.com/virtuosoft-dev/hestiacp-pluginable) ***and*** [HCPP-NodeApp](https://github.com/virtuosoft-dev/hcpp-nodeapp) to function; please ensure that you have first installed both Pluginable and NodeApp on your Hestia Control Panel before proceeding. Switch to a root user and simply clone this project to the /usr/local/hestia/plugins folder. It should appear as a subfolder with the name `vscode`, i.e. `/usr/local/hestia/plugins/vscode`.

First, switch to root user:
```
sudo -s
```

Then simply clone the repo to your plugins folder, with the name `vscode`:

```
cd /usr/local/hestia/plugins
git clone https://github.com/virtuosoft-dev/hcpp-vscode vscode
```

Note: It is important that the plugin folder name is `vscode`.

Be sure to logout and login again to your Hestia Control Panel as the admin user or, as admin, visit Server (gear icon) -> Configure -> Plugins -> Save; the plugin will immediately start installing VSCode server depedencies in the background. A notification will appear under the admin user account indicating *"VSCode plugin has finished installing"* when complete. This may take awhile before the options appear in Hestia. You can force manual installation via root level SSH:

```
sudo -s
cd /usr/local/hestia/plugins/vscode
./install
touch "/usr/local/hestia/data/hcpp/installed/vscode"
```


## Support the creator
You can help this author's open source development endeavors by donating any amount to Stephen J. Carnam @ Virtuosoft. Your donation, no matter how large or small helps pay for essential time and resources to create MIT and GPL licensed projects that you and the world can benefit from. Click the link below to donate today :)
<div>
         

[<kbd> <br> Donate to this Project <br> </kbd>][KBD]


</div>


<!---------------------------------------------------------------------------->

[KBD]: https://virtuosoft.com/donate

https://virtuosoft.com/donate
