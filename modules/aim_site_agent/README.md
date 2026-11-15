# AIM Site Agent

Puts AIM memory in [Site Agent](https://www.drupal.org/project/site_agent)'s
chat. Part of [AIM](../../README.md).

- **A Memory toolset** for the chat: recall, brief and exact-value lookup
  run without asking; every save waits for the person's approval.
- **Approval cards that list the facts.** A save's card shows each fact as
  a heading, with one line under it naming its scope, who or what it is
  about and when it expires, and the words the facts came from at the top.
  Approve or reject covers the whole save: to drop a fact, reject with a
  reason saying which, and the assistant offers the rest again.
- **What each aim tool does**, so Site Agent knows which calls only read.

## Requirements

`aim_tool` and `drupal/site_agent` (`composer require drupal/site_agent`
first: aim's own composer.json only suggests it).

## Setup

`drush en aim_site_agent`. The Memory toolset is installed if no toolset
named `memory` exists yet; edit it at Configuration > AI > Site Agent.
