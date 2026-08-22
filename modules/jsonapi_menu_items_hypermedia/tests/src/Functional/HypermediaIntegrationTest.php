<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_menu_items_hypermedia\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\jsonapi\Functional\JsonApiRequestTestTrait;
use Drupal\Tests\jsonapi\Functional\ResourceResponseTestTrait;
use GuzzleHttp\RequestOptions;

/**
 * Tests JSON:API Hypermedia integration.
 *
 * @requires jsonapi_hypermedia
 */
#[Group('jsonapi_menu_items_hypermedia')]
#[RunTestsInSeparateProcesses]
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
    // AccessRestrictedLink::__construct() in jsonapi_hypermedia 1.10.0 marks
    // $link_cacheability nullable by implication, which PHP 8.4 deprecates.
    // The test HTTP middleware turns that into an error. No release fixes it
    // yet: the fix is in 8.x-1.x-dev, see
    // https://www.drupal.org/i/3526924. Compare at runtime, because Rector
    // folds a PHP_VERSION_ID check into a constant.
    if (version_compare(PHP_VERSION, '8.4', '>=')) {
      $this->markTestSkipped('jsonapi_hypermedia 1.10.0 triggers a PHP 8.4 implicit nullable deprecation.');
    }

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
