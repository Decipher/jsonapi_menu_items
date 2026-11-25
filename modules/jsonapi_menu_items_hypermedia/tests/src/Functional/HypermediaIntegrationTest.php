<?php

namespace Drupal\Tests\jsonapi_menu_items_hypermedia\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\jsonapi\Functional\JsonApiRequestTestTrait;
use Drupal\Tests\jsonapi\Functional\ResourceResponseTestTrait;
use GuzzleHttp\RequestOptions;

/**
 * Tests JSON:API Hypermedia integration.
 *
 * @group jsonapi_menu_items_hypermedia
 * @requires jsonapi_hypermedia
 */
final class HypermediaIntegrationTest extends BrowserTestBase {

  use JsonApiRequestTestTrait;
  use ResourceResponseTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'jsonapi_hypermedia',
    'jsonapi_menu_items',
    'jsonapi_menu_items_hypermedia',
  ];

  /**
   * Tests the `menu_items` links.
   */
  public function testMenuItemsLinks(): void {
    $url = Url::fromRoute('jsonapi.resource_list');
    $request_options = [];
    $request_options[RequestOptions::HEADERS]['Accept'] = 'application/vnd.api+json';
    $response = $this->request('GET', $url, $request_options);
    $body = (string) $response->getBody();
    $this->assertEquals(200, $response->getStatusCode(), $body);
    $decoded_document = Json::decode($body);
    $this->assertTrue(isset($decoded_document['links']['menu_items--main']), var_export($decoded_document, TRUE));
    $link_href = $decoded_document['links']['menu_items--main']['href'];
    $expected_link_href = Url::fromRoute('jsonapi_menu_items.menu', ['menu' => 'main'])->setAbsolute()->toString();
    $this->assertEquals($expected_link_href, $link_href);
  }

}
