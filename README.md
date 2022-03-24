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

* A PHP web hosting (which has php-curl and which runs PHP 7.3 or a more recent version)
* A slack space for your team (of course)
* A CalDAV server (for instance you can use google calendar)
* PHP Composer (you can get it from [here](https://getcomposer.org/))

In a nutshell: to install it you need to

- Create the Slack app
- install those PHP scripts
- set up your configuration file
- create the tables of the database

An installation guide is available [here](https://github.com/Zero-Waste-Paris/slack-agenda-app/wiki).

Technical notes for admin
=========================

* Unless you are a really big organization the slack [rate limits](https://api.slack.com/changelog/2018-03-great-rate-limits) should be really more than enough
* To create an event that requires X participants you should add the category "PX". For instance for an event that requires 2 participants, set the category "P2" on the caldav server;
* Logs are stored in files named like `app-2022-03-21.log`. You will have 10 days of logs. Older logs are deleted automatically.

Technical notes for developers
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


