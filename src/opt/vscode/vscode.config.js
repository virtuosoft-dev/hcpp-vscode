/**
 * Contol Open VSCode Server via PM2
 */
module.exports = {
    apps: (function() {

        // Get active user, hostname, and port
        const {execSync} = require('child_process');
        const fs = require('fs');
        let user = execSync('/bin/bash -c "whoami"').toString().trim();
        let hostname = execSync('/bin/bash -c "hostname -f"').toString().trim();
        hostname = hostname.split('.').slice(-2).join('.');
        let port = 0;
        let pfile = '/usr/local/hestia/data/hcpp/ports/' + user + '/vscode-' + user + '.' + hostname + '.ports';
        let ports = fs.readFileSync(pfile, {encoding:'utf8', flag:'r'});
        ports = ports.split(/\r?\n/);
        for( let i = 0; i < ports.length; i++) {
            if (ports[i].indexOf('vscode_port') > -1) {
                port = ports[i];
                break;
            }
        }
        port = parseInt(port.trim().split(' ').pop());
        
        // Create PM2 compatible app details
        let details = {};
        details.name = "vscode-" + user + '.' + hostname;
        details.interpreter = "/opt/vscode/node";
        details.script = "/opt/vscode/out/server-main.js";
        details.args = "--port " + port;

        // Implement restart policy
        details.watch = ["/home/" + user + "/.openvscode-server/data/token"];
        details.ignore_watch = [];
        details.watch_delay = 5000;
        details.restart_delay = 5000;

        console.log(details);
        return [details];
    })()
}