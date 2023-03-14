# hestiacp-vscode
A plugin for [hestiacp-pluginable](https://github.com/steveorevo/hestiacp-pluginable) that furnishes multi-tenant Open Visual Studio Code Servers preconfigured for step-by-step debugging (Xdebug) for users and their hosted domains. 

&nbsp;
> :warning: !!! Note: this repo is in progress; when completed, a release will appear in the release tab.

## Installation
HestiaCP-vscode requires an Ubuntu based installation of [Hestia Control Panel](https://hestiacp.com) in addition to an installation of [HestiaCP-Pluginable](https://github.com/steveorevo/hestiacp-pluginable) ***and*** [HesitaCP-NodeApp](https://github.com/steveorevo/hestiacp-nodeapp) to function; please ensure that you have first installed both Pluginable and NodeApp on your Hestia Control Panel before proceeding. Switch to a root user and simply clone this project to the /usr/local/hestia/plugins folder. It should appear as a subfolder with the name `vscode`, i.e. `/usr/local/hestia/plugins/vscode`.

First, switch to root user:
```
sudo -s
```

Then simply clone the repo to your plugins folder, with the name `vscode`:

```
cd /usr/local/hestia/plugins
git clone https://github.com/steveorevo/hestiacp-vscode vscode
```

Note: It is important that the plugin folder name is `vscode`.

Be sure to logout and login again to your Hestia Control Panel as the admin user or, as admin, visit Server (gear icon) -> Configure -> Plugins -> Save; the plugin will immediately start installing VSCode server depedencies in the background. A notification will appear under the admin user account indicating *"VSCode plugin has finished installing"* when complete. This may take awhile before the options appear in Hestia. You can force manual installation via root level SSH:

```
sudo -s
cd /usr/local/hestia/plugins/vscode
./install
```