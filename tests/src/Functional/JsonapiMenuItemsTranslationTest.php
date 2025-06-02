<?php

namespace Drupal\Tests\jsonapi_menu_items\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\jsonapi\Functional\JsonApiRequestTestTrait;
use GuzzleHttp\RequestOptions;

/**
 * Tests JSON:API Menu Items functionality with translations.
 *
 * @group jsonapi_menu_items
 */
class JsonapiMenuItemsTranslationTest extends BrowserTestBase {
  use JsonApiRequestTestTrait;

  /**
   * The account to use for authentication.
   *
   * @var null|\Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
    'language',
    'jsonapi_menu_items',
    'jsonapi_menu_items_test',
    'menu_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create administrator user.
    $this->account = $this->drupalCreateUser([
      'administer languages',
      'administer content translation',
      'translate any entity',
      'create content translations',
      'administer menu',
    ]);
    $this->drupalLogin($this->account);

    // Add languages.
    foreach (['fr', 'es'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }

    // Enable translation for menu link content.
    $edit = [
      'entity_types[menu_link_content]' => TRUE,
      'settings[menu_link_content][menu_link_content][translatable]' => TRUE,
    ];
    $this->drupalGet('admin/config/regional/content-language');
    $this->submitForm($edit, 'Save configuration');

    // After adding languages, rebuild the container so that generated links
    // reflect the language configuration.
    $this->rebuildAll();
  }

  /**
   * Asserts whether an expected cache context was present in the last response.
   *
   * @param array $headers
   *   An array of HTTP headers.
   * @param string $expected_cache_context
   *   The expected cache context.
   */
  protected function assertCacheContext(array $headers, $expected_cache_context): void {
    $cache_contexts = explode(' ', $headers['X-Drupal-Cache-Contexts'][0]);
    $this->assertContains($expected_cache_context, $cache_contexts, "'$expected_cache_context' is present in the X-Drupal-Cache-Contexts header.");
  }

  /**
   * Asserts whether an expected cache tag was present in the last response.
   *
   * @param array $headers
   *   An array of HTTP headers.
   * @param string $expected_cache_tag
   *   The expected cache tag.
   */
  protected function assertCacheTag(array $headers, $expected_cache_tag): void {
    $cache_tags = explode(' ', $headers['X-Drupal-Cache-Tags'][0]);
    $this->assertContains($expected_cache_tag, $cache_tags, "'$expected_cache_tag' is present in the X-Drupal-Cache-Tags header.");
  }

