<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_mail_retry\Kernel;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_mail_retry\Plugin\Mail\RetryingMailSystem;

/**
 * Covers the retry decision, the backoff schedule and the give-up boundary.
 *
 * @group makerspace_mail_retry
 */
class MailRetryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'makerspace_mail_retry',
    'makerspace_mail_retry_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['makerspace_mail_retry']);
  }

  /**
   * A message the delegate could not send is queued rather than dropped.
   */
  public function testFailedSendIsQueued(): void {
    $this->useFailingDelegate();

    $queue = $this->container->get('queue')->get(RetryingMailSystem::QUEUE);
    $this->assertSame(0, $queue->numberOfItems());

    $accepted = $this->plugin()->mail($this->message());

    $this->assertTrue($accepted, 'A queued message is reported to the caller as accepted.');
    $this->assertSame(1, $queue->numberOfItems());

    $item = $queue->claimItem();
    $this->assertSame('someone@example.com', $item->data['message']['to']);
    $this->assertSame(0, $item->data['attempts']);
    $this->assertGreaterThan(
      $this->container->get('datetime.time')->getRequestTime(),
      $item->data['not_before'],
      'The first retry is scheduled into the future, not run immediately.'
    );
  }

  /**
   * A successful send never touches the queue.
   */
  public function testSuccessfulSendIsNotQueued(): void {
    $this->config('makerspace_mail_retry.settings')->set('inner_plugin', 'test_mail_collector')->save();

    $this->assertTrue($this->plugin()->mail($this->message()));
    $this->assertSame(0, $this->container->get('queue')->get(RetryingMailSystem::QUEUE)->numberOfItems());
  }

  /**
   * Retrying can be switched off entirely, leaving a pure pass-through.
   */
  public function testRetriesCanBeDisabled(): void {
    $this->useFailingDelegate();
    $this->config('makerspace_mail_retry.settings')->set('max_retries', 0)->save();

    $this->assertFalse($this->plugin()->mail($this->message()), 'With retries off the failure reaches the caller.');
    $this->assertSame(0, $this->container->get('queue')->get(RetryingMailSystem::QUEUE)->numberOfItems());
  }

  /**
   * Backoff lengthens, then holds at its last value instead of overrunning.
   */
  public function testBackoffScheduleRepeatsItsLastValue(): void {
    $plugin = $this->plugin();

    $this->assertSame(300, $plugin->backoffFor(0));
    $this->assertSame(900, $plugin->backoffFor(1));
    $this->assertSame(3600, $plugin->backoffFor(2));
    $this->assertSame(21600, $plugin->backoffFor(3));
    $this->assertSame(21600, $plugin->backoffFor(9), 'Past the end of the list the last delay repeats.');
  }

  /**
   * Entities parked in params never reach the queue.
   */
  public function testUnserializableParamsAreStripped(): void {
    $this->useFailingDelegate();

    $message = $this->message();
    $message['params'] = [
      'from_name' => 'MakeHaven',
      'account' => new \stdClass(),
      'node' => new \stdClass(),
    ];

    $this->plugin()->mail($message);

    $item = $this->container->get('queue')->get(RetryingMailSystem::QUEUE)->claimItem();
    $this->assertSame(['from_name' => 'MakeHaven'], $item->data['message']['params']);
  }

  /**
   * A queued message that is not yet due is delayed, not re-queued.
   *
   * Re-creating the item would let claimItem() hand it straight back and the
   * worker would spin for its whole cron budget while a message sat in
   * backoff; DelayedRequeueException lets core park it instead.
   */
  public function testNotYetDueItemIsDelayed(): void {
    $this->useFailingDelegate();
    $this->plugin()->mail($this->message());

    $queue = $this->container->get('queue')->get(RetryingMailSystem::QUEUE);
    $item = $queue->claimItem();
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('makerspace_mail_retry');

    $this->expectException(DelayedRequeueException::class);
    $worker->processItem($item->data);
  }

  /**
   * Points the delegate at a plugin id that cannot send.
   */
  private function useFailingDelegate(): void {
    $this->config('makerspace_mail_retry.settings')->set('inner_plugin', 'makerspace_mail_retry_failing')->save();
  }

  /**
   * A minimal formatted message.
   */
  private function message(): array {
    return [
      'to' => 'someone@example.com',
      'subject' => 'Your Borrowed Tool is Due Soon',
      'body' => 'Body text.',
      'headers' => ['Content-Type' => 'text/plain'],
      'params' => [],
    ];
  }

  /**
   * The plugin under test.
   */
  private function plugin(): RetryingMailSystem {
    return $this->container->get('plugin.manager.mail')->createInstance('makerspace_mail_retry');
  }

}
