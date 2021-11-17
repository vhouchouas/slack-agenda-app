# Purpose

This project addresses the following problems:
- mobilize efficiently volunteers for actions, meetings, etc.
- without multiplying the number of tools/applications (chat app, agenda app, etc.).

This project implements an agenda (that is located on a CalDAV server) as a [Slack](https://slack.com/intl/fr-fr/) application.

# Requirements:

To be able to use this tool, you will need:
- A Slack team;
- A CalDAV server;
- An HTTP server (apache, nginx).

# Architecture
```
          ┌───────────────────┐
          │       Slack       │
          └────┬───────△──────┘
HTTP requests ▷│       │
 ➜ Events      │       │
 ➜ Actions     │       │◁ API requests
               │       │
               │       │
   - - - - - - │ - - - │ - - - - - - - - -
   ╵    ┌──────▽───────┴───┬──────────┐  ╵  ◁ What this project implements
   ╵    │     HTTP server  │          │  ╵
   ╵    │      Slack app   ◁    File  │  ╵
   ╵    │   CalDav client  ▷   System │  ╵
   ╵    └──────────┬───────┴──────────┘  ╵
   └ - - - - - - - │ - - - - - - - - - - ┘
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

# Slack app creation

To create your app please follow [this page](https://api.slack.com/apps), then click `Create New App` and choose `from an app manifest file`. Copy/paste the content of the file `slack_app_manifest.yaml.sample`, after having configured it (especially the fields: `name`, `display_name` and `request_url`). `request_url` must reach the file `index.php` on your HTTP server.

# Installation
```
wget https://github.com/Zero-Waste-Paris/slack-agenda-app/archive/refs/heads/main.zip
unzip main.zip -x "slack-agenda-app-main/tests/*"
rm main.zip
cd slack-agenda-app-main/
composer install --no-dev
```
Take care to activate HTTPs on your HTTP server.

# Configuration

```
cp config.json.sample config.json
```

Fill in the fields of `config.json` with:
- [the signing secret of your slack application](https://api.slack.com/authentication/verifying-requests-from-slack). You will find it on the `Basic Information` tab on your Slack app dashboard;
- [bot and user tokens](https://api.slack.com/authentication/token-types);  You will find these on the `Installed App` tab on your Slack app dashboard;
- the URL of your caldav agenda + login/pasword;
- the fields `error_mail_from` and `error_mail_to` to get the app errors. See the [monolog PHP logger](https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md);
- the optional fields `prepend_block` and `append_block` to display custom information to users. You can use the [Block Kit Builder](https://app.slack.com/block-kit-builder/) to do so. These values must be filled with a JSON formated string that represents a [block](https://api.slack.com/block-kit) not a list of blocks;
- logger_level, must be one of [these](https://github.com/Seldaek/monolog/blob/fb2c324c17941ffe805aa7c953895af96840d0c9/src/Monolog/Logger.php#L103).