  /**
   * Tests the JSON:API Menu Items resource with translated menu links.
   */
  public function testJsonapiMenuItemsTranslation(): void {
    // Create original English menu link.
    $link_title_en = 'English title ' . $this->randomMachineName();
    $link_description_en = 'English description ' . $this->randomMachineName();
    $content_link = $this->createMenuLink($link_title_en, $link_description_en, 'jsonapi_menu_test.open');

    // Add French translation.
    $link_title_fr = 'French title ' . $this->randomMachineName();
    $link_description_fr = 'French description ' . $this->randomMachineName();
    $content_link_fr = $content_link->addTranslation('fr', [
      'title' => $link_title_fr,
      'description' => $link_description_fr,
      'enabled' => 1,
    ]);
    $content_link_fr->save();

    // Add Spanish translation.
    $link_title_es = 'Spanish title ' . $this->randomMachineName();
    $link_description_es = 'Spanish description ' . $this->randomMachineName();
    $content_link_es = $content_link->addTranslation('es', [
      'title' => $link_title_es,
      'description' => $link_description_es,
      'enabled' => 1,
    ]);
    $content_link_es->save();

    // Get English menu items (default language).
    $url = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
    ]);
    [$content_en, $headers_en] = $this->getJsonApiMenuItemsResponse($url);

    // Get menu items in French.
    $url_fr = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
    ]);
    $url_fr->setOption('language', ConfigurableLanguage::load('fr'));
    [$content_fr, $headers_fr] = $this->getJsonApiMenuItemsResponse($url_fr);

    // Get menu items in Spanish.
    $url_es = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
    ]);
    $url_es->setOption('language', ConfigurableLanguage::load('es'));
    [$content_es, $headers_es] = $this->getJsonApiMenuItemsResponse($url_es);

    // Test that the correct translations are returned for each language.
    $this->assertMenuLinkTranslation($content_en, $content_link->uuid(), $link_title_en, $link_description_en, 'en');
    $this->assertMenuLinkTranslation($content_fr, $content_link->uuid(), $link_title_fr, $link_description_fr, 'fr');
    $this->assertMenuLinkTranslation($content_es, $content_link->uuid(), $link_title_es, $link_description_es, 'es');

    // Test cache contexts and tags.
    $this->assertCacheContext($headers_en, 'languages:language_interface');
    $this->assertCacheContext($headers_fr, 'languages:language_interface');
    $this->assertCacheContext($headers_es, 'languages:language_interface');

    $menu_link_tag = 'config:system.menu.jsonapi-menu-items-test';
    $this->assertCacheTag($headers_en, $menu_link_tag);
    $this->assertCacheTag($headers_fr, $menu_link_tag);
    $this->assertCacheTag($headers_es, $menu_link_tag);
  }

  /**
   * Tests the JSON:API Menu Items resource with mixed languages.
   */
  public function testJsonapiMenuItemsMixedLanguages(): void {
    // Create English-only menu link.
    $link_title_en = 'EN only ' . $this->randomMachineName();
    $content_link_en = $this->createMenuLink($link_title_en, 'English only', 'jsonapi_menu_test.open');

    // Create menu link with English and French, but no Spanish.
    $link_title_en_fr = 'EN+FR ' . $this->randomMachineName();
    $content_link_en_fr = $this->createMenuLink($link_title_en_fr, 'English and French', 'jsonapi_menu_test.open');
    $content_link_en_fr_fr = $content_link_en_fr->addTranslation('fr', [
      'title' => 'FR ' . $this->randomMachineName(),
      'description' => 'French translation',
      'enabled' => 1,
    ]);
    $content_link_en_fr_fr->save();

    // Create menu link with all languages.
    $link_title_all = 'ALL ' . $this->randomMachineName();
    $content_link_all = $this->createMenuLink($link_title_all, 'All languages', 'jsonapi_menu_test.open');
    $content_link_all_fr = $content_link_all->addTranslation('fr', [
      'title' => 'FR ALL ' . $this->randomMachineName(),
      'description' => 'French all',
      'enabled' => 1,
    ]);
    $content_link_all_fr->save();
    $content_link_all_es = $content_link_all->addTranslation('es', [
      'title' => 'ES ALL ' . $this->randomMachineName(),
      'description' => 'Spanish all',
      'enabled' => 1,
    ]);
    $content_link_all_es->save();

    // Get Spanish menu items.
    $url_es = Url::fromRoute('jsonapi_menu_items.menu', [
      'menu' => 'jsonapi-menu-items-test',
    ]);
    $url_es->setOption('language', ConfigurableLanguage::load('es'));
    [$content_es, $headers_es] = $this->getJsonApiMenuItemsResponse($url_es);

    // Test that the Spanish response contains:
    // - English-only link in English fallback.
    // - English+French link in English fallback.
    // - All-languages link in Spanish.
    $menu_data = $content_es['data'];
    $menu_links = [];
    foreach ($menu_data as $item) {
      if (strpos($item['id'], 'menu_link_content:') === 0) {
        $uuid = str_replace('menu_link_content:', '', $item['id']);
        $menu_links[$uuid] = $item;
      }
    }

    // English-only link should use English as fallback in Spanish interface.
    $this->assertArrayHasKey($content_link_en->uuid(), $menu_links);
    $this->assertEquals($link_title_en, $menu_links[$content_link_en->uuid()]['attributes']['title']);
    $this->assertEquals('en', $menu_links[$content_link_en->uuid()]['attributes']['langcode']);

    // English+French link should use English as fallback in Spanish interface.
    $this->assertArrayHasKey($content_link_en_fr->uuid(), $menu_links);
    $this->assertEquals($link_title_en_fr, $menu_links[$content_link_en_fr->uuid()]['attributes']['title']);
    $this->assertEquals('en', $menu_links[$content_link_en_fr->uuid()]['attributes']['langcode']);

    // All-languages link should use Spanish in Spanish interface.
    $this->assertArrayHasKey($content_link_all->uuid(), $menu_links);
    $this->assertStringStartsWith('ES ALL ', $menu_links[$content_link_all->uuid()]['attributes']['title']);
    $this->assertEquals('es', $menu_links[$content_link_all->uuid()]['attributes']['langcode']);
  }

  /**
   * Create menu link.
   *
   * @param string $title
   *   The menu link title.
   * @param string $description
   *   The menu link description.
   * @param string $parent
   *   The menu link parent id.
   *
   * @return \Drupal\menu_link_content\Entity\MenuLinkContent
   *   The menu link.
   */
  protected function createMenuLink(string $title, string $description, string $parent): MenuLinkContent {
    $content_link = MenuLinkContent::create([
      'link' => ['uri' => 'route:menu_test.menu_callback_title'],
      'langcode' => 'en',
      'enabled' => 1,
      'title' => $title,
      'description' => $description,
      'menu_name' => 'jsonapi-menu-items-test',
      'parent' => $parent,
      'weight' => 0,
    ]);
    $content_link->save();

    return $content_link;
  }

  /**
   * Asserts that a menu link has the expected translation.
   *
   * @param array $content
   *   The response content.
   * @param string $uuid
   *   The menu link UUID.
   * @param string $expected_title
   *   The expected menu link title.
   * @param string $expected_description
   *   The expected menu link description.
   * @param string $expected_langcode
   *   The expected language code.
   */
  protected function assertMenuLinkTranslation(array $content, string $uuid, string $expected_title, string $expected_description, string $expected_langcode): void {
    $menu_link_id = 'menu_link_content:' . $uuid;
    $found = FALSE;

    foreach ($content['data'] as $item) {
      if ($item['id'] === $menu_link_id) {
        $found = TRUE;
        $this->assertEquals($expected_title, $item['attributes']['title']);
        $this->assertEquals($expected_description, $item['attributes']['description']);
        $this->assertEquals($expected_langcode, $item['attributes']['langcode']);
        break;
      }
    }

    $this->assertTrue($found, "Menu link with UUID $uuid found in the response.");
  }

  /**
   * Get a JSON:API Menu Items resource response document.
   *
   * @param \Drupal\core\Url $url
   *   The url for a JSON:API View.
   *
   * @return array
   *   The response document and headers.
   */
  protected function getJsonApiMenuItemsResponse(Url $url): array {
    $request_options = [];
    $request_options[RequestOptions::HEADERS]['Accept'] = 'application/vnd.api+json';

    $response = $this->request('GET', $url, $request_options);

    $this->assertSame(200, $response->getStatusCode(), var_export(Json::decode((string) $response->getBody()), TRUE));

    $response_document = Json::decode((string) $response->getBody());

    $this->assertIsArray($response_document['data']);
    $this->assertArrayNotHasKey('errors', $response_document);

    return [$response_document, $response->getHeaders()];
  }

}
