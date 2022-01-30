slack-agenda-app
================

This application integrates an agenda in Slack.

It makes it possible for your team members to:
* Browse your upcoming events
* Filter events based on their categories
* Register or unregister for events
* Get reminders the day before an event to which they registered

all this from Slack

An image is worth a thousand words, here is what it looks like:

    +---------------+  Read events            +----------+  Read events             +------------+
    | CalDav server | <---------------------> | This app | <----------------------> | Your Slack |
    +---------------+  Handle registrations   +----------+  Handle registrations    +------------+

What's the point
----------------

We developped this app because:

* Our volunteers did not wanted to use too many different tools (so they appreciated having everything in Slack)
* We did not had another convenient way to send a reminder

If you are in a similar situation you might find this useful

How to install
==============

Prerequisites: you will need

* A PHP web hosting (which has php-curl)
* A slack space for your team (of course)
* A CalDAV server (for instance you can use google calendar)
* PHP Composer (you can get it from [here](https://getcomposer.org/))

In a nutshell: to install it you need to

- Create the Slack app
- install those PHP scripts
- set up your configuration file
- create the tables of the database

Create the Slack app
--------------------

To create your app please follow [this page](https://api.slack.com/apps), then click `Create New App` and choose `from an app manifest file`. Copy/paste the content of the file `slack_app_manifest.yaml.sample`, after having configured it (especially the fields: `name`, `display_name` and `request_url`). `request_url` must reach the file `index.php` on your HTTP server.

TODO (in particular, mention that a dedicated user should be created for the reminders)

Installing the PHP scripts
--------------------------
Get the code:

    curl -L https://github.com/Zero-Waste-Paris/slack-agenda-app/zipball/main > slack-agenda-app.zip
    unzip slack-agenda-app.zip
    rm slack-agenda-app.zip
    cd Zero-Waste-Paris-slack-agenda-app-*
    composer install --no-dev

Upload everything on your php hosting

Configuration
-------------

```
cp config.json.sample config.json
```

Fill in the fields of `config.json` with:
- [the signing secret of your slack application](https://api.slack.com/authentication/verifying-requests-from-slack). You will find it on the `Basic Information` tab on your Slack app dashboard;
- [bot and user tokens](https://api.slack.com/authentication/token-types);  You will find these on the `Installed App` tab on your Slack app dashboard;
- the URL of your CalDAV agenda + login/pasword;
- the fields `error_mail_from` and `error_mail_to` to get the app errors. See the [monolog PHP logger](https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md);
- the optional fields `prepend_block` and `append_block` to display custom information to users. You can use the [Block Kit Builder](https://app.slack.com/block-kit-builder/) to do so. These values must be filled with a JSON formated strings that represent a [block](https://api.slack.com/block-kit) not a list of blocks;
- logger_level, must be one of [these](https://github.com/Seldaek/monolog/blob/fb2c324c17941ffe805aa7c953895af96840d0c9/src/Monolog/Logger.php#L103).

If you are using a Google calendar you should follow this [doc](https://support.google.com/accounts/answer/185833?hl=fr) to get your CalDAV credentials.

Create the tables of the database
---------------------------------

From the directory where you installed the php files run:

    ./clitools database-create

(or, if you're on Windows:

     php clitools database-create

)

Technical notes for admin
=========================

* Unless you are a really big organization the slack [rate limits](https://api.slack.com/changelog/2018-03-great-rate-limits) should be really more than enough
* The content of the CalDav server is cached on the web hosting (currently on the filesystem) so the number of requests to this backend should be limited
* To create an event that requires X participants you should the category "PX". For instance for an event that requires 2 participants, set the category "P2" on the caldav server.
* TODO: how to get the logs

Technical notes for  for developers
===================================
This app is architectured as follows:

```
                          ┌───────────────────┐
                          │       Slack       │
                          └────┬───────△──────┘
                HTTP requests ▷│       │
                 ➜ Events      │       │
                 ➜ Actions     │       │◁ Slack API requests
                               │       │
                               │       │
                               │       │
                        ┌──────▽───────┴─────┐    ┌────────────┐
                        │     HTTP server    │    │            │
                      - │ - - ▽ - - - △ - -  │    │  Database  │
 What this project▷  ╵  │      Slack app  ╵  ◁────│            │
 implements          ╵  │   CalDav client ╵  ────▷│            │
                     ╵  └──────────┬──────┴──┘    └────────────┘
                     └ - - - - - - │ - - -┘
                                   │
                  CalDAV requests ▷│
                                   │
                                   │
                                   │
                          ┌────────▽──────────┐   Ex: - Sabre (nextcloud, owndloud,etc.)
                          │   CalDAV server   │       - Google
                          └───────────────────┘       - etc.
```
Some useful documentation:
- [Slack events documentation](https://api.slack.com/events). This app uses the `app_home_opened` event;
- [Slack actions documentation](https://api.slack.com/interactivity/shortcuts). Actions are triggered when interacting with the app;
- [Slack API methods](https://api.slack.com/methods);
- [Building a CalDAV client](https://sabre.io/dav/building-a-caldav-client/).
