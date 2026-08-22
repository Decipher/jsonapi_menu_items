<h1 align="center">JSON:API Menu items</h1>

<div align="center">

Adds a JSON:API resource for menu items: `/jsonapi/menu_items/{menu}`

[![GitHub Issues](https://img.shields.io/github/issues/Decipher/jsonapi_menu_items.svg)](https://github.com/Decipher/jsonapi_menu_items/issues)
[![GitHub Pull Requests](https://img.shields.io/github/issues-pr/Decipher/jsonapi_menu_items.svg)](https://github.com/Decipher/jsonapi_menu_items/pulls)
[![Build, test and deploy](https://github.com/Decipher/jsonapi_menu_items/actions/workflows/test.yml/badge.svg)](https://github.com/Decipher/jsonapi_menu_items/actions/workflows/test.yml)
![GitHub release (latest by date)](https://img.shields.io/github/v/release/Decipher/jsonapi_menu_items)
![LICENSE](https://img.shields.io/github/license/Decipher/jsonapi_menu_items)
![Renovate](https://img.shields.io/badge/renovate-enabled-green?logo=renovatebot)

![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4.svg)
![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4.svg)
![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4.svg)
![Drupal 10](https://img.shields.io/badge/Drupal-10-009CDE.svg)
![Drupal 11](https://img.shields.io/badge/Drupal-11-006AA9.svg)

</div>

---

## Features

- Supports user and system created menu items.
- Supports `menu_link_content` and [menu_link_config](https://www.drupal.org/project/menu_link_config) menu items.
- Supports filtering by depth, parents and custom query conditions.
- Support for [JSON:API Hypermedia](https://www.drupal.org/project/jsonapi_hypermedia) based links in `/jsonapi` root document.
- Support for fields added to menu links via [Menu Item Extras](https://www.drupal.org/project/menu_item_extras).
- Support for [sparse fieldsets](https://jsonapi.org/format/#fetching-sparse-fieldsets) via the `fields` query parameter. Example: `?fields[menu_link_content--menu_link_content]=title,url`

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

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for local development, building the
site, checking coding standards, and running the tests.

---
_This repository was created using the [Drupal Extension Scaffold](https://github.com/AlexSkrypnyk/drupal_extension_scaffold) project template_
