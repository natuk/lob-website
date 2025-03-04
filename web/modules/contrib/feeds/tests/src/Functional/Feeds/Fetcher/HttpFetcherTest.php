<?php

namespace Drupal\Tests\feeds\Functional\Feeds\Fetcher;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Tests\feeds\Functional\FeedsBrowserTestBase;
use Drupal\feeds\Exception\EmptyFeedException;
use Drupal\feeds\FeedTypeInterface;
use Drupal\feeds\Plugin\Type\Processor\ProcessorInterface;
use Drupal\feeds\StateInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * @coversDefaultClass \Drupal\feeds\Feeds\Fetcher\HttpFetcher
 * @group feeds
 */
class HttpFetcherTest extends FeedsBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'feeds',
    'node',
    'user',
    'file',
    'block',
    'taxonomy',
    'feeds_test_files',
  ];

  /**
   * The feed type entity.
   *
   * @var \Drupal\feeds\Entity\FeedType
   */
  protected $feedType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Flush all caches to make table "cache_feeds_download" available.
    drupal_flush_all_caches();

    // Add body field.
    node_add_body_field($this->nodeType);

    // Add taxonomy reference field.
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->createFieldWithStorage('field_tags', [
      'type' => 'entity_reference',
      'label' => 'Tags',
      'storage' => [
        'settings' => [
          'target_type' => 'taxonomy_term',
        ],
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      ],
      'field' => [
        'settings' => [
          'handler_settings' => [
            'target_bundles' => [
              'tags' => 'tags',
            ],
          ],
        ],
      ],
    ]);

    // Create a feed type.
    $this->feedType = $this->createFeedType([
      'fetcher' => 'http',
      'mappings' => [
        [
          'target' => 'title',
          'map' => ['value' => 'title'],
        ],
        [
          'target' => 'body',
          'map' => ['value' => 'description'],
        ],
        [
          'target' => 'feeds_item',
          'map' => ['guid' => 'guid', 'url' => 'url'],
          'unique' => ['guid' => TRUE],
        ],
        [
          'target' => 'created',
          'map' => ['value' => 'timestamp'],
        ],
        [
          'target' => 'field_tags',
          'map' => ['target_id' => 'tags'],
          'settings' => ['autocreate' => TRUE],
        ],
      ],
      'processor_configuration' => ['values' => ['type' => 'article']],
      'import_period' => FeedTypeInterface::SCHEDULE_NEVER,
    ]);

    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('local_actions_block');
    $this->drupalPlaceBlock('system_messages_block');
  }

  /**
   * Tests importing a RSS feed using the HTTP fetcher.
   */
  public function testHttpImport() {
    $filepath = $this->resourcesPath() . '/rss/googlenewstz.rss2';

    $feed = $this->createFeed($this->feedType->id(), [
      'source' => $this->resourcesUrl() . '/rss/googlenewstz.rss2',
    ]);

    $this->drupalGet('feed/' . $feed->id());
    $this->clickLink('Import');
    $this->submitForm([], 'Import');
    $this->assertSession()->pageTextContains('Created 6');
    $this->assertNodeCount(6);

    // Assert that the temporary file is cleaned up.
    $this->assertCountFilesInProgressDir(0);

    $xml = new \SimpleXMLElement($filepath, 0, TRUE);

    $expected_terms = [
      1 => [],
      2 => ['Top Stories'],
      3 => ['Top Stories'],
      4 => ['Top Stories 2'],
      5 => ['Top Stories 2'],
      6 => ['Top Stories 3'],
    ];

    foreach (range(1, 6) as $nid) {
      $item = $xml->channel->item[$nid - 1];
      $node = Node::load($nid);
      $this->assertEquals($node->title->value, (string) $item->title);
      $this->assertEquals($node->body->value, (string) $item->description);

      $feeds_item = $node->get('feeds_item')->getItemByFeed($feed);
      $this->assertEquals($feeds_item->guid, (string) $item->guid);
      $this->assertEquals($feeds_item->url, (string) $item->link);

      $this->assertEquals($node->created->value, strtotime((string) $item->pubDate));

      $terms = [];
      foreach ($node->field_tags->referencedEntities() as $term) {
        $terms[] = $term->label();
      }
      $this->assertEquals($expected_terms[$nid], $terms);
    }

    // Test cache.
    $this->drupalGet('feed/' . $feed->id() . '/import');
    $this->submitForm([], 'Import');
    $this->assertSession()->pageTextContains('The feed has not been updated.');

    // Import again.
    \Drupal::cache('feeds_download')->deleteAll();
    $this->drupalGet('feed/' . $feed->id() . '/import');
    $this->submitForm([], 'Import');
    $this->assertSession()->pageTextContains('There are no new');

    // Test force-import.
    \Drupal::cache('feeds_download')->deleteAll();
    $configuration = $this->feedType->getProcessor()->getConfiguration();
    $configuration['skip_hash_check'] = TRUE;
    $configuration['update_existing'] = ProcessorInterface::UPDATE_EXISTING;
    $this->feedType->getProcessor()->setConfiguration($configuration);
    $this->feedType->save();
    $this->drupalGet('feed/' . $feed->id() . '/import');
    $this->submitForm([], 'Import');
    $this->assertNodeCount(6);
    $this->assertSession()->pageTextContains('Updated 6');

    // Delete items.
    $this->clickLink('Delete items');
    $this->submitForm([], 'Delete items');
    $this->assertNodeCount(0);
    $this->assertSession()->pageTextContains('Deleted 6');
  }

  /**
   * Tests if nothing gets cached when disabling HTTP Caching.
   */
  public function testHttpCacheDisabled() {
    // Disable caching.
    $fetcher = $this->feedType->getFetcher();
    $config = $fetcher->getConfiguration();
    $config['always_download'] = TRUE;
    $fetcher->setConfiguration($config);
    $this->feedType->save();

    // Import feed.
    $feed = $this->createFeed($this->feedType->id(), [
      'source' => $this->resourcesUrl() . '/rss/googlenewstz.rss2',
    ]);
    $this->batchImport($feed);
    $this->assertSession()->pageTextContains('Created 6');
    $this->assertNodeCount(6);

    // Assert that no cache entries were created.
    $count = $this->container->get('database')
      ->select('cache_feeds_download')
      ->fields('cache_feeds_download', [])
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);
  }

  /**
   * Tests if a changed source is refetched.
   */
  public function testChangedSource() {
    // Install module that dynamically generates a CSV file.
    $this->container->get('module_installer')->install(['feeds_test_files']);
    $this->rebuildContainer();

    // Create a feed type.
    $feed_type = $this->createFeedTypeForCsv([
      'guid' => 'GUID',
      'title' => 'Title',
    ], [
      'fetcher' => 'http',
      'fetcher_configuration' => [],
      'processor_configuration' => [
        'update_existing' => ProcessorInterface::UPDATE_EXISTING,
        'values' => [
          'type' => 'article',
        ],
      ],
    ]);

    // Import feed.
    $feed = $this->createFeed($feed_type->id(), [
      'source' => $this->getBaseUrl() . '/testing/feeds/nodes.csv',
    ]);
    $this->batchImport($feed);
    $this->assertSession()->pageTextContains('Created 8');
    $this->assertNodeCount(8);

    // Import again.
    $this->batchImport($feed);
    $this->assertSession()->pageTextContains('There are no new');

    // Now change the source to test if the source is refetched.
    // - Items 1 and 4 changed.
    // - Items 2 and 7 were removed.
    \Drupal::state()->set('feeds_test_nodes_last_modified', strtotime('Sun, 30 Mar 2016 10:19:55 GMT'));

    $this->batchImport($feed);
    $this->assertSession()->pageTextContains('Updated 2');
  }

  /**
   * Tests if the file to import gets removed when unlocking a feed.
   */
  public function testRemoveTempFileAfterUnlock() {
    $feed = $this->createFeed($this->feedType->id(), [
      'source' => $this->resourcesUrl() . '/rss/googlenewstz.rss2',
    ]);

    // Start a cron import. This will put items on the queue.
    $feed->startCronImport();

    // Run the first queue task, which is fetching the http source. This will
    // create the file in the Feeds in progress dir.
    $this->runQueue('feeds_feed_refresh:' . $this->feedType->id(), 1);
    // Assert that a file exist in the Feeds in progress dir.
    $this->assertCountFilesInProgressDir(1, '', 'public');
    $this->assertCountFilesInProgressDir(1, $feed->id(), 'public');

    // Now unlock the feed and assert that the file to import gets removed.
    $feed->unlock();
    $this->assertCountFilesInProgressDir(0, '', 'public');
  }

  /**
   * Tests that an import is aborted when the file to import from gets removed.
   */
  public function testAbortImportWhenTemporaryFileIsDeleted() {
    $feed = $this->createFeed($this->feedType->id(), [
      'source' => $this->resourcesUrl() . '/rss/googlenewstz.rss2',
    ]);

    // Start a cron import. This will put items on the queue.
    $feed->startCronImport();

    // Run the first queue task, which is fetching the http source. This will
    // create the file in the Feeds in progress dir.
    $this->runQueue('feeds_feed_refresh:' . $this->feedType->id(), 1);
    // Assert that a file exist in the Feeds in progress dir.
    $this->assertCountFilesInProgressDir(1, '', 'public');
    $this->assertCountFilesInProgressDir(1, $feed->id(), 'public');

    // Remove all the files from the in progress dir.
    $this->container->get('file_system')->deleteRecursive('public://feeds/in_progress');

    // Run the rest of the queue. No nodes should have been imported.
    // The import should abort, but not with uncaught exceptions.
    $this->runCompleteQueue('feeds_feed_refresh:' . $this->feedType->id());
    $this->assertNodeCount(0);
  }

  /**
   * Tests if an import attempt gets retried in case of a 404.
   */
  public function testRetryImportOn404() {
    $feed = $this->createFeed($this->feedType->id(), [
      'source' => $this->resourcesUrl() . '/rss/nonexistent.rss2',
    ]);

    // Start a cron import. This will put items on the queue.
    $feed->startCronImport();

    // Run cron to run the import.
    $this->cronRun();

    // Assert that there is still a task on the queue.
    $this->assertQueueItemCount(1, 'feeds_feed_refresh:' . $this->feedType->id());
  }

  /**
   * Tests if the fetch fails when setting the request timeout to a low value.
   */
  public function testRequestTimeoutSetting() {
    // Create a feed type.
    $feed_type = $this->createFeedTypeForCsv([
      'guid' => 'GUID',
      'title' => 'Title',
    ], [
      'fetcher' => 'http',
      'fetcher_configuration' => [
        'request_timeout' => 1,
      ],
    ]);

    // Set two seconds for a time for a fetch delay.
    \Drupal::state()->set('feeds_timeout', 2);

    // Create a feed that contains 9 items.
    $feed = $this->createFeed($feed_type->id(), [
      'source' => $this->getBaseUrl() . '/testing/feeds/nodes.csv',
    ]);

    // Try to import the feed using the UI.
    $this->batchImport($feed);
    $this->assertSession()->pageTextContains('Operation timed out');

    // Assert that no nodes were imported.
    $this->assertNodeCount(0);

    // Try via cron import too.
    $feed->startCronImport();
    $this->cronRun();

    // Assert that still no nodes were imported.
    $this->assertNodeCount(0);
  }

  /**
   * Tests that a downloaded file is immediately cleaned up when it is empty.
   */
  public function testCleanupOnEmptyFile() {
    $feed = $this->createFeed($this->feedType->id(), [
      'source' => $this->resourcesUrl() . '/rss/empty.rss2',
    ]);

    // Fetch only (instead of performing the whole import).
    $e = NULL;
    try {
      $feed->getType()->getFetcher()->fetch($feed, $feed->getState(StateInterface::FETCH));
    }
    catch (EmptyFeedException $e) {
      // There should be an exception thrown.
    }

    // Assert that temporary files got cleaned up.
    $this->assertCountFilesInProgressDir(0, '', 'public');

    // Assert that a EmptyFeedException was thrown earlier.
    $this->assertInstanceOf(EmptyFeedException::class, $e, 'Failed asserting that a EmptyFeedException was thrown.');
  }

  /**
   * Tests that a temporary file is immediately cleaned up on a 304.
   */
  public function testCleanupOn304() {
    $feed_type = $this->createFeedTypeForCsv([
      'guid' => 'GUID',
      'title' => 'Title',
    ], [
      'fetcher' => 'http',
      'fetcher_configuration' => [],
    ]);

    $feed = $this->createFeed($feed_type->id(), [
      'source' => $this->getBaseUrl() . '/testing/feeds/304.csv',
    ]);

    // Fetch only (instead of performing the whole import).
    $e = NULL;
    try {
      $feed->getType()->getFetcher()->fetch($feed, $feed->getState(StateInterface::FETCH));
    }
    catch (EmptyFeedException $e) {
      // There should be an exception thrown.
    }

    // Assert that temporary files got cleaned up.
    $this->assertCountFilesInProgressDir(0, '', 'public');

    // Assert that a EmptyFeedException was thrown earlier.
    $this->assertInstanceOf(EmptyFeedException::class, $e, 'Failed asserting that a EmptyFeedException was thrown.');
  }

}
