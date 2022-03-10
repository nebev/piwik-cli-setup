# Piwik CLI Setup Script

Have you ever needed to install the fantastic Open Source Analytics product _Piwik_ in an automated fashion?

The UI is great and all, but wouldn't it be nice to be able to programatically set up an initial install?

I found that some people used CURL commands to do this - but if you're running this script in a standalone FPM Docker container, this doesn't cut it.

So here is a (hacky-ish) script that lets you specify all your configuration in a JSON file, and then spins up a new instance of Piwik for you!

## What can it do

* Connect to your MySQL database
* Bootstrap your database and create all of Piwik's tables
* Setup your initial Username + Password
* Setup your first site
* Setup your geolocation stuff
* Setup your privacy options
* Install plugins (that have already been copied to the `plugins` folder)
* Add in some branding
* Add in any extra settings you want in your config file
* Add in any extra custom 'options' for Piwik

## How to run

* Clone this repository somewhere that's outside the main Piwik directory.
* Create an `install.json` file in the same directory - it should follow the structure of the install-example.json file that is included.
    * Make sure you pay attention to the `document_root` - this should be the root directory of your Piwik install
* Run the `install.php` script
    * eg. `php /opt/piwik/setup/install.php`
* You may still need to run `php /opt/piwik/console core:update` to apply Matomo's migrations

## What version of Piwik does it work with?

It has only been tested with the following versions:
* 2.16.5
* 3.14.1
* 4.0.3
* 4.7.1

## Troubleshooting

> I can't log into Matomo, it keeps telling me to enable cookies and refresh

You are probably running Matomo with multiple workers, and probably did not define `salt`. Register it and attribute it a randomly generated 32-character long hexadecimal string into the JSON file.
This seems new since version 4.0.0. It seems they didn't really use the salt before.

## I hate it

OK. Thanks for your feedback. Perhaps you can contribute to this project to make it better!
