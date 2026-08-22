# JSON:API Menu Items

[![Pipeline](https://git.drupalcode.org/project/jsonapi_menu_items/badges/1.2.x/pipeline.svg)](https://git.drupalcode.org/project/jsonapi_menu_items/-/pipelines)
[![Test](https://github.com/Decipher/jsonapi_menu_items/actions/workflows/test.yml/badge.svg?branch=1.2.x)](https://github.com/Decipher/jsonapi_menu_items/actions/workflows/test.yml?query=branch%3A1.2.x)

It adds a [JSON:API Resource](https://www.drupal.org/project/jsonapi_resources)
for menu items, at `/jsonapi/menu_items/{menu}`, allowing for easy consumption
of a site's menus outside of Drupal.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/jsonapi_menu_items).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/jsonapi_menu_items).

## Table of contents

- Requirements
- Installation
- Features
- Filters
- Maintainers

## Requirements

- Drupal 10 or 11
- Custom Menu Links (`menu_link_content`, Drupal core)
- [JSON:API Resources](https://www.drupal.org/project/jsonapi_resources)

## Installation

1. Download and install via Composer:

   ```bash
   composer require drupal/jsonapi_menu_items
   ```

2. Enable the module:

   ```bash
   drush en jsonapi_menu_items
   ```

## Features

- Supports user and system created menu items.
- Supports `menu_link_content` and
  [Menu Link Config](https://www.drupal.org/project/menu_link_config) menu items.
- Supports filtering by depth, parents and custom query conditions.
- Supports [JSON:API Hypermedia](https://www.drupal.org/project/jsonapi_hypermedia)
  based links in the `/jsonapi` root document.
- Supports fields added to menu links via
  [Menu Item Extras](https://www.drupal.org/project/menu_item_extras).
- Supports [sparse fieldsets](https://jsonapi.org/format/#fetching-sparse-fieldsets)
  via the `fields` query parameter.
  Example: `?fields[menu_link_content--menu_link_content]=title,url`

## Filters

- **min_depth**

  Sets the minimum depth of menu links in the resulting tree relative to the root.

  Example: `?filter[min_depth]=2`

- **max_depth**

  Sets the maximum depth of menu links in the resulting tree relative to the root.

  Example: `?filter[max_depth]=2`

- **parent**

  Sets a root for menu tree loading.

  Example: `?filter[parent]=system.admin`

- **parents**

  Adds parent menu links IDs to restrict the tree.

  Example: `?filter[parents]=system.admin,system.admin_structure`

- **conditions[]**

  Adds a custom query condition.

  Example: `?filter[conditions][provider][value]=system`

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
