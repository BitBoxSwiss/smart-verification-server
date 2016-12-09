# Digital Bitbox Smart Verification Communication Server

Allows encrypted messages to be passed between a Digital Bitbox desktop app and a Digital Bitbox mobile app.

We provide a communication server that is used by the desktop and mobile apps.

This repository is provided for those who wish to run their own communication server. The desktop and mobile apps' communication server address can be changed easily by updating the app settings. 


### Usage

Upload the code to a PHP enabled webserver.

Be sure SQLite3 is installed and you have write permissions on a subfolder called `db`. 

Make the `db` folder inaccessible from the internet. For example, if you are using an nginx server, add the following to your server configuration (replace `smartverification` by the path to the folder containing the code):
```
location /smartverification/db {
    deny all;
}
```

Update the desktop and mobile app communication server settings.
